<?php
/**
 * Post-Strauss fix for thecodingmachine/safe generated versioned wrappers.
 *
 * Some build environments can produce a prefixed safe package where
 * generated/<php-version>/*.php files are missing while generated/*.php files
 * still require them. This script backfills missing versioned wrappers by
 * copying from the non-versioned generated files.
 *
 * @package SScribe
 */

declare(strict_types=1);

$baseDir = dirname( __DIR__ );
$safeDir = $baseDir . '/vendor-prefixed/thecodingmachine/safe/generated';

if ( ! is_dir( $safeDir ) ) {
	fwrite( STDOUT, "[fix-prefixed-safe] Skipped: {$safeDir} not found.\n" );
	exit( 0 );
}

$entries = scandir( $safeDir );
if ( false === $entries ) {
	fwrite( STDERR, "[fix-prefixed-safe] Failed to read {$safeDir}.\n" );
	exit( 1 );
}

$rootPhpFiles = array();
foreach ( $entries as $entry ) {
	$path = $safeDir . '/' . $entry;
	if ( is_file( $path ) && str_ends_with( $entry, '.php' ) && 'functionsList.php' !== $entry ) {
		$rootPhpFiles[] = $entry;
	}
}

if ( array() === $rootPhpFiles ) {
	fwrite( STDOUT, "[fix-prefixed-safe] No root generated PHP files found.\n" );
	exit( 0 );
}

$targetVersions = array( '8.1', '8.2', '8.3', '8.4', '8.5', '8.6' );
$copied         = 0;

foreach ( $targetVersions as $version ) {
	$versionDir = $safeDir . '/' . $version;
	if ( ! is_dir( $versionDir ) && ! mkdir( $versionDir, 0755, true ) && ! is_dir( $versionDir ) ) {
		fwrite( STDERR, "[fix-prefixed-safe] Failed to create {$versionDir}.\n" );
		exit( 1 );
	}

	foreach ( $rootPhpFiles as $file ) {
		$source = $safeDir . '/' . $file;
		$target = $versionDir . '/' . $file;
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
