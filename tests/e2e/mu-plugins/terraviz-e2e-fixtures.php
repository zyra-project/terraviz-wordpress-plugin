<?php
/**
 * Plugin Name: Terraviz E2E Fixtures
 * Description: Offline, deterministic fixtures for the Playwright screenshot
 *              suite. Intercepts the Terraviz public read API and serves canned
 *              catalog/dataset/related/hero JSON, plus placeholder thumbnails,
 *              so screenshots never depend on the live node or the network.
 *
 * Inert unless the `TERRAVIZ_E2E` constant (or environment variable) is truthy,
 * so mounting it into a normal `wp-env` dev instance changes nothing. The
 * screenshot workflow flips the constant on via `.wp-env.override.json`.
 *
 * This file is E2E test scaffolding — it never ships in the plugin ZIP (see
 * `.distignore`) and is excluded from PHPCS (see `phpcs.xml.dist`).
 *
 * @package Terraviz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the fixture layer is active for this request.
 */
function terraviz_e2e_enabled() {
	if ( defined( 'TERRAVIZ_E2E' ) && TERRAVIZ_E2E ) {
		return true;
	}

	$env = getenv( 'TERRAVIZ_E2E' );

	return ! empty( $env ) && '0' !== $env && 'false' !== strtolower( (string) $env );
}

if ( ! terraviz_e2e_enabled() ) {
	return;
}

/**
 * Read a fixture file from tests/e2e/fixtures and substitute the {{SITE}}
 * token with this install's home URL, so thumbnail links resolve same-origin.
 *
 * @param string $name Fixture basename without extension.
 * @return string|null Raw JSON body, or null when the fixture is missing.
 */
function terraviz_e2e_fixture( $name ) {
	$path = __DIR__ . '/../fixtures/' . $name . '.json';
	if ( ! is_readable( $path ) ) {
		return null;
	}

	$json = (string) file_get_contents( $path );

	return str_replace( '{{SITE}}', untrailingslashit( home_url() ), $json );
}

/**
 * Index the catalog datasets by every selector the renderer might deep-link
 * on (canonical id, slug, legacyId).
 *
 * @return array<string,array<string,mixed>>
 */
function terraviz_e2e_dataset_index() {
	static $index = null;
	if ( null !== $index ) {
		return $index;
	}

	$index   = array();
	$catalog = json_decode( (string) terraviz_e2e_fixture( 'catalog' ), true );
	$rows    = ( is_array( $catalog ) && isset( $catalog['datasets'] ) ) ? $catalog['datasets'] : array();

	foreach ( $rows as $row ) {
		foreach ( array( 'id', 'slug', 'legacyId' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				$index[ (string) $row[ $key ] ] = $row;
			}
		}
	}

	return $index;
}

/**
 * Build a WP HTTP API response array from a JSON body.
 *
 * @param string $body JSON body.
 * @param int    $code HTTP status code.
 * @return array<string,mixed>
 */
