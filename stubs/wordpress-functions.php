<?php
/**
 * WordPress Function Stubs (for IDE/Intelephense/PHPStan)
 *
 * This file provides function signatures for the WordPress core functions used
 * throughout the SScribe plugin. It exists for two reasons:
 *
 *  1. PHP Intelephense (the language server used by VS Code) needs the function
 *     signatures to avoid PHP0417 "Call to unknown function" warnings on every
 *     WordPress API call. Without this file, every `add_action()`, `get_post_meta()`,
 *     `wp_send_json_*()` etc. would be flagged as unknown.
 *
 *  2. PHPStan can use the same file via its `scanFiles` directive in phpstan.neon,
 *     so the long `ignoreErrors` list of "Function X not found" entries can be
 *     trimmed or removed entirely. The signatures here use `mixed` liberally
 *     to avoid changing type-inference behavior for existing code.
 *
 * Replacement path: when network access is available, install the official
 * package via `composer require --dev php-stubs/wordpress-stubs` and remove
 * this file. The official stubs are 1:1 with WordPress core function
 * signatures; this local file approximates them.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Sanitization / escaping
// ---------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string { return ''; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string { return ''; }
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $classes, $fallback = '' ) { return ''; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ): string { return ''; }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ): string { return ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title, $fallback_title = '', $context = 'save' ): string { return ''; }
}
if ( ! function_exists( 'sanitize_title_with_dashes' ) ) {
	function sanitize_title_with_dashes( $title, $raw_title = '', $context = 'display' ): string { return ''; }
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string { return ''; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ): string { return ''; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ): void {}
}
if ( ! function_exists( 'esc_html_x' ) ) {
	function esc_html_x( $text, $context, $domain = 'default' ): string { return ''; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string { return ''; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ): string { return ''; }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ): void {}
}
if ( ! function_exists( 'esc_attr_x' ) ) {
	function esc_attr_x( $text, $context, $domain = 'default' ): string { return ''; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $_context = 'display' ): string { return ''; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ): string { return ''; }
}
if ( ! function_exists( 'wp_validate_url' ) ) {
	function wp_validate_url( $url, $object = null ): string|false {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$allowed = array( 'http', 'https', 'ftp' );
		$scheme  = strtolower( (string) ( parse_url( $url, PHP_URL_SCHEME ) ?: '' ) );
		return in_array( $scheme, $allowed, true ) ? esc_url_raw( $url ) : false;
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ): string { return ''; }
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $sql ) { return ''; }
}
if ( ! function_exists( 'esc_like' ) ) {
	function esc_like( $text ): string { return ''; }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ): string { return ''; }
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $content, $allowed_html, $allowed_protocols = array() ): string { return ''; }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string { return ''; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'wp_kses_post_deep' ) ) {
	function wp_kses_post_deep( $content ) { return $content; }
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { return (string) $text; }
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ): void {}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ): string { return (string) $text; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ): string { return (string) $single; }
}
if ( ! function_exists( '_nx' ) ) {
	function _nx( $single, $plural, $number, $context, $domain = 'default' ): string { return (string) $single; }
}
if ( ! function_exists( '_n_noop' ) ) {
	function _n_noop( $singular, $plural, $domain = null ): array { return array( '', '' ); }
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ): int { return 0; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ): string { return ''; }
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = null, $original_text = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field, $index_key = null ) { return array(); }
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $content ): string { return ''; }
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content, $ignore_html = false ): string { return ''; }
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ): array { return array(); }
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ): string { return ''; }
}

// ---------------------------------------------------------------------------
// Nonce / capability / security
// ---------------------------------------------------------------------------

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ): int|false { return 1; }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ): int|false { return 1; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string { return ''; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ): string { return ''; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { return new WP_User(); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ): bool { return true; }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability, ...$args ): bool { return true; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return true; }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ): bool { return true; }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {}
}
if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $response, $status_code = null, $options = 0 ): void {}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null, $options = 0 ): void {}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null, $options = 0 ): void {}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ): string|false { return ''; }
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return false; }
}
if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer(): string|false { return false; }
}
if ( ! function_exists( 'wp_get_raw_referer' ) ) {
	function wp_get_raw_referer(): string|false { return false; }
}

// ---------------------------------------------------------------------------
// Options / transients / metadata
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { return $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ): bool { return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool { return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ): bool { return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ): bool { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ): bool { return true; }
}
if ( ! function_exists( 'add_transient' ) ) {
	function add_transient( $transient, $value, $expiration = 0 ): bool { return true; }
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) { return ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ): int|bool { return true; }
}
if ( ! function_exists( 'update_post_meta_cache' ) ) {
	/**
	 * Prime the post meta cache for the given posts so subsequent
	 * get_post_meta() calls do not hit the database.
	 *
	 * @param int[]|int $post_ids Array of post IDs or a single post ID.
	 * @return array|false False on failure, otherwise an empty array.
	 */
	function update_post_meta_cache( $post_ids ): array|false { return array(); }
}
if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ): int|false { return 1; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key, $meta_value = '' ): bool { return true; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) { return ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ): int|bool { return true; }
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { return false; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expiration = 0 ): bool { return true; }
}
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expiration = 0 ): bool { return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ): bool { return true; }
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush(): bool { return true; }
}
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool { return false; }
}
if ( ! function_exists( 'wp_suspend_cache_invalidation' ) ) {
	function wp_suspend_cache_invalidation( $suspend = true ): bool { return true; }
}
if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $post ) {}
}
if ( ! function_exists( 'clean_term_cache' ) ) {
	function clean_term_cache( $ids, $taxonomy = '', $clean_taxonomy = true ) {}
}

