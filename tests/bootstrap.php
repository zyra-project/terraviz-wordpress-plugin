<?php
/**
 * PHPUnit bootstrap: load the WordPress test suite and this plugin.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

$tvz_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tvz_tests_dir ) {
	$tmp           = getenv( 'TMPDIR' ) ? getenv( 'TMPDIR' ) : '/tmp';
	$tvz_tests_dir = rtrim( $tmp, '/\\' ) . '/wordpress-tests-lib';
}

$tvz_functions = $tvz_tests_dir . '/includes/functions.php';

if ( ! is_readable( $tvz_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$tvz_tests_dir}.\n" .
		"Run `composer run env:install-tests` or use `wp-env run tests-cli`.\n"
	);
	exit( 1 );
}

require_once $tvz_functions;

/**
 * Load the plugin into the test WordPress instance.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/terraviz.php';
	}
);

require $tvz_tests_dir . '/includes/bootstrap.php';

// Shared test helpers.
require __DIR__ . '/helpers/FakeReader.php';
