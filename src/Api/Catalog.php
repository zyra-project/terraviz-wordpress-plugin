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
	 * Sentinel returned by {@see read_cache()} for an absent key, so a genuine
	 * cache miss is distinguishable from a cached empty/negative payload.
	 */
	private const CACHE_MISS = "\0terraviz_cache_miss\0";

	/**
	 * Encoding tags for a stored payload. The trailing digit is a format
	 * version — bump it to invalidate every cached value after a format change.
	 */
	private const ENC_GZIP = 'tvz-gz1:';
	private const ENC_RAW  = 'tvz-rw1:';

	/**
	 * Only compress payloads at least this many bytes of JSON; smaller values
	 * (single datasets, negative markers) are stored raw, since gzip + base64
	 * would only inflate them.
	 */
	private const COMPRESS_MIN_BYTES = 2048;

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
		$cached = $this->read_cache( $key );
		if ( self::CACHE_MISS !== $cached ) {
			return empty( $cached ) ? null : WireDataset::from_array( $cached );
		}

		$data = $this->client->get_json( '/api/v1/datasets/' . rawurlencode( $id ) );

		if ( null === $data ) {
			$from_catalog = $this->find_in_catalog( $id );
			if ( null !== $from_catalog ) {
				return $from_catalog;
			}
			// Cache a negative result briefly to avoid hammering the node.
			$this->write_cache( $key, array(), min( $this->ttl, 5 * MINUTE_IN_SECONDS ) );
			return null;
		}

		$this->write_cache( $key, $data, $this->ttl );

		return WireDataset::from_array( $data );
	}

	/**
	 * Fetch the full catalog envelope, cached per origin.
	 */
	public function get_catalog(): ?CatalogResponse {
		$key    = $this->key( 'catalog' );
		$cached = $this->read_cache( $key );
		if ( self::CACHE_MISS !== $cached ) {
			return empty( $cached ) ? null : CatalogResponse::from_array( $cached );
		}

		$data = $this->client->get_json( '/api/v1/catalog' );
		if ( null === $data ) {
			$this->write_cache( $key, array(), min( $this->ttl, 5 * MINUTE_IN_SECONDS ) );
			return null;
		}

		$this->write_cache( $key, $data, $this->ttl );

		return CatalogResponse::from_array( $data );
	}

	/**
	 * Fetch the curated "right now" hero dataset, cached per origin.
	 *
	 * Returns the decoded `hero` object (a WireDataset-shaped array) or null
	 * when the node has no hero configured.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_featured_hero(): ?array {
		$key    = $this->key( 'hero' );
		$cached = $this->read_cache( $key );
		if ( self::CACHE_MISS !== $cached ) {
			return empty( $cached ) ? null : $cached;
		}

		$data = $this->client->get_json( '/api/v1/featured-hero' );
		$hero = ( is_array( $data ) && isset( $data['hero'] ) && is_array( $data['hero'] ) ) ? $data['hero'] : null;

		$this->write_cache( $key, null === $hero ? array() : $hero, min( $this->ttl, 5 * MINUTE_IN_SECONDS ) );

		return $hero;
	}

	/**
	 * Fetch "more like this" related datasets for an id, cached per origin+id.
	 *
	 * Each row is the lightweight related shape (`id`, `title`,
	 * `abstract_snippet`, `categories`, …), not a full WireDataset.
	 *
	 * @param string $id Catalog id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_related( string $id ): array {
		$id = trim( $id );
		if ( '' === $id ) {
			return array();
		}

		$key    = $this->key( 'related', $id );
		$cached = $this->read_cache( $key );
		if ( self::CACHE_MISS !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$data = $this->client->get_json( '/api/v1/datasets/' . rawurlencode( $id ) . '/related' );
		$rows = ( is_array( $data ) && isset( $data['datasets'] ) && is_array( $data['datasets'] ) ) ? array_values( $data['datasets'] ) : array();

		$this->write_cache( $key, $rows, $this->ttl );

		return $rows;
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
	 * Read a cached payload, transparently decoding the stored form.
	 *
	 * Returns {@see CACHE_MISS} when the key is absent or the stored value is
	 * unreadable (so the caller re-fetches), or the decoded array otherwise —
	 * which may be empty for a negative-cache marker.
	 *
	 * @param string $key Transient key.
	 * @return array<int|string,mixed>|string The decoded array, or CACHE_MISS.
	 */
	private function read_cache( string $key ) {
		$stored = get_transient( $key );

		if ( false === $stored ) {
			return self::CACHE_MISS;
		}

		// Back-compat: a pre-compression cache stored the decoded array (and the
		// empty-array negative marker) directly. Honour it as a hit so an
		// upgrade doesn't stampede the node until every entry naturally expires.
		if ( is_array( $stored ) ) {
			return $stored;
		}

		if ( is_string( $stored ) ) {
			$decoded = $this->decode( $stored );
			return null === $decoded ? self::CACHE_MISS : $decoded;
		}

		return self::CACHE_MISS;
	}

	/**
	 * Encode and store a payload in a transient.
	 *
	 * @param string                  $key  Transient key.
	 * @param array<int|string,mixed> $data Payload to cache.
	 * @param int                     $ttl  Lifetime in seconds.
	 */
	private function write_cache( string $key, array $data, int $ttl ): void {
		set_transient( $key, $this->encode( $data ), $ttl );
	}

	/**
	 * Encode a payload for transient storage.
	 *
	 * The catalog envelope is large (100s of KB). Stored as a decoded array it
	 * serialises even larger, which can exceed an object cache's per-item limit
	 * (Memcached defaults to 1 MB) — the write then silently fails and every
	 * request re-fetches from the node. Compressing the JSON keeps it small, and
	 * base64 keeps the bytes 7-bit clean so a DB-backed transient (a utf8mb4
	 * TEXT column) can't mangle raw deflate output.
	 *
	 * @param array<int|string,mixed> $data Payload.
	 */
	private function encode( array $data ): string {
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		if ( strlen( $json ) >= self::COMPRESS_MIN_BYTES && function_exists( 'gzcompress' ) ) {
			$gz = gzcompress( $json, 6 );
			if ( false !== $gz ) {
				// base64 for 7-bit-safe transient storage, not obfuscation.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return self::ENC_GZIP . base64_encode( $gz );
			}
		}

		return self::ENC_RAW . $json;
	}

	/**
	 * Decode a stored payload written by {@see encode()}. Returns null when the
	 * value is in an unknown format or fails to inflate/parse, so the caller
	 * treats it as a miss and re-fetches.
	 *
	 * @param string $stored Stored transient value.
	 * @return array<int|string,mixed>|null
	 */
	private function decode( string $stored ): ?array {
		if ( 0 === strncmp( $stored, self::ENC_GZIP, strlen( self::ENC_GZIP ) ) ) {
			if ( ! function_exists( 'gzuncompress' ) ) {
				return null;
			}
			// Reverses encode()'s base64; strict mode rejects malformed input.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$bin = base64_decode( substr( $stored, strlen( self::ENC_GZIP ) ), true );
			if ( false === $bin ) {
				return null;
			}
			$json = gzuncompress( $bin );
		} elseif ( 0 === strncmp( $stored, self::ENC_RAW, strlen( self::ENC_RAW ) ) ) {
			$json = substr( $stored, strlen( self::ENC_RAW ) );
		} else {
			return null;
		}

		if ( ! is_string( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
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
