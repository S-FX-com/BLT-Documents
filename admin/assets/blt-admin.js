/**
 * BLT Documents — admin scripts (vanilla JS, no jQuery).
 *
 * Handles: copy-to-clipboard for the shortcode field, the Worker
 * "Test Connection" button, and delete confirmation.
 */
( function () {
	'use strict';

	var cfg = window.bltDocuments || {};
	var i18n = cfg.i18n || {};

	/**
	 * POST to admin-ajax and return the parsed JSON envelope.
	 *
	 * @param {string} action  AJAX action suffix.
	 * @param {Object} data     Extra fields.
	 * @return {Promise<Object>}
	 */
	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.set( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	/**
	 * Copy the referenced input's value to the clipboard.
	 *
	 * @param {HTMLElement} button Copy button with data-target.
	 */
	function copyToClipboard( button ) {
		var target = document.getElementById( button.getAttribute( 'data-target' ) );

		if ( ! target ) {
			return;
		}

		var done = function () {
			var original = button.textContent;
			button.textContent = i18n.copied || 'Copied!';
			window.setTimeout( function () { button.textContent = original; }, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( target.value ).then( done ).catch( function () {
				fallbackCopy( target, button );
			} );
		} else {
			fallbackCopy( target, button );
		}
	}

	/**
	 * execCommand fallback for older browsers / insecure contexts.
	 *
	 * @param {HTMLInputElement} target Input to copy from.
	 * @param {HTMLElement}      button Copy button.
	 */
	function fallbackCopy( target, button ) {
		target.focus();
		target.select();

		try {
			document.execCommand( 'copy' );
			button.textContent = i18n.copied || 'Copied!';
		} catch ( e ) {
			window.alert( i18n.copyErr || 'Press Ctrl/Cmd+C to copy' );
		}
	}

	/**
	 * Run the Worker connection test.
	 *
	 * @param {HTMLElement} button Test button.
	 */
	function testConnection( button ) {
		var result = document.getElementById( 'blt-test-result' );
		button.disabled = true;

		if ( result ) {
			result.textContent = i18n.testing || 'Testing…';
			result.className = 'blt-doc-test-result';
		}

		post( 'blt_documents_ajax_test', {} ).then( function ( res ) {
			if ( ! result ) {
				return;
			}

			var message = ( res && res.data && res.data.message ) ? res.data.message : '';
			result.textContent = message;
			result.className = 'blt-doc-test-result ' + ( res && res.success ? 'is-ok' : 'is-error' );
		} ).catch( function () {
			if ( result ) {
				result.textContent = 'Request failed.';
				result.className = 'blt-doc-test-result is-error';
			}
		} ).finally( function () {
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var copyBtn = event.target.closest( '.blt-doc-copy' );
		if ( copyBtn ) {
			event.preventDefault();
			copyToClipboard( copyBtn );
			return;
		}

		var testBtn = event.target.closest( '#blt-test-connection' );
		if ( testBtn ) {
			event.preventDefault();
			testConnection( testBtn );
			return;
		}

		var trashBtn = event.target.closest( '.blt-doc-trash-btn' );
		if ( trashBtn ) {
			if ( ! window.confirm( i18n.confirmDel || 'Delete this document?' ) ) {
				event.preventDefault();
			}
		}
	}, false );
}() );
