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
	delete_option( 'sscribe_session_sodium_key' );
	delete_option( 'sscribe_content_cache_generation' );

	delete_option( 'sscribe_debug_enabled' );
	delete_option( 'sscribe_debug_log_level' );
	delete_option( 'sscribe_debug_auto_refresh' );
	delete_option( 'sscribe_upgrade_last_error' );
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

	$sscribe_upload_dir = wp_upload_dir();
	$sscribe_dirs       = array();
	if ( empty( $sscribe_upload_dir['error'] ) && ! empty( $sscribe_upload_dir['basedir'] ) ) {
		$sscribe_upload_base = untrailingslashit( (string) $sscribe_upload_dir['basedir'] );
		$sscribe_dirs        = array(
			$sscribe_upload_base . '/sscribe-exports',
		);
	}
	foreach ( $sscribe_dirs as $dir_path ) {
		if ( is_link( $dir_path ) ) {
			wp_delete_file( $dir_path );
			continue;
		}
		if ( is_dir( $dir_path ) ) {
			try {
				$real_root = realpath( $dir_path );
				if ( false === $real_root ) {
					continue;
				}
				$safe_root = rtrim( wp_normalize_path( $real_root ), '/' ) . '/';
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $iterator as $fileinfo ) {
					try {
						$entry_path = $fileinfo->getPathname();
						if ( $fileinfo->isLink() ) {
							wp_delete_file( $entry_path );
							continue;
						}
						$real_path = $fileinfo->getRealPath();
						if ( ! $real_path || ! str_starts_with( wp_normalize_path( $real_path ), $safe_root ) ) {
							continue;
						}
						if ( $fileinfo->isDir() ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup during uninstall; @ suppresses rmdir() PHP warning when the directory is non-empty (e.g. locked files). Catch above absorbs the rest.
							@rmdir( $real_path );
						} else {
							wp_delete_file( $entry_path );
						}
					} catch ( \Throwable $e ) {
						continue;
					}
				}
				if ( is_dir( $dir_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup during uninstall; @ suppresses rmdir() PHP warning when the directory is non-empty (e.g. locked files). Outer try/catch absorbs the rest.
					@rmdir( $dir_path );
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}
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
