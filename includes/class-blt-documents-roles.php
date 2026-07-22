<?php
/**
 * Roles and capabilities.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's capabilities and the Board Member role.
 *
 * Capability model:
 *   - blt_manage_documents      Upload/replace/organize + generate shortcodes.
 *   - blt_view_document_history See and download prior (non-current) versions.
 *
 * Everyone else (including logged-out visitors) can only ever reach the
 * current version of a published document.
 */
class BLT_Documents_Roles {

	const CAP_MANAGE  = 'blt_manage_documents';
	const CAP_HISTORY = 'blt_view_document_history';

	const BOARD_MEMBER_ROLE = 'blt_board_member';

	/**
	 * Add capabilities to administrators and create the Board Member role.
	 *
	 * Called on activation.
	 *
	 * @return void
	 */
	public static function add() {
		$admin = get_role( 'administrator' );

		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_HISTORY );
		}

		// Board Member: a read-only site role that can additionally view and
		// download prior document versions. add_role() is a no-op if it exists.
		add_role(
			self::BOARD_MEMBER_ROLE,
			__( 'Board Member', 'blt-documents' ),
			array(
				'read'              => true,
				self::CAP_HISTORY   => true,
			)
		);
	}

	/**
	 * Remove capabilities and the Board Member role.
	 *
	 * Called only from uninstall when opt-in data deletion is enabled.
	 *
	 * @return void
	 */
	public static function remove() {
		foreach ( array( 'administrator', self::BOARD_MEMBER_ROLE ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role ) {
				$role->remove_cap( self::CAP_MANAGE );
				$role->remove_cap( self::CAP_HISTORY );
			}
		}

		remove_role( self::BOARD_MEMBER_ROLE );
	}
}
