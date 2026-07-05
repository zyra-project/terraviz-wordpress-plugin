<?php
/**
 * The [terraviz] shortcode — the Classic Editor compatibility path.
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
 * Registers `[terraviz ...]`, sharing one render function with the blocks.
 * Gutenberg leads; this is the compatibility fallback for Classic Editor and
 * older gov installs (WordPress Integration Plan §3.4).
 *
 * Examples:
 *   [terraviz dataset="INTERNAL_SOS_768" terrain="on" rotate="on"]
 *   [terraviz tour="climate-futures"]
 *   [terraviz catalog="true"]
 */
final class Shortcode {

	/**
	 * Register the shortcode. Called on `init`.
	 */
	public function register(): void {
		add_shortcode( 'terraviz', array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string,mixed>|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'dataset'       => '',
				'tour'          => '',
				'catalog'       => '',
				'origin'        => '',
				'terrain'       => '',
				'labels'        => '',
				'borders'       => '',
				'rotate'        => '',
				'chat'          => '',
				'layout'        => '',
				'category'      => '',
				'aspect'        => '',
				'poster'        => '',
				'interactive'   => '',
				'heading'       => '',
				'show_title'    => '',
				'show_abstract' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'terraviz'
		);

		// Determine selector: dataset > tour > catalog (grammar precedence).
		$type = 'dataset';
		$id   = '';
		if ( '' !== (string) $atts['dataset'] ) {
			$type = 'dataset';
			$id   = (string) $atts['dataset'];
		} elseif ( '' !== (string) $atts['tour'] ) {
			$type = 'tour';
			$id   = (string) $atts['tour'];
		} elseif ( '' !== (string) $atts['catalog'] && ! in_array( strtolower( (string) $atts['catalog'] ), array( 'false', '0', 'no', 'off' ), true ) ) {
			$type = 'catalog';
		}

		$mapped = array(
			'type'        => $type,
			'id'          => $id,
			'origin'      => $atts['origin'],
			'terrain'     => $atts['terrain'],
			'labels'      => $atts['labels'],
			'borders'     => $atts['borders'],
			'rotate'      => $atts['rotate'],
			'chat'        => $atts['chat'],
			'layout'      => $atts['layout'],
			'category'    => $atts['category'],
			'aspectRatio' => $atts['aspect'],
			'heading'     => $atts['heading'],
		);

		// Only override these booleans when the author actually set them.
		if ( '' !== (string) $atts['poster'] ) {
			$mapped['poster'] = $atts['poster'];
		}
		if ( '' !== (string) $atts['interactive'] ) {
			$mapped['interactive'] = $atts['interactive'];
		}
		if ( '' !== (string) $atts['show_title'] ) {
			$mapped['showTitle'] = $atts['show_title'];
		}
		if ( '' !== (string) $atts['show_abstract'] ) {
			$mapped['showAbstract'] = $atts['show_abstract'];
		}

		$renderer = new Renderer();

		return $renderer->render( $mapped );
	}
}
