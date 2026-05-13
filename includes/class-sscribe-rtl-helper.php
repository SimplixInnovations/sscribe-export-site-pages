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
	 *
	 * @var array<string, bool>
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
	 * Get all known ISO 639-1 two-letter language codes.
	 *
	 * Single authoritative source for all language code lookups across the plugin.
	 * Used by SScribe_Zip_Handler::extract_lang_from_filename() and any other code
	 * that needs to validate 2-letter language suffixes against a known list.
	 *
	 * Adding a new language code here makes it available everywhere.
	 *
	 * @return array<string> All known 2-letter language codes (uppercase).
	 */
	public static function get_all_known_codes(): array {
		return array(
			'AR',
			'EN',
			'FR',
			'DE',
			'ES',
			'IT',
			'PT',
			'NL',
			'RU',
			'ZH',
			'JA',
			'KO',
			'HE',
			'FA',
			'UR',
			'TR',
			'PL',
			'SV',
			'DA',
			'FI',
			'NB',
			'CS',
			'SK',
			'HU',
			'RO',
			'BG',
			'HR',
			'SR',
			'UK',
			'VI',
			'TH',
			'ID',
			'MS',
			'EL',
			'HI',
			'BN',
			'LT',
			'LV',
			'ET',
			'SL',
			'PS',
			'KU',
			'SD',
			'YI',
			'IW',
			'JI',
		);
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
