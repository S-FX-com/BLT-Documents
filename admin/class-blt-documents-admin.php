<?php
/**
 * Admin controller: menu, pages, folder/file/version actions, settings.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the wp-admin experience for BLT Documents.
 */
class BLT_Documents_Admin {

	const CAP       = BLT_Documents_Roles::CAP_MANAGE;
	const MENU_SLUG = 'blt-documents';

	const NONCE_ACTION = 'blt_documents_admin';
	const NONCE_FIELD  = 'blt_documents_nonce';
	const AJAX_ACTION  = 'blt_documents_ajax';

	/**
	 * Singleton instance.
	 *
	 * @var BLT_Documents_Admin|null
	 */
	private static $instance = null;

	/**
	 * Collected admin page hook suffixes (for asset gating).
	 *
	 * @var array<int,string>
	 */
	private $page_hooks = array();

	/**
	 * Get the shared instance.
	 *
	 * @return BLT_Documents_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_post' ) );
		add_action( 'admin_init', array( $this, 'handle_action_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notices' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION . '_test', array( $this, 'ajax_test_connection' ) );
		add_filter( 'plugin_action_links_' . BLT_DOCUMENTS_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register the admin menu + submenus.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hooks[] = add_menu_page(
			__( 'BLT Documents', 'blt-documents' ),
			__( 'BLT Documents', 'blt-documents' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_documents_page' ),
			BLT_Family_Brand::menu_icon( BLT_DOCUMENTS_DIR ),
			81
		);

		$this->page_hooks[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Documents', 'blt-documents' ),
			__( 'Documents', 'blt-documents' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_documents_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'blt-documents' ),
			__( 'Settings', 'blt-documents' ),
			self::CAP,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Brighten the BLT menu mark on hover / while the section is open.
	 *
	 * WordPress paints an SVG icon_url as a background image and never
	 * recolours it, so this restores the lit state a dashicon gets for free.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		BLT_Family_Brand::print_menu_icon_style( self::MENU_SLUG );
	}

	/**
	 * Add a Settings link to the Plugins row.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'blt-documents' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Enqueue admin assets on our pages only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'blt-documents-design-system',
			BLT_DOCUMENTS_URL . 'assets/css/blt-design-system.css',
			array(),
			BLT_DOCUMENTS_VERSION
		);

		wp_enqueue_style(
			'blt-documents-admin',
			BLT_DOCUMENTS_URL . 'admin/assets/blt-admin.css',
			array( 'blt-documents-design-system' ),
			BLT_DOCUMENTS_VERSION
		);

		wp_enqueue_script(
			'blt-documents-admin',
			BLT_DOCUMENTS_URL . 'admin/assets/blt-admin.js',
			array(),
			BLT_DOCUMENTS_VERSION,
			true
		);

		wp_localize_script(
			'blt-documents-admin',
			'bltDocuments',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'copied'     => __( 'Copied!', 'blt-documents' ),
					'copyErr'    => __( 'Press Ctrl/Cmd+C to copy', 'blt-documents' ),
					'testing'    => __( 'Testing…', 'blt-documents' ),
					'confirmDel' => __( 'Delete this document? Its version history is retained but it will no longer appear on the site.', 'blt-documents' ),
				),
			)
		);
	}

	/**
	 * Page-render capability guard.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'blt-documents' ) );
		}
	}

	/**
	 * AJAX capability + nonce guard.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'blt-documents' ) ), 403 );
		}

		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
	}

	/* ---------------------------------------------------------------------
	 * Page renderers
	 * ------------------------------------------------------------------- */

