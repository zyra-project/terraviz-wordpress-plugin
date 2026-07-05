<?php
/**
 * Shared server-side renderer for every Terraviz embed surface.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Embed;

use Terraviz\Api\Catalog;
use Terraviz\Api\Client;
use Terraviz\Contract\WireDataset;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One render function shared by the Gutenberg blocks and the `[terraviz]`
 * shortcode. It satisfies Goal 4 (degrade gracefully): every embed emits a
 * real, indexable, accessible server-side fallback (title, abstract,
 * thumbnail, canonical link) from the public read API — cached in a
 * transient — and progressively enhances to a lazy iframe pointed at the
 * embed URL. Globes are heavy, so N of them never boot on page load.
 */
final class Renderer {

	/**
	 * Frontend asset handle (registered by the Plugin bootstrap).
	 */
	public const HANDLE = 'terraviz-embed';

	/**
	 * The catalog data source for the default (site-configured) origin.
	 *
	 * @var Catalog
	 */
	private $catalog;

	/**
	 * Factory that builds a Catalog for a given origin. Injectable for tests;
	 * defaults to a live-client-backed Catalog.
	 *
	 * @var callable|null
	 */
	private $catalog_factory;

	/**
	 * Construct the renderer.
	 *
	 * @param Catalog|null  $catalog         Default-origin data source; built from settings when null.
	 * @param callable|null $catalog_factory fn(string $origin): Catalog for per-embed origin overrides.
	 */
	public function __construct( ?Catalog $catalog = null, ?callable $catalog_factory = null ) {
		$this->catalog         = $catalog ?? new Catalog();
		$this->catalog_factory = $catalog_factory;
	}

	/**
	 * The catalog to read SSR data from for a given embed origin.
	 *
	 * The SSR fallback should come from the same node the iframe targets — but
	 * the per-embed `origin` attribute is author-controllable (anyone with
	 * `edit_posts`), and fetching it server-side would be an SSRF vector
	 * (arbitrary host/port, incl. internal services and cloud metadata). So we
	 * only fetch server-side from an **admin-approved** origin: the
	 * site-configured node, plus any origins a site owner adds via the
	 * `terraviz_allowed_fetch_origins` filter. An override to any other origin
	 * still drives the *iframe* (the visitor's browser, not our server), but its
	 * SSR data falls back to the trusted default node.
	 *
	 * @param string $origin Normalised node origin for this embed.
	 */
	private function catalog_for( string $origin ): Catalog {
		if ( ! $this->origin_allowed( $origin ) ) {
			// Untrusted per-embed origin: never issue a server-side request to
			// it. Read SSR data from the trusted default node instead.
			return $this->catalog;
		}

		if ( $origin === $this->catalog->origin() ) {
			return $this->catalog;
		}

		if ( null !== $this->catalog_factory ) {
			return ( $this->catalog_factory )( $origin );
		}

		return new Catalog( new Client( $origin ) );
	}

	/**
	 * Whether the plugin may issue a server-side read request to an origin.
	 *
	 * Defaults to the site-configured node only; a site owner (not a post
	 * author) can widen this to trusted partner nodes via the filter.
	 *
	 * @param string $origin Normalised node origin.
	 */
	private function origin_allowed( string $origin ): bool {
		$allowed = array( Options::origin(), $this->catalog->origin() );

		/**
		 * Filter the node origins the plugin may fetch SSR data from
		 * server-side. Add trusted partner-node origins here; never expose this
		 * to untrusted (post-author) input.
		 *
		 * @param array<int,string> $allowed Allowed origins.
		 */
		$allowed = apply_filters( 'terraviz_allowed_fetch_origins', $allowed );

		$normalized = array();
		foreach ( (array) $allowed as $candidate ) {
			$normalized[] = Options::normalize_origin( (string) $candidate );
		}

		return in_array( $origin, $normalized, true );
	}

