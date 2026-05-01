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
	 * Get an SVG icon as inline HTML.
	 *
	 * Reads the SVG file, strips the outer <svg> attributes and applies
	 * width/height/class from the caller. Uses fill="currentColor" so
	 * the icon inherits the parent CSS color property.
	 *
	 * @param string $name  Icon name (without .svg extension).
	 * @param int    $size   Icon size in pixels.
	 * @param string $css_class Additional CSS class.
	 * @return string Inline SVG HTML or empty string if not found.
	 */
	public static function get_icon( string $name, int $size = 20, string $css_class = '' ): string {
		if ( ! isset( self::$icon_cache[ $name ] ) ) {
			$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

			if ( ! file_exists( $file_path ) ) {
				self::$icon_cache[ $name ] = '';
			} else {
				$svg_content               = file_get_contents( $file_path );
				self::$icon_cache[ $name ] = ( false === $svg_content ) ? '' : $svg_content;
			}
		}

		$svg_content = self::$icon_cache[ $name ];
		if ( '' === $svg_content ) {
			return '';
		}

		$icon_class = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
		if ( '' !== $css_class ) {
			$icon_class .= ' ' . sanitize_html_class( $css_class );
		}

		$svg_content = preg_replace(
			'/<svg[^>]*>/i',
			'<svg width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '" class="' . esc_attr( $icon_class ) . '" aria-hidden="true" focusable="false">',
			$svg_content,
			1
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG content is sanitized via preg_replace with esc_attr() on all dynamic attributes. Icon files are local, trusted assets.
		return $svg_content;
	}

	/**
	 * Get time estimate for a format export.
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
