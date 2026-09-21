<?php
/**
 * SScribe Vendor Compatibility Shim
 *
 * Provides the prefixed class names expected by SScribe when development uses
 * the plain Composer vendor/ tree instead of the Strauss-generated
 * vendor-prefixed/ tree.
 *
 * The aliases are resolved lazily. This is critical when a prefixed vendor
 * autoloader is already registered: Strauss must get the first opportunity to
 * load its real prefixed classes, and this shim must never eagerly load
 * upstream classes into the same process.
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
	'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord'              => 'PhpOffice\\PhpWord\\PhpWord',
	'SScribeVendor\\PhpOffice\\PhpWord\\IOFactory'            => 'PhpOffice\\PhpWord\\IOFactory',
	'SScribeVendor\\PhpOffice\\PhpWord\\Settings'             => 'PhpOffice\\PhpWord\\Settings',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\Font'          => 'PhpOffice\\PhpWord\\Style\\Font',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC'           => 'PhpOffice\\PhpWord\\Style\\TOC',
	'SScribeVendor\\PhpOffice\\PhpWord\\Style\\ListItem'      => 'PhpOffice\\PhpWord\\Style\\ListItem',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\Jc'       => 'PhpOffice\\PhpWord\\SimpleType\\Jc',
	'SScribeVendor\\PhpOffice\\PhpWord\\SimpleType\\TblWidth' => 'PhpOffice\\PhpWord\\SimpleType\\TblWidth',
	'SScribeVendor\\PhpOffice\\PhpWord\\Shared\\Converter'    => 'PhpOffice\\PhpWord\\Shared\\Converter',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\Section'     => 'PhpOffice\\PhpWord\\Element\\Section',
	'SScribeVendor\\PhpOffice\\PhpWord\\Element\\TextRun'     => 'PhpOffice\\PhpWord\\Element\\TextRun',
	'SScribeVendor_TCPDF'                                           => 'TCPDF',
);

spl_autoload_register(
	static function ( string $sscribe_target ) use ( $sscribe_vendor_aliases ): void {
		if ( ! isset( $sscribe_vendor_aliases[ $sscribe_target ] ) ) {
			return;
		}

		// A higher-priority prefixed autoloader may already have resolved the
		// target before this callback is reached. Never replace a real class.
		if ( class_exists( $sscribe_target, false ) ) {
			return;
		}

		$sscribe_source = $sscribe_vendor_aliases[ $sscribe_target ];
		if ( class_exists( $sscribe_source ) && ! class_exists( $sscribe_target, false ) ) {
			class_alias( $sscribe_source, $sscribe_target );
		}
	},
	true,
	false
);
