<?php
/**
 * Arabic word segmentation utility.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Arabic_Segmenter
 *
 * Accurate word counting for Arabic and RTL languages.
 */
class SScribe_Arabic_Segmenter {

	/**
	 * Count words in text, handling Arabic and mixed content.
	 *
	 * @param string $text     Text to count.
	 * @param string $language Language code.
	 * @return int Word count.
	 */
	public static function count_words( string $text, string $language = 'en' ): int {
		if ( empty( $text ) ) {
			return 0;
		}

		$text = wp_strip_all_tags( $text );
		$text = trim( $text );

		if ( empty( $text ) ) {
			return 0;
		}

		$language = strtolower( $language );

		if ( in_array( $language, array( 'ar', 'fa', 'ur' ), true ) ) {
			return self::count_arabic_words( $text );
		}

		$words = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return $words ? count( array_filter( $words ) ) : 0;
	}

	/**
	 * Count Arabic words by stripping diacritics and tatweel.
	 *
	 * @param string $text The Arabic text to count words in.
	 * @return int Word count.
	 */
	private static function count_arabic_words( string $text ): int {
		$text  = preg_replace( '/[\x{064B}-\x{0652}]/u', '', $text ) ?? $text;
		$text  = preg_replace( '/\x{0640}/u', '', $text ) ?? $text;
		$words = preg_split( '/[\s\p{P}]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return $words ? count( array_filter( $words ) ) : 0;
	}

	/**
	 * Get reading time in minutes.
	 *
	 * @param string $text     Text to analyze.
	 * @param string $language Language code.
	 * @return int Reading time in minutes (minimum 1).
	 */
	public static function get_reading_time( string $text, string $language = 'en' ): int {
		$word_count = self::count_words( $text, $language );
		$wpm        = 'ar' === strtolower( $language ) ? 138 : 200;
		return max( 1, (int) ceil( $word_count / $wpm ) );
	}
}
