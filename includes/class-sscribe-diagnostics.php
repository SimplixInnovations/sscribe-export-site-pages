<?php
/**
 * Comprehensive diagnostics and self-healing for SScribe.
 *
 * Provides preflight checks, detailed error diagnostics,
 * and automatic recovery mechanisms.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostics and self-healing system for SScribe.
 */
class SScribe_Diagnostics {

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Build support/debug information for administrators.
	 *
	 * @return array<string, mixed>
	 */
	public function get_support_info(): array {
		$upload_dir    = wp_upload_dir();
		$export_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports';
		$log_dir       = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs';
		$debug_enabled = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;

		$export_stats = new SScribe_Export_Stats();
		$collector    = new SScribe_Page_Collector();
		$session      = new SScribe_Session();
		$audit_trail  = new SScribe_Audit_Trail();

		$monthly_stats     = $export_stats->get_stats( 'month' );
		$status_counts     = $collector->get_post_status_counts( '' );
		$session_check     = $this->check_session_health();
		$recent_audit_logs = $audit_trail->get_logs( array(), 5, 0 );
		$support_logger    = new SScribe_Logger( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$logger_entries    = $support_logger->get_logs();
		$recent_log_tail   = array_slice( $logger_entries, -5 );

		$sections = array(
			'plugin'      => array(
				'label' => __( 'Plugin', 'sscribe-export-site-pages' ),
				'items' => array(
					'plugin_version' => SSCRIBE_VERSION,
					'debug_mode'     => $debug_enabled ? __( 'Enabled', 'sscribe-export-site-pages' ) : __( 'Disabled', 'sscribe-export-site-pages' ),
					'wpml_active'    => $collector->is_wpml_active() ? __( 'Yes', 'sscribe-export-site-pages' ) : __( 'No', 'sscribe-export-site-pages' ),
					'seo_plugins'    => implode( ', ', $this->get_active_seo_plugins() ),
				),
			),
			'environment' => array(
				'label' => __( 'Environment', 'sscribe-export-site-pages' ),
				'items' => array(
					'wordpress_version'  => get_bloginfo( 'version' ),
					'php_version'        => PHP_VERSION,
					'locale'             => get_locale(),
					'memory_limit'       => (string) ini_get( 'memory_limit' ),
					'max_execution_time' => (string) ini_get( 'max_execution_time' ),
					'zip_extension'      => class_exists( 'ZipArchive' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
					'dompdf'             => class_exists( '\\SScribeVendor\\Dompdf\\Dompdf' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
					'phpword'            => class_exists( '\\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				),
			),
			'paths'       => array(
				'label' => __( 'Paths', 'sscribe-export-site-pages' ),
				'items' => array(
					'upload_base' => $upload_dir['basedir'] ?? '',
					'export_dir'  => $export_dir,
					'log_dir'     => $log_dir,
				),
			),
			'stats'       => array(
				'label' => __( 'Usage', 'sscribe-export-site-pages' ),
				'items' => array(
					'total_pages'        => (string) ( $status_counts['all'] ?? 0 ),
					'published_pages'    => (string) ( $status_counts['publish'] ?? 0 ),
					'exports_this_month' => (string) ( $monthly_stats['total_exports'] ?? 0 ),
					'failed_this_month'  => (string) ( $monthly_stats['failed_exports'] ?? 0 ),
					'avg_duration'       => isset( $monthly_stats['avg_duration'] ) ? round( (float) $monthly_stats['avg_duration'], 2 ) . 's' : '0s',
					'total_size'         => isset( $monthly_stats['total_size_mb'] ) ? round( (float) $monthly_stats['total_size_mb'], 2 ) . ' MB' : '0 MB',
				),
			),
			'health'      => array(
				'label' => __( 'Health', 'sscribe-export-site-pages' ),
				'items' => array(
					'session_health'   => $session_check['message'] ?? '',
					'upload_writable'  => $this->check_upload_directory()['message'] ?? '',
					'file_permissions' => $this->check_file_permissions()['message'] ?? '',
					'wp_cron'          => $this->check_wp_cron()['message'] ?? '',
				),
			),
		);

		$recent_audit_summary = array_map(
			static function ( object $entry ): array {
				return array(
					'timestamp' => (string) ( $entry->timestamp ?? '' ),
					'event'     => (string) ( $entry->event ?? '' ),
					'user_id'   => isset( $entry->user_id ) ? (int) $entry->user_id : 0,
				);
			},
			$recent_audit_logs
		);

		return array(
			'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
			'sections'       => $sections,
			'audit_events'   => $recent_audit_summary,
			'log_tail'       => array_values( $recent_log_tail ),
			'copy_text'      => $this->build_support_copy_text( $sections, $recent_audit_summary, $recent_log_tail ),
			'has_debug_mode' => $debug_enabled,
			'storage'        => array(
				'session_storage' => $session->get_storage_type(),
			),
		);
	}

	/**
	 * Run comprehensive preflight checks before export.
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export formats selected.
	 * @return array Diagnostic results with status, checks, and recommendations.
	 */
	public function run_preflight( int $page_count, array $formats ): array {
		$checks    = array();
		$has_error = false;
		$warnings  = array();

		$checks['php_version'] = $this->check_php_version();
		$checks['memory']      = $this->check_memory( $page_count, $formats );
		$checks['execution']   = $this->check_execution_time( $page_count );
		$checks['upload_dir']  = $this->check_upload_directory();
		$checks['zip']         = $this->check_zip_extension();
		$checks['dompdf']      = $this->check_dompdf();
		$checks['phpword']     = $this->check_phpword();
		$checks['permissions'] = $this->check_file_permissions();
		$checks['wp_cron']     = $this->check_wp_cron();
		$checks['session']     = $this->check_session_health();

		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$has_error = true;
			}
			if ( 'warning' === $check['status'] ) {
				$warnings[] = $check['message'];
			}
		}

		$recommendations = $this->get_recommendations( $checks, $page_count );

		return array(
			'status'          => $has_error ? 'error' : ( ! empty( $warnings ) ? 'warning' : 'ok' ),
			'checks'          => $checks,
			'recommendations' => $recommendations,
			'can_proceed'     => ! $has_error,
		);
	}

	/**
	 * Check required vendor dependencies at runtime.
	 *
	 * @return array<int, string> Missing dependency class labels.
	 */
	public function check_vendor_dependencies(): array {
		$missing = array();

		if ( ! class_exists( '\SScribeVendor\Dompdf\Dompdf' ) && ! class_exists( '\SScribeVendor\Dompdf\Dompdf' ) ) {
			$missing[] = 'SScribeVendor\\Dompdf\\Dompdf';
		}

		if ( ! class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) && ! class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ) {
			$missing[] = 'SScribeVendor\\PhpOffice\\PhpWord\\PhpWord';
		}

		return $missing;
	}

	/**
	 * Check PHP version.
	 */
	private function check_php_version(): array {
		$current  = PHP_VERSION;
		$required = '8.2';

		if ( version_compare( $current, $required, '>=' ) ) {
			return array(
				'name'    => 'PHP Version',
				'status'  => 'ok',
				'message' => sprintf( 'PHP %s (minimum: %s)', $current, $required ),
			);
		}

		return array(
			'name'    => 'PHP Version',
			'status'  => 'error',
			'message' => sprintf( 'PHP %s detected. Minimum required: %s. Contact your hosting provider to upgrade.', $current, $required ),
			'fix'     => 'Upgrade PHP to 8.2 or higher',
		);
	}

	/**
	 * Check memory for estimated export size.
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export formats selected.
	 * @return array Check result with status, name, and message.
	 */
	private function check_memory( int $page_count, array $formats ): array {
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_mb    = round( $memory_limit / 1024 / 1024 );
		$used_mb      = round( memory_get_usage( true ) / 1024 / 1024 );
		$available_mb = $memory_mb - $used_mb;

		// Calculate estimated memory requirement based on formats.
		$estimate_per_page = 2; // Base 2MB for page data collection.
		if ( in_array( 'docx', $formats, true ) ) {
			$estimate_per_page += 3; // PHPWord overhead.
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$estimate_per_page += 5; // DomPDF overhead.
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$estimate_per_page += 0.5;
		}
		if ( in_array( 'html', $formats, true ) ) {
			// phpcs:ignore Squiz.Operators.IncrementDecrementUsage.Found -- Float increment, not integer.
			$estimate_per_page += 1;
		}

		// Total estimated memory for all pages (with 50MB overhead).
		$estimated_total_mb = ( $page_count * $estimate_per_page ) + 50;

		// Memory safety margin (80% of available).
		// Finding #9 fix: Cast to int to prevent floating point precision issues.
		$safe_available_mb = (int) ( $available_mb * 0.8 );

		if ( $memory_mb < 128 ) {
			return array(
				'name'    => 'Memory Limit',
				'status'  => 'error',
				'message' => sprintf( 'Memory limit: %dMB. Minimum required: 128MB. Increase memory_limit in php.ini.', $memory_mb ),
				'fix'     => 'Add define( "WP_MEMORY_LIMIT", "256M" ); to wp-config.php',
			);
		}

		// Check if the export will likely fail due to memory constraints.
		if ( $estimated_total_mb > $safe_available_mb ) {
			$recommended_memory = ceil( $estimated_total_mb / 256 ) * 256;

			if ( $estimated_total_mb > $available_mb ) {
				return array(
					'name'    => 'Memory Forecast',
					'status'  => 'error',
					'message' => sprintf(
						'Export requires ~%dMB but only %dMB available. Increase memory to %dMB+ or reduce page count.',
						$estimated_total_mb,
						$available_mb,
						$recommended_memory
					),
					'fix'     => sprintf( 'Add define( "WP_MEMORY_LIMIT", "%dM" ); to wp-config.php or export fewer pages.', $recommended_memory ),
				);
			}

			return array(
				'name'    => 'Memory Forecast',
				'status'  => 'warning',
				'message' => sprintf(
					'Export will use ~%dMB of %dMB available (%d%%). Consider increasing memory for safety.',
					$estimated_total_mb,
					$available_mb,
					round( ( $estimated_total_mb / $available_mb ) * 100 )
				),
				'fix'     => 'Increase memory_limit to provide more headroom for large exports',
			);
		}

		if ( $available_mb < 50 ) {
			return array(
				'name'    => 'Available Memory',
				'status'  => 'warning',
				'message' => sprintf( 'Only %dMB available (%dMB used of %dMB limit). Large exports may fail.', $available_mb, $used_mb, $memory_mb ),
				'fix'     => 'Increase memory_limit or reduce batch size',
			);
		}

		return array(
			'name'    => 'Memory',
			'status'  => 'ok',
			'message' => sprintf( '%dMB available (limit: %dMB, used: %dMB, estimated need: %dMB)', $available_mb, $memory_mb, $used_mb, $estimated_total_mb ),
		);
	}

	/**
	 * Check execution time limits.
	 *
	 * @param int $page_count Number of pages to export.
	 * @return array Check result with status, name, and message.
	 */
	private function check_execution_time( int $page_count ): array {
		$max_execution = (int) ini_get( 'max_execution_time' );

		$estimated_seconds = $page_count * 2;

		if ( $max_execution > 0 && $max_execution < 30 ) {
			return array(
				'name'    => 'Execution Time',
				'status'  => 'warning',
				'message' => sprintf( 'max_execution_time: %ds. Short timeout may interrupt large exports.', $max_execution ),
				'fix'     => 'Increase max_execution_time or rely on batch processing',
			);
		}

		return array(
			'name'    => 'Execution Time',
			'status'  => 'ok',
			'message' => $max_execution > 0
				? sprintf( 'max_execution_time: %ds (estimated need: %ds)', $max_execution, $estimated_seconds )
				: 'max_execution_time: unlimited',
		);
	}

	/**
	 * Check upload directory is writable.
	 */
	private function check_upload_directory(): array {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return array(
				'name'    => 'Upload Directory',
				'status'  => 'error',
				'message' => 'Upload directory error: ' . $upload_dir['error'],
				'fix'     => 'Check wp-content/uploads directory permissions',
			);
		}

		$export_dir = $upload_dir['basedir'] . '/sscribe-exports';

		if ( ! is_dir( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		if ( ! wp_is_writable( $export_dir ) ) {
			return array(
				'name'    => 'Upload Directory',
				'status'  => 'error',
				'message' => 'Export directory is not writable: ' . $export_dir,
				'fix'     => 'Set directory permissions to 755 or 775',
			);
		}

		$free_space = disk_free_space( $export_dir );
		$free_mb    = round( $free_space / 1024 / 1024 );

		if ( $free_mb < 100 ) {
			return array(
				'name'    => 'Disk Space',
				'status'  => 'warning',
				'message' => sprintf( 'Only %dMB free disk space. Large exports may fail.', $free_mb ),
				'fix'     => 'Free up disk space on the server',
			);
		}

		return array(
			'name'    => 'Upload Directory',
			'status'  => 'ok',
			'message' => sprintf( 'Writable. Free space: %dMB', $free_mb ),
		);
	}

	/**
	 * Check ZipArchive extension.
	 */
	private function check_zip_extension(): array {
		if ( class_exists( 'ZipArchive' ) ) {
			return array(
				'name'    => 'ZIP Extension',
				'status'  => 'ok',
				'message' => 'ZipArchive extension loaded',
			);
		}

		return array(
			'name'    => 'ZIP Extension',
			'status'  => 'error',
			'message' => 'ZipArchive PHP extension is required for DOCX and ZIP exports.',
			'fix'     => 'Enable the zip extension in php.ini or contact hosting provider',
		);
	}

	/**
	 * Check DomPDF library.
	 */
	private function check_dompdf(): array {
		if ( class_exists( '\\SScribeVendor\\Dompdf\\Dompdf' ) ) {
			return array(
				'name'    => 'DomPDF Library',
				'status'  => 'ok',
				'message' => 'DomPDF loaded',
			);
		}

		return array(
			'name'    => 'DomPDF Library',
			'status'  => 'error',
			'message' => 'DomPDF library not found. Run: composer install',
			'fix'     => 'Run composer install in the plugin directory',
		);
	}

	/**
	 * Check PHPWord library.
	 */
	private function check_phpword(): array {
		if ( class_exists( '\\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord' ) ) {
			return array(
				'name'    => 'PHPWord Library',
				'status'  => 'ok',
				'message' => 'PHPWord loaded',
			);
		}

		return array(
			'name'    => 'PHPWord Library',
			'status'  => 'error',
			'message' => 'PHPWord library not found. Run: composer install',
			'fix'     => 'Run composer install in the plugin directory',
		);
	}

	/**
	 * Check file permissions.
	 */
	private function check_file_permissions(): array {
		$plugin_dir = SSCRIBE_PLUGIN_DIR;
		$issues     = array();

		$css_file = $plugin_dir . 'admin/css/sscribe-admin.css';
		if ( ! is_readable( $css_file ) ) {
			$issues[] = 'CSS file not readable';
		}

		$js_file = $plugin_dir . 'admin/js/sscribe-admin.js';
		if ( ! is_readable( $js_file ) ) {
			$issues[] = 'JS file not readable';
		}

		if ( ! empty( $issues ) ) {
			return array(
				'name'    => 'File Permissions',
				'status'  => 'error',
				'message' => implode( ', ', $issues ),
				'fix'     => 'Set correct file permissions (644 for files, 755 for directories)',
			);
		}

		return array(
			'name'    => 'File Permissions',
			'status'  => 'ok',
			'message' => 'All plugin files are readable',
		);
	}

	/**
	 * Check WP-Cron status.
	 */
	private function check_wp_cron(): array {
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' );

		if ( $cron_disabled ) {
			return array(
				'name'    => 'WP-Cron',
				'status'  => 'warning',
				'message' => 'WP-Cron is disabled. Scheduled cleanup may not run.',
				'fix'     => 'Set up a server cron job to run wp-cron.php',
			);
		}

		return array(
			'name'    => 'WP-Cron',
			'status'  => 'ok',
			'message' => 'WP-Cron is active',
		);
	}

	/**
	 * Check for orphaned sessions.
	 */
	private function check_session_health(): array {
		global $wpdb;

		$option_prefix = 'sscribe_session_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphaned = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
				$wpdb->esc_like( $option_prefix ) . '%',
				'%"processing"%'
			)
		);

		if ( $orphaned > 0 ) {
			return array(
				'name'    => 'Session Health',
				'status'  => 'warning',
				'message' => sprintf( '%d orphaned session(s) found from previous incomplete exports.', $orphaned ),
				'fix'     => 'Sessions will auto-clear on next export attempt',
			);
		}

		return array(
			'name'    => 'Session Health',
			'status'  => 'ok',
			'message' => 'No orphaned sessions',
		);
	}

	/**
	 * Get recommendations based on checks.
	 *
	 * @param array $checks     Check results.
	 * @param int   $page_count Number of pages to export.
	 * @return array Recommendations with priority and message.
	 */
	private function get_recommendations( array $checks, int $page_count ): array {
		$recommendations = array();

		if ( $page_count > 100 ) {
			$recommendations[] = array(
				'priority' => 'high',
				'message'  => 'Exporting ' . $page_count . ' pages. Recommended memory: 512MB+. This will take several minutes.',
			);
		}

		if ( 'ok' !== $checks['memory']['status'] ) {
			$recommendations[] = array(
				'priority' => 'high',
				'message'  => 'Increase PHP memory_limit to prevent export failures.',
			);
		}

		if ( 'warning' === $checks['wp_cron']['status'] ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'message'  => 'Consider setting up a server-side cron for WP-Cron.',
			);
		}

		return $recommendations;
	}

