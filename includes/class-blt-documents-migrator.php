<?php
/**
 * Migration from Joomunited's WP File Download.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best-effort importer that pulls existing WP File Download (WPFD) content into
 * BLT Documents: WPFD categories become folders, and each source file is stored
 * in R2 as v1 of a new document.
 *
 * WPFD's storage layout varies by version, so file discovery is intentionally
 * pluggable via the `blt_documents_wpfd_files` filter — the default reader
 * covers the common cases (the wpfd-category taxonomy plus media-library
 * attachments), and a site-specific adapter can supply an exact list.
 *
 * Run it (never destructive to WPFD) with WP-CLI:
 *   wp blt-documents migrate-wpfd --dry-run
 *   wp blt-documents migrate-wpfd
 */
class BLT_Documents_Migrator {

	/**
	 * Register the WP-CLI command when running under WP-CLI.
	 *
	 * @return void
	 */
	public static function register_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'blt-documents migrate-wpfd', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * WP-CLI entry point.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be imported without writing anything.
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function cli( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$report  = self::run( $dry_run );

		foreach ( $report['log'] as $line ) {
			WP_CLI::log( $line );
		}

		WP_CLI::success(
			sprintf(
				'%1$s: %2$d folder(s), %3$d document(s) imported, %4$d skipped, %5$d flagged.',
				$dry_run ? 'Dry run' : 'Migration complete',
				$report['folders'],
				$report['imported'],
				$report['skipped'],
				count( $report['flagged'] )
			)
		);

		foreach ( $report['flagged'] as $flag ) {
			WP_CLI::warning( $flag );
		}
	}

	/**
	 * Perform (or simulate) the migration.
	 *
	 * @param bool $dry_run When true, nothing is written.
	 * @return array{folders:int,imported:int,skipped:int,flagged:array<int,string>,log:array<int,string>}
	 */
	public static function run( $dry_run = false ) {
		$log      = array();
		$flagged  = array();
		$folders  = 0;
		$imported = 0;
		$skipped  = 0;

		$categories = self::discover_categories();
		$files      = self::discover_files();

		$log[] = sprintf( 'Discovered %d categories and %d files in WP File Download.', count( $categories ), count( $files ) );

		// Map WPFD category id => BLT folder id.
		$folder_map = array();

		foreach ( $categories as $cat ) {
			$existing = BLT_Documents_Folders::get_by_slug( sanitize_title( $cat['name'] ) );

			if ( $existing ) {
				$folder_map[ $cat['id'] ] = (int) $existing->id;
				$log[]                    = sprintf( 'Folder already exists: %s', $cat['name'] );
				continue;
			}

			if ( $dry_run ) {
				$log[]                    = sprintf( 'Would create folder: %s', $cat['name'] );
				$folder_map[ $cat['id'] ] = 0;
				++$folders;
				continue;
			}

			$folder_id                = BLT_Documents_Folders::create( $cat['name'] );
			$folder_map[ $cat['id'] ] = $folder_id;
			$log[]                    = sprintf( 'Created folder: %s (#%d)', $cat['name'], $folder_id );
			++$folders;
		}

		foreach ( $files as $file ) {
			$label = isset( $file['title'] ) ? $file['title'] : ( isset( $file['path'] ) ? basename( $file['path'] ) : 'unknown' );

			if ( ! empty( $file['password'] ) || ! empty( $file['expires'] ) ) {
				$flagged[] = sprintf(
					'Flagged (password/expiration not carried to v1): %s',
					$label
				);
			}

			if ( empty( $file['path'] ) || ! is_readable( $file['path'] ) ) {
				$log[] = sprintf( 'Skipped (file not found): %s', $label );
				++$skipped;
				continue;
			}

			$folder_id = isset( $folder_map[ $file['category'] ] ) ? $folder_map[ $file['category'] ] : 0;

			if ( $dry_run ) {
				$log[] = sprintf( 'Would import: %s -> folder #%d', $label, $folder_id );
				++$imported;
				continue;
			}

			$file_id = BLT_Documents_Files::create(
				array(
					'folder_id' => $folder_id,
					'title'     => isset( $file['title'] ) ? $file['title'] : basename( $file['path'] ),
					'file_type' => isset( $file['file_type'] ) ? $file['file_type'] : '',
					'status'    => 'published',
				)
			);

			if ( ! $file_id ) {
				$log[] = sprintf( 'Skipped (could not create record): %s', $label );
				++$skipped;
				continue;
			}

			$result = BLT_Documents_Uploader::store_version_from_path(
				$file_id,
				$file['path'],
				isset( $file['filename'] ) ? $file['filename'] : basename( $file['path'] ),
				__( 'Imported from WP File Download', 'blt-documents' )
			);

			if ( is_wp_error( $result ) ) {
				BLT_Documents_Files::delete( $file_id );
				$log[] = sprintf( 'Skipped (upload failed: %1$s): %2$s', $result->get_error_message(), $label );
				++$skipped;
				continue;
			}

			$log[] = sprintf( 'Imported: %s', $label );
			++$imported;
		}

		return array(
			'folders'  => $folders,
			'imported' => $imported,
			'skipped'  => $skipped,
			'flagged'  => $flagged,
			'log'      => $log,
		);
	}

	/**
	 * Discover WPFD categories.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function discover_categories() {
		$categories = array();

		if ( taxonomy_exists( 'wpfd-category' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'wpfd-category',
					'hide_empty' => false,
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					);
				}
			}
		}

		/**
		 * Filter the discovered WPFD categories.
		 *
		 * @param array<int,array{id:int,name:string}> $categories Categories.
		 */
		return apply_filters( 'blt_documents_wpfd_categories', $categories );
	}

	/**
	 * Discover WPFD files.
	 *
	 * The default reader returns nothing, because WPFD's file storage differs
	 * across versions; supply a site-specific list via the filter below. Each
	 * entry: [ 'title', 'filename', 'path' (absolute), 'category' (WPFD cat id),
	 * 'file_type', 'password' (bool), 'expires' (bool) ].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function discover_files() {
		$files = array();

		/**
		 * Filter the discovered WPFD files to import.
		 *
		 * @param array<int,array<string,mixed>> $files Files to import.
		 */
		return apply_filters( 'blt_documents_wpfd_files', $files );
	}
}