// ---------------------------------------------------------------------------
// Posts / pages / queries
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @return WP_Post|array|null
	 */
	function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) { return null; }
}
if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * WordPress core returns array|WP_Post[] in practice but can return false on
	 * malformed $args. Stub returns array but the union keeps the runtime
	 * `is_array()` guard meaningful at the call sites.
	 *
	 * @return array|WP_Post[]|false
	 */
	function get_posts( $args = null ) { return array(); }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0, $leavename = false ) { return ''; }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) { return ''; }
}
if ( ! function_exists( 'get_the_content' ) ) {
	function get_the_content( $more_link_text = null, $strip_teaser = false ) { return ''; }
}
if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( $post = null ) { return ''; }
}
if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( $field = '', $user_id = false ) { return ''; }
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format = '', $post = null ) { return ''; }
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( $format = '', $post = null ) { return ''; }
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) { return ''; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) { return false; }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) { return null; }
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) { return array(); }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post = null ) { return ''; }
}
if ( ! function_exists( 'get_post_ancestors' ) ) {
	function get_post_ancestors( $post ) { return array(); }
}
if ( ! function_exists( 'get_children' ) ) {
	/**
	 * @return WP_Post[]|array
	 */
	function get_children( $args = '', $output = OBJECT ) { return array(); }
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment, $unfiltered = false ) { return false; }
}
if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	/**
	 * WordPress core returns array|false. Stub returns false but is declared
	 * `array|false` so IDE narrowing inside the runtime guard
	 * `if ( $image_src ) { $url = $image_src[0]; }` resolves correctly.
	 *
	 * @return array|false
	 */
	function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail', $icon = false ): array|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) { return false; }
}
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id, $unfiltered = false ) { return false; }
}
if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( $type = 'post', $perm = '' ) { return (object) array(); }
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ): bool { return false; }
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ): bool { return false; }
}
if ( ! function_exists( 'is_single' ) ) {
	function is_single( $post = '' ): bool { return false; }
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool { return false; }
}
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool { return false; }
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool { return false; }
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool { return false; }
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool { return false; }
}
if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site( $site_id = null ): bool { return true; }
}
if ( ! function_exists( 'is_user_admin' ) ) {
	function is_user_admin(): bool { return false; }
}
if ( ! function_exists( 'is_network_admin' ) ) {
	function is_network_admin(): bool { return false; }
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ): bool { return false; }
}
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ): bool { return false; }
}
if ( ! function_exists( 'is_plugin_inactive' ) ) {
	function is_plugin_inactive( $plugin ): bool { return true; }
}
if ( ! function_exists( 'is_plugin_page' ) ) {
	function is_plugin_page(): bool { return false; }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool { return false; }
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool { return false; }
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post_id ): bool { return false; }
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post_id ): bool { return false; }
}

if ( ! function_exists( 'setup_postdata' ) ) {
	function setup_postdata( $post ) { return true; }
}
if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata() {}
}
if ( ! function_exists( 'wp_reset_query' ) ) {
	function wp_reset_query() {}
}
if ( ! function_exists( 'rewind_posts' ) ) {
	function rewind_posts() {}
}
if ( ! function_exists( 'get_post_status_object' ) ) {
	function get_post_status_object( $post_status ) { return null; }
}
if ( ! function_exists( 'get_post_stati' ) ) {
	function get_post_stati( $args = array(), $output = 'names', $operator = 'and' ) { return array(); }
}
if ( ! function_exists( 'register_post_status' ) ) {
	function register_post_status( $post_status, $args = array() ): object|null { return null; }
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ): bool { return false; }
}
if ( ! function_exists( 'post_status_exists' ) ) {
	function post_status_exists( $post_status ): bool { return false; }
}
if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( $post_id = 0, $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_get_post_tags' ) ) {
	function wp_get_post_tags( $post_id = 0, $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_set_post_terms' ) ) {
	function wp_set_post_terms( $post_id, $tags = '', $taxonomy = 'post_tag', $append = false ) { return array(); }
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) { return array(); }
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) { return array(); }
}

