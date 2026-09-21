<?php
/**
 * Plugin Check WP-CLI compatibility bootstrap.
 *
 * Plugin Check's cli.php is intentionally loaded before normal plugins so
 * runtime checks can prepare WordPress early. The CLI can reach
 * PHPCS checks before plugin.php has defined WP_PLUGIN_CHECK_PLUGIN_DIR_PATH.
 * Define only that missing path constant, then delegate to the official CLI
 * entry point without modifying or suppressing any Plugin Check check.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

$wp_root = getenv( 'SSCRIBE_WP_ROOT' );
if ( ! is_string( $wp_root ) || '' === $wp_root ) {
	throw new RuntimeException( 'SSCRIBE_WP_ROOT is required for Plugin Check bootstrap.' );
}

$plugin_check_dir = rtrim( str_replace( '\\', '/', $wp_root ), '/' ) . '/wp-content/plugins/plugin-check/';
$plugin_check_cli = $plugin_check_dir . 'cli.php';

if ( ! is_file( $plugin_check_cli ) ) {
	throw new RuntimeException( 'Plugin Check CLI bootstrap was not found.' );
}

if ( ! defined( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH' ) ) {
	define( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH', $plugin_check_dir );
}

require $plugin_check_cli;
