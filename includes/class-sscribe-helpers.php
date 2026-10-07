<?php
/**
 * SScribe Helpers
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
 * General utility functions for the plugin.
 */
class SScribe_Helpers {

	/**
	 * Relative path to icon directory.
	 *
	 * @var string
	 */

	/**
	 * Cache of rendered icon HTML.
	 *
	 * @var array
	 */
	private static array $icon_cache = array();

	private const KNOWN_ICONS = array( 'check', 'chevron-down', 'clock', 'download', 'download-package', 'eye', 'file-doc', 'file-html', 'file-log', 'file-md', 'file-pdf', 'file-search', 'file-text', 'globe', 'info', 'refresh-cw', 'search', 'settings', 'warning-circle', 'x', 'loader', 'article', 'check-circle', 'copy', 'list', 'warning' );



	/**
	 * Render an icon glyph from the embedded icon font.
	 *
	 * Icons draw in currentColor so they inherit the surrounding text
	 * color (including the dark theme) and size via font-size.
	 *
	 * @param string $name      Icon name (see KNOWN_ICONS).
	 * @param int    $size      Glyph size in pixels.
	 * @param string $css_class Additional CSS classes.
	 * @return string Markup, or an empty string for unknown names.
	 */
	public static function get_icon( string $name, int $size = 20, string $css_class = '' ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]{1,50}$/D', $name ) ) {
			return '';
		}
		if ( ! in_array( $name, self::KNOWN_ICONS, true ) ) {
			return '';
		}

		$size      = max( 1, min( 256, $size ) );
		$cache_key = $name . ':' . $size . ':' . $css_class;
		if ( isset( self::$icon_cache[ $cache_key ] ) ) {
			return self::$icon_cache[ $cache_key ];
		}

		$icon_class = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
		if ( '' !== $css_class ) {
			$extra = preg_split( '/\s+/', trim( $css_class ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( is_array( $extra ) ? $extra : array() as $part ) {
				$cleaned = sanitize_html_class( (string) $part );
				if ( '' !== $cleaned ) {
					$icon_class .= ' ' . $cleaned;
				}
			}
		}

		$html                           = sprintf(
			'<i class="%s" style="font-size:%dpx" aria-hidden="true"></i>',
			esc_attr( $icon_class ),
			absint( $size )
		);
		self::$icon_cache[ $cache_key ] = $html;
		return $html;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		$trusted_headers = apply_filters( 'sscribe_trusted_ip_headers', array() );
		$trusted_headers = is_array( $trusted_headers ) ? array_slice( $trusted_headers, 0, 10 ) : array();

		foreach ( $trusted_headers as $header ) {
			$header = sanitize_key( (string) $header );
			if ( '' === $header ) {
				continue;
			}

			$header_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			if ( isset( $_SERVER[ $header_key ] ) && is_string( $_SERVER[ $header_key ] ) && '' !== $_SERVER[ $header_key ] ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header_key ] ) ) );
				$ip = trim( $ip[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is sanitized to text and then passed to filter_var(FILTER_VALIDATE_IP) below; non-IP input is rejected and `0.0.0.0` is returned instead.
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		if ( '' === $remote_addr ) {
			return '0.0.0.0';
		}
		$ip = filter_var( $remote_addr, FILTER_VALIDATE_IP );

		if ( empty( $ip ) ) {
			return '0.0.0.0';
		}

		return $ip;
	}

	/**
	 * Strip page builder attributes from HTML.
	 *
	 * @param string $html Raw HTML content.
	 * @return string Cleaned HTML.
	 */
	public static function strip_page_builder_attributes( string $html ): string {
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html ) ?? $html;

		$attribute_patterns = array(
			'/\s*style="[^"]*"/i',
			"/\s*style='[^']*'/i",
			'/\s*class="[^"]*"/i',
			"/\s*class='[^']*'/i",
			'/\s*data-elementor(-[a-z]+)?="[^"]*"/i',
			'/\s*data-(widget|column|section)-[a-z0-9_-]{0,30}="[^"]*"/i',
			'/\s*id="elementor-[^"]*"/i',
		);

		// Attributes are removed only inside tags, so page text such as an
		// escaped code sample ("&lt;div class=...&gt;") is left as written.
		return preg_replace_callback(
			'/<[a-zA-Z][^>]*>/',
			static function ( array $m ) use ( $attribute_patterns ): string {
				return preg_replace( $attribute_patterns, '', $m[0] ) ?? $m[0];
			},
			$html
		) ?? $html;
	}

	/**
	 * Multibyte-safe wrappers that gracefully degrade when the mbstring
	 * extension is unavailable. Each function delegates to mb_* when
	 * present and falls back to the byte-oriented equivalent otherwise.
	 *
	 * @package SScribe_Export_Site_Pages
	 */
	/**
	 * Multibyte-safe strlen that falls back to byte counting when the
	 * mbstring extension is unavailable.
	 *
	 * @param string $string Input string.
	 * @return int Character count.
	 */
	public static function mb_strlen( string $string ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $string ) : strlen( $string );
	}

	/**
	 * Multibyte-safe substr that falls back to byte slicing.
	 *
	 * @param string   $string Input string.
	 * @param int      $start  Start offset (negative counts from end).
	 * @param int|null $length Optional length; null reads to the end.
	 * @return string The substring.
	 */
	public static function mb_substr( string $string, int $start, ?int $length = null ): string {
		if ( ! function_exists( 'mb_substr' ) ) {
			return null === $length ? substr( $string, $start ) : substr( $string, $start, $length );
		}
		return null === $length ? (string) mb_substr( $string, $start ) : (string) mb_substr( $string, $start, $length );
	}

	/**
	 * Multibyte-safe strcut that falls back to byte slicing.
	 *
	 * @param string   $string Input string.
	 * @param int      $start  Start offset.
	 * @param int|null $length Optional length; null reads to the end.
	 * @return string The substring.
	 */
	public static function mb_strcut( string $string, int $start, ?int $length = null ): string {
		if ( ! function_exists( 'mb_strcut' ) ) {
			return null === $length ? substr( $string, $start ) : substr( $string, $start, $length );
		}
		return null === $length ? (string) mb_strcut( $string, $start ) : (string) mb_strcut( $string, $start, $length );
	}

	/**
	 * Multibyte-safe strpos that falls back to byte searching.
	 *
	 * @param string $haystack String to search.
	 * @param string $needle   Needle substring.
	 * @param int    $offset    Search offset (negative counts from end).
	 * @return int|false Position of needle, or false if not found.
	 */
	public static function mb_strpos( string $haystack, string $needle, int $offset = 0 ): int|false {
		if ( ! function_exists( 'mb_strpos' ) ) {
			return strpos( $haystack, $needle, $offset );
		}
		return mb_strpos( $haystack, $needle, $offset );
	}

	/**
	 * Multibyte-safe strtolower that falls back to byte lowering.
	 *
	 * @param string $string Input string.
	 * @return string Lower-cased string.
	 */
	public static function mb_strtolower( string $string ): string {
		return function_exists( 'mb_strtolower' ) ? (string) mb_strtolower( $string ) : strtolower( $string );
	}

	/**
	 * Multibyte-safe strtoupper that falls back to byte upper-casing.
	 *
	 * @param string $string Input string.
	 * @return string Upper-cased string.
	 */
	public static function mb_strtoupper( string $string ): string {
		return function_exists( 'mb_strtoupper' ) ? (string) mb_strtoupper( $string ) : strtoupper( $string );
	}

	/**
	 * Heuristic: does this string look like a machine-generated payload
	 * (URL, base64 blob, hash) that is safe to truncate for memory reasons,
	 * rather than natural human text?
	 *
	 * Natural language contains spaces, so spaced text is never machine
	 * classified. Spaceless strings are machine classified only when they
	 * carry a URL scheme or a high ratio of ASCII machine characters; CJK
	 * and other spaceless human scripts are explicitly preserved.
	 *
	 * @param string $text Text to test.
	 * @return bool True when the string looks like a machine payload.
	 */
	public static function is_machine_style_string( string $text ): bool {
		// Prose contains spaces; machine payloads (URLs, base64, hashes)
		// do not. A space is proof of human text, never machine content.
		if ( false !== self::mb_strpos( $text, ' ', 0 ) ) {
			return false;
		}

		// CJK / Hangul / Kana / full-width forms need no spaces either;
		// truncating them mid-character corrupts the export.
		if ( preg_match( '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u', $text ) ) {
			return false;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $text ) ) {
			return true;
		}

		$ascii_machine_count = preg_match_all( '/[A-Za-z0-9=\/\+_\-:.;?&%@#]/', $text );
		$total_length        = self::mb_strlen( $text );

		return $total_length > 0 && ( $ascii_machine_count / $total_length ) > 0.6;
	}
}
