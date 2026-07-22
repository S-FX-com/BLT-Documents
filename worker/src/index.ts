/**
 * BLT Documents — Cloudflare Worker (enforcement + delivery plane).
 *
 * Self-hosted on the S-FX Cloudflare account. One Worker serves all client
 * sites; a private R2 bucket holds every document version as an immutable
 * object. Nothing in R2 has a public URL — the only way in is a short-TTL,
 * HMAC-signed request from the BLT Documents WordPress plugin.
 *
 * Endpoints (all POST, all signed):
 *   /v1/health  — connectivity + credential check.
 *   /v1/get     — stream an object back to WordPress (which proxies to the browser).
 *   /v1/put     — store an uploaded object (integrity-checked via its SHA-256).
 */

import { Control, decodeControl, isFresh, isSafeKey, verifySignature } from './auth';

export interface Env {
	/** Private R2 bucket holding the documents. Bound in wrangler.toml. */
	BUCKET: R2Bucket;
	/** Shared HMAC secret. Set via `wrangler secret put WORKER_SECRET`. */
	WORKER_SECRET: string;
	/** Optional freshness window override (seconds, plaintext [vars]). Default 300. */
	MAX_SKEW?: string;
}

/** Cap on any signed control payload we will read from a header. */
const MAX_CONTROL = 4096;

/** JSON response helper. */
function json(data: object, status = 200): Response {
	return new Response(JSON.stringify(data), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}

/** JSON error helper. */
function jsonError(message: string, status: number): Response {
	return json({ error: message }, status);
}

/** Constant-time compare for the Bearer credential. */
function bearerMatches(provided: string, expected: string): boolean {
	if (!expected || provided.length !== expected.length) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < expected.length; i++) {
		diff |= provided.charCodeAt(i) ^ expected.charCodeAt(i);
	}
	return diff === 0;
}

interface AuthOk {
	control: Control;
}
interface AuthFail {
	response: Response;
}

/**
 * Authenticate a signed request and return the parsed control.
 *
 * Order: presence -> freshness -> Bearer -> signature -> control decode ->
 * op binding. Any failure short-circuits with a 401/400 response.
 */
async function authenticate(request: Request, env: Env, expectedOp: string): Promise<AuthOk | AuthFail> {
	const auth = request.headers.get('Authorization') || '';
	const token = auth.startsWith('Bearer ') ? auth.slice(7).trim() : '';
	const ts = request.headers.get('X-BLT-Timestamp') || '';
	const controlB64 = request.headers.get('X-BLT-Control') || '';
	const sig = request.headers.get('X-BLT-Signature') || '';

	if (!token || !ts || !controlB64 || !sig) {
		return { response: jsonError('Missing credentials.', 401) };
	}

	if (controlB64.length > MAX_CONTROL) {
		return { response: jsonError('Control payload too large.', 400) };
	}

	const maxSkew = Number(env.MAX_SKEW) > 0 ? Number(env.MAX_SKEW) : 300;
	if (!isFresh(Number(ts), Math.floor(Date.now() / 1000), maxSkew)) {
		return { response: jsonError('Stale request.', 401) };
	}

	if (!bearerMatches(token, env.WORKER_SECRET || '')) {
		return { response: jsonError('Unauthorized.', 401) };
	}

	if (!(await verifySignature(env.WORKER_SECRET, ts, controlB64, sig))) {
		return { response: jsonError('Bad signature.', 401) };
	}

	const control = decodeControl(controlB64);
	if (!control) {
		return { response: jsonError('Malformed control.', 400) };
	}

	if (control.op !== expectedOp) {
		return { response: jsonError('Operation mismatch.', 401) };
	}

	return { control };
}

/** GET an object from R2 and stream it back to the caller (WordPress). */
async function handleGet(request: Request, env: Env): Promise<Response> {
	const auth = await authenticate(request, env, 'get');
	if ('response' in auth) {
		return auth.response;
	}

	const key = auth.control.key;
	if (!isSafeKey(key)) {
		return jsonError('Invalid key.', 400);
	}

	const object = await env.BUCKET.get(key);
	if (!object) {
		return jsonError('Not found.', 404);
	}

	const headers = new Headers();
	object.writeHttpMetadata(headers);
	headers.set('ETag', object.httpEtag);
	headers.set('X-Robots-Tag', 'noindex, nofollow');
	headers.set('Cache-Control', 'private, no-store');
	headers.set('X-Blt-Documents', 'r2');

	return new Response(object.body, { status: 200, headers });
}

/** PUT (store) an object into R2, verifying its SHA-256 for integrity. */
async function handlePut(request: Request, env: Env): Promise<Response> {
	const auth = await authenticate(request, env, 'put');
	if ('response' in auth) {
		return auth.response;
	}

	const { key, sha256, content_type: contentType } = auth.control;
	if (!isSafeKey(key)) {
		return jsonError('Invalid key.', 400);
	}

	if (!request.body) {
		return jsonError('Empty body.', 400);
	}

	const options: R2PutOptions = {};
	if (typeof sha256 === 'string' && /^[a-f0-9]{64}$/.test(sha256)) {
		options.sha256 = sha256;
	}
	if (typeof contentType === 'string' && contentType) {
		options.httpMetadata = { contentType };
	}

	try {
		await env.BUCKET.put(key, request.body, options);
	} catch (err) {
		const detail = err instanceof Error ? err.message : 'unknown error';
		return jsonError(`Storage rejected the upload: ${detail}`, 422);
	}

	return json({ ok: true, key });
}

/** Health check — verifies the signed request round-trips. */
async function handleHealth(request: Request, env: Env): Promise<Response> {
	const auth = await authenticate(request, env, 'health');
	if ('response' in auth) {
		return auth.response;
	}
	return json({ ok: true, worker: 'blt-documents' });
}

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		try {
			const url = new URL(request.url);

			if (request.method !== 'POST') {
				return jsonError('Method not allowed.', 405);
			}

			if (url.pathname.endsWith('/v1/health')) {
				return await handleHealth(request, env);
			}
			if (url.pathname.endsWith('/v1/get')) {
				return await handleGet(request, env);
			}
			if (url.pathname.endsWith('/v1/put')) {
				return await handlePut(request, env);
			}

			return jsonError('Not found.', 404);
		} catch (err) {
			const detail = err instanceof Error ? err.message : 'unknown error';
			return jsonError(`Server error: ${detail}`, 500);
		}
	},
};
