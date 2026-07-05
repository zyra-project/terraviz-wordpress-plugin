<?php
/**
 * Minimal HTTP client for Terraviz's public read API.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over the WordPress HTTP API for GETting Terraviz's public,
 * unauthenticated read endpoints.
 *
 * Uses only `wp_remote_get()` (never PHP's own cURL/streams) so the plugin
 * stays WordPress-VIP-review-clean, and never sends credentials — the entire
 * read path is public in Phase 1.
 */
final class Client implements JsonReader {

	/**
	 * Node origin (scheme+host, no trailing slash).
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Construct the client.
	 *
	 * @param string $origin  Node origin.
	 * @param int    $timeout Request timeout in seconds.
	 */
	public function __construct( string $origin, int $timeout = 5 ) {
		$this->origin  = untrailingslashit( $origin );
		$this->timeout = max( 1, $timeout );
	}

	/**
	 * The node origin this client targets.
	 */
	public function origin(): string {
		return $this->origin;
	}

	/**
	 * GET a JSON endpoint and return the decoded array.
	 *
	 * @param string               $path  API path beginning with '/'.
	 * @param array<string,scalar> $query Optional query args.
	 * @return array<string,mixed>|null Decoded body, or null on any failure.
	 */
	public function get_json( string $path, array $query = array() ): ?array {
		$url = $this->origin . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		/**
		 * Filter the request args for a Terraviz read-API call.
		 *
		 * @param array  $args Args passed to wp_remote_get().
		 * @param string $url  Fully composed request URL.
		 */
		$args = apply_filters(
			'terraviz_request_args',
			array(
				'timeout'     => $this->timeout,
				'redirection' => 2,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'TerravizWP/' . TERRAVIZ_VERSION . '; ' . home_url( '/' ),
				),
			),
			$url
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}
}
