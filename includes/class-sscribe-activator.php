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

		$missing_extensions = SScribe_Vendor_Bootstrap::get_missing_extensions();
		if ( ! empty( $missing_extensions ) ) {
			$message = sprintf(
				/* translators: %s: comma-separated PHP extension names. */
				__( 'Activation aborted: required PHP extensions are missing: %s.', 'sscribe-export-site-pages' ),
				implode( ', ', array_map( 'sanitize_key', $missing_extensions ) )
			);
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => $message,
					'time'    => gmdate( 'Y-m-d H:i:s \\U\\T\\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);
			wp_die(
				esc_html( $message ),
				esc_html__( 'SScribe activation failed', 'sscribe-export-site-pages' ),
				array( 'back_link' => true )
			);
		}

		if ( ! SScribe_Vendor_Bootstrap::is_available() ) {
			$message = sprintf(
				/* translators: %s: plugin version */
				__( 'Activation aborted: required runtime dependencies are missing. Reinstall the complete plugin package. Version: %s', 'sscribe-export-site-pages' ),
				SSCRIBE_VERSION
			);
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => $message,
					'time'    => gmdate( 'Y-m-d H:i:s \\U\\T\\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);
			wp_die(
				esc_html( $message ),
				esc_html__( 'SScribe activation failed', 'sscribe-export-site-pages' ),
				array( 'back_link' => true )
			);
		}

		try {
			if ( $network_wide && is_multisite() ) {
				self::activate_network_wide();
			} else {
				self::activate_single_site();
			}
		} catch ( \Throwable $exception ) {
			$reference = substr( hash( 'sha256', get_class( $exception ) . '|' . $exception->getMessage() ), 0, 12 );
			$message   = sprintf(
				/* translators: %s: diagnostic reference code. */
				__( 'Activation could not complete the required setup. Reinstall the plugin package or contact your site administrator. Reference: %s', 'sscribe-export-site-pages' ),
				$reference
			);
			set_transient(
				'sscribe_boot_error',
				array(
					'message' => $message,
					'time'    => gmdate( 'Y-m-d H:i:s \\U\\T\\C' ),
				),
				MINUTE_IN_SECONDS * 10
			);
			wp_die(
				esc_html( $message ),
				esc_html__( 'SScribe activation failed', 'sscribe-export-site-pages' ),
				array( 'back_link' => true )
			);
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
				try {
					self::activate_single_site();
				} finally {
					restore_current_blog();
				}
			}

			$offset += $number;
		}
	}

	/**
	 * Provision a newly created site while the plugin is network-active.
	 *
	 * @param object $new_site WordPress site object supplied by wp_initialize_site.
	 * @return void
	 */
	public function activate_new_site( object $new_site ): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			$plugin_functions = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $plugin_functions ) ) {
				require_once $plugin_functions;
			}
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( SSCRIBE_PLUGIN_BASENAME ) ) {
			return;
		}

		$site_data = get_object_vars( $new_site );
		$blog_id   = absint( $site_data['blog_id'] ?? 0 );
		if ( $blog_id <= 0 ) {
			return;
		}

		switch_to_blog( $blog_id );
		try {
			self::activate_single_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Activate plugin for the current site.
	 */
	private static function activate_single_site(): void {
		self::create_export_directory();
		self::create_database_tables();
		// register_settings() is wired to the admin_init hook in
		// sscribe-export-site-pages.php so the Settings API whitelist
		// is live on every admin request, not just on activation. The
		// option defaults declared inside register_settings() seed new
		// installs; existing installs keep their stored values.
		self::schedule_cleanup();
		self::cleanup_orphaned_data();
		self::grant_export_capability();
		update_option( 'sscribe_version', SSCRIBE_VERSION, false );
	}

	/**
	 * Create plugin database tables.
	 *
	 * @throws \RuntimeException When the WordPress upgrade helper is unavailable.
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
			session_id VARCHAR(60) DEFAULT NULL,
			user_id BIGINT UNSIGNED,
			request_id VARCHAR(12),
			memory_usage VARCHAR(20),
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_level (level),
			KEY idx_user_id (user_id),
			KEY idx_request_id (request_id),
			KEY idx_session_id (session_id)
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
			status VARCHAR(20) NOT NULL DEFAULT 'processing',
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
			throw new \RuntimeException( 'WordPress database upgrade functions are unavailable.' );
		}

		dbDelta( $sql_logs );
		dbDelta( $sql_stats );

		SScribe_Audit_Trail::create_table();
		update_option( 'sscribe_schema_version', SSCRIBE_VERSION, false );
	}

	/**
	 * Register WordPress settings via register_setting().
	 *
	 * Required for WordPress Plugin Review compliance and for the
	 * Settings API whitelist to actually include these option names.
	 * Wired to the admin_init hook from sscribe-export-site-pages.php
	 * so the registry is live on every admin request, not just on
	 * activation. Calling it only from the activation hook left the
	 * registry dead on every page load after activation, which is the
	 * settings-API dead-registry anti-pattern.
	 */
	public static function register_settings(): void {
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
				'sanitize_callback' => array( 'SScribe_Settings', 'sanitize_debug_log_level' ),
				'default'           => SScribe_Settings::LEVEL_DEBUG,
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
	 *
	 * Migration failure is logged and surfaced to the administrator via a
	 * transient notice, but never aborts activation. Legacy public artifacts
	 * stay in place so the operator can clean them up by hand when file
	 * permissions or ownership prevent the plugin from moving them.
	 *
	 * @throws \RuntimeException When no safe private storage directory is available.
	 */
	private static function create_export_directory(): void {
		$export_path = SScribe_Private_Storage::get_export_dir();
		if ( '' === $export_path ) {
			throw new \RuntimeException( 'SScribe could not create a safe private storage directory during activation.' );
		}
		if ( ! SScribe_Private_Storage::migrate_legacy_storage() ) {
			set_transient(
				'sscribe_migration_warning',
				array(
					'message' => sprintf(
						/* translators: %s: legacy directory name. */
						__( 'SScribe could not move every legacy public artifact into private storage. Review permissions on %s and the SScribe Diagnostics screen, then re-run Migration from the Tools menu.', 'sscribe-export-site-pages' ),
						'wp-content/uploads/sscribe-exports'
					),
					'time'    => gmdate( 'Y-m-d H:i:s \U\T\C' ),
				),
				DAY_IN_SECONDS
			);
		}
	}

	/**
	 * Schedule cleanup cron jobs.
	 */
	private static function schedule_cleanup(): void {
		$interval = apply_filters( 'sscribe_cleanup_interval', 'hourly' );
		if ( ! is_string( $interval ) || '' === $interval ) {
			$interval = 'hourly';
		}
		if ( function_exists( 'wp_get_schedules' ) && ! isset( wp_get_schedules()[ $interval ] ) ) {
			$interval = 'hourly';
		}
		if ( ! wp_next_scheduled( 'sscribe_cleanup_exports' ) ) {
			wp_schedule_event( time(), $interval, 'sscribe_cleanup_exports' );
		}

		if ( ! wp_next_scheduled( 'sscribe_cleanup_sessions' ) ) {
			wp_schedule_event( time(), $interval, 'sscribe_cleanup_sessions' );
		}

		$audit_interval = apply_filters( 'sscribe_audit_cleanup_interval', 'daily' );
		if ( ! is_string( $audit_interval ) || '' === $audit_interval ) {
			$audit_interval = 'daily';
		}
		if ( function_exists( 'wp_get_schedules' ) && ! isset( wp_get_schedules()[ $audit_interval ] ) ) {
			$audit_interval = 'daily';
		}
		if ( ! wp_next_scheduled( 'sscribe_cleanup_audit_trail' ) ) {
			wp_schedule_event( time(), $audit_interval, 'sscribe_cleanup_audit_trail' );
		}
	}

	/**
	 * Clean up orphaned transients and options.
	 */
	private static function cleanup_orphaned_data(): void {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( '_transient_sscribe_session_' ) . '%',
			$wpdb->esc_like( '_transient_sscribe_lock_' ) . '%',
			$wpdb->esc_like( '_transient_sscribe_rate_' ) . '%',
			$wpdb->esc_like( 'sscribe_export_lock_' ) . '%',
			$wpdb->esc_like( 'sscribe_rate_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_session_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_rate_' ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			self::delete_option_pattern_in_batches( $pattern );
		}
	}

	/**
	 * Delete matching options in bounded, cross-database batches.
	 *
	 * MySQL rejects LIMIT inside an IN/ALL/ANY/SOME subquery, while SQLite
	 * does not consistently support DELETE ... LIMIT. Selecting the option
	 * IDs first and deleting that bounded ID set keeps activation cleanup
	 * portable across both database engines without an unbounded delete.
	 *
	 * @param string $pattern Escaped SQL LIKE pattern.
	 */
	private static function delete_option_pattern_in_batches( string $pattern ): void {
		global $wpdb;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded activation cleanup against the options table.
			$option_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",
					$pattern
				)
			);

			$option_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', is_array( $option_ids ) ? $option_ids : array() )
					)
				)
			);

			if ( empty( $option_ids ) ) {
				break;
			}

			$rows = 0;
			foreach ( $option_ids as $option_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded activation cleanup by canonical option_id; wpdb::delete() prepares the integer predicate.
				$deleted = $wpdb->delete(
					$wpdb->options,
					array( 'option_id' => $option_id ),
					array( '%d' )
				);

				if ( false === $deleted ) {
					$rows = false;
					break;
				}

				$rows += (int) $deleted;
			}
		} while ( false !== $rows && $rows > 0 );
	}

	/**
	 * Grant the sscribe_export and sscribe_health capabilities to the
	 * Administrator role.
	 *
	 * The two capabilities are deliberately separate: an editor who
	 * has been granted `sscribe_export` to run exports should NOT
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
