<?php
/**
 * Settings screen: Worker connection, upload limits, file-type taxonomy.
 *
 * @package Blt_Documents
 *
 * @var array<string,mixed> $settings   Stored settings (raw; secret is encrypted).
 * @var bool                $has_secret Whether a Worker secret is stored.
 * @var bool                $strong     Whether a strong AEAD backend is available.
 * @var bool                $configured Whether the Worker connection is complete.
 * @var string              $update_url Nonced manual "Check for Updates" URL ('' if unavailable).
 * @var int                 $last_check Unix timestamp of the last update check (0 if never).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_menu       = BLT_Documents_Admin::MENU_SLUG;
$blt_file_types = is_array( $settings['file_types'] ) ? implode( "\n", $settings['file_types'] ) : '';
?>
<div class="wrap blt-ui blt-documents-wrap blt-documents-settings">
	<div class="blt-admin-page-header">
		<h1>
			<?php
			// Pre-built, KSES-filtered SVG markup from the shared brand class.
			echo BLT_Family_Brand::inline_mark( BLT_DOCUMENTS_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized in inline_mark().
			?>
			<?php esc_html_e( 'BLT Documents', 'blt-documents' ); ?>
			<span class="blt-admin-page-header-sub"><?php esc_html_e( 'Settings', 'blt-documents' ); ?></span>
		</h1>
		<?php if ( '' !== $update_url ) : ?>
			<div class="blt-admin-page-actions">
				<?php
				// The automatic check runs once a day at midnight site time; this
				// link is an explicit request and runs immediately.
				?>
				<a class="button" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'Check for Updates', 'blt-documents' ); ?></a>
				<?php if ( $last_check ) : ?>
					<span class="blt-admin-page-header-meta">
						<?php
						printf(
							/* translators: %s: date and time of the last update check. */
							esc_html__( 'Last checked %s', 'blt-documents' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_check ) )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $strong ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No strong encryption backend (libsodium or OpenSSL AES-GCM) was found on this server. The Worker secret will be stored with weak obfuscation only. Ask your host to enable the sodium or openssl PHP extension.', 'blt-documents' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . $blt_menu . '-settings' ) ); ?>">
		<?php wp_nonce_field( 'blt_save_settings', 'blt_settings_nonce' ); ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Cloudflare Worker', 'blt-documents' ); ?></h2>
				<p><?php esc_html_e( 'The private delivery plane for this site’s documents. Nothing can be uploaded or downloaded until it is connected.', 'blt-documents' ); ?></p>
				<div class="blt-card-header-badges">
					<?php if ( $configured ) : ?>
						<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Connected', 'blt-documents' ); ?></span>
					<?php else : ?>
						<span class="blt-badge blt-badge-off"><?php esc_html_e( 'Not connected', 'blt-documents' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-worker-url"><?php esc_html_e( 'Worker URL', 'blt-documents' ); ?></label></div>
					<div>
						<input type="url" id="blt-worker-url" class="regular-text code" name="blt_settings[worker_url]" value="<?php echo esc_attr( $settings['worker_url'] ); ?>" placeholder="https://docs.s-fx.com" />
						<p class="blt-field-desc"><?php esc_html_e( 'Base URL of the self-hosted BLT Documents Worker (no trailing path).', 'blt-documents' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-worker-secret"><?php esc_html_e( 'Worker Secret', 'blt-documents' ); ?></label></div>
					<div>
						<input type="password" id="blt-worker-secret" class="regular-text code" name="blt_settings[worker_secret]" autocomplete="new-password" value="" placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (leave blank to keep current)', 'blt-documents' ) : ''; ?>" />
						<p class="blt-field-desc"><?php esc_html_e( 'The shared HMAC secret set on the Worker via “wrangler secret put WORKER_SECRET”. Stored encrypted at rest.', 'blt-documents' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Site ID', 'blt-documents' ); ?></div>
					<div>
						<code><?php echo esc_html( $settings['site_id'] ? $settings['site_id'] : __( '(generated on first save)', 'blt-documents' ) ); ?></code>
						<p class="blt-field-desc"><?php esc_html_e( 'Top-level R2 key namespace for this site. Generated automatically; do not change it once documents exist.', 'blt-documents' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Connection', 'blt-documents' ); ?></div>
					<div>
						<button type="button" class="button" id="blt-test-connection"><?php esc_html_e( 'Test Connection', 'blt-documents' ); ?></button>
						<span id="blt-test-result" class="blt-doc-test-result" role="status" aria-live="polite"></span>
						<p class="blt-field-desc"><?php esc_html_e( 'Save your settings first, then test.', 'blt-documents' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Documents', 'blt-documents' ); ?></h2>
				<p><?php esc_html_e( 'Upload limits and the file-type labels offered when adding a document.', 'blt-documents' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-max-upload"><?php esc_html_e( 'Max upload size (MB)', 'blt-documents' ); ?></label></div>
					<div><input type="number" id="blt-max-upload" min="1" max="500" name="blt_settings[max_upload_mb]" value="<?php echo esc_attr( absint( $settings['max_upload_mb'] ) ); ?>" class="small-text" /></div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-file-types"><?php esc_html_e( 'File types', 'blt-documents' ); ?></label></div>
					<div>
						<textarea id="blt-file-types" name="blt_settings[file_types]" rows="6" class="large-text code"><?php echo esc_textarea( $blt_file_types ); ?></textarea>
						<p class="blt-field-desc"><?php esc_html_e( 'One label per line. These populate the “File Type” dropdown and the front-end column (e.g. Bylaws, Rules & Regs, Minutes, Policy, Deed).', 'blt-documents' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Advanced', 'blt-documents' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'On uninstall', 'blt-documents' ); ?></div>
					<div>
						<label class="blt-toggle">
							<input type="checkbox" name="blt_settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'blt-documents' ); ?></span>
								<span class="blt-toggle-desc"><?php esc_html_e( 'Folders, files, version records and settings. Off by default. R2 objects are never deleted by the plugin — manage them in Cloudflare.', 'blt-documents' ); ?></span>
							</span>
						</label>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" name="blt_settings_submit" value="1" class="button button-primary blt-save-button"><?php esc_html_e( 'Save Settings', 'blt-documents' ); ?></button>
		</div>
	</form>
</div>
