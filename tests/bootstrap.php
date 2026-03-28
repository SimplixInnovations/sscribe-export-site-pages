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

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$sscribe_test_transients = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $sscribe_transient ) {
		global $sscribe_test_transients;
		return isset( $sscribe_test_transients[ $sscribe_transient ] ) ? $sscribe_test_transients[ $sscribe_transient ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $sscribe_transient, $sscribe_value, $sscribe_expiration = 0 ) {
		global $sscribe_test_transients;
		$sscribe_test_transients[ $sscribe_transient ] = $sscribe_value;
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		return (object) array( 'ID' => 1, 'user_login' => 'testuser' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return strip_tags( $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return stripslashes( $value );
	}
}

$sscribe_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $sscribe_option, $sscribe_default = false ) {
		global $sscribe_test_options;
		return isset( $sscribe_test_options[ $sscribe_option ] ) ? $sscribe_test_options[ $sscribe_option ] : $sscribe_default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $sscribe_option, $sscribe_value, $sscribe_autoload = null ) {
		global $sscribe_test_options;
		$sscribe_test_options[ $sscribe_option ] = $sscribe_value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $sscribe_option ) {
		global $sscribe_test_options;
		if ( isset( $sscribe_test_options[ $sscribe_option ] ) ) {
			unset( $sscribe_test_options[ $sscribe_option ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );
		$bytes /= pow( 1024, $pow );
		return round( $bytes, $decimals ) . ' ' . $units[ $pow ];
	}
}


if ( ! function_exists( 'add_action' ) ) {
	function add_action( $sscribe_hook, $sscribe_callback, $sscribe_priority = 10, $sscribe_args = 1 ) {
		// No-op stub for unit tests.
		return true;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $sscribe_file ) {
		if ( file_exists( $sscribe_file ) ) {
			return unlink( $sscribe_file );
		}
		return false;
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