	/**
	 * Render an embed from a normalised attributes array.
	 *
	 * @param array<string,mixed> $atts Raw attributes (from a block or shortcode).
	 * @return string HTML.
	 */
	public function render( array $atts ): string {
		$atts = $this->normalize( $atts );

		switch ( $atts['type'] ) {
			case 'tour':
				return $this->render_tour( $atts );
			case 'catalog':
				return $this->render_catalog( $atts );
			case 'hero':
				return $this->render_hero( $atts );
			case 'related':
				return $this->render_related( $atts );
			case 'dataset':
			default:
				return $this->render_dataset( $atts );
		}
	}

	/**
	 * Merge raw attributes with the configured defaults and coerce types.
	 *
	 * @param array<string,mixed> $atts Raw attributes.
	 * @return array<string,mixed>
	 */
	public function normalize( array $atts ): array {
		$type = isset( $atts['type'] ) ? (string) $atts['type'] : 'dataset';
		if ( ! in_array( $type, array( 'dataset', 'tour', 'catalog', 'hero', 'related' ), true ) ) {
			$type = 'dataset';
		}

		$origin = ! empty( $atts['origin'] ) ? Options::normalize_origin( (string) $atts['origin'] ) : Options::origin();

		return array(
			'type'         => $type,
			'origin'       => $origin,
			// One selector value: dataset id, tour slug, or '' for catalog.
			'id'           => isset( $atts['id'] ) ? sanitize_text_field( (string) $atts['id'] ) : '',
			'terrain'      => $this->flag( $atts, 'terrain', (bool) Options::get( 'default_terrain', false ) ),
			'labels'       => $this->flag( $atts, 'labels', (bool) Options::get( 'default_labels', false ) ),
			'borders'      => $this->flag( $atts, 'borders', (bool) Options::get( 'default_borders', false ) ),
			'rotate'       => $this->flag( $atts, 'rotate', (bool) Options::get( 'default_rotate', false ) ),
			'chat'         => $this->flag( $atts, 'chat', (bool) Options::get( 'default_chat', false ) ),
			'layout'       => isset( $atts['layout'] ) && in_array( (int) $atts['layout'], array( 1, 2, 4 ), true ) ? (int) $atts['layout'] : 1,
			'category'     => isset( $atts['category'] ) ? sanitize_text_field( (string) $atts['category'] ) : '',
			'aspectRatio'  => isset( $atts['aspectRatio'] ) ? Options::sanitize_aspect_ratio( (string) $atts['aspectRatio'] ) : (string) Options::get( 'aspect_ratio', '16:9' ),
			'poster'       => $this->flag( $atts, 'poster', (bool) Options::get( 'lazy_poster', true ) ),
			'interactive'  => $this->flag( $atts, 'interactive', true ),
			'heading'      => $this->heading_tag( isset( $atts['heading'] ) ? (string) $atts['heading'] : 'h3' ),
			'showTitle'    => $this->flag( $atts, 'showTitle', true ),
			'showAbstract' => $this->flag( $atts, 'showAbstract', true ),
		);
	}

