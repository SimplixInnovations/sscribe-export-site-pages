<?php
/**
 * SScribe Helpers
 *
 * @package SScribe_Export_Site_Pages
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
	private static string $icons_dir = 'assets/icons/';

	/**
	 * Cache of rendered icon HTML.
	 *
	 * @var array
	 */
	private static array $icon_cache = array();

	/**
	 * Get the URL for an icon file.
	 *
	 * @param string $name Icon name without extension.
	 * @return string
	 */
	public static function icon_url( string $name ): string {
		return SSCRIBE_PLUGIN_URL . self::$icons_dir . $name . '.svg';
	}

	/**
	 * Get an icon as inline HTML.
	 *
	 * @param string $name      Icon name.
	 * @param int    $size      Icon size in pixels.
	 * @param string $css_class Additional CSS classes.
	 * @return string
	 */
	public static function get_icon( string $name, int $size = 20, string $css_class = '' ): string {
		$cache_key = $name . ':' . $size . ':' . $css_class;
		if ( isset( self::$icon_cache[ $cache_key ] ) ) {
			return self::$icon_cache[ $cache_key ];
		}

		$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

		if ( ! file_exists( $file_path ) ) {
			if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
				$logger = SScribe_Logger::instance( true );
				$logger->warning(
					'Icon file not found',
					array(
						'icon_name' => $name,
						'file_path' => $file_path,
					)
				);
			}
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
	 * Get time estimate for a single format export.
	 *
	 * @param string $format     Export format.
	 * @param int    $page_count Number of pages.
	 * @return array
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
	 * Get time estimate for all formats combined.
	 *
	 * @param int $page_count Number of pages.
	 * @return array
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
	 * Format bytes to human-readable size.
	 *
	 * @param int $bytes File size in bytes.
	 * @return string
	 */
	public static function format_filesize( int $bytes ): string {
		return size_format( $bytes, 1 );
	}

	/**
	 * Validate and sanitize a document URL.
	 *
	 * @param string $url URL to validate.
	 * @return string Sanitized URL or empty string.
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
	 * Get the client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		$trusted_headers = apply_filters( 'sscribe_trusted_ip_headers', array() );

		foreach ( $trusted_headers as $header ) {
			$header_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			if ( ! empty( $_SERVER[ $header_key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header_key ] ) ) );
				$ip = trim( $ip[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		$remote_addr = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_DEFAULT );
		if ( false === $remote_addr || null === $remote_addr ) {
			return '0.0.0.0';
		}
		$ip = filter_var( sanitize_text_field( wp_unslash( (string) $remote_addr ) ), FILTER_VALIDATE_IP );

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
		$patterns = array(
			'/\s*style="[^"]*"/i',
			"/\s*style='[^']*'/i",
			'/<style[^>]*>.*?<\/style>/is',
			'/\s*class="[^"]*"/i',
			"/\s*class='[^']*'/i",
			'/\s*data-elementor(-[a-z]+)?="[^"]*"/i',
			'/\s*data-(widget|column|section)-[a-z0-9_-]{0,30}="[^"]*"/i',
			'/\s*id="elementor-[^"]*"/i',
		);

		return preg_replace( $patterns, '', $html ) ?? $html;
	}
}
