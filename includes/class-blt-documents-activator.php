<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one-time setup on activation. Deactivation is deliberately
 * non-destructive — documents and version history are never removed here.
 */
class BLT_Documents_Activator {

	/**
	 * Install tables, seed settings, ensure a site id, and register roles.
	 *
	 * @return void
	 */
	public static function activate() {
		BLT_Documents_Schema::install();
		BLT_Documents_Settings::seed_defaults();
		BLT_Documents_Settings::ensure_site_id();
		BLT_Documents_Roles::add();
	}

	/**
	 * Deactivation cleanup. Intentionally minimal (data is preserved).
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No-op: never delete documents, versions, or roles on deactivate.
		// Uninstall (uninstall.php) handles opt-in teardown.
	}
}
