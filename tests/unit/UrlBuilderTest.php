<?php
/**
 * Tests for the embed-URL grammar composer.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Embed\UrlBuilder;

/**
 * @covers \Terraviz\Embed\UrlBuilder
 */
class UrlBuilderTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://terraviz.zyra-project.org';

	public function test_dataset_embed_is_minimal_by_default(): void {
		$url = UrlBuilder::embed( self::ORIGIN, 'dataset', 'INTERNAL_SOS_768' );

		$this->assertStringStartsWith( self::ORIGIN . '/?', $url );
		$this->assertStringContainsString( 'dataset=INTERNAL_SOS_768', $url );
		$this->assertStringContainsString( 'embed=1', $url );
		$this->assertStringNotContainsString( 'chat=1', $url );
		$this->assertStringNotContainsString( 'terrain=', $url );
	}

	public function test_view_flags_compose(): void {
		$url = UrlBuilder::embed(
			self::ORIGIN,
			'dataset',
			'abc',
			array(
				'chat'    => true,
				'terrain' => true,
				'rotate'  => true,
				'labels'  => false,
				'layout'  => 4,
			)
		);

		$this->assertStringContainsString( 'embed=1', $url );
		$this->assertStringContainsString( 'chat=1', $url );
		$this->assertStringContainsString( 'terrain=on', $url );
		$this->assertStringContainsString( 'rotate=on', $url );
		$this->assertStringContainsString( 'layout=4', $url );
		$this->assertStringNotContainsString( 'labels=', $url );
	}

	public function test_invalid_layout_is_dropped(): void {
		$url = UrlBuilder::embed( self::ORIGIN, 'dataset', 'abc', array( 'layout' => 3 ) );
		$this->assertStringNotContainsString( 'layout=', $url );
	}

	public function test_tour_selector(): void {
		$url = UrlBuilder::embed( self::ORIGIN, 'tour', 'climate-futures' );
		$this->assertStringContainsString( 'tour=climate-futures', $url );
		$this->assertStringContainsString( 'embed=1', $url );
	}

	public function test_catalog_selector_with_category(): void {
		$url = UrlBuilder::embed( self::ORIGIN, 'catalog', '', array( 'category' => 'Water' ) );
		$this->assertStringContainsString( 'catalog=true', $url );
		$this->assertStringContainsString( 'category=Water', $url );
		$this->assertStringContainsString( 'embed=1', $url );
	}

	public function test_canonical_dataset_uses_path_form(): void {
		$url = UrlBuilder::canonical( self::ORIGIN, 'dataset', 'INTERNAL_SOS_768' );
		$this->assertSame( self::ORIGIN . '/dataset/INTERNAL_SOS_768', $url );
	}

	public function test_canonical_never_carries_embed_flag(): void {
		$url = UrlBuilder::canonical( self::ORIGIN, 'tour', 'x' );
		$this->assertStringNotContainsString( 'embed=1', $url );
	}
}
