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

	private function feed_request( array $body, string $id = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		if ( '' !== $id ) {
			$request->set_param( 'id', $id );
		}
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	public function test_feeds_routes_require_configure_tier(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->require_configure() );

		// A publish-tier editor is NOT enough — feeds are configure-only.
		wp_set_current_user( $editor );
		$this->assertFalse( $this->controller->require_configure() );

		wp_set_current_user( $author );
		$this->assertFalse( $this->controller->require_configure() );
	}

	public function test_list_feeds_forwards_get(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'feeds' => array() ) ),
		);

		$response = $this->controller->list_feeds();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'GET', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/feeds', end( $this->sent_urls ) );
	}

	public function test_create_feed_forwards_post_with_normalized_body(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 201 ),
			'body'     => (string) wp_json_encode( array( 'feed' => array( 'id' => 'F1' ) ) ),
		);

		$response = $this->controller->create_feed(
			$this->feed_request(
				array(
					'kind'    => 'rss',
					'label'   => '  Quakes  ',
					'url'     => 'https://example.com/feed.xml',
					'enabled' => 'false', // Stringy — coerced to a real bool.
					'id'      => 'INJECTED', // Server-owned — dropped.
					'evil'    => 'DROP',
				)
			)
		);

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertContains( 'POST', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/feeds', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame(
			array(
				'kind'    => 'rss',
				'label'   => 'Quakes',
				'url'     => 'https://example.com/feed.xml',
				'enabled' => false,
			),
			$sent
		);
	}

	public function test_update_feed_drops_kind_and_forwards_post(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'feed' => array( 'id' => 'F1' ) ) ),
		);

		$response = $this->controller->update_feed(
			$this->feed_request(
				array(
					'kind'     => 'eonet', // Immutable — must be dropped on patch.
					'label'    => 'Renamed',
					'category' => null,     // null clears.
				),
				'F1'
			)
		);

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/feeds/F1', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertArrayNotHasKey( 'kind', $sent );
		$this->assertSame( 'Renamed', $sent['label'] );
		$this->assertArrayHasKey( 'category', $sent );
		$this->assertNull( $sent['category'] );
	}

	public function test_delete_feed_forwards_delete(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['DELETE'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'deleted' => true ) ),
		);

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', 'F1' );
		$response = $this->controller->delete_feed( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'DELETE', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/feeds/F1', end( $this->sent_urls ) );
	}

	public function test_preview_feed_forwards_get_with_query(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'fetched'  => 3,
					'mappable' => 2,
					'items'    => array(),
				)
			),
		);

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'kind', 'rss' );
		$request->set_param( 'url', 'https://example.com/feed.xml' );
		$response = $this->controller->preview_feed( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$url = end( $this->sent_urls );
		$this->assertStringContainsString( '/api/v1/publish/feeds/preview?', $url );
		$this->assertStringContainsString( 'kind=rss', $url );
		$this->assertStringContainsString( 'url=https', $url );
	}

	public function test_normalize_feed_body_allowlists_and_coerces(): void {
		// Create keeps kind; a non-scalar category is dropped; unknown keys go.
		$create = $this->controller->normalize_feed_body(
			array(
				'kind'     => 'rss',
				'label'    => '  Feed  ',
				'category' => array( 'not', 'scalar' ),
				'enabled'  => 1,
				'junk'     => 'x',
			),
			true
		);
		$this->assertSame(
			array(
				'kind'    => 'rss',
				'label'   => 'Feed',
				'enabled' => true,
			),
			$create
		);

		// An unknown kind on create is dropped (node stays the validator).
		$bad_kind = $this->controller->normalize_feed_body( array( 'kind' => 'atom' ), true );
		$this->assertArrayNotHasKey( 'kind', $bad_kind );

		// Patch drops kind even when present, and preserves an explicit null category.
		$patch = $this->controller->normalize_feed_body(
			array(
				'kind'     => 'eonet',
				'category' => null,
			),
			false
		);
		$this->assertSame( array( 'category' => null ), $patch );
	}

	public function test_list_feeds_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->controller->list_feeds();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_get_featured_hero_forwards_get_to_public_path(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'hero' => null ) ),
		);

		$response = $this->controller->get_featured_hero();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'GET', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/featured-hero', end( $this->sent_urls ) );
	}

	public function test_set_featured_hero_forwards_put_with_normalized_body(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['PUT'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'hero' => array( 'datasetId' => 'D1' ) ) ),
		);

		$request  = $this->put_request(
			'',
			array(
				'dataset_id' => 'D1',
				'window'     => array(
					'start' => '2026-07-01T00:00:00.000Z',
					'end'   => '2026-07-08T00:00:00.000Z',
					'junk'  => 'x',
				),
				'headline'   => 'Aurora tonight',
				'evil'       => 'DROP',
			)
		);
		$response = $this->controller->set_featured_hero( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/featured-hero', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame(
			array(
				'dataset_id' => 'D1',
				'window'     => array(
					'start' => '2026-07-01T00:00:00.000Z',
					'end'   => '2026-07-08T00:00:00.000Z',
				),
				'headline'   => 'Aurora tonight',
			),
			$sent
		);
	}

	public function test_clear_featured_hero_forwards_delete_and_preserves_204(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['DELETE'] = array(
			'response' => array( 'code' => 204 ),
			'body'     => '',
		);

		$response = $this->controller->clear_featured_hero();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 204, $response->get_status() );
		$this->assertContains( 'DELETE', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/featured-hero', end( $this->sent_urls ) );
	}

	public function test_normalize_hero_body_allowlists_and_coerces(): void {
		// Keeps dataset_id + a whole window; drops unknown window/top-level keys.
		$out = $this->controller->normalize_hero_body(
			array(
				'dataset_id' => 'D1',
				'window'     => array(
					'start' => '2026-07-01T00:00:00Z',
					'end'   => '2026-07-08T00:00:00Z',
					'junk'  => 'x',
				),
				'headline'   => 'Hi',
				'set_by'     => 'INJECTED',
			)
		);
		$this->assertSame(
			array(
				'dataset_id' => 'D1',
				'window'     => array(
					'start' => '2026-07-01T00:00:00Z',
					'end'   => '2026-07-08T00:00:00Z',
				),
				'headline'   => 'Hi',
			),
			$out
		);

		// A null headline is preserved (clears); a non-scalar headline is dropped.
		$this->assertNull( $this->controller->normalize_hero_body( array( 'headline' => null ) )['headline'] );
		$this->assertArrayNotHasKey(
			'headline',
			$this->controller->normalize_hero_body( array( 'headline' => array( 'a' ) ) )
		);

		// A window object with no usable start/end is dropped entirely.
		$this->assertArrayNotHasKey(
			'window',
			$this->controller->normalize_hero_body( array( 'window' => array( 'junk' => 1 ) ) )
		);
	}

	public function test_hero_routes_require_publish_tier(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		// The hero routes all gate on the publish tier.
		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $author );
		$this->assertFalse( $this->controller->require_publish() );
	}

	public function test_set_featured_hero_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->set_featured_hero( $this->put_request( '', array( 'dataset_id' => 'D1' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_generate_event_tour_forwards_post_to_tour_path(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 201 ),
			'body'     => (string) wp_json_encode( array( 'tour' => array( 'id' => 'T1' ) ) ),
		);

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'EV1' );
		$response = $this->controller->generate_event_tour( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertContains( 'POST', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/events/EV1/tour', end( $this->sent_urls ) );
	}

	public function test_generate_event_tour_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'EV1' );
		$response = $this->controller->generate_event_tour( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_media_channels_require_configure_tier(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Media channels reuse the configure gate (the node restricts them to
		// admin/service), so a publish-tier editor is not enough.
		wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->require_configure() );

		wp_set_current_user( $editor );
		$this->assertFalse( $this->controller->require_configure() );
	}

	public function test_list_media_channels_forwards_get(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'channels' => array() ) ),
		);

		$response = $this->controller->list_media_channels();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/media/youtube-channels', end( $this->sent_urls ) );
	}

	public function test_create_media_channel_forwards_normalized_url(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 201 ),
			'body'     => (string) wp_json_encode( array( 'channel' => array( 'channelId' => 'UC1' ) ) ),
		);

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'url'  => '  https://youtube.com/@nasa  ',
					'evil' => 'DROP',
				)
			)
		);
		$response = $this->controller->create_media_channel( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 201, $response->get_status() );
		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame( array( 'url' => 'https://youtube.com/@nasa' ), $sent );
	}

	public function test_delete_media_channel_forwards_delete(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['DELETE'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'removed' => true ) ),
		);

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', 'UC1' );
		$response = $this->controller->delete_media_channel( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'DELETE', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/media/youtube-channels/UC1', end( $this->sent_urls ) );
	}

	public function test_list_media_channels_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->controller->list_media_channels();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_normalize_media_channel_body_allowlists_url(): void {
		$this->assertSame(
			array( 'url' => 'https://youtube.com/@x' ),
			$this->controller->normalize_media_channel_body(
				array(
					'url'  => '  https://youtube.com/@x  ',
					'junk' => 1,
				)
			)
		);
		$this->assertSame( array(), $this->controller->normalize_media_channel_body( array( 'url' => array( 'a' ) ) ) );
	}

	public function test_blog_route_requires_publish_tier(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $author );
		$this->assertFalse( $this->controller->require_publish() );
	}

	public function test_list_blog_forwards_get_with_status(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'posts' => array( array( 'id' => 'B1' ) ) ) ),
		);

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'status', 'draft' );
		$response = $this->controller->list_blog( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '/api/v1/publish/blog?status=draft', end( $this->sent_urls ) );
	}

	public function test_list_blog_decorates_wp_edit_url_for_linked_post(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// A WP post linked to node blog post B1 via the sync's id meta.
		$wp_id = self::factory()->post->create(
			array(
				'post_title'  => 'Linked',
				'post_status' => 'publish',
				'post_author' => $editor,
			)
		);
		update_post_meta( $wp_id, \Terraviz\Blog\Sync::ID_META, 'B1' );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'posts' => array(
						array(
							'id'   => 'B1',
							'slug' => 'linked',
						),
						array(
							'id'   => 'B2',
							'slug' => 'unlinked',
						),
					),
				)
			),
		);

		$response = $this->controller->list_blog( new WP_REST_Request( 'GET' ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$posts = $response->get_data()['posts'];
		// The linked post carries an editor URL pointing at its WP post; the
		// unlinked one is explicitly null.
		$this->assertNotEmpty( $posts[0]['wp_edit_url'] );
		$this->assertStringContainsString( (string) $wp_id, $posts[0]['wp_edit_url'] );
		$this->assertNull( $posts[1]['wp_edit_url'] );
	}

	public function test_list_blog_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->list_blog( new WP_REST_Request( 'GET' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	private function import_request( string $id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $id );
		return $request;
	}

	public function test_import_blog_to_wp_creates_linked_draft(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// The node returns the post to seed from (get_blog).
		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'post' => array(
						'id'         => 'B1',
						'slug'       => 'a-story',
						'title'      => 'A Story',
						'bodyMd'     => "Lead paragraph.\n\n[Read more](https://example.com/x)",
						'datasetIds' => array( 'sea-surface-temp' ),
					),
				)
			),
		);

		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 201, $response->get_status() );
		$data  = $response->get_data();
		$wp_id = (int) $data['wpId'];
		$this->assertGreaterThan( 0, $wp_id );
		$this->assertNotEmpty( $data['editUrl'] );

		$post = get_post( $wp_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'A Story', $post->post_title );
		$this->assertSame( (int) $editor, (int) $post->post_author );
		// Seeded as real Gutenberg blocks (not one Classic block)…
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
		// …with the escaped link inside the paragraph…
		$this->assertStringContainsString( '<a href="https://example.com/x">Read more</a>', $post->post_content );
		// …and a Terraviz dataset embed for the linked grounding.
		$this->assertStringContainsString( '<!-- wp:terraviz/dataset {"id":"sea-surface-temp"} /-->', $post->post_content );
		// Link meta wires the two together for the existing sync.
		$this->assertSame( 'B1', get_post_meta( $wp_id, \Terraviz\Blog\Sync::ID_META, true ) );
		$this->assertSame( 'a-story', get_post_meta( $wp_id, \Terraviz\Blog\Sync::SLUG_META, true ) );
		$this->assertTrue( (bool) get_post_meta( $wp_id, \Terraviz\Blog\Sync::OPTIN_META, true ) );
	}

	public function test_import_blog_to_wp_is_idempotent_for_linked_post(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// An existing WP post already linked to node post B1.
		$wp_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $editor,
			)
		);
		update_post_meta( $wp_id, \Terraviz\Blog\Sync::ID_META, 'B1' );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		// Returns the existing post, does not create a second, and never hits the
		// node (no get_blog).
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['already_linked'] );
		$this->assertSame( $wp_id, (int) $response->get_data()['wpId'] );
		$this->assertNotContains( 'GET', $this->sent_methods );

		$linked = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => \Terraviz\Blog\Sync::ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'B1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$this->assertCount( 1, $linked );
	}

	public function test_import_blog_to_wp_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_import_blog_to_wp_requires_wp_post_capability(): void {
		$this->configure_credential();

		// A configure-tier user (has manage_terraviz) who lacks WordPress
		// post-editing rights: passes require_publish() but must NOT be able to
		// create a WP post through this route.
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $user );

		$this->assertTrue( $this->controller->require_publish() );
		$this->assertFalse( current_user_can( 'edit_posts' ) );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'forbidden', $response->get_data()['error'] );
		// It must never reach the node, either.
		$this->assertNotContains( 'GET', $this->sent_methods );
	}

	public function test_markdown_to_html_converts_common_shapes(): void {
		$this->assertSame(
			"<p>A summary.</p>\n\n<p><a href=\"https://example.com/x\">Read more</a></p>",
			$this->controller->markdown_to_html( "A summary.\n\n[Read more](https://example.com/x)" )
		);
		// ATX heading clamps to h2 (nests under the post title).
		$this->assertSame( '<h2>Title</h2>', $this->controller->markdown_to_html( '# Title' ) );
		// Unordered list.
		$this->assertSame( '<ul><li>one</li><li>two</li></ul>', $this->controller->markdown_to_html( "- one\n- two" ) );
		// Raw HTML is escaped (no injection into the WP post).
		$this->assertSame(
			'<p>x &lt;script&gt;alert(1)&lt;/script&gt;</p>',
			$this->controller->markdown_to_html( 'x <script>alert(1)</script>' )
		);
		// A non-http(s) link URL is dropped by esc_url.
		$this->assertStringContainsString( '<a href="">', $this->controller->markdown_to_html( '[x](javascript:alert(1))' ) );
	}

	public function test_markdown_to_blocks_emits_gutenberg_markup(): void {
		$out = $this->controller->markdown_to_blocks( "Intro.\n\n## Section\n\n- one\n- two" );

		// Block delimiters for each block type (not one Classic block).
		$this->assertStringContainsString( "<!-- wp:paragraph -->\n<p>Intro.</p>\n<!-- /wp:paragraph -->", $out );
		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2>Section</h2>\n<!-- /wp:heading -->", $out );
		$this->assertStringContainsString( '<!-- wp:list -->', $out );
		$this->assertStringContainsString( '<!-- wp:list-item --><li>one</li><!-- /wp:list-item -->', $out );
		// A deeper heading carries the level attribute.
		$this->assertStringContainsString(
			'<!-- wp:heading {"level":3} -->',
			$this->controller->markdown_to_blocks( '### Sub' )
		);
	}

	public function test_markdown_to_blocks_converts_media_shapes(): void {
		$md  = "![Cover shot](https://ex.com/cover.png)\n\n"
			. "https://www.youtube.com/watch?v=abc123\n\n"
			. 'Text with an ![inline](https://ex.com/a.jpg) image.';
		$out = $this->controller->markdown_to_blocks( $md );

		// A standalone image line becomes an image block.
		$this->assertStringContainsString( '<!-- wp:image -->', $out );
		$this->assertStringContainsString(
			'<figure class="wp-block-image"><img src="https://ex.com/cover.png" alt="Cover shot"/></figure>',
			$out
		);
		// A bare video URL becomes an embed block.
		$this->assertStringContainsString( '<!-- wp:embed', $out );
		$this->assertStringContainsString( 'class="wp-block-embed"', $out );
		// An inline image stays inside its paragraph.
		$this->assertStringContainsString(
			'<p>Text with an <img src="https://ex.com/a.jpg" alt="inline"/> image.</p>',
			$out
		);
	}

	public function test_markdown_media_drops_unsafe_urls(): void {
		// An unsafe image scheme yields no <img> (and so no image block at all).
		$blocks = $this->controller->markdown_to_blocks( '![x](javascript:alert(1))' );
		$this->assertStringNotContainsString( '<!-- wp:image -->', $blocks );
		$this->assertStringNotContainsString( '<img', $blocks );

		$html = $this->controller->markdown_to_html( '![x](javascript:alert(1))' );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_import_blog_seeds_featured_image_from_cover(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$cover_url = 'https://example.com/media/cover.png';
		// A 1x1 PNG so wp_generate_attachment_metadata can read real dimensions.
		$png = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
		);

		// The node's get_blog returns JSON; the cover fetch returns image bytes.
		// Both are GET, so discriminate on the URL.
		$image_filter = function ( $pre, $args, $url ) use ( $cover_url, $png ) {
			if ( (string) $url === $cover_url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => $png,
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'post' => array(
							'id'            => 'B1',
							'slug'          => 'a-story',
							'title'         => 'A Story',
							'bodyMd'        => 'Lead paragraph.',
							'coverImageUrl' => $cover_url,
							'coverImageAlt' => 'A wide cover',
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $image_filter, 10, 3 );
		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );
		remove_filter( 'pre_http_request', $image_filter, 10 );

		$this->assertSame( 201, $response->get_status() );
		$wp_id = (int) $response->get_data()['wpId'];
		$this->assertGreaterThan( 0, $wp_id );

		$thumb_id = get_post_thumbnail_id( $wp_id );
		$this->assertGreaterThan( 0, (int) $thumb_id );
		$this->assertSame( 'image/png', get_post_mime_type( $thumb_id ) );
		$this->assertSame( (int) $wp_id, (int) get_post( $thumb_id )->post_parent );
		$this->assertSame( 'A wide cover', get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
	}

	public function test_import_blog_ignores_unsafe_cover_and_still_seeds_post(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'post' => array(
						'id'            => 'B1',
						'slug'          => 'a-story',
						'title'         => 'A Story',
						'bodyMd'        => 'Lead paragraph.',
						'coverImageUrl' => 'javascript:alert(1)',
					),
				)
			),
		);

		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		// The post is still created; an unsafe cover URL is simply dropped, and no
		// featured image is set (only the get_blog GET was sent).
		$this->assertSame( 201, $response->get_status() );
		$wp_id = (int) $response->get_data()['wpId'];
		$this->assertSame( 0, (int) get_post_thumbnail_id( $wp_id ) );
		$this->assertCount( 1, $this->sent_methods );
	}
}