	/**
	 * Render a single-dataset embed.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	private function render_dataset( array $atts ): string {
		$id = (string) $atts['id'];
		if ( '' === $id ) {
			return $this->notice( __( 'No Terraviz dataset selected.', 'terraviz' ) );
		}

		$dataset = $this->catalog_for( $atts['origin'] )->get_dataset( $id );
		// Accept a human-readable slug or legacyId: resolve to the canonical
		// catalog id for the URLs the Terraviz app consumes.
		$selector  = $this->canonical_selector( $dataset, $id );
		$embed_url = UrlBuilder::embed( $atts['origin'], 'dataset', $selector, $this->flags( $atts ) );
		$canonical = UrlBuilder::canonical( $atts['origin'], 'dataset', $selector );

		return $this->frame(
			$atts,
			$embed_url,
			$canonical,
			$this->dataset_title( $dataset, $id ),
			$this->dataset_abstract( $dataset ),
			$this->dataset_thumb( $dataset ),
			$dataset ? (array) $dataset->get( 'tags', array() ) : array()
		);
	}

	/**
	 * Render a tour embed. A tour is a catalog row whose format is
	 * 'tour/json', so its title/thumbnail come from the same read API.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	private function render_tour( array $atts ): string {
		$id = (string) $atts['id'];
		if ( '' === $id ) {
			return $this->notice( __( 'No Terraviz tour selected.', 'terraviz' ) );
		}

		$dataset   = $this->catalog_for( $atts['origin'] )->get_dataset( $id );
		$selector  = $this->canonical_selector( $dataset, $id );
		$embed_url = UrlBuilder::embed( $atts['origin'], 'tour', $selector, $this->flags( $atts ) );
		$canonical = UrlBuilder::canonical( $atts['origin'], 'tour', $selector );

		return $this->frame(
			$atts,
			$embed_url,
			$canonical,
			$this->dataset_title( $dataset, $id ),
			$this->dataset_abstract( $dataset ),
			$this->dataset_thumb( $dataset ),
			$dataset ? (array) $dataset->get( 'tags', array() ) : array()
		);
	}

	/**
	 * Render the full-catalog embed. The SSR fallback is a real, crawlable
	 * grid of dataset cards from GET /api/v1/catalog.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	private function render_catalog( array $atts ): string {
		$catalog   = $this->catalog_for( $atts['origin'] )->get_catalog();
		$embed_url = UrlBuilder::embed( $atts['origin'], 'catalog', '', $this->flags( $atts ) );
		$canonical = UrlBuilder::canonical( $atts['origin'], 'catalog', '' );

		$cards = '';
		$count = 0;
		if ( $catalog ) {
			foreach ( $catalog->datasets() as $dataset ) {
				if ( true === $dataset->get( 'isHidden' ) ) {
					continue;
				}
				$cards .= $this->catalog_card( $atts['origin'], $dataset );
				++$count;
				// Keep the SSR list bounded; the iframe shows the full browser.
				if ( $count >= 60 ) {
					break;
				}
			}
		}

		$this->enqueue();

		$fallback = '';
		if ( '' !== $cards ) {
			$fallback = '<ul class="terraviz-embed__catalog-grid">' . $cards . '</ul>';
		} else {
			$fallback = $this->notice( __( 'The Terraviz catalog is temporarily unavailable.', 'terraviz' ) );
		}

		$heading = $atts['showTitle']
			? sprintf(
				'<%1$s class="terraviz-embed__title">%2$s</%1$s>',
				$atts['heading'],
				esc_html__( 'Explore the Terraviz catalog', 'terraviz' )
			)
			: '';

		$media = $atts['interactive']
			? $this->media_html( $atts, $embed_url, esc_html__( 'the Terraviz catalog browser', 'terraviz' ), '' )
			: '';

		return sprintf(
			'<figure class="terraviz-embed terraviz-embed--catalog" data-terraviz="1">%1$s<figcaption class="terraviz-embed__body">%2$s<div class="terraviz-embed__catalog-fallback">%3$s</div><p class="terraviz-embed__meta"><a class="terraviz-embed__link" href="%4$s" rel="noopener">%5$s</a></p></figcaption></figure>',
			$media,
			$heading,
			$fallback,
			esc_url( $canonical ),
			esc_html__( 'Open the full catalog on Terraviz ↗', 'terraviz' )
		);
	}

	/**
	 * Render the "right now" hero embed: the node's curated featured dataset,
	 * shown as a full dataset embed. Degrades to a notice when no hero is set.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	private function render_hero( array $atts ): string {
		$hero = $this->catalog_for( $atts['origin'] )->get_featured_hero();
		$id   = ( is_array( $hero ) && ! empty( $hero['id'] ) ) ? (string) $hero['id'] : '';

		if ( '' === $id ) {
			return $this->notice( __( 'No featured Terraviz dataset right now.', 'terraviz' ) );
		}

		// Render exactly like a dataset embed, with the hero's id.
		$atts['id'] = $id;

		return $this->render_dataset( $atts );
	}

	/**
	 * Render a "more like this" rail of related-dataset cards from
	 * GET /api/v1/datasets/:id/related. SSR-only (links, no globe).
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	private function render_related( array $atts ): string {
		$id = (string) $atts['id'];
		if ( '' === $id ) {
			return $this->notice( __( 'No Terraviz dataset selected for related items.', 'terraviz' ) );
		}

		$catalog = $this->catalog_for( $atts['origin'] );
		$rows    = $catalog->get_related( $id );

		if ( empty( $rows ) ) {
			return $this->notice( __( 'No related Terraviz datasets found.', 'terraviz' ) );
		}

		$cards = '';
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$rid      = (string) $row['id'];
			$title    = isset( $row['title'] ) ? (string) $row['title'] : $rid;
			$abstract = isset( $row['abstract_snippet'] ) ? $this->plain_snippet( (string) $row['abstract_snippet'] ) : '';
			$href     = UrlBuilder::canonical( $atts['origin'], 'dataset', $rid );
			$thumb    = $this->dataset_thumb( $catalog->find_in_catalog( $rid ) );

			$cards .= $this->related_card( $href, $title, $thumb, $abstract );
			++$count;
			if ( $count >= 12 ) {
				break;
			}
		}

		// The response had rows but none were usable (e.g. all missing an id):
		// show the notice rather than an empty rail.
		if ( '' === $cards ) {
			return $this->notice( __( 'No related Terraviz datasets found.', 'terraviz' ) );
		}

		// Only load frontend assets once we know a rail will actually render.
		$this->enqueue();

		$heading = $atts['showTitle']
			? sprintf(
				'<%1$s class="terraviz-embed__title">%2$s</%1$s>',
				$atts['heading'],
				esc_html__( 'Related datasets', 'terraviz' )
			)
			: '';

		return sprintf(
			'<div class="terraviz-embed terraviz-embed--related" data-terraviz="1">%1$s<ul class="terraviz-embed__rail">%2$s</ul></div>',
			$heading,
			$cards
		);
	}

	/**
	 * A single related-rail card.
	 *
	 * @param string $href          Canonical dataset URL.
	 * @param string $title         Dataset title.
	 * @param string $thumb         Thumbnail URL or ''.
	 * @param string $abstract_text Short plain-text snippet.
	 */
	private function related_card( string $href, string $title, string $thumb, string $abstract_text ): string {
		$img = '';
		if ( '' !== $thumb ) {
			$img = sprintf(
				'<img class="terraviz-embed__card-thumb" src="%s" alt="" loading="lazy" decoding="async" />',
				esc_url( $thumb )
			);
		}

		$abs = '';
		if ( '' !== $abstract_text ) {
			$abs = sprintf( '<span class="terraviz-embed__rail-abstract">%s</span>', esc_html( $abstract_text ) );
		}

		return sprintf(
			'<li class="terraviz-embed__rail-item"><a href="%1$s" rel="noopener"><span class="terraviz-embed__card">%2$s<span class="terraviz-embed__card-title">%3$s</span></span>%4$s</a></li>',
			esc_url( $href ),
			$img,
			esc_html( $title ),
			$abs
		);
	}

