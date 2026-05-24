<?php
/**
 * SScribe Uninstaller
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$sscribe_cleanup_site = static function (): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
			$wpdb->esc_like( 'sscribe_session_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
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
	delete_transient( 'sscribe_key_warning_shown' );
	delete_transient( 'sscribe_boot_error' );

	delete_option( 'sscribe_version' );
	delete_option( 'sscribe_export_index' );
	delete_option( 'sscribe_export_metrics' );
	delete_option( 'sscribe_schema_version' );
	delete_option( 'sscribe_session_signing_key' );

	delete_option( 'sscribe_debug_enabled' );
	delete_option( 'sscribe_debug_log_level' );
	delete_option( 'sscribe_debug_auto_refresh' );
	delete_option( 'sscribe_upgrade_last_error' );
	delete_option( 'sscribe_settings' );
	delete_option( 'sscribe_active_languages' );

	$sscribe_tables = array(
		$wpdb->prefix . 'sscribe_export_logs',
		$wpdb->prefix . 'sscribe_export_stats',
		$wpdb->prefix . 'sscribe_audit_log',
		$wpdb->prefix . 'sscribe_sessions',
	);

	foreach ( $sscribe_tables as $sscribe_table_name ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Controlled plugin tables during uninstall. Table names are hardcoded in array above.

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $sscribe_table_name . '`' );
		// phpcs:enable

	}

	wp_clear_scheduled_hook( 'sscribe_cleanup_exports' );
	wp_clear_scheduled_hook( 'sscribe_cleanup_sessions' );
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
				$sscribe_blog_id = is_object( $sscribe_site ) ? $sscribe_site->blog_id : $sscribe_site['blog_id'];
				switch_to_blog( (int) $sscribe_blog_id );
				$sscribe_cleanup_site();
				restore_current_blog();
			}
			$sscribe_offset += $sscribe_number;
		}
	}
} else {
	$sscribe_cleanup_site();
}

$sscribe_upload_dir = wp_upload_dir();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local file-scope variable in uninstall context, not a global.
$directories_to_clean = array(
	$sscribe_upload_dir['basedir'] . '/sscribe-exports',
	$sscribe_upload_dir['basedir'] . '/sscribe-logs',
	$sscribe_upload_dir['basedir'] . '/sscribe/mpdf-tmp',
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local foreach variable.
foreach ( $directories_to_clean as $dir_path ) {
	if ( is_dir( $dir_path ) ) {
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variable.
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local foreach variable.
			foreach ( $iterator as $fileinfo ) {
				try {
					if ( $fileinfo->isDir() ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variable.
						$real_path = $fileinfo->getRealPath();
						if ( $real_path && is_dir( $real_path ) ) {
							rmdir( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup operation during uninstall; WP_Filesystem not available in uninstall context.
						}
					} else {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variable.
						$real_path = $fileinfo->getRealPath();
						if ( $real_path ) {
							wp_delete_file( $real_path );
						}
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}

			if ( is_dir( $dir_path ) ) {
				rmdir( $dir_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup operation during uninstall; WP_Filesystem not available in uninstall context.
			}
		} catch ( \Throwable $e ) {
			continue;
		}
	}
}
