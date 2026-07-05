<?php
/**
 * Tests for the shared SSR renderer, using a canned reader (no network).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Catalog;
use Terraviz\Embed\Renderer;
use Terraviz\Tests\FakeReader;

/**
 * @covers \Terraviz\Embed\Renderer
 */
class RendererTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://terraviz.zyra-project.org';

	private function dataset_payload(): array {
		return array(
			'id'                => 'INTERNAL_SOS_768',
			'slug'              => 'hurricane-season-2024',
			'title'             => 'Hurricane Season 2024',
			'format'            => 'video/mp4',
			'dataLink'          => '/api/v1/datasets/INTERNAL_SOS_768/manifest',
			'abstractTxt'       => 'A visualization of the 2024 Atlantic hurricane season.',
			'thumbnailLink'     => 'https://video.zyra-project.org/x/thumbnail.jpg',
			'tags'              => array( 'Air', 'Water' ),
			'originNode'        => 'NODE',
			'originNodeUrl'     => self::ORIGIN,
			'originDisplayName' => 'Terraviz',
			'visibility'        => 'public',
			'schemaVersion'     => 1,
			'createdAt'         => '2026-04-30T21:51:55.439Z',
			'updatedAt'         => '2026-05-13T18:38:16.526Z',
		);
	}

	private function renderer_with( array $responses ): Renderer {
		$reader  = new FakeReader( self::ORIGIN, $responses );
		$catalog = new Catalog( $reader, 60 );

		return new Renderer( $catalog );
	}

	public function test_dataset_ssr_fallback_has_indexable_content(): void {
		$renderer = $this->renderer_with(
			array( '/api/v1/datasets/INTERNAL_SOS_768' => $this->dataset_payload() )
		);

		$html = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => 'INTERNAL_SOS_768',
			)
		);

		// Real, crawlable text.
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
		$this->assertStringContainsString( 'A visualization of the 2024 Atlantic hurricane season.', $html );
		$this->assertStringContainsString( 'video.zyra-project.org/x/thumbnail.jpg', $html );

		// Canonical link (path form), not the embed URL, in the caption.
		$this->assertStringContainsString( self::ORIGIN . '/dataset/INTERNAL_SOS_768', $html );

		// Tags rendered.
		$this->assertStringContainsString( 'terraviz-embed__tag', $html );

		// Progressive-enhancement hook: the embed URL is deferred, not a live iframe.
		$this->assertStringContainsString( 'data-terraviz-src=', $html );
		$this->assertStringContainsString( 'embed=1', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_view_flags_reach_the_embed_url(): void {
		$renderer = $this->renderer_with(
			array(
				'/api/v1/datasets/x' => array(
					'id'    => 'x',
					'title' => 'X',
				) + $this->dataset_payload(),
			)
		);

		$html = $renderer->render(
			array(
				'type'    => 'dataset',
				'id'      => 'x',
				'terrain' => true,
				'chat'    => true,
			)
		);

		$this->assertStringContainsString( 'terrain=on', $html );
		$this->assertStringContainsString( 'chat=1', $html );
	}

	public function test_missing_dataset_still_renders_a_usable_embed(): void {
		// No dataset payload and no catalog: the block degrades to id-titled card.
		$renderer = $this->renderer_with( array() );

		$html = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => 'UNKNOWN_ID',
			)
		);

		$this->assertStringContainsString( 'UNKNOWN_ID', $html );
		$this->assertStringContainsString( 'data-terraviz-src=', $html );
	}

	public function test_empty_id_is_a_notice_not_a_fatal(): void {
		$renderer = $this->renderer_with( array() );
		$html     = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => '',
			)
		);

		$this->assertStringContainsString( 'terraviz-embed--notice', $html );
		$this->assertStringNotContainsString( 'data-terraviz-src=', $html );
	}

	public function test_catalog_ssr_lists_dataset_cards(): void {
		$catalog_body = array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-04T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => null,
			'datasets'       => array( $this->dataset_payload() ),
			'tombstones'     => array(),
		);

		$renderer = $this->renderer_with( array( '/api/v1/catalog' => $catalog_body ) );
		$html     = $renderer->render( array( 'type' => 'catalog' ) );

		$this->assertStringContainsString( 'terraviz-embed__catalog-grid', $html );
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
		$this->assertStringContainsString( self::ORIGIN . '/dataset/INTERNAL_SOS_768', $html );
		// The interactive catalog embed URL is present too.
		$this->assertStringContainsString( 'catalog=true', $html );
	}

	public function test_non_interactive_dataset_omits_the_globe_hook(): void {
		$renderer = $this->renderer_with(
			array( '/api/v1/datasets/INTERNAL_SOS_768' => $this->dataset_payload() )
		);

		$html = $renderer->render(
			array(
				'type'        => 'dataset',
				'id'          => 'INTERNAL_SOS_768',
				'interactive' => false,
			)
		);

		$this->assertStringNotContainsString( 'data-terraviz-src=', $html );
		// Still indexable/accessible.
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
	}

	public function test_output_is_escaped(): void {
		$payload                = $this->dataset_payload();
		$payload['title']       = 'Bad <script>alert(1)</script>';
		$payload['abstractTxt'] = 'x " onerror=alert(1) ';

		$renderer = $this->renderer_with( array( '/api/v1/datasets/x' => array( 'id' => 'x' ) + $payload ) );
		$html     = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => 'x',
			)
		);

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'Bad &lt;script&gt;', $html );
	}

	public function test_slug_is_resolved_to_canonical_id(): void {
		// Author supplies the human-readable slug; only the catalog endpoint
		// is available (the per-id endpoint 404s for a slug), so resolution
		// happens via the catalog scan.
		$catalog_body = array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-04T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => null,
			'datasets'       => array( $this->dataset_payload() ),
			'tombstones'     => array(),
		);

		$renderer = $this->renderer_with( array( '/api/v1/catalog' => $catalog_body ) );
		$html     = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => 'hurricane-season-2024', // the slug, not the id.
			)
		);

		// The embed + canonical URLs carry the canonical id, not the slug.
		$this->assertStringContainsString( 'dataset=INTERNAL_SOS_768', $html );
		$this->assertStringContainsString( self::ORIGIN . '/dataset/INTERNAL_SOS_768', $html );
		$this->assertStringNotContainsString( 'dataset=hurricane-season-2024', $html );
		// And the title still resolves for the SSR fallback.
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
	}

	public function test_legacy_id_is_resolved_to_canonical_id(): void {
		$catalog_body = array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-04T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => null,
			'datasets'       => array( $this->dataset_payload() + array( 'legacyId' => 'INTERNAL_SOS_768_LEGACY' ) ),
			'tombstones'     => array(),
		);

		$renderer = $this->renderer_with( array( '/api/v1/catalog' => $catalog_body ) );
		$html     = $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => 'INTERNAL_SOS_768_LEGACY',
			)
		);

		$this->assertStringContainsString( 'dataset=INTERNAL_SOS_768', $html );
		$this->assertStringNotContainsString( 'INTERNAL_SOS_768_LEGACY', $html );
	}

	public function test_untrusted_origin_override_is_not_fetched_server_side(): void {
		$node = 'https://attacker.example';

		// The (trusted) default catalog holds the data; the override node's
		// factory would ALSO resolve it — but the plugin must never call it,
		// because the override origin is author-controllable (SSRF).
		$default = new Catalog(
			new FakeReader( self::ORIGIN, array( '/api/v1/datasets/INTERNAL_SOS_768' => $this->dataset_payload() ) ),
			60
		);

		$factory_called_with = array();
		$renderer            = new Renderer(
			$default,
			static function ( string $origin ) use ( &$factory_called_with ) {
				$factory_called_with[] = $origin;
				// A catalog that would happily serve the attacker's node.
				return new \Terraviz\Api\Catalog(
					new FakeReader(
						$origin,
						array(
							'/api/v1/datasets/INTERNAL_SOS_768' => array(
								'id'    => 'x',
								'title' => 'PWNED',
							),
						)
					),
					60
				);
			}
		);

		$html = $renderer->render(
			array(
				'type'   => 'dataset',
				'id'     => 'INTERNAL_SOS_768',
				'origin' => $node,
			)
		);

		// The untrusted origin was NEVER fetched server-side.
		$this->assertNotContains( $node, $factory_called_with );
		$this->assertStringNotContainsString( 'PWNED', $html );
		// SSR data fell back to the trusted default node.
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
		// The iframe still targets the override origin (that's the visitor's
		// browser, not our server) — the override is honoured for the embed.
		$this->assertStringContainsString( 'data-terraviz-src="' . $node, $html );
	}

	public function test_allowlisted_origin_is_fetched_server_side(): void {
		$node = 'https://partner.example';

		add_filter(
			'terraviz_allowed_fetch_origins',
			static function ( $origins ) use ( $node ) {
				$origins[] = $node;
				return $origins;
			}
		);

		$default      = new Catalog( new FakeReader( self::ORIGIN, array() ), 60 );
		$node_catalog = new Catalog(
			new FakeReader( $node, array( '/api/v1/datasets/INTERNAL_SOS_768' => $this->dataset_payload() ) ),
			60
		);

		$renderer = new Renderer(
			$default,
			static function ( string $origin ) use ( $node, $node_catalog, $default ) {
				return $origin === $node ? $node_catalog : $default;
			}
		);

		$html = $renderer->render(
			array(
				'type'   => 'dataset',
				'id'     => 'INTERNAL_SOS_768',
				'origin' => $node,
			)
		);

		// An admin-allowlisted origin IS fetched server-side.
		$this->assertStringContainsString( 'Hurricane Season 2024', $html );
		$this->assertStringContainsString( $node . '/dataset/INTERNAL_SOS_768', $html );
	}
}
