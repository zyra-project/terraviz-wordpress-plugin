<?php
/**
 * Generate the PHP wire-contract value objects from the served JSON Schema.
 *
 * The plugin depends on Terraviz's *published contract*, not its TypeScript
 * source. Rather than hand-copying field names into PHP (which silently
 * drift), we generate the classes in src/Contract/ from the schemas served
 * at https://<node>/schema/v1/*.schema.json.
 *
 * Usage:
 *   php bin/generate-contracts.php                 # fetch from the canonical node
 *   php bin/generate-contracts.php path/to/schema-dir   # from local schema files
 *
 * Re-run whenever the wire schema changes (a CI drift-check should compare
 * the committed output against a fresh run). This is a dev/build tool; it is
 * never invoked at runtime.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$default_base = 'https://terraviz.zyra-project.org/schema/v1';
$source       = $argv[1] ?? $default_base;

/**
 * The schemas we generate, and the class each becomes.
 * `wraps` maps an array-of-object property to the class that wraps its items.
 */
$targets = array(
	'dataset.schema.json'    => array(
		'class' => 'WireDataset',
		'title' => 'Terraviz wire Dataset (GET /api/v1/datasets/:id, and each catalog entry).',
		'wraps' => array(),
	),
	'catalog.schema.json'    => array(
		'class' => 'CatalogResponse',
		'title' => 'Terraviz catalog envelope (GET /api/v1/catalog).',
		'wraps' => array( 'datasets' => 'WireDataset' ),
	),
	'well-known.schema.json' => array(
		'class' => 'WellKnownDoc',
		'title' => 'Terraviz node discovery document (/.well-known/terraviz.json).',
		'wraps' => array(),
	),
);

$out_dir = dirname( __DIR__ ) . '/src/Contract';

foreach ( $targets as $file => $meta ) {
	$schema = tvz_load_schema( $source, $file );
	if ( null === $schema ) {
		fwrite( STDERR, "Failed to load schema: {$file}\n" );
		exit( 1 );
	}

	$code = tvz_emit_class( $schema, $meta['class'], $meta['title'], $meta['wraps'], $source, $file );
	$path = $out_dir . '/' . $meta['class'] . '.php';
	file_put_contents( $path, $code );
	fwrite( STDOUT, "Generated {$path}\n" );
}

fwrite( STDOUT, "Done.\n" );

/**
 * Load a schema from a directory path or an HTTP(S) base URL.
 *
 * @return array<string,mixed>|null
 */
function tvz_load_schema( string $source, string $file ): ?array {
	if ( preg_match( '#^https?://#i', $source ) ) {
		$url  = rtrim( $source, '/' ) . '/' . $file;
		$json = @file_get_contents( $url ); // phpcs:ignore
	} else {
		$path = rtrim( $source, '/' ) . '/' . $file;
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
	}

	if ( false === $json ) {
		return null;
	}

	$data = json_decode( (string) $json, true );

	return is_array( $data ) ? $data : null;
}

/**
 * Emit a PHP class for one top-level object schema.
 *
 * @param array<string,mixed>  $schema  Decoded JSON Schema.
 * @param string               $class   Target class name.
 * @param string               $title   Human title for the docblock.
 * @param array<string,string> $wraps   property => wrapping class.
 */
function tvz_emit_class( array $schema, string $class, string $title, array $wraps, string $source, string $file ): string {
	$props    = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
	$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

	$req_export  = tvz_export_list( $required );
	$prop_export = tvz_export_list( array_keys( $props ) );

	$getters = '';
	foreach ( $props as $name => $spec ) {
		$getters .= tvz_emit_getter( (string) $name, is_array( $spec ) ? $spec : array(), $wraps );
	}

	$schema_id = isset( $schema['$id'] ) ? (string) $schema['$id'] : ( rtrim( $source, '/' ) . '/' . $file );

	$header = <<<PHP
<?php
/**
 * {$title}
 *
 * GENERATED FILE — do not edit by hand.
 * Source schema: {$schema_id}
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
 * {$title}
 */
final class {$class} extends Wire {

	/**
	 * Every property declared by the schema.
	 *
	 * @var array<int,string>
	 */
	public const PROPERTIES = {$prop_export};

	/**
	 * Properties the schema marks required.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED = {$req_export};

{$getters}}

PHP;

	return $header;
}

/**
 * Emit a single typed getter for a property.
 *
 * @param array<string,mixed>  $spec  Property schema.
 * @param array<string,string> $wraps property => wrapping class.
 */
function tvz_emit_getter( string $name, array $spec, array $wraps ): string {
	$method = tvz_method_name( $name );
	$type   = isset( $spec['type'] ) ? $spec['type'] : 'mixed';
	if ( is_array( $type ) ) {
		// e.g. ["string","null"] — take the first non-null.
		$non_null = array_values( array_filter( $type, static fn( $t ) => 'null' !== $t ) );
		$type     = $non_null[0] ?? 'mixed';
	}

	// Array-of-object property that wraps into a value object.
	if ( 'array' === $type && isset( $wraps[ $name ] ) ) {
		$wrap = $wraps[ $name ];

		return <<<PHP
	/**
	 * @return {$wrap}[]
	 */
	public function {$method}(): array {
		\$out = array();
		foreach ( \$this->list( '{$name}' ) as \$item ) {
			if ( is_array( \$item ) ) {
				\$out[] = {$wrap}::from_array( \$item );
			}
		}

		return \$out;
	}


PHP;
	}

	switch ( $type ) {
		case 'string':
			$doc  = '@return string|null';
			$body = "return \$this->scalar( '{$name}' );";
			break;
		case 'number':
		case 'integer':
			$doc  = '@return float|null';
			$body = "return \$this->number( '{$name}' );";
			break;
		case 'boolean':
			$doc  = '@return bool|null';
			$body = "return \$this->boolean( '{$name}' );";
			break;
		case 'array':
			$doc  = '@return array<int,mixed>';
			$body = "return \$this->list( '{$name}' );";
			break;
		case 'object':
			$doc  = '@return array<string,mixed>';
			$body = "return \$this->object( '{$name}' );";
			break;
		default:
			$doc  = '@return mixed';
			$body = "return \$this->get( '{$name}' );";
			break;
	}

	return <<<PHP
	/**
	 * {$doc}
	 */
	public function {$method}() {
		{$body}
	}


PHP;
}

/**
 * Convert a wire field name (camelCase / snake) to a method name.
 * The method keeps the field's own casing to stay unambiguous, but is a
 * valid identifier.
 */
function tvz_method_name( string $name ): string {
	$name = preg_replace( '/[^A-Za-z0-9_]/', '_', $name );

	return $name ?? '_';
}

/**
 * Export a list as a compact PHP array literal.
 *
 * @param array<int,string> $items Values.
 */
function tvz_export_list( array $items ): string {
	if ( empty( $items ) ) {
		return 'array()';
	}

	$quoted = array_map(
		static fn( string $v ) => "'" . str_replace( "'", "\\'", $v ) . "'",
		array_values( $items )
	);

	return 'array( ' . implode( ', ', $quoted ) . ' )';
}
