<?php
/**
 * SScribe Test Prefixed Script
 *
 * @package SScribe_Export_Site_Pages
 */
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/sscribe-prefixed-runtime-shim.php';
require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
echo "Autoload OK\n";

if ( class_exists( '\SScribeVendor\Dompdf\Dompdf' ) ) {
	echo "Dompdf: OK\n";
} else {
	echo "Dompdf: MISSING\n";
}

if ( class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ) {
	echo "PhpWord: OK\n";
} else {
	echo "PhpWord: MISSING\n";
}

echo "Total prefixed classes: " . count( require dirname( __DIR__ ) . '/vendor-prefixed/autoload-classmap.php' ) . "\n";
