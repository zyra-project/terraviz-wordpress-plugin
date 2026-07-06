<?php
/**
 * Tests for the publisher dashboard REST proxy.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Rest\PublisherController;
use Terraviz\Support\Capabilities;

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

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant();
		$this->controller = new PublisherController();
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
}
