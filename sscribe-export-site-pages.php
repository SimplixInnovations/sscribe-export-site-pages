<?php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Plugin URI:        https://simplixi.com/sscribe
 * Description:       Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.
 * Version:           3.2.0
 * Requires at least: 5.8
 * Requires PHP:      8.1
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
define( 'SSCRIBE_VERSION', '3.2.0' );

/**
 * Check PHP Version gracefully.
 *
 * If the environment is running < PHP 8.1, do not load the classes.
 * Show an admin notice instead to prevent fatal errors on older sites.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="error"><p>';
			echo esc_html__( 'SScribe Export Site Pages requires PHP 8.1 or higher to run. Please upgrade your PHP version to benefit from enterprise-level performance and features. Your current version is: ', 'sscribe-export-site-pages' );
			echo esc_html( PHP_VERSION );
			echo '</p></div>';
		}
	);
	return; // Stop loading the rest of the plugin.
}

/**
 * Debug mode - set to true to enable logging to wp-content/uploads/sscribe-logs/
 * Automatically disabled on production (when WP_DEBUG is false).
 */
define( 'SSCRIBE_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );

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

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';

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
	try {
		if ( class_exists( 'SScribe' ) ) {
			$plugin = new SScribe();
			$plugin->run();
		}
	} catch ( \Throwable $e ) {
		error_log( 'SScribe Fatal Error Prevented: ' . $e->getMessage() );
	}
}

add_action( 'plugins_loaded', 'sscribe_init' );
