<?php
/**
 * Tests for the Terraviz-grounded-post block pattern (Blog slice 3).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

/**
 * @covers \Terraviz\Blog\Patterns
 */
class BlogPatternsTest extends WP_UnitTestCase {

	public function test_grounded_post_pattern_is_registered(): void {
		// The plugin registers the pattern on `init`, which has fired by now.
		$registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'terraviz/grounded-post' ) );

		$pattern = $registry->get_registered( 'terraviz/grounded-post' );
		$this->assertContains( 'terraviz', $pattern['categories'] );
		// Scoped to standard posts (the blog authoring surface).
		$this->assertContains( 'post', $pattern['postTypes'] );
	}

	public function test_pattern_seeds_a_dataset_embed_and_heading(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'terraviz/grounded-post' );
		$content = (string) $pattern['content'];

		// A live dataset block with no id — the editor shows its picker so the
		// author chooses the dataset.
		$this->assertStringContainsString( '<!-- wp:terraviz/dataset /-->', $content );
		// An "Explore the data" section heading.
		$this->assertStringContainsString( '<!-- wp:heading -->', $content );
		$this->assertStringContainsString( '<h2', $content );
	}

	public function test_terraviz_pattern_category_is_registered(): void {
		$this->assertTrue(
			WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'terraviz' )
		);
	}
}
