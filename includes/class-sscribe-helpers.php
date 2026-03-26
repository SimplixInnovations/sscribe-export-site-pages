<?php
/**
 * Helper functions for SScribe.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Helpers {

	private static $icons_dir = 'assets/icons/';

	public static function get_icon( string $name, int $size = 20, array $attrs = array() ): string {
		$file_path = SSCRIBE_PLUGIN_DIR . self::$icons_dir . $name . '.svg';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		$svg_content = file_get_contents( $file_path );
		if ( $svg_content === false ) {
			return '';
		}

		$class = 'sscribe-icon scribe-icon-' . sanitize_html_class( $name );
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

	public static function icon_url( string $name ): string {
		return SSCRIBE_PLUGIN_URL . self::$icons_dir . $name . '.svg';
	}

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
				'text'   => sprintf( __( '~%d seconds', 'sscribe-export-site-pages' ), ceil( $total_seconds ) ),
				'seconds' => ceil( $total_seconds ),
			);
		}

		$minutes = ceil( $total_seconds / 60 );
		return array(
			'text'    => sprintf( _n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ), $minutes ),
			'seconds' => ceil( $total_seconds ),
		);
	}

	public static function get_all_formats_time_estimate( int $page_count ): array {
		$total_seconds = ( 1.2 + 8 + 1 + 0.5 ) * $page_count;
		$minutes       = ceil( $total_seconds / 60 );

		if ( $minutes < 60 ) {
			return array(
				'text'    => sprintf( _n( '~%d minute', '~%d minutes', $minutes, 'sscribe-export-site-pages' ), $minutes ),
				'seconds' => ceil( $total_seconds ),
			);
		}

		$hours   = floor( $minutes / 60 );
		$mins    = $minutes % 60;
		$text    = sprintf( __( '~%dh %dm', 'sscribe-export-site-pages' ), $hours, $mins );
		return array(
			'text'    => $text,
			'seconds' => ceil( $total_seconds ),
		);
	}

	public static function format_filesize( int $bytes ): string {
		return size_format( $bytes, 1 );
	}

	public static function get_export_log_path( string $session_id ): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/' . $session_id . '.json';
	}

	public static function ensure_log_directory(): string {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			file_put_contents( $log_dir . '.htaccess', 'Deny from all' );
			file_put_contents( $log_dir . 'index.html', '' );
		}

		return $log_dir;
	}
}
