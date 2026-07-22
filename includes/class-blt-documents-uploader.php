<?php
/**
 * Upload / versioning service.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an uploaded file into a new immutable version: validates it, pushes
 * the bytes to R2 through the Worker, records the version, and repoints the
 * file's current_version_id. Prior versions are never overwritten or removed.
 */
class BLT_Documents_Uploader {

	/**
	 * Default allowed extension => mime map for governance documents.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_mimes() {
		$mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt'  => 'text/plain',
			'rtf'  => 'application/rtf',
			'csv'  => 'text/csv',
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		);

		/**
		 * Filter the allowed document extensions/mime types.
		 *
		 * @param array<string,string> $mimes Extension => mime map.
		 */
		return apply_filters( 'blt_documents_allowed_mimes', $mimes );
	}

	/**
	 * Validate a $_FILES upload entry.
	 *
	 * @param array<string,mixed> $upload A single $_FILES[...] entry.
	 * @return array{ext:string,mime:string}|WP_Error
	 */
	public static function validate( $upload ) {
		if ( ! is_array( $upload ) || ! isset( $upload['tmp_name'], $upload['name'], $upload['error'] ) ) {
			return new WP_Error( 'blt_documents_no_file', __( 'No file was uploaded.', 'blt-documents' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			return new WP_Error( 'blt_documents_upload_error', __( 'The file failed to upload. Please try again.', 'blt-documents' ) );
		}

		if ( ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'blt_documents_not_uploaded', __( 'Invalid upload source.', 'blt-documents' ) );
		}

		$max_bytes = absint( BLT_Documents_Settings::get( 'max_upload_mb', 50 ) ) * 1024 * 1024;

		if ( $max_bytes > 0 && (int) $upload['size'] > $max_bytes ) {
			return new WP_Error(
				'blt_documents_too_large',
				sprintf(
					/* translators: %d: maximum size in megabytes. */
					__( 'File exceeds the maximum upload size of %d MB.', 'blt-documents' ),
					absint( BLT_Documents_Settings::get( 'max_upload_mb', 50 ) )
				)
			);
		}

		$allowed = self::allowed_mimes();
		$check   = wp_check_filetype_and_ext( $upload['tmp_name'], $upload['name'], $allowed );
		$ext     = $check['ext'] ? $check['ext'] : strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );

		if ( ! $ext || ! isset( $allowed[ $ext ] ) ) {
			return new WP_Error(
				'blt_documents_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed extensions. */
					__( 'Unsupported file type. Allowed: %s.', 'blt-documents' ),
					implode( ', ', array_keys( $allowed ) )
				)
			);
		}

		$mime = $check['type'] ? $check['type'] : $allowed[ $ext ];

		return array( 'ext' => $ext, 'mime' => $mime );
	}

	/**
	 * Store a new version for an existing file from an HTTP upload.
	 *
	 * @param int                 $file_id File id.
	 * @param array<string,mixed> $upload  A single $_FILES[...] entry.
	 * @param string              $notes   Optional changelog note.
	 * @return int|WP_Error New version id, or error.
	 */
	public static function store_version( $file_id, $upload, $notes = '' ) {
		$file = BLT_Documents_Files::get( $file_id );

		if ( ! $file ) {
			return new WP_Error( 'blt_documents_no_file_record', __( 'Document not found.', 'blt-documents' ) );
		}

		if ( ! BLT_Documents_Settings::is_configured() ) {
			return new WP_Error( 'blt_documents_not_configured', __( 'Connect the Cloudflare Worker in Settings before uploading.', 'blt-documents' ) );
		}

		$valid = self::validate( $upload );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::persist( $file, $upload['tmp_name'], $upload['name'], $valid['mime'], $valid['ext'], $notes );
	}

	/**
	 * Store a new version from a trusted local file path (migration / WP-CLI).
	 *
	 * Unlike store_version(), this does not require an HTTP upload; the caller
	 * is responsible for providing a legitimate local path.
	 *
	 * @param int    $file_id       File id.
	 * @param string $path          Absolute local path to the source file.
	 * @param string $original_name Original filename to record.
	 * @param string $notes         Optional note.
	 * @return int|WP_Error New version id, or error.
	 */
	public static function store_version_from_path( $file_id, $path, $original_name, $notes = '' ) {
		$file = BLT_Documents_Files::get( $file_id );

		if ( ! $file ) {
			return new WP_Error( 'blt_documents_no_file_record', __( 'Document not found.', 'blt-documents' ) );
		}

		if ( ! BLT_Documents_Settings::is_configured() ) {
			return new WP_Error( 'blt_documents_not_configured', __( 'Connect the Cloudflare Worker in Settings before importing.', 'blt-documents' ) );
		}

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'blt_documents_unreadable', __( 'Source file is not readable.', 'blt-documents' ) );
		}

		$allowed = self::allowed_mimes();
		$check   = wp_check_filetype_and_ext( $path, $original_name, $allowed );
		$ext     = $check['ext'] ? $check['ext'] : strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! $ext || ! isset( $allowed[ $ext ] ) ) {
			return new WP_Error( 'blt_documents_bad_type', __( 'Unsupported file type for import.', 'blt-documents' ) );
		}

		$mime = $check['type'] ? $check['type'] : $allowed[ $ext ];

		return self::persist( $file, $path, $original_name, $mime, $ext, $notes );
	}

	/**
	 * Shared version-persistence core.
	 *
	 * Order matters for correctness under concurrency: the version number (and
	 * therefore the R2 key) is RESERVED by inserting the version row first,
	 * relying on the UNIQUE(file_id, version_number) index to serialize racing
	 * uploads. Only once a number is exclusively ours do we write the bytes to
	 * R2 — so two concurrent uploads can never derive the same object key, and
	 * a failed upload is rolled back rather than left as an orphaned object.
	 *
	 * @param object $file          File row.
	 * @param string $path          Local source path.
	 * @param string $original_name Original filename.
	 * @param string $mime          Content type.
	 * @param string $ext           Extension (no dot).
	 * @param string $notes         Optional note.
	 * @return int|WP_Error
	 */
	private static function persist( $file, $path, $original_name, $mime, $ext, $notes ) {
		$folder      = $file->folder_id ? BLT_Documents_Folders::get( $file->folder_id ) : null;
		$folder_slug = $folder ? $folder->slug : 'root';
		$file_slug   = BLT_Documents_Files::file_slug( $file );
		$sha256      = hash_file( 'sha256', $path );
		$size        = (int) filesize( $path );

		// Reserve a version number + key atomically. On a UNIQUE collision with
		// a concurrent upload, create() returns 0; re-read the next number and
		// retry so both uploads end up with distinct numbers/keys.
		$version_id = 0;
		$key        = '';

		for ( $attempt = 0; $attempt < 5 && ! $version_id; $attempt++ ) {
			$version_no = BLT_Documents_Versions::next_version_number( $file->id );
			$key        = BLT_Documents_Worker_Client::object_key( $folder_slug, $file_slug, $version_no, $ext );

			$version_id = BLT_Documents_Versions::create(
				array(
					'file_id'           => $file->id,
					'version_number'    => $version_no,
					'r2_key'            => $key,
					'original_filename' => $original_name,
					'mime_type'         => $mime,
					'file_size'         => $size,
					'sha256'            => $sha256,
					'uploaded_by'       => get_current_user_id(),
					'notes'             => $notes,
				)
			);
		}

		if ( ! $version_id ) {
			return new WP_Error( 'blt_documents_version_failed', __( 'Could not reserve a version number for this document. Please try again.', 'blt-documents' ) );
		}

		// The number/key is now exclusively ours — write the bytes.
		$put = BLT_Documents_Worker_Client::put_object( $key, $path, $mime, $sha256 );

		if ( is_wp_error( $put ) ) {
			// Roll back the reservation; no bytes were committed to R2.
			BLT_Documents_Versions::delete( $version_id );
			return $put;
		}

		BLT_Documents_Files::set_current_version( $file->id, $version_id );

		return $version_id;
	}
}
