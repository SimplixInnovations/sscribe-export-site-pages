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

$baseDir          = dirname( __DIR__ );
$targetSafeDir    = $baseDir . '/vendor-prefixed/thecodingmachine/safe/generated';
$canonicalSafeDir = $baseDir . '/vendor/thecodingmachine/safe/generated';

if ( ! is_dir( $targetSafeDir ) ) {
	fwrite( STDOUT, "[fix-prefixed-safe] Skipped: {$targetSafeDir} not found.\n" );
	exit( 0 );
}

if ( ! is_dir( $canonicalSafeDir ) ) {
	fwrite( STDOUT, "[fix-prefixed-safe] Skipped: {$canonicalSafeDir} not found.\n" );
	exit( 0 );
}

$canonicalEntries = scandir( $canonicalSafeDir );
if ( false === $canonicalEntries ) {
	fwrite( STDERR, "[fix-prefixed-safe] Failed to read {$canonicalSafeDir}.\n" );
	exit( 1 );
}

$targetVersions = array( '8.1', '8.2', '8.3', '8.4', '8.5', '8.6' );
$copied         = 0;

foreach ( $targetVersions as $version ) {
	$sourceVersionDir = $canonicalSafeDir . '/' . $version;
	if ( ! is_dir( $sourceVersionDir ) ) {
		continue;
	}

	$targetVersionDir = $targetSafeDir . '/' . $version;
	if ( ! is_dir( $targetVersionDir ) && ! mkdir( $targetVersionDir, 0755, true ) && ! is_dir( $targetVersionDir ) ) {
		fwrite( STDERR, "[fix-prefixed-safe] Failed to create {$targetVersionDir}.\n" );
		exit( 1 );
	}

	foreach ( $canonicalEntries as $entry ) {
		$source = $sourceVersionDir . '/' . $entry;
		$target = $targetVersionDir . '/' . $entry;

		if ( ! is_file( $source ) || ! str_ends_with( $entry, '.php' ) ) {
			continue;
		}

		if ( ! file_exists( $target ) ) {
			if ( ! copy( $source, $target ) ) {
				fwrite( STDERR, "[fix-prefixed-safe] Failed copying {$source} -> {$target}.\n" );
				exit( 1 );
			}

			++$copied;
		}
	}
}

fwrite( STDOUT, "[fix-prefixed-safe] Completed. Files copied: {$copied}.\n" );
