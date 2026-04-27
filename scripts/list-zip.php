<?php
declare(strict_types=1);

$zip_file = dirname( __DIR__ ) . '/dist/sscribe-export-site-pages-3.32.0.zip';
$zip      = new ZipArchive();
$zip->open( $zip_file );

echo "=== ALL FILES IN ZIP ===\n";
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	echo $zip->getNameIndex( $i ) . "\n";
}
echo "\nTotal: {$zip->numFiles} files\n";
$zip->close();