	/**
	 * Get detailed error information for a failed page.
	 *
	 * @param int    $page_id   Page ID.
	 * @param string $format    Export format.
	 * @param string $error     Error message.
	 * @param array  $context   Additional context (reserved for future use).
	 * @return array Detailed error with fix suggestions.
	 */
	public function diagnose_page_error( int $page_id, string $format, string $error, array $context = array() ): array {
		$diagnosis = array(
			'page_id'   => $page_id,
			'format'    => $format,
			'error'     => $error,
			'category'  => $this->categorize_error( $error ),
			'severity'  => 'error',
			'fix'       => array(),
			'technical' => array(),
			'context'   => $context,
		);

		$lower_error = strtolower( $error );

		if ( str_contains( $lower_error, 'memory' ) || str_contains( $lower_error, 'allowed memory' ) ) {
			$diagnosis['category']  = 'memory_exhausted';
			$diagnosis['fix']       = array(
				'Add define( "WP_MEMORY_LIMIT", "512M" ); to wp-config.php',
				'Reduce export batch size',
				'Export fewer formats at once',
			);
			$diagnosis['technical'] = array(
				'current_usage' => size_format( memory_get_usage( true ) ),
				'peak_usage'    => size_format( memory_get_peak_usage( true ) ),
				'memory_limit'  => ini_get( 'memory_limit' ),
			);
		} elseif ( str_contains( $lower_error, 'timeout' ) || str_contains( $lower_error, 'time limit' ) ) {
			$diagnosis['category'] = 'timeout';
			$diagnosis['fix']      = array(
				'Increase max_execution_time in php.ini',
				'Reduce page content complexity',
				'Export in smaller batches',
			);
		} elseif ( str_contains( $lower_error, 'zip' ) || str_contains( $lower_error, 'ziparchive' ) ) {
			$diagnosis['category'] = 'zip_extension';
			$diagnosis['fix']      = array(
				'Enable the zip extension in php.ini',
				'Contact hosting provider to enable ZipArchive',
			);
		} elseif ( str_contains( $lower_error, 'permission' ) || str_contains( $lower_error, 'writable' ) ) {
			$diagnosis['category'] = 'permissions';
			$diagnosis['fix']      = array(
				'Set wp-content/uploads permissions to 755',
				'Check that the PHP process can write to the uploads directory',
			);
		} elseif ( str_contains( $lower_error, 'dompdf' ) || str_contains( $lower_error, 'pdf' ) ) {
			$diagnosis['category'] = 'pdf_generation';
			$diagnosis['fix']      = array(
				'Run composer install to ensure DomPDF is installed',
				'Check that the page content does not contain invalid HTML',
			);
		} elseif ( str_contains( $lower_error, 'phpword' ) || str_contains( $lower_error, 'docx' ) ) {
			$diagnosis['category'] = 'docx_generation';
			$diagnosis['fix']      = array(
				'Run composer install to ensure PHPWord is installed',
				'Check page content for complex elements that may not convert well',
			);
		}

		return $diagnosis;
	}

