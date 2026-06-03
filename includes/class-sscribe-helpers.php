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
			if ( SSCRIBE_DEBUG ) {
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
			'<img src="%s" width="%d" height="%d" class="%s" aria-hidden="true">',
			$icon_url,
			absint( $size ),
			absint( $size ),
			esc_attr( $icon_class )
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
