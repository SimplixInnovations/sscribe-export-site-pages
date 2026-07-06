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
			self::revoke_plugin_capabilities();
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
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_sscribe_lock_' ) . '%',
				$wpdb->esc_like( '_transient_sscribe_rate_' ) . '%',
				$wpdb->esc_like( '_transient_sscribe_active_sid_' ) . '%'
			)
		);

		foreach ( $transients as $t ) {
			delete_option( $t->option_name );
		}

		delete_transient( 'sscribe_upgrade_lock' );
	}

	/**
	 * Revoke the scribe_export capability from all roles.
	 *
	 * Per WordPress.org guidelines, deactivation cleans up runtime data.
	 * The capability is a plugin-specific addition and should be removed
	 * on deactivation to keep the role database clean.
	 */
	private static function revoke_plugin_capabilities(): void {
		$wp_roles = new \WP_Roles();

		$sscribe_capabilities = array( 'sscribe_export', 'sscribe_health' );

		foreach ( $sscribe_capabilities as $sscribe_cap ) {
			foreach ( $wp_roles->roles as $role_name => $role_data ) {
				$role = get_role( $role_name );
				if ( $role && $role->has_cap( $sscribe_cap ) ) {
					$role->remove_cap( $sscribe_cap );
				}
			}
		}
	}
}