// ---------------------------------------------------------------------------
// Taxonomies / terms
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * WordPress core returns WP_Error|array|WP_Term|null depending on $output.
	 * Default $output=OBJECT yields WP_Term; ARRAY_A/ARRAY_N yield array;
	 * invalid input yields WP_Error; missing term yields null. Stub returns
	 * null but the union keeps the runtime `if ( $term && ! is_wp_error( $term ) )`
	 * narrowing meaningful at the call sites.
	 *
	 * @return \WP_Error|array|WP_Term|null
	 */
	function get_term( $term, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) { return null; }
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) { return false; }
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) { return array(); }
}
if ( ! function_exists( 'get_term_children' ) ) {
	function get_term_children( $term_id, $taxonomy ) { return array(); }
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object, $output = 'names' ) { return array(); }
}
if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( $taxonomy ) { return false; }
}
if ( ! function_exists( 'get_taxonomies' ) ) {
	function get_taxonomies( $args = array(), $output = 'names', $operator = 'and' ) { return array(); }
}
if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomy = '', $term = '' ): bool { return false; }
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ): bool { return false; }
}
if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, $taxonomy = '', $parent = null ) { return null; }
}
if ( ! function_exists( 'wp_get_object_term_cache' ) ) {
	function wp_get_object_term_cache( $object_ids, $taxonomies ) { return array(); }
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) { return false; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) { return false; }
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) { return array(); }
}
if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( $username ) { return false; }
}
if ( ! function_exists( 'email_exists' ) ) {
	function email_exists( $email ) { return false; }
}
if ( ! function_exists( 'wp_create_user' ) ) {
	function wp_create_user( $username, $password, $email = '' ): int|\WP_Error { return 0; }
}
if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( $userdata ): int|\WP_Error { return 0; }
}
if ( ! function_exists( 'wp_delete_user' ) ) {
	function wp_delete_user( $id, $reassign = null ): bool { return true; }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id, $name = '' ) { return new WP_User(); }
}
if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
	function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) { return false; }
}
if ( ! function_exists( 'wp_generate_auth_cookie' ) ) {
	function wp_generate_auth_cookie( $user_id, $expiration, $scheme = 'auth', $token = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_set_auth_cookie' ) ) {
	function wp_set_auth_cookie( $user_id, $remember = false, $secure = '', $token = '' ) {}
}
if ( ! function_exists( 'wp_clear_auth_cookie' ) ) {
	function wp_clear_auth_cookie() {}
}
if ( ! function_exists( 'is_user_member_of_blog' ) ) {
	function is_user_member_of_blog( $user_id = 0, $blog_id = 0 ): bool { return true; }
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $role ) { return null; }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return new WP_Roles(); }
}
if ( ! function_exists( 'add_role' ) ) {
	function add_role( $role, $display_name, $capabilities = array() ) { return null; }
}
if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( $role ) {}
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): bool { return true; }
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook_name, $callback, $priority = 10 ): bool { return false; }
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook_name, $callback, $priority = 10 ): bool { return false; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$arg ) {}
}
if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( $hook_name, $args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'apply_filters_ref_array' ) ) {
	function apply_filters_ref_array( $hook_name, $args ) { return $args[0] ?? null; }
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook_name, $callback = false ): int|false { return false; }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook_name, $callback = false ): int|false { return false; }
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ): int { return 0; }
}
if ( ! function_exists( 'current_action' ) ) {
	function current_action(): string|false { return false; }
}
if ( ! function_exists( 'current_filter' ) ) {
	function current_filter(): string|false { return false; }
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ): bool { return false; }
}
if ( ! function_exists( 'doing_filter' ) ) {
	function doing_filter( $hook_name = null ): bool { return false; }
}
if ( ! function_exists( 'remove_all_actions' ) ) {
	function remove_all_actions( $hook_name, $priority = false ): int { return 0; }
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $hook_name, $priority = false ): int { return 0; }
}

// ---------------------------------------------------------------------------
// Plugin / theme / filesystem
// ---------------------------------------------------------------------------

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ): string { return ''; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ): string { return ''; }
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ): string { return ''; }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ): string { return ''; }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook( $file, $callback ) {}
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ): bool { return true; }
}
if ( ! function_exists( 'load_theme_textdomain' ) ) {
	function load_theme_textdomain( $domain, $path = '' ): bool { return true; }
}
if ( ! function_exists( 'is_textdomain_loaded' ) ) {
	function is_textdomain_loaded( $domain ): bool { return false; }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ): array { return array(); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ): bool { return true; }
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ): bool { return true; }
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ): bool { return true; }
}
if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem( $args = false, $credentials = false ): bool { return true; }
}
if ( ! function_exists( 'request_filesystem_credentials' ) ) {
	function request_filesystem_credentials( $form_post = '', $type = '', $error = false, $context = false, $extra_fields = null ) { return false; }
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_safe_remote_request' ) ) {
	function wp_safe_remote_request( $url, $args = array() ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string|array { return ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int|string { return 200; }
}
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( $response ): \Requests_Utility_CaseInsensitiveDictionary|array { return array(); }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ): array|string { return ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_cookie' ) ) {
	function wp_remote_retrieve_cookie( $response, $name ): string { return ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_cookies' ) ) {
	function wp_remote_retrieve_cookies( $response ): array { return array(); }
}
if ( ! function_exists( 'wp_get_http_headers' ) ) {
	function wp_get_http_headers( $url, $deprecated = false ) { return false; }
}

// ---------------------------------------------------------------------------
// URLs / formatting
// ---------------------------------------------------------------------------

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ): string { return ''; }
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ): string { return ''; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ): string { return ''; }
}
if ( ! function_exists( 'includes_url' ) ) {
	function includes_url( $path = '', $scheme = null ): string { return ''; }
}
if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ): string { return ''; }
}
if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( $path = '', $scheme = 'admin' ): string { return ''; }
}
if ( ! function_exists( 'user_admin_url' ) ) {
	function user_admin_url( $path = '', $scheme = 'admin' ): string { return ''; }
}
if ( ! function_exists( 'self_admin_url' ) ) {
	function self_admin_url( $path = '', $scheme = 'admin' ): string { return ''; }
}
if ( ! function_exists( 'get_admin_url' ) ) {
	function get_admin_url( $user_id, $path = '', $scheme = 'admin' ): string { return ''; }
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $user_id = 0, $path = '', $scheme = null ): string { return ''; }
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( $user_id = 0, $path = '', $scheme = null ): string { return ''; }
}
if ( ! function_exists( 'get_rest_url' ) ) {
	function get_rest_url( $user_id = 0, $path = '', $scheme = 'rest' ): string { return ''; }
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = '' ): string { return (string) $url; }
}
if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $key, $url = null ): string { return (string) $url; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ): string { return rtrim( (string) $value, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ): string { return str_replace( '\\', '/', (string) $path ); }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ): string { return rtrim( (string) $value, '/\\' ); }
}
if ( ! function_exists( 'user_trailingslashit' ) ) {
	function user_trailingslashit( $string, $type_of_url = '' ): string { return trailingslashit( (string) $string ); }
}
if ( ! function_exists( 'url_shorten' ) ) {
	function url_shorten( $url, $length = 35 ): string { return (string) $url; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ): string|false { return ''; }
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string { return 'en_US'; }
}
if ( ! function_exists( 'get_user_locale' ) ) {
	function get_user_locale( $user = 0 ): string { return get_locale(); }
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ): string { return ''; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ): string { return ''; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ): int|string { return 0; }
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ): string { return ''; }
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ): string { return ''; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ): string { return (string) $number; }
}
if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ): int { return 0; }
}
if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
	function wp_raise_memory_limit( $context = 'admin' ): int|string|false { return 0; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ): string { return ''; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ): int { return 0; }
}
if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( $prefix = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string { return ''; }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) { return @unserialize( (string) $data, array( 'allowed_classes' => false ) ); }
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ): string { return ''; }
}
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ): bool { return false; }
}
if ( ! function_exists( 'is_serialized_string' ) ) {
	function is_serialized_string( $data ): bool { return false; }
}
if ( ! function_exists( 'wp_check_jsonp_callback' ) ) {
	function wp_check_jsonp_callback( $callback = false ): bool { return false; }
}

