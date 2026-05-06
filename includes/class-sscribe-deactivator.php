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

		try {
			self::cleanup_options();
			self::cleanup_database_tables();
			self::cleanup_export_files();
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
	 * Remove plugin tables.
	 *
	 * NOTE: Only session and stats tables are dropped on deactivation
	 * to keep the audit trail and export logs intact for reactivation.
	 * ALL tables are dropped on full uninstall (see uninstall.php).
	 *
	 * @return void
	 */
	private static function cleanup_database_tables(): void {
		global $wpdb;

		$tables_to_drop = array(
			$wpdb->prefix . 'sscribe_sessions',
			$wpdb->prefix . 'sscribe_export_stats',
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $tables_to_drop as $table ) {
			$table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Deactivation cleanup; table name is plugin-controlled constant (a-zA-Z0-9_ only).
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table_safe . '`' );
		}
	}

	/**
	 * Remove export files from the uploads directory.
	 *
	 * @return void
	 */
	private static function cleanup_export_files(): void {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/sscribe-exports';

		if ( ! is_dir( $export_dir ) ) {
			return;
		}

		try {
			if ( class_exists( 'SScribe_Security' ) ) {
				SScribe_Security::delete_directory( $export_dir );
			} else {
				$log_dir = $upload_dir['basedir'] . '/sscribe-logs';
				foreach ( array( $export_dir, $log_dir ) as $dir ) {
					if ( is_dir( $dir ) ) {
						$it = new \RecursiveIteratorIterator(
							new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
							\RecursiveIteratorIterator::CHILD_FIRST
						);
						foreach ( $it as $file ) {
							$file->isDir() ? @rmdir( $file->getRealPath() ) : wp_delete_file( $file->getRealPath() );
						}
						@rmdir( $dir );
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Non-fatal during deactivation — cleanup failures are logged but do not block plugin deactivation.
			\SScribe_Logger::instance()->warning( 'SScribe deactivation cleanup error: ' . $e->getMessage() );
		}
	}
}
