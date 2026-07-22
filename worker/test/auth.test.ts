/**
 * Known-vector tests for the auth core. Run with: npm test
 *
 * These prove the Worker's HMAC/hash logic agrees byte-for-byte with the PHP
 * signer, so the two enforcement layers never disagree on a valid request.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
	sha256Hex,
	hmacHex,
	timingSafeEqualHex,
	isFresh,
	verifySignature,
	decodeControl,
	isSafeKey,
	keyInSite,
} from '../src/auth';

function base64url(input: string): string {
	return Buffer.from(input, 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

test('sha256Hex matches the known vector for "abc"', async () => {
	assert.equal(
		await sha256Hex('abc'),
		'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
	);
});

test('hmacHex matches the RFC-style fox vector', async () => {
	assert.equal(
		await hmacHex('key', 'The quick brown fox jumps over the lazy dog'),
		'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8'
	);
});

test('verifySignature mirrors the plugin format ({ts}.{message})', async () => {
	const secret = 'shared-secret';
	const ts = 42;
	const message = base64url('{"op":"get","key":"site/a/b/v1.pdf"}');
	const sig = await hmacHex(secret, `${ts}.${message}`);

	assert.equal(await verifySignature(secret, ts, message, sig), true);
	// Tampering the last hex char must fail.
	const bad = sig.slice(0, -1) + (sig.slice(-1) === '0' ? '1' : '0');
	assert.equal(await verifySignature(secret, ts, message, bad), false);
	// Tampering the message must fail.
	assert.equal(await verifySignature(secret, ts, message + 'x', sig), false);
});

test('timingSafeEqualHex handles equal, different, and length-mismatch', () => {
	assert.equal(timingSafeEqualHex('abcd', 'abcd'), true);
	assert.equal(timingSafeEqualHex('abcd', 'abce'), false);
	assert.equal(timingSafeEqualHex('abcd', 'abcde'), false);
});

test('isFresh honors the +/-300s skew window', () => {
	assert.equal(isFresh(1000, 1200), true); // 200s <= 300
	assert.equal(isFresh(1000, 1300), true); // exactly 300
	assert.equal(isFresh(1000, 1400), false); // 400s > 300
	assert.equal(isFresh(Number.NaN, 1000), false);
});

test('decodeControl round-trips a base64url control string', () => {
	const control = decodeControl(base64url('{"op":"put","key":"s/a/b/v2.pdf","sha256":"deadbeef"}'));
	assert.ok(control);
	assert.equal(control?.op, 'put');
	assert.equal(control?.key, 's/a/b/v2.pdf');
	assert.equal(decodeControl('!!!not-base64!!!'), null);
});

test('isSafeKey rejects traversal, leading slash, and bad chars', () => {
	assert.equal(isSafeKey('site123/governing/5-bylaws/v1.pdf'), true);
	assert.equal(isSafeKey('../etc/passwd'), false);
	assert.equal(isSafeKey('/leading/slash'), false);
	assert.equal(isSafeKey('has space'), false);
	assert.equal(isSafeKey(''), false);
	assert.equal(isSafeKey(123 as unknown), false);
});

test('keyInSite scopes a key to its signed site prefix', () => {
	assert.equal(keyInSite('abc123/governing/5-bylaws/v1.pdf', 'abc123'), true);
	// A key for a different site must be rejected.
	assert.equal(keyInSite('victim9/governing/5-bylaws/v1.pdf', 'abc123'), false);
	// A prefix that is not a full path segment must not match.
	assert.equal(keyInSite('abc1234/x/v1.pdf', 'abc123'), false);
	// Missing / malformed site is rejected.
	assert.equal(keyInSite('abc123/x/v1.pdf', undefined), false);
	assert.equal(keyInSite('abc123/x/v1.pdf', ''), false);
	assert.equal(keyInSite('abc123/x/v1.pdf', 'ABC/../'), false);
});
