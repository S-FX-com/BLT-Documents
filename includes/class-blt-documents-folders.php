<?php
/**
 * Folders data-access layer.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the {prefix}blt_documents_folders table.
 */
class BLT_Documents_Folders {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return BLT_Documents_Schema::folders_table();
	}

	/**
	 * Create a folder.
	 *
	 * @param string $name       Display name.
	 * @param int    $parent_id  Parent folder id (0 = top level).
	 * @param int    $sort_order Sort order.
	 * @return int New folder id (0 on failure).
	 */
	public static function create( $name, $parent_id = 0, $sort_order = 0 ) {
		global $wpdb;

		$name = sanitize_text_field( $name );

		if ( '' === trim( $name ) ) {
			return 0;
		}

		$data = array(
			'parent_id'  => absint( $parent_id ),
			'name'       => substr( $name, 0, 191 ),
			'slug'       => self::unique_slug( $name, absint( $parent_id ) ),
			'sort_order' => (int) $sort_order,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a folder's name / parent / sort order.
	 *
	 * @param int                 $id     Folder id.
	 * @param array<string,mixed> $fields Fields to change (name, parent_id, sort_order).
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$id   = absint( $id );
		$data = array();

		if ( isset( $fields['name'] ) ) {
			$name = sanitize_text_field( $fields['name'] );

			if ( '' === trim( $name ) ) {
				return false;
			}

			$parent      = isset( $fields['parent_id'] ) ? absint( $fields['parent_id'] ) : self::parent_of( $id );
			$data['name'] = substr( $name, 0, 191 );
			$data['slug'] = self::unique_slug( $name, $parent, $id );
		}

		if ( isset( $fields['parent_id'] ) ) {
			$data['parent_id'] = absint( $fields['parent_id'] );
		}

		if ( isset( $fields['sort_order'] ) ) {
			$data['sort_order'] = (int) $fields['sort_order'];
		}

		if ( empty( $data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Get a folder row by id.
	 *
	 * @param int $id Folder id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Get a folder by slug (optionally within a parent).
	 *
	 * @param string $slug      Folder slug.
	 * @param int    $parent_id Parent id.
	 * @return object|null
	 */
	public static function get_by_slug( $slug, $parent_id = 0 ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slug = %s AND parent_id = %d LIMIT 1",
				sanitize_title( $slug ),
				absint( $parent_id )
			)
		);
	}

	/**
	 * All folders, ordered for display.
	 *
	 * @return array<int,object>
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC" );

		return $rows ? $rows : array();
	}

	/**
	 * Delete a folder (does not touch its files — callers must reassign or
	 * block deletion of non-empty folders).
	 *
	 * @param int $id Folder id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete( self::table(), array( 'id' => absint( $id ) ) );
	}

	/**
	 * Parent id of a folder (0 if none/unknown).
	 *
	 * @param int $id Folder id.
	 * @return int
	 */
	private static function parent_of( $id ) {
		$folder = self::get( $id );
		return $folder ? (int) $folder->parent_id : 0;
	}

	/**
	 * Build a slug unique within the parent folder.
	 *
	 * @param string $name       Source name.
	 * @param int    $parent_id  Parent id.
	 * @param int    $exclude_id Folder id to exclude (for updates).
	 * @return string
	 */
	private static function unique_slug( $name, $parent_id, $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table();
		$base  = sanitize_title( $name );

		if ( '' === $base ) {
			$base = 'folder';
		}

		$slug   = $base;
		$suffix = 2;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND parent_id = %d AND id <> %d LIMIT 1",
					$slug,
					absint( $parent_id ),
					absint( $exclude_id )
				)
			);

			if ( ! $exists ) {
				return $slug;
			}

			$slug = $base . '-' . $suffix;
			++$suffix;
		}
	}
}
