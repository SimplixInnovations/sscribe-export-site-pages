<?php
/**
 * Post-Strauss fix for thecodingmachine/safe generated versioned wrappers.
 *
 * Some build environments can produce a prefixed safe package where
 * generated/<php-version>/*.php files are missing. This script backfills
 * missing versioned files by copying canonical version-specific files from
 * the non-prefixed vendor tree.
 *
 * @package SScribe
 */

declare(strict_types=1);

$base_dir           = dirname( __DIR__ );
$target_safe_dir    = $base_dir . '/vendor-prefixed/thecodingmachine/safe/generated';
$canonical_safe_dir = $base_dir . '/vendor/thecodingmachine/safe/generated';

if ( ! is_dir( $target_safe_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
	fwrite( STDOUT, "[fix-prefixed-safe] Skipped: {$target_safe_dir} not found.\n" );
	exit( 0 );
}

if ( ! is_dir( $canonical_safe_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
	fwrite( STDOUT, "[fix-prefixed-safe] Skipped: {$canonical_safe_dir} not found.\n" );
	exit( 0 );
}

$canonical_entries = scandir( $canonical_safe_dir );
if ( false === $canonical_entries ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
	fwrite( STDERR, "[fix-prefixed-safe] Failed to read {$canonical_safe_dir}.\n" );
	exit( 1 );
}

$target_versions = array( '8.1', '8.2', '8.3', '8.4', '8.5', '8.6' );
$copied          = 0;

foreach ( $target_versions as $version ) {
	$source_version_dir = $canonical_safe_dir . '/' . $version;
	if ( ! is_dir( $source_version_dir ) ) {
		continue;
	}

	$target_version_dir = $target_safe_dir . '/' . $version;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI maintenance script.
	if ( ! is_dir( $target_version_dir ) && ! mkdir( $target_version_dir, 0755, true ) && ! is_dir( $target_version_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
		fwrite( STDERR, "[fix-prefixed-safe] Failed to create {$target_version_dir}.\n" );
		exit( 1 );
	}

	foreach ( $canonical_entries as $entry ) {
		$source = $source_version_dir . '/' . $entry;
		$target = $target_version_dir . '/' . $entry;

		if ( ! is_file( $source ) || ! str_ends_with( $entry, '.php' ) ) {
			continue;
		}

		if ( ! file_exists( $target ) ) {
			if ( ! copy( $source, $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
				fwrite( STDERR, "[fix-prefixed-safe] Failed copying {$source} -> {$target}.\n" );
				exit( 1 );
			}

			++$copied;
		}
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.
fwrite( STDOUT, "[fix-prefixed-safe] Completed. Files copied: {$copied}.\n" );
