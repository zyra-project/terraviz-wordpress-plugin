/**
 * wp-admin dashboard screenshots (runs under the authenticated `admin` project).
 *
 * Captures the publisher dashboard app shell and the settings screen — the
 * two admin surfaces this plugin adds. Both render deterministically without
 * the live node (the dashboard shows its "connect a credential" state; the
 * settings screen is static).
 */

const { test, expect } = require( '@playwright/test' );
const { snap } = require( './helpers/shots' );

test( 'dashboard: publisher app', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=terraviz-publisher' );
	await page.locator( '#terraviz-publisher-app' ).waitFor();

	// Let the React app mount and settle into its initial (not-connected) state.
	await page.waitForLoadState( 'networkidle', { timeout: 8000 } ).catch( () => {} );
	await page.waitForTimeout( 750 );

	await snap( page.locator( '.wrap' ).first(), 'dashboard-publisher', expect, { wporg: 6 } );
} );

test( 'dashboard: settings', async ( { page } ) => {
	await page.goto( '/wp-admin/options-general.php?page=terraviz-settings' );
	await page.locator( 'form' ).first().waitFor();
	await page.waitForLoadState( 'networkidle', { timeout: 8000 } ).catch( () => {} );

	await snap( page.locator( '#wpbody-content' ).first(), 'dashboard-settings', expect, { wporg: 7 } );
} );
