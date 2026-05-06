<?php
/**
 * Font helper for RTL support.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Font_Helper
 *
 * Provides font path utilities for RTL language support.
 */
class SScribe_Font_Helper {

	/**
	 * Get the path to the Arabic font file.
	 *
	 * @param bool $bold Whether to get the bold variant.
	 * @return string Path to the font file.
	 */
	public static function get_arabic_font_path( bool $bold = false ): string {
		if ( $bold ) {
			return defined( 'SSCRIBE_FONT_ARABIC_BOLD' ) ? SSCRIBE_FONT_ARABIC_BOLD : SSCRIBE_PLUGIN_DIR . 'assets/fonts/noto/NotoSansArabic-Bold.ttf';
		}
		return defined( 'SSCRIBE_FONT_ARABIC' ) ? SSCRIBE_FONT_ARABIC : SSCRIBE_PLUGIN_DIR . 'assets/fonts/noto/NotoSansArabic-Regular.ttf';
	}

	/**
	 * Check if the Arabic font file exists.
	 *
	 * @param bool $bold Whether to check the bold variant.
	 * @return bool True if font exists.
	 */
	public static function font_exists( bool $bold = false ): bool {
		return file_exists( self::get_arabic_font_path( $bold ) );
	}

	/**
	 * Get the font URL (for web use).
	 *
	 * @param bool $bold Whether to get the bold variant.
	 * @return string URL to the font file.
	 */
	public static function get_arabic_font_url( bool $bold = false ): string {
		$filename = $bold ? 'NotoSansArabic-Bold.ttf' : 'NotoSansArabic-Regular.ttf';
		return SSCRIBE_PLUGIN_URL . 'assets/fonts/notosansarabic/' . $filename;
	}
}
