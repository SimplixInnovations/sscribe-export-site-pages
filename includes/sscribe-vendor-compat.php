<?php
/**
 * Compatibility aliases for local development without a generated vendor-prefixed tree.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_vendor_aliases = array(
	'Dompdf\\Dompdf'                           => 'SScribeVendor\\Dompdf\\Dompdf',
	'Dompdf\\Options'                          => 'SScribeVendor\\Dompdf\\Options',
	'PhpOffice\\PhpWord\\PhpWord'              => 'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord',
	'PhpOffice\\PhpWord\\IOFactory'            => 'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory',
	'PhpOffice\\PhpWord\\Settings'             => 'SScribeVendor\\PhpOffice\\PhpWord\\Settings',
	'PhpOffice\\PhpWord\\Style\\Font'          => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font',
	'PhpOffice\\PhpWord\\Style\\TOC'           => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC',
	'PhpOffice\\PhpWord\\Style\\ListItem'      => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem',
	'PhpOffice\\PhpWord\\SimpleType\\Jc'       => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc',
	'PhpOffice\\PhpWord\\SimpleType\\TblWidth' => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth',
	'PhpOffice\\PhpWord\\Shared\\Converter'    => 'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter',
	'PhpOffice\\PhpWord\\Element\\Section'     => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section',
	'PhpOffice\\PhpWord\\Element\\TextRun'     => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun',
);

foreach ( $sscribe_vendor_aliases as $source => $target ) {
	if ( class_exists( $source ) && ! class_exists( $target ) ) {
		class_alias( $source, $target );
	}
}
