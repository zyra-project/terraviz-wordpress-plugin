<?php
/**
 * Tests for the transient-cached Catalog reader, focused on the compressed
 * cache encoding (no network — canned reader).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Catalog;
use Terraviz\Tests\FakeReader;

/**
 * @covers \Terraviz\Api\Catalog
 */
class CatalogTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://terraviz.zyra-project.org';

	/**
	 * A catalog envelope with enough datasets to cross the compression
	 * threshold, so the stored transient exercises the gzip path.
	 *
	 * @param int $count Number of datasets to synthesise.
	 * @return array<string,mixed>
	 */
	private function catalog_body( int $count ): array {
		$datasets = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$datasets[] = array(
				'id'                => 'DATASET_' . $i,
				'slug'              => 'dataset-' . $i,
				'title'             => 'Dataset number ' . $i,
				'format'            => 'video/mp4',
				'abstractTxt'       => str_repeat( 'Lorem ipsum dolor sit amet. ', 8 ),
				'dataLink'          => '/api/v1/datasets/DATASET_' . $i . '/manifest',
				'thumbnailLink'     => self::ORIGIN . '/thumb/' . $i . '.jpg',
				'originNode'        => 'NODE',
				'originNodeUrl'     => self::ORIGIN,
				'originDisplayName' => 'Terraviz',
				'visibility'        => 'public',
				'schemaVersion'     => 1,
				'createdAt'         => 'x',
				'updatedAt'         => 'y',
			);
		}

		return array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-05T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => '2026-07-05T00:00:00Z',
			'datasets'       => $datasets,
			'tombstones'     => array(),
		);
	}

	private function reader( array $body ): FakeReader {
		return new FakeReader( self::ORIGIN, array( '/api/v1/catalog' => $body ) );
	}

	public function test_catalog_round_trips_through_the_cache(): void {
		$body   = $this->catalog_body( 150 );
		$reader = $this->reader( $body );
		$cat    = new Catalog( $reader, 600 );

		$first = $cat->get_catalog();
		$this->assertNotNull( $first );
		$this->assertCount( 150, $first->datasets() );

		// A second read is served from the transient — the reader isn't hit again.
		$second = new Catalog( $reader, 600 );
		$again  = $second->get_catalog();
		$this->assertNotNull( $again );
		$this->assertCount( 150, $again->datasets() );
		$this->assertSame( 1, $reader->call_count, 'Second get_catalog() must hit the cache, not the reader.' );
	}

	public function test_large_catalog_is_stored_compressed_not_as_an_array(): void {
		$body   = $this->catalog_body( 150 );
		$reader = $this->reader( $body );
		$cat    = new Catalog( $reader, 600 );
		$cat->get_catalog();

		$key    = $this->transient_key( $cat, 'catalog' );
		$stored = get_transient( $key );

		// The old code stored the decoded array (which serialises large enough to
		// blow an object cache's item limit). It must now be a compact string.
		$this->assertIsString( $stored );

		if ( function_exists( 'gzcompress' ) ) {
			// With zlib the large payload takes the compressed path...
			$this->assertStringStartsWith( 'tvz-gz1:', $stored );

			// ...and must be markedly smaller than the raw JSON it represents.
			$raw_json = (string) wp_json_encode( $body );
			$this->assertLessThan( strlen( $raw_json ), strlen( $stored ) );
		} else {
			// Without zlib the code correctly falls back to the raw format.
			$this->assertStringStartsWith( 'tvz-rw1:', $stored );
		}
	}

	public function test_negative_result_is_cached_and_not_refetched(): void {
		// No '/api/v1/catalog' response seeded → reader returns null.
		$reader = new FakeReader( self::ORIGIN, array() );
		$cat    = new Catalog( $reader, 600 );

		$this->assertNull( $cat->get_catalog() );
		$this->assertNull( ( new Catalog( $reader, 600 ) )->get_catalog() );
		$this->assertSame( 1, $reader->call_count, 'A cached negative result must not re-hit the reader.' );
	}

	public function test_legacy_uncompressed_array_cache_is_honoured(): void {
		$body   = $this->catalog_body( 3 );
		$reader = $this->reader( $body );
		$cat    = new Catalog( $reader, 600 );

		// Simulate a pre-compression cache entry: the decoded array stored directly.
		set_transient( $this->transient_key( $cat, 'catalog' ), $body, 600 );

		$result = $cat->get_catalog();
		$this->assertNotNull( $result );
		$this->assertCount( 3, $result->datasets() );
		$this->assertSame( 0, $reader->call_count, 'A legacy array cache must be read without hitting the reader.' );
	}

	/**
	 * Reach the private, origin-scoped transient key so a test can inspect or
	 * seed the exact stored entry.
	 */
	private function transient_key( Catalog $cat, string $kind ): string {
		$method = new ReflectionMethod( Catalog::class, 'key' );
		$method->setAccessible( true );

		return (string) $method->invoke( $cat, $kind, '' );
	}
}
