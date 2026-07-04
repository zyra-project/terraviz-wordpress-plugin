<?php
/**
 * CI smoke test: hit the canonical node's public API and assert a block renders.
 *
 * This is the drift tripwire called for in the WordPress Integration Plan §7 —
 * it catches a change to the embed-URL grammar or the wire schema within a day,
 * not a release. It talks to the live public read API (no credentials), so it
 * is skipped when the node is unreachable (e.g. an offline dev box).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Client;

/**
 * @group smoke
 * @coversNothing
 */
class PublicApiSmokeTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://terraviz.zyra-project.org';

	/**
	 * @var array<string,mixed>|null
	 */
	private static $catalog = null;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$client        = new Client( self::ORIGIN, 15 );
		self::$catalog = $client->get_json( '/api/v1/catalog' );
	}

	private function require_node(): array {
		if ( null === self::$catalog ) {
			$this->markTestSkipped( 'Canonical Terraviz node is unreachable; skipping live smoke test.' );
		}

		return self::$catalog;
	}

	public function test_catalog_envelope_matches_the_wire_contract(): void {
		$catalog = $this->require_node();

		foreach ( array( 'schema_version', 'generated_at', 'etag', 'datasets' ) as $field ) {
			$this->assertArrayHasKey( $field, $catalog, "Catalog envelope missing '{$field}'." );
		}
		$this->assertIsArray( $catalog['datasets'] );
		$this->assertNotEmpty( $catalog['datasets'], 'Canonical catalog is empty.' );

		$first = $catalog['datasets'][0];
		foreach ( array( 'id', 'title', 'format', 'visibility' ) as $field ) {
			$this->assertArrayHasKey( $field, $first, "Dataset missing required '{$field}'." );
		}
	}

	public function test_dataset_block_renders_from_live_data(): void {
		$catalog = $this->require_node();
		$id      = (string) $catalog['datasets'][0]['id'];
		$title   = (string) $catalog['datasets'][0]['title'];

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'terraviz/dataset' ),
			'The Terraviz Dataset block should be registered.'
		);

		$html = do_blocks(
			sprintf( '<!-- wp:terraviz/dataset {"id":%s} /-->', wp_json_encode( $id ) )
		);

		// The SSR fallback carries the real title (indexable) ...
		$this->assertStringContainsString( esc_html( $title ), $html );
		// ... and the deferred embed URL follows the grammar.
		$this->assertStringContainsString( 'data-terraviz-src=', $html );
		$this->assertStringContainsString( 'embed=1', $html );
		$this->assertStringContainsString( rawurlencode( $id ), $html );
	}

	public function test_single_dataset_endpoint_is_shaped_as_expected(): void {
		$catalog = $this->require_node();
		$id      = (string) $catalog['datasets'][0]['id'];

		$client  = new Client( self::ORIGIN, 15 );
		$dataset = $client->get_json( '/api/v1/datasets/' . rawurlencode( $id ) );

		$this->assertNotNull( $dataset, 'Single-dataset endpoint returned nothing.' );
		$this->assertSame( $id, (string) $dataset['id'] );
		$this->assertArrayHasKey( 'title', $dataset );
	}
}
