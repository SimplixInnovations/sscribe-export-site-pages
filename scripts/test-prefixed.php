<?php
/**
 * SScribe Test Prefixed Script
 *
 * @package SScribe_Export_Site_Pages
 */
declare(strict_types=1);

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/fake-wp/' );
}

require_once $root . '/vendor-prefixed/autoload.php';
require_once $root . '/includes/sscribe-prefixed-runtime-shim.php';

$required_classes = array(
	'SScribeVendor\\Mpdf\\Mpdf',
	'SScribeVendor\\Mpdf\\HTMLParserMode',
	'SScribeVendor\\Mpdf\\Output\\Destination',
	'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord',
	'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun',
	'PhpOffice\\PhpWord\\IOFactory',
);

$missing = array();
foreach ( $required_classes as $class_name ) {
	if ( ! class_exists( $class_name ) ) {
		$missing[] = $class_name;
	}
}

if ( $missing ) {
	fwrite( STDERR, "Missing prefixed runtime classes:\n  " . implode( "\n  ", $missing ) . "\n" );
	exit( 1 );
}

$classmap = array();
$classmap_path = $root . '/vendor-prefixed/composer/autoload_classmap.php';
if ( file_exists( $classmap_path ) ) {
	$classmap = require $classmap_path;
}

echo "Prefixed runtime OK\n";
echo 'Total prefixed classes: ' . count( $classmap ) . "\n";
