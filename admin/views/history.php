<?php
/**
 * Version history for a single document (admin / Board Member only).
 *
 * @package Blt_Documents
 *
 * @var object            $file          File row.
 * @var array<int,object> $versions      Version rows, newest first.
 * @var string            $history_nonce wp_rest nonce for scoped downloads.
 * @var string            $back_url      URL back to the folder view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_current_id = (int) $file->current_version_id;
?>
<div class="wrap blt-ui blt-ui-wide blt-documents-wrap">
	<div class="blt-admin-page-header">
		<h1>
			<?php esc_html_e( 'Version History', 'blt-documents' ); ?>
			<span class="blt-admin-page-header-sub"><?php echo esc_html( $file->title ); ?></span>
		</h1>
		<div class="blt-admin-page-actions">
			<a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to documents', 'blt-documents' ); ?></a>
		</div>
	</div>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Versions', 'blt-documents' ); ?></h2>
			<p><?php esc_html_e( 'Every uploaded version is retained. Prior versions are only reachable here — never publicly, and never by search engines.', 'blt-documents' ); ?></p>
		</div>
		<div class="blt-card-body">
			<?php if ( empty( $versions ) ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No versions recorded', 'blt-documents' ); ?></span>
					<span><?php esc_html_e( 'Upload a file on the document’s edit screen to create version 1.', 'blt-documents' ); ?></span>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'File', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'Size', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'Uploaded', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'By', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'Note', 'blt-documents' ); ?></th>
							<th><?php esc_html_e( 'Download', 'blt-documents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $versions as $blt_v ) :
							$blt_user = $blt_v->uploaded_by ? get_userdata( (int) $blt_v->uploaded_by ) : false;
							$blt_dl   = add_query_arg(
								array(
									'id'       => absint( $file->id ),
									'version'  => absint( $blt_v->id ),
									'_wpnonce' => $history_nonce,
								),
								rest_url( BLT_DOCUMENTS_REST_NS . '/download' )
							);
							$blt_is_current = ( (int) $blt_v->id === $blt_current_id );
							?>
							<tr>
								<td>
									v<?php echo absint( $blt_v->version_number ); ?>
									<?php if ( $blt_is_current ) : ?>
										<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Current', 'blt-documents' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $blt_v->original_filename ); ?></td>
								<td><?php echo esc_html( $blt_v->file_size ? size_format( (int) $blt_v->file_size ) : '—' ); ?></td>
								<td><?php echo esc_html( $blt_v->created_at ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $blt_v->created_at ) : '—' ); ?></td>
								<td><?php echo esc_html( $blt_user ? $blt_user->display_name : '—' ); ?></td>
								<td><?php echo esc_html( $blt_v->notes ); ?></td>
								<td><a class="button button-small" rel="nofollow" href="<?php echo esc_url( $blt_dl ); ?>"><?php esc_html_e( 'Download', 'blt-documents' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