function terraviz_e2e_response( $body, $code = 200 ) {
	$messages = array(
		200 => 'OK',
		404 => 'Not Found',
		500 => 'Internal Server Error',
	);

	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => isset( $messages[ $code ] ) ? $messages[ $code ] : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Serve a whole-file fixture as a response, failing loudly (500 + JSON error)
 * when the fixture file is missing or unreadable rather than returning an
 * empty 200 body that decodes to nothing and breaks confusingly downstream.
 *
 * @param string $name Fixture basename without extension.
 * @return array<string,mixed>
 */
function terraviz_e2e_fixture_response( $name ) {
	$body = terraviz_e2e_fixture( $name );
	if ( null === $body ) {
		return terraviz_e2e_response(
			(string) wp_json_encode(
				array(
					'error'   => 'fixture_missing',
					'fixture' => $name,
				)
			),
			500
		);
	}

	return terraviz_e2e_response( $body );
}

/**
 * Short-circuit outbound requests to the Terraviz public read API with canned
 * fixtures. Any non-API request (or an unknown route) is passed through
 * untouched by returning the original $preempt value.
 *
 * @param mixed  $preempt Whatever a prior filter set (false = "make the request").
 * @param array  $args    Request args (unused).
 * @param string $url     Target URL.
 * @return mixed
 */
function terraviz_e2e_intercept( $preempt, $args, $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	// Full catalog envelope.
	if ( '/api/v1/catalog' === $path ) {
		return terraviz_e2e_fixture_response( 'catalog' );
	}

	// Curated "right now" hero.
	if ( '/api/v1/featured-hero' === $path ) {
		return terraviz_e2e_fixture_response( 'featured-hero' );
	}

	// "More like this" rail for a dataset.
	if ( preg_match( '#^/api/v1/datasets/([^/]+)/related$#', $path ) ) {
		return terraviz_e2e_fixture_response( 'related' );
	}

	// A single dataset (also used to resolve tours and the hero body).
	if ( preg_match( '#^/api/v1/datasets/([^/]+)$#', $path, $m ) ) {
		$id    = rawurldecode( $m[1] );
		$index = terraviz_e2e_dataset_index();
		if ( isset( $index[ $id ] ) ) {
			// Re-run the {{SITE}} substitution on the encoded single row.
			$body = str_replace(
				'{{SITE}}',
				untrailingslashit( home_url() ),
				(string) wp_json_encode( $index[ $id ] )
			);

			return terraviz_e2e_response( $body );
		}

		return terraviz_e2e_response( '{"error":"not_found"}', 404 );
	}

	return $preempt;
}
add_filter( 'pre_http_request', 'terraviz_e2e_intercept', 10, 3 );

/**
 * Serve a deterministic SVG placeholder thumbnail for
 * `?terraviz_e2e_thumb=<label>&w=&h=&c=<hex>`, so screenshots show real,
 * offline artwork instead of broken cross-origin images.
 */
function terraviz_e2e_serve_thumb() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public placeholder image, no state change.
	if ( ! isset( $_GET['terraviz_e2e_thumb'] ) ) {
		return;
	}

	// The requested key only *selects* a hardcoded preset — no value from the
	// request is ever echoed, so there is no reflected-output / XSS surface
	// (an unknown key falls back to a neutral preset, never to the raw input).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key = sanitize_key( wp_unslash( (string) $_GET['terraviz_e2e_thumb'] ) );

	$presets = array(
		'sea-surface-temp' => array( 'Sea Surface Temperature', 'e05a3a' ),
		'global-precip'    => array( 'Global Precipitation', '2f6fb0' ),
		'sea-ice-extent'   => array( 'Arctic Sea Ice', '3a9d8f' ),
		'night-lights'     => array( 'Earth at Night', 'c8922b' ),
		'coral-tour'       => array( 'Coral Reefs Tour', 'b5477e' ),
	);
	// Resolve the preset by *comparing* the key rather than indexing with it, so
	// the request value only ever gates a branch and never flows into the
	// response — $title/$color are always one of the constant strings above.
	$title = 'Terraviz Dataset';
	$color = '2f6fb0';
	foreach ( $presets as $preset_key => $preset ) {
		if ( $preset_key === $key ) {
			$title = $preset[0];
			$color = $preset[1];
			break;
		}
	}

	$width  = 640;
	$height = 360;

	$svg = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
			. '<stop offset="0" stop-color="#%4$s"/><stop offset="1" stop-color="#0b1b2b"/>'
			. '</linearGradient></defs>'
			. '<rect width="%1$d" height="%2$d" fill="url(#g)"/>'
			. '<circle cx="%5$d" cy="%6$d" r="%7$d" fill="#ffffff" opacity="0.10"/>'
			. '<text x="50%%" y="50%%" fill="#ffffff" font-family="Georgia,\'Times New Roman\',serif" '
			. 'font-size="%8$d" text-anchor="middle" dominant-baseline="middle" opacity="0.95">%9$s</text>'
			. '</svg>',
		$width,
		$height,
		esc_attr( $title ),
		$color,
		(int) ( $width * 0.78 ),
		(int) ( $height * 0.30 ),
		(int) ( $height * 0.55 ),
		max( 14, (int) ( $height / 9 ) ),
		esc_html( $title )
	);

	header( 'Content-Type: image/svg+xml' );
	header( 'Cache-Control: public, max-age=3600' );
	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG assembled from hardcoded presets; no request data reaches output.
	exit;
}
add_action( 'init', 'terraviz_e2e_serve_thumb' );
