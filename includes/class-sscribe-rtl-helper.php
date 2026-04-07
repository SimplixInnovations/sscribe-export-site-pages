<?php
/**
 * RTL language detection helper.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_RTL_Helper
 *
 * Centralized RTL language detection for all exporters.
 */
class SScribe_RTL_Helper {

	/**
	 * RTL language codes map.
	 */
	private static array $rtl_languages = array(
		'ar' => true,
		'he' => true,
		'fa' => true,
		'ur' => true,
		'ps' => true,
		'ku' => true,
		'sd' => true,
		'yi' => true,
		'iw' => true,
		'ji' => true,
	);

	/**
	 * Check if a language code is RTL.
	 *
	 * @param string $lang_code Language code.
	 * @return bool True if RTL.
	 */
	public static function is_rtl( string $lang_code ): bool {
		$lang_code = strtolower( substr( $lang_code, 0, 2 ) );
		return isset( self::$rtl_languages[ $lang_code ] );
	}

	/**
	 * Get all RTL language codes.
	 *
	 * @return array<string> RTL language codes.
	 */
	public static function get_rtl_languages(): array {
		return array_keys( self::$rtl_languages );
	}

	/**
	 * Get text direction for a language.
	 *
	 * @param string $lang_code Language code.
	 * @return string 'rtl' or 'ltr'.
	 */
	public static function get_direction( string $lang_code ): string {
		return self::is_rtl( $lang_code ) ? 'rtl' : 'ltr';
	}

	/**
	 * Get text alignment for a language.
	 *
	 * @param string $lang_code Language code.
	 * @return string 'right' or 'left'.
	 */
	public static function get_alignment( string $lang_code ): string {
		return self::is_rtl( $lang_code ) ? 'right' : 'left';
	}
}