// ---------------------------------------------------------------------------
// Scripts / styles / assets
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ): bool { return true; }
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ): bool { return true; }
}
if ( ! function_exists( 'wp_deregister_style' ) ) {
	function wp_deregister_style( $handle ) {}
}
if ( ! function_exists( 'wp_deregister_script' ) ) {
	function wp_deregister_script( $handle ) {}
}
if ( ! function_exists( 'wp_dequeue_style' ) ) {
	function wp_dequeue_style( $handle ) {}
}
if ( ! function_exists( 'wp_dequeue_script' ) ) {
	function wp_dequeue_script( $handle ) {}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $data, $position = 'after' ): bool { return true; }
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ): bool { return true; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ): bool { return true; }
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ): bool { return true; }
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $list = 'enqueued' ): bool { return false; }
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $list = 'enqueued' ): bool { return false; }
}
if ( ! function_exists( 'wp_styles' ) ) {
	function wp_styles(): \WP_Styles { return new \WP_Styles(); }
}
if ( ! function_exists( 'wp_scripts' ) ) {
	function wp_scripts(): \WP_Scripts { return new \WP_Scripts(); }
}

// ---------------------------------------------------------------------------
// Admin menu / page helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ): string { return ''; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_management_page' ) ) {
	function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_plugins_page' ) ) {
	function add_plugins_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_theme_page' ) ) {
	function add_theme_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_dashboard_page' ) ) {
	function add_dashboard_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_posts_page' ) ) {
	function add_posts_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_media_page' ) ) {
	function add_media_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'add_users_page' ) ) {
	function add_users_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ): string|false { return false; }
}
if ( ! function_exists( 'remove_menu_page' ) ) {
	function remove_menu_page( $menu_slug ): array|false { return false; }
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $menu_slug, $submenu_slug ): array|false { return false; }
}
if ( ! function_exists( 'get_admin_page_title' ) ) {
	function get_admin_page_title(): string { return ''; }
}
if ( ! function_exists( 'get_plugin_page_hook' ) ) {
	function get_plugin_page_hook( $plugin_page, $parent_page ): string|null { return null; }
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ): array { return array(); }
}
if ( ! function_exists( 'plugin_sandbox_scrape' ) ) {
	function plugin_sandbox_scrape( $content ): string { return ''; }
}
if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page ) {}
}
if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {}
}
if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $page ) {}
}
if ( ! function_exists( 'do_settings_fields' ) ) {
	function do_settings_fields( $page, $section ) {}
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = array() ): bool { return true; }
}
if ( ! function_exists( 'unregister_setting' ) ) {
	function unregister_setting( $option_group, $option_name, $deprecated = '' ): bool { return true; }
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $option_group ) {}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ): string { return ''; }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ): string { return ''; }
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, $echo = true ): string { return ''; }
}
if ( ! function_exists( 'readonly' ) ) {
	function readonly( $readonly, $current = true, $echo = true ): string { return ''; }
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): \WP_Screen|null { return null; }
}
if ( ! function_exists( 'get_admin_notices' ) ) {
	function get_admin_notices() { return false; }
}
if ( ! function_exists( 'self_link' ) ) {
	function self_link(): string { return ''; }
}
if ( ! function_exists( 'get_submit_button' ) ) {
	function get_submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ): string { return ''; }
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ): int|false { return false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ): bool|\WP_Error { return true; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ): bool|\WP_Error { return true; }
}
if ( ! function_exists( 'wp_reschedule_event' ) ) {
	function wp_reschedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ): bool|\WP_Error { return true; }
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ): bool|\WP_Error { return true; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ): int|\WP_Error { return 0; }
}
if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( $hook, $wp_error = false ): int|\WP_Error { return 0; }
}
if ( ! function_exists( 'wp_get_schedule' ) ) {
	function wp_get_schedule( $hook, $args = array() ): string|false { return false; }
}

