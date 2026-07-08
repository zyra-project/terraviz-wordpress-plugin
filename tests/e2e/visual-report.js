#!/usr/bin/env node
/**
 * Visual report generator.
 *
 * Compares the freshly captured gallery actuals (tests/e2e/.artifacts/gallery/)
 * against the committed baselines (tests/e2e/__snapshots__/) and emits:
 *
 *   - tests/e2e/.artifacts/visual-report.json   — machine-readable per-scene metrics
 *   - tests/e2e/.artifacts/visual-report.md     — the PR comment body
 *   - tests/e2e/.artifacts/gallery/<name>.diff.png — a red-highlighted diff overlay
 *     for every scene that changed (so the gallery artifact carries the evidence)
 *
 * It reuses `snap()`'s own gallery PNGs, so it works whether or not the
 * `toHaveScreenshot` gate passed: `snap()` writes the gallery actual *before*
 * asserting, so even a failing scene has an actual to diff. This is a read-only
 * reporting pass — it never touches the committed baselines and never fails the
 * build. The `Screenshots` workflow uploads the outputs; a separate,
 * write-scoped `workflow_run` job posts the comment (see
 * .github/workflows/screenshots-comment.yml).
 *
 * Dev tooling; never ships in the plugin ZIP (see .distignore).
 *
 * Usage: node tests/e2e/visual-report.js [--threshold 0.001]
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { PNG } = require( 'pngjs' );
const pixelmatch = require( 'pixelmatch' );

const E2E_DIR = __dirname;
const GALLERY = path.join( E2E_DIR, '.artifacts', 'gallery' );
const BASELINES = path.join( E2E_DIR, '__snapshots__' );
const OUT_JSON = path.join( E2E_DIR, '.artifacts', 'visual-report.json' );
const OUT_MD = path.join( E2E_DIR, '.artifacts', 'visual-report.md' );

// The comment marker lets the poster find and update its own sticky comment.
const MARKER = '<!-- terraviz-visual-report -->';

// Report threshold (ratio of changed pixels). Intentionally stricter than the
// 0.02 `toHaveScreenshot` *gate* — the report surfaces subtle changes for human
// review even when the gate tolerates them. Override with --threshold or
// VISUAL_REPORT_THRESHOLD.
const DEFAULT_THRESHOLD = 0.001;

// Human labels for the known scenes, keyed by their base name (without any
// `-mobile` viewport suffix). Unknown scenes (e.g. a newly added spec) fall back
// to a derived label; see sceneMeta().
const SCENES = {
	'block-dataset': 'Dataset block',
	'block-tour': 'Tour block',
	'block-catalog': 'Catalog block',
	'block-hero': 'Right-Now Hero block',
	'block-related': 'Related Datasets rail',
	'dashboard-publisher': 'Publisher dashboard',
	'dashboard-settings': 'Settings screen',
};

function parseThreshold() {
	const idx = process.argv.indexOf( '--threshold' );
	const raw =
		idx !== -1 && process.argv[ idx + 1 ]
			? process.argv[ idx + 1 ]
			: process.env.VISUAL_REPORT_THRESHOLD;
	if ( raw === undefined ) {
		return DEFAULT_THRESHOLD;
	}
	// A non-numeric or negative override would make `ratio > threshold` never
	// (or always) true and silently hide real diffs — reject it.
	const value = Number( raw );
	if ( ! Number.isFinite( value ) || value < 0 ) {
		// eslint-disable-next-line no-console
		console.warn( `Ignoring invalid threshold "${ raw }"; using ${ DEFAULT_THRESHOLD }.` );
		return DEFAULT_THRESHOLD;
	}
	return value;
}

// Neutralise anything a scene name could inject into the Markdown table/summary.
// Scene names come from the PR's own spec files, and the comment is posted by a
// write-scoped job, so keep untrusted strings inert: no pipes/backticks/HTML,
// and no `@`/`#` that GitHub would auto-link into mentions or issue refs.
function sanitize( str ) {
	return String( str )
		.replace( /[|`<>\\@#]/g, '' )
		.replace( /\r?\n/g, ' ' )
		.trim()
		.slice( 0, 120 );
}

function sceneMeta( name ) {
	const mobile = name.endsWith( '-mobile' );
	const base = mobile ? name.slice( 0, -'-mobile'.length ) : name;
	const label =
		SCENES[ base ] ||
		base.replace( /[-_]/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() );
	return { label, viewport: mobile ? 'mobile' : 'desktop' };
}

function listPngs( dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return [];
	}
	return fs
		.readdirSync( dir )
		.filter( ( f ) => f.endsWith( '.png' ) && ! f.endsWith( '.diff.png' ) )
		.map( ( f ) => f.replace( /\.png$/, '' ) );
}

function readPng( file ) {
	return PNG.sync.read( fs.readFileSync( file ) );
}

// Compare one scene's actual against its baseline. Returns a metrics record and,
// for a genuine change, writes a diff overlay into the gallery.
function compareScene( name, threshold ) {
	const meta = sceneMeta( name );
	const actualPath = path.join( GALLERY, `${ name }.png` );
	const baselinePath = path.join( BASELINES, `${ name }.png` );
	const base = {
		name,
		label: sanitize( meta.label ),
		viewport: sanitize( meta.viewport ),
	};

	if ( ! fs.existsSync( actualPath ) ) {
		// A committed baseline with no fresh capture — the scene failed to shoot.
		return { ...base, status: 'missing', changedPixels: 0, totalPixels: 0, ratio: 0 };
	}
	if ( ! fs.existsSync( baselinePath ) ) {
		const png = readPng( actualPath );
		return {
			...base,
			status: 'new',
			width: png.width,
			height: png.height,
			changedPixels: png.width * png.height,
			totalPixels: png.width * png.height,
			ratio: 1,
		};
	}

	const actual = readPng( actualPath );
	const baseline = readPng( baselinePath );

	// Dimension change — pixelmatch needs equal sizes; report it as a full change.
	if ( actual.width !== baseline.width || actual.height !== baseline.height ) {
		const totalPixels = Math.max(
			actual.width * actual.height,
			baseline.width * baseline.height
		);
		return {
			...base,
			status: 'resized',
			width: actual.width,
			height: actual.height,
			baselineWidth: baseline.width,
			baselineHeight: baseline.height,
			changedPixels: totalPixels,
			totalPixels,
			ratio: 1,
		};
	}

	const { width, height } = actual;
	const totalPixels = width * height;
	const diff = new PNG( { width, height } );
	const changedPixels = pixelmatch(
		actual.data,
		baseline.data,
		diff.data,
		width,
		height,
		{ threshold: 0.1, includeAA: false }
	);
	const ratio = totalPixels ? changedPixels / totalPixels : 0;
	const changed = ratio > threshold;

	if ( changed ) {
		fs.writeFileSync( path.join( GALLERY, `${ name }.diff.png` ), PNG.sync.write( diff ) );
	}

	return {
		...base,
		status: changed ? 'changed' : 'unchanged',
		width,
		height,
		changedPixels,
		totalPixels,
		ratio,
	};
}

function pct( ratio ) {
	return `${ ( ratio * 100 ).toFixed( 2 ) }%`;
}

function changeCell( s ) {
	switch ( s.status ) {
		case 'new':
			return `new baseline (${ s.width }×${ s.height })`;
		case 'missing':
			return 'not captured ⚠️';
		case 'resized':
			return `resized ${ s.baselineWidth }×${ s.baselineHeight } → ${ s.width }×${ s.height }`;
		default:
			return `${ pct( s.ratio ) } (${ s.changedPixels } px)`;
	}
}

function buildMarkdown( scenes, threshold ) {
	const viewports = [ ...new Set( scenes.map( ( s ) => s.viewport ) ) ];
	const problems = scenes.filter( ( s ) => s.status !== 'unchanged' );
	const changed = scenes.filter( ( s ) => s.status === 'changed' || s.status === 'resized' );
	const created = scenes.filter( ( s ) => s.status === 'new' );
	const missing = scenes.filter( ( s ) => s.status === 'missing' );

	const lines = [];
	lines.push( MARKER );
	lines.push( '### 🖼️ Visual report' );
	lines.push( '' );

	const summary =
		`**${ scenes.length }** shot(s) · ${ viewports.length } viewport(s) ` +
		`(${ viewports.join( ', ' ) }) · **${ problems.length } with changes**`;
	lines.push( summary );
	lines.push( '' );
	lines.push(
		`**Regression:** ${ changed.length } shot(s) changed, ${ created.length } new ` +
			`(no baseline)${ missing.length ? `, ${ missing.length } not captured` : '' }, ` +
			`threshold ${ threshold }.`
	);
	lines.push( '' );

	if ( problems.length === 0 ) {
		lines.push( `All ${ scenes.length } shot(s) match their baselines. ✅` );
	} else {
		lines.push( '| Scene | Viewport | Change |' );
		lines.push( '|---|---|---|' );
		for ( const s of problems ) {
			lines.push( `| ${ s.label } | ${ s.viewport } | ${ changeCell( s ) } |` );
		}
	}
	lines.push( '' );

	// The full list, collapsed — every scene, changed or not.
	lines.push( `<details><summary>All ${ scenes.length } shot(s)</summary>` );
	lines.push( '' );
	lines.push( '| Scene | Viewport | Change |' );
	lines.push( '|---|---|---|' );
	for ( const s of scenes ) {
		const cell = s.status === 'unchanged' ? '—' : changeCell( s );
		lines.push( `| ${ s.label } | ${ s.viewport } | ${ cell } |` );
	}
	lines.push( '' );
	lines.push( '</details>' );
	lines.push( '' );
	lines.push(
		'Full gallery (actuals + `.diff.png` overlays) → the **`screenshots-gallery`** ' +
			'artifact on the Screenshots run.'
	);
	lines.push( '' );
	lines.push(
		'_Advisory — the Screenshots workflow is kept out of the core CI gate, ' +
			'so this never blocks a merge. Visual review only._'
	);
	lines.push( '' );

	return lines.join( '\n' );
}

function main() {
	const threshold = parseThreshold();

	// Union of scenes that have an actual and scenes that have a baseline, so a
	// failed capture (baseline but no actual) still surfaces.
	const names = [ ...new Set( [ ...listPngs( GALLERY ), ...listPngs( BASELINES ) ] ) ].sort();

	const scenes = names.map( ( name ) => compareScene( name, threshold ) );

	fs.mkdirSync( path.dirname( OUT_JSON ), { recursive: true } );
	fs.writeFileSync(
		OUT_JSON,
		JSON.stringify( { threshold, generatedScenes: scenes.length, scenes }, null, 2 )
	);

	const md = buildMarkdown( scenes, threshold );
	fs.writeFileSync( OUT_MD, md );

	const problems = scenes.filter( ( s ) => s.status !== 'unchanged' ).length;
	// eslint-disable-next-line no-console
	console.log(
		`Visual report: ${ scenes.length } shot(s), ${ problems } with changes ` +
			`(threshold ${ threshold }). Wrote ${ path.relative( process.cwd(), OUT_MD ) }.`
	);
}

main();
