<?php
/**
 * Tests for settings normalisation.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Support\Options;

/**
 * @covers \Terraviz\Support\Options
 */
class OptionsTest extends WP_UnitTestCase {

	public function test_default_origin_is_canonical(): void {
		delete_option( Options::OPTION );
		$this->assertSame( TERRAVIZ_DEFAULT_ORIGIN, Options::origin() );
	}

	public function test_bare_host_gets_https(): void {
		$this->assertSame( 'https://example.org', Options::normalize_origin( 'example.org' ) );
	}

	public function test_trailing_slash_and_path_are_stripped(): void {
		$this->assertSame( 'https://n.example.org', Options::normalize_origin( 'https://n.example.org/foo/bar/' ) );
	}

	public function test_unusable_origin_falls_back(): void {
		$this->assertSame( TERRAVIZ_DEFAULT_ORIGIN, Options::normalize_origin( '   ' ) );
	}

	public function test_aspect_ratio_validation(): void {
		$this->assertSame( '4:3', Options::sanitize_aspect_ratio( '4:3' ) );
		$this->assertSame( '16:9', Options::sanitize_aspect_ratio( 'nonsense' ) );
		$this->assertSame( '21:9', Options::sanitize_aspect_ratio( '21 : 9' ) );
	}

	public function test_cache_ttl_is_clamped(): void {
		$clean = Options::sanitize( array( 'cache_ttl' => 5 ) );
		$this->assertGreaterThanOrEqual( MINUTE_IN_SECONDS, $clean['cache_ttl'] );

		$clean = Options::sanitize( array( 'cache_ttl' => 999999999 ) );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, $clean['cache_ttl'] );
	}

	public function test_telemetry_is_whitelisted(): void {
		$clean = Options::sanitize( array( 'telemetry' => 'wat' ) );
		$this->assertSame( 'lazy', $clean['telemetry'] );

		$clean = Options::sanitize( array( 'telemetry' => 'eager' ) );
		$this->assertSame( 'eager', $clean['telemetry'] );
	}
}
