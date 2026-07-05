<?php
/**
 * Registers the Terraviz Gutenberg blocks (dynamic, PHP-rendered).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz;

use Terraviz\Embed\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Dataset, Tour, and Catalog blocks. Each is a dynamic block
 * whose render_callback delegates to the shared {@see Renderer} — the exact
 * same code path the shortcode uses — so SSR fallback + progressive iframe
 * behaviour is identical everywhere.
 */
final class Blocks {

	/**
	 * Block name (without the `terraviz/` namespace) => embed type.
	 *
	 * @var array<string,string>
	 */
	private const BLOCKS = array(
		'dataset' => 'dataset',
		'tour'    => 'tour',
		'catalog' => 'catalog',
	);

	/**
	 * Register every block. Called on `init`.
	 */
	public function register(): void {
		foreach ( self::BLOCKS as $name => $type ) {
			$dir = $this->metadata_dir( $name );
			if ( null === $dir ) {
				continue;
			}

			register_block_type(
				$dir,
				array(
					'render_callback' => $this->render_callback( $type ),
				)
			);
		}
	}

	/**
	 * Locate a block's metadata directory, preferring the compiled build
	 * output and falling back to the source (so server rendering and tests
	 * work even before `npm run build`).
	 *
	 * @param string $name Block name without the `terraviz/` namespace.
	 */
	private function metadata_dir( string $name ): ?string {
		$build  = TERRAVIZ_PLUGIN_DIR . 'build/' . $name;
		$source = TERRAVIZ_PLUGIN_DIR . 'blocks/' . $name;

		if ( is_readable( $build . '/block.json' ) ) {
			return $build;
		}
		if ( is_readable( $source . '/block.json' ) ) {
			return $source;
		}

		return null;
	}

	/**
	 * Build a render callback bound to a given embed type.
	 *
	 * @param string $type Embed type (dataset|tour|catalog).
	 * @return callable
	 */
	private function render_callback( string $type ): callable {
		return static function ( $attributes ) use ( $type ): string {
			$attributes         = is_array( $attributes ) ? $attributes : array();
			$attributes['type'] = $type;

			$renderer = new Renderer();

			return $renderer->render( $attributes );
		};
	}
}
