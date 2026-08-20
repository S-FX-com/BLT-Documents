<?php
/**
 * Edit a document's metadata and upload a replacement (new version).
 *
 * @package Blt_Documents
 *
 * @var object            $file       File row.
 * @var array<int,object> $folders    All folders.
 * @var array<int,string> $file_types Configured file-type labels.
 * @var object|null       $current    Current version row (or null).
 * @var string            $back_url   URL back to the folder view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once BLT_DOCUMENTS_DIR . 'admin/views/partials.php';
?>
<div class="wrap blt-ui blt-documents-wrap">
	<div class="blt-admin-page-header">
		<h1>
			<?php esc_html_e( 'Edit Document', 'blt-documents' ); ?>
			<span class="blt-admin-page-header-sub"><?php echo esc_html( $file->title ); ?></span>
		</h1>
		<div class="blt-admin-page-actions">
			<a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to documents', 'blt-documents' ); ?></a>
		</div>
	</div>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Details', 'blt-documents' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<form method="post">
				<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
				<input type="hidden" name="blt_documents_action" value="edit_file" />
				<input type="hidden" name="file_id" value="<?php echo absint( $file->id ); ?>" />

				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-edit-title"><?php esc_html_e( 'Title', 'blt-documents' ); ?></label></div>
					<div><input type="text" id="blt-edit-title" name="title" class="regular-text" value="<?php echo esc_attr( $file->title ); ?>" required /></div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'File Type', 'blt-documents' ); ?></div>
					<div><?php blt_documents_file_type_select( 'file_type', $file->file_type, $file_types ); ?></div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Folder', 'blt-documents' ); ?></div>
					<div><?php blt_documents_folder_select( 'folder_id', (int) $file->folder_id, $folders, true ); ?></div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Status', 'blt-documents' ); ?></div>
					<div><?php blt_documents_status_select( 'status', $file->status ); ?></div>
				</div>

				<div class="blt-settings-footer">
					<?php submit_button( __( 'Save Details', 'blt-documents' ), 'primary blt-save-button', 'blt_edit_file_submit', false ); ?>
				</div>
			</form>
		</div>
	</div>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Replace File (upload new version)', 'blt-documents' ); ?></h2>
			<?php if ( $current ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: version number, 2: filename. */
						esc_html__( 'Current version: v%1$d (%2$s). Uploading a new file makes it the current version; the previous file is retained in history.', 'blt-documents' ),
						absint( $current->version_number ),
						esc_html( $current->original_filename )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'This document has no file yet. Upload one to make it downloadable.', 'blt-documents' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="blt-card-body">
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
				<input type="hidden" name="blt_documents_action" value="replace_file" />
				<input type="hidden" name="file_id" value="<?php echo absint( $file->id ); ?>" />

				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-replace-upload"><?php esc_html_e( 'New file', 'blt-documents' ); ?></label></div>
					<div><input type="file" id="blt-replace-upload" name="document" required /></div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-replace-notes"><?php esc_html_e( 'Version note', 'blt-documents' ); ?></label></div>
					<div>
						<input type="text" id="blt-replace-notes" name="notes" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Updated Article IV per 5/3/25 vote', 'blt-documents' ); ?>" />
						<p class="blt-field-desc"><?php esc_html_e( 'Optional changelog note shown in version history.', 'blt-documents' ); ?></p>
					</div>
				</div>

				<div class="blt-settings-footer">
					<?php submit_button( __( 'Upload New Version', 'blt-documents' ), 'secondary blt-save-button', 'blt_replace_file_submit', false ); ?>
				</div>
			</form>
		</div>
	</div>
</div>
