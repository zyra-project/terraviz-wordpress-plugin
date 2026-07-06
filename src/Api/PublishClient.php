<?php
/**
 * Authenticated client for Terraviz's publish API — Phase 2 uses only the
 * read-only `me` probe.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal authenticated client over Terraviz's `/api/v1/publish/**` surface.
 *
 * Phase 2 exercises exactly one, read-only endpoint: `GET /api/v1/publish/me`,
 * the "who am I" probe used to validate a service token *before* any write
 * path exists. It attaches the Cloudflare Access service-token headers
 * (`Cf-Access-Client-Id` / `Cf-Access-Client-Secret`); Cloudflare's edge
 * exchanges them for a JWT before the node sees the request.
 *
 * Like {@see Client}, it uses only `wp_safe_remote_get()` with
 * `reject_unsafe_urls`, so it stays VIP-review-clean and refuses redirects
 * into private/reserved hosts.
 */
final class PublishClient {

	/**
	 * Node origin (scheme+host, no trailing slash).
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Authentication headers (the service-token pair).
	 *
	 * @var array<string,string>
	 */
	private $auth_headers;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Construct the client.
	 *
	 * @param string               $origin       Node origin.
	 * @param array<string,string> $auth_headers Cloudflare Access headers.
	 * @param int                  $timeout      Request timeout in seconds.
	 */
	public function __construct( string $origin, array $auth_headers, int $timeout = 8 ) {
		$this->origin       = untrailingslashit( $origin );
		$this->auth_headers = $auth_headers;
		$this->timeout      = max( 1, $timeout );
	}

	/**
	 * Call `GET /api/v1/publish/me` and return a normalised result.
	 *
	 * The result is a plain array rather than an exception so the settings
	 * screen can map each outcome to a friendly notice:
	 *
	 *   array{
	 *     ok:       bool,          // true only on a 200 with a JSON body
	 *     status:   int,           // HTTP status, or 0 on a transport error
	 *     profile:  array|null,    // decoded body on success
	 *     error:    string,        // node error slug, or a synthetic one
	 *     message:  string,        // node message, or a transport message
	 *   }
	 *
	 * @return array{ok:bool,status:int,profile:array<string,mixed>|null,error:string,message:string}
	 */
	public function me(): array {
		$url = $this->origin . '/api/v1/publish/me';

		/**
		 * Filter the request args for a Terraviz publish-API call.
		 *
		 * @param array  $args Args passed to wp_safe_remote_get().
		 * @param string $url  Fully composed request URL.
		 */
		$args = apply_filters(
			'terraviz_publish_request_args',
			array(
				'timeout'            => $this->timeout,
				'redirection'        => 2,
				'reject_unsafe_urls' => true,
				'headers'            => array_merge(
					array(
						'Accept'     => 'application/json',
						'User-Agent' => 'TerravizWP/' . TERRAVIZ_VERSION,
					),
					$this->auth_headers
				),
			),
			$url
		);

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'profile' => null,
				'error'   => 'transport',
				'message' => $response->get_error_message(),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = ( '' !== $body ) ? json_decode( $body, true ) : null;
		$data    = is_array( $decoded ) ? $decoded : array();

		if ( $code >= 200 && $code < 300 ) {
			// A 2xx with an empty or non-JSON body is not a valid `me` response
			// (e.g. an intercepting proxy or a login/HTML page). Treat it as a
			// failure rather than reporting a false-positive "credential valid",
			// matching Api\Client::get_json's handling of the read path.
			if ( ! is_array( $decoded ) ) {
				return array(
					'ok'      => false,
					'status'  => $code,
					'profile' => null,
					'error'   => 'invalid_response',
					'message' => __( 'The node returned a non-JSON response to the identity probe.', 'terraviz' ),
				);
			}

			return array(
				'ok'      => true,
				'status'  => $code,
				'profile' => $data,
				'error'   => '',
				'message' => '',
			);
		}//end if

		return array(
			'ok'      => false,
			'status'  => $code,
			'profile' => null,
			'error'   => isset( $data['error'] ) ? (string) $data['error'] : 'http_' . $code,
			'message' => isset( $data['message'] ) ? (string) $data['message'] : '',
		);
	}
}
