<?php
/**
 * SScribe Vendor Compatibility Shim
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_vendor_aliases = array(

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
	'TCPDF'                                      => 'SScribeVendor_TCPDF',
);

foreach ( $sscribe_vendor_aliases as $sscribe_source => $sscribe_target ) {
	if ( class_exists( $sscribe_source ) && ! class_exists( $sscribe_target, false ) ) {
		class_alias( $sscribe_source, $sscribe_target );
	}
}
