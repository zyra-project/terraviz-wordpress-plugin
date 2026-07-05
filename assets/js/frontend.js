/**
 * Terraviz embed — progressive enhancement.
 *
 * Replaces each server-rendered poster with the live globe iframe. Globes are
 * heavy, so we never boot them all on page load:
 *   - mode="poster": load only when the visitor clicks "Load interactive globe".
 *   - mode="lazy":   load when the block scrolls into view (IntersectionObserver),
 *                    with the button as a keyboard/no-IO fallback.
 *
 * No dependencies, no outbound calls — the iframe src is a Terraviz origin
 * composed entirely server-side.
 */
( function () {
	'use strict';

	var SELECTOR = '.terraviz-embed__media[data-terraviz-src]';

	function buildIframe( media ) {
		var src = media.getAttribute( 'data-terraviz-src' );
		var title = media.getAttribute( 'data-terraviz-title' ) || 'Terraviz';
		var iframe = document.createElement( 'iframe' );
		iframe.className = 'terraviz-embed__iframe';
		iframe.setAttribute( 'src', src );
		iframe.setAttribute( 'title', title );
		iframe.setAttribute( 'loading', 'lazy' );
		iframe.setAttribute( 'allow', 'fullscreen; accelerometer; gyroscope; xr-spatial-tracking' );
		iframe.setAttribute( 'allowfullscreen', 'true' );
		iframe.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		return iframe;
	}

	function load( media ) {
		if ( media.getAttribute( 'data-terraviz-loaded' ) === '1' ) {
			return;
		}
		media.setAttribute( 'data-terraviz-loaded', '1' );

		var iframe = buildIframe( media );
		var button = media.querySelector( '.terraviz-embed__load' );

		// Swap the poster button for the iframe.
		if ( button && button.parentNode ) {
			button.parentNode.replaceChild( iframe, button );
		} else {
			media.appendChild( iframe );
		}

		// Move keyboard focus into the freshly loaded globe for a11y.
		try {
			iframe.focus( { preventScroll: true } );
		} catch ( e ) {
			/* older browsers ignore the options arg */
		}
	}

	function wire( media ) {
		var mode = media.getAttribute( 'data-terraviz-mode' ) || 'poster';
		var button = media.querySelector( '.terraviz-embed__load' );

		if ( button ) {
			button.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				load( media );
			} );
		}

		if ( mode === 'lazy' ) {
			if ( 'IntersectionObserver' in window ) {
				var io = new IntersectionObserver(
					function ( entries, obs ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								obs.unobserve( entry.target );
								load( entry.target );
							}
						} );
					},
					{ rootMargin: '200px 0px' }
				);
				io.observe( media );
			} else {
				// No IntersectionObserver: fall back to eager load.
				load( media );
			}
		}
	}

	function init() {
		var nodes = document.querySelectorAll( SELECTOR );
		Array.prototype.forEach.call( nodes, wire );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