// ---------------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int { return 1; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return 0; }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $new_blog, $deprecated = null ): bool { return true; }
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool { return true; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string { return 'UTC'; }
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $prefix = '', $dir = '' ): string|false { return ''; }
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string { return 'production'; }
}
if ( ! function_exists( 'wp_get_development_mode' ) ) {
	function wp_get_development_mode(): string { return ''; }
}
if ( ! function_exists( 'wp_installing' ) ) {
	function wp_installing( $is_installing = null ): bool { return false; }
}
if ( ! function_exists( 'wp_is_site_initialized' ) ) {
	function wp_is_site_initialized(): bool { return true; }
}
if ( ! function_exists( 'is_blog_admin' ) ) {
	function is_blog_admin(): bool { return false; }
}
if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ): bool { return true; }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html( $context = '' ): array { return array(); }
}
if ( ! function_exists( 'wp_kses_init' ) ) {
	function wp_kses_init() {}
}
if ( ! function_exists( 'wp_kses_named_entities' ) ) {
	function wp_kses_named_entities( $content ): string { return (string) $content; }
}
if ( ! function_exists( 'wp_kses_hook' ) ) {
	function wp_kses_hook( $content, $allowed_html, $allowed_protocols ): string { return (string) $content; }
}
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( $content ): string { return (string) $content; }
}
if ( ! function_exists( 'wp_filter_comment_kses' ) ) {
	function wp_filter_comment_kses( $content ): string { return (string) $content; }
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ): array { return array( 'ext' => '', 'type' => '', 'proper_filename' => false ); }
}
if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null ): array { return array(); }
}
if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types( $user = null ): array { return array(); }
}
if ( ! function_exists( 'wp_get_mime_types' ) ) {
	function wp_get_mime_types(): array { return array(); }
}
if ( ! function_exists( 'wp_ext2type' ) ) {
	function wp_ext2type( $ext ): string|false { return false; }
}
if ( ! function_exists( 'wp_get_default_extension_for_mime_type' ) ) {
	function wp_get_default_extension_for_mime_type( $mime_type ): string|false { return false; }
}
if ( ! function_exists( 'get_post_mime_types' ) ) {
	function get_post_mime_types(): array { return array(); }
}
if ( ! function_exists( 'wp_get_image_editor' ) ) {
	function wp_get_image_editor( $path, $args = array() ): \WP_Image_Editor|\WP_Error { return new \WP_Error( '' ); }
}
if ( ! function_exists( 'wp_load_press_this' ) ) {
	function wp_load_press_this() {}
}
if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
	function wp_read_audio_metadata( $file ): array|false { return false; }
}
if ( ! function_exists( 'wp_read_video_metadata' ) ) {
	function wp_read_video_metadata( $file ): array|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_id3_keys' ) ) {
	function wp_get_attachment_id3_keys( $attachment, $context = 'display' ): array { return array(); }
}
if ( ! function_exists( 'wp_add_id3_tag_data' ) ) {
	function wp_add_id3_tag_data( &$metadata, $data ): void {}
}
if ( ! function_exists( 'wp_calculate_image_srcset' ) ) {
	function wp_calculate_image_srcset( $size_array, $image_src, $image_meta, $attachment_id = 0 ): array|false { return false; }
}
if ( ! function_exists( 'wp_calculate_image_sizes' ) ) {
	function wp_calculate_image_sizes( $size, $image_src = null, $image_meta = null, $attachment_id = 0 ): string|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	function wp_get_attachment_image( $attachment_id, $size = 'thumbnail', $icon = false, $attr = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_get_attachment_caption' ) ) {
	function wp_get_attachment_caption( $post_id = 0 ): string { return ''; }
}
if ( ! function_exists( 'wp_get_attachment_link' ) ) {
	function wp_get_attachment_link( $id = 0, $size = 'thumbnail', $permalink = false, $icon = false, $text = false, $attr = '' ): string { return ''; }
}
if ( ! function_exists( 'wp_prepare_attachment_for_js' ) ) {
	function wp_prepare_attachment_for_js( $attachment ): array { return array(); }
}
if ( ! function_exists( 'wp_send_json_to_editor' ) ) {
	function wp_send_json_to_editor( $m ) {}
}
if ( ! function_exists( 'media_send_to_editor' ) ) {
	function media_send_to_editor( $html ) {}
}
if ( ! function_exists( 'get_media_item' ) ) {
	function get_media_item( $attachment_id, $args = null ) { return ''; }
}
if ( ! function_exists( 'get_attachment_taxonomies' ) ) {
	function get_attachment_taxonomies( $attachment = 0 ): array { return array(); }
}
if ( ! function_exists( 'wp_media_attach_action' ) ) {
	function wp_media_attach_action( $parent_id, $action = 'attach' ) {}
}
if ( ! function_exists( 'wp_get_post_parent_id' ) ) {
	function wp_get_post_parent_id( $post_ID ): int { return 0; }
}
if ( ! function_exists( 'wp_create_post_autosave' ) ) {
	function wp_create_post_autosave( $post_data ): array|int { return 0; }
}
if ( ! function_exists( 'wp_save_post_revision' ) ) {
	function wp_save_post_revision( $post_id ): int|\WP_Error { return 0; }
}
if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	function wp_get_post_revisions( $post_id, $args = null ) { return array(); }
}
if ( ! function_exists( 'wp_revisions_to_keep' ) ) {
	function wp_revisions_to_keep( $post ): int { return 0; }
}
if ( ! function_exists( '_wp_post_thumbnail_class_filter' ) ) {
	function _wp_post_thumbnail_class_filter( $attr ): array { return $attr; }
}
if ( ! function_exists( '_wp_post_thumbnail_class_filter_add' ) ) {
	function _wp_post_thumbnail_class_filter_add( $attr ) { return $attr; }
}
if ( ! function_exists( '_wp_post_thumbnail_class_filter_remove' ) ) {
	function _wp_post_thumbnail_class_filter_remove( $attr ) { return $attr; }
}
if ( ! function_exists( '_post_thumbnail_class_filter' ) ) {
	function _post_thumbnail_class_filter( $attr ) { return $attr; }
}
if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ): bool { return false; }
}
if ( ! function_exists( 'the_post_thumbnail' ) ) {
	function the_post_thumbnail( $size = 'post-thumbnail', $attr = '' ): string { return ''; }
}
if ( ! function_exists( 'get_the_post_thumbnail' ) ) {
	function get_the_post_thumbnail( $post = null, $size = 'post-thumbnail', $attr = '' ): string { return ''; }
}
if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ): string|false { return false; }
}
if ( ! function_exists( 'get_the_post_thumbnail_caption' ) ) {
	function get_the_post_thumbnail_caption( $post = null ): string { return ''; }
}
if ( ! function_exists( 'update_post_thumbnail_cache' ) ) {
	function update_post_thumbnail_cache( $wp_query = null ) {}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post, $thumbnail_id ): int|bool { return true; }
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( $post ): bool { return true; }
}
if ( ! function_exists( 'wp_read_image_metadata' ) ) {
	function wp_read_image_metadata( $file ) { return false; }
}
if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
	function wp_get_registered_image_subsizes(): array { return array(); }
}
if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
	function wp_create_image_subsizes( $file, $attachment_id ): array { return array(); }
}
if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
	function wp_update_image_subsizes( $attachment_id ): array|\WP_Error { return array(); }
}
if ( ! function_exists( 'wp_get_missing_image_subsizes' ) ) {
	function wp_get_missing_image_subsizes( $attachment_id ): array { return array(); }
}
if ( ! function_exists( 'wp_get_attachment_image_srcset' ) ) {
	function wp_get_attachment_image_srcset( $attachment_id, $size = 'medium', $image_meta = null ): string|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_image_sizes' ) ) {
	function wp_get_attachment_image_sizes( $attachment_id, $size = 'medium', $image_meta = null ): string|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail', $icon = false ): string|false { return false; }
}
if ( ! function_exists( 'wp_get_attachment_image_attributes' ) ) {
	function wp_get_attachment_image_attributes( $attr, $attachment, $size ): array { return $attr; }
}
if ( ! function_exists( 'wp_get_attachment_image_width_height' ) ) {
	function wp_get_attachment_image_width_height( $attachment_id, $size = 'thumbnail' ): array { return array(); }
}
if ( ! function_exists( 'wp_get_attachment_image_alt' ) ) {
	function wp_get_attachment_image_alt( $attachment_id ): string|false { return false; }
}
if ( ! function_exists( 'wp_filter_content_tags' ) ) {
	function wp_filter_content_tags( $content, $context = null ): string { return (string) $content; }
}

