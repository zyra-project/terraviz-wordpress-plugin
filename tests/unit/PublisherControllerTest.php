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
		// An inline image stays inside its paragraph (not promoted to its own
		// block). `wp_kses_post` may re-space the self-closing tag, so assert on
		// the parts, not an exact `/>`.
		$this->assertStringContainsString( '<p>Text with an <img src="https://ex.com/a.jpg" alt="inline"', $out );
		$this->assertStringContainsString( ' image.</p>', $out );
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

	public function test_markdown_preserves_literal_token_placeholder(): void {
		// A literal `{{TVTOKn}}` typed in the body was never minted by md_inline,
		// so it must survive verbatim, not become a fabricated empty link.
		$html = $this->controller->markdown_to_html( 'Type {{TVTOK0}} literally.' );
		$this->assertStringContainsString( '{{TVTOK0}}', $html );
		$this->assertStringNotContainsString( '<a href="">', $html );
	}

	public function test_import_blog_rejects_cover_whose_bytes_are_not_an_image(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$cover_url = 'https://example.com/media/fake.png';
		// The server lies: an image content-type over non-image bytes.
		$liar = function ( $pre, $args, $url ) use ( $cover_url ) {
			if ( (string) $url === $cover_url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => 'this is definitely not a PNG',
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
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $liar, 10, 3 );
		$response = $this->controller->import_blog_to_wp( $this->import_request( 'B1' ) );
		remove_filter( 'pre_http_request', $liar, 10 );

		// The post still seeds, but the lying cover is rejected on its real bytes,
		// so no featured image is set.
		$this->assertSame( 201, $response->get_status() );
		$wp_id = (int) $response->get_data()['wpId'];
		$this->assertSame( 0, (int) get_post_thumbnail_id( $wp_id ) );
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

	private function png_base64(): string {
		// A real 1x1 PNG, base64-encoded (the wire shape the pane sends).
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
	}

	public function test_media_suggestion_routes_require_publish_tier(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		// The suggestion reads/writes gate at the publish tier (consistent with
		// event review), unlike the configure-tier channel allowlist.
		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->require_publish() );

		wp_set_current_user( $author );
		$this->assertFalse( $this->controller->require_publish() );
	}

	public function test_search_youtube_media_forwards_get_with_query(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'videos' => array() ) ),
		);

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'q', 'Hurricane Delta' );
		$response = $this->controller->search_youtube_media( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$url = end( $this->sent_urls );
		$this->assertStringContainsString( '/api/v1/publish/media/youtube-search?q=', $url );
		$this->assertStringContainsString( 'Hurricane', $url );
	}

	public function test_list_nhc_storms_forwards_get(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'activeStorms' => array() ) ),
		);

		$response = $this->controller->list_nhc_storms();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/media/nhc-storms', end( $this->sent_urls ) );
	}

	public function test_set_event_image_forwards_normalized_body(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'imageUrl' => 'https://node/img/EV1.png' ) ),
		);

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'EV1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => $this->png_base64(),
					'altText'     => '  A storm  ',
					'evil'        => 'DROP',
				)
			)
		);
		$response = $this->controller->set_event_image( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/events/EV1/image', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame( 'image/png', $sent['contentType'] );
		$this->assertSame( $this->png_base64(), $sent['dataBase64'] );
		$this->assertSame( 'A storm', $sent['altText'] );
		$this->assertArrayNotHasKey( 'evil', $sent );
	}

	public function test_set_event_image_rejects_non_image_without_forwarding(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'EV1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => base64_encode( 'this is not an image' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			)
		);
		$response = $this->controller->set_event_image( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		// Rejected locally with a 400; never forwarded to the node.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_image', $response->get_data()['error'] );
		$this->assertNotContains( 'POST', $this->sent_methods );
	}

	public function test_media_suggestion_routes_without_credential_return_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$search = new WP_REST_Request( 'GET' );
		$search->set_param( 'q', 'x' );
		$this->assertSame( 409, $this->controller->search_youtube_media( $search )->get_status() );
		$this->assertSame( 409, $this->controller->list_nhc_storms()->get_status() );

		$image = new WP_REST_Request( 'POST' );
		$image->set_param( 'id', 'EV1' );
		$this->assertSame( 409, $this->controller->set_event_image( $image )->get_status() );
	}

	public function test_normalize_event_image_body_validates_and_allowlists(): void {
		// A valid PNG: forwards the detected type, the bare base64, sanitized alt.
		$ok = $this->controller->normalize_event_image_body(
			array(
				'contentType' => 'image/png',
				'dataBase64'  => 'data:image/png;base64,' . $this->png_base64(),
				'altText'     => "line\nbreak",
			)
		);
		$this->assertSame( 'image/png', $ok['contentType'] );
		$this->assertSame( $this->png_base64(), $ok['dataBase64'] );
		$this->assertSame( 'line break', $ok['altText'] );

		// An unsupported claimed type is rejected before decoding.
		$this->assertArrayHasKey(
			'error',
			$this->controller->normalize_event_image_body(
				array(
					'contentType' => 'image/svg+xml',
					'dataBase64'  => $this->png_base64(),
				)
			)
		);

		// Valid base64 that isn't actually an image is rejected.
		$this->assertArrayHasKey(
			'error',
			$this->controller->normalize_event_image_body(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => base64_encode( 'nope' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			)
		);
	}

	private function generate_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	public function test_draft_blog_with_ai_seeds_wp_draft_from_generated_content(): void {
		$this->configure_credential();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// The node returns an (unpersisted) AI draft plus a companion tour.
		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'draft' => array(
						'title'   => 'Warming Seas',
						'summary' => 'A short summary.',
						'bodyMd'  => "Lead paragraph.\n\n[More](https://example.com/x)",
					),
					'tour'  => array(
						'id'    => 'tour-01',
						'slug'  => 'warming-seas',
						'title' => 'Warming Seas tour',
					),
				)
			),
		);

		$response = $this->controller->draft_blog_with_ai(
			$this->generate_request(
				array(
					'datasetIds' => array( 'sea-surface-temp', 'sea-surface-temp' ),
					'length'     => 'medium',
					'evil'       => 'DROP',
				)
			)
		);

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/blog/generate', end( $this->sent_urls ) );

		// The forwarded body is the normalized allowlist (deduped ids, no `evil`).
		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame( array( 'sea-surface-temp' ), $sent['datasetIds'] );
		$this->assertArrayNotHasKey( 'evil', $sent );

		$wp_id = (int) $response->get_data()['wpId'];
		$this->assertGreaterThan( 0, $wp_id );

		$post = get_post( $wp_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Warming Seas', $post->post_title );
		$this->assertSame( (int) $editor, (int) $post->post_author );
		$this->assertSame( 'A short summary.', $post->post_excerpt );
		// Seeded as real Gutenberg blocks with the escaped link…
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
		$this->assertStringContainsString( '<a href="https://example.com/x">More</a>', $post->post_content );
		// …a dataset embed for the grounding, and the companion tour embed.
		$this->assertStringContainsString( '<!-- wp:terraviz/dataset {"id":"sea-surface-temp"} /-->', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:terraviz/tour {"id":"tour-01"} /-->', $post->post_content );
		// Opted into Terraviz so a WP publish creates the node stub via the sync.
		$this->assertTrue( (bool) get_post_meta( $wp_id, \Terraviz\Blog\Sync::OPTIN_META, true ) );
		// No node blog id is linked (the draft was never persisted upstream).
		$this->assertSame( '', (string) get_post_meta( $wp_id, \Terraviz\Blog\Sync::ID_META, true ) );

		$this->assertSame(
			array(
				'id'    => 'tour-01',
				'slug'  => 'warming-seas',
				'title' => 'Warming Seas tour',
			),
			$response->get_data()['tour']
		);
	}

	public function test_draft_blog_with_ai_rejects_empty_datasets_without_forwarding(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$response = $this->controller->draft_blog_with_ai( $this->generate_request( array( 'datasetIds' => array() ) ) );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_datasets', $response->get_data()['error'] );
		// Never reaches the node.
		$this->assertNotContains( 'POST', $this->sent_methods );
	}

	public function test_draft_blog_with_ai_surfaces_node_ai_unavailable(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 503 ),
			'body'     => (string) wp_json_encode(
				array(
					'error'   => 'ai_unavailable',
					'message' => 'No AI binding on this node.',
				)
			),
		);

		$before   = (int) wp_count_posts()->draft;
		$response = $this->controller->draft_blog_with_ai(
			$this->generate_request( array( 'datasetIds' => array( 'sea-surface-temp' ) ) )
		);

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		// The node's status + reason pass through, and no WP post is created.
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'ai_unavailable', $response->get_data()['error'] );
		$this->assertSame( $before, (int) wp_count_posts()->draft );
	}

	public function test_draft_blog_with_ai_requires_wp_post_capability(): void {
		$this->configure_credential();

		// Configure-tier (manage_terraviz) but no WordPress post-editing rights.
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $user );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$response = $this->controller->draft_blog_with_ai(
			$this->generate_request( array( 'datasetIds' => array( 'sea-surface-temp' ) ) )
		);

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'forbidden', $response->get_data()['error'] );
		$this->assertNotContains( 'POST', $this->sent_methods );
	}

	public function test_draft_blog_with_ai_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->controller->draft_blog_with_ai(
			$this->generate_request( array( 'datasetIds' => array( 'sea-surface-temp' ) ) )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'credential_missing', $response->get_data()['error'] );
	}

	public function test_normalize_blog_generate_body_allowlists_and_coerces(): void {
		$out = $this->controller->normalize_blog_generate_body(
			array(
				'datasetIds'  => array( 'a', 'a', 'b!!', 5 ),
				'eventId'     => 'EV_1',
				'tone'        => '  warm  and   clear ',
				'length'      => 'long',
				'includeTour' => true,
				'stray'       => 1,
			)
		);
		$this->assertSame( array( 'a', 'b', '5' ), $out['datasetIds'] );
		$this->assertSame( 'EV_1', $out['eventId'] );
		$this->assertSame( 'warm and clear', $out['tone'] );
		$this->assertSame( 'long', $out['length'] );
		$this->assertTrue( $out['includeTour'] );
		$this->assertArrayNotHasKey( 'stray', $out );

		// A bad length is dropped (node defaults it); a non-true includeTour is
		// omitted; the id list is capped at the node's 20-dataset limit.
		$capped = $this->controller->normalize_blog_generate_body(
			array(
				'datasetIds'  => array_map( static fn( $i ) => 'd' . $i, range( 1, 30 ) ),
				'length'      => 'epic',
				'includeTour' => 'false',
			)
		);
		$this->assertCount( 20, $capped['datasetIds'] );
		$this->assertArrayNotHasKey( 'length', $capped );
		$this->assertArrayNotHasKey( 'includeTour', $capped );
	}

	public function test_node_profile_routes_require_configure_tier(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Node profile is operator-wide config the node restricts to admin/service,
		// so a publish-tier editor is not enough.
		wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->require_configure() );

		wp_set_current_user( $editor );
		$this->assertFalse( $this->controller->require_configure() );
	}

	public function test_get_node_profile_forwards_get(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['GET'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'profile' => null ) ),
		);

		$response = $this->controller->get_node_profile();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/node-profile', end( $this->sent_urls ) );
	}

	public function test_set_node_profile_forwards_put_with_normalized_body(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['PUT'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'profile' => array( 'orgName' => 'NOAA Lab' ) ) ),
		);

		$request = new WP_REST_Request( 'PUT' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'orgName'     => '  NOAA Lab  ',
					'aboutMd'     => "## About\n\nWe **do** science.",
					'defaultTone' => 'factual',
					'links'       => array(
						array(
							'label' => 'Home',
							'url'   => 'https://noaa.example',
						),
						'not-an-object',
					),
					'evil'        => 'DROP',
				)
			)
		);
		$response = $this->controller->set_node_profile( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/node-profile', end( $this->sent_urls ) );

		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame( 'NOAA Lab', $sent['orgName'] );
		// Markdown preserved verbatim (not tag-stripped).
		$this->assertSame( "## About\n\nWe **do** science.", $sent['aboutMd'] );
		$this->assertArrayNotHasKey( 'evil', $sent );
		// The non-object link is dropped; the valid one is forwarded.
		$this->assertSame(
			array(
				array(
					'label' => 'Home',
					'url'   => 'https://noaa.example',
				),
			),
			$sent['links']
		);
	}

	public function test_set_node_profile_logo_forwards_post_and_rejects_non_image(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['POST'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'profile' => array( 'logoUrl' => 'https://node/logo.png' ) ) ),
		);

		// A real PNG uploads; the forwarded body carries only type + bytes.
		$ok = new WP_REST_Request( 'POST' );
		$ok->set_header( 'Content-Type', 'application/json' );
		$ok->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => $this->png_base64(),
				)
			)
		);
		$response = $this->controller->set_node_profile_logo( $ok );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/api/v1/publish/node-profile/logo', end( $this->sent_urls ) );
		$sent = json_decode( end( $this->sent_bodies ), true );
		$this->assertSame( 'image/png', $sent['contentType'] );
		$this->assertArrayNotHasKey( 'altText', $sent );

		// Non-image bytes are rejected locally (400) and never forwarded.
		$this->sent_methods = array();
		$bad                = new WP_REST_Request( 'POST' );
		$bad->set_header( 'Content-Type', 'application/json' );
		$bad->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => base64_encode( 'not an image' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			)
		);
		$bad_response = $this->controller->set_node_profile_logo( $bad );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 400, $bad_response->get_status() );
		$this->assertSame( 'invalid_image', $bad_response->get_data()['error'] );
		$this->assertNotContains( 'POST', $this->sent_methods );
	}

	public function test_delete_node_profile_logo_forwards_delete(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->http_by_method['DELETE'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'profile' => array( 'logoUrl' => null ) ) ),
		);

		$response = $this->controller->delete_node_profile_logo();

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'DELETE', $this->sent_methods );
		$this->assertStringEndsWith( '/api/v1/publish/node-profile/logo', end( $this->sent_urls ) );
	}

	public function test_node_profile_without_credential_returns_409(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 409, $this->controller->get_node_profile()->get_status() );

		$put = new WP_REST_Request( 'PUT' );
		$put->set_header( 'Content-Type', 'application/json' );
		$put->set_body( (string) wp_json_encode( array( 'orgName' => 'X' ) ) );
		$this->assertSame( 409, $this->controller->set_node_profile( $put )->get_status() );

		// Logo POST has its own entry point, so cover it too.
		$logo = new WP_REST_Request( 'POST' );
		$logo->set_header( 'Content-Type', 'application/json' );
		$logo->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/png',
					'dataBase64'  => $this->png_base64(),
				)
			)
		);
		$this->assertSame( 409, $this->controller->set_node_profile_logo( $logo )->get_status() );

		$this->assertSame( 409, $this->controller->delete_node_profile_logo()->get_status() );
	}

	public function test_logo_rejects_gif_locally_without_forwarding(): void {
		$this->configure_credential();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// GIF is allowed for an event image but NOT for a logo (png/jpeg/webp
		// only), so the proxy rejects it locally rather than forwarding it.
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'contentType' => 'image/gif',
					'dataBase64'  => base64_encode( 'GIF89a' . str_repeat( 'x', 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			)
		);
		$response = $this->controller->set_node_profile_logo( $request );

		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_image', $response->get_data()['error'] );
		$this->assertNotContains( 'POST', $this->sent_methods );

		// The generic image normalizer still accepts a GIF (event images do).
		$this->assertArrayNotHasKey(
			'error',
			$this->controller->normalize_event_image_body(
				array(
					'contentType' => 'image/gif',
					'dataBase64'  => base64_encode( "GIF89a\x01\x00\x01\x00\x00\x00\x00" ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			)
		);
	}

	public function test_normalize_node_profile_body_allowlists_and_preserves_markdown(): void {
		$out = $this->controller->normalize_node_profile_body(
			array(
				'orgName'     => '  NOAA  Lab ',
				'regionFocus' => 'North Atlantic',
				'defaultTone' => 'factual',
				'mission'     => "Line1\nLine2",
				'aboutMd'     => "## H\n\n**bold**",
				'links'       => array(
					array(
						'label' => ' Home ',
						'url'   => ' https://x.org ',
					),
					array(
						'label' => 'Bad',
						'url'   => 'javascript:alert(1)',
					),
				),
				'stray'       => 1,
			)
		);

		$this->assertSame( 'NOAA Lab', $out['orgName'] );
		// Single-line fields collapse whitespace; multi-line prose/markdown is kept.
		$this->assertSame( "Line1\nLine2", $out['mission'] );
		$this->assertSame( "## H\n\n**bold**", $out['aboutMd'] );
		$this->assertArrayNotHasKey( 'stray', $out );
		// A valid link is trimmed; an unsafe URL is neutralized to '' (the node
		// then field-errors it) rather than silently dropped.
		$this->assertSame( 'Home', $out['links'][0]['label'] );
		$this->assertSame( 'https://x.org', $out['links'][0]['url'] );
		$this->assertSame( '', $out['links'][1]['url'] );
	}
}
