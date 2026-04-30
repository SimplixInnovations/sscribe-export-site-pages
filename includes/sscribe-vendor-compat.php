<?php
/**
 * Compatibility aliases for local development without a generated vendor-prefixed tree.
 *
 * Maps original vendor class names to Strauss-prefixed SScribeVendor namespace.
 * In production builds, Strauss handles this automatically via composer.
 * This file provides fallback aliases for development environments.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_vendor_aliases = array(
	// PhpOffice\PhpWord aliases.
	'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord'              => 'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord',
	'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory'            => 'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory',
	'SScribeVendor\\PhpOffice\\PhpWord\\Settings'             => 'SScribeVendor\\PhpOffice\\PhpWord\\Settings',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font'          => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC'           => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem'      => 'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc'       => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth' => 'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth',
	'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter'    => 'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section'     => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun'     => 'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun',
);

foreach ( $sscribe_vendor_aliases as $source => $target ) {
	if ( class_exists( $source ) && ! class_exists( $target ) ) {
		class_alias( $source, $target );
	}
}
