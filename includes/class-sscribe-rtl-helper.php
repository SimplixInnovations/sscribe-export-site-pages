<?php
/**
 * SScribe RTL Helper
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility for RTL language detection and text direction.
 */
class SScribe_RTL_Helper {

	/**
	 * Map of RTL language codes.
	 *
	 * @var array<string, true>
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
	 * @return bool
	 */
	public static function is_rtl( string $lang_code ): bool {
		$lang_code = strtolower( substr( $lang_code, 0, 2 ) );
		return isset( self::$rtl_languages[ $lang_code ] );
	}

	/**
	 * Get all known RTL language codes.
	 *
	 * @return array
	 */
	public static function get_rtl_languages(): array {
		return array_keys( self::$rtl_languages );
	}

	/**
	 * Get all known language codes.
	 *
	 * @return array
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
