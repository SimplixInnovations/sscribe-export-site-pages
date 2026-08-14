<?php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Description:       Export WordPress pages to professional DOCX, PDF, HTML, or Markdown files with multilingual RTL support and secure ZIP download.
 * Version:           1.9.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Simplix Innovations
 * Author URI:        https://simplixi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sscribe-export-site-pages
 * Domain Path:       /languages
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SSCRIBE_VERSION' ) ) {
	define( 'SSCRIBE_VERSION', '1.9.0' );
}

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="error"><p><strong>%1$s</strong></p><p>%2$s <code>%3$s</code></p><p>%4$s <a href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a>.</p></div>',
				esc_html__( 'SScribe Export Site Pages has been deactivated.', 'sscribe-export-site-pages' ),
				esc_html__( 'This plugin requires PHP 8.2 or higher. Your server is running PHP', 'sscribe-export-site-pages' ),
				esc_html( PHP_VERSION ),
				esc_html__( 'Ask your hosting provider to upgrade PHP, or follow the WordPress guide:', 'sscribe-export-site-pages' ),
				esc_url( 'https://make.wordpress.org/core/handbook/tutorials/upgrading-php/' ),
				esc_html__( 'Upgrading PHP on WordPress', 'sscribe-export-site-pages' )
			);
		}
	);
	add_action(
		'admin_init',
		static function () {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				return;
			}
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);
	return;
}

if ( function_exists( 'get_bloginfo' ) ) {
	$sscribe_wp_version = (string) get_bloginfo( 'version' );
} else {
	global $wp_version;
	$sscribe_wp_version = isset( $wp_version ) && is_scalar( $wp_version ) ? (string) $wp_version : '0.0';
}
if ( version_compare( $sscribe_wp_version, '6.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () use ( $sscribe_wp_version ) {
			printf(
				'<div class="error"><p><strong>%1$s</strong></p><p>%2$s <code>%3$s</code></p><p>%4$s</p></div>',
				esc_html__( 'SScribe Export Site Pages has been deactivated.', 'sscribe-export-site-pages' ),
				esc_html__( 'This plugin requires WordPress 6.0 or higher. Your installation is running', 'sscribe-export-site-pages' ),
				esc_html( $sscribe_wp_version ),
				esc_html__( 'Update WordPress from Dashboard → Updates before activating this plugin.', 'sscribe-export-site-pages' )
			);
		}
	);
	add_action(
		'admin_init',
		static function () {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				return;
			}
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);
	return;
}

if ( ! defined( 'SSCRIBE_DEBUG' ) ) {
	define( 'SSCRIBE_DEBUG', false );
}

define( 'SSCRIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

define( 'SSCRIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'SSCRIBE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-autoloader.php';

register_activation_hook( __FILE__, array( 'SScribe_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SScribe_Deactivator', 'deactivate' ) );

// Register the Settings API whitelist on every admin request.
add_action(
	'admin_init',
	array( 'SScribe_Activator', 'register_settings' )
);

$sscribe_has_dependencies = ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' )
	|| file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) );

if ( ! $sscribe_has_dependencies ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'SScribe Export Site Pages:', 'sscribe-export-site-pages' ),
				esc_html__( 'Required runtime files are missing. Please reinstall the plugin from a complete release package.', 'sscribe-export-site-pages' )
			);
		}
	);
	return;
}


add_action(
	'admin_notices',
	static function (): void {
		$boot_error = get_transient( 'sscribe_boot_error' );

		if ( ! is_array( $boot_error ) || empty( $boot_error['message'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = is_scalar( $boot_error['message'] ) && ! is_bool( $boot_error['message'] ) ? (string) $boot_error['message'] : '';
		$time    = isset( $boot_error['time'] ) && is_scalar( $boot_error['time'] ) && ! is_bool( $boot_error['time'] ) ? (string) $boot_error['time'] : '';
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'SScribe Export Site Pages could not finish loading.', 'sscribe-export-site-pages' ),
			esc_html( $message ),
			$time ? '<p><small>' . esc_html( $time ) . '</small></p>' : ''
		);
	}
);

add_action(
	'plugins_loaded',
	static function () {
		delete_transient( 'sscribe_boot_error' );

		try {
			( new SScribe() )->run();
		} catch ( \Throwable $e ) {
			$error_reference = substr( hash( 'sha256', get_class( $e ) . '|' . $e->getMessage() ), 0, 12 );
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => sprintf(
						/* translators: %s: diagnostic reference code. */
						__( 'The plugin could not start. Check the server error log and include reference %s when requesting support.', 'sscribe-export-site-pages' ),
						$error_reference
					),
					'time'    => gmdate( 'Y-m-d H:i:s \U\T\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SScribe bootstrap error [' . $error_reference . ']: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
);