	/**
	 * Flatten a markdown-ish snippet to a short plain-text string: strip
	 * `[label](url)` to `label`, drop any other markup, and truncate.
	 *
	 * @param string $text Raw snippet.
	 */
	private function plain_snippet( string $text ): string {
		$text = preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $this->truncate( $text, 140 );
	}

	/**
	 * Truncate to a length on a word boundary, adding an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max characters.
	 */
	private function truncate( string $text, int $limit ): string {
		// Use the mb_* family throughout when it's available, so a mid-string
		// cut never splits a multibyte character.
		$has_mb = function_exists( 'mb_strlen' );

		if ( $has_mb ? mb_strlen( $text ) <= $limit : strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut = $has_mb ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
		$sp  = $has_mb ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 0 ) {
			$cut = $has_mb ? mb_substr( $cut, 0, $sp ) : substr( $cut, 0, $sp );
		}

		return $cut . '…';
	}

	/**
	 * The shared figure: an optional interactive media area plus the always-
	 * present textual fallback/caption.
	 *
	 * @param array<string,mixed> $atts      Normalised attributes.
	 * @param string              $embed_url Embed iframe URL.
	 * @param string              $canonical Human-facing canonical URL.
	 * @param string              $title         Dataset/tour title (plain text).
	 * @param string              $abstract_text Abstract (plain text).
	 * @param string              $thumb         Thumbnail URL or ''.
	 * @param array<int,mixed>    $tags          Tag strings.
	 */
	private function frame( array $atts, string $embed_url, string $canonical, string $title, string $abstract_text, string $thumb, array $tags ): string {
		$this->enqueue();

		$media = $atts['interactive']
			? $this->media_html( $atts, $embed_url, $title, $thumb )
			: $this->static_thumb( $thumb, $canonical, $title );

		$body = $this->caption_html( $atts, $canonical, $title, $abstract_text, $tags );

		$classes = 'terraviz-embed terraviz-embed--' . $atts['type'];

		return sprintf(
			'<figure class="%1$s" data-terraviz="1">%2$s<figcaption class="terraviz-embed__body">%3$s</figcaption></figure>',
			esc_attr( $classes ),
			$media,
			$body
		);
	}

