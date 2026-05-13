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
 *
 * Note: As of v3.7.6, NotoSansArabic font files are no longer shipped with the plugin.
 * Font constants SSCRIBE_FONT_ARABIC / SSCRIBE_FONT_ARABIC_BOLD have been removed.
 * As of v3.7.7, Arabic PDF rendering uses XB Riyaz (bundled with mPDF) — purpose-built
 * for Arabic script with professional typography and zero MarkGlyphSets issues.
 */
class SScribe_Font_Helper {

	/**
	 * Get the path to the Arabic font file (XB Riyaz from mPDF).
	 *
	 * @param bool $bold Whether to get the bold variant.
	 * @return string Path to the font file.
	 */
	public static function get_arabic_font_path( bool $bold = false ): string {
		$base   = defined( 'SSCRIBE_PLUGIN_DIR' ) ? SSCRIBE_PLUGIN_DIR : '';
		$prefix = $base . 'vendor-prefixed/mpdf/mpdf/ttfonts/';
		if ( $bold ) {
			return $prefix . 'XB RiyazBd.ttf';
		}
		return $prefix . 'XB Riyaz.ttf';
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
	 * DejaVu Sans is bundled with mPDF in vendor-prefixed and is not directly
	 * web-accessible. This method returns an empty string; the HTML exporter
	 * now relies on system fonts for Arabic rendering.
	 *
	 * @param bool $bold Whether to get the bold variant.
	 * @return string Empty string — font is not served via URL.
	 */
	public static function get_arabic_font_url( bool $bold = false ): string {
		return '';
	}
}
