/**
 * Playwright global setup for the screenshot suite.
 *
 * 1. Seeds one published page per embed surface (dataset / tour / catalog /
 *    hero / related) via WP-CLI inside the wp-env `cli` container, writing a
 *    manifest the specs read to know which page to visit.
 * 2. Logs in as the wp-env admin and saves the authenticated storage state so
 *    the `admin` project can screenshot wp-admin without re-logging in.
 *
 * Runs on the host; `wp-env` must already be started with the E2E fixtures
 * enabled (TERRAVIZ_E2E). See tests/e2e/README.md.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'password';
const ARTIFACTS = path.join( __dirname, '.artifacts' );

// One page per embed surface. The blocks are server-rendered (`save: () =>
// null`), so the block comment plus its attributes is all WordPress stores —
// the render_callback produces the HTML on view.
const PAGES = [
	{ key: 'dataset', slug: 'tze2e-dataset', title: 'Terraviz Dataset block', content: '<!-- wp:terraviz/dataset {"id":"sea-surface-temp"} /-->' },
	{ key: 'tour', slug: 'tze2e-tour', title: 'Terraviz Tour block', content: '<!-- wp:terraviz/tour {"id":"coral-tour"} /-->' },
	{ key: 'catalog', slug: 'tze2e-catalog', title: 'Terraviz Catalog block', content: '<!-- wp:terraviz/catalog /-->' },
	{ key: 'hero', slug: 'tze2e-hero', title: 'Terraviz Hero block', content: '<!-- wp:terraviz/hero /-->' },
	{ key: 'related', slug: 'tze2e-related', title: 'Terraviz Related block', content: '<!-- wp:terraviz/related {"id":"sea-surface-temp"} /-->' },
];

/**
 * Seed the fixture pages with a single idempotent WP-CLI `eval`. The page spec
 * is passed base64-encoded to sidestep every layer of shell/PHP/JSON quoting.
 *
 * @return {Object} manifest keyed by page `key`.
 */
function seedPages() {
	// The page spec is base64-encoded (alphanumeric, brace-free) so it survives
	// every shell/PHP/JSON quoting layer. The PHP itself uses alternative
	// (brace-free) syntax so the ONLY `{`/`}` anywhere in the command's stdout
	// belong to the JSON manifest we echo — which lets us extract it reliably
	// even when wp-env prefixes its own status lines.
	const b64 = Buffer.from( JSON.stringify( PAGES ) ).toString( 'base64' );
	const php = [
		"$pages = json_decode( base64_decode( '" + b64 + "' ), true );",
		'$out = array();',
		'foreach ( $pages as $p ):',
		"  $existing = get_page_by_path( $p['slug'], OBJECT, 'page' );",
		'  if ( $existing ): wp_delete_post( $existing->ID, true ); endif;',
		"  $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $p['slug'], 'post_title' => $p['title'], 'post_content' => $p['content'] ) );",
		"  $out[ $p['key'] ] = array( 'id' => $id, 'slug' => $p['slug'], 'title' => $p['title'] );",
		'endforeach;',
		'echo wp_json_encode( $out );',
	].join( ' ' );

	const raw = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', 'eval', php ],
		{ encoding: 'utf8' }
	);

	// wp-env may wrap the command output in status lines; grab the JSON object.
	const match = raw.match( /\{[\s\S]*\}/ );
	if ( ! match ) {
		throw new Error( 'Could not parse seeded-page manifest from WP-CLI output:\n' + raw );
	}

	return JSON.parse( match[ 0 ] );
}

/**
 * Log into wp-admin and persist the authenticated storage state.
 *
 * @param {string} statePath Where to write the storage state JSON.
 */
async function saveAdminState( statePath ) {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { baseURL: BASE_URL } );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', ADMIN_USER );
		await page.fill( '#user_pass', ADMIN_PASS );
		await page.click( '#wp-submit' );
		await page.waitForSelector( '#wpadminbar, #login_error', { timeout: 30000 } );
		await page.context().storageState( { path: statePath } );
	} finally {
		await browser.close();
	}
}

module.exports = async function globalSetup() {
	fs.mkdirSync( ARTIFACTS, { recursive: true } );

	const manifest = seedPages();
	fs.writeFileSync(
		path.join( ARTIFACTS, 'pages.json' ),
		JSON.stringify( manifest, null, '\t' )
	);

	await saveAdminState( path.join( ARTIFACTS, 'admin-state.json' ) );
};
