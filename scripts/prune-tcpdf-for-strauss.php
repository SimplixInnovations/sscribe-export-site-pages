<?php
/**
 * Stage the deterministic TCPDF 7 font subset before Strauss prefixes vendors.
 *
 * TCPDF 7 no longer ships the legacy `tecnickcom/tcpdf/fonts/*.php` tree.
 * Runtime font data is provided by `tecnickcom/tc-lib-pdf-font` under
 * `target/fonts/`. Composer dependency scripts do not build those assets for
 * dependencies, so SScribe keeps the reviewed runtime subset in
 * `scripts/resources/tcpdf-fonts/` and copies it into Composer's ephemeral
 * vendor tree before Strauss walks the dependency graph.
 *
 * Only Composer's generated vendor/ tree is mutated. The tracked source subset
 * remains the reproducible build input and vendor-prefixed/ remains the
 * production dependency tree.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root          = dirname( __DIR__ );
$tcpdf_entry   = $root . '/vendor/tecnickcom/tcpdf/tcpdf.php';
$font_package  = $root . '/vendor/tecnickcom/tc-lib-pdf-font';
$resource_dir  = $root . '/scripts/resources/tcpdf-fonts';
$target_dir    = $font_package . '/target/fonts';

if ( ! is_file( $tcpdf_entry ) ) {
	fwrite( STDERR, "[tcpdf-fonts] TCPDF entrypoint is missing: {$tcpdf_entry}\n" );
	exit( 1 );
}
if ( ! is_dir( $font_package ) ) {
	fwrite( STDERR, "[tcpdf-fonts] tc-lib-pdf-font package is missing: {$font_package}\n" );
	exit( 1 );
}
if ( ! is_dir( $resource_dir ) || is_link( $resource_dir ) ) {
	fwrite( STDERR, "[tcpdf-fonts] Tracked font resource directory is missing or unsafe: {$resource_dir}\n" );
	exit( 1 );
}

$required = array(
	'core/LICENSE',
	'core/courier.json',
	'core/courierb.json',
	'core/courierbi.json',
	'core/courieri.json',
	'core/helvetica.json',
	'core/helveticab.json',
	'core/helveticabi.json',
	'core/helveticai.json',
	'core/symbol.json',
	'core/times.json',
	'core/timesb.json',
	'core/timesbi.json',
	'core/timesi.json',
	'core/zapfdingbats.json',
	'dejavu/LICENSE',
	'dejavu/dejavusans.json',
	'dejavu/dejavusans.z',
	'dejavu/dejavusans.ctg.z',
	'dejavu/dejavusansb.json',
	'dejavu/dejavusansb.z',
	'dejavu/dejavusansb.ctg.z',
	'dejavu/dejavusansi.json',
	'dejavu/dejavusansi.z',
	'dejavu/dejavusansi.ctg.z',
	'dejavu/dejavusansbi.json',
	'dejavu/dejavusansbi.z',
	'dejavu/dejavusansbi.ctg.z',
);

foreach ( $required as $relative ) {
	$source = $resource_dir . '/' . $relative;
	if ( ! is_file( $source ) || is_link( $source ) ) {
		fwrite( STDERR, "[tcpdf-fonts] Required tracked font asset is missing or unsafe: {$relative}\n" );
		exit( 1 );
	}
	$size = filesize( $source );
	if ( false === $size || 0 === $size ) {
		fwrite( STDERR, "[tcpdf-fonts] Required tracked font asset is empty: {$relative}\n" );
		exit( 1 );
	}
}

$remove_tree = static function ( string $directory ) use ( &$remove_tree ): bool {
	if ( is_link( $directory ) ) {
		return false;
	}
	if ( ! is_dir( $directory ) ) {
		return true;
	}
	$entries = scandir( $directory );
	if ( false === $entries ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $directory . '/' . $entry;
		if ( is_link( $path ) ) {
			return false;
		}
		if ( is_dir( $path ) ) {
			if ( ! $remove_tree( $path ) ) {
				return false;
			}
		} elseif ( ! unlink( $path ) ) {
			return false;
		}
	}
	return rmdir( $directory );
};

if ( file_exists( $target_dir ) ) {
	if ( ! $remove_tree( $target_dir ) ) {
		fwrite( STDERR, "[tcpdf-fonts] Failed to replace the generated target font tree safely.\n" );
		exit( 1 );
	}
}

$copied = 0;
foreach ( $required as $relative ) {
	$source      = $resource_dir . '/' . $relative;
	$destination = $target_dir . '/' . $relative;
	$directory   = dirname( $destination );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
		fwrite( STDERR, "[tcpdf-fonts] Failed to create font target directory: {$directory}\n" );
		exit( 1 );
	}
	if ( ! copy( $source, $destination ) ) {
		fwrite( STDERR, "[tcpdf-fonts] Failed to stage font asset: {$relative}\n" );
		exit( 1 );
	}
	if ( hash_file( 'sha256', $source ) !== hash_file( 'sha256', $destination ) ) {
		fwrite( STDERR, "[tcpdf-fonts] Font staging checksum mismatch: {$relative}\n" );
		exit( 1 );
	}
	++$copied;
}

fwrite( STDOUT, "[tcpdf-fonts] Staged {$copied} deterministic TCPDF 7 font/license assets.\n" );
exit( 0 );
