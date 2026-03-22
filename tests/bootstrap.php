<?php
/**
 * PHPUnit Test Bootstrap
 *
 * @package SScribe
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/fake-wp/');
}

define('SSCRIBE_VERSION', '1.0.0');
define('SSCRIBE_PLUGIN_DIR', dirname(__DIR__) . '/');
define('SSCRIBE_PLUGIN_URL', 'http://example.org/wp-content/plugins/sscribe-export-site-pages/');
define('SSCRIBE_PLUGIN_BASENAME', 'sscribe-export-site-pages/sscribe-export-site-pages.php');

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        return [];
    }
}

if (!function_exists('get_post_field')) {
    function get_post_field($field, $post_id)
    {
        return '';
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id)
    {
        return 'http://example.org/?p=' . $post_id;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post_id = 0)
    {
        return 'Test Title';
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status($post_id)
    {
        return 'publish';
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post_id)
    {
        return 'page';
    }
}

if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        return false;
    }
}

require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
