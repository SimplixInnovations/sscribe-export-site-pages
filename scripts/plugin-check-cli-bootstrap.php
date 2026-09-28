<?php
/**
 * Plugin Check WP-CLI compatibility bootstrap.
 *
 * Plugin Check's cli.php is intentionally loaded before normal plugins so
 * runtime checks can prepare WordPress early. The CLI can reach
 * PHPCS checks without plugin.php when Plugin Check is inactive. Let normal
 * plugin loading define its own constants first; provide the missing path
 * only after WordPress loads. The official CLI still initializes early.
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

// Keep machine-verified report markers stable without changing site options.
WP_CLI::add_hook(
	'after_wp_load',
	static function () use ( $plugin_check_dir ): void {
		// plugin.php defines this unconditionally when active. An early fallback
		// causes a duplicate-constant warning and invalidates the raw report.
		if ( ! defined( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH' ) ) {
			define( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH', $plugin_check_dir );
		}
		switch_to_locale( 'en_US' );
	}
);

require $plugin_check_cli;
