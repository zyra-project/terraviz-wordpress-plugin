<?php
/**
 * Terraviz catalog envelope (GET /api/v1/catalog).
 *
 * GENERATED FILE — do not edit by hand.
 * Source schema: https://terraviz.zyra-project.org/schema/v1/catalog.schema.json
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
 * Terraviz catalog envelope (GET /api/v1/catalog).
 */
final class CatalogResponse extends Wire {

	/**
	 * Every property declared by the schema.
	 *
	 * @var array<int,string>
	 */
	public const PROPERTIES = array( 'schema_version', 'generated_at', 'etag', 'cursor', 'datasets', 'tombstones' );

	/**
	 * Properties the schema marks required.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED = array( 'schema_version', 'generated_at', 'etag', 'cursor', 'datasets', 'tombstones' );

	/**
	 * @return float|null
	 */
	public function schema_version() {
		return $this->number( 'schema_version' );
	}

	/**
	 * @return string|null
	 */
	public function generated_at() {
		return $this->scalar( 'generated_at' );
	}

	/**
	 * @return string|null
	 */
	public function etag() {
		return $this->scalar( 'etag' );
	}

	/**
	 * @return string|null
	 */
	public function cursor() {
		return $this->scalar( 'cursor' );
	}

	/**
	 * @return WireDataset[]
	 */
	public function datasets(): array {
		$out = array();
		foreach ( $this->list( 'datasets' ) as $item ) {
			if ( is_array( $item ) ) {
				$out[] = WireDataset::from_array( $item );
			}
		}

		return $out;
	}

	/**
	 * @return array<int,mixed>
	 */
	public function tombstones() {
		return $this->list( 'tombstones' );
	}

}
