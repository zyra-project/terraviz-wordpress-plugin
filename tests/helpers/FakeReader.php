<?php
/**
 * A canned JsonReader for tests — no network.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Tests;

use Terraviz\Api\JsonReader;

/**
 * Returns pre-seeded responses keyed by path, so the catalog and renderer can
 * be exercised deterministically and offline.
 */
final class FakeReader implements JsonReader {

	/**
	 * @var string
	 */
	private $origin;

	/**
	 * path => decoded response.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private $responses;

	/**
	 * Number of get_json() calls, so tests can assert the cache prevents
	 * re-fetching.
	 *
	 * @var int
	 */
	public $call_count = 0;

	/**
	 * @param string                                 $origin    Node origin.
	 * @param array<string,array<string,mixed>|null> $responses path => body.
	 */
	public function __construct( string $origin, array $responses = array() ) {
		$this->origin    = untrailingslashit( $origin );
		$this->responses = $responses;
	}

	/**
	 * @inheritDoc
	 */
	public function origin(): string {
		return $this->origin;
	}

	/**
	 * @inheritDoc
	 */
	public function get_json( string $path, array $query = array() ): ?array {
		++$this->call_count;
		$key = '/' . ltrim( $path, '/' );

		return array_key_exists( $key, $this->responses ) ? $this->responses[ $key ] : null;
	}
}
