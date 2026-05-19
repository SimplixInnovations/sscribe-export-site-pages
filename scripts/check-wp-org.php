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

$all = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$all[] = $zip->getNameIndex( $i );
}

echo "=== WordPress.org Submission Checklist ===\n\n";

$checks = array(
	'LICENSE file'          => 'sscribe-export-site-pages/LICENSE',
	'readme.txt'            => 'sscribe-export-site-pages/readme.txt',
	'Main plugin file'      => 'sscribe-export-site-pages/sscribe-export-site-pages.php',
	'uninstall.php'         => 'sscribe-export-site-pages/uninstall.php',
	'No CONTRIBUTING.md'    => 'CONTRIBUTING.md',
	'No CHANGELOG.md'       => 'CHANGELOG.md',
	'No tests/'             => 'tests/',
	'No scripts/'           => 'scripts/',
	'No .git/'              => '.git/',
	'No .cache/'            => '.cache/',
	'No .sisyphus/'         => '.sisyphus/',
	'No vendor/ (raw)'      => 'vendor/',
	'No composer.lock'      => 'composer.lock',
	'No package.json'       => 'package.json',
	'No phpunit.xml'        => 'phpunit.xml',
	'No phpcs.xml'          => 'phpcs.xml',
	'No phpstan.neon'       => 'phpstan.neon',
	'No opencode.json'      => 'opencode.json',
	'No .editorconfig'      => '.editorconfig',
	'No .wp-env.json'       => '.wp-env.json',
);

foreach ( $checks as $label => $needle ) {
	$found = false;
	foreach ( $all as $file ) {
		if ( str_contains( $file, $needle ) ) {
			$found = true;
			break;
		}
	}
	$should_exist = ! str_starts_with( $label, 'No ' );
	$status       = $should_exist ? ( $found ? 'PASS' : 'FAIL' ) : ( ! $found ? 'PASS' : 'FAIL' );
	echo ( 'PASS' === $status ? '✅' : '❌' ) . " {$label}\n";
}

echo "\n=== Top-level structure ===\n";
$top = array();
foreach ( $all as $file ) {
	$parts = explode( '/', $file );
	if ( count( $parts ) >= 2 ) {
		$dir = $parts[1];
		if ( ! isset( $top[ $dir ] ) ) {
			$top[ $dir ] = 0;
		}
		$top[ $dir ]++;
	}
}
ksort( $top );
foreach ( $top as $dir => $count ) {
	echo "  {$dir}/ ({$count} files)\n";
}

echo "\nTotal: {$zip->numFiles} files\n";
$zip->close();
