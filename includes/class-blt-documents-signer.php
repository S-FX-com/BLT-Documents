<?php
/**
 * HMAC request signer for the Cloudflare Worker contract.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, unit-tested signing helper.
 *
 * The signed message is exactly "{ts}.{message}" (ts cast to int, a literal
 * dot separator, then the message) hashed with HMAC-SHA256 and rendered as
 * LOWERCASE HEX. This mirrors the blt-secure fleet signer so the TypeScript
 * Worker verifier (auth.ts) validates identically. Any byte difference in the
 * message breaks verification, so sign the exact string you transmit.
 *
 * For BLT-Documents the message is a base64url-encoded JSON "control" string
 * describing the operation (op/key/sha256/content_type/site). Binding op+key
 * into the signature means a captured request cannot be replayed against a
 * different object or operation.
 */
class BLT_Documents_Signer {

	/**
	 * Freshness window in seconds. MUST equal the Worker's isFresh() maxSkew.
	 */
	const MAX_SKEW = 300;

	/**
	 * Compute the lowercase-hex HMAC-SHA256 of "{ts}.{message}".
	 *
	 * @param int    $ts      Unix timestamp (seconds).
	 * @param string $message Signed message (typically the base64url control).
	 * @param string $secret  Shared Worker secret.
	 * @return string Lowercase hex signature.
	 */
	public static function sign( $ts, $message, $secret ) {
		return hash_hmac( 'sha256', (int) $ts . '.' . (string) $message, (string) $secret );
	}

	/**
	 * URL-safe base64 encode without padding (matches the Worker decoder).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Build the signed header set for an outbound Worker request.
	 *
	 * @param array<string,mixed> $control Operation descriptor (op/key/...).
	 * @param string              $secret  Shared Worker secret.
	 * @param array<string,string> $extra  Additional headers to merge in.
	 * @return array<string,string>
	 */
	public static function headers( array $control, $secret, array $extra = array() ) {
		$ts          = time();
		$control_b64 = self::base64url_encode( (string) wp_json_encode( $control ) );

		$headers = array(
			'Authorization'   => 'Bearer ' . $secret,
			'X-BLT-Timestamp' => (string) $ts,
			'X-BLT-Control'   => $control_b64,
			'X-BLT-Signature' => self::sign( $ts, $control_b64, $secret ),
		);

		return array_merge( $headers, $extra );
	}
}