	/**
	 * Render the Documents management page (folders + files + history).
	 *
	 * @return void
	 */
	public function render_documents_page() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'history' === $action ) {
			$this->render_history_view();
			return;
		}

		if ( 'edit-file' === $action ) {
			$this->render_edit_file_view();
			return;
		}

		$folders = BLT_Documents_Folders::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only folder selection.
		$current_folder = isset( $_GET['folder'] ) ? absint( wp_unslash( $_GET['folder'] ) ) : ( $folders ? (int) $folders[0]->id : 0 );
		$files          = $current_folder ? BLT_Documents_Files::get_by_folder( $current_folder ) : array();
		$file_types     = BLT_Documents_Settings::file_types();
		$configured     = BLT_Documents_Settings::is_configured();

		require BLT_DOCUMENTS_DIR . 'admin/views/documents.php';
	}

	/**
	 * Render the version-history view for a single file.
	 *
	 * @return void
	 */
	private function render_history_view() {
		if ( ! current_user_can( BLT_Documents_Roles::CAP_HISTORY ) ) {
			wp_die( esc_html__( 'You do not have permission to view version history.', 'blt-documents' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$file_id = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0;
		$file    = BLT_Documents_Files::get( $file_id );

		if ( ! $file ) {
			wp_die( esc_html__( 'Document not found.', 'blt-documents' ) );
		}

		$versions       = BLT_Documents_Versions::list_for_file( $file_id );
		$history_nonce  = wp_create_nonce( 'wp_rest' );
		$back_url       = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&folder=' . absint( $file->folder_id ) );

		require BLT_DOCUMENTS_DIR . 'admin/views/history.php';
	}

	/**
	 * Render the edit-file view (metadata + replace).
	 *
	 * @return void
	 */
	private function render_edit_file_view() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$file_id = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0;
		$file    = BLT_Documents_Files::get( $file_id );

		if ( ! $file ) {
			wp_die( esc_html__( 'Document not found.', 'blt-documents' ) );
		}

		$folders    = BLT_Documents_Folders::all();
		$file_types = BLT_Documents_Settings::file_types();
		$current    = $file->current_version_id ? BLT_Documents_Versions::get( $file->current_version_id ) : null;
		$back_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&folder=' . absint( $file->folder_id ) );

		require BLT_DOCUMENTS_DIR . 'admin/views/edit-file.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->guard();

		$settings   = BLT_Documents_Settings::all();
		$has_secret = '' !== (string) BLT_Documents_Settings::get( 'worker_secret' );
		$strong     = BLT_Documents_Crypto::is_strong();
		$configured = BLT_Documents_Settings::is_configured();

		// Manual update check. The automatic check runs once a day at midnight
		// site time (BLT_Family_Updates); this link bypasses that floor. The
		// checker instance is the global the main plugin file builds it into —
		// only used to read the last-check timestamp, and skipped if absent.
		$update_url = class_exists( 'BLT_Family_Updates' )
			? BLT_Family_Updates::check_now_url( 'blt-documents' )
			: '';
		$last_check = ( '' !== $update_url && isset( $GLOBALS['blt_documents_update_checker'] ) )
			? BLT_Family_Updates::last_check_time( $GLOBALS['blt_documents_update_checker'] )
			: 0;

		require BLT_DOCUMENTS_DIR . 'admin/views/settings.php';
	}

	/* ---------------------------------------------------------------------
	 * POST handlers (PRG)
	 * ------------------------------------------------------------------- */

	/**
	 * Handle the settings form submission.
	 *
	 * @return void
	 */
	public function handle_settings_post() {
		if ( ! isset( $_POST['blt_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'blt-documents' ) );
		}

		check_admin_referer( 'blt_save_settings', 'blt_settings_nonce' );

		$raw = isset( $_POST['blt_settings'] ) && is_array( $_POST['blt_settings'] )
			? wp_unslash( $_POST['blt_settings'] ) // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- Sanitized per-field in Settings::save().
			: array();

		BLT_Documents_Settings::save( $raw );
		BLT_Documents_Settings::ensure_site_id();

		$this->redirect_with_notice( self::MENU_SLUG . '-settings', 'settings_saved' );
	}

	/**
	 * Dispatch folder/file action submissions.
	 *
	 * @return void
	 */
	public function handle_action_post() {
		if ( ! isset( $_POST['blt_documents_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'blt-documents' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$action = sanitize_key( wp_unslash( $_POST['blt_documents_action'] ) );

		switch ( $action ) {
			case 'add_folder':
				$this->do_add_folder();
				break;
			case 'rename_folder':
				$this->do_rename_folder();
				break;
			case 'delete_folder':
				$this->do_delete_folder();
				break;
			case 'add_file':
				$this->do_add_file();
				break;
			case 'replace_file':
				$this->do_replace_file();
				break;
			case 'edit_file':
				$this->do_edit_file();
				break;
			case 'trash_file':
				$this->do_trash_file();
				break;
			default:
				$this->redirect_with_notice( self::MENU_SLUG, 'unknown_action' );
		}
	}

	/**
	 * Create a folder.
	 *
	 * @return void
	 */
	private function do_add_folder() {
		$name   = isset( $_POST['folder_name'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_name'] ) ) : '';
		$parent = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;

		$id = BLT_Documents_Folders::create( $name, $parent );

		$this->redirect_with_notice( self::MENU_SLUG, $id ? 'folder_added' : 'folder_error', $id ? array( 'folder' => $id ) : array() );
	}

	/**
	 * Rename a folder.
	 *
	 * @return void
	 */
	private function do_rename_folder() {
		$id   = isset( $_POST['folder_id'] ) ? absint( wp_unslash( $_POST['folder_id'] ) ) : 0;
		$name = isset( $_POST['folder_name'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_name'] ) ) : '';

		BLT_Documents_Folders::update( $id, array( 'name' => $name ) );

		$this->redirect_with_notice( self::MENU_SLUG, 'folder_renamed', array( 'folder' => $id ) );
	}

	/**
	 * Delete a folder (only when it holds no non-trashed files).
	 *
	 * @return void
	 */
	private function do_delete_folder() {
		$id = isset( $_POST['folder_id'] ) ? absint( wp_unslash( $_POST['folder_id'] ) ) : 0;

		if ( BLT_Documents_Files::get_by_folder( $id ) ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'folder_not_empty', array( 'folder' => $id ) );
			return;
		}

		BLT_Documents_Folders::delete( $id );

		$this->redirect_with_notice( self::MENU_SLUG, 'folder_deleted' );
	}

	/**
	 * Create a file and store its first version.
	 *
	 * @return void
	 */
	private function do_add_file() {
		$folder_id = isset( $_POST['folder_id'] ) ? absint( wp_unslash( $_POST['folder_id'] ) ) : 0;
		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$file_type = isset( $_POST['file_type'] ) ? sanitize_text_field( wp_unslash( $_POST['file_type'] ) ) : '';
		$status    = ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'published';
		$notes     = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( '' === trim( $title ) ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'file_title_required', array( 'folder' => $folder_id ) );
			return;
		}

		$upload = $this->uploaded_document();

		if ( is_wp_error( $upload ) ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'upload_error', array( 'folder' => $folder_id, 'msg' => $upload->get_error_message() ) );
			return;
		}

		$file_id = BLT_Documents_Files::create(
			array(
				'folder_id' => $folder_id,
				'title'     => $title,
				'file_type' => $file_type,
				'status'    => $status,
			)
		);

		if ( ! $file_id ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'file_error', array( 'folder' => $folder_id ) );
			return;
		}

		$result = BLT_Documents_Uploader::store_version( $file_id, $upload, $notes );

		if ( is_wp_error( $result ) ) {
			// Roll back the empty file record so we don't leave a version-less row.
			BLT_Documents_Files::delete( $file_id );
			$this->redirect_with_notice( self::MENU_SLUG, 'upload_error', array( 'folder' => $folder_id, 'msg' => $result->get_error_message() ) );
			return;
		}

		$this->redirect_with_notice( self::MENU_SLUG, 'file_added', array( 'folder' => $folder_id ) );
	}

	/**
	 * Store a new version of an existing file.
	 *
	 * @return void
	 */
	private function do_replace_file() {
		$file_id = isset( $_POST['file_id'] ) ? absint( wp_unslash( $_POST['file_id'] ) ) : 0;
		$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$file    = BLT_Documents_Files::get( $file_id );
		$folder  = $file ? absint( $file->folder_id ) : 0;

		$upload = $this->uploaded_document();

		if ( is_wp_error( $upload ) ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'upload_error', array( 'folder' => $folder, 'msg' => $upload->get_error_message() ) );
			return;
		}

		$result = BLT_Documents_Uploader::store_version( $file_id, $upload, $notes );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( self::MENU_SLUG, 'upload_error', array( 'folder' => $folder, 'msg' => $result->get_error_message() ) );
			return;
		}

		$this->redirect_with_notice( self::MENU_SLUG, 'file_replaced', array( 'folder' => $folder ) );
	}

	/**
	 * Update a file's editable metadata.
	 *
	 * @return void
	 */
	private function do_edit_file() {
		$file_id = isset( $_POST['file_id'] ) ? absint( wp_unslash( $_POST['file_id'] ) ) : 0;

		$fields = array(
			'title'     => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'file_type' => isset( $_POST['file_type'] ) ? sanitize_text_field( wp_unslash( $_POST['file_type'] ) ) : '',
			'folder_id' => isset( $_POST['folder_id'] ) ? absint( wp_unslash( $_POST['folder_id'] ) ) : 0,
			'status'    => ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'published',
		);

		BLT_Documents_Files::update( $file_id, $fields );

		$this->redirect_with_notice( self::MENU_SLUG, 'file_updated', array( 'folder' => $fields['folder_id'] ) );
	}

	/**
	 * Soft-delete (trash) a file.
	 *
	 * @return void
	 */
	private function do_trash_file() {
		$file_id = isset( $_POST['file_id'] ) ? absint( wp_unslash( $_POST['file_id'] ) ) : 0;
		$file    = BLT_Documents_Files::get( $file_id );
		$folder  = $file ? absint( $file->folder_id ) : 0;

		BLT_Documents_Files::trash( $file_id );

		$this->redirect_with_notice( self::MENU_SLUG, 'file_trashed', array( 'folder' => $folder ) );
	}

	/**
	 * Normalize the uploaded document from $_FILES.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function uploaded_document() {
		// Nonce already verified in handle_action_post().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['document'] ) || ! isset( $_FILES['document']['name'] ) ) {
			return new WP_Error( 'blt_documents_no_file', __( 'Please choose a file to upload.', 'blt-documents' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidationSanitization.InputNotSanitized -- Raw $_FILES entry; validated in the uploader.
		$document = array_map( 'wp_unslash', $_FILES['document'] );

		if ( '' === $document['name'] ) {
			return new WP_Error( 'blt_documents_no_file', __( 'Please choose a file to upload.', 'blt-documents' ) );
		}

		return $document;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Test the Worker connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->verify_ajax();

		$result = BLT_Documents_Worker_Client::health();

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------- */

	/**
	 * PRG redirect with a notice code.
	 *
	 * @param string               $page   Admin page slug.
	 * @param string               $notice Notice code.
	 * @param array<string,mixed>  $extra  Extra query args.
	 * @return void
	 */
	private function redirect_with_notice( $page, $notice, array $extra = array() ) {
		$args = array_merge(
			array(
				'page'       => $page,
				'blt_notice' => $notice,
			),
			$extra
		);

		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render a queued admin notice.
	 *
	 * @return void
	 */
	public function maybe_render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice routing.
		if ( empty( $_GET['blt_notice'] ) || empty( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), self::MENU_SLUG ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( wp_unslash( $_GET['blt_notice'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';

		$map = array(
			'settings_saved'      => array( 'success', __( 'Settings saved.', 'blt-documents' ) ),
			'folder_added'        => array( 'success', __( 'Folder created.', 'blt-documents' ) ),
			'folder_renamed'      => array( 'success', __( 'Folder renamed.', 'blt-documents' ) ),
			'folder_deleted'      => array( 'success', __( 'Folder deleted.', 'blt-documents' ) ),
			'folder_not_empty'    => array( 'error', __( 'That folder still contains documents. Move or delete them first.', 'blt-documents' ) ),
			'folder_error'        => array( 'error', __( 'Could not create the folder.', 'blt-documents' ) ),
			'file_added'          => array( 'success', __( 'Document added.', 'blt-documents' ) ),
			'file_replaced'       => array( 'success', __( 'A new version was uploaded and is now the current version.', 'blt-documents' ) ),
			'file_updated'        => array( 'success', __( 'Document updated.', 'blt-documents' ) ),
			'file_trashed'        => array( 'success', __( 'Document removed from the site. Its version history is retained.', 'blt-documents' ) ),
			'file_title_required' => array( 'error', __( 'A document title is required.', 'blt-documents' ) ),
			'file_error'          => array( 'error', __( 'Could not save the document.', 'blt-documents' ) ),
			'upload_error'        => array( 'error', __( 'Upload failed.', 'blt-documents' ) ),
			'unknown_action'      => array( 'error', __( 'Unknown action.', 'blt-documents' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $notice ];

		if ( '' !== $detail && 'upload_error' === $notice ) {
			$message .= ' ' . $detail;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
