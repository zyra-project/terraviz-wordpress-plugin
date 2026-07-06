/**
 * Extends the @wordpress/scripts default webpack config to add the wp-admin
 * publisher dashboard as an entry point, alongside the auto-detected block
 * scripts (the build still runs with `--webpack-src-dir=blocks`).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const baseEntry =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...baseEntry,
		'admin/index': path.resolve( process.cwd(), 'blocks', 'admin', 'index.js' ),
		'post-panel/index': path.resolve(
			process.cwd(),
			'blocks',
			'post-panel',
			'index.js'
		),
	},
};