// ---------------------------------------------------------------------------
// Database (wpdb is provided by the WP_Scribble class, not a function — but a
// few helpers are still used as functions).
// ---------------------------------------------------------------------------

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ): array { return array(); }
}
if ( ! function_exists( 'wp_load_alloptions' ) ) {
	function wp_load_alloptions( $force_cache = false ): array { return array(); }
}
if ( ! function_exists( 'wp_load_translations_early' ) ) {
	function wp_load_translations_early() {}
}
if ( ! function_exists( 'wp_load_core_wp_options' ) ) {
	function wp_load_core_wp_options( $options = array() ): array { return array(); }
}
if ( ! function_exists( 'wp_load_plugin_dependencies' ) ) {
	function wp_load_plugin_dependencies(): array { return array(); }
}
if ( ! function_exists( 'wp_register_plugin_realpath' ) ) {
	function wp_register_plugin_realpath( $plugin_file ) {}
}
if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
	function wp_clean_plugins_cache( $clear_update_cache = true ) {}
}
if ( ! function_exists( 'wp_get_active_and_valid_plugins' ) ) {
	function wp_get_active_and_valid_plugins(): array { return array(); }
}
if ( ! function_exists( 'wp_get_active_network_plugins' ) ) {
	function wp_get_active_network_plugins(): array { return array(); }
}
if ( ! function_exists( 'wp_get_associated_theme' ) ) {
	function wp_get_associated_theme( $stylesheet, $containing_theme = null ): array { return array(); }
}
if ( ! function_exists( 'wp_set_wpdb_vars' ) ) {
	function wp_set_wpdb_vars() {}
}
if ( ! function_exists( 'wp_set_current_db_version' ) ) {
	function wp_set_current_db_version() {}
}
if ( ! function_exists( 'wp_upgrade' ) ) {
	function wp_upgrade() {}
}

