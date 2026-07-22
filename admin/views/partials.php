<?php
/**
 * Shared admin view helpers.
 *
 * @package Blt_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'blt_documents_file_type_select' ) ) {
	/**
	 * Render a File Type <select> from the configured taxonomy.
	 *
	 * @param string            $name     Field name.
	 * @param string            $selected Selected label.
	 * @param array<int,string> $types    Available type labels.
	 * @return void
	 */
	function blt_documents_file_type_select( $name, $selected, array $types ) {
		echo '<select name="' . esc_attr( $name ) . '" class="blt-doc-select">';
		echo '<option value="">' . esc_html__( '— Select —', 'blt-documents' ) . '</option>';

		foreach ( $types as $label ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $label ),
				selected( $selected, $label, false )
			);
		}

		echo '</select>';
	}
}

if ( ! function_exists( 'blt_documents_status_select' ) ) {
	/**
	 * Render a published/draft status <select>.
	 *
	 * @param string $name     Field name.
	 * @param string $selected Selected status.
	 * @return void
	 */
	function blt_documents_status_select( $name, $selected ) {
		$options = array(
			'published' => __( 'Published (visible on site)', 'blt-documents' ),
			'draft'     => __( 'Draft (hidden from site)', 'blt-documents' ),
		);

		echo '<select name="' . esc_attr( $name ) . '" class="blt-doc-select">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}
}

if ( ! function_exists( 'blt_documents_folder_select' ) ) {
	/**
	 * Render a folder <select>.
	 *
	 * @param string            $name     Field name.
	 * @param int               $selected Selected folder id.
	 * @param array<int,object> $folders  Folder rows.
	 * @param bool              $none     Whether to include an "Uncategorized" option.
	 * @return void
	 */
	function blt_documents_folder_select( $name, $selected, array $folders, $none = false ) {
		echo '<select name="' . esc_attr( $name ) . '" class="blt-doc-select">';

		if ( $none ) {
			printf(
				'<option value="0" %1$s>%2$s</option>',
				selected( (int) $selected, 0, false ),
				esc_html__( 'Uncategorized', 'blt-documents' )
			);
		}

		foreach ( $folders as $folder ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				absint( $folder->id ),
				selected( (int) $selected, (int) $folder->id, false ),
				esc_html( $folder->name )
			);
		}

		echo '</select>';
	}
}
