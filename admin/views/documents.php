<?php
/**
 * Documents management screen: folders sidebar + file list + add forms.
 *
 * @package Blt_Documents
 *
 * @var array<int,object> $folders        All folders.
 * @var int               $current_folder Selected folder id.
 * @var array<int,object> $files          Files in the selected folder.
 * @var array<int,string> $file_types     Configured file-type labels.
 * @var bool              $configured     Whether the Worker is configured.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once BLT_DOCUMENTS_DIR . 'admin/views/partials.php';

$blt_menu     = BLT_Documents_Admin::MENU_SLUG;
$blt_current  = null;
foreach ( $folders as $blt_f ) {
	if ( (int) $blt_f->id === (int) $current_folder ) {
		$blt_current = $blt_f;
	}
}
$blt_shortcode = $blt_current ? '[blt_documents folder="' . $blt_current->slug . '"]' : '[blt_documents]';
$blt_can_hist  = current_user_can( BLT_Documents_Roles::CAP_HISTORY );
?>
<div class="wrap blt-documents-wrap">
	<h1><?php esc_html_e( 'BLT Documents', 'blt-documents' ); ?></h1>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'The Cloudflare Worker is not connected yet. <a href="%s">Open Settings</a> to add your Worker URL and secret before uploading documents.', 'blt-documents' ) ),
					esc_url( admin_url( 'admin.php?page=' . $blt_menu . '-settings' ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="blt-doc-admin-layout">
		<!-- Folders sidebar -->
		<aside class="blt-doc-sidebar">
			<h2><?php esc_html_e( 'Folders', 'blt-documents' ); ?></h2>
			<ul class="blt-doc-folder-list">
				<?php foreach ( $folders as $blt_folder ) : ?>
					<li class="<?php echo ( (int) $blt_folder->id === (int) $current_folder ) ? 'is-active' : ''; ?>">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $blt_menu . '&folder=' . absint( $blt_folder->id ) ) ); ?>">
							<?php echo esc_html( $blt_folder->name ); ?>
						</a>
					</li>
				<?php endforeach; ?>
				<?php if ( empty( $folders ) ) : ?>
					<li class="blt-doc-muted"><?php esc_html_e( 'No folders yet.', 'blt-documents' ); ?></li>
				<?php endif; ?>
			</ul>

			<form method="post" class="blt-doc-inline-form">
				<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
				<input type="hidden" name="blt_documents_action" value="add_folder" />
				<label class="screen-reader-text" for="blt-new-folder"><?php esc_html_e( 'New folder name', 'blt-documents' ); ?></label>
				<input type="text" id="blt-new-folder" name="folder_name" class="regular-text" placeholder="<?php esc_attr_e( 'New folder name', 'blt-documents' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Add Folder', 'blt-documents' ); ?></button>
			</form>
		</aside>

		<!-- Main panel -->
		<div class="blt-doc-main">
			<?php if ( ! $blt_current ) : ?>
				<div class="blt-doc-panel">
					<p><?php esc_html_e( 'Create a folder to start adding documents.', 'blt-documents' ); ?></p>
				</div>
			<?php else : ?>

				<div class="blt-doc-panel">
					<div class="blt-doc-folder-head">
						<h2><?php echo esc_html( $blt_current->name ); ?></h2>
						<div class="blt-doc-folder-tools">
							<form method="post" class="blt-doc-inline-form" onsubmit="return true;">
								<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
								<input type="hidden" name="blt_documents_action" value="rename_folder" />
								<input type="hidden" name="folder_id" value="<?php echo absint( $blt_current->id ); ?>" />
								<input type="text" name="folder_name" value="<?php echo esc_attr( $blt_current->name ); ?>" class="regular-text" />
								<button type="submit" class="button"><?php esc_html_e( 'Rename', 'blt-documents' ); ?></button>
							</form>
							<form method="post" class="blt-doc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this empty folder?', 'blt-documents' ) ); ?>');">
								<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
								<input type="hidden" name="blt_documents_action" value="delete_folder" />
								<input type="hidden" name="folder_id" value="<?php echo absint( $blt_current->id ); ?>" />
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete Folder', 'blt-documents' ); ?></button>
							</form>
						</div>
					</div>

					<!-- Shortcode generator -->
					<div class="blt-doc-shortcode">
						<label for="blt-doc-sc"><strong><?php esc_html_e( 'Shortcode', 'blt-documents' ); ?></strong></label>
						<input type="text" id="blt-doc-sc" class="regular-text code" readonly value="<?php echo esc_attr( $blt_shortcode ); ?>" />
						<button type="button" class="button blt-doc-copy" data-target="blt-doc-sc"><?php esc_html_e( 'Copy', 'blt-documents' ); ?></button>
						<p class="description"><?php esc_html_e( 'Paste this into any page to display this folder’s current documents.', 'blt-documents' ); ?></p>
					</div>
				</div>

				<!-- Add document -->
				<div class="blt-doc-panel">
					<h2><?php esc_html_e( 'Add Document', 'blt-documents' ); ?></h2>
					<form method="post" enctype="multipart/form-data" class="blt-doc-add-form">
						<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
						<input type="hidden" name="blt_documents_action" value="add_file" />
						<input type="hidden" name="folder_id" value="<?php echo absint( $blt_current->id ); ?>" />

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="blt-file-title"><?php esc_html_e( 'Title', 'blt-documents' ); ?></label></th>
								<td><input type="text" id="blt-file-title" name="title" class="regular-text" required /></td>
							</tr>
							<tr>
								<th scope="row"><label for="blt-file-type"><?php esc_html_e( 'File Type', 'blt-documents' ); ?></label></th>
								<td><?php blt_documents_file_type_select( 'file_type', '', $file_types ); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="blt-file-status"><?php esc_html_e( 'Status', 'blt-documents' ); ?></label></th>
								<td><?php blt_documents_status_select( 'status', 'published' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="blt-file-upload"><?php esc_html_e( 'File', 'blt-documents' ); ?></label></th>
								<td><input type="file" id="blt-file-upload" name="document" required /></td>
							</tr>
							<tr>
								<th scope="row"><label for="blt-file-notes"><?php esc_html_e( 'Version note', 'blt-documents' ); ?></label></th>
								<td><input type="text" id="blt-file-notes" name="notes" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Initial upload', 'blt-documents' ); ?>" /></td>
							</tr>
						</table>

						<?php submit_button( __( 'Add Document', 'blt-documents' ), 'primary', 'blt_add_file_submit', false, $configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

				<!-- File list -->
				<div class="blt-doc-panel">
					<h2><?php esc_html_e( 'Documents', 'blt-documents' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'blt-documents' ); ?></th>
								<th><?php esc_html_e( 'File Type', 'blt-documents' ); ?></th>
								<th><?php esc_html_e( 'Last Updated', 'blt-documents' ); ?></th>
								<th><?php esc_html_e( 'Version', 'blt-documents' ); ?></th>
								<th><?php esc_html_e( 'Status', 'blt-documents' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'blt-documents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $files ) ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No documents in this folder yet.', 'blt-documents' ); ?></td></tr>
							<?php endif; ?>
							<?php
							foreach ( $files as $blt_file ) :
								$blt_version = $blt_file->current_version_id ? BLT_Documents_Versions::get( $blt_file->current_version_id ) : null;
								$blt_edit    = admin_url( 'admin.php?page=' . $blt_menu . '&action=edit-file&file=' . absint( $blt_file->id ) );
								$blt_hist    = admin_url( 'admin.php?page=' . $blt_menu . '&action=history&file=' . absint( $blt_file->id ) );
								?>
								<tr>
									<td><strong><?php echo esc_html( $blt_file->title ); ?></strong></td>
									<td><?php echo esc_html( $blt_file->file_type ); ?></td>
									<td><?php echo esc_html( $blt_file->updated_at ? mysql2date( get_option( 'date_format' ), $blt_file->updated_at ) : '—' ); ?></td>
									<td><?php echo $blt_version ? 'v' . absint( $blt_version->version_number ) : '—'; ?></td>
									<td><?php echo esc_html( ucfirst( $blt_file->status ) ); ?></td>
									<td class="blt-doc-actions">
										<a class="button button-small" href="<?php echo esc_url( $blt_edit ); ?>"><?php esc_html_e( 'Edit / Replace', 'blt-documents' ); ?></a>
										<?php if ( $blt_can_hist ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $blt_hist ); ?>"><?php esc_html_e( 'History', 'blt-documents' ); ?></a>
										<?php endif; ?>
										<form method="post" class="blt-doc-inline-form blt-doc-trash-form">
											<?php wp_nonce_field( BLT_Documents_Admin::NONCE_ACTION, BLT_Documents_Admin::NONCE_FIELD ); ?>
											<input type="hidden" name="blt_documents_action" value="trash_file" />
											<input type="hidden" name="file_id" value="<?php echo absint( $blt_file->id ); ?>" />
											<button type="submit" class="button button-small button-link-delete blt-doc-trash-btn"><?php esc_html_e( 'Delete', 'blt-documents' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

			<?php endif; ?>
		</div>
	</div>
</div>
