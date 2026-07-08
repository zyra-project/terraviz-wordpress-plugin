/**
 * Front-end block screenshots.
 *
 * Visits the page seeded for each embed surface and captures its server-side
 * rendered figure. Because the click-to-load poster is the default, the heavy
 * cross-origin globe iframe never boots — the shot shows the deterministic SSR
 * fallback (title, abstract, thumbnail, tags, canonical link) served from the
 * offline fixtures.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { snap } = require( './helpers/shots' );

const manifest = JSON.parse(
	fs.readFileSync( path.join( __dirname, '.artifacts', 'pages.json' ), 'utf8' )
);

// The hero renders like a dataset embed but keeps its own `--hero` type class
// (render_hero() delegates to render_dataset() without changing $atts['type']).
const CASES = [
	{ key: 'dataset', selector: '.terraviz-embed--dataset', wporg: 1 },
	{ key: 'tour', selector: '.terraviz-embed--tour', wporg: 2 },
	{ key: 'catalog', selector: '.terraviz-embed--catalog', wporg: 3 },
	{ key: 'hero', selector: '.terraviz-embed--hero', wporg: 4 },
	{ key: 'related', selector: '.terraviz-embed--related', wporg: 5 },
];

for ( const testCase of CASES ) {
	test( `block: ${ testCase.key }`, async ( { page }, testInfo ) => {
		const seeded = manifest[ testCase.key ];
		expect( seeded, `page for "${ testCase.key }" was seeded` ).toBeTruthy();

		await page.goto( `/?page_id=${ seeded.id }` );

		const figure = page.locator( testCase.selector ).first();
		await figure.waitFor( { state: 'visible' } );

		// Let the SSR thumbnails (served same-origin by the fixture mu-plugin) paint.
		await page.waitForLoadState( 'networkidle', { timeout: 8000 } ).catch( () => {} );

		// The same specs run under both the desktop (`frontend`) and phone-width
		// (`frontend-mobile`) projects. Mobile shots get a `-mobile` baseline and
		// skip the WordPress.org listing images (those stay the desktop set).
		const isMobile = testInfo.project.name === 'frontend-mobile';
		const name = isMobile ? `block-${ testCase.key }-mobile` : `block-${ testCase.key }`;
		const opts = isMobile ? {} : { wporg: testCase.wporg };

		await snap( figure, name, expect, opts );
	} );
}
