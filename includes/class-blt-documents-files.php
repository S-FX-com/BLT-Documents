<?php
/**
 * Files data-access layer.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the {prefix}blt_documents_files table.
 */
class BLT_Documents_Files {

	const STATUSES = array( 'published', 'draft', 'trashed' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return BLT_Documents_Schema::files_table();
	}

	/**
	 * Create a file record (no version yet).
	 *
	 * @param array<string,mixed> $data folder_id, title, file_type, status, sort_order.
	 * @return int New file id (0 on failure).
	 */
	public static function create( array $data ) {
		global $wpdb;

		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === trim( $title ) ) {
			return 0;
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true )
			? $data['status']
			: 'published';

		$row = array(
			'folder_id'  => isset( $data['folder_id'] ) ? absint( $data['folder_id'] ) : 0,
			'title'      => substr( $title, 0, 255 ),
			'file_type'  => isset( $data['file_type'] ) ? substr( sanitize_text_field( $data['file_type'] ), 0, 64 ) : '',
			'status'     => $status,
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $row );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable file fields (title, file_type, folder_id, status, sort_order).
	 *
	 * @param int                 $id     File id.
	 * @param array<string,mixed> $fields Fields to change.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$data = array();

		if ( isset( $fields['title'] ) ) {
			$title = sanitize_text_field( $fields['title'] );

			if ( '' === trim( $title ) ) {
				return false;
			}

			$data['title'] = substr( $title, 0, 255 );
		}

		if ( isset( $fields['file_type'] ) ) {
			$data['file_type'] = substr( sanitize_text_field( $fields['file_type'] ), 0, 64 );
		}

		if ( isset( $fields['folder_id'] ) ) {
			$data['folder_id'] = absint( $fields['folder_id'] );
		}

		if ( isset( $fields['status'] ) && in_array( $fields['status'], self::STATUSES, true ) ) {
			$data['status'] = $fields['status'];
		}

		if ( isset( $fields['sort_order'] ) ) {
			$data['sort_order'] = (int) $fields['sort_order'];
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $data, array( 'id' => absint( $id ) ) );
	}

	/**
	 * Point a file at a new current version and bump updated_at.
	 *
	 * @param int $file_id    File id.
	 * @param int $version_id Version id.
	 * @return bool
	 */
	public static function set_current_version( $file_id, $version_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			self::table(),
			array(
				'current_version_id' => absint( $version_id ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => absint( $file_id ) )
		);
	}

	/**
	 * Get a file row by id.
	 *
	 * @param int $id File id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Files in a folder for the admin list (excludes trashed).
	 *
	 * @param int $folder_id Folder id.
	 * @return array<int,object>
	 */
	public static function get_by_folder( $folder_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE folder_id = %d AND status <> 'trashed' ORDER BY sort_order ASC, title ASC",
				absint( $folder_id )
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Published files (with current-version metadata) for front-end display.
	 *
	 * @param array<string,mixed> $args folder_id, file_type, orderby, order, limit.
	 * @return array<int,object> Rows with joined ext/original_filename/file_size/version_number/updated_at.
	 */
	public static function for_display( array $args = array() ) {
		global $wpdb;

		$files    = self::table();
		$versions = BLT_Documents_Schema::versions_table();

		$args = wp_parse_args(
			$args,
			array(
				'folder_id' => 0,
				'file_type' => '',
				'orderby'   => 'updated_at',
				'order'     => 'DESC',
				'limit'     => 50,
			)
		);

		$where  = "f.status = 'published' AND f.current_version_id IS NOT NULL";
		$params = array();

		if ( absint( $args['folder_id'] ) > 0 ) {
			$where   .= ' AND f.folder_id = %d';
			$params[] = absint( $args['folder_id'] );
		}

		if ( '' !== (string) $args['file_type'] ) {
			$where   .= ' AND f.file_type = %s';
			$params[] = sanitize_text_field( $args['file_type'] );
		}

		$allowed_orderby = array(
			'updated_at' => 'f.updated_at',
			'title'      => 'f.title',
			'sort_order' => 'f.sort_order',
			'file_type'  => 'f.file_type',
		);
		$orderby = isset( $allowed_orderby[ $args['orderby'] ] ) ? $allowed_orderby[ $args['orderby'] ] : 'f.updated_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$limit   = max( 1, min( 500, absint( $args['limit'] ) ) );

		$sql = "SELECT f.id, f.title, f.file_type, f.updated_at,
				v.version_number, v.original_filename, v.file_size, v.r2_key
			FROM {$files} f
			INNER JOIN {$versions} v ON v.id = f.current_version_id
			WHERE {$where}
			ORDER BY {$orderby} {$order}
			LIMIT %d";

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return $rows ? $rows : array();
	}

	/**
	 * Soft-delete: move a file to the trashed status (versions/R2 untouched).
	 *
	 * @param int $id File id.
	 * @return bool
	 */
	public static function trash( $id ) {
		return self::update( $id, array( 'status' => 'trashed' ) );
	}

	/**
	 * Move every file (any status, including trashed) from one folder to
	 * another. Used when a folder is deleted so no file row is left pointing at
	 * a non-existent folder_id.
	 *
	 * @param int $from_folder_id Source folder id.
	 * @param int $to_folder_id   Destination folder id (0 = Uncategorized).
	 * @return void
	 */
	public static function reassign_folder( $from_folder_id, $to_folder_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array( 'folder_id' => absint( $to_folder_id ) ),
			array( 'folder_id' => absint( $from_folder_id ) )
		);
	}

	/**
	 * Hard-delete a file row (used only by opt-in uninstall purge).
	 *
	 * @param int $id File id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete( self::table(), array( 'id' => absint( $id ) ) );
	}

	/**
	 * Stable, folder-unique slug for R2 keys (id-prefixed so title edits are safe).
	 *
	 * @param object $file File row.
	 * @return string
	 */
	public static function file_slug( $file ) {
		$title = isset( $file->title ) ? sanitize_title( $file->title ) : '';
		return absint( $file->id ) . ( '' !== $title ? '-' . $title : '' );
	}
}
