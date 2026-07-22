/**
 * Request authentication for the BLT Documents Worker.
 *
 * Pure, runtime-agnostic Web Crypto — unit-tested under Node (`node --test`)
 * and identical in the Workers runtime. This mirrors the PHP signer
 * (BLT_Documents_Signer::sign): lowercase-hex HMAC-SHA256 over "{ts}.{message}".
 */

const encoder = new TextEncoder();

/** Lowercase hex of a byte buffer. */
function toHex(buf: ArrayBuffer): string {
	return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** SHA-256 hex of a string. */
export async function sha256Hex(value: string): Promise<string> {
	return toHex(await crypto.subtle.digest('SHA-256', encoder.encode(value)));
}

/** Lowercase-hex HMAC-SHA256 of `message` keyed by `secret`. */
export async function hmacHex(secret: string, message: string): Promise<string> {
	const key = await crypto.subtle.importKey(
		'raw',
		encoder.encode(String(secret)),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		['sign']
	);
	return toHex(await crypto.subtle.sign('HMAC', key, encoder.encode(String(message))));
}

/** Constant-time comparison of two equal-length hex strings. */
export function timingSafeEqualHex(a: string, b: string): boolean {
	a = String(a);
	b = String(b);
	if (a.length !== b.length) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < a.length; i++) {
		diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return diff === 0;
}

/** Whether a timestamp is within the allowed skew of now (replay guard). */
export function isFresh(ts: number, now: number, maxSkew = 300): boolean {
	ts = Number(ts);
	now = Number(now);
	if (!Number.isFinite(ts) || !Number.isFinite(now)) {
		return false;
	}
	return Math.abs(now - ts) <= maxSkew;
}

/**
 * Verify the signature the plugin computed over "{ts}.{message}".
 *
 * @param secret    Shared Worker secret (HMAC key).
 * @param ts        Presented X-BLT-Timestamp.
 * @param message   Signed message (the base64url control string).
 * @param signature Presented X-BLT-Signature.
 */
export async function verifySignature(
	secret: string,
	ts: string | number,
	message: string,
	signature: string
): Promise<boolean> {
	const expected = await hmacHex(secret, `${String(ts)}.${String(message)}`);
	return timingSafeEqualHex(expected, signature);
}

/** The parsed operation descriptor carried in X-BLT-Control. */
export interface Control {
	op: string;
	key?: string;
	sha256?: string;
	content_type?: string;
	site?: string;
}

/**
 * Decode a base64url control string into a Control object.
 *
 * @param b64url URL-safe base64 (no padding) control payload.
 * @returns Parsed control, or null when malformed.
 */
export function decodeControl(b64url: string): Control | null {
	try {
		let b64 = String(b64url).replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4 !== 0) {
			b64 += '=';
		}
		const json = atob(b64);
		const parsed = JSON.parse(json) as Control;
		if (!parsed || typeof parsed.op !== 'string') {
			return null;
		}
		return parsed;
	} catch {
		return null;
	}
}

/**
 * Validate an R2 object key: printable, no traversal, no leading slash.
 *
 * @param key Candidate key.
 */
export function isSafeKey(key: unknown): key is string {
	return (
		typeof key === 'string' &&
		key.length > 0 &&
		key.length <= 1024 &&
		/^[A-Za-z0-9][A-Za-z0-9/_.-]*$/.test(key) &&
		!key.includes('..') &&
		!key.startsWith('/')
	);
}

/**
 * Whether a key lives under the signed site's namespace.
 *
 * The plugin builds every key as `{site-id}/…` and signs `site` in the control
 * payload, so a request can only ever touch its own prefix. Note: with a shared
 * WORKER_SECRET this binds the key to the *claimed* site but does not
 * cryptographically isolate tenants from one another — for untrusted
 * multi-tenancy give each site its own secret (see DEPLOY.md).
 *
 * @param key  Candidate object key.
 * @param site Signed site id from the control payload.
 */
export function keyInSite(key: string, site: string | undefined): boolean {
	if (typeof site !== 'string' || !/^[a-z0-9_-]+$/.test(site)) {
		return false;
	}
	return key === site || key.startsWith(site + '/');
}
