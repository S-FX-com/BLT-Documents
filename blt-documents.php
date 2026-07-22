<?php
/**
 * Plugin Name:       BLT Documents
 * Plugin URI:        https://s-fx.com/plugins/blt-documents/
 * Description:       Secure, versioned document delivery for governance boards. Files live in a private Cloudflare R2 bucket behind an HMAC-gated Worker — never crawlable, never hotlinkable, with real version history and role-scoped access to prior versions.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            S-FX.com Small Business Solutions
 * Author URI:        https://s-fx.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-documents
 * Domain Path:       /languages
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------- Constants ----------
define( 'BLT_DOCUMENTS_VERSION', '1.0.0' );
define( 'BLT_DOCUMENTS_FILE', __FILE__ );
define( 'BLT_DOCUMENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_DOCUMENTS_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_DOCUMENTS_BASENAME', plugin_basename( __FILE__ ) );
define( 'BLT_DOCUMENTS_REST_NS', 'blt-documents/v1' );

// ---------- Update checker ----------
// Self-hosted updates served from GitHub release assets (the CI-built zip with
// a stable blt-documents/ top-level folder, produced by
// .github/workflows/release.yml) — never from source zipballs, whose folder
// name includes the commit hash and would break the install path.
//
// The repository is public, so update checks work with no credentials. A
// GitHub token is optional; when the BLT_DOCUMENTS_GITHUB_TOKEN wp-config
// constant is defined it is used only to raise the API rate limit
// (60->5000 req/hr) and to keep working if the repo is ever made private.
require_once BLT_DOCUMENTS_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

$blt_documents_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/S-FX-com/BLT-Documents/',
	__FILE__,
	'blt-documents'
);
$blt_documents_update_checker->setBranch( 'main' );
if ( defined( 'BLT_DOCUMENTS_GITHUB_TOKEN' ) && is_string( BLT_DOCUMENTS_GITHUB_TOKEN ) && '' !== BLT_DOCUMENTS_GITHUB_TOKEN ) {
	$blt_documents_update_checker->setAuthentication( BLT_DOCUMENTS_GITHUB_TOKEN );
}
// Only accept the CI-built zip asset; ignore checksums/source archives.
$blt_documents_update_checker->getVcsApi()->enableReleaseAssets( '/^blt-documents(-[\d.]+)?\.zip$/i' );

// ---------- Autoloader ----------
// Resolves BLT_Documents_<Thing> to includes/class-blt-documents-<thing>.php
// (probing admin/ as a fallback), matching the WordPress file-naming scheme
// used across the BLT plugin family.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'BLT_Documents_';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );
		$file     = 'class-blt-documents-' . $relative . '.php';

		foreach ( array( BLT_DOCUMENTS_DIR . 'includes/' . $file, BLT_DOCUMENTS_DIR . 'admin/' . $file ) as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// ---------- Activation / Deactivation ----------
register_activation_hook( __FILE__, array( 'BLT_Documents_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BLT_Documents_Activator', 'deactivate' ) );

// ---------- i18n ----------
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'blt-documents', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// ---------- Boot ----------
add_action(
	'plugins_loaded',
	function () {
		// Idempotent schema upgrade for file-copy updates that skip activation.
		add_action( 'init', array( 'BLT_Documents_Schema', 'maybe_upgrade' ), 0 );

		// Public download endpoint + shortcode front-end.
		BLT_Documents_REST::init();
		BLT_Documents_Render::init();

		// WP-CLI migration command (no-op unless running under WP-CLI).
		BLT_Documents_Migrator::register_cli();

		// Admin UI (folders, files, versioning, settings, shortcode generator).
		if ( is_admin() ) {
			BLT_Documents_Admin::instance()->init();
		}
	}
);

/**
 * Global convenience accessor for the plugin's shared settings/services.
 *
 * @return string Plugin version (kept intentionally trivial; services are static).
 */
function blt_documents() {
	return BLT_DOCUMENTS_VERSION;
}
