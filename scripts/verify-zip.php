<?php
declare(strict_types=1);

$dist_files = glob( dirname( __DIR__ ) . '/dist/sscribe-export-site-pages-*.zip' );
if ( empty( $dist_files ) ) {
	exit( "No dist ZIP found. Run: composer release\n" );
}
usort( $dist_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
$zip_file = $dist_files[0];
$zip      = new ZipArchive();
$zip->open( $zip_file );

echo "First 30 entries in ZIP:\n";
for ( $i = 0; $i < min( 30, $zip->numFiles ); $i++ ) {
	echo '  ' . $zip->getNameIndex( $i ) . "\n";
}

echo "\nTotal files: {$zip->numFiles}\n";

$bad = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( str_contains( $name, '.cache' ) || str_contains( $name, 'tests/' ) || str_contains( $name, 'WPScan' ) || str_contains( $name, '.git' ) || str_contains( $name, 'scripts/' ) || str_contains( $name, 'phpunit' ) || str_contains( $name, 'phpstan' ) || str_contains( $name, 'phpcs' ) || str_contains( $name, 'package.json' ) || str_contains( $name, 'composer.lock' ) || str_contains( $name, '.sisyphus' ) ) {
		$bad[] = $name;
	}
}

if ( ! empty( $bad ) ) {
	echo "\nBAD entries found:\n";
	foreach ( $bad as $b ) {
		echo "  {$b}\n";
	}
} else {
	echo "\nNo dev/test files found in ZIP.\n";
}

$zip->close();