	/**
	 * The interactive media area: a click-to-load / lazy poster that the
	 * frontend script swaps for the globe iframe.
	 *
	 * @param array<string,mixed> $atts      Normalised attributes.
	 * @param string              $embed_url Embed iframe URL.
	 * @param string              $title     Human title for a11y labels.
	 * @param string              $thumb     Thumbnail URL or ''.
	 */
	private function media_html( array $atts, string $embed_url, string $title, string $thumb ): string {
		$mode  = $atts['poster'] ? 'poster' : 'lazy';
		$ratio = $this->aspect_css( (string) $atts['aspectRatio'] );

		/* translators: %s: dataset or tour title. */
		$load_label = sprintf( __( 'Load the interactive Terraviz globe for %s', 'terraviz' ), $title );

		$thumb_html = '';
		if ( '' !== $thumb ) {
			$thumb_html = sprintf(
				'<img class="terraviz-embed__thumb" src="%1$s" alt="" loading="lazy" decoding="async" />',
				esc_url( $thumb )
			);
		}

		$button = sprintf(
			'<button type="button" class="terraviz-embed__load" aria-label="%1$s">%2$s<span class="terraviz-embed__play" aria-hidden="true"></span><span class="terraviz-embed__hint">%3$s</span></button>',
			esc_attr( $load_label ),
			$thumb_html,
			esc_html__( 'Load interactive globe', 'terraviz' )
		);

		/* translators: %s: dataset or tour title. */
		$iframe_title = sprintf( __( 'Interactive Terraviz globe: %s', 'terraviz' ), $title );

		return sprintf(
			'<div class="terraviz-embed__media" style="%1$s" data-terraviz-src="%2$s" data-terraviz-mode="%3$s" data-terraviz-title="%4$s">%5$s</div>',
			esc_attr( $ratio ),
			esc_url( $embed_url ),
			esc_attr( $mode ),
			esc_attr( $iframe_title ),
			$button
		);
	}

	/**
	 * A non-interactive thumbnail linking to the canonical page (used when a
	 * block opts out of the live globe).
	 *
	 * @param string $thumb     Thumbnail URL or ''.
	 * @param string $canonical Human-facing canonical URL.
	 * @param string $title     Dataset/tour title (plain text).
	 */
	private function static_thumb( string $thumb, string $canonical, string $title ): string {
		if ( '' === $thumb ) {
			return '';
		}

		/* translators: %s: dataset or tour title. */
		$alt = sprintf( __( 'Thumbnail for %s', 'terraviz' ), $title );

		return sprintf(
			'<a class="terraviz-embed__media terraviz-embed__media--static" href="%1$s" rel="noopener"><img class="terraviz-embed__thumb" src="%2$s" alt="%3$s" loading="lazy" decoding="async" /></a>',
			esc_url( $canonical ),
			esc_url( $thumb ),
			esc_attr( $alt )
		);
	}

