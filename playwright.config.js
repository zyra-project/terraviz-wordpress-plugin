/**
 * Playwright configuration for the Terraviz screenshot / visual-regression
 * suite. It drives a real WordPress (booted by `wp-env`, default
 * http://localhost:8888) with the plugin active and the offline E2E fixtures
 * enabled, then captures every block and admin dashboard view.
 *
 * Two outputs per shot (see tests/e2e/helpers/shots.js):
 *   - a gallery PNG under tests/e2e/.artifacts/gallery/ (uploaded as a CI
 *     artifact — "here's how everything looks");
 *   - a committed baseline under tests/e2e/__snapshots__/ that
 *     `toHaveScreenshot` compares against (visual-regression gate).
 * A subset is also written to .wordpress-org/screenshot-N.png for the
 * WordPress.org plugin listing.
 *
 * This file is dev tooling and never ships in the plugin ZIP (see .distignore).
 */

const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

const ARTIFACTS = path.join( __dirname, 'tests', 'e2e', '.artifacts' );
const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: path.join( __dirname, 'tests', 'e2e' ),
	testMatch: /.*\.spec\.js$/,
	outputDir: path.join( ARTIFACTS, 'test-results' ),
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),

	// Screenshots must be deterministic, so run serially.
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: !! process.env.CI,
	timeout: 60 * 1000,

	reporter: process.env.CI
		? [
				[ 'github' ],
				[ 'html', { outputFolder: path.join( ARTIFACTS, 'report' ), open: 'never' } ],
				[ 'list' ],
		  ]
		: [ [ 'list' ] ],

	// One flat, platform-agnostic baseline directory. Baselines are generated
	// on the CI Linux runner (`npm run screenshots:update`); see tests/e2e/README.md.
	snapshotPathTemplate: '{testDir}/__snapshots__/{arg}{ext}',

	expect: {
		toHaveScreenshot: {
			maxDiffPixelRatio: 0.02,
			animations: 'disabled',
			scale: 'css',
		},
	},

	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'off',
	},

	projects: [
		{
			name: 'frontend',
			testMatch: /blocks\.spec\.js$/,
			use: {
				browserName: 'chromium',
				viewport: { width: 1280, height: 800 },
				deviceScaleFactor: 1,
			},
		},
		{
			// The blocks again at a phone width — the visitor-facing surface, where
			// the responsive SSR fallback actually has to reflow. Shares the block
			// specs; blocks.spec.js suffixes these shots `-mobile` and skips the
			// WordPress.org listing writes (those stay the desktop images). The
			// wp-admin views are WordPress core's responsive surface, not the
			// plugin's, so they aren't re-shot here.
			name: 'frontend-mobile',
			testMatch: /blocks\.spec\.js$/,
			use: {
				browserName: 'chromium',
				viewport: { width: 390, height: 844 },
				deviceScaleFactor: 2,
				isMobile: true,
				hasTouch: true,
			},
		},
		{
			name: 'admin',
			testMatch: /dashboard\.spec\.js$/,
			use: {
				browserName: 'chromium',
				viewport: { width: 1280, height: 900 },
				deviceScaleFactor: 1,
				storageState: path.join( ARTIFACTS, 'admin-state.json' ),
			},
		},
	],
} );
