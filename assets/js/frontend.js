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

	// Align feature detection with the methods we actually call: require the
	// element's request method to exist, and — when the browser exposes the
	// enabled flag (fullscreen may be blocked by permissions policy) — honour it.
	function fullscreenSupported( el ) {
		if ( ! ( el.requestFullscreen || el.webkitRequestFullscreen ) ) {
			return false;
		}
		if ( typeof document.fullscreenEnabled === 'boolean' ) {
			return document.fullscreenEnabled;
		}
		if ( typeof document.webkitFullscreenEnabled === 'boolean' ) {
			return document.webkitFullscreenEnabled;
		}
		return true;
	}

	function fullscreenElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	function requestFullscreen( el ) {
		if ( el.requestFullscreen ) {
			return el.requestFullscreen();
		}
		if ( el.webkitRequestFullscreen ) {
			return el.webkitRequestFullscreen();
		}
	}

	function exitFullscreen() {
		if ( document.exitFullscreen ) {
			return document.exitFullscreen();
		}
		if ( document.webkitExitFullscreen ) {
			return document.webkitExitFullscreen();
		}
	}

	function labels( media ) {
		var title = media.getAttribute( 'data-terraviz-title' ) || 'Terraviz';
		return {
			enter: 'Enter fullscreen: ' + title,
			exit: 'Exit fullscreen: ' + title,
		};
	}

	// A single toggle button, overlaid on the globe once it has loaded.
	function addFullscreenButton( media ) {
		if ( ! fullscreenSupported( media ) ) {
			return;
		}
		// Guard against a second injection (e.g. an iframe that fires load twice).
		if ( media.querySelector( '.terraviz-embed__fullscreen' ) ) {
			return;
		}

		var text = labels( media );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'terraviz-embed__fullscreen';
		btn.setAttribute( 'aria-label', text.enter );
		btn.setAttribute( 'title', text.enter );
		// Two icons (expand / compress); CSS shows the one matching the state.
		btn.innerHTML =
			'<svg class="terraviz-embed__fs-enter" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' +
			'<path fill="currentColor" d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm16 0v6h-6v-2h4v-4h2z"/></svg>' +
			'<svg class="terraviz-embed__fs-exit" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' +
			'<path fill="currentColor" d="M8 4h2v6H4V8h4V4zm6 0h2v4h4v2h-6V4zM4 14h6v6H8v-4H4v-2zm10 0h6v2h-4v4h-2v-6z"/></svg>';

		btn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			if ( fullscreenElement() === media ) {
				exitFullscreen();
			} else {
				var res = requestFullscreen( media );
				if ( res && typeof res.catch === 'function' ) {
					res.catch( function () {
						/* user gesture rejected / not permitted */
					} );
				}
			}
		} );

		// Keep the label/pressed-state in sync with the actual fullscreen state.
		function sync() {
			var active = fullscreenElement() === media;
			media.classList.toggle( 'is-fullscreen', active );
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			btn.setAttribute( 'aria-label', active ? text.exit : text.enter );
			btn.setAttribute( 'title', active ? text.exit : text.enter );
		}
		sync();
		document.addEventListener( 'fullscreenchange', sync );
		document.addEventListener( 'webkitfullscreenchange', sync );

		media.appendChild( btn );
	}

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

		// Offer a fullscreen toggle only once the globe has actually loaded, so
		// the button never appears over an iframe that failed to load
		// (network / CSP). Attach before insertion so the load event isn't missed.
		iframe.addEventListener( 'load', function () {
			addFullscreenButton( media );
		} );

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
