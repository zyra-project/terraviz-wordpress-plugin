<?php
/**
 * Tests for the one-way WP-post → Terraviz-event proposal.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Catalog;
use Terraviz\Events\Sync;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;
use Terraviz\Tests\FakeReader;

/**
 * @covers \Terraviz\Events\Sync
 */
class EventsSyncTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://node.example';

	/**
	 * Requests the intercepted HTTP layer captured.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $requests = array();

	/**
	 * HTTP status the intercepted `POST /publish/events` returns.
	 *
	 * @var int
	 */
	private $post_status = 201;

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant();
		$this->requests    = array();
		$this->post_status = 201;
	}

	/**
	 * A Sync whose catalog resolves ids from a canned catalog body.
	 *
	 * @param array<int,array<string,mixed>> $datasets Catalog dataset rows.
	 */
	private function sync_with( array $datasets = array() ): Sync {
		$catalog_body = array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-06T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => null,
			'datasets'       => $datasets,
			'tombstones'     => array(),
		);
		$reader       = new FakeReader( self::ORIGIN, array( '/api/v1/catalog' => $catalog_body ) );

		return new Sync( new Catalog( $reader ) );
	}

	private function make_post( string $content = '', array $args = array() ): WP_Post {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Hurricane Season 2024',
					'post_content' => $content,
				),
				$args
			)
		);
		return get_post( $id );
	}

	/**
	 * Intercept outgoing HTTP, recording each request and returning a canned
	 * create response for `POST /publish/events`.
	 */
	public function intercept( $pre, $args, $url ) {
		$method           = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : array(),
		);

		return array(
			'response' => array( 'code' => $this->post_status ),
			'body'     => (string) wp_json_encode(
				$this->post_status < 300 ? array( 'event' => array( 'id' => 'EV_NEW' ) ) : array( 'error' => 'boom' )
			),
		);
	}

	private function configure_credential(): void {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'No crypto backend to store a service token.' );
		}
		Credential::save( 'cid.access', 'sekret' );
	}

	// --- build_body -------------------------------------------------------

	public function test_build_body_maps_source_provenance_and_grounding(): void {
		$content = '<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->'
			. '<!-- wp:terraviz/related {"id":"DS_TWO"} /-->';
		$post    = $this->make_post( $content, array( 'post_excerpt' => 'A stormy season.' ) );

		$body = $this->sync_with()->build_body( $post );

		$this->assertSame( 'Hurricane Season 2024', $body['title'] );
		$this->assertSame( (string) $post->ID, $body['externalId'] );
		$this->assertSame( get_bloginfo( 'name' ), $body['source']['name'] );
		$this->assertSame( get_permalink( $post ), $body['source']['url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['source']['publishedAt'] );
		$this->assertSame( $body['source']['publishedAt'], $body['occurredStart'] );
		$this->assertSame( 'A stormy season.', $body['summary'] );
		$this->assertSame( array( 'DS_ONE', 'DS_TWO' ), $body['datasetIds'] );
		$this->assertArrayNotHasKey( 'imageUrl', $body, 'No featured image ⇒ no imageUrl.' );
	}

	public function test_build_body_includes_featured_image_when_set(): void {
		$post   = $this->make_post();
		$att_id = self::factory()->attachment->create_object(
			'hurricane.jpg',
			$post->ID,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		set_post_thumbnail( $post->ID, $att_id );

		$body = $this->sync_with()->build_body( get_post( $post->ID ) );

		$this->assertArrayHasKey( 'imageUrl', $body );
		$this->assertNotSame( '', $body['imageUrl'] );
	}

	public function test_grounding_resolves_slug_to_canonical_id(): void {
		$datasets = array(
			array(
				'id'    => 'CANONICAL_1',
				'slug'  => 'hurricane-season-2024',
				'title' => 'Hurricane Season 2024',
			),
		);
		$post     = $this->make_post( '<!-- wp:terraviz/dataset {"id":"hurricane-season-2024"} /-->' );

		$this->assertSame( array( 'CANONICAL_1' ), $this->sync_with( $datasets )->grounding( $post ) );
	}

	// --- do_propose -------------------------------------------------------

	public function test_do_propose_posts_once_and_marks_proposed(): void {
		$this->configure_credential();
		$post = $this->make_post();
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$sync = $this->sync_with();
		$sync->do_propose( $post->ID );
		// A second run must NOT propose again (propose-once guard on state).
		$sync->do_propose( $post->ID );
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
		$this->assertStringEndsWith( '/api/v1/publish/events', (string) $this->requests[0]['url'] );
		$this->assertSame( 'proposed', get_post_meta( $post->ID, Sync::STATE_META, true ) );
		$this->assertSame( 'EV_NEW', get_post_meta( $post->ID, Sync::ID_META, true ) );
	}

	public function test_do_propose_without_credential_makes_no_request(): void {
		$post = $this->make_post();
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$this->sync_with()->do_propose( $post->ID );
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertSame( array(), $this->requests );
	}

	public function test_do_propose_skips_password_protected_post(): void {
		$this->configure_credential();
		$post = $this->make_post( '', array( 'post_password' => 'secret' ) );
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$this->sync_with()->do_propose( $post->ID );
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertSame( array(), $this->requests );
	}

	public function test_error_state_allows_a_later_retry(): void {
		$this->configure_credential();
		$post = $this->make_post();
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$this->post_status = 500;
		$this->sync_with()->do_propose( $post->ID );
		$this->assertSame( 'error', get_post_meta( $post->ID, Sync::STATE_META, true ) );

		// A subsequent save/run retries (state is 'error', not 'proposed').
		$this->post_status = 201;
		$this->sync_with()->do_propose( $post->ID );
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'proposed', get_post_meta( $post->ID, Sync::STATE_META, true ) );
	}
}
