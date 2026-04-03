<?php
/**
 * Version synchronization verification script.
 *
 * Ensures all version references across the plugin are consistent.
 * Run: php scripts/verify-version-sync.php
 *
 * @package SScribe
 */

declare( strict_types=1 );

// Exit if not running from CLI.
if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir         = dirname( __DIR__ );
$version_errors   = array();
$version_warnings = array();

// Define all locations where version should be defined.
$version_locations = array(
	'plugin_header' => array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => '/\*\s*Version:\s*([0-9.]+)/',
		'line'    => 6,
	),
	'constant'      => array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/U",
		'line'    => 30,
	),
	'stable_tag'    => array(
		'file'    => $root_dir . '/readme.txt',
		'pattern' => '/Stable tag:\s*([0-9.]+)/',
		'line'    => 7,
	),
	'css_header'    => array(
		'file'    => $root_dir . '/admin/css/sscribe-admin.css',
		'pattern' => '/@version\s+([0-9.]+)/',
		'line'    => 8,
	),
);

// Extract versions from each location.
$versions = array();

foreach ( $version_locations as $name => $location ) {
	$file = $location['file'];

	if ( ! file_exists( $file ) ) {
		$version_errors[] = sprintf( '[%s] File not found: %s', $name, $file );
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content = file_get_contents( $file );

	if ( ! preg_match( $location['pattern'], $content, $matches ) ) {
		$version_errors[] = sprintf( '[%s] Version not found in file: %s', $name, $file );
		continue;
	}

	$versions[ $name ] = $matches[1];
}

// Check if minified CSS exists and has same content (if minification was run).
$min_css_file = $root_dir . '/admin/css/sscribe-admin.min.css';
if ( file_exists( $min_css_file ) ) {
	// Minified CSS exists - good.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$min_css_content = file_get_contents( $min_css_file );
	if ( strlen( $min_css_content ) < 100 ) {
		$version_warnings[] = 'Minified CSS file appears to be empty or too small.';
	}
} else {
	$version_warnings[] = 'Minified CSS file not found. Run: composer css:minify';
}

// Verify all versions match.
if ( count( $versions ) < 2 ) {
	$version_errors[] = 'Could not extract enough version references to compare.';
} else {
	$unique_versions = array_unique( array_values( $versions ) );

	if ( count( $unique_versions ) > 1 ) {
		$version_errors[] = 'Version mismatch detected!';
		foreach ( $versions as $v_name => $v_version ) {
			$version_errors[] = sprintf( '  - %s: %s', $v_name, $v_version );
		}
	}
}

// Check for changelog entry for current version.
if ( ! empty( $versions['constant'] ) ) {
	$readme_file = $root_dir . '/readme.txt';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$readme_content    = file_get_contents( $readme_file );
	$changelog_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =/';

	if ( ! preg_match( $changelog_pattern, $readme_content ) ) {
		$version_warnings[] = sprintf( 'Changelog entry not found for version %s', $versions['constant'] );
	}

	// Check for upgrade notice.
	$upgrade_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =[\s\S]*?(?== [0-9]|\z)/';
	if ( preg_match( $upgrade_pattern, $readme_content, $upgrade_section ) ) {
		if ( strlen( trim( $upgrade_section[0] ) ) < 50 ) {
			$version_warnings[] = 'Upgrade notice section appears to be missing or too short.';
		}
	}
}

// Output results.
echo "=== SScribe Version Verification ===\n\n";

	if ( ! empty( $versions ) ) {
		echo "Versions found:\n";
		foreach ( $versions as $v_name => $v_version ) {
			printf( "  ✓ %-15s: %s\n", $v_name, $v_version );
		}
		echo "\n";
	}

	if ( ! empty( $version_warnings ) ) {
		echo "Warnings:\n";
		foreach ( $version_warnings as $v_warning ) {
			printf( "  ⚠ %s\n", $v_warning );
		}
		echo "\n";
	}

	if ( ! empty( $version_errors ) ) {
		echo "Errors:\n";
		foreach ( $version_errors as $v_error ) {
			printf( "  ✗ %s\n", $v_error );
		}
		echo "\n";
		exit( 1 );
	}

echo "✓ All version references are synchronized.\n";
echo "✓ All checks passed.\n";
exit( 0 );
