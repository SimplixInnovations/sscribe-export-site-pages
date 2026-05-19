<?php
/**
 * SScribe Tests Bootstrap
 *
 * @package SScribe_Export_Site_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fake-wp/' );
}


if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
	@session_start();
}
$_SESSION = $_SESSION ?? array();


if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		return true;
	}
}


if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
		return true;
	}
}


if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false; 
	}
}


if ( ! function_exists( 'wp_kses_allowed_html' ) ) {


	function wp_kses_allowed_html( string $context = 'post' ): array {
		
		
		$html = array(
			'address'    => array(),
			'a'          => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ),
			'abbr'       => array(),
			'area'       => array( 'alt' => true, 'coords' => true, 'href' => true, 'shape' => true ),
			'article'    => array(),
			'aside'      => array(),
			'b'          => array(),
			'blockquote' => array( 'cite' => true ),
			'br'         => array(),
			'caption'    => array(),
			'cite'       => array(),
			'code'       => array(),
			'col'        => array( 'span' => true ),
			'colgroup'   => array( 'span' => true ),
			'dd'         => array(),
			'del'        => array( 'datetime' => true ),
			'dfn'        => array(),
			'div'        => array( 'class' => true, 'id' => true, 'style' => true ),
			'dl'         => array(),
			'dt'         => array(),
			'em'         => array(),
			'fieldset'   => array(),
			'figcaption' => array(),
			'figure'     => array(),
			'footer'     => array(),
			'h1'         => array( 'class' => true, 'id' => true ),
			'h2'         => array( 'class' => true, 'id' => true ),
			'h3'         => array( 'class' => true, 'id' => true ),
			'h4'         => array( 'class' => true, 'id' => true ),
			'h5'         => array( 'class' => true, 'id' => true ),
			'h6'         => array( 'class' => true, 'id' => true ),
			'header'     => array(),
			'hgroup'     => array(),
			'hr'         => array(),
			'i'          => array(),
			'img'        => array( 'alt' => true, 'class' => true, 'height' => true, 'src' => true, 'width' => true ),
			'ins'        => array( 'datetime' => true ),
			'li'         => array( 'class' => true ),
			'main'       => array(),
			'mark'       => array(),
			'nav'        => array(),
			'ol'         => array( 'class' => true, 'start' => true, 'type' => true ),
			'p'          => array( 'class' => true, 'style' => true ),
			'pre'        => array(),
			's'          => array(),
			'section'    => array(),
			'small'      => array(),
			'span'       => array( 'class' => true, 'style' => true ),
			'strong'     => array(),
			'sub'        => array(),
			'sup'        => array(),
			'table'      => array( 'class' => true ),
			'tbody'      => array(),
			'td'         => array( 'colspan' => true, 'rowspan' => true, 'class' => true ),
			'tfoot'      => array(),
			'th'         => array( 'colspan' => true, 'rowspan' => true, 'scope' => true, 'class' => true ),
			'thead'      => array(),
			'time'       => array( 'datetime' => true ),
			'tr'         => array(),
			'u'          => array(),
			'ul'         => array( 'class' => true ),
			'video'      => array( 'controls' => true, 'height' => true, 'src' => true, 'width' => true ),
			'audio'      => array( 'controls' => true, 'src' => true ),
			'iframe'     => array( 'allow' => true, 'height' => true, 'src' => true, 'width' => true ),
		);



		return apply_filters( 'wp_kses_allowed_html', $html, $context );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {


	function wp_kses( string $content, array $allowed_html ): string {
		
		
		return strip_tags( $content, array_keys( $allowed_html ) );
	}
}

if ( ! function_exists( 'wp_filter_content_tags' ) ) {
	function wp_filter_content_tags( string $content ): string {
		return $content;
	}
}

if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	define( 'SSCRIBE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SSCRIBE_PLUGIN_URL' ) ) {
	define( 'SSCRIBE_PLUGIN_URL', 'http://example.org/wp-content/plugins/sscribe-export-site-pages/' );
}
if ( ! defined( 'SSCRIBE_PLUGIN_BASENAME' ) ) {
	define( 'SSCRIBE_PLUGIN_BASENAME', 'sscribe-export-site-pages/sscribe-export-site-pages.php' );
}


if ( ! defined( 'SSCRIBE_TESTING' ) ) {
	define( 'SSCRIBE_TESTING', true );
}


if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'test-auth-salt-for-unit-tests-only' );
}

if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-salt-for-unit-tests-only' );
}

if ( ! defined( 'SSCRIBE_DEBUG' ) ) {
	define( 'SSCRIBE_DEBUG', false );
}




if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/fake-wp/wp-content' );
}

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

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		$site_name = 'Test Site';
		switch ( $show ) {
			case 'name':
				return $site_name;
			case 'description':
				return 'Just another WordPress site';
			case 'url':
			case 'home':
				return 'https://example.org';
			case 'wpurl':
			case 'siteurl':
				return 'https://example.org/wp';
			case 'version':
				return '6.4.0';
			default:
				return $site_name;
		}
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
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

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $sscribe_filename ) {
		$sscribe_filename = preg_replace( '/[^a-zA-Z0-9._\-]/', '_', $sscribe_filename );
		return preg_replace( '/_+/', '_', trim( $sscribe_filename, '_' ) );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) {
		global $sscribe_test_actions;
		return count( $sscribe_test_actions[ $hook_name ] ?? array() );
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class ) {
		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', $class );
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {
		global $sscribe_test_status_header;
		$sscribe_test_status_header = $code;
		return true;
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null, $options = 0 ) {
		echo wp_json_encode( array( 'success' => false, 'data' => $data ) );
		throw new \RuntimeException( 'AJAX error response sent' );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null, $options = 0 ) {
		echo wp_json_encode( array( 'success' => true, 'data' => $data ) );
		throw new \RuntimeException( 'AJAX success response sent' );
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		if ( is_dir( $path ) ) {
			return is_writable( $path );
		}
		return is_dir( dirname( $path ) ) && is_writable( dirname( $path ) );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		
		$nonce_field = false === $query_arg ? '_ajax_nonce' : $query_arg;
		$nonce_value = $_POST[ $nonce_field ] ?? $_REQUEST[ $nonce_field ] ?? '';

		if ( empty( $nonce_value ) ) {
			if ( $stop ) {
				wp_send_json_error( 'Nonce check failed', 403 );
			}
			return false;
		}

		return 1; 
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
	function wp_raise_memory_limit( $context = 'admin' ) {
		return 268435456; 
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		echo (string) $message;
		throw new \RuntimeException( 'wp_die called' );
	}
}



if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		public string $user_login = '';
		public string $display_name = '';

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		global $sscribe_test_current_user;
		return $sscribe_test_current_user ?? null;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $sscribe_text, $sscribe_domain = 'default' ) {
		return $sscribe_text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $sscribe_single, $sscribe_plural, $sscribe_number, $sscribe_domain = 'default' ) {
		return 1 === $sscribe_number ? $sscribe_single : $sscribe_plural;
	}
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ) {
		$value = strtolower( trim( $value ) );
		$bytes = (int) $value;

		if ( str_contains( $value, 'g' ) ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( str_contains( $value, 'm' ) ) {
			$bytes *= 1024 * 1024;
		} elseif ( str_contains( $value, 'k' ) ) {
			$bytes *= 1024;
		}

		return $bytes;
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

if ( ! defined( 'SSCRIBE_VERSION' ) ) {
	$sscribe_plugin_data = get_file_data(
		SSCRIBE_PLUGIN_DIR . 'sscribe-export-site-pages.php',
		array( 'version' => 'Version' )
	);
	define( 'SSCRIBE_VERSION', $sscribe_plugin_data['version'] ?? '1.0.0' );
}

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

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $sscribe_transient ) {
		global $sscribe_test_transients;
		unset( $sscribe_test_transients[ $sscribe_transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		
		return true;
	}
}


if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public int $found_posts = 0;

		public function __construct( $query = array() ) {
			
		}
	}
}


if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_title = '';
		public string $post_content = '';
		public string $post_status = 'publish';
		public string $post_type = 'page';
		public string $post_name = '';
		public int $post_parent = 0;

		public function __construct( $post = null ) {
		}
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

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		global $wpdb;
		return $wpdb->esc_sql( $data );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return strip_tags( $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return stripslashes( $value );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		$trimmed = trim( $data );
		if ( '' === $trimmed ) {
			return $data;
		}

		$unserialized = @unserialize( $trimmed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test bootstrap compatibility shim.

		if ( false !== $unserialized || 'b:0;' === $trimmed ) {
			return $unserialized;
		}

		return $data;
	}
}

$sscribe_test_options = array();
$sscribe_test_db_tables = array(
	'wp_sscribe_audit_log'    => array(),
	'wp_sscribe_export_stats' => array(),
);
$sscribe_test_http_response = array();
$sscribe_test_filters       = array();
$sscribe_test_actions       = array();
$sscribe_test_menu_pages    = array();
$sscribe_test_styles        = array();
$sscribe_test_scripts       = array();
$sscribe_test_localized     = array();
$sscribe_test_current_user_can = true;
$sscribe_test_is_admin         = true;
$sscribe_test_doing_ajax       = false;
$sscribe_test_ajax_nonce_valid = true;

	if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $posts = 'wp_posts';

		public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
		}

		public function prepare( $query, ...$args ) {
			$index = 0;
			return preg_replace_callback(
				'/%(?:d|s|f)/',
				static function ( array $matches ) use ( $args, &$index ) {
					$value = $args[ $index++ ] ?? null;
					if ( '%d' === $matches[0] ) {
						return (string) (int) $value;
					}
					if ( '%f' === $matches[0] ) {
						return (string) (float) $value;
					}
					
					
					
					
					if ( is_string( $value ) && preg_match( '/^[\w]+$/', $value ) ) {
						
						return $value;
					}
					return "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				$query
			);
		}

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function esc_sql( $data ) {
			return (string) $data;
		}

		public function get_charset_collate() {
			return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function get_var( $query ) {
			global $sscribe_test_db_tables;

			
			
			if ( preg_match( "/SHOW TABLES LIKE\s+['`]([^'`]+)['`]/i", $query, $matches ) ) {
				return array_key_exists( $matches[1], $sscribe_test_db_tables ) ? $matches[1] : null;
			}

			return null;
		}

		public function get_results( $query ) {
			global $sscribe_test_options, $sscribe_test_db_tables;

			if ( false !== strpos( $query, $this->options ) && preg_match( "/LIKE '([^']+)'/i", $query, $matches ) ) {
				$like_pattern = str_replace( '\\_', '_', $matches[1] );
				$pattern      = str_replace( '%', '.*', preg_quote( $like_pattern, '/' ) );
				$results = array();

				foreach ( (array) $sscribe_test_options as $option_name => $option_value ) {
					if ( preg_match( '/^' . $pattern . '$/', $option_name ) ) {
						$results[] = (object) array(
							'option_name'  => $option_name,
							'option_value' => $option_value,
						);
					}
				}

				return $results;
			}

			if ( preg_match( '/FROM\s+(\w+_sscribe_export_stats)\s+WHERE\s+user_id\s*=\s*(\d+)\s+ORDER BY\s+export_date\s+DESC\s+LIMIT\s+(\d+)/i', $query, $matches ) ) {
				$table   = $matches[1];
				$user_id = (int) $matches[2];
				$limit   = (int) $matches[3];
				$rows    = array_values(
					array_filter(
						$sscribe_test_db_tables[ $table ] ?? array(),
						static fn( array $row ): bool => (int) ( $row['user_id'] ?? 0 ) === $user_id
					)
				);

				usort(
					$rows,
					static fn( array $left, array $right ): int => strcmp( (string) ( $right['export_date'] ?? '' ), (string) ( $left['export_date'] ?? '' ) )
				);

				$rows = array_slice( $rows, 0, $limit );

				return array_map( static fn( array $row ): object => (object) $row, $rows );
			}

			return array();
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			global $sscribe_test_db_tables;

			if ( ! isset( $sscribe_test_db_tables[ $table ] ) || ! is_array( $sscribe_test_db_tables[ $table ] ) ) {
				return false;
			}

			$count = 0;
			foreach ( $sscribe_test_db_tables[ $table ] as &$row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					if ( ! array_key_exists( $key, $row ) || (string) $row[ $key ] !== (string) $value ) {
						$matches = false;
						break;
					}
				}

				if ( $matches ) {
					$row = array_merge( $row, $data );
					++$count;
				}
			}
			unset( $row );

			return $count;
		}
	}
}

$GLOBALS['wpdb'] = new wpdb();

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

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $sscribe_option, $sscribe_value = '', $sscribe_deprecated = '', $sscribe_autoload = 'yes' ) {
		global $sscribe_test_options;
		if ( ! isset( $sscribe_test_options[ $sscribe_option ] ) ) {
			$sscribe_test_options[ $sscribe_option ] = $sscribe_value;
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'setup_postdata' ) ) {
	function setup_postdata( $post ) {
		return true;
	}
}

if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata() {
		global $post;
		$post = null;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null ) {
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		if ( is_numeric( $post ) ) {
			$p = new WP_Post();
			$p->ID = (int) $post;
			return $p;
		}
		return null;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = (string) $url;
		
		$protocol = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' === $protocol ) {
			return '';
		}
		$bad_protocols = array( 'javascript', 'data', 'vbscript', 'file' );
		if ( in_array( $protocol, $bad_protocols, true ) ) {
			return '';
		}
		$filtered = filter_var( $url, FILTER_SANITIZE_URL );
		return $filtered ?: '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		unset( $scheme );
		return 'https://example.org' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) {
		unset( $scheme );
		return 'https://example.org' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			public string $code = '',
			public string $message = '',
			public mixed $data = null
		) {
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		$validated = filter_var( $url, FILTER_VALIDATE_URL );
		if ( false === $validated ) {
			return false;
		}

		$parts = parse_url( $validated );
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = strtolower( $parts['host'] ?? '' );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( '' === $host || in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return false;
		}

		return $validated;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		global $sscribe_test_http_response;

		if ( empty( $sscribe_test_http_response ) ) {
			return new WP_Error( 'no_response', 'No mock HTTP response configured.' );
		}

		$response = $sscribe_test_http_response;
		$response['requested_url'] = $url;
		$response['request_args']  = $args;
		$sscribe_test_http_response = $response;

		return $response;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		$headers = $response['headers'] ?? array();
		$header  = strtolower( (string) $header );

		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $header ) {
				return $value;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$result = array();
		foreach ( $list as $item ) {
			if ( is_object( $item ) ) {
				$result[] = $item->$field ?? null;
			} elseif ( is_array( $item ) ) {
				$result[] = $item[ $field ] ?? null;
			}
		}
		return $result;
	}
}

if ( ! function_exists( 'class_exists' ) || ! class_exists( 'WP_Query' ) ) {
	
}


if ( ! function_exists( 'add_action' ) ) {
	function add_action( $sscribe_hook, $sscribe_callback, $sscribe_priority = 10, $sscribe_args = 1 ) {
		global $sscribe_test_actions;
		$sscribe_test_actions[] = array(
			'hook'          => $sscribe_hook,
			'callback'      => $sscribe_callback,
			'priority'      => $sscribe_priority,
			'accepted_args' => $sscribe_args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $sscribe_hook, $sscribe_callback, $sscribe_priority = 10, $sscribe_args = 1 ) {
		global $sscribe_test_filters;
		$sscribe_test_filters[] = array(
			'hook'          => $sscribe_hook,
			'callback'      => $sscribe_callback,
			'priority'      => $sscribe_priority,
			'accepted_args' => $sscribe_args,
		);
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		global $sscribe_test_filters;

		if ( ! is_array( $sscribe_test_filters ) ) {
			return $value;
		}

		foreach ( $sscribe_test_filters as $filter ) {
			if ( $filter['hook'] !== $hook_name || ! is_callable( $filter['callback'] ) ) {
				continue;
			}

			$value = call_user_func( $filter['callback'], $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		global $sscribe_test_current_user_can;
		unset( $capability );
		return (bool) $sscribe_test_current_user_can;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		global $sscribe_test_is_admin;
		return (bool) $sscribe_test_is_admin;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		global $sscribe_test_doing_ajax;
		return (bool) $sscribe_test_doing_ajax;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.org/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . md5( (string) $action );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		global $sscribe_test_menu_pages;
		$sscribe_test_menu_pages[] = array(
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'callback'   => $callback,
			'icon_url'   => $icon_url,
			'position'   => $position,
		);
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		global $sscribe_test_styles;
		$sscribe_test_styles[] = compact( 'handle', 'src', 'deps', 'ver', 'media' );
		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $data ) {
		global $sscribe_test_styles;
		$sscribe_test_styles[] = compact( 'handle', 'data' );
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		global $sscribe_test_scripts;
		$sscribe_test_scripts[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		global $sscribe_test_localized;
		$sscribe_test_localized[] = compact( 'handle', 'object_name', 'l10n' );
		return true;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $display = true ) {
		$result = ( $checked == $current ) ? 'checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
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

if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( $post_type = 'post', $perm = 'readable' ) {
		
		$counts                = new \stdClass();
		$counts->publish       = 5;
		$counts->draft         = 2;
		$counts->private       = 1;
		$counts->future        = 0;
		$counts->pending       = 1;
		$counts->inherit       = 0;
		$counts->trash         = 0;
		$counts->{'auto-draft'} = 0;
		return $counts;
	}
}

if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
	if ( file_exists( SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php' ) ) {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
	}
	
	
}

if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-prefixed-runtime-shim.php';
	require_once SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-autoloader.php';
