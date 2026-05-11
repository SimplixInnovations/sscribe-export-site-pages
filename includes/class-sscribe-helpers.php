<?php
/**
 * Helper functions for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Helpers
 *
 * Utility functions for the SScribe plugin.
 */
class SScribe_Helpers {

	/**
	 * Icons directory relative path.
	 *
	 * @var string
	 */
	private static string $icons_dir = 'assets/icons/';

	/**
	 * SVG icon content cache.
	 *
	 * @var array<string, string>
	 */
	private static array $icon_cache = array();

	/**
	 * Get the absolute URL for an icon.
	 *
	 * @param string $name Icon name (without .svg extension).
	 * @return string Absolute URL to the icon.
	 */
	public static function icon_url( string $name ): string {
		return SSCRIBE_PLUGIN_URL . self::$icons_dir . $name . '.svg';
	}

	/**
	 * Get an SVG icon as an <img> tag.
	 *
	 * Uses CSS filter technique for color inheritance so icons
	 * match their parent CSS color without inline styles.
	 * For accessibility, icons are aria-hidden by default (decorative).
	 *
	 * @param string $name       Icon name (without .svg extension).
	 * @param int    $size       Icon size in pixels.
	 * @param string $css_class  Additional CSS class.
	 * @return string HTML <img> tag or empty string if icon not found.
	 */
	public static function get_icon( string $name, int $size = 20, string $css_class = '' ): string {
		$cache_key = $name . ':' . $size . ':' . $css_class;
		if ( isset( self::$icon_cache[ $cache_key ] ) ) {
			return self::$icon_cache[ $cache_key ];
		}

		$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		$icon_class = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
		if ( '' !== $css_class ) {
			$parts     = preg_split( '/\s+/', trim( $css_class ), -1, PREG_SPLIT_NO_EMPTY );
			$sanitized = array();
			foreach ( $parts as $part ) {
				$cleaned = sanitize_html_class( $part );
				if ( '' !== $cleaned ) {
					$sanitized[] = $cleaned;
				}
			}
			if ( ! empty( $sanitized ) ) {
				$icon_class .= ' ' . implode( ' ', $sanitized );
			}
		}

		$icon_url = esc_url( SSCRIBE_PLUGIN_URL . self::$icons_dir . $name . '.svg' );

		$html                           = sprintf(
			'<img src="%s" width="%d" height="%d" class="%s" aria-hidden="true" focusable="false">',
			$icon_url,
			absint( $size ),
			absint( $size ),
			esc_attr( $icon_class )
		);
		self::$icon_cache[ $cache_key ] = $html;
		return $html;
	}

	/**
	 * Get time estimate for a single format.
	 *
	 * @param string $format     Export format.
	 * @param int    $page_count Number of pages.
	 * @return array{text: string, seconds: int}
	 */
	public static function get_format_time_estimate( string $format, int $page_count ): array {
		$times = array(
			'docx'     => 1.2,
			'pdf'      => 8,
			'html'     => 1,
			'markdown' => 0.5,
		);

		$seconds_per_page = isset( $times[ $format ] ) ? $times[ $format ] : 2;
		$total_seconds    = (int) ceil( $seconds_per_page * $page_count );

		if ( $total_seconds < 60 ) {
			return array(
				'text'    => sprintf(
					/* translators: %d: Estimated seconds. */
					__( '~%d seconds', 'sscribe-export-site-pages' ),
					$total_seconds
				),
				'seconds' => $total_seconds,
			);
		}

		$minutes = (int) ceil( $total_seconds / 60 );
		return array(
			'text'    => sprintf(
				/* translators: %d: Estimated minutes. */
				_n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ),
				$minutes
			),
			'seconds' => $total_seconds,
		);
	}

	/**
	 * Get time estimate for all formats.
	 *
	 * @param int $page_count Number of pages.
	 * @return array{text: string, seconds: int}
	 */
	public static function get_all_formats_time_estimate( int $page_count ): array {
		$total_seconds = (int) ceil( ( 1.2 + 8 + 1 + 0.5 ) * $page_count );
		$minutes       = (int) ceil( $total_seconds / 60 );

		if ( $minutes < 60 ) {
			return array(
				'text'    => sprintf(
					/* translators: %d: Estimated minutes. */
					_n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ),
					$minutes
				),
				'seconds' => $total_seconds,
			);
		}

		$hours = (int) floor( $minutes / 60 );
		$mins  = $minutes % 60;
		$text  = sprintf(
			/* translators: 1: Hours, 2: Minutes. */
			__( '~%1$dh %2$dm', 'sscribe-export-site-pages' ),
			$hours,
			$mins
		);
		return array(
			'text'    => $text,
			'seconds' => $total_seconds,
		);
	}

	/**
	 * Format a byte count as human-readable size.
	 *
	 * @param int $bytes Byte count.
	 * @return string Formatted size.
	 */
	public static function format_filesize( int $bytes ): string {
		return size_format( $bytes, 1 );
	}

	/**
	 * Validate and sanitize a URL for use in documents.
	 *
	 * Uses esc_url_raw to prevent HTML entity encoding.
	 *
	 * @param string $url URL to validate.
	 * @return string Valid URL or empty string.
	 */
	public static function validate_document_url( string $url ): string {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return '';
		}

		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Get the current client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( empty( $ip ) ) {
			return '0.0.0.0';
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Strip page builder inline styles, classes, and data attributes from HTML.
	 *
	 * Shared between SScribe_Page_Collector and SScribe_Content_Parser.
	 *
	 * @param string $html Raw HTML content.
	 * @return string Cleaned HTML.
	 */
	public static function strip_page_builder_attributes( string $html ): string {
		$html = preg_replace( '/\s*style="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*style='[^']*'/i", '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/\s*class="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*class='[^']*'/i", '', $html );
		$html = preg_replace( '/\s*data-elementor(-[a-z]+)?="[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*data-(widget|column|section)-[a-z0-9_-]{0,30}="[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*id="elementor-[^"]*"/i', '', $html );

		return $html;
	}
}
