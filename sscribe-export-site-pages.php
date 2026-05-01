<?php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Plugin URI:        https://simplixi.com/sscribe
 * Description:       Export WordPress pages to professional DOCX, PDF, HTML, or Markdown files with multilingual RTL support, SEO metadata, and secure ZIP download.
 * Version:           3.47.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Simplix Innovations
 * Author URI:        https://simplixi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sscribe-export-site-pages
 * Domain Path:       languages
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version - single source of truth.
 * All version references read from the plugin header above.
 */
if ( ! defined( 'SSCRIBE_VERSION' ) ) {
	define( 'SSCRIBE_VERSION', '3.47.0' );
}

/**
 * Check PHP Version gracefully.
 *
 * If the environment is running < PHP 8.2, do not load the classes.
 * Show an admin notice instead to prevent fatal errors on older sites.
 */
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="error"><p>%s %s</p></div>',
				esc_html__( 'SScribe Export Site Pages requires PHP 8.2 or higher to run. Please upgrade your PHP version to benefit from enterprise-level performance and features. Your current version is:', 'sscribe-export-site-pages' ),
				esc_html( PHP_VERSION )
			);
		}
	);
	return; // Stop loading the rest of the plugin.
}

/**
 * Debug mode — enabled when WP_DEBUG is true.
 *
 * SECURITY WARNING: Debug mode exposes sensitive internal data in API responses
 * and writes detailed logs to wp-content/uploads/sscribe-logs/. This should NEVER
 * be enabled in production environments.
 *
 * To enable for development: define( 'WP_DEBUG', true ) in wp-config.php.
 * To force disable in production: define( 'SSCRIBE_DEBUG', false ) in wp-config.php.
 *
 * Note: Site admins can override this by defining SSCRIBE_DEBUG in wp-config.php.
 * The define() guard ensures wp-config.php overrides always take precedence.
 */
if ( ! defined( 'SSCRIBE_DEBUG' ) ) {
	define( 'SSCRIBE_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
}

/**
 * Secondary debug flag: controls whether debug/support info is displayed
 * on the admin page. Defaults to SSCRIBE_DEBUG but can be overridden
 * independently for production environments.
 *
 * To show debug info on admin page without enabling full debug logging:
 *   define( 'SSCRIBE_DEBUG_PUBLIC', true );
 * To hide all debug info even when WP_DEBUG is on:
 *   define( 'SSCRIBE_DEBUG_PUBLIC', false );
 */
if ( ! defined( 'SSCRIBE_DEBUG_PUBLIC' ) ) {
	define( 'SSCRIBE_DEBUG_PUBLIC', defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
}

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
 * Arabic font paths for RTL support.
 */
define( 'SSCRIBE_FONT_ARABIC', SSCRIBE_PLUGIN_DIR . 'assets/fonts/notosansarabic/NotoSansArabic-Regular.ttf' );
define( 'SSCRIBE_FONT_ARABIC_BOLD', SSCRIBE_PLUGIN_DIR . 'assets/fonts/notosansarabic/NotoSansArabic-Bold.ttf' );

require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-autoloader.php';

/**
 * Activation/deactivation hooks — register unconditionally so WP calls them
 * even when vendor dependencies are missing. The activator itself detects
 * missing vendors and bails gracefully.
 */
register_activation_hook( __FILE__, array( 'SScribe_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SScribe_Deactivator', 'deactivate' ) );

/**
 * Autoload Composer dependencies and plugin classes.
 */

$sscribe_has_dependencies = false;

if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-prefixed-runtime-shim.php';
	require_once SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
	$sscribe_has_dependencies = true;
} elseif ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
	require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
	$sscribe_has_dependencies = true;
}

if ( ! $sscribe_has_dependencies ) {
	// Graceful error handling if dependencies are missing (e.g., incomplete install).
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'SScribe Export Site Pages:', 'sscribe-export-site-pages' ),
				esc_html__( 'Required runtime dependencies are missing. Rebuild the plugin package or run "composer install" followed by "composer vendor:prefix" in the plugin directory.', 'sscribe-export-site-pages' )
			);
		}
	);
	return; // Stop loading the rest of the plugin.
}

if ( ! function_exists( 'sscribe_render_boot_error_notice' ) ) {
	/**
	 * Render a persistent admin notice when plugin boot fails.
	 *
	 * @return void
	 */
	function sscribe_render_boot_error_notice(): void {
		$boot_error = get_transient( 'sscribe_boot_error' );

		if ( ! is_array( $boot_error ) || empty( $boot_error['message'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = (string) $boot_error['message'];
		$time    = isset( $boot_error['time'] ) ? (string) $boot_error['time'] : '';

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'SScribe Export Site Pages could not finish loading.', 'sscribe-export-site-pages' ),
			esc_html( $message ),
			$time ? '<p><small>' . esc_html( $time ) . '</small></p>' : ''
		);
	}
}

add_action( 'admin_notices', 'sscribe_render_boot_error_notice' );

/**
 * Initialize the plugin safely.
 */
add_action(
	'plugins_loaded',
	static function () {
		delete_transient( 'sscribe_boot_error' );

		try {
			( new SScribe() )->run();
		} catch ( \Throwable $e ) {
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'The plugin bootstrap failed before the admin menu could be registered: %s', 'sscribe-export-site-pages' ),
						$e->getMessage()
					),
					'time'    => gmdate( 'Y-m-d H:i:s \U\T\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);

			// Critical error logging for debugging production issues.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SScribe Fatal Error Prevented: ' . $e->getMessage() );
			}
		}
	}
);








