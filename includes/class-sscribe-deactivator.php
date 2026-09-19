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
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			$limit  = 100;

			do {
				$site_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => $limit,
						'offset' => $offset,
					)
				);

				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					try {
						self::deactivate_site();
					} finally {
						restore_current_blog();
					}
				}

				$offset += count( $site_ids );
			} while ( count( $site_ids ) === $limit );

			return;
		}

		self::deactivate_site();
	}

	/**
	 * Clear runtime state for the current site without deleting user data.
	 */
	private static function deactivate_site(): void {
		wp_clear_scheduled_hook( 'sscribe_cleanup_exports' );
		wp_clear_scheduled_hook( 'sscribe_cleanup_sessions' );
		wp_clear_scheduled_hook( 'sscribe_cleanup_audit_trail' );

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

		$patterns = array(
			$wpdb->esc_like( '_transient_sscribe_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_' ) . '%',
			$wpdb->esc_like( 'sscribe_export_lock_' ) . '%',
			$wpdb->esc_like( 'sscribe_rate_lock_' ) . '%',
		);
		foreach ( $patterns as $pattern ) {
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivation cleanup of plugin-owned transients.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",
						$pattern
					)
				);
				foreach ( $rows as $row ) {
					delete_option( $row->option_name );
				}
				$row_count = count( $rows );
			} while ( $row_count >= 1000 );
		}

		delete_transient( 'sscribe_upgrade_lock' );
	}
}
