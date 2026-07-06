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

	/**
	 * The request URL captured by the filter, for assertions.
	 *
	 * @var string
	 */
	private $captured_url = '';

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
		$this->captured_url  = $url;
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

	public function test_list_datasets_builds_query_and_uses_get(): void {
		$this->canned = $this->respond(
			200,
			(string) wp_json_encode(
				array(
					'datasets'    => array(),
					'next_cursor' => null,
				)
			)
		);

		$result = $this->client()->list_datasets(
			array(
				'status' => 'draft',
				'limit'  => '25',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'GET', $this->captured_args['method'] );
		$this->assertStringContainsString( 'status=draft', $this->captured_url );
		$this->assertStringContainsString( 'limit=25', $this->captured_url );
		// A bodyless GET must not declare a JSON content type (WordPress itself
		// injects a default empty `body` arg downstream, so assert on the header
		// this class actually controls rather than the presence of `body`).
		$this->assertArrayNotHasKey( 'Content-Type', $this->captured_args['headers'] );
	}

	public function test_create_dataset_posts_json_body(): void {
		$this->canned = $this->respond( 201, (string) wp_json_encode( array( 'dataset' => array( 'id' => 'NEW' ) ) ) );

		$result = $this->client()->create_dataset(
			array(
				'title'  => 'Hi',
				'format' => 'image/png',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 201, $result['status'] );
		$this->assertSame( 'NEW', $result['data']['dataset']['id'] );
		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertSame( 'application/json', $this->captured_args['headers']['Content-Type'] );
		$this->assertSame( 'Hi', json_decode( $this->captured_args['body'], true )['title'] );
		$this->assertSame( 'cid.access', $this->captured_args['headers']['Cf-Access-Client-Id'] );
	}

	public function test_validation_errors_pass_through(): void {
		$this->canned = $this->respond(
			400,
			(string) wp_json_encode(
				array(
					'errors' => array(
						array(
							'field'   => 'title',
							'code'    => 'too_short',
							'message' => 'min 3',
						),
					),
				)
			)
		);

		$result = $this->client()->create_dataset( array( 'title' => 'x' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'validation', $result['error'] );
		$this->assertSame( 'title', $result['errors'][0]['field'] );
	}

	public function test_update_uses_put_with_encoded_id(): void {
		$this->canned = $this->respond( 200, (string) wp_json_encode( array( 'dataset' => array( 'id' => 'D1' ) ) ) );

		$this->client()->update_dataset( 'D1', array( 'abstract' => 'text' ) );

		$this->assertSame( 'PUT', $this->captured_args['method'] );
		$this->assertStringContainsString( '/datasets/D1', $this->captured_url );
	}

	public function test_publish_and_retract_target_lifecycle_paths(): void {
		$this->canned = $this->respond( 200, (string) wp_json_encode( array( 'dataset' => array( 'id' => 'D1' ) ) ) );

		$this->client()->publish_dataset( 'D1' );
		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/datasets/D1/publish', $this->captured_url );
		// An empty lifecycle body must serialise to a JSON object, not `[]`.
		$this->assertSame( '{}', $this->captured_args['body'] );

		$this->client()->retract_dataset( 'D1' );
		$this->assertStringEndsWith( '/datasets/D1/retract', $this->captured_url );
	}

	public function test_delete_uses_delete_method(): void {
		$this->canned = $this->respond( 200, (string) wp_json_encode( array( 'deleted_id' => 'D1' ) ) );

		$result = $this->client()->delete_dataset( 'D1' );

		$this->assertSame( 'DELETE', $this->captured_args['method'] );
		$this->assertSame( 'D1', $result['data']['deleted_id'] );
	}

	public function test_id_segment_is_url_encoded(): void {
		$this->canned = $this->respond( 200, '{}' );

		$this->client()->get_dataset( 'has space/../x' );

		$this->assertStringContainsString( 'has%20space', $this->captured_url );
		$this->assertStringNotContainsString( '/../', $this->captured_url );
	}

	public function test_init_asset_posts_body_to_asset_path(): void {
		$this->canned = $this->respond(
			201,
			(string) wp_json_encode(
				array(
					'upload_id' => 'U1',
					'r2'        => array(
						'method'  => 'PUT',
						'url'     => 'https://r2.example/x',
						'headers' => array(),
						'key'     => 'k',
					),
					'mock'      => false,
				)
			)
		);

		$result = $this->client()->init_asset(
			'D1',
			array(
				'kind'           => 'data',
				'mime'           => 'image/png',
				'size'           => 123,
				'content_digest' => 'sha256:' . str_repeat( 'a', 64 ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'U1', $result['data']['upload_id'] );
		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/datasets/D1/asset', $this->captured_url );
		$this->assertSame( 'data', json_decode( $this->captured_args['body'], true )['kind'] );
	}

	public function test_complete_asset_targets_upload_path_with_object_body(): void {
		$this->canned = $this->respond(
			202,
			(string) wp_json_encode(
				array(
					'dataset'     => array( 'id' => 'D1' ),
					'transcoding' => true,
				)
			)
		);

		$result = $this->client()->complete_asset( 'D1', 'U1' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 202, $result['status'] );
		$this->assertTrue( $result['data']['transcoding'] );
		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/datasets/D1/asset/U1/complete', $this->captured_url );
		// The bodyless completion must serialise to `{}`, not `[]`.
		$this->assertSame( '{}', $this->captured_args['body'] );
	}

	public function test_create_blog_posts_to_collection(): void {
		$this->canned = $this->respond(
			201,
			(string) wp_json_encode(
				array(
					'post' => array(
						'id'   => 'B1',
						'slug' => 's',
					),
				)
			)
		);

		$result = $this->client()->create_blog(
			array(
				'title'  => 'T',
				'bodyMd' => 'body',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'B1', $result['data']['post']['id'] );
		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/publish/blog', $this->captured_url );
		$this->assertSame( 'T', json_decode( $this->captured_args['body'], true )['title'] );
	}

	public function test_update_blog_puts_to_item(): void {
		$this->canned = $this->respond( 200, (string) wp_json_encode( array( 'post' => array( 'id' => 'B1' ) ) ) );

		$this->client()->update_blog( 'B1', array( 'title' => 'T2' ) );

		$this->assertSame( 'PUT', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/publish/blog/B1', $this->captured_url );
	}

	public function test_set_blog_action_posts_action_body(): void {
		$this->canned = $this->respond( 200, (string) wp_json_encode( array( 'post' => array( 'id' => 'B1' ) ) ) );

		$this->client()->set_blog_action( 'B1', 'publish' );

		$this->assertSame( 'POST', $this->captured_args['method'] );
		$this->assertStringEndsWith( '/publish/blog/B1', $this->captured_url );
		$this->assertSame( 'publish', json_decode( $this->captured_args['body'], true )['action'] );
	}
}
