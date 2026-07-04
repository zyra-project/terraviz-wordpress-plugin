<?php
/**
 * Terraviz node discovery document (/.well-known/terraviz.json).
 *
 * GENERATED FILE — do not edit by hand.
 * Source schema: https://terraviz.zyra-project.org/schema/v1/well-known.schema.json
 * Regenerate with: php bin/generate-contracts.php
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terraviz node discovery document (/.well-known/terraviz.json).
 */
final class WellKnownDoc extends Wire {

	/**
	 * Every property declared by the schema.
	 *
	 * @var array<int,string>
	 */
	public const PROPERTIES = array( 'node_id', 'display_name', 'base_url', 'public_key', 'schema_versions_supported', 'endpoints', 'policy', 'contact' );

	/**
	 * Properties the schema marks required.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED = array( 'node_id', 'display_name', 'base_url', 'public_key', 'schema_versions_supported', 'endpoints', 'policy', 'contact' );

	/**
	 * @return string|null
	 */
	public function node_id() {
		return $this->scalar( 'node_id' );
	}

	/**
	 * @return string|null
	 */
	public function display_name() {
		return $this->scalar( 'display_name' );
	}

	/**
	 * @return string|null
	 */
	public function base_url() {
		return $this->scalar( 'base_url' );
	}

	/**
	 * @return string|null
	 */
	public function public_key() {
		return $this->scalar( 'public_key' );
	}

	/**
	 * @return array<int,mixed>
	 */
	public function schema_versions_supported() {
		return $this->list( 'schema_versions_supported' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function endpoints() {
		return $this->object( 'endpoints' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function policy() {
		return $this->object( 'policy' );
	}

	/**
	 * @return string|null
	 */
	public function contact() {
		return $this->scalar( 'contact' );
	}

}
