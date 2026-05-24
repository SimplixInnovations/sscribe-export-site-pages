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

	'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord'           => 'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord',
	'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory'         => 'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory',
	'SScribeVendor\\PhpOffice\\PhpWord\\Settings'          => 'SScribeVendor\\PhpOffice\\PhpWord\\Settings',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font'       => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC'        => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem'   => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc'    => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth' => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth',
	'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter' => 'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section'  => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun'  => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun',
);

foreach ( $sscribe_vendor_aliases as $sscribe_source => $sscribe_target ) {
	if ( class_exists( $sscribe_source ) && ! class_exists( $sscribe_target ) ) {
		class_alias( $sscribe_source, $sscribe_target );
	}
}