	/**
	 * The textual caption/fallback: heading, abstract, tags, canonical link.
	 *
	 * @param array<string,mixed> $atts      Normalised attributes.
	 * @param string              $canonical Human-facing canonical URL.
	 * @param string              $title         Dataset/tour title (plain text).
	 * @param string              $abstract_text Abstract (plain text).
	 * @param array<int,mixed>    $tags          Tag strings.
	 */
	private function caption_html( array $atts, string $canonical, string $title, string $abstract_text, array $tags ): string {
		$out = '';

		if ( $atts['showTitle'] ) {
			$out .= sprintf(
				'<%1$s class="terraviz-embed__title"><a href="%2$s" rel="noopener">%3$s</a></%1$s>',
				$atts['heading'],
				esc_url( $canonical ),
				esc_html( $title )
			);
		}

		if ( $atts['showAbstract'] && '' !== $abstract_text ) {
			$out .= sprintf( '<p class="terraviz-embed__abstract">%s</p>', esc_html( $abstract_text ) );
		}

		$tag_html = $this->tags_html( $tags );
		if ( '' !== $tag_html ) {
			$out .= $tag_html;
		}

		$out .= sprintf(
			'<p class="terraviz-embed__meta"><a class="terraviz-embed__link" href="%1$s" rel="noopener">%2$s</a></p>',
			esc_url( $canonical ),
			esc_html__( 'View on Terraviz ↗', 'terraviz' )
		);

		return $out;
	}

	/**
	 * Render a bounded tag list.
	 *
	 * @param array<int,mixed> $tags Tag strings.
	 */
	private function tags_html( array $tags ): string {
		$clean = array();
		foreach ( $tags as $tag ) {
			if ( is_string( $tag ) && '' !== trim( $tag ) ) {
				$clean[] = trim( $tag );
			}
			if ( count( $clean ) >= 8 ) {
				break;
			}
		}

		if ( empty( $clean ) ) {
			return '';
		}

		$items = '';
		foreach ( $clean as $tag ) {
			$items .= sprintf( '<li class="terraviz-embed__tag">%s</li>', esc_html( $tag ) );
		}

		return sprintf(
			'<ul class="terraviz-embed__tags" aria-label="%s">%s</ul>',
			esc_attr__( 'Dataset categories', 'terraviz' ),
			$items
		);
	}

	/**
	 * A single catalog card for the SSR catalog grid.
	 *
	 * @param string      $origin  Node origin.
	 * @param WireDataset $dataset The dataset to render.
	 */
	private function catalog_card( string $origin, WireDataset $dataset ): string {
		$id    = (string) $dataset->get( 'id', '' );
		$title = $this->dataset_title( $dataset, $id );
		$thumb = $this->dataset_thumb( $dataset );
		$href  = UrlBuilder::canonical( $origin, 'dataset', $id );

		$img = '';
		if ( '' !== $thumb ) {
			$img = sprintf(
				'<img class="terraviz-embed__card-thumb" src="%s" alt="" loading="lazy" decoding="async" />',
				esc_url( $thumb )
			);
		}

		return sprintf(
			'<li class="terraviz-embed__card"><a href="%1$s" rel="noopener">%2$s<span class="terraviz-embed__card-title">%3$s</span></a></li>',
			esc_url( $href ),
			$img,
			esc_html( $title )
		);
	}

	/**
	 * View-flag map passed to the URL builder.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 * @return array<string,mixed>
	 */
	private function flags( array $atts ): array {
		return array(
			'terrain'  => $atts['terrain'],
			'labels'   => $atts['labels'],
			'borders'  => $atts['borders'],
			'rotate'   => $atts['rotate'],
			'chat'     => $atts['chat'],
			'layout'   => $atts['layout'],
			'category' => $atts['category'],
		);
	}

