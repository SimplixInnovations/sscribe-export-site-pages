<?php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Plugin URI:        https://simplixi.com/sscribe
 * Description:       Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.
 * Version:           1.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Simplix Innovations
 * Author URI:        https://simplixi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sscribe-export-site-pages
 * Domain Path:       /languages
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
define( 'SSCRIBE_VERSION', '1.2.1' );

/**
 * Plugin directory path.
 */
define( 'SSCRIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'SSCRIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'SSCRIBE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload Composer dependencies and plugin classes.
 */
if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, array( 'SScribe_Activator', 'activate' ) );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, array( 'SScribe_Deactivator', 'deactivate' ) );

/**
 * Initialize the plugin safely.
 *
 * @return void
 */
function sscribe_init() {
	// Try-catch block to prevent hard crashes during activation or bootstrapping.
	try {
		if ( class_exists( 'SScribe' ) ) {
			$plugin = new SScribe();
			$plugin->run();
		}
	} catch ( \Throwable $e ) {
		// Log to WP debug if an underlying dependency crashes at runtime.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'SScribe Fatal Error Prevented: ' . $e->getMessage() );
	}
}

add_action( 'plugins_loaded', 'sscribe_init' );
