<?php
/**
 * Tests for the publisher dashboard REST proxy.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Rest\PublisherController;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;

/**
 * @covers \Terraviz\Rest\PublisherController
 */
class PublisherControllerTest extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var PublisherController
	 */
	private $controller;

	/**
	 * Canned HTTP responses keyed by method, for the proxy-gate tests.
	 *
	 * @var array<string,mixed>
	 */
	private $http_by_method = array();

	/**
	 * HTTP methods the proxy actually sent, in order.
	 *
	 * @var array<int,string>
	 */
	private $sent_methods = array();

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant();
		$this->controller = new PublisherController();
	}

	/**
	 * Short-circuit the proxy's outbound HTTP, recording the method and
	 * returning a canned per-method response.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return mixed
	 */
	public function intercept_http( $pre, $args, $url ) {
		$method               = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$this->sent_methods[] = $method;

		return $this->http_by_method[ $method ] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);
	}

	private function configure_credential(): void {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'No crypto backend to store a service token.' );
		}
		Credential::save( 'cid.access', 'sekret' );
	}

	private function dataset_response( array $dataset ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'dataset' => $dataset ) ),
		);
	}

	private function put_request( string $id, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'PUT' );
		$request->set_param( 'id', $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	public function test_normalize_keeps_allowlisted_fields_and_coerces_types(): void {
		$out = $this->controller->normalize_dataset_body(
			array(
				'title'           => 'My Dataset',
				'radius_mi'       => '6371',
				'is_hidden'       => 1,
				'is_flipped_in_y' => null,
				'keywords'        => array( 'a', 'b', 5, array( 'nested' ) ),
				'bounding_box'    => array(
					'n'    => 90,
					's'    => '-45.5',
					'w'    => -180,
					'e'    => 180,
					'junk' => 'x',
				),
				'categories'      => array(
					'theme' => array( 'ocean' ),
					'bad'   => 'not-array',
				),
			)
		);

		$this->assertSame( 'My Dataset', $out['title'] );
		$this->assertSame( 6371, $out['radius_mi'] );
		$this->assertTrue( $out['is_hidden'] );
		$this->assertArrayHasKey( 'is_flipped_in_y', $out );
		$this->assertNull( $out['is_flipped_in_y'] );
		$this->assertSame( array( 'a', 'b', '5' ), $out['keywords'] );
		$this->assertSame(
			array(
				'n' => 90,
				's' => -45.5,
				'w' => -180,
				'e' => 180,
			),
			$out['bounding_box']
		);
		$this->assertSame( array( 'ocean' ), $out['categories']['theme'] );
		$this->assertArrayNotHasKey( 'bad', $out['categories'] );
	}

	public function test_normalize_drops_unknown_and_server_managed_fields(): void {
		$out = $this->controller->normalize_dataset_body(
			array(
				'title'        => 'Keep',
				'evil'         => 'DROP TABLE',
				'transcoding'  => true,
				'published_at' => '2026-01-01',
			)
		);

		$this->assertSame( array( 'title' => 'Keep' ), $out );
	}

	public function test_normalize_parses_stringy_booleans(): void {
		$out = $this->controller->normalize_dataset_body(
			array(
				'is_hidden'        => 'false',
				'run_tour_on_load' => '0',
				'is_flipped_in_y'  => 'true',
			)
		);

		$this->assertFalse( $out['is_hidden'] );
		$this->assertFalse( $out['run_tour_on_load'] );
		$this->assertTrue( $out['is_flipped_in_y'] );
	}

	public function test_normalize_preserves_multiline_free_text(): void {
		$out = $this->controller->normalize_dataset_body(
			array( 'abstract' => "Line one\nLine two <b>kept</b>" )
		);

		$this->assertSame( "Line one\nLine two <b>kept</b>", $out['abstract'] );
	}

	public function test_permission_tiers_follow_capabilities(): void {
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->require_draft() );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->require_draft() );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $author );
		$this->assertTrue( $this->controller->require_draft() );
		$this->assertFalse( $this->controller->require_publish() );

		wp_set_current_user( $contributor );
		$this->assertFalse( $this->controller->require_draft() );
		$this->assertFalse( $this->controller->require_publish() );
	}

	public function test_draft_tier_cannot_edit_published_dataset(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// The state fetch reports a published (non-retracted) dataset.
		$this->http_by_method['GET'] = $this->dataset_response(
			array(
				'id'           => 'D1',
				'published_at' => '2026-01-01T00:00:00Z',
				'retracted_at' => null,
			)
		);

		$response = $this->controller->update_dataset( $this->put_request( 'D1', array( 'title' => 'Hijacked' ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'forbidden_published', $response->get_data()['error'] );
		// The write must never be forwarded.
		$this->assertNotContains( 'PUT', $this->sent_methods );
	}

	public function test_draft_tier_can_edit_a_draft(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = $this->dataset_response(
			array(
				'id'           => 'D1',
				'published_at' => null,
				'retracted_at' => null,
			)
		);
		$this->http_by_method['PUT'] = $this->dataset_response(
			array(
				'id'    => 'D1',
				'title' => 'New',
			)
		);

		$response = $this->controller->update_dataset( $this->put_request( 'D1', array( 'title' => 'New' ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'PUT', $this->sent_methods );
	}

	public function test_normalize_asset_init_allowlists_fields(): void {
		$out = $this->controller->normalize_asset_init(
			array(
				'kind'           => 'data',
				'mime'           => 'video/mp4',
				'size'           => '456',
				'content_digest' => 'sha256:' . str_repeat( 'a', 64 ),
				'evil'           => 'DROP',
				'target'         => 'stream',
			)
		);

		$this->assertSame(
			array(
				'kind'           => 'data',
				'mime'           => 'video/mp4',
				'size'           => 456,
				'content_digest' => 'sha256:' . str_repeat( 'a', 64 ),
			),
			$out
		);
	}

	public function test_draft_tier_cannot_upload_to_published_dataset(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = $this->dataset_response(
			array(
				'id'           => 'D1',
				'published_at' => '2026-01-01T00:00:00Z',
				'retracted_at' => null,
			)
		);

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'D1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'kind'           => 'data',
					'mime'           => 'image/png',
					'size'           => 1,
					'content_digest' => 'sha256:' . str_repeat( 'a', 64 ),
				)
			)
		);

		$response = $this->controller->init_asset( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'forbidden_published', $response->get_data()['error'] );
		// The init must never be forwarded (only the state GET happened).
		$this->assertNotContains( 'POST', $this->sent_methods );
	}

	public function test_publish_tier_edits_published_without_precheck(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['PUT'] = $this->dataset_response(
			array(
				'id'    => 'D1',
				'title' => 'Edited',
			)
		);

		$response = $this->controller->update_dataset( $this->put_request( 'D1', array( 'title' => 'Edited' ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		// A publish-tier user skips the state fetch entirely.
		$this->assertNotContains( 'GET', $this->sent_methods );
		$this->assertContains( 'PUT', $this->sent_methods );
	}
}
