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

// Shared options for every capture, so the gallery/listing PNGs and the
// `toHaveScreenshot` baseline are taken the same way:
//   - scale: 'css'      — 1 image px per CSS px, so a device-scale-factor > 1
//                         (the mobile project, DPR 2) doesn't emit gallery PNGs
//                         at 2× the baseline's dimensions (a no-op at DPR 1).
//   - animations: 'disabled' — matches playwright.config.js's toHaveScreenshot,
//                         freezing CSS animations/transitions to their end state.
const SHOT_OPTS = { scale: 'css', animations: 'disabled' };

/**
 * Wait until the element's rendered height stops changing before we capture it.
 *
 * `toHaveScreenshot` retries internally until the render is visually stable, but
 * a raw `locator.screenshot()` grabs a single immediate frame. On an admin page
 * that is still settling (e.g. the settings screen growing a few px after load),
 * that makes the gallery shot a few pixels shorter than the settled baseline, and
 * the visual report — which diffs gallery vs baseline — false-flags it as
 * "resized". Polling the box height until it holds steady lets every capture
 * below see the same settled layout. Best-effort: it just returns on timeout.
 *
 * @param {import('@playwright/test').Locator} locator Element to settle.
 */
async function waitForStableHeight( locator ) {
	let last = null;
	for ( let i = 0; i < 20; i++ ) {
		const box = await locator.boundingBox();
		const height = box ? Math.round( box.height ) : null;
		if ( height !== null && height === last ) {
			return;
		}
		last = height;
		await locator.page().waitForTimeout( 150 );
	}
}

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
	// Let a still-settling layout stabilise so all three captures agree.
	await waitForStableHeight( locator );

	fs.mkdirSync( GALLERY, { recursive: true } );
	await locator.screenshot( {
		path: path.join( GALLERY, `${ name }.png` ),
		...SHOT_OPTS,
	} );

	if ( opts.wporg ) {
		fs.mkdirSync( WPORG, { recursive: true } );
		await locator.screenshot( {
			path: path.join( WPORG, `screenshot-${ opts.wporg }.png` ),
			...SHOT_OPTS,
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
