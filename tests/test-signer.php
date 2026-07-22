<?php
/**
 * Tests for BLT_Documents_Signer — must agree with the Worker's auth.ts.
 *
 * @package Blt_Documents
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers BLT_Documents_Signer
 */
class Test_BLT_Documents_Signer extends TestCase {

	/**
	 * PHP's HMAC engine must match the known RFC-style vector the Worker uses.
	 */
	public function test_known_hmac_vector() {
		$this->assertSame(
			'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8',
			hash_hmac( 'sha256', 'The quick brown fox jumps over the lazy dog', 'key' )
		);
	}

	/**
	 * sign() signs exactly "{ts}.{message}" with hex output.
	 */
	public function test_sign_format() {
		$ts      = 1700000000;
		$message = 'payload';
		$secret  = 'shared-secret';

		$this->assertSame(
			hash_hmac( 'sha256', $ts . '.' . $message, $secret ),
			BLT_Documents_Signer::sign( $ts, $message, $secret )
		);
	}

	/**
	 * ts is cast to int before signing (float/string safe).
	 */
	public function test_sign_casts_ts_to_int() {
		$this->assertSame(
			BLT_Documents_Signer::sign( 42, 'm', 's' ),
			BLT_Documents_Signer::sign( '42', 'm', 's' )
		);
	}

	/**
	 * base64url output is URL-safe and unpadded.
	 */
	public function test_base64url_is_url_safe() {
		$encoded = BLT_Documents_Signer::base64url_encode( '{"op":"get","key":"a/b/c"}??>>' );
		$this->assertStringNotContainsString( '+', $encoded );
		$this->assertStringNotContainsString( '/', $encoded );
		$this->assertStringNotContainsString( '=', $encoded );
	}

	/**
	 * headers() produces a self-consistent, verifiable signature.
	 */
	public function test_headers_signature_verifies() {
		$secret  = 'shared-secret';
		$control = array( 'op' => 'get', 'key' => 'site/a/b/v1.pdf' );
		$headers = BLT_Documents_Signer::headers( $control, $secret );

		$this->assertSame( 'Bearer ' . $secret, $headers['Authorization'] );
		$this->assertArrayHasKey( 'X-BLT-Timestamp', $headers );
		$this->assertArrayHasKey( 'X-BLT-Control', $headers );

		$expected = hash_hmac( 'sha256', $headers['X-BLT-Timestamp'] . '.' . $headers['X-BLT-Control'], $secret );
		$this->assertSame( $expected, $headers['X-BLT-Signature'] );
	}
}
