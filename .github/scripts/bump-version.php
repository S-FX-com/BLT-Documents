<?php
/**
 * CI version bump for BLT Documents (semver).
 *
 * The plugin header `Version:` line is the source of truth. This rewrites the
 * three places the version lives — the header, the BLT_DOCUMENTS_VERSION
 * constant, and readme.txt's `Stable tag:` — and prints the new version.
 *
 * Pure functions are unit-testable; the CLI block only runs when invoked
 * directly, so tests can include this file without side effects.
 *
 * @package Blt_Documents
 */

/**
 * Compute the next version for a bump level.
 *
 * @param string $current Current X.Y.Z version.
 * @param string $level   'major' | 'minor' | 'patch'.
 * @return string
 */
function blt_documents_next_version( $current, $level ) {
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', (string) $current, $m ) ) {
		throw new InvalidArgumentException( "Not a X.Y.Z version: {$current}" );
	}

	list( , $major, $minor, $patch ) = array_map( 'intval', $m );

	switch ( $level ) {
		case 'major':
			return ( $major + 1 ) . '.0.0';
		case 'minor':
			return $major . '.' . ( $minor + 1 ) . '.0';
		case 'patch':
			return $major . '.' . $minor . '.' . ( $patch + 1 );
	}

	throw new InvalidArgumentException( "Unknown bump level: {$level}" );
}

/**
 * Apply a bump across the plugin file and readme.txt.
 *
 * @param string $plugin_file Path to blt-documents.php.
 * @param string $readme      Path to readme.txt.
 * @param string $level       Bump level.
 * @return array{version:string,plugin_file:string,readme:string}
 */
function blt_documents_apply_bump( $plugin_file, $readme, $level ) {
	$plugin = file_get_contents( $plugin_file );
	if ( false === $plugin ) {
		throw new RuntimeException( "Cannot read {$plugin_file}" );
	}

	if ( ! preg_match( '/^ \* Version:\s*([0-9.]+)\s*$/m', $plugin, $vm ) ) {
		throw new RuntimeException( 'Could not find the Version: header.' );
	}

	$current = trim( $vm[1] );
	$next    = blt_documents_next_version( $current, $level );

	$plugin = preg_replace_callback(
		'/^( \* Version:\s*)[0-9.]+(\s*)$/m',
		function ( $m ) use ( $next ) {
			return $m[1] . $next . $m[2];
		},
		$plugin,
		1,
		$count_header
	);

	$plugin = preg_replace(
		"/define\(\s*'BLT_DOCUMENTS_VERSION',\s*'[0-9.]+'\s*\)/",
		"define( 'BLT_DOCUMENTS_VERSION', '{$next}' )",
		$plugin,
		1,
		$count_const
	);

	if ( 1 !== $count_header || 1 !== $count_const ) {
		throw new RuntimeException( 'Version header/constant did not match exactly once.' );
	}

	file_put_contents( $plugin_file, $plugin );

	$readme_body = file_get_contents( $readme );
	if ( false === $readme_body ) {
		throw new RuntimeException( "Cannot read {$readme}" );
	}

	$readme_body = preg_replace( '/^Stable tag:\s*[0-9.]+\s*$/m', "Stable tag: {$next}", $readme_body, 1, $count_readme );

	if ( 1 !== $count_readme ) {
		throw new RuntimeException( 'Stable tag did not match exactly once.' );
	}

	file_put_contents( $readme, $readme_body );

	return array(
		'version'     => $next,
		'plugin_file' => $plugin_file,
		'readme'      => $readme,
	);
}

// CLI entry point (guarded so tests can include this file safely).
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$level = isset( $argv[1] ) ? $argv[1] : 'patch';
	$root  = dirname( __DIR__, 2 );
	$out   = blt_documents_apply_bump( $root . '/blt-documents.php', $root . '/readme.txt', $level );
	echo $out['version'] . "\n";
}
