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

echo "=== ALL FILES IN ZIP ===\n";
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	echo $zip->getNameIndex( $i ) . "\n";
}
echo "\nTotal: {$zip->numFiles} files\n";
$zip->close();
