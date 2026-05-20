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

class SScribe_RTL_Helper {

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

	public static function is_rtl( string $lang_code ): bool {
		$lang_code = strtolower( substr( $lang_code, 0, 2 ) );
		return isset( self::$rtl_languages[ $lang_code ] );
	}

	public static function get_rtl_languages(): array {
		return array_keys( self::$rtl_languages );
	}

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

	public static function get_direction( string $lang_code ): string {
		return self::is_rtl( $lang_code ) ? 'rtl' : 'ltr';
	}

	public static function get_alignment( string $lang_code ): string {
		return self::is_rtl( $lang_code ) ? 'right' : 'left';
	}
}
