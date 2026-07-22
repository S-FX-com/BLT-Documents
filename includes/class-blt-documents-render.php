<?php
/**
 * Front-end shortcode + table renderer.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders [blt_documents folder="..." type="..."] as a minimal, responsive
 * four-column table (File Name / File Type / Last Updated / Download).
 *
 * Download links point at the REST route (never a file URL), so nothing is
 * crawlable or hotlinkable. Progressive enhancement: the links work without
 * JavaScript.
 */
class BLT_Documents_Render {

	const SHORTCODE = 'blt_documents';

	/**
	 * Register the shortcode and front-end assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the shortcode tag.
	 *
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Register (not enqueue) the front-end CSS/JS so the shortcode can enqueue
	 * conditionally, only on pages that actually use it.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'blt-documents-frontend',
			BLT_DOCUMENTS_URL . 'assets/css/blt-documents-frontend.css',
			array(),
			BLT_DOCUMENTS_VERSION
		);

		wp_register_script(
			'blt-documents-frontend',
			BLT_DOCUMENTS_URL . 'assets/js/blt-documents-frontend.js',
			array(),
			BLT_DOCUMENTS_VERSION,
			true
		);
	}

	/**
	 * Shortcode callback. Returns escaped HTML (never echoes).
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'folder'  => '',
				'type'    => '',
				'orderby' => 'updated_at',
				'order'   => 'DESC',
				'limit'   => 50,
			),
			$atts,
			self::SHORTCODE
		);

		$folder_id = self::resolve_folder_id( $atts['folder'] );

		if ( '' !== (string) $atts['folder'] && 0 === $folder_id ) {
			return '<p class="blt-doc-empty">' . esc_html__( 'No documents available.', 'blt-documents' ) . '</p>';
		}

		$rows = BLT_Documents_Files::for_display(
			array(
				'folder_id' => $folder_id,
				'file_type' => sanitize_text_field( (string) $atts['type'] ),
				'orderby'   => sanitize_key( (string) $atts['orderby'] ),
				'order'     => sanitize_key( (string) $atts['order'] ),
				'limit'     => absint( $atts['limit'] ),
			)
		);

		wp_enqueue_style( 'blt-documents-frontend' );
		wp_enqueue_script( 'blt-documents-frontend' );

		return self::render_table( $rows );
	}

	/**
	 * Resolve a folder slug to its id (searches every level). Empty slug = all.
	 *
	 * @param string $slug Folder slug.
	 * @return int Folder id, or 0 for "all folders" / not found.
	 */
	private static function resolve_folder_id( $slug ) {
		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return 0;
		}

		foreach ( BLT_Documents_Folders::all() as $folder ) {
			if ( $folder->slug === $slug ) {
				return (int) $folder->id;
			}
		}

		return 0;
	}

	/**
	 * Build the public download URL (current version) for a file id.
	 *
	 * @param int $file_id File id.
	 * @return string
	 */
	private static function download_url( $file_id ) {
		return add_query_arg( 'id', absint( $file_id ), rest_url( BLT_DOCUMENTS_REST_NS . '/download' ) );
	}

	/**
	 * Render the documents table as an escaped HTML string.
	 *
	 * @param array<int,object> $rows Rows from BLT_Documents_Files::for_display().
	 * @return string
	 */
	public static function render_table( array $rows ) {
		if ( empty( $rows ) ) {
			return '<p class="blt-doc-empty">' . esc_html__( 'No documents available.', 'blt-documents' ) . '</p>';
		}

		$date_format = get_option( 'date_format' );

		ob_start();
		?>
		<div class="blt-doc-wrap">
			<table class="blt-doc-table">
				<thead>
					<tr>
						<th class="blt-doc-col-name"><?php esc_html_e( 'File Name', 'blt-documents' ); ?></th>
						<th class="blt-doc-col-type"><?php esc_html_e( 'File Type', 'blt-documents' ); ?></th>
						<th class="blt-doc-col-updated"><?php esc_html_e( 'Last Updated', 'blt-documents' ); ?></th>
						<th class="blt-doc-col-download"><?php esc_html_e( 'Download', 'blt-documents' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$ext      = strtoupper( pathinfo( (string) $row->original_filename, PATHINFO_EXTENSION ) );
						$type     = '' !== (string) $row->file_type ? $row->file_type : $ext;
						$updated  = $row->updated_at ? date_i18n( $date_format, strtotime( $row->updated_at ) ) : '';
						$dl_label = sprintf(
							/* translators: %s: document title. */
							__( 'Download %s', 'blt-documents' ),
							$row->title
						);
						?>
						<tr class="blt-doc-row">
							<td class="blt-doc-cell blt-doc-name" data-label="<?php esc_attr_e( 'File Name', 'blt-documents' ); ?>">
								<?php echo esc_html( $row->title ); ?>
							</td>
							<td class="blt-doc-cell blt-doc-type" data-label="<?php esc_attr_e( 'File Type', 'blt-documents' ); ?>">
								<span class="blt-doc-badge blt-doc-badge-<?php echo esc_attr( strtolower( $ext ) ); ?>"><?php echo esc_html( $type ); ?></span>
							</td>
							<td class="blt-doc-cell blt-doc-updated" data-label="<?php esc_attr_e( 'Last Updated', 'blt-documents' ); ?>">
								<?php echo esc_html( $updated ); ?>
							</td>
							<td class="blt-doc-cell blt-doc-download" data-label="<?php esc_attr_e( 'Download', 'blt-documents' ); ?>">
								<a class="blt-doc-btn" rel="nofollow" href="<?php echo esc_url( self::download_url( $row->id ) ); ?>" aria-label="<?php echo esc_attr( $dl_label ); ?>">
									<span class="blt-doc-btn-icon" aria-hidden="true">&#8595;</span>
									<span class="blt-doc-btn-text"><?php esc_html_e( 'Download', 'blt-documents' ); ?></span>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
