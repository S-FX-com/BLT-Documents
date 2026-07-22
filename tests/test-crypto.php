<?php
/**
 * Tests for BLT_Documents_Crypto (at-rest secret encryption).
 *
 * @package Blt_Documents
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers BLT_Documents_Crypto
 */
class Test_BLT_Documents_Crypto extends TestCase {

	/**
	 * Encrypt/decrypt round-trips to the original plaintext.
	 */
	public function test_round_trip() {
		$secret    = 'super-secret-worker-token-0123456789abcdef';
		$envelope  = BLT_Documents_Crypto::encrypt( $secret );
		$recovered = BLT_Documents_Crypto::decrypt( $envelope );

		$this->assertSame( $secret, $recovered );
	}

	/**
	 * The ciphertext is tagged and not equal to the plaintext.
	 */
	public function test_envelope_is_tagged() {
		$envelope = BLT_Documents_Crypto::encrypt( 'plaintext-value' );

		$this->assertNotSame( 'plaintext-value', $envelope );
		$this->assertRegExp( '/^(bd1:|bd2:|b64:)/', $envelope );
	}

	/**
	 * Empty input encrypts and decrypts to empty string.
	 */
	public function test_empty() {
		$this->assertSame( '', BLT_Documents_Crypto::encrypt( '' ) );
		$this->assertSame( '', BLT_Documents_Crypto::decrypt( '' ) );
	}

	/**
	 * A strong AEAD backend is available in the test environment.
	 */
	public function test_strong_backend_available() {
		$this->assertTrue( BLT_Documents_Crypto::is_strong() );
	}
}
