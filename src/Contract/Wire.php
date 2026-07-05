<?php
/**
 * Base class for wire-contract value objects.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-ish wrapper over a decoded wire payload.
 *
 * The wire format evolves additively and leaves `additionalProperties`
 * open (see the protocol README), so we keep the full decoded array and
 * expose typed convenience getters over it rather than dropping unknown
 * fields. Subclasses are code-generated from the served JSON Schema by
 * {@see bin/generate-contracts.php}; do not hand-edit their field lists.
 */
abstract class Wire {

	/**
	 * The raw decoded payload.
	 *
	 * @var array<string,mixed>
	 */
	protected $raw;

	/**
	 * Construct from a decoded payload.
	 *
	 * @param array<string,mixed> $raw Decoded payload.
	 */
	public function __construct( array $raw ) {
		$this->raw = $raw;
	}

	/**
	 * Build from a decoded array.
	 *
	 * @param array<string,mixed> $raw Decoded payload.
	 * @return static
	 */
	public static function from_array( array $raw ) {
		return new static( $raw );
	}

	/**
	 * Whether a field is present (and non-null).
	 *
	 * @param string $field Field name.
	 */
	public function has( string $field ): bool {
		return isset( $this->raw[ $field ] ) && null !== $this->raw[ $field ];
	}

	/**
	 * Raw value of a field.
	 *
	 * @param string $field    Field name.
	 * @param mixed  $fallback Value returned when the field is absent.
	 * @return mixed
	 */
	public function get( string $field, $fallback = null ) {
		return $this->has( $field ) ? $this->raw[ $field ] : $fallback;
	}

	/**
	 * Scalar string accessor helper used by generated getters.
	 *
	 * @param string $field Field name.
	 * @return string|null
	 */
	protected function scalar( string $field ) {
		return $this->has( $field ) ? (string) $this->raw[ $field ] : null;
	}

	/**
	 * Numeric accessor helper used by generated getters.
	 *
	 * @param string $field Field name.
	 * @return float|null
	 */
	protected function number( string $field ) {
		return $this->has( $field ) ? (float) $this->raw[ $field ] : null;
	}

	/**
	 * Boolean accessor helper used by generated getters.
	 *
	 * @param string $field Field name.
	 * @return bool|null
	 */
	protected function boolean( string $field ) {
		return $this->has( $field ) ? (bool) $this->raw[ $field ] : null;
	}

	/**
	 * List accessor helper used by generated getters.
	 *
	 * @param string $field Field name.
	 * @return array<int,mixed>
	 */
	protected function list( string $field ): array {
		$value = $this->get( $field, array() );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Object accessor helper used by generated getters.
	 *
	 * @param string $field Field name.
	 * @return array<string,mixed>
	 */
	protected function object( string $field ): array {
		$value = $this->get( $field, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * The full decoded payload.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->raw;
	}
}
