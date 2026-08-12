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
		// Properly strip tags while preserving allowed ones and their attributes.
		$tag_names = array_keys( $allowed_html );
		$tag_list  = '<' . implode( '><', $tag_names ) . '>';
		return strip_tags( $content, $tag_list );
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
		unset( $sscribe_post_id );
		if ( array_key_exists( 'sscribe_test_post_type_override', $GLOBALS ) ) {
			return $GLOBALS['sscribe_test_post_type_override'];
		}
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

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $sscribe_string ) {
		return rtrim( $sscribe_string, '/\\' );
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

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $prefix = 'tmp' ) {
		return tempnam( sys_get_temp_dir(), $prefix );
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

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		global $sscribe_test_actions;
		foreach ( $sscribe_test_actions as $action ) {
			if ( $action['hook'] === $hook_name && is_callable( $action['callback'] ) ) {
				call_user_func_array( $action['callback'], $args );
			}
		}
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

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = array() ) {
		global $sscribe_test_registered_settings;
		$sscribe_test_registered_settings[ $option_name ] = array(
			'group' => $option_group,
			'args'  => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		global $sscribe_test_scheduled_events;
		unset( $args );
		return $sscribe_test_scheduled_events[ $hook ]['timestamp'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
		global $sscribe_test_scheduled_events;
		unset( $args, $wp_error );
		$sscribe_test_scheduled_events[ $hook ] = array(
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = false ) {
		global $sscribe_test_scheduled_events;
		unset( $timestamp, $args );
		unset( $sscribe_test_scheduled_events[ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		global $sscribe_test_scheduled_events;
		unset( $args );
		unset( $sscribe_test_scheduled_events[ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 1;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post_id ) {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post_id ) {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		unset( $blog_id );
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		return true;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $args = array() ) {
		unset( $args );
		return array();
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public string $name;
		public array $capabilities = array();

		public function __construct( string $name, array $capabilities = array() ) {
			$this->name         = $name;
			$this->capabilities = $capabilities;
		}

		public function has_cap( string $capability ): bool {
			return ! empty( $this->capabilities[ $capability ] );
		}

		public function add_cap( string $capability, bool $grant = true ): void {
			$this->capabilities[ $capability ] = $grant;
		}

		public function remove_cap( string $capability ): void {
			unset( $this->capabilities[ $capability ] );
		}
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	class WP_Roles {
		public array $roles = array();

		public function __construct() {
			$this->roles = array(
				'administrator' => array(
					'name'         => 'Administrator',
					'capabilities' => array( 'manage_options' => true ),
				),
			);
		}
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( $role ) {
		// Tests can inject specific role overrides via this global.
		if ( isset( $GLOBALS['sscribe_test_role_overrides'] ) && is_array( $GLOBALS['sscribe_test_role_overrides'] ) && isset( $GLOBALS['sscribe_test_role_overrides'][ $role ] ) ) {
			return $GLOBALS['sscribe_test_role_overrides'][ $role ];
		}

		static $roles = null;
		if ( null === $roles ) {
			$roles = array(
				'administrator' => new WP_Role( 'administrator', array( 'manage_options' => true ) ),
			);
		}

		return $roles[ $role ] ?? null;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['sscribe_test_wp_cache'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
		if ( ! isset( $GLOBALS['sscribe_test_wp_cache'] ) ) {
			$GLOBALS['sscribe_test_wp_cache'] = array();
		}
		if ( isset( $GLOBALS['sscribe_test_wp_cache'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['sscribe_test_wp_cache'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		if ( ! isset( $GLOBALS['sscribe_test_wp_cache'][ $key ] ) ) {
			$found = false;
			return false;
		}
		$found = true;
		return $GLOBALS['sscribe_test_wp_cache'][ $key ];
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		if ( ! isset( $GLOBALS['sscribe_test_wp_cache'] ) ) {
			$GLOBALS['sscribe_test_wp_cache'] = array();
		}
		$GLOBALS['sscribe_test_wp_cache'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		if ( ! isset( $GLOBALS['sscribe_test_wp_cache'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['sscribe_test_wp_cache'][ $key ] += $offset;
		return $GLOBALS['sscribe_test_wp_cache'][ $key ];
	}
}


if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public int $found_posts = 0;
		public int $max_num_pages = 0;

		public function __construct( $query = array() ) {
			// Test-time stub. If a test sets
			// $GLOBALS['sscribe_test_wp_query_chunks'] to an array of
			// integer arrays, the stub yields each chunk in order on
			// successive constructions (i.e. successive paged calls).
			// Anything else: empty result set.
			$chunks = isset( $GLOBALS['sscribe_test_wp_query_chunks'] ) && is_array( $GLOBALS['sscribe_test_wp_query_chunks'] )
				? $GLOBALS['sscribe_test_wp_query_chunks']
				: array();

			$call_index = isset( $GLOBALS['sscribe_test_wp_query_calls'] ) ? (int) $GLOBALS['sscribe_test_wp_query_calls'] : 0;
			if ( ! isset( $GLOBALS['sscribe_test_wp_query_calls'] ) ) {
				$GLOBALS['sscribe_test_wp_query_calls'] = 0;
			}
			++$GLOBALS['sscribe_test_wp_query_calls'];

			if ( isset( $chunks[ $call_index ] ) && is_array( $chunks[ $call_index ] ) ) {
				$this->posts = array_values( array_map( 'intval', $chunks[ $call_index ] ) );
			} else {
				$this->posts = array();
			}
			$this->found_posts  = count( $this->posts );
			$this->max_num_pages = count( $this->posts ) > 0 ? 1 : 0;
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
		return isset( $GLOBALS['sscribe_test_current_user_id'] ) ? (int) $GLOBALS['sscribe_test_current_user_id'] : 1;
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

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		if ( null === $timestamp ) {
			$timestamp = time();
		}
		if ( $timezone instanceof \DateTimeZone ) {
			$dt = new \DateTime( '@' . $timestamp );
			$dt->setTimezone( $timezone );
			return $dt->format( $format );
		}
		return gmdate( $format, $timestamp );
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

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( (string) $email, FILTER_SANITIZE_EMAIL ) ?: '';
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
$sscribe_test_registered_settings = array();
$sscribe_test_scheduled_events    = array();
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
			// WordPress prepare() accepts either variadic args or a single array.
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0];
			}
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
				return array_key_exists( $matches[1], (array) $sscribe_test_db_tables ) ? $matches[1] : null;
			}

			return null;
		}

		public function query( $query ) {
			global $sscribe_test_db_tables;

			if ( preg_match( '/DELETE\s+FROM\s+(\w+)/i', $query, $matches ) ) {
				$table = $matches[1];
				if ( isset( $sscribe_test_db_tables[ $table ] ) && is_array( $sscribe_test_db_tables[ $table ] ) ) {
					$count = count( $sscribe_test_db_tables[ $table ] );
					$sscribe_test_db_tables[ $table ] = array();
					return $count;
				}
				return 0;
			}

			return 0;
		}

		public function get_results( $query, $output = null ) {
			global $sscribe_test_options, $sscribe_test_db_tables;

			if ( false !== strpos( $query, $this->options ) && preg_match_all( "/LIKE '([^']+)'/i", $query, $like_matches ) ) {
				$patterns = array();
				foreach ( $like_matches[1] as $raw ) {
					$unrolled    = str_replace( '\\_', '_', $raw );
					$patterns[] = '/^' . str_replace( '%', '.*', preg_quote( $unrolled, '/' ) ) . '$' . '/';
				}
				$results = array();

				foreach ( (array) $sscribe_test_options as $option_name => $option_value ) {
					foreach ( $patterns as $pattern ) {
						if ( preg_match( $pattern, $option_name ) ) {
							$results[] = (object) array(
								'option_name'  => $option_name,
								'option_value' => $option_value,
							);
							break;
						}
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

		public function get_row( $query = null, $output = null, $y = 0 ) {
			unset( $query, $output, $y );
			return null;
		}

		public function insert( $table, $data, $format = null ) {
			global $sscribe_test_db_tables;

			if ( ! isset( $sscribe_test_db_tables[ $table ] ) || ! is_array( $sscribe_test_db_tables[ $table ] ) ) {
				return false;
			}

			$sscribe_test_db_tables[ $table ][] = $data;
			return 1;
		}

		public function delete( $table, $where, $where_format = null ) {
			global $sscribe_test_db_tables;

			if ( ! isset( $sscribe_test_db_tables[ $table ] ) || ! is_array( $sscribe_test_db_tables[ $table ] ) ) {
				return false;
			}

			$count = 0;
			$remaining = array();
			foreach ( $sscribe_test_db_tables[ $table ] as $row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					if ( array_key_exists( $key, $row ) && (string) $row[ $key ] === (string) $value ) {
						$matches = false;
						++$count;
						break;
					}
				}
				if ( $matches ) {
					$remaining[] = $row;
				}
			}
			$sscribe_test_db_tables[ $table ] = $remaining;

			return $count;
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

if ( ! function_exists( 'wp_validate_url' ) ) {
	function wp_validate_url( $url, $object = null ) {
		unset( $object );
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https', 'ftp' ), true ) ) {
			return false;
		}
		return esc_url_raw( $url ) ?: false;
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

// Mock stubs only needed in non-WP environments — WP_Query available via bootstrap


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

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Test-bench counterpart of WordPress's remove_filter(). Removes the
	 * first matching callback from the in-memory filter registry so that
	 * tests that register a listener via add_filter() can clean up
	 * without leaking the closure into the next test's apply_filters().
	 * Returns false when no matching entry is present, matching WP.
	 */
	function remove_filter( $sscribe_hook, $sscribe_callback, $sscribe_priority = 10 ) {
		global $sscribe_test_filters;

		if ( ! is_array( $sscribe_test_filters ) ) {
			return false;
		}

		$sscribe_remaining = array();
		$sscribe_removed   = false;
		foreach ( $sscribe_test_filters as $sscribe_filter ) {
			if (
				! $sscribe_removed
				&& $sscribe_filter['hook'] === $sscribe_hook
				&& $sscribe_filter['callback'] === $sscribe_callback
				&& (int) $sscribe_filter['priority'] === (int) $sscribe_priority
			) {
				$sscribe_removed = true;
				continue;
			}
			$sscribe_remaining[] = $sscribe_filter;
		}
		$sscribe_test_filters = $sscribe_remaining;

		return $sscribe_removed;
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

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		global $sscribe_test_doing_cron;
		return (bool) ( $sscribe_test_doing_cron ?? false );
	}
}

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', false );
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.org/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = array(), string $output = 'names', string $operator = 'and' ): array {
		return array( 'page', 'post' );
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
			// @-suppress: Windows file locks from the test's own write
			// can leave the just-created index.php handle open for a
			// tick; the production code never hits this. PHPUnit's
			// failOnWarning="true" turns the harmless notice into a
			// non-zero exit, so silence the operand.
			return @unlink( $sscribe_file );
		}
		return false;
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// WordPress schema helpers normally live in wp-admin/includes/upgrade.php
// which the unit suite does not load. The upgrader/activator call
// require_once ABSPATH . 'wp-admin/includes/upgrade.php' (which then warns
// in this environment) and immediately invoke dbDelta(); provide a no-op
// shim so the migration bookkeeping runs to completion and the test
// assertions on sscribe_schema_version / sscribe_version can succeed.
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ) {
		unset( $queries, $execute );
		return array();
	}
}

if ( ! function_exists( 'maybe_create_table' ) ) {
	function maybe_create_table( $table_name, $create_ddl ) {
		unset( $table_name, $create_ddl );
		return true;
	}
}

if ( ! function_exists( 'wp_should_upgrade_global_tables' ) ) {
	function wp_should_upgrade_global_tables() {
		return false;
	}
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

if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
} elseif ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
	if ( file_exists( SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php' ) ) {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
	}
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-autoloader.php';

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz_string = get_option( 'timezone_string' );
		if ( ! empty( $tz_string ) ) {
			return new \DateTimeZone( $tz_string );
		}
		$offset = (float) get_option( 'gmt_offset' );
		$hours  = (int) $offset;
		$mins   = (int) ( ( $offset - $hours ) * 60 );
		return new \DateTimeZone( sprintf( '%+03d:%02d', -$hours, -$mins ) );
	}
}
