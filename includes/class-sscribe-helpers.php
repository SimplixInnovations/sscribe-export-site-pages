<?php
/**
 * Helper functions for SScribe.
 *
 * @package SScribe
 */

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
	 * Get an SVG icon as HTML.
	 *
	 * @param string $name  Icon name (without .svg extension).
	 * @param int    $size  Icon size in pixels.
	 * @param array  $attrs Optional attributes.
	 * @return string SVG HTML or empty string if not found.
	 */
	public static function get_icon( string $name, int $size = 20, array $attrs = array() ): string {
		$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		$svg_content = file_get_contents( $file_path );
		if ( false === $svg_content ) {
			return '';
		}

		$class = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
		if ( isset( $attrs['class'] ) ) {
			$class .= ' ' . sanitize_html_class( $attrs['class'] );
		}

		$width  = isset( $attrs['width'] ) ? (int) $attrs['width'] : $size;
		$height = isset( $attrs['height'] ) ? (int) $attrs['height'] : $size;

		$svg_content = preg_replace(
			'/<svg([^>]*)>/i',
			'<svg$1 width="' . esc_attr( $width ) . '" height="' . esc_attr( $height ) . '" class="' . esc_attr( $class ) . '">',
			$svg_content,
			1
		);

		return $svg_content;
	}

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
	 * Get time estimate for a format export.
	 *
	 * @param string $format     Export format.
	 * @param int    $page_count Number of pages.
	 * @return array Time estimate with 'text' and 'seconds' keys.
	 */
	public static function get_format_time_estimate( string $format, int $page_count ): array {
		$times = array(
			'docx'     => 1.2,
			'pdf'      => 8,
			'html'     => 1,
			'markdown' => 0.5,
		);

		$seconds_per_page = isset( $times[ $format ] ) ? $times[ $format ] : 2;
		$total_seconds    = $seconds_per_page * $page_count;

		if ( $total_seconds < 60 ) {
			return array(
				'text'    => sprintf(
					/* translators: %d: Estimated seconds. */
					__( '~%d seconds', 'sscribe-export-site-pages' ),
					ceil( $total_seconds )
				),
				'seconds' => ceil( $total_seconds ),
			);
		}

		$minutes = ceil( $total_seconds / 60 );
		return array(
			'text'    => sprintf(
				/* translators: %d: Estimated minutes. */
				_n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ),
				$minutes
			),
			'seconds' => ceil( $total_seconds ),
		);
	}

	/**
	 * Get time estimate for all formats.
	 *
	 * @param int $page_count Number of pages.
	 * @return array Time estimate with 'text' and 'seconds' keys.
	 */
	public static function get_all_formats_time_estimate( int $page_count ): array {
		$total_seconds = ( 1.2 + 8 + 1 + 0.5 ) * $page_count;
		$minutes       = ceil( $total_seconds / 60 );

		if ( $minutes < 60 ) {
			return array(
				'text'    => sprintf(
					/* translators: %d: Estimated minutes. */
					_n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ),
					$minutes
				),
				'seconds' => ceil( $total_seconds ),
			);
		}

		$hours = floor( $minutes / 60 );
		$mins  = $minutes % 60;
		$text  = sprintf(
			/* translators: 1: Hours, 2: Minutes. */
			__( '~%1$dh %2$dm', 'sscribe-export-site-pages' ),
			$hours,
			$mins
		);
		return array(
			'text'    => $text,
			'seconds' => ceil( $total_seconds ),
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
}