// ---------------------------------------------------------------------------
// Constants commonly used in WP code (class/string constant-style)
// ---------------------------------------------------------------------------

if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}
if ( ! defined( 'FS_CONNECT_TIMEOUT' ) ) {
	define( 'FS_CONNECT_TIMEOUT', 30 );
}
if ( ! defined( 'FS_TIMEOUT' ) ) {
	define( 'FS_TIMEOUT', 30 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}

// ---------------------------------------------------------------------------
// WordPress core class shells (minimal — only the properties/methods touched
// by the SScribe plugin, just enough for PHPStan and Intelephense to know
// what `$screen->id` / `wp_send_json_error( $wp_error )` are valid usages).
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Screen' ) ) {
	class WP_Screen {
		public string $id          = '';
		public string $base        = '';
		public string $parent_base = '';
		public string $post_type   = '';
		public ?string $taxonomy   = null;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $error_code = '';
		public array $errors      = array();
		public array $error_data  = array();
		public function __construct( $code = '', $message = '', $data = '' ) {}
		public function get_error_code(): string { return $this->error_code; }
		public function get_error_message(): string { return ''; }
		public function get_error_data( $code = '' ) { return array(); }
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID                  = 0;
		public string $user_login       = '';
		public string $user_email       = '';
		public string $user_nicename    = '';
		public string $user_url         = '';
		public string $user_registered  = '';
		public string $user_status      = '';
		public string $display_name     = '';
		public array $roles             = array();
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	class WP_Roles {
		public array $roles         = array();
		public array $role_objects  = array();
		public array $role_names    = array();
		public function get_role( $role ) { return null; }
		public function add_role( $role, $display_name, $capabilities = array() ) { return null; }
		public function remove_role( $role ) { return null; }
		public function role_exists( $role ): bool { return false; }
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID                = 0;
		public int $post_author       = 0;
		public string $post_date      = '';
		public string $post_date_gmt  = '';
		public string $post_content   = '';
		public string $post_title     = '';
		public string $post_excerpt   = '';
		public string $post_status    = '';
		public string $comment_status = '';
		public string $ping_status    = '';
		public string $post_password  = '';
		public string $post_name      = '';
		public string $to_ping        = '';
		public string $pinged         = '';
		public string $post_modified  = '';
		public string $post_modified_gmt = '';
		public string $post_content_filtered = '';
		public int $post_parent       = 0;
		public string $guid           = '';
		public int $menu_order        = 0;
		public string $post_type      = '';
		public string $post_mime_type = '';
		public int $comment_count     = 0;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id          = 0;
		public string $name          = '';
		public string $slug          = '';
		public string $term_group    = '';
		public int $term_taxonomy_id = 0;
		public string $taxonomy      = '';
		public string $description   = '';
		public int $parent           = 0;
		public int $count            = 0;
		public string $filter        = '';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $query         = array();
		public array $query_vars    = array();
		public int $max_num_pages   = 0;
		public int $found_posts     = 0;
		public array $posts         = array();
		public ?WP_Post $post       = null;
		public function __construct( $query = '' ) {}
		public function have_posts(): bool { return false; }
		public function the_post() {}
		public function rewind_posts() {}
		public function get_posts(): array { return array(); }
	}
}

if ( ! class_exists( 'WP_Styles' ) ) {
	class WP_Styles {
		public array $registered = array();
		public array $queue      = array();
		public array $done       = array();
		public function enqueue( $handle ) {}
		public function dequeue( $handle ) {}
	}
}

if ( ! class_exists( 'WP_Scripts' ) ) {
	class WP_Scripts {
		public array $registered = array();
		public array $queue      = array();
		public array $done       = array();
		public function enqueue( $handle ) {}
		public function dequeue( $handle ) {}
	}
}

if ( ! class_exists( 'WP_Image_Editor' ) ) {
	abstract class WP_Image_Editor {
		abstract public function load( $file ): bool|wp_error;
	}
}

if ( ! class_exists( 'Requests_Utility_CaseInsensitiveDictionary' ) ) {
	class Requests_Utility_CaseInsensitiveDictionary implements \ArrayAccess, \IteratorAggregate, \Countable {
		public function offsetExists( $offset ): bool { return false; }
		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) { return null; }
		public function offsetSet( $offset, $value ): void {}
		public function offsetUnset( $offset ): void {}
		public function getIterator(): \Traversable { return new \ArrayIterator( array() ); }
		public function count(): int { return 0; }
	}
}

