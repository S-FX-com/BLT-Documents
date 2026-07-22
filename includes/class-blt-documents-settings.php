<?php
/**
 * Settings storage & retrieval.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the single blt_documents_settings option, including at-rest
 * encryption of the Worker shared secret and the configurable file-type list.
 */
class BLT_Documents_Settings {

	/**
	 * Option key in wp_options.
	 */
	const OPTION = 'blt_documents_settings';

	/**
	 * Default settings values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'worker_url'               => '',
			'worker_secret'            => '',
			'site_id'                  => '',
			'max_upload_mb'            => 50,
			'file_types'               => array( 'Bylaws', 'Rules & Regs', 'Minutes', 'Policy', 'Deed' ),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Seed defaults on activation without clobbering existing values.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		$existing = get_option( self::OPTION, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		update_option( self::OPTION, wp_parse_args( $existing, self::defaults() ) );
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting. The worker_secret is transparently decrypted.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( 'worker_secret' === $key ) {
			return BLT_Documents_Crypto::decrypt( $all['worker_secret'] );
		}

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a sanitized settings array.
	 *
	 * The worker_secret is encrypted at rest; a blank submitted secret
	 * preserves the previously stored value (so the field can render masked).
	 *
	 * @param array<string,mixed> $input Raw input (already unslashed).
	 * @return array<string,mixed> The stored settings.
	 */
	public static function save( array $input ) {
		$current = self::all();
		$clean   = array();

		$clean['worker_url'] = isset( $input['worker_url'] )
			? esc_url_raw( trim( $input['worker_url'] ) )
			: '';

		if ( isset( $input['worker_secret'] ) && '' !== trim( (string) $input['worker_secret'] ) ) {
			$clean['worker_secret'] = BLT_Documents_Crypto::encrypt( trim( (string) $input['worker_secret'] ) );
		} else {
			$clean['worker_secret'] = $current['worker_secret'];
		}

		// site_id is a stable slug; keep the existing one unless a valid new
		// value is supplied (changing it orphans existing R2 objects).
		if ( isset( $input['site_id'] ) && '' !== sanitize_key( $input['site_id'] ) ) {
			$clean['site_id'] = sanitize_key( $input['site_id'] );
		} else {
			$clean['site_id'] = $current['site_id'];
		}

		$clean['max_upload_mb'] = isset( $input['max_upload_mb'] )
			? max( 1, min( 500, absint( $input['max_upload_mb'] ) ) )
			: 50;

		$clean['file_types'] = self::sanitize_file_types(
			isset( $input['file_types'] ) ? $input['file_types'] : $current['file_types']
		);

		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Normalize the file-type list into a de-duplicated array of labels.
	 *
	 * Accepts either an array of labels or a newline-separated string.
	 *
	 * @param mixed $value Raw file-type input.
	 * @return array<int,string>
	 */
	public static function sanitize_file_types( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$out = array();

		foreach ( $value as $label ) {
			$label = sanitize_text_field( (string) $label );
			$label = trim( $label );

			if ( '' !== $label && ! in_array( $label, $out, true ) ) {
				$out[] = $label;
			}
		}

		return $out;
	}

	/**
	 * The configured file-type labels.
	 *
	 * @return array<int,string>
	 */
	public static function file_types() {
		$types = self::get( 'file_types', array() );
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Generate and persist a stable random site id if none is set.
	 *
	 * The site id is the top-level R2 key prefix ({site-id}/folder/file/vN.ext),
	 * so it must never change once objects exist.
	 *
	 * @return string
	 */
	public static function ensure_site_id() {
		$settings = self::all();

		if ( '' !== (string) $settings['site_id'] ) {
			return $settings['site_id'];
		}

		// 12 hex chars is plenty of entropy for a key namespace.
		$settings['site_id'] = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		update_option( self::OPTION, $settings );

		return $settings['site_id'];
	}

	/**
	 * Whether the Worker connection is fully configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== (string) self::get( 'worker_url' )
			&& '' !== (string) self::get( 'worker_secret' )
			&& '' !== (string) self::get( 'site_id' );
	}
}
