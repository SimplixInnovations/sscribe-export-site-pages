<?php
/**
 * SScribe Font Helper
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility for Arabic font path resolution.
 */
class SScribe_Font_Helper {

	/**
	 * Get the path to the Arabic font file.
	 *
	 * Resolves the bundled Amiri font from `assets/fonts/amiri/`. Amiri is
	 * the OFL-licensed classical Naskh face we ship for RTL/Arabic PDF
	 * rendering. It replaces XB Riyaz (which was pulled from mPDF's vendor
	 * default font directory and never actually wired into our fontdata).
	 *
	 * @param bool $bold Whether to return bold variant.
	 * @return string Font file path.
	 */
	public static function get_arabic_font_path( bool $bold = false ): string {
		$base   = defined( 'SSCRIBE_PLUGIN_DIR' ) ? SSCRIBE_PLUGIN_DIR : '';
		$prefix = $base . 'assets/fonts/amiri/';
		if ( $bold ) {
			return $prefix . 'Amiri-Bold.ttf';
		}
		return $prefix . 'Amiri-Regular.ttf';
	}

	/**
	 * Check if the Arabic font file exists.
	 *
	 * @param bool $bold Whether to check bold variant.
	 * @return bool
	 */
	public static function font_exists( bool $bold = false ): bool {
		return file_exists( self::get_arabic_font_path( $bold ) );
	}

	/**
	 * Get the URL to the Arabic font file.
	 *
	 * @param bool $bold Whether to return bold variant.
	 * @return string Font URL (empty if unavailable).
	 */
	public static function get_arabic_font_url( bool $bold = false ): string {
		return '';
	}
}
