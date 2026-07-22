<?php
/**
 * Uninstall cleanup for BLT Documents.
 *
 * Data deletion is OPT-IN (Settings → Advanced). By default, documents,
 * version records, and settings are preserved so an accidental delete/reinstall
 * loses nothing. R2 objects are NEVER touched by the plugin.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$blt_documents_settings = get_option( 'blt_documents_settings', array() );

if ( empty( $blt_documents_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Drop custom tables (versions -> files -> folders).
foreach ( array( 'blt_documents_versions', 'blt_documents_files', 'blt_documents_folders' ) as $blt_documents_table ) {
	$blt_documents_full = $wpdb->prefix . $blt_documents_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$blt_documents_full}" );
}

// Delete options.
delete_option( 'blt_documents_settings' );
delete_option( 'blt_documents_db_version' );

// Remove capabilities and the Board Member role.
foreach ( array( 'administrator', 'blt_board_member' ) as $blt_documents_role_name ) {
	$blt_documents_role = get_role( $blt_documents_role_name );

	if ( $blt_documents_role ) {
		$blt_documents_role->remove_cap( 'blt_manage_documents' );
		$blt_documents_role->remove_cap( 'blt_view_document_history' );
	}
}
remove_role( 'blt_board_member' );

// plugin-update-checker state.
delete_option( 'external_updates-blt-documents' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-blt-documents' );
