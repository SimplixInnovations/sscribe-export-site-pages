<?php
/**
 * SScribe Fix Prefixed Safe Script
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

$base_dir           = dirname( __DIR__ );
$target_safe_dir    = $base_dir . '/vendor-prefixed/thecodingmachine/safe/generated';
$canonical_safe_dir = $base_dir . '/vendor/thecodingmachine/safe/generated';

// Preserve PHPWord's LGPL notice under a WordPress.org-compatible filename.
// The upstream `COPYING.LESSER` suffix is interpreted as a forbidden file
// extension by Plugin Check; the contents and attribution remain unchanged.
$phpword_lgpl_source = $base_dir . '/vendor-prefixed/phpoffice/phpword/COPYING.LESSER';
$phpword_lgpl_target = $base_dir . '/vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt';
if ( is_file( $phpword_lgpl_source ) ) {
	if ( ! copy( $phpword_lgpl_source, $phpword_lgpl_target ) ) {
		fwrite( STDERR, "[fix-prefixed-safe] Failed to normalize the PHPWord LGPL notice.\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "[fix-prefixed-safe] Normalized PHPWord LGPL notice filename.\n" );
}

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

$target_mpdf_dir    = $base_dir . '/vendor-prefixed/mpdf/mpdf/data';
$canonical_mpdf_dir = $base_dir . '/vendor/mpdf/mpdf/data';

$copied_mpdf = 0;
$entries     = is_dir( $canonical_mpdf_dir ) ? scandir( $canonical_mpdf_dir ) : false;
if ( false !== $entries && is_array( $entries ) ) {

	if ( ! is_dir( $target_mpdf_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI maintenance script.

		mkdir( $target_mpdf_dir, 0755, true );
	}
	foreach ( $entries as $entry ) {
		if ( ! is_file( $canonical_mpdf_dir . '/' . $entry ) || ! str_ends_with( $entry, '.php' ) ) {
			continue;
		}
		$target = $target_mpdf_dir . '/' . $entry;
		if ( ! file_exists( $target ) ) {
			if ( copy( $canonical_mpdf_dir . '/' . $entry, $target ) ) {
				++$copied_mpdf;
			}
		}
	}
} else {
	fwrite( STDOUT, "[fix-prefixed-safe] mpdf data dir {$canonical_mpdf_dir} not found — likely handled by Strauss.\n" );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI maintenance script.

fwrite( STDOUT, "[fix-prefixed-safe] mpdf data files backfilled: {$copied_mpdf}.\n" );
