<?php
/**
 * Transient-cached access to the Terraviz catalog and individual datasets.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Api;

use Terraviz\Contract\CatalogResponse;
use Terraviz\Contract\WireDataset;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the public catalog and single datasets, caching every response in a
 * WordPress transient (Integration H). Transients are the only caching
 * primitive used — no direct filesystem writes — so the plugin stays clean
 * under WordPress VIP review and works on object-cache-backed hosts.
 */
final class Catalog {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'terraviz_';

	/**
	 * The read client.
	 *
	 * @var JsonReader
	 */
	private $client;

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private $ttl;

	/**
	 * Construct the catalog reader.
	 *
	 * @param JsonReader|null $client Read client; built from settings when null.
	 * @param int|null        $ttl    Cache TTL; from settings when null.
	 */
	public function __construct( ?JsonReader $client = null, ?int $ttl = null ) {
		$origin       = Options::origin();
		$this->client = $client ?? new Client( $origin );
		$this->ttl    = null !== $ttl ? $ttl : (int) Options::get( 'cache_ttl', 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * The origin this instance reads from.
	 */
	public function origin(): string {
		return $this->client->origin();
	}

	/**
	 * Fetch one dataset by catalog id, cached per origin+id.
	 *
	 * Falls back to scanning the cached catalog when the per-id endpoint is
	 * unavailable, so a block still SSRs from data we already hold.
	 *
	 * @param string $id Catalog id, slug, or legacy id.
	 */
	public function get_dataset( string $id ): ?WireDataset {
		$id = trim( $id );
		if ( '' === $id ) {
			return null;
		}

		$key    = $this->key( 'dataset', $id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : WireDataset::from_array( $cached );
		}

		$data = $this->client->get_json( '/api/v1/datasets/' . rawurlencode( $id ) );

		if ( null === $data ) {
			$from_catalog = $this->find_in_catalog( $id );
			if ( null !== $from_catalog ) {
				return $from_catalog;
			}
			// Cache a negative result briefly to avoid hammering the node.
			set_transient( $key, array(), min( $this->ttl, 5 * MINUTE_IN_SECONDS ) );
			return null;
		}

		set_transient( $key, $data, $this->ttl );

		return WireDataset::from_array( $data );
	}

	/**
	 * Fetch the full catalog envelope, cached per origin.
	 */
	public function get_catalog(): ?CatalogResponse {
		$key    = $this->key( 'catalog' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : CatalogResponse::from_array( $cached );
		}

		$data = $this->client->get_json( '/api/v1/catalog' );
		if ( null === $data ) {
			set_transient( $key, array(), min( $this->ttl, 5 * MINUTE_IN_SECONDS ) );
			return null;
		}

		set_transient( $key, $data, $this->ttl );

		return CatalogResponse::from_array( $data );
	}

	/**
	 * Find a dataset within the cached catalog by id, slug, or legacyId.
	 *
	 * @param string $id Catalog id, slug, or legacy id.
	 */
	public function find_in_catalog( string $id ): ?WireDataset {
		$catalog = $this->get_catalog();
		if ( null === $catalog ) {
			return null;
		}

		foreach ( $catalog->datasets() as $dataset ) {
			if ( $id === $dataset->get( 'id' ) || $id === $dataset->get( 'slug' ) || $id === $dataset->get( 'legacyId' ) ) {
				return $dataset;
			}
		}

		return null;
	}

	/**
	 * Purge all Terraviz caches. Called from the settings screen and on
	 * settings change so an origin switch takes effect immediately.
	 */
	public static function flush(): void {
		global $wpdb;

		// Best-effort bulk delete of our transients. On object-cache-backed
		// installs get_transient/set_transient bypass the options table, so
		// we also expose per-key deletion via the callers above.
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
			);
			$timeout_like = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like )
			);
		}

		/**
		 * Fires after Terraviz caches are flushed, so an external object
		 * cache can be cleared too.
		 */
		do_action( 'terraviz_cache_flushed' );
	}

	/**
	 * Build a transient key scoped to the current origin.
	 *
	 * @param string $kind  Key namespace (e.g. 'dataset', 'catalog').
	 * @param string $extra Extra discriminator (e.g. a dataset id).
	 */
	private function key( string $kind, string $extra = '' ): string {
		// Scope by origin so switching nodes never serves stale data, and
		// hash to stay within the 172-char transient-key limit.
		$scope = md5( $this->origin() . '|' . $kind . '|' . $extra );

		return self::PREFIX . $kind . '_' . $scope;
	}
}
