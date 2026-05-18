<?php
/**
 * Fired during plugin deactivation.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Deactivator
 */
class SScribe_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
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

		// NOTE: Database tables are intentionally NOT dropped on deactivation.
		// Deactivation is often temporary (troubleshooting, staging migration).
		// Tables are only removed on full uninstall (uninstall.php).
		// See issue #9: Deactivator deletes export files on deactivate.
		//
		// Version history: Prior to 1.1.3, the deactivator dropped sscribe_sessions
		// and sscribe_export_stats tables. This was changed in 1.1.3 to preserve
		// data across deactivation/reactivation cycles, matching the behavior of
		// most enterprise WordPress plugins.
		try {
			self::cleanup_options();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SScribe deactivation error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Remove all plugin-specific options from wp_options.
	 *
	 * @return void
	 */
	private static function cleanup_options(): void {
		$options_to_remove = array(
			'sscribe_export_index',
			'sscribe_schema_version',
			'sscribe_version',
			'sscribe_session_signing_key',
			'sscribe_upgrade_last_error',
			'sscribe_settings',
			'sscribe_active_languages',
			'sscribe_export_metrics',
		);

		foreach ( $options_to_remove as $option ) {
			delete_option( $option );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivation cleanup.
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'sscribe_session_' ) . '%'
			)
		);

		foreach ( $sessions as $session ) {
			delete_option( $session->option_name );
		}

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

	/**
	 * Remove plugin tables — REMOVED in 1.1.3.
	 *
	 * Previously dropped sscribe_sessions and sscribe_export_stats on deactivation.
	 * Moved to uninstall.php only to preserve data across deactivation/reactivation.
	 *
	 * @deprecated 1.1.3
	 * @return void
	 */
}
