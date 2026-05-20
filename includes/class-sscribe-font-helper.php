<?php
/**
 * SScribe Font Helper
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Font_Helper {

	public static function get_arabic_font_path( bool $bold = false ): string {
		$base   = defined( 'SSCRIBE_PLUGIN_DIR' ) ? SSCRIBE_PLUGIN_DIR : '';
		$prefix = $base . 'vendor-prefixed/mpdf/mpdf/ttfonts/';
		if ( $bold ) {
			return $prefix . 'XB RiyazBd.ttf';
		}
		return $prefix . 'XB Riyaz.ttf';
	}

	public static function font_exists( bool $bold = false ): bool {
		return file_exists( self::get_arabic_font_path( $bold ) );
	}

	public static function get_arabic_font_url( bool $bold = false ): string {
		return '';
	}
}
