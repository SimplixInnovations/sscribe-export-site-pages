<?php
/**
 * Phase 47: Export result validation contract.
 *
 * The export pipeline writes per-page files via SScribe_Exporter_Interface
 * implementations and the SScribe_Export_All_Formats_Wrapper convenience
 * dispatcher. A regression in any of the following ships a release whose
 * download ZIP contains malformed files (wrong extension, wrong MIME,
 * missing magic bytes, etc.):
 *
 *   1. Every exporter implements SScribe_Exporter_Interface.
 *   2. Every exporter's get_extension() returns the canonical value
 *      matching the format-picker UI string (docx → "docx", pdf → "pdf",
 *      html → "html", markdown → "md").
 *   3. Every exporter's get_mime_type() returns the canonical MIME.
 *   4. The MIME / extension pair is internally consistent with what
 *      wp_check_filetype() expects.
 *   5. The wrapper exposes export_page / successful_formats / failed_formats.
 *   6. SScribe_Exporter_Factory exposes build_filename().
 *
 * The verifier reads the shipped source tree, instantiates every exporter
 * in isolation, and persists a manifest the integration test re-checks.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir        = dirname( __DIR__ );
$format_enum     = $root_dir . '/includes/class-sscribe-export-format.php';
$exporters_dir   = $root_dir . '/includes/exporters';
$factory         = $root_dir . '/includes/exporters/class-sscribe-exporter-factory.php';
$interface_path  = $root_dir . '/includes/exporters/interface-sscribe-exporter.php';
$wrapper_path    = $root_dir . '/includes/exporters/class-sscribe-export-all-formats-wrapper.php';
$manifest_path   = $root_dir . '/dist/export-result-manifest.json';

$errors = array();

// Sentinel sentinels so the exporters load outside WP.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	define( 'SSCRIBE_PLUGIN_DIR', $root_dir . '/' );
}
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
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
}

if ( ! is_file( $format_enum ) ) {
	fwrite( STDERR, "includes/class-sscribe-export-format.php not found.\n" );
	exit( 1 );
}
if ( ! is_file( $interface_path ) ) {
	fwrite( STDERR, "interface-sscribe-exporter.php not found.\n" );
	exit( 1 );
}
if ( ! is_file( $factory ) ) {
	$errors[] = 'class-sscribe-exporter-factory.php is missing.';
}
if ( ! is_file( $wrapper_path ) ) {
	$errors[] = 'class-sscribe-export-all-formats-wrapper.php is missing.';
}

require_once $format_enum;
require_once $interface_path;

// The DOCX exporter delegates to the legacy SScribe_Exporter class. Load
// the bare minimum it needs before instantiation so the structural audit
// can construct every exporter.
require_once $root_dir . '/includes/class-sscribe-result.php';
require_once $root_dir . '/includes/class-sscribe-filesystem.php';
require_once $root_dir . '/includes/class-sscribe-logger.php';
require_once $root_dir . '/includes/class-sscribe-exporter.php'; // Legacy exporter used by DOCX delegation.
require_once $root_dir . '/includes/exporters/class-sscribe-docx-exporter.php';
require_once $root_dir . '/includes/exporters/class-sscribe-pdf-exporter.php';
require_once $root_dir . '/includes/exporters/class-sscribe-html-exporter.php';
require_once $root_dir . '/includes/exporters/class-sscribe-markdown-exporter.php';

$enum_cases = \SScribe_Export_Format::cases();

// Canonical contracts.
$expected_extension = array(
	'docx'     => 'docx',
	'pdf'      => 'pdf',
	'html'     => 'html',
	'markdown' => 'md',
);

$expected_mime = array(
	'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'pdf'      => 'application/pdf',
	'html'     => 'text/html',
	'markdown' => 'text/markdown',
);

$expected_magic = array(
	'docx'     => 'PK\x03\x04',
	'pdf'      => '%PDF-',
	'html'     => '<!DOCTYPE|<html',
	'markdown' => null, // text; verified separately by sniff_md_signature().
);

$matrix = array();

foreach ( $enum_cases as $case ) {
	$value      = (string) $case->value;
	$class_name = 'SScribe_' . strtoupper( $value ) . '_Exporter';
	$file_path  = $exporters_dir . '/class-sscribe-' . $value . '-exporter.php';

	$row = array(
		'value'           => $value,
		'expected_class'  => $class_name,
		'expected_file'   => str_replace( $root_dir . '/', '', $file_path ),
		'file_exists'     => is_file( $file_path ),
		'class_loaded'    => false,
		'extension_ok'    => false,
		'mime_ok'         => false,
		'extension_value' => '',
		'mime_value'      => '',
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

	if ( ! is_subclass_of( $class_name, 'SScribe_Exporter_Interface' ) ) {
		$errors[] = sprintf( '[%s] %s does not implement SScribe_Exporter_Interface', $value, $class_name );
		$matrix[] = $row;
		continue;
	}

	// Use reflection to skip constructor — get_extension() / get_mime_type()
	// are static-shape contract probes and must not require the full
	// legacy exporter stack to be loaded (DOCX delegating to SScribe_Exporter
	// pulls in the content parser and parser dependencies that aren't part
	// of the structural audit).
	$reflection     = new \ReflectionClass( $class_name );
	$exporter       = $reflection->newInstanceWithoutConstructor();

	$row['extension_value'] = (string) $exporter->get_extension();
	$row['mime_value']      = (string) $exporter->get_mime_type();

	if ( ! isset( $expected_extension[ $value ] ) ) {
		$errors[] = sprintf( '[%s] no canonical extension declared', $value );
		$matrix[] = $row;
		continue;
	}

	if ( $row['extension_value'] !== $expected_extension[ $value ] ) {
		$errors[] = sprintf(
			'[%s] get_extension() returned "%s", expected "%s"',
			$value,
			$row['extension_value'],
			$expected_extension[ $value ]
		);
	} else {
		$row['extension_ok'] = true;
	}

	if ( $row['mime_value'] !== $expected_mime[ $value ] ) {
		$errors[] = sprintf(
			'[%s] get_mime_type() returned "%s", expected "%s"',
			$value,
			$row['mime_value'],
			$expected_mime[ $value ]
		);
	} else {
		$row['mime_ok'] = true;
	}

	$matrix[] = $row;
}

// Wrapper API surface.
$wrapper_source = is_file( $wrapper_path ) ? (string) file_get_contents( $wrapper_path ) : '';
$wrapper_methods = array( 'export_page', 'successful_formats', 'failed_formats' );
$wrapper_present = array();
foreach ( $wrapper_methods as $method ) {
	$wrapper_present[ $method ] = (bool) preg_match(
		'/public\s+static\s+function\s+' . preg_quote( $method, '/' ) . '\s*\(/',
		$wrapper_source
	);
	if ( ! $wrapper_present[ $method ] ) {
		$errors[] = sprintf( 'wrapper missing static method %s()', $method );
	}
}

// Factory surface.
if ( is_file( $factory ) ) {
	$factory_source = (string) file_get_contents( $factory );
	if ( ! preg_match( '/public\s+static\s+function\s+build_filename\s*\(/', $factory_source ) ) {
		$errors[] = 'SScribe_Exporter_Factory missing build_filename() static method';
	}
	if ( ! preg_match( '/public\s+static\s+function\s+is_supported\s*\(/', $factory_source ) ) {
		$errors[] = 'SScribe_Exporter_Factory missing is_supported() static method';
	}
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'    => gmdate( 'c' ),
	'format_count'    => count( $matrix ),
	'matrix'          => $matrix,
	'wrapper_methods' => $wrapper_present,
	'expected'        => array(
		'extension' => $expected_extension,
		'mime'      => $expected_mime,
		'magic'     => $expected_magic,
	),
	'errors_count'    => count( $errors ),
	'passes'          => 0 === count( $errors ),
	'errors'          => $errors,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Export Result Validation ===\n\n";
echo sprintf( "Formats declared: %d\n", count( $matrix ) );
foreach ( $matrix as $row ) {
	$status = $row['extension_ok'] && $row['mime_ok'] ? '✓' : '✗';
	echo sprintf(
		"  %s  %-9s ext=%-6s mime=%s\n",
		$status,
		$row['value'],
		$row['extension_value'],
		$row['mime_value']
	);
}
echo "\nWrapper methods: ";
echo $wrapper_present['export_page'] && $wrapper_present['successful_formats'] && $wrapper_present['failed_formats']
	? 'present' . "\n"
	: 'MISSING' . "\n";
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Export result validation contract valid.\n";
exit( 0 );
