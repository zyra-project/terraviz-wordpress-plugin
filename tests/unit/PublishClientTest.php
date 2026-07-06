<?php
/**
 * Tests for the authenticated publish-API probe client.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\PublishClient;

/**
 * @covers \Terraviz\Api\PublishClient
 */
class PublishClientTest extends WP_UnitTestCase {

	/**
	 * The canned HTTP response the pre_http_request filter returns.
	 *
	 * @var array|\WP_Error
	 */
	private $canned;

	/**
	 * The request args captured by the filter, for assertions.
	 *
	 * @var array<string,mixed>
	 */
	private $captured_args = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'short_circuit' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'short_circuit' ), 10 );
		parent::tear_down();
	}

	/**
	 * Short-circuit wp_safe_remote_get with the canned response.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return mixed
	 */
	public function short_circuit( $pre, $args, $url ) {
		$this->captured_args = $args;
		return $this->canned;
	}

	private function client(): PublishClient {
		return new PublishClient(
			'https://node.example',
			array(
				'Cf-Access-Client-Id'     => 'cid.access',
				'Cf-Access-Client-Secret' => 'sekret',
			)
		);
	}

	private function respond( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_success_returns_profile(): void {
		$this->canned = $this->respond(
			200,
			(string) wp_json_encode(
				array(
					'id'     => 'pub_1',
					'role'   => 'service',
					'status' => 'active',
					'email'  => 'cid.access@service.local',
				)
			)
		);

		$result = $this->client()->me();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'service', $result['profile']['role'] );
	}

	public function test_sends_service_token_headers(): void {
		$this->canned = $this->respond( 200, '{}' );
		$this->client()->me();

		$this->assertSame( 'cid.access', $this->captured_args['headers']['Cf-Access-Client-Id'] );
		$this->assertSame( 'sekret', $this->captured_args['headers']['Cf-Access-Client-Secret'] );
	}

	public function test_maps_401_error_slug(): void {
		$this->canned = $this->respond(
			401,
			(string) wp_json_encode(
				array(
					'error'   => 'unauthenticated',
					'message' => 'Invalid or expired Access assertion.',
				)
			)
		);

		$result = $this->client()->me();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'unauthenticated', $result['error'] );
	}

	public function test_maps_403_pending(): void {
		$this->canned = $this->respond(
			403,
			(string) wp_json_encode(
				array(
					'error'   => 'pending',
					'message' => 'awaiting approval',
				)
			)
		);

		$this->assertSame( 'pending', $this->client()->me()['error'] );
	}

	public function test_transport_error(): void {
		$this->canned = new WP_Error( 'http_request_failed', 'could not resolve host' );

		$result = $this->client()->me();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertSame( 'transport', $result['error'] );
	}

	public function test_non_2xx_without_body_synthesizes_error(): void {
		$this->canned = $this->respond( 500, '' );
		$this->assertSame( 'http_500', $this->client()->me()['error'] );
	}

	public function test_2xx_with_non_json_body_is_not_success(): void {
		$this->canned = $this->respond( 200, '<!doctype html><title>Login</title>' );

		$result = $this->client()->me();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_response', $result['error'] );
	}

	public function test_2xx_with_empty_body_is_not_success(): void {
		$this->canned = $this->respond( 200, '' );

		$result = $this->client()->me();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_response', $result['error'] );
	}
}
