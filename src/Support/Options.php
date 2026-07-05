<?php
/**
 * Typed accessor over the plugin's single settings option.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and normalises the `terraviz_settings` option.
 *
 * One option row holds every Phase-1 setting. There is deliberately no
 * credential field — the read/embed path is entirely public.
 */
final class Options {

	/**
	 * The option name in wp_options.
	 */
	public const OPTION = 'terraviz_settings';

	/**
	 * Default settings. `origin` defaults to the canonical node and is
	 * overridable both here (site-wide) and per block/shortcode.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'origin'          => TERRAVIZ_DEFAULT_ORIGIN,
			'default_terrain' => false,
			'default_labels'  => false,
			'default_borders' => false,
			'default_rotate'  => false,
			'default_chat'    => false,
			'aspect_ratio'    => '16:9',
			'lazy_poster'     => true,
			'cache_ttl'       => 15 * MINUTE_IN_SECONDS,
			// Telemetry posture. The embed inherits Terraviz's own
			// two-tier telemetry inside the iframe; this flag only
			// governs whether the plugin defers iframe boot until the
			// visitor interacts (a consent-friendly posture), it never
			// makes the plugin itself phone home.
			'telemetry'       => 'lazy',
		);
	}

	/**
	 * The merged, normalised settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Fetch one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * The configured node origin, always a scheme+host with no trailing slash.
	 */
	public static function origin(): string {
		$origin = (string) self::get( 'origin', TERRAVIZ_DEFAULT_ORIGIN );

		return self::normalize_origin( $origin );
	}

	/**
	 * Normalise an origin to `scheme://host[:port]` with no trailing slash
	 * or path. Falls back to the canonical origin when the input is unusable.
	 *
	 * @param string $origin Raw origin string.
	 */
	public static function normalize_origin( string $origin ): string {
		$origin = trim( $origin );
		if ( '' === $origin ) {
			return TERRAVIZ_DEFAULT_ORIGIN;
		}

		// Tolerate a bare host by assuming https.
		if ( ! preg_match( '#^https?://#i', $origin ) ) {
			$origin = 'https://' . ltrim( $origin, '/' );
		}

		$parts = wp_parse_url( $origin );
		if ( empty( $parts['host'] ) ) {
			return TERRAVIZ_DEFAULT_ORIGIN;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			$scheme = 'https';
		}

		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Sanitise a full settings array. Used on save and on read so a
	 * hand-edited option can never inject unsafe values downstream.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$out      = array();

		$out['origin'] = self::normalize_origin( (string) ( $input['origin'] ?? $defaults['origin'] ) );

		foreach ( array( 'default_terrain', 'default_labels', 'default_borders', 'default_rotate', 'default_chat', 'lazy_poster' ) as $bool ) {
			$out[ $bool ] = ! empty( $input[ $bool ] );
		}

		$ratio               = isset( $input['aspect_ratio'] ) ? (string) $input['aspect_ratio'] : $defaults['aspect_ratio'];
		$out['aspect_ratio'] = self::sanitize_aspect_ratio( $ratio );

		$ttl = isset( $input['cache_ttl'] ) ? (int) $input['cache_ttl'] : (int) $defaults['cache_ttl'];
		// Clamp: at least a minute (avoid hammering the node), at most a day.
		$out['cache_ttl'] = max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $ttl ) );

		$telemetry        = isset( $input['telemetry'] ) ? (string) $input['telemetry'] : $defaults['telemetry'];
		$out['telemetry'] = in_array( $telemetry, array( 'eager', 'lazy' ), true ) ? $telemetry : 'lazy';

		return $out;
	}

	/**
	 * Validate an aspect ratio of the form `W:H`; fall back to 16:9.
	 *
	 * @param string $ratio Raw aspect-ratio string.
	 */
	public static function sanitize_aspect_ratio( string $ratio ): string {
		$ratio = trim( $ratio );
		if ( preg_match( '/^\d{1,3}\s*:\s*\d{1,3}$/', $ratio ) ) {
			return str_replace( ' ', '', $ratio );
		}

		return '16:9';
	}
}
