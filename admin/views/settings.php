<?php
/**
 * Settings screen: Worker connection, upload limits, file-type taxonomy.
 *
 * @package Blt_Documents
 *
 * @var array<string,mixed> $settings   Stored settings (raw; secret is encrypted).
 * @var bool                $has_secret Whether a Worker secret is stored.
 * @var bool                $strong     Whether a strong AEAD backend is available.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_menu       = BLT_Documents_Admin::MENU_SLUG;
$blt_file_types = is_array( $settings['file_types'] ) ? implode( "\n", $settings['file_types'] ) : '';
?>
<div class="wrap blt-documents-wrap">
	<h1><?php esc_html_e( 'BLT Documents — Settings', 'blt-documents' ); ?></h1>

	<?php if ( ! $strong ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No strong encryption backend (libsodium or OpenSSL AES-GCM) was found on this server. The Worker secret will be stored with weak obfuscation only. Ask your host to enable the sodium or openssl PHP extension.', 'blt-documents' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . $blt_menu . '-settings' ) ); ?>">
		<?php wp_nonce_field( 'blt_save_settings', 'blt_settings_nonce' ); ?>

		<h2 class="title"><?php esc_html_e( 'Cloudflare Worker', 'blt-documents' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="blt-worker-url"><?php esc_html_e( 'Worker URL', 'blt-documents' ); ?></label></th>
				<td>
					<input type="url" id="blt-worker-url" class="regular-text code" name="blt_settings[worker_url]" value="<?php echo esc_attr( $settings['worker_url'] ); ?>" placeholder="https://docs.s-fx.com" />
					<p class="description"><?php esc_html_e( 'Base URL of the self-hosted BLT Documents Worker (no trailing path).', 'blt-documents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="blt-worker-secret"><?php esc_html_e( 'Worker Secret', 'blt-documents' ); ?></label></th>
				<td>
					<input type="password" id="blt-worker-secret" class="regular-text code" name="blt_settings[worker_secret]" autocomplete="new-password" value="" placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (leave blank to keep current)', 'blt-documents' ) : ''; ?>" />
					<p class="description"><?php esc_html_e( 'The shared HMAC secret set on the Worker via “wrangler secret put WORKER_SECRET”. Stored encrypted at rest.', 'blt-documents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Site ID', 'blt-documents' ); ?></th>
				<td>
					<code><?php echo esc_html( $settings['site_id'] ? $settings['site_id'] : __( '(generated on first save)', 'blt-documents' ) ); ?></code>
					<p class="description"><?php esc_html_e( 'Top-level R2 key namespace for this site. Generated automatically; do not change it once documents exist.', 'blt-documents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection', 'blt-documents' ); ?></th>
				<td>
					<button type="button" class="button" id="blt-test-connection"><?php esc_html_e( 'Test Connection', 'blt-documents' ); ?></button>
					<span id="blt-test-result" class="blt-doc-test-result" role="status" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'Save your settings first, then test.', 'blt-documents' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Documents', 'blt-documents' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="blt-max-upload"><?php esc_html_e( 'Max upload size (MB)', 'blt-documents' ); ?></label></th>
				<td><input type="number" id="blt-max-upload" min="1" max="500" name="blt_settings[max_upload_mb]" value="<?php echo esc_attr( absint( $settings['max_upload_mb'] ) ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="blt-file-types"><?php esc_html_e( 'File types', 'blt-documents' ); ?></label></th>
				<td>
					<textarea id="blt-file-types" name="blt_settings[file_types]" rows="6" class="large-text code"><?php echo esc_textarea( $blt_file_types ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One label per line. These populate the “File Type” dropdown and the front-end column (e.g. Bylaws, Rules & Regs, Minutes, Policy, Deed).', 'blt-documents' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Advanced', 'blt-documents' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'blt-documents' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="blt_settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Delete all plugin data (folders, files, version records, settings) when the plugin is deleted.', 'blt-documents' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. R2 objects are never deleted by the plugin — manage them in Cloudflare.', 'blt-documents' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'blt-documents' ), 'primary', 'blt_settings_submit' ); ?>
	</form>
</div>
