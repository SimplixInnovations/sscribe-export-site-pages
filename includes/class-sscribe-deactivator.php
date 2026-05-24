<?php
/**
 * SScribe Deactivator
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin deactivation cleanup.
 */
class SScribe_Deactivator {

	/**
	 * Run deactivation cleanup tasks.
	 *
	 * Per WordPress.org guidelines, deactivation must NOT delete user data.
	 * Only cron hooks, transients, and temporary runtime data are cleared.
	 * User options and data are removed only via uninstall.php when the user
	 * explicitly deletes the plugin through the admin plugin management screen.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'sscribe_cleanup_exports' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sscribe_cleanup_exports' );
		}

		$session_timestamp = wp_next_scheduled( 'sscribe_cleanup_sessions' );
		if ( $session_timestamp ) {
			wp_unschedule_event( $session_timestamp, 'sscribe_cleanup_sessions' );
		}

		$audit_timestamp = wp_next_scheduled( 'sscribe_cleanup_audit_trail' );
		if ( $audit_timestamp ) {
			wp_unschedule_event( $audit_timestamp, 'sscribe_cleanup_audit_trail' );
		}

		try {
			self::cleanup_transients();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SScribe deactivation error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Remove transient data only (not user options or settings).
	 */
	private static function cleanup_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivation cleanup.
		$locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_sscribe_lock_' ) . '%'
			)
		);

		foreach ( $locks as $lock ) {
			delete_option( $lock->option_name );
		}
	}
}
