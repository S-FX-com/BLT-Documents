<?php
/**
 * REST API — the nonce/capability-gated download proxy.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers wp-json/blt-documents/v1/download.
 *
 * The browser only ever sees this route — the R2 key, the Worker URL, and the
 * HMAC signature are all server-side. Current versions of published documents
 * are public; any prior (historical) version requires the
 * blt_view_document_history capability.
 */
class BLT_Documents_REST {

	/**
	 * Hook route registration and robots.txt disallow.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 1 );
	}

	/**
	 * Register the download route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			BLT_DOCUMENTS_REST_NS,
			'/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'download' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'version' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Keep the download route out of search indexes at the crawler level.
	 *
	 * @param string $output Existing robots.txt body.
	 * @return string
	 */
	public static function robots_txt( $output ) {
		$output .= "\nDisallow: /wp-json/" . BLT_DOCUMENTS_REST_NS . "/\n";
		return $output;
	}

	/**
	 * Permission gate. Historical versions require the history capability;
	 * current versions are public (the callback still enforces published state).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permission_check( $request ) {
		if ( absint( $request['version'] ) > 0 ) {
			return current_user_can( BLT_Documents_Roles::CAP_HISTORY );
		}

		return true;
	}

	/**
	 * Resolve the requested version, fetch it through the Worker, and stream it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Streams + exits on success.
	 */
	public static function download( $request ) {
		$file_id    = absint( $request['id'] );
		$version_id = absint( $request['version'] );
		$file       = BLT_Documents_Files::get( $file_id );

		if ( ! $file || 'trashed' === $file->status ) {
			return self::not_found();
		}

		if ( $version_id > 0 ) {
			// Historical (or explicit) version — capability already checked.
			$version = BLT_Documents_Versions::get( $version_id );

			if ( ! $version || (int) $version->file_id !== $file_id ) {
				return self::not_found();
			}
		} else {
			// Current version. Drafts are not public.
			if ( 'published' !== $file->status
				&& ! current_user_can( BLT_Documents_Roles::CAP_HISTORY )
				&& ! current_user_can( BLT_Documents_Roles::CAP_MANAGE ) ) {
				return self::not_found();
			}

			if ( empty( $file->current_version_id ) ) {
				return self::not_found();
			}

			$version = BLT_Documents_Versions::get( $file->current_version_id );

			if ( ! $version ) {
				return self::not_found();
			}
		}

		$result = BLT_Documents_Worker_Client::get_object_to_file( $version->r2_key );

		if ( is_wp_error( $result ) ) {
			$status = 'blt_documents_not_found' === $result->get_error_code() ? 404 : 502;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		$mime     = '' !== $version->mime_type ? $version->mime_type : ( '' !== $result['mime'] ? $result['mime'] : 'application/octet-stream' );
		$filename = '' !== $version->original_filename ? $version->original_filename : ( 'document-' . $file_id );

		self::stream_and_exit( $result['file'], $filename, $mime );
	}

	/**
	 * Stream a local temp file to the browser as an attachment, then exit.
	 *
	 * @param string $path     Temp file path.
	 * @param string $filename Download filename.
	 * @param string $mime     Content type.
	 * @return void
	 */
	private static function stream_and_exit( $path, $filename, $mime ) {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		$filename = sanitize_file_name( $filename );

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );

		// Flush any buffered output so the binary stream is not corrupted.
		// Break out if a buffer is non-removable (e.g. a caching plugin's), so
		// this can never spin — ob_end_clean() returns false without lowering
		// the level in that case.
		while ( ob_get_level() > 0 ) {
			if ( ! ob_end_clean() ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a local temp file to the client.
		readfile( $path );

		wp_delete_file( $path );

		exit;
	}

	/**
	 * Uniform 404 for anything not reachable by the caller.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error(
			'blt_documents_not_found',
			__( 'Document not found.', 'blt-documents' ),
			array( 'status' => 404 )
		);
	}
}
