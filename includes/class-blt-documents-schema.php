<?php
/**
 * Custom database schema (folders / files / versions).
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the three custom tables that back the plugin.
 *
 * Version history needs real relational structure, so this uses dedicated
 * wpdb tables (dbDelta) rather than CPT meta.
 */
class BLT_Documents_Schema {

	/**
	 * Schema version. Bump when the CREATE TABLE definitions change.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option storing the installed schema version.
	 */
	const DB_VERSION_OPTION = 'blt_documents_db_version';

	/**
	 * Fully-qualified folders table name.
	 *
	 * @return string
	 */
	public static function folders_table() {
		global $wpdb;
		return $wpdb->prefix . 'blt_documents_folders';
	}

	/**
	 * Fully-qualified files table name.
	 *
	 * @return string
	 */
	public static function files_table() {
		global $wpdb;
		return $wpdb->prefix . 'blt_documents_files';
	}

	/**
	 * Fully-qualified versions table name.
	 *
	 * @return string
	 */
	public static function versions_table() {
		global $wpdb;
		return $wpdb->prefix . 'blt_documents_versions';
	}

	/**
	 * Create or upgrade all tables. Idempotent (dbDelta diffs the schema).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$folders         = self::folders_table();
		$files           = self::files_table();
		$versions        = self::versions_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$folders} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(191) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				sort_order INT NOT NULL DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				KEY parent_idx (parent_id),
				UNIQUE KEY parent_slug (parent_id, slug)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$files} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				folder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(255) NOT NULL,
				file_type VARCHAR(64) NOT NULL DEFAULT '',
				current_version_id BIGINT UNSIGNED DEFAULT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'published',
				sort_order INT NOT NULL DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NULL,
				KEY folder_idx (folder_id),
				KEY status_idx (status),
				KEY file_type_idx (file_type)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$versions} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				file_id BIGINT UNSIGNED NOT NULL,
				version_number INT UNSIGNED NOT NULL DEFAULT 1,
				r2_key VARCHAR(512) NOT NULL,
				original_filename VARCHAR(255) NOT NULL DEFAULT '',
				mime_type VARCHAR(128) NOT NULL DEFAULT '',
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				sha256 CHAR(64) NOT NULL DEFAULT '',
				uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				KEY file_idx (file_id),
				UNIQUE KEY file_version (file_id, version_number)
			) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Re-run install() when the stored schema version is stale.
	 *
	 * Hooked on 'init' so file-copy plugin updates (which never fire the
	 * activation hook) still pick up schema changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop all tables and the version option (used by uninstall, opt-in).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$versions = self::versions_table();
		$files    = self::files_table();
		$folders  = self::folders_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$versions}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$files}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$folders}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( self::DB_VERSION_OPTION );
	}
}
