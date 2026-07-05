<?php
/**
 * Contract for a read-only JSON transport against a Terraviz node.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal seam over "GET a JSON endpoint on a node". {@see Client} is the
 * production implementation (WordPress HTTP API); tests provide a canned
 * implementation so the catalog/renderer can be exercised without network.
 */
interface JsonReader {

	/**
	 * The node origin (scheme+host, no trailing slash) this reader targets.
	 */
	public function origin(): string;

	/**
	 * GET a JSON endpoint and return the decoded array, or null on failure.
	 *
	 * @param string               $path  API path beginning with '/'.
	 * @param array<string,scalar> $query Optional query args.
	 * @return array<string,mixed>|null
	 */
	public function get_json( string $path, array $query = array() ): ?array;
}
