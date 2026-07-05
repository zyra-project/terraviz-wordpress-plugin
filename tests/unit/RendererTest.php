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
}
