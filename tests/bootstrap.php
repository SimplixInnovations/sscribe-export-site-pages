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

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return array(
			'basedir' => sys_get_temp_dir() . '/sscribe-test-uploads',
			'baseurl' => 'http://example.org/wp-content/uploads',
			'path'    => sys_get_temp_dir() . '/sscribe-test-uploads',
			'url'     => 'http://example.org/wp-content/uploads',
			'subdir'  => '',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $sscribe_string ) {
		return rtrim( $sscribe_string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $sscribe_dir ) {
		if ( ! is_dir( $sscribe_dir ) ) {
			mkdir( $sscribe_dir, 0755, true );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $sscribe_length = 12, $sscribe_special_chars = true, $sscribe_extra_special_chars = false ) {
		$sscribe_chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $sscribe_special_chars ) {
			$sscribe_chars .= '!@#$%^&*()';
		}
		if ( $sscribe_extra_special_chars ) {
			$sscribe_chars .= '-_ []{}<>~`+=,.;:/?|';
		}
		$sscribe_password = '';
		for ( $sscribe_i = 0; $sscribe_i < $sscribe_length; $sscribe_i++ ) {
			$sscribe_password .= $sscribe_chars[ random_int( 0, strlen( $sscribe_chars ) - 1 ) ];
		}
		return $sscribe_password;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $sscribe_data, $sscribe_options = 0, $sscribe_depth = 512 ) {
		return json_encode( $sscribe_data, $sscribe_options, $sscribe_depth );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $sscribe_key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $sscribe_key ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $sscribe_text, $sscribe_domain = 'default' ) {
		return $sscribe_text;
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
