<?php
/**
 * PHPUnit Test Bootstrap
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fake-wp/' );
}

define( 'SSCRIBE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'SSCRIBE_PLUGIN_URL', 'http://example.org/wp-content/plugins/sscribe-export-site-pages/' );
define( 'SSCRIBE_PLUGIN_BASENAME', 'sscribe-export-site-pages/sscribe-export-site-pages.php' );

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $sscribe_data ) {
		return $sscribe_data;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $sscribe_post_id, $sscribe_key = '', $sscribe_single = false ) {
		return array();
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $sscribe_field, $sscribe_post_id ) {
		return '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $sscribe_post_id ) {
		return 'http://example.org/?p=' . $sscribe_post_id;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $sscribe_post_id = 0 ) {
		return 'Test Title';
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $sscribe_post_id ) {
		return 'publish';
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $sscribe_post_id ) {
		return 'page';
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl() {
		return false;
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $sscribe_content ) {
		return $sscribe_content;
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( $sscribe_file, $sscribe_headers ) {
		$content = file_get_contents( $sscribe_file );
		$data    = array();
		foreach ( $sscribe_headers as $key => $pattern ) {
			if ( preg_match( '/^[ \t]*\* ' . preg_quote( $pattern, '/' ) . ':\s*(.+?)\s*$/m', $content, $matches ) ) {
				$data[ $key ] = trim( $matches[1] );
			}
		}
		return $data;
	}
}

$sscribe_plugin_data = get_file_data(
	SSCRIBE_PLUGIN_DIR . 'sscribe-export-site-pages.php',
	array( 'version' => 'Version' )
);
define( 'SSCRIBE_VERSION', $sscribe_plugin_data['version'] ?? '1.0.0' );

require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
