<?php
/**
 * Composes Terraviz embed + canonical URLs per the versioned URL grammar.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Embed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `/?dataset=…&embed=1&…` embed URLs and the human-facing
 * canonical links, following docs/EMBED_URL_GRAMMAR.md (grammar v1). The
 * plugin depends on this published grammar, never on Terraviz's source.
 *
 * View toggles use the fixed `on`-only convention; the `embed`/`chat`
 * booleans use presence-is-on. Unknown values are silently ignored by the
 * app, so we only ever emit the documented shapes.
 */
final class UrlBuilder {

	/**
	 * Compose an embed iframe URL.
	 *
	 * @param string              $origin   Node origin (scheme+host).
	 * @param string              $selector One of 'dataset', 'tour', 'catalog'.
	 * @param string              $value    Dataset id / tour slug / '' for catalog.
	 * @param array<string,mixed> $flags    View flags (see below).
	 * @return string
	 */
	public static function embed( string $origin, string $selector, string $value, array $flags = array() ): string {
		$origin = untrailingslashit( $origin );
		$query  = array();

		switch ( $selector ) {
			case 'tour':
				$query['tour'] = $value;
				break;
			case 'catalog':
				$query['catalog'] = 'true';
				if ( ! empty( $flags['category'] ) ) {
					$query['category'] = (string) $flags['category'];
				}
				break;
			case 'dataset':
			default:
				$query['dataset'] = $value;
				break;
		}

		// embed=1 is presentational only — always present for an embed.
		$query['embed'] = '1';

		// Orbit chat trigger is opt-in and only meaningful with embed=1.
		if ( ! empty( $flags['chat'] ) ) {
			$query['chat'] = '1';
		}

		// on-only view modifiers.
		foreach ( array( 'terrain', 'labels', 'borders', 'rotate' ) as $toggle ) {
			if ( ! empty( $flags[ $toggle ] ) ) {
				$query[ $toggle ] = 'on';
			}
		}

		// Multi-globe grid: only 1, 2, 4 are valid.
		if ( isset( $flags['layout'] ) && in_array( (int) $flags['layout'], array( 1, 2, 4 ), true ) ) {
			$query['layout'] = (string) (int) $flags['layout'];
		}

		// Open Orbit panel on load, optionally seeded.
		if ( ! empty( $flags['orbit_open'] ) ) {
			$query['orbit'] = 'open';
			if ( ! empty( $flags['prompt'] ) ) {
				$query['prompt'] = (string) $flags['prompt'];
			}
		}

		return $origin . '/?' . self::build_query( $query );
	}

	/**
	 * The human-facing canonical URL for a selector — used for the SSR
	 * "View on Terraviz" link and as the crawlable target.
	 */
	public static function canonical( string $origin, string $selector, string $value ): string {
		$origin = untrailingslashit( $origin );

		switch ( $selector ) {
			case 'tour':
				return $origin . '/?' . self::build_query( array( 'tour' => $value ) );
			case 'catalog':
				return $origin . '/?' . self::build_query( array( 'catalog' => 'true' ) );
			case 'dataset':
			default:
				// The canonical path form documented in the grammar.
				return $origin . '/dataset/' . rawurlencode( $value );
		}
	}

	/**
	 * Build a query string preserving key order and using rawurlencode,
	 * without WordPress re-encoding surprises.
	 *
	 * @param array<string,string> $query Ordered key/value pairs.
	 */
	private static function build_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $pairs );
	}
}
