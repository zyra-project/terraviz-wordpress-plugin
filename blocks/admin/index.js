/**
 * Bootstrap for the Terraviz publisher dashboard (wp-admin).
 *
 * Wires the REST cookie nonce into api-fetch and mounts the React app into the
 * container printed by `Admin\Dashboard::render()`.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot, render } from '@wordpress/element';
import App from './App';

const boot = window.terravizPublisher || {};

if ( boot.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
}

function mount() {
	const el = document.getElementById( 'terraviz-publisher-app' );
	if ( ! el ) {
		return;
	}
	const app = <App boot={ boot } />;
	// createRoot on WP 6.2+; fall back to render for 6.1.
	if ( typeof createRoot === 'function' ) {
		createRoot( el ).render( app );
	} else {
		render( app, el );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
