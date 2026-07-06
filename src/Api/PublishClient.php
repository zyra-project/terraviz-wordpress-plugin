<?php
/**
 * Authenticated server-side client for Terraviz's publish API.
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
 * This is the server-side publish proxy's transport: it attaches the
 * Cloudflare Access service-token headers (`Cf-Access-Client-Id` /
 * `Cf-Access-Client-Secret`) — which Cloudflare's edge exchanges for a JWT
 * before the node sees the request — so **the token never reaches the
 * browser**. Every method runs in PHP on behalf of an authorised wp-admin
 * user (see `Rest\PublisherController`).
 *
 * Like {@see Client}, it uses only the `wp_safe_remote_*` family with
 * `reject_unsafe_urls`, so it stays VIP-review-clean and refuses redirects
 * into private/reserved hosts.
 *
 * Every method returns the same normalised result array so callers never have
 * to parse HTTP by hand:
 *
 *   array{
 *     ok:      bool,                  // true on a 2xx with a JSON body
 *     status:  int,                   // HTTP status, or 0 on a transport error
 *     data:    array<string,mixed>,   // decoded response body ({} when none)
 *     error:   string,                // '' on success; a node/synthetic slug otherwise
 *     message: string,                // node/transport message
 *     errors:  array<int,mixed>,      // field-validation errors [{field,code,message}]
 *   }
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
	public function __construct( string $origin, array $auth_headers, int $timeout = 10 ) {
		$this->origin       = untrailingslashit( $origin );
		$this->auth_headers = $auth_headers;
		$this->timeout      = max( 1, $timeout );
	}

	/**
	 * `GET /api/v1/publish/me` — validate the token and return the publisher
	 * profile. Kept in its original shape (`profile`, not `data`) for the
	 * settings-screen "Verify credential" probe.
	 *
	 * @return array{ok:bool,status:int,profile:array<string,mixed>|null,error:string,message:string}
	 */
	public function me(): array {
		$result = $this->send( 'GET', '/api/v1/publish/me' );

		return array(
			'ok'      => $result['ok'],
			'status'  => $result['status'],
			'profile' => $result['ok'] ? $result['data'] : null,
			'error'   => $result['error'],
			'message' => $result['message'],
		);
	}

	/**
	 * `GET /api/v1/publish/datasets` — list the caller's datasets.
	 *
	 * @param array<string,scalar> $query Optional `status`, `cursor`, `limit`.
	 * @return array<string,mixed>
	 */
	public function list_datasets( array $query = array() ): array {
		$path  = '/api/v1/publish/datasets';
		$clean = array();
		foreach ( array( 'status', 'cursor', 'limit' ) as $key ) {
			if ( isset( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
				$clean[ $key ] = (string) $query[ $key ];
			}
		}
		if ( ! empty( $clean ) ) {
			// Single-encode: add_query_arg() only urlencode_deep()s params already
			// present in the base URL (there are none here) and emits newly-added
			// args verbatim via build_query( urlencode: false ), so the
			// rawurlencode() is the one and only encoding — required so a cursor
			// containing '+' or '/' round-trips correctly.
			$path = add_query_arg( array_map( 'rawurlencode', $clean ), $path );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/datasets/:id` — fetch one dataset.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function get_dataset( string $id ): array {
		return $this->send( 'GET', '/api/v1/publish/datasets/' . rawurlencode( $id ) );
	}

	/**
	 * `POST /api/v1/publish/datasets` — create a draft.
	 *
	 * @param array<string,mixed> $body Dataset draft fields.
	 * @return array<string,mixed>
	 */
	public function create_dataset( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets', $body );
	}

	/**
	 * `PUT /api/v1/publish/datasets/:id` — partial update.
	 *
	 * @param string              $id   Dataset id.
	 * @param array<string,mixed> $body Fields to change.
	 * @return array<string,mixed>
	 */
	public function update_dataset( string $id, array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/datasets/' . rawurlencode( $id ), $body );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/publish` — publish a draft.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function publish_dataset( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets/' . rawurlencode( $id ) . '/publish', array() );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/retract` — retract a published dataset.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function retract_dataset( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets/' . rawurlencode( $id ) . '/retract', array() );
	}

	/**
	 * `DELETE /api/v1/publish/datasets/:id` — hard-delete a draft/retracted row.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function delete_dataset( string $id ): array {
		return $this->send( 'DELETE', '/api/v1/publish/datasets/' . rawurlencode( $id ) );
	}

	/**
	 * Perform a request and normalise the response.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path (with any query string) beginning with '/'.
	 * @param array<string,mixed>|null $body   JSON body to send, or null for none.
	 * @return array{ok:bool,status:int,data:array<string,mixed>,error:string,message:string,errors:array<int,mixed>}
	 */
	private function send( string $method, string $path, ?array $body = null ): array {
		$url = $this->origin . $path;

		$headers = array_merge(
			array(
				'Accept'     => 'application/json',
				'User-Agent' => 'TerravizWP/' . TERRAVIZ_VERSION,
			),
			$this->auth_headers
		);

		$args = array(
			'method'             => $method,
			'timeout'            => $this->timeout,
			'redirection'        => 2,
			'reject_unsafe_urls' => true,
			'headers'            => $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			// Cast to object so an empty body serialises to `{}` (a JSON object,
			// which the publish/retract routes expect) rather than `[]`.
			// Non-empty associative payloads and nested lists are unaffected.
			$args['body'] = wp_json_encode( (object) $body );
		}

		/**
		 * Filter the request args for a Terraviz publish-API call.
		 *
		 * @param array  $args Args passed to wp_safe_remote_request().
		 * @param string $url  Fully composed request URL.
		 */
		$args = apply_filters( 'terraviz_publish_request_args', $args, $url );

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $this->result( false, 0, array(), 'transport', $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = ( '' !== $raw ) ? json_decode( $raw, true ) : null;
		$data    = is_array( $decoded ) ? $decoded : array();
		$errors  = ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) ? $data['errors'] : array();

		if ( $code >= 200 && $code < 300 ) {
			// Every publish endpoint returns a JSON object on success. A 2xx with
			// an empty or non-JSON body means an intercepting proxy / login page,
			// not the API — treat it as a failure rather than a false success.
			if ( ! is_array( $decoded ) ) {
				return $this->result( false, $code, array(), 'invalid_response', __( 'The node returned a non-JSON response.', 'terraviz' ) );
			}

			return $this->result( true, $code, $data, '', '' );
		}

		$slug = '';
		if ( isset( $data['error'] ) ) {
			$slug = (string) $data['error'];
		} elseif ( ! empty( $errors ) ) {
			$slug = 'validation';
		} else {
			$slug = 'http_' . $code;
		}

		$message = isset( $data['message'] ) ? (string) $data['message'] : '';

		return $this->result( false, $code, $data, $slug, $message, $errors );
	}

	/**
	 * Assemble a normalised result array.
	 *
	 * @param bool                $ok      Whether the call succeeded.
	 * @param int                 $status  HTTP status (0 on transport error).
	 * @param array<string,mixed> $data    Decoded body.
	 * @param string              $error   Error slug ('' on success).
	 * @param string              $message Human message.
	 * @param array<int,mixed>    $errors  Field-validation errors.
	 * @return array{ok:bool,status:int,data:array<string,mixed>,error:string,message:string,errors:array<int,mixed>}
	 */
	private function result( bool $ok, int $status, array $data, string $error, string $message, array $errors = array() ): array {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'data'    => $data,
			'error'   => $error,
			'message' => $message,
			'errors'  => $errors,
		);
	}
}