	/**
	 * The value to place in the embed / canonical URL for a selector.
	 *
	 * Authors may supply a human-readable slug or a legacyId; when the catalog
	 * resolves the row we substitute its canonical catalog id (the shape the
	 * Terraviz app reliably deep-links on). When the catalog is unreachable we
	 * pass the author's value through unchanged (it already works if it was an
	 * id, and degrades gracefully otherwise).
	 *
	 * @param WireDataset|null $dataset The resolved dataset, if any.
	 * @param string           $raw     The author-supplied selector.
	 */
	private function canonical_selector( ?WireDataset $dataset, string $raw ): string {
		if ( $dataset ) {
			$id = (string) $dataset->get( 'id', '' );
			if ( '' !== $id ) {
				return $id;
			}
		}

		return $raw;
	}

	/**
	 * Resolve a display title, falling back to the id when unresolved.
	 *
	 * @param WireDataset|null $dataset The dataset, if resolved.
	 * @param string           $id      Selector id, used as the fallback title.
	 */
	private function dataset_title( ?WireDataset $dataset, string $id ): string {
		if ( $dataset && '' !== (string) $dataset->get( 'title', '' ) ) {
			return (string) $dataset->get( 'title' );
		}

		return $id;
	}

	/**
	 * Resolve an abstract, preferring the human abstract then the enriched
	 * description.
	 *
	 * @param WireDataset|null $dataset The dataset, if resolved.
	 */
	private function dataset_abstract( ?WireDataset $dataset ): string {
		if ( ! $dataset ) {
			return '';
		}

		$abstract = (string) $dataset->get( 'abstractTxt', '' );
		if ( '' !== $abstract ) {
			return $abstract;
		}

		$enriched = $dataset->get( 'enriched', array() );
		if ( is_array( $enriched ) && ! empty( $enriched['description'] ) ) {
			return (string) $enriched['description'];
		}

		return '';
	}

	/**
	 * Resolve a thumbnail URL, only accepting an absolute http(s) URL.
	 *
	 * @param WireDataset|null $dataset The dataset, if resolved.
	 */
	private function dataset_thumb( ?WireDataset $dataset ): string {
		if ( ! $dataset ) {
			return '';
		}

		$thumb = (string) $dataset->get( 'thumbnailLink', '' );

		return preg_match( '#^https?://#i', $thumb ) ? $thumb : '';
	}

	/**
	 * Convert a `W:H` ratio to an `aspect-ratio` CSS declaration.
	 *
	 * @param string $ratio Aspect ratio like "16:9".
	 */
	private function aspect_css( string $ratio ): string {
		$ratio = Options::sanitize_aspect_ratio( $ratio );
		$parts = explode( ':', $ratio );
		$w     = isset( $parts[0] ) ? (int) $parts[0] : 16;
		$h     = isset( $parts[1] ) ? (int) $parts[1] : 9;
		if ( $w < 1 || $h < 1 ) {
			$w = 16;
			$h = 9;
		}

		return sprintf( 'aspect-ratio:%d/%d', $w, $h );
	}

	/**
	 * Whitelist a heading tag.
	 *
	 * @param string $tag Requested heading tag.
	 */
	private function heading_tag( string $tag ): string {
		$tag = strtolower( trim( $tag ) );

		return in_array( $tag, array( 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'h3';
	}

	/**
	 * Read a boolean-ish attribute with a default.
	 *
	 * @param array<string,mixed> $atts     Attributes.
	 * @param string              $key      Attribute key.
	 * @param bool                $fallback Value used when the attribute is unset.
	 */
	private function flag( array $atts, string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $atts ) ) {
			return $fallback;
		}

		$value = $atts[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}

		return (bool) $value;
	}

	/**
	 * An accessible inline notice for empty/error states.
	 *
	 * @param string $message Message text.
	 */
	private function notice( string $message ): string {
		return sprintf(
			'<p class="terraviz-embed terraviz-embed--notice" role="note">%s</p>',
			esc_html( $message )
		);
	}

	/**
	 * Register + enqueue the frontend assets on demand.
	 */
	private function enqueue(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}
}
