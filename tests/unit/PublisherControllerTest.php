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

	/**
	 * Outbound URLs the proxy actually sent, in order.
	 *
	 * @var array<int,string>
	 */
	private $sent_urls = array();

	/**
	 * Outbound request bodies the proxy actually sent, in order.
	 *
	 * @var array<int,string>
	 */
	private $sent_bodies = array();

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
		$this->sent_urls[]    = (string) $url;
		$this->sent_bodies[]  = isset( $args['body'] ) ? (string) $args['body'] : '';

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

	public function test_normalize_asset_init_rejects_bad_size(): void {
		$this->assertArrayNotHasKey( 'size', $this->controller->normalize_asset_init( array( 'size' => '1.5' ) ) );
		$this->assertArrayNotHasKey( 'size', $this->controller->normalize_asset_init( array( 'size' => -5 ) ) );
		$this->assertSame( 123, $this->controller->normalize_asset_init( array( 'size' => '123' ) )['size'] );
		$this->assertSame( 0, $this->controller->normalize_asset_init( array( 'size' => 0 ) )['size'] );
	}

	public function test_guard_fails_closed_when_state_fetch_errors(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// The state fetch fails with a node error (not a 404).
		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{}',
		);

		$response = $this->controller->update_dataset( $this->put_request( 'D1', array( 'title' => 'x' ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'state_unverified', $response->get_data()['error'] );
		$this->assertNotContains( 'PUT', $this->sent_methods );
	}

	public function test_guard_forwards_when_state_fetch_is_404(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// A plain 404 is harmless to forward; the write returns the node's 404.
		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => (string) wp_json_encode(
				array(
					'error'   => 'not_found',
					'message' => 'nope',
				)
			),
		);
		$this->http_by_method['PUT'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => (string) wp_json_encode(
				array(
					'error'   => 'not_found',
					'message' => 'nope',
				)
			),
		);

		$response = $this->controller->update_dataset( $this->put_request( 'D1', array( 'title' => 'x' ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertContains( 'PUT', $this->sent_methods );
		$this->assertSame( 404, $response->get_status() );
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

	private function event_request( string $id, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	public function test_list_events_forwards_get_with_status(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'events' => array( array( 'id' => 'EV1' ) ) ) ),
		);

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'status', 'approved' );
		$response = $this->controller->list_events( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'GET', $this->sent_methods );
		$this->assertStringContainsString( '/api/v1/publish/events?status=approved', end( $this->sent_urls ) );
	}

	public function test_list_events_omits_empty_status(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'events' => array() ) ),
		);

		$response = $this->controller->list_events( new WP_REST_Request( 'GET' ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/events', end( $this->sent_urls ) );
	}

	public function test_review_event_forwards_post_with_normalized_body(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'event' => array( 'id' => 'EV1' ) ) ),
		);

		$request  = $this->event_request(
			'EV1',
			array(
				'event'         => 'approve',
				'addDatasetIds' => array( 'D1', 'D2', array( 'nope' ) ),
				'links'         => array(
					array(
						'datasetId' => 'D3',
						'decision'  => 'reject',
					),
					array( 'datasetId' => 'D4' ), // Missing decision — dropped.
				),
				'edits'         => array(
					'occurredStart' => '2026-01-02',
					'regionName'    => 'north-atlantic',
					'point'         => array(
						'lat' => '40.5',
						'lon' => '-70',
					),
					'imageAlt'      => null,
					'evil'          => 'DROP',
				),
				'garbage'       => true,
			)
		);
		$response = $this->controller->review_event( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'POST', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/events/EV1', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame(
			array(
				'event'         => 'approve',
				'addDatasetIds' => array( 'D1', 'D2' ),
				'links'         => array(
					array(
						'datasetId' => 'D3',
						'decision'  => 'reject',
					),
				),
				'edits'         => array(
					'occurredStart' => '2026-01-02',
					'regionName'    => 'north-atlantic',
					'imageAlt'      => null,
					'point'         => array(
						'lat' => 40.5,
						'lon' => -70,
					),
				),
			),
			$sent
		);
	}

	public function test_normalize_event_review_body_drops_unknown_and_bad_shapes(): void {
		$out = $this->controller->normalize_event_review_body(
			array(
				'event'         => 'maybe', // Not in the enum — dropped.
				'addDatasetIds' => 'D1', // Not a list — dropped.
				'links'         => array( 'not-an-object' ),
				'edits'         => array(
					'videoEmbedUrl' => 'https://youtube-nocookie.com/x',
					'point'         => array( 'lat' => 40.5 ), // Missing lon — dropped.
					'unknown'       => 'x',
				),
				'stray'         => 1,
			)
		);

		$this->assertSame(
			array( 'edits' => array( 'videoEmbedUrl' => 'https://youtube-nocookie.com/x' ) ),
			$out
		);
	}

	public function test_normalize_event_edits_handles_nullable_fields(): void {
		// null clears; a scalar sets; a non-scalar is dropped (never "Array").
		$out = $this->controller->normalize_event_review_body(
			array(
				'edits' => array(
					'imageAlt'      => null,
					'videoEmbedUrl' => array( 'not', 'a', 'string' ),
					'imageUrl'      => 'https://x/y.png',
				),
			)
		);

		$this->assertArrayHasKey( 'imageAlt', $out['edits'] );
		$this->assertNull( $out['edits']['imageAlt'] );
		$this->assertArrayNotHasKey( 'videoEmbedUrl', $out['edits'] );
		$this->assertSame( 'https://x/y.png', $out['edits']['imageUrl'] );
	}

	public function test_events_routes_require_publish_tier(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $author );
		$this->assertFalse( $this->controller->require_publish() );
	}

	public function test_list_events_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->list_events( new WP_REST_Request( 'GET' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_review_event_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->review_event( $this->event_request( 'EV1', array( 'event' => 'approve' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}
}
