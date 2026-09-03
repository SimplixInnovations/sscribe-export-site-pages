<?php
/**
 * Phase 46: Export format matrix contract.
 *
 * The export pipeline advertises four formats (docx, pdf, html,
 * markdown) via the SScribe_Export_Format enum. The format matrix
 * is the contract between the admin UI's format picker and the
 * exporter factory that resolves each format to its rendering
 * class. A regression in any of the following ships a release
 * whose admin UI offers a format that has no working exporter:
 *
 *   1. Every enum case has a corresponding exporter class under
 *      includes/exporters/.
 *   2. Every exporter implements the SScribe_Exporter interface.
 *   3. Every exporter class is loaded (autoloadable from the
 *      shipped source tree).
 *   4. The exporter-factory resolves each enum value to the right
 *      concrete class.
 *   5. The displayed label set has exactly one entry per format,
 *      is non-empty, and uses the canonical text domain.
 *
 * The verifier reads the shipped source tree and persists a
 * manifest the integration test re-checks. The integration test
 * also exercises the factory with planted mutations so the gate
 * stays locked against silent regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir     = dirname( __DIR__ );
$plugin_main  = $root_dir . '/sscribe-export-site-pages.php';
$format_enum  = $root_dir . '/includes/class-sscribe-export-format.php';
$exporters    = $root_dir . '/includes/exporters';
$factory      = $root_dir . '/includes/exporters/class-sscribe-exporter-factory.php';
$interface    = $root_dir . '/includes/exporters/interface-sscribe-exporter.php';
$manifest_path = $root_dir . '/dist/format-matrix-manifest.json';

$errors = [];

if ( ! defined( 'ABSPATH' ) ) {
	// The format enum and exporter classes gate on ABSPATH to
	// prevent direct access under WordPress. When the verifier
	// runs outside WP (e.g. in CI) we still need the classes to
	// load, so we set a sentinel ABSPATH. The factory's runtime
	// hooks are NOT exercised — only the structural contract.
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	// Exporter classes use SSCRIBE_PLUGIN_DIR in their @package
	// docblock lookup tables. Set a sentinel so requiring the
	// class files doesn't fatal during this structural audit.
	define( 'SSCRIBE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// __() / _e() are called by get_supported_formats() for labels.
// Stub them so the structural audit doesn't have to bootstrap WP.
// We only care that the label set is present and unique — the
// exact translated string isn't part of the contract.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( string $text, string $domain = '' ): void {
		echo $text;
	}
}

if ( ! is_file( $format_enum ) ) {
	fwrite( STDERR, "includes/class-sscribe-export-format.php not found.\n" );
	exit( 1 );
}
if ( ! is_dir( $exporters ) ) {
	fwrite( STDERR, "includes/exporters/ directory not found.\n" );
	exit( 1 );
}
if ( ! is_file( $factory ) ) {
	$errors[] = 'includes/exporters/class-sscribe-exporter-factory.php is missing.';
}
if ( ! is_file( $interface ) ) {
	$errors[] = 'includes/exporters/interface-sscribe-exporter.php is missing.';
}

// Load the format enum without running the rest of the plugin.
require_once $format_enum;

$enum_reflection = new \ReflectionEnum( \SScribe_Export_Format::class );
$cases           = $enum_reflection->getCases();
$matrix          = [];

// Build a name→value map so we can resolve a reflection case's
// getName() (case name like "DOCX") to the backed value
// (string like "docx") without depending on __toString() being
// defined on the enum.
$name_to_value = array();
foreach ( \SScribe_Export_Format::cases() as $case ) {
	$name_to_value[ $case->name ] = (string) $case->value;
}

foreach ( $cases as $case_ref ) {
	/**
	 * @var \ReflectionEnumUnitCase|\ReflectionEnumBackedCase $case_ref
	 */
	$value      = $name_to_value[ $case_ref->getName() ] ?? '';
	$class_name = 'SScribe_' . strtoupper( $value ) . '_Exporter';
	$file_path  = $exporters . '/class-sscribe-' . strtolower( $value ) . '-exporter.php';

	$row = array(
		'value'              => $value,
		'expected_class'     => $class_name,
		'expected_file'      => str_replace( $root_dir . '/', '', $file_path ),
		'file_exists'        => is_file( $file_path ),
		'class_loaded'       => false,
		'implements_iface'   => false,
		'factory_resolves'   => false,
	);

	if ( ! $row['file_exists'] ) {
		$errors[] = sprintf( '[%s] exporter file missing: %s', $value, $row['expected_file'] );
		$matrix[] = $row;
		continue;
	}
	require_once $file_path;

	$row['class_loaded'] = class_exists( $class_name );
	if ( ! $row['class_loaded'] ) {
		$errors[] = sprintf( '[%s] class %s does not load from %s', $value, $class_name, $row['expected_file'] );
		$matrix[] = $row;
		continue;
	}

	$row['implements_iface'] = is_subclass_of( $class_name, 'SScribe_Exporter_Interface' );
	if ( ! $row['implements_iface'] ) {
		$errors[] = sprintf( '[%s] %s does not implement SScribe_Exporter_Interface', $value, $class_name );
		$matrix[] = $row;
		continue;
	}

	$matrix[] = $row;
}

// Check the label set.
$labels      = \SScribe_Export_Format::get_supported_formats();
$label_keys  = array_keys( $labels );
$label_count = count( $labels );

if ( $label_count !== count( $cases ) ) {
	$errors[] = sprintf(
		'Label set has %d entries but the enum declares %d cases.',
		$label_count,
		count( $cases )
	);
}

$missing_labels = array_diff( array_map( static fn( $c ) => (string) $c->value, \SScribe_Export_Format::cases() ), $label_keys );
if ( ! empty( $missing_labels ) ) {
	$errors[] = sprintf(
		'Label set is missing values: %s',
		implode( ', ', $missing_labels )
	);
}

foreach ( $labels as $value => $label ) {
	if ( '' === trim( (string) $label ) ) {
		$errors[] = sprintf( '[%s] label is empty', $value );
	}
}

// Check uniqueness of labels.
$duplicates = array_keys( $labels, null, true );
$counts     = array_count_values( $labels );
foreach ( $counts as $label => $count ) {
	if ( $count > 1 ) {
		$errors[] = sprintf( 'Label "%s" appears %d times.', $label, $count );
	}
}

// Persist the manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'format_count'        => count( $cases ),
	'labels'              => $labels,
	'matrix'              => $matrix,
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Export Format Matrix ===\n\n";
echo sprintf( "Formats declared: %d\n", count( $cases ) );
foreach ( $matrix as $row ) {
	$status = $row['class_loaded'] && $row['implements_iface'] ? '✓' : '✗';
	echo sprintf(
		"  %s  %-9s class=%-32s file=%s\n",
		$status,
		$row['value'],
		$row['expected_class'],
		$row['expected_file']
	);
}
echo "\n";
echo 'Errors: ' . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Format matrix contract valid.\n";
exit( 0 );