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

		self::cleanup_options();
		self::cleanup_transients();
		self::cleanup_database_tables();
		self::cleanup_export_files();
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
			'sscribe_adaptive_metrics',
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
	 * Remove plugin transients.
	 *
	 * @return void
	 */
	private static function cleanup_transients(): void {
		delete_transient( 'sscribe_cron_cleanup_lock' );
		delete_transient( 'sscribe_upgrade_lock' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivation cleanup.
		$user_transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_sscribe_active_session_' ) . '%'
			)
		);

		foreach ( $user_transients as $ut ) {
			delete_option( $ut->option_name );
		}
	}

	/**
	 * Drop plugin-specific database tables.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Deactivation cleanup; table name is plugin-controlled.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->esc_sql( $table ) . '`' );
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

		if ( class_exists( 'SScribe_Security' ) ) {
			SScribe_Security::delete_directory( $export_dir );
		} else {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				$wp_filesystem->rmdir( $export_dir, true );
			}
		}
	}
}
