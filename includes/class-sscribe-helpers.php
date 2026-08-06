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
	 * Get an icon as inline SVG so currentColor resolves correctly.
	 *
	 * Use this when an icon must inherit its color from CSS (e.g. white
	 * check on a colored selected-state circle). For most other uses,
	 * get_icon() with the <img> tag is preferred.
	 *
	 * @param string $name      Icon name.
	 * @param int    $size      Icon size in pixels.
	 * @param string $css_class Additional CSS classes.
	 * @return string
	 */
	public static function get_icon_inline( string $name, int $size = 20, string $css_class = '' ): string {
		$cache_key = 'inline:' . $name . ':' . $size . ':' . $css_class;
		if ( isset( self::$icon_cache[ $cache_key ] ) ) {
			return self::$icon_cache[ $cache_key ];
		}

		$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		$raw = file_get_contents( $file_path );
		if ( false === $raw || '' === $raw ) {
			return '';
		}

		$raw = (string) $raw;

		$classes = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
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
				$classes .= ' ' . implode( ' ', $sanitized );
			}
		}

		$abs_size = absint( $size );

		if ( strpos( $raw, '<svg' ) !== false ) {
			$raw = preg_replace( '/\s(width|height)="[^"]*"/i', '', $raw, 2 );
		}

		$svg  = preg_replace(
			'/<svg\b/i',
			'<svg width="' . $abs_size . '" height="' . $abs_size . '" class="' . esc_attr( $classes ) . '" aria-hidden="true"',
			$raw,
			1
		);
		if ( null === $svg ) {
			$svg = $raw;
		}

		self::$icon_cache[ $cache_key ] = $svg;
		return $svg;
	}

	/**
	 * Get an inline SVG icon, escaped through an SVG-only kses allowlist.
	 *
	 * WordPress's `wp_kses_post()` strips `<svg>` and `<path>` elements on
	 * many installs (the default `post` allowed-HTML context does not include
	 * them in all WP versions, and several hardening plugins actively remove
	 * them). Passing the output of `get_icon_inline()` through `wp_kses_post()`
	 * therefore yields an empty string, which is why the radio-card check
	 * icons and all other inline SVG icons were missing from the rendered
	 * admin UI. This wrapper applies a tight allowlist that whitelists only
	 * the SVG elements and attributes our shipped icons actually use, so
	 * the inline SVG survives sanitization without weakening any other
	 * escape path in the plugin.
	 *
	 * Callers should `echo` the return value of this method directly;
	 * wrapping it again in `wp_kses_post()` re-introduces the bug.
	 *
	 * @param string $name      Icon name (must exist in assets/icons/).
	 * @param int    $size      Width/height attribute in pixels.
	 * @param string $css_class Additional CSS classes.
	 * @return string Sanitized inline SVG markup.
	 */
	public static function get_icon_inline_safe( string $name, int $size = 20, string $css_class = '' ): string {
		$svg = self::get_icon_inline( $name, $size, $css_class );
		if ( '' === $svg ) {
			return '';
		}
		$allowed = array(
			'svg'  => array(
				'class'             => true,
				'aria-hidden'       => true,
				'aria-label'        => true,
				'role'              => true,
				'width'             => true,
				'height'            => true,
				'viewbox'           => true,
				'xmlns'             => true,
				'fill'              => true,
				'stroke'            => true,
				'stroke-width'      => true,
				'stroke-linecap'    => true,
				'stroke-linejoin'   => true,
			),
			'g'    => array(
				'fill'    => true,
				'stroke'  => true,
				'transform' => true,
			),
			'path' => array(
				'd'                 => true,
				'fill'              => true,
				'stroke'            => true,
				'stroke-width'      => true,
				'stroke-linecap'    => true,
				'stroke-linejoin'   => true,
				'transform'         => true,
			),
			'circle' => array(
				'cx'    => true,
				'cy'    => true,
				'r'     => true,
				'fill'  => true,
				'stroke' => true,
			),
			'rect'   => array(
				'x'         => true,
				'y'         => true,
				'width'     => true,
				'height'    => true,
				'rx'        => true,
				'ry'        => true,
				'fill'      => true,
				'stroke'    => true,
				'transform' => true,
			),
			'line'   => array(
				'x1'            => true,
				'y1'            => true,
				'x2'            => true,
				'y2'            => true,
				'stroke'        => true,
				'stroke-width'  => true,
				'stroke-linecap' => true,
			),
			'polyline' => array(
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'polygon' => array(
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-linejoin' => true,
			),
		);
		return wp_kses( $svg, $allowed );
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

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is sanitized to text and then passed to filter_var(FILTER_VALIDATE_IP) below; non-IP input is rejected and `0.0.0.0` is returned instead.
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
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