	/**
	 * Categorize an error message.
	 *
	 * @param string $error Error message to categorize.
	 * @return string Category name (memory, timeout, permission, etc.).
	 */
	private function categorize_error( string $error ): string {
		$lower = strtolower( $error );

		$categories = array(
			'memory'     => array( 'memory', 'allocated', 'exhausted' ),
			'timeout'    => array( 'timeout', 'time limit', 'execution' ),
			'permission' => array( 'permission', 'writable', 'denied' ),
			'zip'        => array( 'zip', 'ziparchive', 'archive' ),
			'pdf'        => array( 'dompdf', 'pdf' ),
			'docx'       => array( 'phpword', 'docx', 'word' ),
			'network'    => array( 'network', 'connection', 'ajax' ),
			'session'    => array( 'session', 'expired', 'not found' ),
		);

		foreach ( $categories as $category => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $lower, $keyword ) ) {
					return $category;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Attempt self-healing by cleaning up orphaned data.
	 *
	 * @return array Actions taken.
	 */
	public function self_heal(): array {
		$actions = array();

		$actions['cleared_locks']    = $this->clear_orphaned_locks();
		$actions['cleared_sessions'] = $this->clear_stale_sessions();
		$actions['cleared_temp']     = $this->clear_old_temp_files();

		$this->logger->debug( 'Self-healing completed', $actions );

		return $actions;
	}

	/**
	 * Clear orphaned lock transients.
	 */
	private function clear_orphaned_locks(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'sscribe_lock_' ) . '%'
			)
		);

		$cleared = 0;
		foreach ( $locks as $lock ) {
			$transient = str_replace( '_transient_', '', $lock->option_name );
			$value     = get_transient( $transient );

			if ( $value ) {
				$parts     = explode( '|', $value );
				$lock_time = (int) $parts[0];

				if ( time() - $lock_time > 60 ) {
					delete_transient( $transient );
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Clear stale sessions.
	 */
	private function clear_stale_sessions(): int {
		global $wpdb;

		$option_prefix = 'sscribe_session_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);

		$cleared = 0;
		foreach ( $sessions as $session ) {
			$data = json_decode( $session->option_value, true );

			// SECURITY: Skip legacy PHP-serialized sessions — do not use maybe_unserialize().
			// Those sessions will be cleaned up by SScribe_Session::cleanup_expired() instead.

			// Finding #2 fix: Validate session schema before use.
			if ( is_array( $data ) && isset( $data['created_at'] ) ) {
				$created_at = $data['created_at'];
				$created    = is_numeric( $created_at ) ? (int) $created_at : strtotime( (string) $created_at );
				if ( $created && time() - $created > 3600 ) {
					delete_option( $session->option_name );
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Clear old temporary export files.
	 */
	private function clear_old_temp_files(): int {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/sscribe-exports';

		if ( ! is_dir( $export_dir ) ) {
			return 0;
		}

		$cleared = 0;
		$files   = scandir( $export_dir );

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$full_path = $export_dir . '/' . $file;
			$mtime     = filemtime( $full_path );

			if ( $mtime && time() - $mtime > 86400 ) {
				if ( is_dir( $full_path ) ) {
					$this->delete_directory( $full_path );
				} else {
					wp_delete_file( $full_path );
				}
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * Uses SScribe_Security for proper symlink handling.
	 *
	 * @param string $dir Directory path to delete.
	 */
	private function delete_directory( string $dir ): void {
		SScribe_Security::delete_directory( $dir );
	}

	/**
	 * Get active SEO plugin names for diagnostics.
	 *
	 * @return array<int, string>
	 */
	private function get_active_seo_plugins(): array {
		$reader  = new SScribe_SEO_Reader();
		$plugins = $reader->get_active_seo_plugins();

		if ( empty( $plugins ) ) {
			return array( __( 'None detected', 'sscribe-export-site-pages' ) );
		}

		return array_values( $plugins );
	}

	/**
	 * Build copy-friendly support text.
	 *
	 * @param array<string, array{label: string, items: array<string, string>}> $sections Sections to render.
	 * @param array<int, array<string, string|int>>                             $audit_events Recent audit events.
	 * @param array<int, string>                                                $log_tail Recent log entries.
	 * @return string
	 */
	private function build_support_copy_text( array $sections, array $audit_events, array $log_tail ): string {
		$lines   = array();
		$lines[] = 'SScribe Support Information';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		foreach ( $sections as $section ) {
			$lines[] = '';
			$lines[] = '[' . $section['label'] . ']';
			foreach ( $section['items'] as $key => $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}

		if ( ! empty( $audit_events ) ) {
			$lines[] = '';
			$lines[] = '[Recent Audit Events]';
			foreach ( $audit_events as $event ) {
				$lines[] = sprintf(
					'%s | %s | user:%d',
					(string) ( $event['timestamp'] ?? '' ),
					(string) ( $event['event'] ?? '' ),
					(int) ( $event['user_id'] ?? 0 )
				);
			}
		}

		if ( ! empty( $log_tail ) ) {
			$lines[] = '';
			$lines[] = '[Recent Log Tail]';
			foreach ( $log_tail as $entry ) {
				$lines[] = $entry;
			}
		}

		return implode( PHP_EOL, $lines );
	}
}
