<?php
/**
 * REST endpoint that powers the block editor's dataset/tour picker.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Rest;

use Terraviz\Api\Catalog;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /wp-json/terraviz/v1/search?q=&type=` — a **same-origin** typeahead for
 * the block editor. It searches the transient-cached catalog by title / slug /
 * legacy id / id and returns lightweight rows so an author can pick a dataset
 * or tour by name instead of typing a ULID.
 *
 * Security notes:
 * - Requires `edit_posts` (the block editor's own capability) — it is not a
 *   public proxy.
 * - Reads only the **site-configured** node (via the default {@see Catalog});
 *   it deliberately accepts no caller-supplied origin, so it cannot be turned
 *   into an SSRF vector the way an arbitrary per-embed origin could.
 */
final class SearchController {

	private const NAMESPACE = 'terraviz/v1';
	private const ROUTE     = '/search';

	/**
	 * Default max rows returned by the picker.
	 *
	 * Raised from the original 20 so a large catalog (the canonical node carries
	 * 150+ datasets) is reachable through the typeahead rather than being clipped
	 * after the first handful of catalog-order rows. Filterable via
	 * `terraviz_search_limit` for sites that want a different cap.
	 */
	private const DEFAULT_LIMIT = 50;

	/**
	 * The catalog data source.
	 *
	 * @var Catalog
	 */
	private $catalog;

	/**
	 * Construct the controller.
	 *
	 * @param Catalog|null $catalog Data source; the site-default catalog when null.
	 */
	public function __construct( ?Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Catalog();
	}

	/**
	 * Register the route. Hooked on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					'q'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type' => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'dataset', 'tour', 'all' ),
						'default'  => 'all',
					),
				),
			)
		);
	}

	/**
	 * Gate the picker to users who can edit posts (Contributor and up) — the
	 * same capability the block editor itself requires. Not a public endpoint.
	 */
	public function can_search(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$q    = (string) $request->get_param( 'q' );
		$type = (string) $request->get_param( 'type' );

		/**
		 * Filter the maximum number of rows the editor picker returns.
		 *
		 * @param int $limit Default row cap.
		 */
		$limit = (int) apply_filters( 'terraviz_search_limit', self::DEFAULT_LIMIT );
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		return rest_ensure_response( $this->query( $q, $type, $limit ) );
	}

	/**
	 * Search the cached catalog. Pure enough to unit-test without the REST stack.
	 *
	 * @param string $q     Query string (matched as a case-insensitive substring).
	 * @param string $type  'dataset' | 'tour' | 'all'.
	 * @param int    $limit Max results.
	 * @return array<int,array<string,string>>
	 */
	public function query( string $q, string $type, int $limit ): array {
		$catalog = $this->catalog->get_catalog();
		if ( null === $catalog ) {
			return array();
		}

		$needle = trim( strtolower( $q ) );
		$out    = array();

		foreach ( $catalog->datasets() as $dataset ) {
			if ( true === $dataset->get( 'isHidden' ) ) {
				continue;
			}

			$format  = (string) $dataset->get( 'format', '' );
			$is_tour = ( 'tour/json' === $format );

			if ( 'tour' === $type && ! $is_tour ) {
				continue;
			}
			if ( 'dataset' === $type && $is_tour ) {
				continue;
			}

			if ( '' !== $needle ) {
				$haystack = strtolower(
					implode(
						' ',
						array(
							(string) $dataset->get( 'title', '' ),
							(string) $dataset->get( 'slug', '' ),
							(string) $dataset->get( 'legacyId', '' ),
							(string) $dataset->get( 'id', '' ),
						)
					)
				);
				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}

			$thumb = (string) $dataset->get( 'thumbnailLink', '' );

			$out[] = array(
				'id'        => (string) $dataset->get( 'id', '' ),
				'title'     => (string) $dataset->get( 'title', '' ),
				'slug'      => (string) $dataset->get( 'slug', '' ),
				'format'    => $format,
				'type'      => $is_tour ? 'tour' : 'dataset',
				'thumbnail' => preg_match( '#^https?://#i', $thumb ) ? $thumb : '',
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}//end foreach

		return $out;
	}
}