// ---------------------------------------------------------------------------
// wpdb — WordPress database access abstraction class.
//
// Stub provides the table properties and query helpers actually referenced
// by the SScribe plugin so PHP Intelephense does not flag every
// `$wpdb->postmeta` / `$wpdb->last_error` access as an undefined property.
// The runtime contract is identical to WordPress core; the methods here
// return safely-typed defaults so unit tests / fake-wp can satisfy calls
// without booting a real MySQL connection.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix         = 'wp_';
		public int $insert_id         = 0;
		public int $num_queries       = 0;
		public string $last_error     = '';
		public string $last_query     = '';
		public string $last_result    = '';
		public string $db_version     = '';
		public string $db_server_info = '';
		public array $tables          = array();
		// Per-table handles — WordPress core sets these in set_sql_mode / db_connect.
		public array $posts               = array();
		public array $postmeta            = array();
		public array $options             = array();
		public array $users               = array();
		public array $usermeta            = array();
		public array $terms               = array();
		public array $term_taxonomy       = array();
		public array $term_relationships  = array();
		public array $commentmeta         = array();
		public array $comments            = array();
		public array $links               = array();
		public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {}
		public function query( $query ) { return false; }
		public function get_var( $query = null, $x = 0, $y = 0 ) { return null; }
		public function get_row( $query = null, $output = OBJECT, $y = 0 ) { return null; }
		public function get_col( $query = null, $x = 0 ) { return array(); }
		public function get_results( $query = null, $output = OBJECT ) { return array(); }
		public function prepare( $query, ...$args ): string { return (string) $query; }
		public function insert( $table, $data, $format = null ) { return 1; }
		public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
		public function delete( $table, $where, $where_format = null ) { return 1; }
		public function replace( $table, $data, $format = null ) { return 1; }
		public function flush() { return true; }
		public function esc_like( $text ): string { return addcslashes( (string) $text, '_%\\' ); }
		public function hide_errors() { return false; }
		public function show_errors( $show = true ) { return false; }
		public function print_error( $str = '' ) {}
		public function check_safe_collation( $query ): bool { return true; }
		public function set_charset( $dbh, $charset = null, $collate = null ) { return true; }
		public function db_connect( $allow_bail = true ) { return false; }
	}
}

// ---------------------------------------------------------------------------
// WP_Filesystem_Base — abstract base for the WP_Filesystem_* transport
// drivers (direct, ssh2, ftpext, ftpsockets). Plugin code calls these
// methods through `global $wp_filesystem;` after WP_Filesystem() init.
//
// The stub exists so the IDE knows delete()/is_writable()/dirlist()/
// copy()/move() exist on the base class; runtime signatures match core.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	abstract class WP_Filesystem_Base {
		public string $verbose = '';
		public array $options  = array();
		public function abspath() { return ''; }
		public function wp_content_dir() { return ''; }
		public function wp_plugins_dir() { return ''; }
		public function wp_themes_dir() { return ''; }
		public function wp_lang_dir() { return ''; }
		public function find_folder( $folder ) { return ''; }
		public function search_for_folder( $folder, $base = '.', $loop = false ) { return ''; }
		public function wp_plugins_dir_iis7( $plugin_folder = '' ) { return false; }
		public function wp_content_url() { return ''; }
		public function theme_root() { return ''; }
		public function theme_root_uri() { return ''; }
		public function exists( $file ): bool { return false; }
		public function is_file( $file ): bool { return false; }
		public function is_dir( $path ): bool { return false; }
		public function is_readable( $file ): bool { return false; }
		public function is_writable( $file ): bool { return false; }
		public function mkdir( $path, $chmod = false, $chown = false, $chgrp = false ): bool { return true; }
		public function rmdir( $path, $recursive = false ): bool { return true; }
		public function dirlist( $path, $include_hidden = true, $recursive = false ): array|false { return array(); }
		public function delete( $file, $recursive = false, $type = false ): bool { return true; }
		public function copy( $source, $destination, $overwrite = false, $mode = false ): bool { return true; }
		public function move( $source, $destination, $overwrite = false ): bool { return true; }
		public function get_contents( $file ): string|false { return false; }
		public function put_contents( $file, $contents, $mode = false ): bool { return true; }
		public function chmod( $file, $mode = false, $recursive = false ): bool { return true; }
		public function touch( $file, $time = 0, $atime = 0 ): bool { return true; }
		public function owner( $file ): string|false { return false; }
		public function group( $file ): string|false { return false; }
		public function perm( $file ): string|false { return false; }
		public function method() { return ''; }
		public function connect() { return true; }
		public function bail( $message, $header = '' ) {}
		public function errors(): array { return array(); }
		public function error_get_last() { return null; }
		public function init( $url = '', $verb = 'GET', $temp = false ) {}
	}
}

// Phase 25: WP_Site class + get_sites() function — needed for static
// analysis of uninstall.php's multisite loop (per-site table cleanup).
if ( ! class_exists( 'WP_Site', false ) ) {
	class WP_Site {
		public int $blog_id;
		public string $domain;
		public string $path;
		public int $network_id;
		public string $registered;
		public string $last_updated;
		public bool $public;
		public bool $archived;
		public bool $mature;
		public bool $spam;
		public bool $deleted;
		public int $lang_id;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * @param array<string, mixed> $args Arguments. The 'fields' key
	 *                                     controls the returned shape:
	 *                                     'ids' returns int[], any
	 *                                     other value returns WP_Site[].
	 * @phpstan-return (
	 *     $args is array{fields: 'ids'} ? array<int, int>
	 *     : array<int, WP_Site>
	 * )
	 */
	function get_sites( $args = array() ): array {
		return array();
	}
}
