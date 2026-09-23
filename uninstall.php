<?php
/**
 * SScribe Uninstaller
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	define( 'SSCRIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-autoloader.php';

global $wpdb;

$sscribe_cleanup_site = static function (): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_session_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_page_ids_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_log_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_rate_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sscribe_status_counts_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_status_counts_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_export_lock_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sscribe_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sscribe_upgrade_lock' ),
			$wpdb->esc_like( '_transient_timeout_sscribe_upgrade_lock' )
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sscribe_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_' ) . '%'
		)
	);

	delete_transient( 'sscribe_activation_redirect' );
	delete_transient( 'sscribe_boot_error' );

	delete_option( 'sscribe_version' );
	delete_option( 'sscribe_export_index' );
	delete_option( 'sscribe_export_metrics' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sscribe_export_row_' ) . '%'
		)
	);
	delete_option( 'sscribe_schema_version' );
	delete_option( 'sscribe_session_signing_key' );
	delete_option( 'sscribe_session_signing_key_prev' );
	delete_option( 'sscribe_session_signing_key_prev_rotated_at' );
	delete_option( 'sscribe_session_sodium_key' );
	delete_option( 'sscribe_session_aes_key' );
	delete_option( 'sscribe_content_cache_generation' );

	delete_option( 'sscribe_debug_enabled' );
	delete_option( 'sscribe_debug_log_level' );
	delete_option( 'sscribe_debug_auto_refresh' );
	delete_option( 'sscribe_upgrade_last_error' );
	delete_option( 'sscribe_upgrade_failures' );
	delete_option( 'sscribe_upgrade_next_attempt' );
	delete_option( 'sscribe_settings' );
	delete_option( 'sscribe_active_languages' );

	$sscribe_roles = new \WP_Roles();
	$sscribe_plugin_caps = array( 'sscribe_export', 'sscribe_health' );
	foreach ( $sscribe_plugin_caps as $sscribe_cap ) {
		foreach ( $sscribe_roles->roles as $sscribe_role_name => $sscribe_role_data ) {
			$sscribe_role = get_role( $sscribe_role_name );
			if ( $sscribe_role && $sscribe_role->has_cap( $sscribe_cap ) ) {
				$sscribe_role->remove_cap( $sscribe_cap );
			}
		}
	}

	$sscribe_tables = array(
		$wpdb->prefix . 'sscribe_export_logs',
		$wpdb->prefix . 'sscribe_export_stats',
		$wpdb->prefix . 'sscribe_audit_log',
	);

	foreach ( $sscribe_tables as $sscribe_table_name ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Controlled plugin tables during uninstall. Table names are hardcoded in array above.

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $sscribe_table_name . '`' );
		// phpcs:enable

	}

	wp_clear_scheduled_hook( 'sscribe_cleanup_exports' );
	wp_clear_scheduled_hook( 'sscribe_cleanup_sessions' );
	wp_clear_scheduled_hook( 'sscribe_cleanup_audit_trail' );

	SScribe_Private_Storage::delete_owned_storage();
	SScribe_Private_Storage::delete_legacy_storage();
};

if ( is_multisite() ) {
	if ( function_exists( 'get_sites' ) ) {
		$sscribe_number = 100;
		$sscribe_offset = 0;
		while ( true ) {
			$sscribe_sites = get_sites(
				array(
					'number' => $sscribe_number,
					'offset' => $sscribe_offset,
				)
			);
			if ( empty( $sscribe_sites ) ) {
				break;
			}
			foreach ( $sscribe_sites as $sscribe_site ) {
				// get_sites() returns WP_Site objects; blog_id is the
				// canonical identifier for switch_to_blog(). The
				// is_object()/array-access fallback was dead code that
				// PHPStan level 7 flagged as unreachable.
				$sscribe_blog_id = (int) $sscribe_site->blog_id;
				switch_to_blog( $sscribe_blog_id );
				try {
					$sscribe_cleanup_site();
				} finally {
					restore_current_blog();
				}
			}
			$sscribe_offset += $sscribe_number;
		}
	}
} else {
	$sscribe_cleanup_site();
}
