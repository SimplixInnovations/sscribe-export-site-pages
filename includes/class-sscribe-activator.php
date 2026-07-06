<?php
/**
 * SScribe Activator
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
 * Plugin activation routines: database tables, directories, scheduled tasks.
 */
class SScribe_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Handles both single-site and multisite network activation.
	 * WordPress passes $network_wide as a second parameter to the
	 * callback registered via register_activation_hook().
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	public static function activate( bool $network_wide = false ): void {

		$existing_version = get_option( 'sscribe_version', null );
		if ( null === $existing_version ) {
			delete_option( 'sscribe_export_index' );
			delete_option( 'sscribe_schema_version' );
			delete_option( 'sscribe_version' );
		}

		if ( ! file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' )
			&& ! file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' )
		) {
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => sprintf(
						/* translators: %s: plugin version */
						__( 'Activation aborted: required runtime dependencies are missing. Run "composer install" in the plugin directory or reinstall the plugin package. Version: %s', 'sscribe-export-site-pages' ),
						SSCRIBE_VERSION
					),
					'time'    => gmdate( 'Y-m-d H:i:s \\U\\T\\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);
			return;
		}

		if ( $network_wide && is_multisite() ) {
			self::activate_network_wide();
		} else {
			self::activate_single_site();
		}

		set_transient( 'sscribe_activation_redirect', '1', MINUTE_IN_SECONDS );
	}

	/**
	 * Activate plugin for all sites in a multisite network.
	 */
	private static function activate_network_wide(): void {
		if ( ! function_exists( 'get_sites' ) ) {
			self::activate_single_site();
			return;
		}

		$number  = 100;
		$offset  = 0;
		$blog_id = 0;

		while ( true ) {
			$sites = get_sites(
				array(
					'number' => $number,
					'offset' => $offset,
					'fields' => 'ids',
				)
			);

			if ( empty( $sites ) ) {
				break;
			}

			foreach ( $sites as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::activate_single_site();
				restore_current_blog();
			}

			$offset += $number;
		}
	}

	/**
	 * Activate plugin for the current site.
	 */
	private static function activate_single_site(): void {
		self::create_export_directory();
		self::create_database_tables();
		self::register_settings();
		self::schedule_cleanup();
		self::cleanup_orphaned_data();
		self::grant_export_capability();
		update_option( 'sscribe_version', SSCRIBE_VERSION, false );
	}

	/**
	 * Create plugin database tables.
	 */
	private static function create_database_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_logs = $wpdb->prefix . 'sscribe_export_logs';

		$sql_logs = "CREATE TABLE $table_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT,
			user_id BIGINT UNSIGNED,
			request_id VARCHAR(12),
			memory_usage VARCHAR(20),
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_level (level),
			KEY idx_user_id (user_id),
			KEY idx_request_id (request_id)
		) $charset_collate;";

		$table_stats = $wpdb->prefix . 'sscribe_export_stats';

		$sql_stats = "CREATE TABLE $table_stats (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			export_session_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			export_date DATETIME NOT NULL,
			total_pages INT UNSIGNED,
			successful_pages INT UNSIGNED,
			failed_pages INT UNSIGNED,
			formats LONGTEXT,
			memory_peak VARCHAR(20),
			duration_seconds FLOAT,
			file_size_mb DECIMAL(10, 2),
			status ENUM('completed', 'failed', 'paused') DEFAULT 'completed',
			error_message TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_export_session_id (export_session_id),
			KEY idx_export_date (export_date),
			KEY idx_user_id (user_id),
			KEY idx_status (status)
		) $charset_collate;";

		$upgrade_functions = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade_functions ) ) {
			require_once $upgrade_functions;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => __( 'Activation could not create database tables because the WordPress upgrade functions are unavailable.', 'sscribe-export-site-pages' ),
					'time'    => gmdate( 'Y-m-d H:i:s \\U\\T\\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);
			return;
		}

		dbDelta( $sql_logs );
		dbDelta( $sql_stats );

		$table_sessions = $wpdb->prefix . 'sscribe_sessions';
		$sql_sessions   = "CREATE TABLE $table_sessions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status ENUM('pending', 'processing', 'completed', 'failed', 'paused', 'cancelled') DEFAULT 'pending',
			language VARCHAR(10) NOT NULL DEFAULT '',
			post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
			formats LONGTEXT,
			total_pages INT UNSIGNED DEFAULT 0,
			processed_pages INT UNSIGNED DEFAULT 0,
			current_page_index INT UNSIGNED DEFAULT 0,
			page_ids LONGTEXT,
			session_data LONGTEXT,
			signature VARCHAR(64),
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			expires_at DATETIME,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_session_id (session_id),
			KEY idx_user_id (user_id),
			KEY idx_status (status),
			KEY idx_expires_at (expires_at)
		) $charset_collate;";
		dbDelta( $sql_sessions );

		SScribe_Audit_Trail::create_table();
	}

	/**
	 * Register WordPress settings via register_setting().
	 * Required for WordPress Plugin Review compliance.
	 */
	private static function register_settings(): void {
		register_setting(
			'sscribe_settings',
			'sscribe_debug_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'sscribe_settings',
			'sscribe_debug_log_level',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'DEBUG',
			)
		);

		register_setting(
			'sscribe_settings',
			'sscribe_debug_auto_refresh',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
	}

	/**
	 * Create and protect the export directory.
	 */
	private static function create_export_directory(): void {
		$upload_dir  = wp_upload_dir();
		$export_path = untrailingslashit( $upload_dir['basedir'] ) . '/sscribe-exports';

		SScribe_Security::protect_directory( $export_path );
	}

	/**
	 * Schedule cleanup cron jobs.
	 */
	private static function schedule_cleanup(): void {
		$interval = apply_filters( 'sscribe_cleanup_interval', 'hourly' );
		if ( ! wp_next_scheduled( 'sscribe_cleanup_exports' ) ) {
			wp_schedule_event( time(), $interval, 'sscribe_cleanup_exports' );
		}

		if ( ! wp_next_scheduled( 'sscribe_cleanup_sessions' ) ) {
			wp_schedule_event( time(), $interval, 'sscribe_cleanup_sessions' );
		}

		$audit_interval = apply_filters( 'sscribe_audit_cleanup_interval', 'daily' );
		if ( ! wp_next_scheduled( 'sscribe_cleanup_audit_trail' ) ) {
			wp_schedule_event( time(), $audit_interval, 'sscribe_cleanup_audit_trail' );
		}
	}

	/**
	 * Clean up orphaned transients and options.
	 */
	private static function cleanup_orphaned_data(): void {
		global $wpdb;

		$session_pattern = $wpdb->esc_like( '_transient_sscribe_session_' ) . '%';
		$lock_pattern    = $wpdb->esc_like( '_transient_sscribe_lock_' ) . '%';
		$rate_pattern    = $wpdb->esc_like( '_transient_sscribe_rate_' ) . '%';

		$session_option_pattern = $wpdb->esc_like( 'sscribe_session_' ) . '%';

		$patterns = array( $session_pattern, $lock_pattern, $rate_pattern, $session_option_pattern );

		foreach ( $patterns as $pattern ) {

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during activation.
				$rows = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",
						$pattern
					)
				);
			} while ( false !== $rows && $rows > 0 );
		}

		$timeout_patterns = array(
			$wpdb->esc_like( '_transient_timeout_sscribe_session_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_rate_' ) . '%',
		);

		foreach ( $timeout_patterns as $pattern ) {
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during activation.
				$rows = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",
						$pattern
					)
				);
			} while ( false !== $rows && $rows > 0 );
		}
	}

	/**
	 * Grant the scribe_export and scribe_health capabilities to the
	 * Administrator role.
	 *
	 * The two capabilities are deliberately separate: an editor who
	 * has been granted `scribe_export` to run exports should NOT
	 * automatically be able to read the health diagnostics endpoint,
	 * which surfaces PHP version, memory state, plugin versions and
	 * other server fingerprint information. Splitting the capability
	 * lets site admins grant the read-only diagnostic without granting
	 * the (more powerful) export functionality.
	 *
	 * Other roles can be granted access via the
	 * `sscribe_export_capability` and `sscribe_health_capability` filters
	 * or by manually assigning the capabilities.
	 */
	private static function grant_export_capability(): void {
		$admin_role = get_role( 'administrator' );
		if ( ! $admin_role ) {
			return;
		}

		if ( ! $admin_role->has_cap( 'sscribe_export' ) ) {
			$admin_role->add_cap( 'sscribe_export' );
		}

		if ( ! $admin_role->has_cap( 'sscribe_health' ) ) {
			$admin_role->add_cap( 'sscribe_health' );
		}
	}
}
