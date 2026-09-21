<?php
/**
 * Reduce TCPDF's source font catalog before Strauss parses vendor packages.
 *
 * TCPDF ships a broad generated font catalog. Strauss parses every PHP file in
 * selected packages before its own copy/prefix exclusions are applied, which
 * makes the unused TCPDF font metrics consume excessive memory. SScribe pins
 * PDF output to DejaVu Sans and strips document font-family overrides, so the
 * remaining generated fonts are unreachable at runtime.
 *
 * This script only mutates Composer's ephemeral vendor/ tree. The release tree
 * is built from vendor-prefixed/ after Strauss completes.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root      = dirname( __DIR__ );
$fonts_dir = $root . '/vendor/tecnickcom/tcpdf/fonts';

if ( ! is_dir( $fonts_dir ) ) {
	fwrite( STDERR, "[tcpdf-prune] TCPDF fonts directory is missing: {$fonts_dir}\n" );
	exit( 1 );
}

$allowed_top_level = array(
	// TCPDF initializes with Helvetica before SScribe selects DejaVu Sans.
	'helvetica.php',
	'helveticab.php',
	'helveticabi.php',
	'helveticai.php',

	// TCPDF core fallbacks are tiny and may be selected internally.
	'courier.php',
	'courierb.php',
	'courierbi.php',
	'courieri.php',
	'times.php',
	'timesb.php',
	'timesbi.php',
	'timesi.php',
	'symbol.php',
	'zapfdingbats.php',

	// SScribe's pinned Unicode/RTL family.
	'dejavusans.php',
	'dejavusans.z',
	'dejavusans.ctg.z',
	'dejavusansb.php',
	'dejavusansb.z',
	'dejavusansb.ctg.z',
	'dejavusansi.php',
	'dejavusansi.z',
	'dejavusansi.ctg.z',
	'dejavusansbi.php',
	'dejavusansbi.z',
	'dejavusansbi.ctg.z',
);

$allowed_nested = array(
	'dejavu-fonts-ttf-2.33/LICENSE',
	'dejavu-fonts-ttf-2.34/LICENSE',
);

$removed = 0;
$kept    = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $fonts_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::CHILD_FIRST
);

foreach ( $iterator as $item ) {
	$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $fonts_dir ) + 1 ) );

	if ( $item->isDir() ) {
		$children = new RecursiveDirectoryIterator( $item->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS );
		if ( 0 === iterator_count( $children ) ) {
			@rmdir( $item->getPathname() );
		}
		continue;
	}

	$allowed = false;
	if ( false === strpos( $relative, '/' ) && in_array( $relative, $allowed_top_level, true ) ) {
		$allowed = true;
	} elseif ( in_array( $relative, $allowed_nested, true ) ) {
		$allowed = true;
	}

	if ( $allowed ) {
		++$kept;
		continue;
	}

	if ( ! unlink( $item->getPathname() ) ) {
		fwrite( STDERR, "[tcpdf-prune] Failed to remove unreachable font asset: {$relative}\n" );
		exit( 1 );
	}
	++$removed;
}

foreach ( array_merge( $allowed_top_level, $allowed_nested ) as $required ) {
	if ( ! is_file( $fonts_dir . '/' . $required ) ) {
		fwrite( STDERR, "[tcpdf-prune] Required TCPDF font asset is missing after pruning: {$required}\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "[tcpdf-prune] Kept {$kept} required font/license files; removed {$removed} unreachable assets.\n" );
exit( 0 );
