<?php
/**
 * Versions data-access layer.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the {prefix}blt_documents_versions table.
 *
 * Every version is an immutable record pointing at its own R2 object. Rows are
 * never updated or deleted through the normal admin flow — only appended.
 */
class BLT_Documents_Versions {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return BLT_Documents_Schema::versions_table();
	}

	/**
	 * The next version number for a file (1-based, auto-incrementing per file).
	 *
	 * @param int $file_id File id.
	 * @return int
	 */
	public static function next_version_number( $file_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(version_number) FROM {$table} WHERE file_id = %d", absint( $file_id ) )
		);

		return $max + 1;
	}

	/**
	 * Insert a new version record.
	 *
	 * @param array<string,mixed> $data file_id, version_number, r2_key,
	 *                                   original_filename, mime_type, file_size,
	 *                                   sha256, uploaded_by, notes.
	 * @return int New version id (0 on failure).
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = array(
			'file_id'           => absint( $data['file_id'] ),
			'version_number'    => absint( $data['version_number'] ),
			'r2_key'            => substr( (string) $data['r2_key'], 0, 512 ),
			'original_filename' => substr( sanitize_file_name( (string) $data['original_filename'] ), 0, 255 ),
			'mime_type'         => substr( (string) $data['mime_type'], 0, 128 ),
			'file_size'         => isset( $data['file_size'] ) ? absint( $data['file_size'] ) : 0,
			'sha256'            => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $data['sha256'] ) ),
			'uploaded_by'       => isset( $data['uploaded_by'] ) ? absint( $data['uploaded_by'] ) : get_current_user_id(),
			'notes'             => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			'created_at'        => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $row );

		// Return 0 on failure (e.g. the UNIQUE(file_id, version_number) guard
		// firing under a concurrent upload) so callers can retry — never a
		// stale insert_id from an earlier successful insert.
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Delete a single version row by id (used to roll back a failed upload
	 * reservation before any bytes are committed).
	 *
	 * @param int $id Version id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => absint( $id ) ) );
	}

	/**
	 * Get a version row by id.
	 *
	 * @param int $id Version id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * All versions for a file, newest first.
	 *
	 * @param int $file_id File id.
	 * @return array<int,object>
	 */
	public static function list_for_file( $file_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE file_id = %d ORDER BY version_number DESC",
				absint( $file_id )
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Delete every version row for a file (opt-in uninstall purge only).
	 *
	 * @param int $file_id File id.
	 * @return void
	 */
	public static function delete_for_file( $file_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'file_id' => absint( $file_id ) ) );
	}
}
