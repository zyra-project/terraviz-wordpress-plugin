<?php
/**
 * Auto-embed Terraviz dataset/tour URLs pasted into the editor.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz;

use Terraviz\Embed\Renderer;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers embed handlers so an author pasting a Terraviz dataset or tour
 * URL on its own line gets an automatic embed (Integration B). This is done
 * entirely plugin-side — no dependency on the node exposing an oEmbed
 * endpoint — and renders through the same {@see Renderer} as the blocks.
 */
final class Oembed {

	/**
	 * Register embed handlers for the configured origin. Called on `init`.
	 */
	public function register(): void {
		$origin = Options::origin();
		$host   = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		$h = preg_quote( $host, '#' );

		// Canonical path form: https://<node>/dataset/<id> .
		wp_embed_register_handler(
			'terraviz-dataset-path',
			'#^https?://' . $h . '/dataset/([^/?\#]+)#i',
			array( $this, 'handle_dataset_path' )
		);

		// Query form: https://<node>/?dataset=<id> (the slash before ? is optional).
		wp_embed_register_handler(
			'terraviz-dataset-query',
			'#^https?://' . $h . '/?\?[^\#]*\bdataset=([^&\#]+)#i',
			array( $this, 'handle_dataset_query' )
		);

		// Query form: https://<node>/?tour=<slug> (the slash before ? is optional).
		wp_embed_register_handler(
			'terraviz-tour-query',
			'#^https?://' . $h . '/?\?[^\#]*\btour=([^&\#]+)#i',
			array( $this, 'handle_tour_query' )
		);
	}

	/**
	 * Handle a `/dataset/:id` URL.
	 *
	 * @param array<int,string> $matches Regex matches.
	 * @return string
	 */
	public function handle_dataset_path( array $matches ): string {
		return $this->render_dataset( rawurldecode( $matches[1] ?? '' ) );
	}

	/**
	 * Handle a `?dataset=` URL.
	 *
	 * @param array<int,string> $matches Regex matches.
	 * @return string
	 */
	public function handle_dataset_query( array $matches ): string {
		return $this->render_dataset( rawurldecode( $matches[1] ?? '' ) );
	}

	/**
	 * Handle a `?tour=` URL.
	 *
	 * @param array<int,string> $matches Regex matches.
	 * @return string
	 */
	public function handle_tour_query( array $matches ): string {
		$slug = rawurldecode( $matches[1] ?? '' );
		if ( '' === $slug ) {
			return '';
		}

		$renderer = new Renderer();

		return $renderer->render(
			array(
				'type' => 'tour',
				'id'   => $slug,
			)
		);
	}

	/**
	 * Shared dataset render.
	 *
	 * @param string $id Dataset id.
	 */
	private function render_dataset( string $id ): string {
		if ( '' === $id ) {
			return '';
		}

		$renderer = new Renderer();

		return $renderer->render(
			array(
				'type' => 'dataset',
				'id'   => $id,
			)
		);
	}
}
