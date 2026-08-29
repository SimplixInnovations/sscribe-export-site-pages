<?php
/**
 * SScribe Diagnostics
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostics logging only fires inside `if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )` runtime guards (8 sites). Inline per-call `phpcs:ignore` would be brittle to add to try/catch blocks; the runtime guard is the canonical safety.
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
		$this->support_errors = array();
		$export_dir    = SScribe_Private_Storage::get_export_dir( false );
		$log_dir       = SScribe_Private_Storage::get_subdirectory( 'logs', false );
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
				error_log( 'SScribe Diagnostics: monthly_stats failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: monthly_stats unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'monthly_stats unavailable';
		}

		try {
			$collector     = $container->get( SScribe_Page_Collector::class );
			$status_counts = $collector->get_post_status_counts( '' );
		} catch ( \Throwable $e ) {
			$status_counts = array();
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: status_counts failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: status_counts unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'status_counts unavailable';
		}

		try {
			$session_check = $this->check_session_health();
		} catch ( \Throwable $e ) {
			$session_check = array( 'message' => __( 'Unavailable', 'sscribe-export-site-pages' ) );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: session_check failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: session_check unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'session_check unavailable';
		}

		try {
			$wpml_active = $container->get( SScribe_Page_Collector::class )->is_wpml_active();
		} catch ( \Throwable $e ) {
			$wpml_active = false;
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: wpml_active failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: wpml_active check failed', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'wpml_active unavailable';
		}

		try {
			$seo_plugins = implode( ', ', $this->get_active_seo_plugins() );
		} catch ( \Throwable $e ) {
			$seo_plugins = __( 'Unavailable', 'sscribe-export-site-pages' );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: seo_plugins failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: seo_plugins unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'seo_plugins unavailable';
		}

		try {
			$session         = $container->get( SScribe_Session::class );
			$session_storage = $session->get_storage_type();
		} catch ( \Throwable $e ) {
			$session_storage = 'unknown';
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SScribe Diagnostics: session_storage failed : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $debug_logger ) {
				$debug_logger->warning( 'Support info: session_storage unavailable', array( 'error' => $e->getMessage() ) );
			}
			$this->support_errors[] = 'session_storage unavailable';
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

		// Pre-warm the prefixed vendor autoloader before the class_exists()
		// probes below. The autoloader is lazy-loaded by
		// SScribe_Exporter_Factory so that frontend page requests don't pay
		// the PhpWord/mPDF parse cost on every load. The export flow works
		// because it triggers the autoloader before instantiating the
		// exporter; this Support snapshot can be requested from any admin
		// page (settings, history, support itself) without an exporter ever
		// running, so without the pre-warm the probes would return false
		// even though the libraries are fully installed and ready.
		$this->check_vendor_dependencies();

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
				'dom_extension'      => class_exists( 'DOMDocument' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'mbstring'           => function_exists( 'mb_convert_encoding' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'mpdf'               => class_exists( '\SScribeVendor\Mpdf\Mpdf' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
				'phpword'            => class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ? __( 'Available', 'sscribe-export-site-pages' ) : __( 'Missing', 'sscribe-export-site-pages' ),
			),
		);

		$sections['paths'] = array(
			'label' => __( 'Paths', 'sscribe-export-site-pages' ),
			'items' => array(
				'storage'     => '' === $export_dir ? __( 'Unavailable', 'sscribe-export-site-pages' ) : '[private]/' . SScribe_Private_Storage::get_directory_name(),
				'export_dir'  => '[private]/' . SScribe_Private_Storage::get_directory_name(),
				'log_dir'     => '[private]/' . SScribe_Private_Storage::get_directory_name() . '/logs',
				'writable'    => '' !== $export_dir && wp_is_writable( $export_dir ) ? __( 'Yes', 'sscribe-export-site-pages' ) : __( 'No', 'sscribe-export-site-pages' ),
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

		try {
			$copy_text = $this->build_support_copy_text( $sections );
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
			'copy_text'      => $copy_text,
			'has_debug_mode' => $debug_enabled,
			'storage'        => array(
				'session_storage' => $session_storage,
			),
			'errors'         => $this->support_errors,
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

		// Pre-warm the prefixed vendor autoloader BEFORE the mPDF / PHPWord
		// checks below. The preflight runs over AJAX (admin-ajax.php), which
		// never fires admin_notices; the only other path that calls
		// check_vendor_dependencies(). Without this call, the very first
		// preflight on a fresh install reports a false-positive "mPDF
		// library not found. Run: composer install" because the prefixed
		// autoloader has never been registered in this request. Idempotent:
		// the inner check is a one-shot via SSCRIBE_VENDOR_AUTOLDED, and
		// later calls become a no-op once defined.
		$this->check_vendor_dependencies();

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
		// Pre-warm the prefixed vendor autoloader BEFORE class_exists()
		// checks. The autoloader is lazy-loaded by SScribe_Exporter_Factory
		// so frontend page loads don't pay the PhpWord/mPDF parse cost at
		// boot. The admin notice is gated on this same dependency list,
		// so without the pre-warm it would always fire a false-positive on
		// admin pages where no exporter has yet exercised
		// create()/is_supported(). Three paths:
		//   1) SSCRIBE_VENDOR_AUTOLOADED is already defined -> someone
		//      earlier in the request primed it, nothing to do.
		//   2) SScribe_Exporter_Factory is already loaded (because some
		//      earlier call site used it) -> delegate to its one-shot
		//      loader.
		//   3) The factory has never been touched yet (most common: a
		//      settings page where no exporter has been invoked). We
		//      trigger the plugin spl_autoloader by calling
		//      `class_exists( '\SScribe_Exporter_Factory' )` with the
		//      default autoload-ON behavior, then delegate. This is the
		//      path that closes the false-positive for fresh admin loads
		//      because the plugin autoloader maps
		//      `SScribe_Exporter_Factory` to
		//      `includes/exporters/class-sscribe-exporter-factory.php`.
		if ( ! defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) ) {
			if ( class_exists( '\SScribe_Exporter_Factory' ) ) {
				\SScribe_Exporter_Factory::ensure_vendor_loaded();
			} else {

				if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
					$missing = array( 'SScribeVendor\Mpdf\Mpdf', 'SScribeVendor\PhpOffice\PhpWord\PhpWord' );
					return $missing;
				}
				$vendor_prefixed = SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
				if ( file_exists( $vendor_prefixed ) ) {
					require_once $vendor_prefixed;
					define( 'SSCRIBE_VENDOR_AUTOLOADED', true );
				}
			}
		}

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
				'name'    => __( 'PHP Version', 'sscribe-export-site-pages' ),
				'status'  => 'ok',
				'message' => sprintf(
					/* translators: 1: current PHP version, 2: minimum required PHP version. */
					__( 'PHP %1$s (minimum: %2$s)', 'sscribe-export-site-pages' ),
					$current,
					$required
				),
			);
		}

		return array(
			'name'    => __( 'PHP Version', 'sscribe-export-site-pages' ),
			'status'  => 'error',
			'message' => sprintf(
				/* translators: 1: current PHP version, 2: minimum required PHP version. */
				__( 'PHP %1$s detected. Minimum required: %2$s. Contact your hosting provider to upgrade.', 'sscribe-export-site-pages' ),
				$current,
				$required
			),
			'fix'     => __( 'Upgrade PHP to 8.2 or higher', 'sscribe-export-site-pages' ),
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
		if ( $memory_limit <= 0 ) {
			return array(
				'name'    => __( 'Memory', 'sscribe-export-site-pages' ),
				'status'  => 'ok',
				'message' => __( 'PHP memory limit is unlimited.', 'sscribe-export-site-pages' ),
			);
		}

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
				'name'    => __( 'Memory Forecast', 'sscribe-export-site-pages' ),
				'status'  => 'error',
				'message' => sprintf(
					/* translators: 1: memory limit in MB, 2: used memory in MB. */
					__( 'No memory available for export (limit: %1$dMB, used: %2$dMB). Increase memory_limit in php.ini.', 'sscribe-export-site-pages' ),
					$memory_mb,
					$used_mb
				),
				'fix'     => __( 'Add define( "WP_MEMORY_LIMIT", "256M" ); to wp-config.php', 'sscribe-export-site-pages' ),
			);
		}

		$safe_available_mb = (int) ( $available_mb * 0.8 );

		if ( $memory_mb < 128 ) {
			return array(
				'name'    => __( 'Memory Limit', 'sscribe-export-site-pages' ),
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %d: memory limit in MB. */
					__( 'Memory limit: %dMB. Minimum required: 128MB. Increase memory_limit in php.ini.', 'sscribe-export-site-pages' ),
					$memory_mb
				),
				'fix'     => __( 'Add define( "WP_MEMORY_LIMIT", "256M" ); to wp-config.php', 'sscribe-export-site-pages' ),
			);
		}

		if ( $estimated_total_mb > $safe_available_mb ) {
			$recommended_memory = ceil( $estimated_total_mb / 256 ) * 256;

			if ( $estimated_total_mb > $available_mb ) {
				return array(
					'name'    => __( 'Memory Forecast', 'sscribe-export-site-pages' ),
					'status'  => 'error',
					'message' => sprintf(
						/* translators: 1: estimated memory need in MB, 2: available memory in MB, 3: recommended memory in MB. */
						__( 'Export requires ~%1$dMB but only %2$dMB available. Increase memory to %3$dMB+ or reduce page count.', 'sscribe-export-site-pages' ),
						$estimated_total_mb,
						$available_mb,
						$recommended_memory
					),
					'fix'     => sprintf(
						/* translators: %d: recommended memory limit in MB. */
						__( 'Add define( "WP_MEMORY_LIMIT", "%dM" ); to wp-config.php or export fewer pages.', 'sscribe-export-site-pages' ),
						$recommended_memory
					),
				);
			}

			$percent = round( ( $estimated_total_mb / $available_mb ) * 100 );

			return array(
				'name'    => __( 'Memory Forecast', 'sscribe-export-site-pages' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: 1: estimated memory use in MB, 2: available memory in MB, 3: percentage of available memory. */
					__( 'Export will use ~%1$dMB of %2$dMB available (%3$d%%). Consider increasing memory for safety.', 'sscribe-export-site-pages' ),
					$estimated_total_mb,
					$available_mb,
					$percent
				),
				'fix'     => __( 'Increase memory_limit to provide more headroom for large exports', 'sscribe-export-site-pages' ),
			);
		}

		if ( $available_mb < 50 ) {
			return array(
				'name'    => __( 'Available Memory', 'sscribe-export-site-pages' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: 1: available memory in MB, 2: used memory in MB, 3: memory limit in MB. */
					__( 'Only %1$dMB available (%2$dMB used of %3$dMB limit). Large exports may fail.', 'sscribe-export-site-pages' ),
					$available_mb,
					$used_mb,
					$memory_mb
				),
				'fix'     => __( 'Increase memory_limit or reduce batch size', 'sscribe-export-site-pages' ),
			);
		}

		return array(
			'name'    => __( 'Memory', 'sscribe-export-site-pages' ),
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: 1: available memory in MB, 2: memory limit in MB, 3: used memory in MB, 4: estimated memory need in MB. */
				__( '%1$dMB available (limit: %2$dMB, used: %3$dMB, estimated need: %4$dMB)', 'sscribe-export-site-pages' ),
				$available_mb,
				$memory_mb,
				$used_mb,
				$estimated_total_mb
			),
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
				'name'    => __( 'Execution Time', 'sscribe-export-site-pages' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: max execution time in seconds. */
					__( 'max_execution_time: %ds. Short timeout may interrupt large exports.', 'sscribe-export-site-pages' ),
					$max_execution
				),
				'fix'     => __( 'Increase max_execution_time or rely on batch processing', 'sscribe-export-site-pages' ),
			);
		}

		return array(
			'name'    => __( 'Execution Time', 'sscribe-export-site-pages' ),
			'status'  => 'ok',
			'message' => $max_execution > 0
				? sprintf(
					/* translators: 1: max execution time in seconds, 2: estimated execution time in seconds. */
					__( 'max_execution_time: %1$ds (estimated need: %2$ds)', 'sscribe-export-site-pages' ),
					$max_execution,
					$estimated_seconds
				)
				: sprintf(
					/* translators: %d: estimated execution time in seconds. */
					__( 'max_execution_time: unlimited (estimated need: %ds)', 'sscribe-export-site-pages' ),
					$estimated_seconds
				),
		);
	}

	/**
	 * Check private storage writability.
	 *
	 * @return array
	 */
	private function check_upload_directory(): array {
		$export_dir = SScribe_Private_Storage::get_export_dir();
		if ( '' === $export_dir || ! is_dir( $export_dir ) ) {
			return array(
				'name'    => __( 'Private Storage', 'sscribe-export-site-pages' ),
				'status'  => 'error',
				'message' => __( 'A safe private export directory could not be created.', 'sscribe-export-site-pages' ),
				'fix'     => __( 'Make the system temporary directory writable, or configure SSCRIBE_PRIVATE_STORAGE_DIR to a writable path outside the web root.', 'sscribe-export-site-pages' ),
			);
		}

		if ( ! wp_is_writable( $export_dir ) ) {
			return array(
				'name'    => __( 'Private Storage', 'sscribe-export-site-pages' ),
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %s: export directory name. */
					__( 'Export directory is not writable: %s', 'sscribe-export-site-pages' ),
					basename( $export_dir )
				),
				'fix'     => __( 'Set the private storage directory to owner-writable permissions.', 'sscribe-export-site-pages' ),
			);
		}

		if ( ! function_exists( 'disk_free_space' ) ) {
			return array(
				'name'    => __( 'Disk Space', 'sscribe-export-site-pages' ),
				'status'  => 'ok',
				'message' => __( 'Disk space check is not available on this server configuration (S3, NFS, or restricted hosting).', 'sscribe-export-site-pages' ),
			);
		}

		$free_space = disk_free_space( $export_dir );
		if ( false === $free_space ) {
			return array(
				'name'    => __( 'Disk Space', 'sscribe-export-site-pages' ),
				'status'  => 'ok',
				'message' => __( 'Disk space check not available on this server configuration.', 'sscribe-export-site-pages' ),
			);
		}
		$free_mb = round( $free_space / 1024 / 1024 );

		if ( $free_mb < 100 ) {
			return array(
				'name'    => __( 'Disk Space', 'sscribe-export-site-pages' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: free disk space in MB. */
					__( 'Only %dMB free disk space. Large exports may fail.', 'sscribe-export-site-pages' ),
					$free_mb
				),
				'fix'     => __( 'Free up disk space on the server', 'sscribe-export-site-pages' ),
			);
		}

		return array(
			'name'    => __( 'Private Storage', 'sscribe-export-site-pages' ),
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %d: free disk space in MB. */
				__( 'Writable. Free space: %dMB', 'sscribe-export-site-pages' ),
				$free_mb
			),
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
		// Defense in depth: prewarm vendor before class_exists() so any caller
		// (not just run_preflight()) resolves the prefixed class.
		$this->check_vendor_dependencies();

		if ( ! class_exists( '\SScribeVendor\Mpdf\Mpdf' ) ) {
			return array(
				'name'    => 'mPDF Library',
				'status'  => 'error',
				'message' => 'mPDF library files are missing from the plugin install. The vendor-prefixed/ directory must be present for PDF exports.',
				'fix'     => 'Deactivate the plugin, then reinstall it from the Plugins screen to restore the bundled libraries.',
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
			$font_files = $this->find_mpdf_font_files( $ttfonts_dir );
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
	 * Find packaged mPDF font assets without relying on GLOB_BRACE.
	 *
	 * @param string $directory Font directory.
	 * @return array<string> Absolute font paths.
	 */
	private function find_mpdf_font_files( string $directory ): array {
		$font_files = array_merge(
			(array) glob( $directory . '/*.ttf' ),
			(array) glob( $directory . '/*.otf' ),
			(array) glob( $directory . '/*.txt' )
		);
		sort( $font_files, SORT_STRING );

		return $font_files;
	}

	/**
	 * Check PHPWord library availability.
	 *
	 * @return array
	 */
	private function check_phpword(): array {

		// Defense in depth: prewarm vendor before class_exists() so any caller
		// (not just run_preflight()) resolves the prefixed class.
		$this->check_vendor_dependencies();

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
			'message' => 'PHPWord library files are missing from the plugin install. The vendor-prefixed/ directory must be present for DOCX exports.',
			'fix'     => 'Deactivate the plugin, then reinstall it from the Plugins screen to restore the bundled libraries.',
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
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);

		$orphaned = 0;
		$session  = new SScribe_Session();
		foreach ( (array) $options as $option ) {
			$session_id = SScribe_Session::extract_session_id( (string) ( $option->option_name ?? '' ) );
			if ( null === $session_id ) {
				continue;
			}

			$decoded      = $session->decode_session_value( $option->option_value ?? '', $session_id );
			$last_updated = is_array( $decoded ) ? (int) ( $decoded['updated_at'] ?? $decoded['created_at'] ?? 0 ) : 0;
			if (
				is_array( $decoded )
				&& in_array( $decoded['status'] ?? '', array( 'processing', 'pending', 'finalizing', 'completing' ), true )
				&& ( $last_updated <= 0 || ( time() - $last_updated ) > HOUR_IN_SECONDS )
			) {
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
				'message'  => sprintf(
					/* translators: %d: number of pages being exported. */
					__( 'Exporting %d pages. Recommended memory: 512MB+. This will take several minutes.', 'sscribe-export-site-pages' ),
					$page_count
				),
			);
		}

		if ( 'ok' !== $checks['memory']['status'] ) {
			$recommendations[] = array(
				'priority' => 'high',
				'message'  => __( 'Increase PHP memory_limit to prevent export failures.', 'sscribe-export-site-pages' ),
			);
		}

		if ( isset( $checks['wp_cron'] ) && 'warning' === $checks['wp_cron']['status'] ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'message'  => __( 'Consider setting up a server-side cron for WP-Cron.', 'sscribe-export-site-pages' ),
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
				__( 'Add define( "WP_MEMORY_LIMIT", "512M" ); to wp-config.php', 'sscribe-export-site-pages' ),
				__( 'Reduce export batch size', 'sscribe-export-site-pages' ),
				__( 'Export fewer formats at once', 'sscribe-export-site-pages' ),
			);
			$diagnosis['technical'] = array(
				'current_usage' => size_format( memory_get_usage( true ) ),
				'peak_usage'    => size_format( memory_get_peak_usage( true ) ),
				'memory_limit'  => ini_get( 'memory_limit' ),
			);
		} elseif ( str_contains( $lower_error, 'timeout' ) || str_contains( $lower_error, 'time limit' ) ) {
			$diagnosis['category'] = 'timeout';
			$diagnosis['fix']      = array(
				__( 'Increase max_execution_time in php.ini', 'sscribe-export-site-pages' ),
				__( 'Reduce page content complexity', 'sscribe-export-site-pages' ),
				__( 'Export in smaller batches', 'sscribe-export-site-pages' ),
			);
		} elseif ( str_contains( $lower_error, 'zip' ) || str_contains( $lower_error, 'ziparchive' ) ) {
			$diagnosis['category'] = 'zip_extension';
			$diagnosis['fix']      = array(
				__( 'Enable the zip extension in php.ini', 'sscribe-export-site-pages' ),
				__( 'Contact hosting provider to enable ZipArchive', 'sscribe-export-site-pages' ),
			);
		} elseif ( str_contains( $lower_error, 'permission' ) || str_contains( $lower_error, 'writable' ) ) {
			$diagnosis['category'] = 'permissions';
			$diagnosis['fix']      = array(
				__( 'Check owner permissions on the private storage directory', 'sscribe-export-site-pages' ),
				__( 'Check that the PHP process can write to the configured private storage base', 'sscribe-export-site-pages' ),
			);
		} elseif ( str_contains( $lower_error, 'mpdf' ) || str_contains( $lower_error, 'pdf' ) ) {
			$diagnosis['category'] = 'pdf_generation';
			$diagnosis['fix']      = array(
				__( 'Run composer install to ensure mPDF is installed', 'sscribe-export-site-pages' ),
				__( 'Check that the page content does not contain invalid HTML', 'sscribe-export-site-pages' ),
			);
		} elseif ( str_contains( $lower_error, 'phpword' ) || str_contains( $lower_error, 'docx' ) ) {
			$diagnosis['category'] = 'docx_generation';
			$diagnosis['fix']      = array(
				__( 'Run composer install to ensure PHPWord is installed', 'sscribe-export-site-pages' ),
				__( 'Check page content for complex elements that may not convert well', 'sscribe-export-site-pages' ),
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
		return ( new SScribe_Export_Lock_Manager() )->cleanup_expired_locks();
	}

	/**
	 * Clear stale session options.
	 *
	 * @return int
	 */
	private function clear_stale_sessions(): int {
		$session_ttl = (int) apply_filters( 'sscribe_session_ttl', DAY_IN_SECONDS );
		$session_ttl = max( HOUR_IN_SECONDS, min( 7 * DAY_IN_SECONDS, $session_ttl ) );

		return ( new SScribe_Session() )->cleanup_expired( $session_ttl );
	}

	/**
	 * Clear old temporary files.
	 *
	 * @return int
	 */
	private function clear_old_temp_files(): int {
		$export_dir = SScribe_Private_Storage::get_export_dir( false );

		if ( ! is_dir( $export_dir ) || is_link( $export_dir ) ) {
			return 0;
		}

		$cleared     = 0;
		$temp_dirs   = glob( trailingslashit( $export_dir ) . 'temp-*', GLOB_ONLYDIR ) ?: array();
		$active_dirs = $this->collect_active_temp_dirs();

		foreach ( $temp_dirs as $temp_dir ) {
			if ( is_link( $temp_dir ) || ! is_dir( $temp_dir ) ) {
				continue;
			}

			$mtime = filemtime( $temp_dir );
			if (
				false !== $mtime
				&& time() - $mtime > 3 * DAY_IN_SECONDS
				&& ! $this->is_temp_dir_in_active_set( $temp_dir, $active_dirs )
				&& $this->delete_directory( $temp_dir )
			) {
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * Collect the set of temp_dir paths currently owned by an active session.
	 *
	 * Replaces the previous per-file is_temp_dir_in_use() call which issued
	 * one DB query per directory. Building the set once brings the cost
	 * down to a single query regardless of how many files self-heal examines.
	 *
	 * @return array<string, true> Active temp_dir paths, keyed by path.
	 */
	private function collect_active_temp_dirs(): array {
		global $wpdb;

		if ( ! class_exists( 'SScribe_Session' ) ) {
			return array();
		}

		$session = new SScribe_Session();
		$pattern = $wpdb->esc_like( SScribe_Session::OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight check for active session temp_dir.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		$active = array();
		foreach ( (array) $options as $option ) {
			$session_id = SScribe_Session::extract_session_id( (string) ( $option->option_name ?? '' ) );
			if ( null === $session_id ) {
				continue;
			}

			$data = $session->decode_session_value( $option->option_value ?? '', $session_id );
			if (
				! is_array( $data )
				|| empty( $data['temp_dir'] )
				|| ! in_array( $data['status'] ?? '', array( 'processing', 'pending', 'finalizing', 'completing' ), true )
			) {
				continue;
			}
			$temp_dir = (string) $data['temp_dir'];
			if ( is_link( $temp_dir ) ) {
				continue;
			}

			$real_temp = realpath( $temp_dir );
			if ( false !== $real_temp ) {
				$active[ $this->normalize_path( $real_temp ) ] = true;
			}
		}

		return $active;
	}

	/**
	 * Membership check against the active-temp-dir set built once per pass.
	 *
	 * @param string              $dir         Directory to test.
	 * @param array<string, bool> $active_dirs Active temp_dirs indexed by path.
	 * @return bool True if $dir is currently owned by an active session.
	 */
	private function is_temp_dir_in_active_set( string $dir, array $active_dirs ): bool {
		if ( empty( $active_dirs ) || is_link( $dir ) ) {
			return false;
		}

		$real_dir = realpath( $dir );
		return false !== $real_dir && isset( $active_dirs[ $this->normalize_path( $real_dir ) ] );
	}

	/**
	 * Normalize an existing path for cross-platform set membership.
	 *
	 * @param string $path Absolute path.
	 * @return string Normalized path.
	 */
	private function normalize_path( string $path ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}

	/**
	 * Delete a directory recursively.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( string $dir ): bool {
		return SScribe_Security::delete_directory( $dir );
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

		$has_cap                   = current_user_can( SScribe_Capabilities::get_health_required() );
		$checks['user_capability'] = array(
			'name'    => 'User Permission',
			'status'  => $has_cap ? 'ok' : 'error',
			'message' => $has_cap ? 'User has health-check capability' : 'User lacks health-check capability',
		);

		$site_url                  = site_url();
		$checks['url_consistency'] = array(
			'name'    => 'URL Configuration',
			'status'  => 'ok',
			'message' => sprintf( 'Home: %s | Site: %s', $home_url, $site_url ),
		);

		$has_error       = false;
		$summary         = __( 'AJAX health: OK', 'sscribe-export-site-pages' );
		$recommendations = array();

		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$has_error = true;
			}
		}

		if ( $has_error ) {
			$summary = __( 'AJAX health: ERRORS DETECTED', 'sscribe-export-site-pages' );
		}

		$recommendations[] = __( 'If AJAX returns 404, check that admin-ajax.php is NOT excluded in CDN/WAF rules (Cloudflare, Sucuri, Wordfence).', 'sscribe-export-site-pages' );
		$recommendations[] = __( 'Ensure ModSecurity or similar WAF modules are not blocking AJAX POST requests containing HTML content.', 'sscribe-export-site-pages' );
		$recommendations[] = sprintf(
			/* translators: %s: plugin directory placeholder. */
			__( 'Verify that the plugin files are intact in %s', 'sscribe-export-site-pages' ),
			'[plugin]'
		);

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
	 * @param array $sections Info sections.
	 * @return string
	 */
	private function build_support_copy_text( array $sections ): string {
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

		return implode( PHP_EOL, $lines );
	}
}
