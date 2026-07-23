<?php
/**
 * SScribe Diagnostics
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging gated behind WP_DEBUG_LOG.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System diagnostics, preflight checks, and error categorization.
 */
class SScribe_Diagnostics {

	/**
	 * Minimum expected mPDF font files in the shipped vendor tree.
	 *
	 * The plugin ships 17 font files in
	 * vendor-prefixed/mpdf/mpdf/ttfonts/ (16 TTF + DejaVuinfo.txt). A lower
	 * bound of 15 leaves room for one or two expected file additions without
	 * firing false-positive warnings on healthy installs. Values higher than
	 * the shipped count produce a "warning" on every install, which is the
	 * bug this constant was guarding against.
	 */
	private const MIN_MPDF_FONT_COUNT = 15;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Track errors encountered during support info collection.
	 *
	 * @var array<string>
	 */
	private array $support_errors = array();

	/**
	 * Initialize diagnostics.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}

	/**
	 * Get site + plugin support snapshot for the diagnostics export.
	 *
	 * @return array
	 */
	public function get_support_info(): array {
		$upload_dir    = wp_upload_dir();
		$export_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports';
		$log_dir       = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs';
		$debug_enabled = SSCRIBE_DEBUG;

		$container    = SScribe_Container::instance();
		$debug_logger = null;

		if ( $debug_enabled ) {
			try {
				$debug_logger = $container->get( SScribe_Logger::class );
			} catch ( \Throwable $e ) {
				$debug_logger = null;
			}
		}

		$sections = array();

		try {
			$export_stats  = new SScribe_Export_Stats();
			$monthly_stats = $export_stats->get_stats( 'month' );
		} catch ( \Throwable $e ) {
			$monthly_stats = array();

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: monthly_stats failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: monthly_stats unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'monthly_stats: ' . $e->getMessage();
		}

		try {
			$collector     = $container->get( SScribe_Page_Collector::class );
			$status_counts = $collector->get_post_status_counts( '' );
		} catch ( \Throwable $e ) {
			$status_counts = array();
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: status_counts failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: status_counts unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'status_counts: ' . $e->getMessage();
		}

		try {
			$session_check = $this->check_session_health();
		} catch ( \Throwable $e ) {
			$session_check = array( 'message' => __( 'Unavailable', 'sscribe-export-site-pages' ) );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: session_check failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: session_check unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'session_check: ' . $e->getMessage();
		}

		try {
			$audit_trail       = new SScribe_Audit_Trail();
			$recent_audit_logs = $audit_trail->get_logs( array(), 5, 0 );
		} catch ( \Throwable $e ) {
			$recent_audit_logs = array();
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: recent_audit_logs failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: recent_audit_logs unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'recent_audit_logs: ' . $e->getMessage();
		}

		try {
			$logger_entries = null !== $debug_logger ? $debug_logger->get_logs( 5 ) : array();
		} catch ( \Throwable $e ) {
			$logger_entries = array();
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: logger_entries failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: logger_entries unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'logger_entries: ' . $e->getMessage();
		}

		$recent_log_tail = $logger_entries;

		try {
			$wpml_active = $container->get( SScribe_Page_Collector::class )->is_wpml_active();
		} catch ( \Throwable $e ) {
			$wpml_active = false;
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: wpml_active failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: wpml_active check failed', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'wpml_active: ' . $e->getMessage();
		}

		try {
			$seo_plugins = implode( ', ', $this->get_active_seo_plugins() );
		} catch ( \Throwable $e ) {
			$seo_plugins = __( 'Unavailable', 'sscribe-export-site-pages' );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: seo_plugins failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: seo_plugins unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'seo_plugins: ' . $e->getMessage();
		}

		try {
			$session         = $container->get( SScribe_Session::class );
			$session_storage = $session->get_storage_type();
		} catch ( \Throwable $e ) {
			$session_storage = 'unknown';
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: session_storage failed : ' . $e->getMessage() );
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: session_storage unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'session_storage: ' . $e->getMessage();
		}

		$sections['plugin'] = array(
			'label' => __( 'Plugin', 'sscribe-export-site-pages' ),
			'items' => array(
				'plugin_version' => SSCRIBE_VERSION,
				'debug_mode'     => $debug_enabled ? __( 'Enabled', 'sscribe-export-site-pages' ) : __( 'Disabled', 'sscribe-export-site-pages' ),
				'wpml_active'    => $wpml_active ? __( 'Yes', 'sscribe-export-site-pages' ) : __( 'No', 'sscribe-export-site-pages' ),
				'seo_plugins'    => $seo_plugins,
			),
		);

		$sections['environment'] = array(
			'label' => __( 'Environment', 'sscribe-export-site-pages' ),
			'span'  => 'full',
			'items' => array(
				'wordpress_version'  => get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'locale'             => get_locale(),
				'memory_limit'       => (string) ini_get( 'memory_limit' ),
				'max_execution_time' => (string) ini_get( 'max_execution_time' ),
				'zip_extension'      => class_exists( 'ZipArchive' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'dom_extension'     => class_exists( 'DOMDocument' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'mbstring'           => function_exists( 'mb_convert_encoding' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'mpdf'               => class_exists( '\SScribeVendor\Mpdf\Mpdf' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'phpword'            => class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
			),
		);

		$sections['paths'] = array(
			'label' => __( 'Paths', 'sscribe-export-site-pages' ),
			'items' => array(
				'upload_base' => defined( 'ABSPATH' )
					? str_replace( trailingslashit( ABSPATH ), '[ABSPATH]/', $upload_dir['basedir'] )
					: basename( $upload_dir['basedir'] ),
				'export_dir'  => '[uploads]/sscribe-exports',
				'log_dir'     => '[uploads]/sscribe-logs',
				'writable'    => wp_is_writable( $export_dir ) ? __( 'Yes', 'sscribe-export-site-pages' ) : __( 'No', 'sscribe-export-site-pages' ),
			),
		);

		$sections['stats'] = array(
			'label' => __( 'Usage', 'sscribe-export-site-pages' ),
			'items' => array(
				'total_pages'        => (string) ( $status_counts['all'] ?? 0 ),
				'published_pages'    => (string) ( $status_counts['publish'] ?? 0 ),
				'exports_this_month' => (string) ( $monthly_stats['total_exports'] ?? 0 ),
				'failed_this_month'  => (string) ( $monthly_stats['failed_exports'] ?? 0 ),
				'avg_duration'       => isset( $monthly_stats['avg_duration'] ) ? round( (float) $monthly_stats['avg_duration'], 2 ) . 's' : '0s',
				'total_size'         => isset( $monthly_stats['total_size_mb'] ) ? round( (float) $monthly_stats['total_size_mb'], 2 ) . ' MB' : '0 MB',
			),
		);

		try {
			$upload_writable  = $this->check_upload_directory();
			$file_permissions = $this->check_file_permissions();
			$wp_cron_check    = $this->check_wp_cron();
		} catch ( \Throwable $e ) {
			$upload_writable  = array( 'message' => __( 'Unavailable', 'sscribe-export-site-pages' ) );
			$file_permissions = array( 'message' => __( 'Unavailable', 'sscribe-export-site-pages' ) );
			$wp_cron_check    = array( 'message' => __( 'Unavailable', 'sscribe-export-site-pages' ) );
		}

		$sections['health'] = array(
			'label' => __( 'Health', 'sscribe-export-site-pages' ),
			'items' => array(
				'session_health'   => $session_check['message'] ?? __( 'Unknown', 'sscribe-export-site-pages' ),
				'upload_writable'  => $upload_writable['message'] ?? __( 'Unknown', 'sscribe-export-site-pages' ),
				'file_permissions' => $file_permissions['message'] ?? __( 'Unknown', 'sscribe-export-site-pages' ),
				'wp_cron'          => $wp_cron_check['message'] ?? __( 'Unknown', 'sscribe-export-site-pages' ),
			),
		);

		$recent_audit_summary = array();
		if ( count( $recent_audit_logs ) > 0 ) {
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
		}

		try {
			$copy_text = $this->build_support_copy_text( $sections, $recent_audit_summary, $recent_log_tail );
		} catch ( \Throwable $e ) {
			$copy_text = '';
		}

		$ordered_sections = array();
		foreach ( array( 'plugin', 'paths', 'stats', 'health', 'environment' ) as $section_key ) {
			if ( isset( $sections[ $section_key ] ) ) {
				$ordered_sections[ $section_key ] = $sections[ $section_key ];
			}
		}
		foreach ( $sections as $section_key => $section_data ) {
			if ( ! isset( $ordered_sections[ $section_key ] ) ) {
				$ordered_sections[ $section_key ] = $section_data;
			}
		}
		$sections = $ordered_sections;

		return array(
			'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
			'sections'       => $sections,
			'audit_events'   => $recent_audit_summary,
			'log_tail'       => $recent_log_tail,
			'copy_text'      => $copy_text,
			'has_debug_mode' => $debug_enabled,
			'storage'        => array(
				'session_storage' => $session_storage,
			),
			'errors'         => array_values( $this->support_errors ),
		);
	}

	/**
	 * Run preflight checks before export.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array
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
		$checks['mpdf']        = $this->check_mpdf();
		$checks['phpword']     = $this->check_phpword();
		$checks['permissions'] = $this->check_file_permissions();
		$checks['wp_cron']     = $this->check_wp_cron();
		$checks['session']     = $this->check_session_health();

		foreach ( $checks as $check ) {
			if ( isset( $check['status'] ) && 'error' === $check['status'] ) {
				$has_error = true;
			}
			if ( isset( $check['status'] ) && 'warning' === $check['status'] ) {
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
	 * Check if vendor dependencies are loaded.
	 *
	 * @return array
	 */
	public function check_vendor_dependencies(): array {
		$missing = array();

		if ( ! class_exists( '\SScribeVendor\Mpdf\Mpdf' ) ) {
			$missing[] = 'SScribeVendor\Mpdf\Mpdf';
		}

		if ( ! class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ) {
			$missing[] = 'SScribeVendor\PhpOffice\PhpWord\PhpWord';
		}

		return $missing;
	}

	/**
	 * Check PHP version meets minimum requirement.
	 *
	 * @return array
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
	 * Check memory availability for export.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array
	 */
	private function check_memory( int $page_count, array $formats ): array {
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_mb    = round( $memory_limit / 1024 / 1024 );
		$used_mb      = round( memory_get_usage( true ) / 1024 / 1024 );
		$available_mb = $memory_mb - $used_mb;

		$estimate_per_page = 2;
		if ( in_array( 'docx', $formats, true ) ) {
			$estimate_per_page += 3;
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$estimate_per_page += 3;
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$estimate_per_page += 0.5;
		}
		if ( in_array( 'html', $formats, true ) ) {
			// phpcs:ignore Squiz.Operators.IncrementDecrementUsage.Found -- Float increment, not integer.
			$estimate_per_page += 1;
		}

		$estimated_total_mb = ( $page_count * $estimate_per_page ) + 50;

		if ( $available_mb <= 0 ) {
			return array(
				'name'    => 'Memory Forecast',
				'status'  => 'error',
				'message' => sprintf(
					'No memory available for export (limit: %dMB, used: %dMB). Increase memory_limit in php.ini.',
					$memory_mb,
					$used_mb
				),
				'fix'     => 'Add define( "WP_MEMORY_LIMIT", "256M" ); to wp-config.php',
			);
		}

		$safe_available_mb = (int) ( $available_mb * 0.8 );

		if ( $memory_mb < 128 ) {
			return array(
				'name'    => 'Memory Limit',
				'status'  => 'error',
				'message' => sprintf( 'Memory limit: %dMB. Minimum required: 128MB. Increase memory_limit in php.ini.', $memory_mb ),
				'fix'     => 'Add define( "WP_MEMORY_LIMIT", "256M" ); to wp-config.php',
			);
		}

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

			$percent = round( ( $estimated_total_mb / $available_mb ) * 100 );

			return array(
				'name'    => 'Memory Forecast',
				'status'  => 'warning',
				'message' => sprintf(
					'Export will use ~%dMB of %dMB available (%d%%). Consider increasing memory for safety.',
					$estimated_total_mb,
					$available_mb,
					$percent
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
	 * Check execution time availability.
	 *
	 * @param int $page_count Number of pages.
	 * @return array
	 */
	private function check_execution_time( int $page_count ): array {
		$max_execution = (int) ini_get( 'max_execution_time' );

		$estimated_seconds = $page_count * 2;

		if ( $max_execution > 0 && ( $max_execution < 30 || $estimated_seconds > $max_execution ) ) {
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
				: sprintf( 'max_execution_time: unlimited (estimated need: %ds)', $estimated_seconds ),
		);
	}

	/**
	 * Check upload directory writability.
	 *
	 * @return array
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
			if ( ! wp_mkdir_p( $export_dir ) ) {
				return array(
					'name'    => 'Upload Directory',
					'status'  => 'error',
					'message' => 'Export directory could not be created: ' . $export_dir,
					'fix'     => 'Check that wp-content/uploads is writable (chmod 755)',
				);
			}

			SScribe_Security::protect_directory( $export_dir );
		}

		if ( ! wp_is_writable( $export_dir ) ) {
			return array(
				'name'    => 'Upload Directory',
				'status'  => 'error',
				'message' => 'Export directory is not writable: ' . $export_dir,
				'fix'     => 'Set directory permissions to 755 or 775',
			);
		}

		if ( ! function_exists( 'disk_free_space' ) ) {
			return array(
				'name'    => 'Disk Space',
				'status'  => 'ok',
				'message' => 'Disk space check is not available on this server configuration (S3, NFS, or restricted hosting).',
			);
		}

		$free_space = disk_free_space( $export_dir );
		if ( false === $free_space ) {
			return array(
				'name'    => 'Disk Space',
				'status'  => 'ok',
				'message' => 'Disk space check not available on this server configuration.',
			);
		}
		$free_mb = round( $free_space / 1024 / 1024 );

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
	 * Check ZIP extension availability.
	 *
	 * @return array
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
	 * Check mPDF library availability.
	 *
	 * @return array
	 */
	private function check_mpdf(): array {
		if ( ! class_exists( '\SScribeVendor\Mpdf\Mpdf' ) ) {
			return array(
				'name'    => 'mPDF Library',
				'status'  => 'error',
				'message' => 'mPDF library not found. Run: composer install',
				'fix'     => 'Run composer install in the plugin directory',
			);
		}

		$amiri_dir   = SSCRIBE_PLUGIN_DIR . 'assets/fonts/amiri';
		$amiri_fonts = is_dir( $amiri_dir ) ? glob( $amiri_dir . '/Amiri-*.ttf' ) : array();
		if ( empty( $amiri_fonts ) ) {
			return array(
				'name'    => 'mPDF Library',
				'status'  => 'warning',
				'message' => 'mPDF loaded, but Amiri font files missing',
				'fix'     => 'Reinstall the plugin to restore font files',
			);
		}

		$ttfonts_dir = SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/mpdf/mpdf/ttfonts';
		if ( is_dir( $ttfonts_dir ) ) {
			$glob_flags = defined( 'GLOB_BRACE' ) ? GLOB_BRACE : 0;
			$font_files = glob( $ttfonts_dir . '/*.{ttf,otf,txt}', $glob_flags );
			if ( ! is_array( $font_files ) ) {
				$font_files = array_merge(
					(array) glob( $ttfonts_dir . '/*.ttf' ),
					(array) glob( $ttfonts_dir . '/*.otf' ),
					(array) glob( $ttfonts_dir . '/*.txt' )
				);
			}
			$count      = count( $font_files );
			if ( $count < self::MIN_MPDF_FONT_COUNT ) {
				return array(
					'name'    => 'mPDF Library',
					'status'  => 'warning',
					'message' => sprintf( 'mPDF loaded, but bundled fonts are incomplete (%d files found). Fallback rendering may fail.', $count ),
					'fix'     => 'Run "composer vendor:prefix" to restore all bundled fonts',
				);
			}
		}

		return array(
			'name'    => 'mPDF Library',
			'status'  => 'ok',
			'message' => 'mPDF loaded with full font support',
		);
	}

	/**
	 * Check PHPWord library availability.
	 *
	 * @return array
	 */
	private function check_phpword(): array {

		if ( class_exists( '\\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord' ) ) {
			return array(
				'name'    => 'PHPWord Library',
				'status'  => 'ok',
				'message' => 'PHPWord loaded : XML encoding handled natively by library',
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
	 *
	 * @return array
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

		$main_file = $plugin_dir . 'sscribe-export-site-pages.php';
		if ( ! is_readable( $main_file ) ) {
			$issues[] = 'Main plugin file not readable';
		}

		$exporter_file = $plugin_dir . 'includes/class-sscribe-exporter.php';
		if ( ! is_readable( $exporter_file ) ) {
			$issues[] = 'Exporter file not readable';
		}

		$autoloader_file = $plugin_dir . 'includes/sscribe-autoloader.php';
		if ( ! is_readable( $autoloader_file ) ) {
			$issues[] = 'Autoloader file not readable';
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
	 *
	 * @return array
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
	 * Check session health for orphaned sessions.
	 *
	 * @return array
	 */
	private function check_session_health(): array {
		global $wpdb;

		$option_prefix = SScribe_Session::OPTION_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);

		$orphaned = 0;
		foreach ( (array) $options as $option ) {
			$decoded = is_string( $option->option_value ) ? json_decode( $option->option_value, true ) : null;
			if ( is_array( $decoded ) && ( $decoded['status'] ?? '' ) === 'processing' ) {
				++$orphaned;
			}
		}

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
	 * Get recommendations based on check results.
	 *
	 * @param array $checks     Check results.
	 * @param int   $page_count Number of pages.
	 * @return array
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

		if ( isset( $checks['wp_cron'] ) && 'warning' === $checks['wp_cron']['status'] ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'message'  => 'Consider setting up a server-side cron for WP-Cron.',
			);
		}

		return $recommendations;
	}

	/**
	 * Diagnose a page-level export error.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $format  Export format.
	 * @param string $error   Error message.
	 * @param array  $context Additional context.
	 * @return array
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
		} elseif ( str_contains( $lower_error, 'mpdf' ) || str_contains( $lower_error, 'pdf' ) ) {
			$diagnosis['category'] = 'pdf_generation';
			$diagnosis['fix']      = array(
				'Run composer install to ensure mPDF is installed',
				'Check that the page content does not contain invalid HTML',
			);
		} elseif ( str_contains( $lower_error, 'phpword' ) || str_contains( $lower_error, 'docx' ) ) {
			$diagnosis['category'] = 'docx_generation';
			$diagnosis['fix']      = array(
				'Run composer install to ensure PHPWord is installed',
				'Check page content for complex elements that may not convert well',
			);
		}

		$warning_categories    = array( 'timeout' );
		$diagnosis['severity'] = in_array( $diagnosis['category'], $warning_categories, true ) ? 'warning' : 'error';

		return $diagnosis;
	}

	/**
	 * Categorize an error message.
	 *
	 * @param string $error Error message.
	 * @return string
	 */
	private function categorize_error( string $error ): string {
		$lower = strtolower( $error );

		$categories = array(
			'memory'     => array( 'memory', 'allocated', 'exhausted' ),
			'timeout'    => array( 'timeout', 'time limit', 'execution' ),
			'permission' => array( 'permission', 'writable', 'denied' ),
			'zip'        => array( 'zip', 'ziparchive', 'archive' ),
			'pdf'        => array( 'mpdf', 'pdf' ),
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
	 * Run self-healing cleanup tasks.
	 *
	 * @return array
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
	 *
	 * @return int
	 */
	private function clear_orphaned_locks(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_sscribe_lock_' ) . '%'
			)
		);

		$cleared = 0;
		foreach ( $locks as $lock ) {
			$transient = str_replace( '_transient_', '', $lock->option_name );
			$value     = get_transient( $transient );

			if ( $value ) {
				$parts     = explode( '|', $value );
				$lock_time = (int) $parts[0];

				if ( time() - $lock_time > 300 ) {
					delete_transient( $transient );
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Clear stale session options.
	 *
	 * @return int
	 */
	private function clear_stale_sessions(): int {
		global $wpdb;

		$option_prefix = SScribe_Session::OPTION_PREFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);

		$cleared     = 0;
		$session_ttl = apply_filters( 'sscribe_session_ttl', DAY_IN_SECONDS );
		foreach ( $sessions as $session ) {
			$data = json_decode( $session->option_value, true );

			if ( is_array( $data ) && isset( $data['created_at'] ) ) {
				$created_at = $data['created_at'];
				$created    = is_numeric( $created_at ) ? (int) $created_at : strtotime( (string) $created_at );
				if ( $created && time() - $created > $session_ttl ) {
					delete_option( $session->option_name );
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Clear old temporary files.
	 *
	 * @return int
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

			if ( $mtime && time() - $mtime > 3 * DAY_IN_SECONDS ) {

				if ( is_dir( $full_path ) && ! $this->is_temp_dir_in_use( $full_path ) ) {
					$this->delete_directory( $full_path );
					++$cleared;
				} elseif ( ! is_dir( $full_path ) ) {
					wp_delete_file( $full_path );
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Delete a directory recursively.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( string $dir ): void {
		SScribe_Security::delete_directory( $dir );
	}

	/**
	 * Check if a temp directory is currently in use by an active session.
	 *
	 * Prevents self-heal from deleting temp dirs belonging to ongoing exports.
	 *
	 * @param string $dir Directory path to check.
	 * @return bool True if directory is in use by an active session.
	 */
	private function is_temp_dir_in_use( string $dir ): bool {
		global $wpdb;

		if ( ! class_exists( 'SScribe_Session' ) ) {
			return false;
		}

		$session = new SScribe_Session();
		$pattern = $wpdb->esc_like( SScribe_Session::OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight check for active session temp_dir.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		foreach ( (array) $options as $option ) {
			$data = $session->decode_session_value( $option->option_value ?? '' );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( ! empty( $data['temp_dir'] ) && str_starts_with( $data['temp_dir'], $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get list of active SEO plugins.
	 *
	 * @return array
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
	 * Check AJAX endpoint health.
	 *
	 * @return array
	 */
	public function check_ajax_health(): array {
		$checks = array();

		$ajax_url           = admin_url( 'admin-ajax.php' );
		$home_url           = home_url();
		$checks['ajax_url'] = array(
			'name'    => 'AJAX Endpoint',
			'status'  => 'ok',
			'message' => $ajax_url,
		);

		$nonce                      = wp_create_nonce( 'sscribe_export_nonce' );
		$checks['nonce_generation'] = array(
			'name'    => 'Nonce Generation',
			'status'  => ! empty( $nonce ) ? 'ok' : 'error',
			'message' => ! empty( $nonce ) ? 'Nonces can be created' : 'Failed to generate nonce',
		);

		$has_cap                   = current_user_can( SScribe_Capabilities::get_required() );
		$checks['user_capability'] = array(
			'name'    => 'User Permission',
			'status'  => $has_cap ? 'ok' : 'error',
			'message' => $has_cap ? 'User has export capability' : 'User lacks required capability',
		);

		$site_url                  = site_url();
		$checks['url_consistency'] = array(
			'name'    => 'URL Configuration',
			'status'  => 'ok',
			'message' => sprintf( 'Home: %s | Site: %s', $home_url, $site_url ),
		);

		$has_error       = false;
		$summary         = 'AJAX health: OK';
		$recommendations = array();

		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$has_error = true;
			}
		}

		if ( $has_error ) {
			$summary = 'AJAX health: ERRORS DETECTED';
		}

		$recommendations[] = 'If AJAX returns 404, check that admin-ajax.php is NOT excluded in CDN/WAF rules (Cloudflare, Sucuri, Wordfence).';
		$recommendations[] = 'Ensure ModSecurity or similar WAF modules are not blocking AJAX POST requests containing HTML content.';
		$recommendations[] = sprintf( 'Verify that the plugin files are intact in %s', SSCRIBE_PLUGIN_DIR );

		return array(
			'status'          => $has_error ? 'error' : 'ok',
			'checks'          => $checks,
			'summary'         => $summary,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Get boot-time diagnostics.
	 *
	 * @return array
	 */
	public function get_boot_diagnostics(): array {
		$missing_deps = $this->check_vendor_dependencies();
		$loaded       = empty( $missing_deps );

		$hooks_registered = 0;
		global $wp_filter;
		foreach ( (array) $wp_filter as $hook_obj ) {
			if ( ! is_object( $hook_obj ) ) {
				continue;
			}
			foreach ( $hook_obj as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) {
						$class_name = get_class( $callback[0] );
						if ( strpos( $class_name, 'SScribe' ) !== false ) {
							++$hooks_registered;
						}
					}
				}
			}
		}

		$boot_errors = array();
		if ( ! class_exists( 'SScribe' ) ) {
			$boot_errors[] = 'Core class SScribe not found';
		}
		if ( ! class_exists( 'SScribe_Batch_Processor' ) ) {
			$boot_errors[] = 'Batch processor class not found';
		}

		return array(
			'loaded'           => $loaded && empty( $boot_errors ),
			'version'          => defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : 'unknown',
			'dependencies'     => array(
				'mpdf_loaded'    => class_exists( '\\SScribeVendor\\Mpdf\\Mpdf' ),
				'phpword_loaded' => class_exists( '\\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord' ),
				'zip_extension'  => class_exists( 'ZipArchive' ),
			),
			'hooks_registered' => $hooks_registered,
			'boot_errors'      => $boot_errors,
		);
	}

	/**
	 * Format a support info value for safe public display.
	 *
	 * @param string $key   Field key.
	 * @param string $value Raw field value.
	 * @return string Formatted value safe for public sharing.
	 */
	private function format_support_value( string $key, string $value ): string {

		if ( 'php_version' === $key ) {
			$parts = explode( '.', $value );
			if ( count( $parts ) >= 2 ) {
				return $parts[0] . '.' . $parts[1] . '.x';
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Build copy-paste support text.
	 *
	 * @param array $sections     Info sections.
	 * @param array $audit_events Audit events.
	 * @param array $log_tail     Recent log entries.
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
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$value   = $this->format_support_value( $key, (string) $value );
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
				$lines[] = is_string( $entry ) ? $entry : wp_json_encode( $entry );
			}
		}

		return implode( PHP_EOL, $lines );
	}
}
