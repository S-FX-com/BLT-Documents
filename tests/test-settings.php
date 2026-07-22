<?php
/**
 * Tests for BLT_Documents_Settings.
 *
 * @package Blt_Documents
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers BLT_Documents_Settings
 */
class Test_BLT_Documents_Settings extends TestCase {

	/**
	 * Reset the in-memory option store before each test.
	 */
	protected function setUp(): void {
		$GLOBALS['blt_test_options'] = array();
	}

	/**
	 * all() merges stored values over defaults.
	 */
	public function test_defaults_merge() {
		$all = BLT_Documents_Settings::all();
		$this->assertSame( 50, $all['max_upload_mb'] );
		$this->assertIsArray( $all['file_types'] );
		$this->assertContains( 'Bylaws', $all['file_types'] );
	}

	/**
	 * A blank submitted secret preserves the stored (encrypted) value.
	 */
	public function test_blank_secret_preserves_existing() {
		BLT_Documents_Settings::save( array( 'worker_secret' => 'first-secret' ) );
		$this->assertSame( 'first-secret', BLT_Documents_Settings::get( 'worker_secret' ) );

		BLT_Documents_Settings::save( array( 'worker_secret' => '' ) );
		$this->assertSame( 'first-secret', BLT_Documents_Settings::get( 'worker_secret' ) );
	}

	/**
	 * The secret is never stored in plaintext.
	 */
	public function test_secret_encrypted_at_rest() {
		BLT_Documents_Settings::save( array( 'worker_secret' => 'plain-token' ) );
		$stored = $GLOBALS['blt_test_options']['blt_documents_settings']['worker_secret'];
		$this->assertNotSame( 'plain-token', $stored );
		$this->assertStringContainsString( ':', $stored );
	}

	/**
	 * max_upload_mb is clamped to a sane range.
	 */
	public function test_max_upload_clamped() {
		BLT_Documents_Settings::save( array( 'max_upload_mb' => 99999 ) );
		$this->assertSame( 500, BLT_Documents_Settings::get( 'max_upload_mb' ) );

		BLT_Documents_Settings::save( array( 'max_upload_mb' => 0 ) );
		$this->assertSame( 1, BLT_Documents_Settings::get( 'max_upload_mb' ) );
	}

	/**
	 * file_types accepts a newline string and de-duplicates.
	 */
	public function test_file_types_from_string() {
		$clean = BLT_Documents_Settings::sanitize_file_types( "Bylaws\nMinutes\nBylaws\n\n Policy " );
		$this->assertSame( array( 'Bylaws', 'Minutes', 'Policy' ), $clean );
	}

	/**
	 * ensure_site_id() generates a stable id and does not change it.
	 */
	public function test_ensure_site_id_stable() {
		$first  = BLT_Documents_Settings::ensure_site_id();
		$second = BLT_Documents_Settings::ensure_site_id();
		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * is_configured() requires url, secret, and site id.
	 */
	public function test_is_configured() {
		$this->assertFalse( BLT_Documents_Settings::is_configured() );

		BLT_Documents_Settings::save(
			array(
				'worker_url'    => 'https://docs.example.com',
				'worker_secret' => 'a-secret',
			)
		);
		BLT_Documents_Settings::ensure_site_id();

		$this->assertTrue( BLT_Documents_Settings::is_configured() );
	}
}
