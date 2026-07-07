/**
 * Screenshot routing helper.
 *
 * Every captured shot fans out to up to three destinations:
 *   - a gallery PNG (tests/e2e/.artifacts/gallery/<name>.png) — the
 *     "here's how it looks" CI artifact;
 *   - optionally .wordpress-org/screenshot-<N>.png — the canonical
 *     WordPress.org plugin-listing image (referenced by readme.txt's
 *     `== Screenshots ==` section, deployed to the plugin SVN `assets/`);
 *   - a committed baseline (tests/e2e/__snapshots__/<name>.png) that
 *     `toHaveScreenshot` diffs against, failing the run on an unexpected change.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const GALLERY = path.join( __dirname, '..', '.artifacts', 'gallery' );
const WPORG = path.join( __dirname, '..', '..', '..', '.wordpress-org' );

/**
 * Capture `locator` under `name` and route it to every destination.
 *
 * @param {import('@playwright/test').Locator} locator Element to shoot.
 * @param {string}                             name    Base file name (no ext).
 * @param {Function}                           expect  The test's `expect`.
 * @param {Object}                             [opts]  Options.
 * @param {number}                             [opts.wporg] WordPress.org listing index (1-based) to also write.
 */
async function snap( locator, name, expect, opts = {} ) {
	fs.mkdirSync( GALLERY, { recursive: true } );
	await locator.screenshot( { path: path.join( GALLERY, `${ name }.png` ) } );

	if ( opts.wporg ) {
		fs.mkdirSync( WPORG, { recursive: true } );
		await locator.screenshot( {
			path: path.join( WPORG, `screenshot-${ opts.wporg }.png` ),
		} );
	}

	// The visual-regression gate. On the first run (or `--update-snapshots`)
	// this writes the baseline; thereafter it diffs against it. Playwright's
	// `{arg}` token is the name WITHOUT extension, so `${name}.png` with the
	// `{arg}{ext}` template resolves to `<name>.png` (single extension), exactly
	// as with Playwright's own default template.
	await expect( locator ).toHaveScreenshot( `${ name }.png` );
}

module.exports = { snap };
