<?php
/**
 * Client for the self-hosted Cloudflare Worker (R2 enforcement plane).
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs signed, server-to-server requests to the Worker. The browser never
 * talks to the Worker directly — WordPress is the only caller, and it holds
 * the shared secret. Every request is HMAC-signed and short-TTL.
 */
class BLT_Documents_Worker_Client {

	/**
	 * Build the immutable R2 object key for a version.
	 *
	 * Structure: {site-id}/{folder-slug}/{file-slug}/v{n}.{ext}
	 *
	 * @param string $folder_slug Folder slug.
	 * @param string $file_slug   File slug.
	 * @param int    $version     Version number.
	 * @param string $ext         File extension (no dot).
	 * @return string
	 */
	public static function object_key( $folder_slug, $file_slug, $version, $ext ) {
		$site = sanitize_key( (string) BLT_Documents_Settings::get( 'site_id' ) );
		$parts = array(
			$site,
			sanitize_title( $folder_slug ),
			sanitize_title( $file_slug ),
			'v' . absint( $version ) . ( '' !== $ext ? '.' . preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) ) : '' ),
		);

		return implode( '/', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Normalized Worker base URL (no trailing slash).
	 *
	 * @return string
	 */
	private static function base_url() {
		return untrailingslashit( (string) BLT_Documents_Settings::get( 'worker_url' ) );
	}

	/**
	 * Verify connectivity + credentials against the Worker.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function health() {
		$secret = (string) BLT_Documents_Settings::get( 'worker_secret' );
		$base   = self::base_url();

		if ( '' === $base || '' === $secret ) {
			return array(
				'ok'      => false,
				'message' => __( 'Worker URL and secret must be set first.', 'blt-documents' ),
			);
		}

		$control = array( 'op' => 'health', 'site' => (string) BLT_Documents_Settings::get( 'site_id' ) );

		$response = wp_remote_post(
			$base . '/v1/health',
			array(
				'timeout' => 15,
				'headers' => BLT_Documents_Signer::headers( $control, $secret ),
				'body'    => '',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array( 'ok' => true, 'message' => __( 'Connection OK.', 'blt-documents' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Worker returned HTTP %d.', 'blt-documents' ),
			$code
		);

		return array( 'ok' => false, 'message' => $msg );
	}

	/**
	 * Upload a local file to R2 under the given key.
	 *
	 * @param string $key       R2 object key.
	 * @param string $file_path Absolute path to the local file.
	 * @param string $mime      Content type.
	 * @param string $sha256    Lowercase hex SHA-256 of the file (integrity).
	 * @return true|WP_Error
	 */
	public static function put_object( $key, $file_path, $mime, $sha256 ) {
		$secret = (string) BLT_Documents_Settings::get( 'worker_secret' );
		$base   = self::base_url();

		if ( '' === $base || '' === $secret ) {
			return new WP_Error( 'blt_documents_not_configured', __( 'The Worker connection is not configured.', 'blt-documents' ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'blt_documents_unreadable', __( 'Upload file is not readable.', 'blt-documents' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file, not a remote fetch.
		$bytes = file_get_contents( $file_path );

		if ( false === $bytes ) {
			return new WP_Error( 'blt_documents_read_failed', __( 'Could not read the upload file.', 'blt-documents' ) );
		}

		$control = array(
			'op'           => 'put',
			'key'          => $key,
			'sha256'       => $sha256,
			'content_type' => $mime,
			'site'         => (string) BLT_Documents_Settings::get( 'site_id' ),
		);

		$response = wp_remote_post(
			$base . '/v1/put',
			array(
				'timeout' => 60,
				'headers' => BLT_Documents_Signer::headers(
					$control,
					$secret,
					array( 'Content-Type' => 'application/octet-stream' )
				),
				'body'    => $bytes,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code || 201 === $code ) {
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Upload failed (HTTP %d).', 'blt-documents' ),
			$code
		);

		return new WP_Error( 'blt_documents_put_failed', $msg );
	}

	/**
	 * Fetch an object from R2, streamed to a temporary local file.
	 *
	 * Streaming to disk keeps memory bounded regardless of file size; the
	 * caller is responsible for readfile()-ing and unlink()-ing the temp file.
	 *
	 * @param string $key R2 object key.
	 * @return array{file:string,mime:string}|WP_Error
	 */
	public static function get_object_to_file( $key ) {
		$secret = (string) BLT_Documents_Settings::get( 'worker_secret' );
		$base   = self::base_url();

		if ( '' === $base || '' === $secret ) {
			return new WP_Error( 'blt_documents_not_configured', __( 'The Worker connection is not configured.', 'blt-documents' ) );
		}

		$tmp = wp_tempnam( 'blt-documents-' );

		if ( ! $tmp ) {
			return new WP_Error( 'blt_documents_tmp_failed', __( 'Could not create a temporary file.', 'blt-documents' ) );
		}

		$control = array(
			'op'   => 'get',
			'key'  => $key,
			'site' => (string) BLT_Documents_Settings::get( 'site_id' ),
		);

		$response = wp_remote_post(
			$base . '/v1/get',
			array(
				'timeout'  => 30,
				'headers'  => BLT_Documents_Signer::headers( $control, $secret ),
				'body'     => '',
				'stream'   => true,
				'filename' => $tmp,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::cleanup( $tmp );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			// On error the (small) JSON body was streamed to $tmp.
			$err = '';
			if ( is_readable( $tmp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file.
				$decoded = json_decode( (string) file_get_contents( $tmp ), true );
				if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
					$err = $decoded['error'];
				}
			}
			self::cleanup( $tmp );

			if ( 404 === $code ) {
				return new WP_Error( 'blt_documents_not_found', __( 'The requested document could not be found in storage.', 'blt-documents' ) );
			}

			return new WP_Error(
				'blt_documents_get_failed',
				'' !== $err ? $err : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Download failed (HTTP %d).', 'blt-documents' ),
					$code
				)
			);
		}

		return array(
			'file' => $tmp,
			'mime' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	/**
	 * Remove a temp file if it exists.
	 *
	 * @param string $path Temp file path.
	 * @return void
	 */
	private static function cleanup( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
