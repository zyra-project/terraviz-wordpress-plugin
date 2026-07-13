<?php
/**
 * Block patterns for Terraviz-grounded posts (Blog slice 3).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a starter block pattern that scaffolds a Terraviz-grounded post:
 * a lead paragraph, an "Explore the data" section carrying a live dataset
 * embed (the author picks the dataset in the block's own typeahead), and a tip
 * pointing at the "Show this post in Terraviz" opt-in panel.
 *
 * This is the from-scratch mirror of the node→WP seed (slice 2): where the seed
 * imports an existing node post, this gives an author starting a brand-new
 * WordPress post the same grounding scaffold. It is content only — the pattern
 * can't flip the opt-in meta, so the tip nudges the author to the panel, which
 * {@see PostPanel} already provides.
 */
final class Patterns {

	/**
	 * Pattern-category slug (grouped under this label in the inserter).
	 */
	private const CATEGORY = 'terraviz';

	/**
	 * The starter pattern's name.
	 */
	private const PATTERN = 'terraviz/grounded-post';

	/**
	 * Wire hooks. Patterns register on `init`, once the pattern APIs exist.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
	}

	/**
	 * Register the Terraviz pattern category and the grounded-post starter.
	 */
	public function register_patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) || ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'Terraviz', 'terraviz' ) )
		);

		register_block_pattern(
			self::PATTERN,
			array(
				'title'       => __( 'Terraviz-grounded post', 'terraviz' ),
				'description' => __(
					'A starter layout for a post grounded in a Terraviz dataset: a lead paragraph, an "Explore the data" section with a live dataset embed, and a reminder to surface the post on the globe.',
					'terraviz'
				),
				'categories'  => array( self::CATEGORY ),
				'postTypes'   => array( 'post' ),
				'keywords'    => array( 'terraviz', 'dataset', 'globe', 'grounded' ),
				'content'     => $this->content(),
			)
		);
	}

	/**
	 * The pattern's block markup. Uses core paragraph/heading blocks plus one
	 * `terraviz/dataset` embed with no id, so the block editor shows its dataset
	 * picker for the author to fill in.
	 *
	 * @return string Serialized block markup.
	 */
	private function content(): string {
		$lead    = __(
			'Open with the story — what is happening, and why it matters. Then let the live data below carry the detail.',
			'terraviz'
		);
		$heading = __( 'Explore the data', 'terraviz' );
		$tip     = __(
			'Tip: search this embed for the dataset your post is about. When you are ready to surface this post on the Terraviz globe, enable "Show this post in Terraviz" in the Terraviz panel — it publishes a short, linked-back summary carrying the datasets and tours you cite here.',
			'terraviz'
		);

		return "<!-- wp:paragraph -->\n<p>" . esc_html( $lead ) . "</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $heading ) . "</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:terraviz/dataset /-->\n\n"
			. "<!-- wp:paragraph -->\n<p><em>" . esc_html( $tip ) . "</em></p>\n<!-- /wp:paragraph -->";
	}
}
