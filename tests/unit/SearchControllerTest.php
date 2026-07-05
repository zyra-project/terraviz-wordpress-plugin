<?php
/**
 * Tests for the editor picker's search endpoint (query logic, offline).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Api\Catalog;
use Terraviz\Rest\SearchController;
use Terraviz\Tests\FakeReader;

/**
 * @covers \Terraviz\Rest\SearchController
 */
class SearchControllerTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://terraviz.zyra-project.org';

	private function controller_with_catalog(): SearchController {
		$body = array(
			'schema_version' => 1,
			'generated_at'   => '2026-07-04T00:00:00Z',
			'etag'           => '"x"',
			'cursor'         => null,
			'datasets'       => array(
				array(
					'id'                => 'INTERNAL_SOS_768',
					'slug'              => 'hurricane-season-2024',
					'title'             => 'Hurricane Season 2024',
					'format'            => 'video/mp4',
					'legacyId'          => 'INTERNAL_SOS_768',
					'thumbnailLink'     => 'https://video.zyra-project.org/x/thumbnail.jpg',
					'dataLink'          => '/x',
					'originNode'        => 'N',
					'originNodeUrl'     => self::ORIGIN,
					'originDisplayName' => 'Terraviz',
					'visibility'        => 'public',
					'schemaVersion'     => 1,
					'createdAt'         => 'x',
					'updatedAt'         => 'y',
				),
				array(
					'id'                => 'TOUR_01',
					'slug'              => 'climate-futures',
					'title'             => 'Climate Futures Tour',
					'format'            => 'tour/json',
					'dataLink'          => '/x',
					'originNode'        => 'N',
					'originNodeUrl'     => self::ORIGIN,
					'originDisplayName' => 'Terraviz',
					'visibility'        => 'public',
					'schemaVersion'     => 1,
					'createdAt'         => 'x',
					'updatedAt'         => 'y',
				),
				array(
					'id'                => 'HIDDEN_01',
					'slug'              => 'hurricane-hidden',
					'title'             => 'Hurricane Hidden',
					'format'            => 'image/png',
					'isHidden'          => true,
					'dataLink'          => '/x',
					'originNode'        => 'N',
					'originNodeUrl'     => self::ORIGIN,
					'originDisplayName' => 'Terraviz',
					'visibility'        => 'public',
					'schemaVersion'     => 1,
					'createdAt'         => 'x',
					'updatedAt'         => 'y',
				),
			),
			'tombstones'     => array(),
		);

		$catalog = new Catalog( new FakeReader( self::ORIGIN, array( '/api/v1/catalog' => $body ) ), 60 );

		return new SearchController( $catalog );
	}

	public function test_matches_by_title_substring(): void {
		$results = $this->controller_with_catalog()->query( 'hurricane', 'all', 20 );

		$ids = wp_list_pluck( $results, 'id' );
		$this->assertContains( 'INTERNAL_SOS_768', $ids );
		// Hidden rows are excluded even when they match.
		$this->assertNotContains( 'HIDDEN_01', $ids );
	}

	public function test_matches_by_slug_and_legacy_id(): void {
		$this->assertNotEmpty( $this->controller_with_catalog()->query( 'climate-futures', 'all', 20 ) );
		$this->assertNotEmpty( $this->controller_with_catalog()->query( 'INTERNAL_SOS_768', 'all', 20 ) );
	}

	public function test_type_filter_tours_only(): void {
		$results = $this->controller_with_catalog()->query( '', 'tour', 20 );
		$ids     = wp_list_pluck( $results, 'id' );

		$this->assertSame( array( 'TOUR_01' ), $ids );
	}

	public function test_type_filter_datasets_excludes_tours(): void {
		$results = $this->controller_with_catalog()->query( '', 'dataset', 20 );
		$ids     = wp_list_pluck( $results, 'id' );

		$this->assertContains( 'INTERNAL_SOS_768', $ids );
		$this->assertNotContains( 'TOUR_01', $ids );
	}

	public function test_result_shape(): void {
		$results = $this->controller_with_catalog()->query( 'hurricane', 'all', 20 );
		$row     = $results[0];

		$this->assertSame( 'INTERNAL_SOS_768', $row['id'] );
		$this->assertSame( 'Hurricane Season 2024', $row['title'] );
		$this->assertSame( 'hurricane-season-2024', $row['slug'] );
		$this->assertSame( 'dataset', $row['type'] );
		$this->assertStringStartsWith( 'https://', $row['thumbnail'] );
	}

	public function test_permission_requires_edit_posts(): void {
		$controller = $this->controller_with_catalog();

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->can_search() );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( $controller->can_search() );
	}
}
