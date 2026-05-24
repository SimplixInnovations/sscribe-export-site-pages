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
		'iw' => true, // Legacy ISO 639-1 code for Hebrew (same as 'he', used by some older WPML versions)
		'fa' => true,
		'ur' => true,
		'ps' => true,
		'ku' => true,
		'sd' => true,
		'yi' => true,
		'ji' => true, // Legacy ISO 639-1 code for Yiddish (same as 'yi')
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
			// 2-letter codes (primary)
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
			// 3-letter codes (ISO 639-2)
			'ARB', // Arabic
			'ENG', // English
			'FRA', // French
			'DEU', // German
			'SPA', // Spanish
			'ITA', // Italian
			'POR', // Portuguese
			'NLD', // Dutch
			'RUS', // Russian
			'ZHO', // Chinese
			'JPN', // Japanese
			'KOR', // Korean
			'HEB', // Hebrew
			'FAS', // Persian
			'URD', // Urdu
			'TUR', // Turkish
			'POL', // Polish
			'SWE', // Swedish
			'DAN', // Danish
			'FIN', // Finnish
			'NOR', // Norwegian (Bokmål)
			'CES', // Czech
			'SLK', // Slovak
			'HUN', // Hungarian
			'RON', // Romanian
			'BUL', // Bulgarian
			'HRV', // Croatian
			'SRP', // Serbian
			'UKR', // Ukrainian
			'VIE', // Vietnamese
			'THA', // Thai
			'IND', // Indonesian
			'MSA', // Malay
			'ELL', // Greek
			'HIN', // Hindi
			'BEN', // Bengali
			'LIT', // Lithuanian
			'LAV', // Latvian
			'EST', // Estonian
			'SLV', // Slovenian
			'PSH', // Pashto
			'KUR', // Kurdish
			'SAD', // Sindhi
			'YID', // Yiddish
			// Additional 3-letter codes (WPML and common variants)
			'ZHT', // Traditional Chinese (WPML)
			'ZHS', // Simplified Chinese
			'AZE', // Azerbaijani
			'KAZ', // Kazakh
			'UZB', // Uzbek
			'TGL', // Tagalog
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
