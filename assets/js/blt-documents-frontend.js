/**
 * BLT Documents — front-end progressive enhancement.
 *
 * The download links work with no JavaScript (they are real <a> elements
 * pointing at the nonce-free public REST route, which streams the current
 * version as an attachment). This script only adds a brief "downloading"
 * affordance and guards against accidental double-clicks. No jQuery.
 */
( function () {
	'use strict';

	function onClick( event ) {
		var link = event.target.closest( '.blt-doc-btn' );

		if ( ! link || link.classList.contains( 'is-downloading' ) ) {
			return;
		}

		link.classList.add( 'is-downloading' );

		// The browser navigates to the attachment; re-enable shortly after so
		// the row stays usable for a second download.
		window.setTimeout( function () {
			link.classList.remove( 'is-downloading' );
		}, 4000 );
	}

	document.addEventListener( 'click', onClick, false );
}() );
