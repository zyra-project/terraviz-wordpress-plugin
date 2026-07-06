<?php
/**
 * Tests for the one-way WP-post → Terraviz-blog-stub sync.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Catalog;
use Terraviz\Blog\Sync;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;
use Terraviz\Tests\FakeReader;

/**
 * @covers \Terraviz\Blog\Sync
 */
class BlogSyncTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://node.example';

	/**
	 * Requests the intercepted HTTP layer captured.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $requests = array();

	/**
	 * Optional canned PUT response (e.g. a 404 to exercise recreate).
	 *
	 * @var array|null
	 */
	private $put_response = null;

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant();
	}

	/**
	 * A Sync whose catalog resolves ids from a canned catalog body (empty by
	 * default, so cited ids pass through unresolved).
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
		$catalog      = new Catalog( $reader );

		return new Sync( $catalog );
	}

	private function make_post( string $content, array $args = array() ): WP_Post {
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

	public function test_grounding_extracts_dedupes_and_picks_first_tour(): void {
		$content = implode(
			"\n",
			array(
				'<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->',
				'<!-- wp:terraviz/related {"id":"DS_TWO"} /-->',
				'<!-- wp:terraviz/tour {"id":"TOUR_ONE"} /-->',
				'<!-- wp:terraviz/tour {"id":"TOUR_TWO"} /-->',
				'<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->',
				'<!-- wp:paragraph -->Just prose.<!-- /wp:paragraph -->',
				'<!-- wp:terraviz/catalog /-->',
			)
		);

		$grounding = $this->sync_with()->grounding( $this->make_post( $content ) );

		$this->assertSame( array( 'DS_ONE', 'DS_TWO' ), $grounding['datasetIds'] );
		$this->assertSame( 'TOUR_ONE', $grounding['tourId'] );
	}

	public function test_grounding_resolves_slug_to_canonical_id(): void {
		$datasets = array(
			array(
				'id'    => 'CANONICAL_1',
				'slug'  => 'hurricane-season-2024',
				'title' => 'Hurricane Season 2024',
			),
		);
		$content  = '<!-- wp:terraviz/dataset {"id":"hurricane-season-2024"} /-->';

		$grounding = $this->sync_with( $datasets )->grounding( $this->make_post( $content ) );

		$this->assertSame( array( 'CANONICAL_1' ), $grounding['datasetIds'] );
	}

	public function test_build_body_has_title_summary_link_and_grounding(): void {
		$content = '<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->';
		$post    = $this->make_post( $content, array( 'post_excerpt' => 'A stormy season.' ) );

		$body = $this->sync_with()->build_body( $post );

		$this->assertSame( 'Hurricane Season 2024', $body['title'] );
		$this->assertSame( 'A stormy season.', $body['summary'] );
		$this->assertStringContainsString( 'A stormy season.', $body['bodyMd'] );
		$this->assertStringContainsString( get_permalink( $post ), $body['bodyMd'] );
		$this->assertSame( array( 'DS_ONE' ), $body['datasetIds'] );
	}

	/**
	 * Intercept the proxy's HTTP, recording each request and returning a canned
	 * response by (method, path shape).
	 */
	public function intercept( $pre, $args, $url ) {
		$method           = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : array(),
		);

		$is_collection = (bool) preg_match( '#/publish/blog$#', (string) $url );

		if ( 'POST' === $method && $is_collection ) {
			// create
			return $this->json(
				201,
				array(
					'post' => array(
						'id'   => 'BLOG_NEW',
						'slug' => 'hurricane-season-2024',
					),
				)
			);
		}
		if ( 'PUT' === $method ) {
			// update — honour a canned override (e.g. a 404).
			return $this->put_response ?? $this->json( 200, array( 'post' => array( 'id' => 'BLOG_EXISTING' ) ) );
		}
		// POST on an item route = publish/unpublish action.
		return $this->json(
			200,
			array(
				'post' => array(
					'id'   => 'BLOG',
					'slug' => 'hurricane-season-2024',
				),
			)
		);
	}

	private function json( int $code, array $data ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $data ),
		);
	}

	private function configure_credential(): void {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'No crypto backend to store a service token.' );
		}
		Credential::save( 'cid.access', 'sekret' );
	}

	private function methods(): array {
		return array_map(
			static function ( $r ) {
				return $r['method'];
			},
			$this->requests
		);
	}

	public function test_do_sync_creates_then_publishes(): void {
		$this->configure_credential();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$post = $this->make_post( '<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->' );
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		$this->sync_with()->do_sync( $post->ID );

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertSame( 'BLOG_NEW', get_post_meta( $post->ID, Sync::ID_META, true ) );
		$this->assertSame( 'hurricane-season-2024', get_post_meta( $post->ID, Sync::SLUG_META, true ) );
		$this->assertSame( 'synced', get_post_meta( $post->ID, Sync::STATE_META, true ) );
		// A create (POST collection) followed by a publish action (POST item).
		$this->assertSame( array( 'POST', 'POST' ), $this->methods() );
	}

	public function test_do_sync_updates_existing_stub_in_place(): void {
		$this->configure_credential();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$post = $this->make_post( '<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->' );
		update_post_meta( $post->ID, Sync::OPTIN_META, true );
		update_post_meta( $post->ID, Sync::ID_META, 'BLOG_EXISTING' );

		$this->sync_with()->do_sync( $post->ID );

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertSame( 'BLOG_EXISTING', get_post_meta( $post->ID, Sync::ID_META, true ) );
		$this->assertSame( 'synced', get_post_meta( $post->ID, Sync::STATE_META, true ) );
		// PUT update, then a POST publish action — no POST create.
		$this->assertSame( array( 'PUT', 'POST' ), $this->methods() );
	}

	public function test_do_sync_recreates_when_stub_is_gone_upstream(): void {
		$this->configure_credential();
		$this->put_response = $this->json(
			404,
			array(
				'error'   => 'not_found',
				'message' => 'gone',
			)
		);
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$post = $this->make_post( '<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->' );
		update_post_meta( $post->ID, Sync::OPTIN_META, true );
		update_post_meta( $post->ID, Sync::ID_META, 'BLOG_OLD' );

		$this->sync_with()->do_sync( $post->ID );

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		// PUT 404 → create → publish, and the id is refreshed.
		$this->assertSame( array( 'PUT', 'POST', 'POST' ), $this->methods() );
		$this->assertSame( 'BLOG_NEW', get_post_meta( $post->ID, Sync::ID_META, true ) );
		$this->assertSame( 'synced', get_post_meta( $post->ID, Sync::STATE_META, true ) );
	}

	public function test_do_sync_skips_password_protected_post(): void {
		$this->configure_credential();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$post = $this->make_post(
			'<!-- wp:terraviz/dataset {"id":"DS_ONE"} /-->',
			array( 'post_password' => 'secret' )
		);
		update_post_meta( $post->ID, Sync::OPTIN_META, true );

		$this->sync_with()->do_sync( $post->ID );

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		// A password-protected post is access-gated content — nothing is sent.
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', get_post_meta( $post->ID, Sync::ID_META, true ) );
	}

	public function test_do_unsync_unpublishes_but_keeps_id(): void {
		$this->configure_credential();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$post = $this->make_post( 'x' );
		update_post_meta( $post->ID, Sync::ID_META, 'BLOG_EXISTING' );
		update_post_meta( $post->ID, Sync::STATE_META, 'synced' );

		$this->sync_with()->do_unsync( $post->ID );

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		$this->assertSame( 'unsynced', get_post_meta( $post->ID, Sync::STATE_META, true ) );
		$this->assertSame( 'BLOG_EXISTING', get_post_meta( $post->ID, Sync::ID_META, true ) );
		$this->assertSame( 'unpublish', $this->requests[0]['body']['action'] );
	}
}
