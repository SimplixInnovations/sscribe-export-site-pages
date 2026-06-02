<?php
/**
 * SScribe Batch Processor
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

/*
 * phpcs:disable WordPress.NamingConventions.ValidVariableName
 * Reason: ZipArchive is PHP built-in with camelCase properties like numFiles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-sscribe-result.php';

use SScribe_Result as SScribe_Export_Result;

/**
 * Handles batch export processing with rate limiting and resource monitoring.
 */
class SScribe_Batch_Processor {

	private const MAX_STORED_ERRORS          = 50;
	private const DEFAULT_FORMATS            = array( 'docx' );
	private const MAX_RETRIES                = 3;
	private const RETRY_TRANSIENT_CATEGORIES = array( 'network', 'timeout', 'rate_limit', 'temporary' );

	/**
	 * Number of pages to process in each batch.
	 *
	 * @var int
	 */
	private int $batch_size = 5;

	/**
	 * Diagnostics service.
	 *
	 * @var SScribe_Diagnostics|null
	 */
	private ?SScribe_Diagnostics $diagnostics = null;

	/**
	 * Page collector service.
	 *
	 * @var \SScribe_Page_Collector
	 */
	private readonly \SScribe_Page_Collector $collector;

	/**
	 * Zip handler service.
	 *
	 * @var \SScribe_Zip_Handler
	 */
	private readonly \SScribe_Zip_Handler $zip_handler;

	/**
	 * Session manager.
	 *
	 * @var \SScribe_Session
	 */
	private readonly \SScribe_Session $session;

	/**
	 * Logger interface.
	 *
	 * @var \SScribe_Logger_Interface
	 */
	private readonly \SScribe_Logger_Interface $logger;

	/**
	 * Batch file handler for download/delete/nonce operations.
	 *
	 * @var SScribe_Batch_File_Handler
	 */
	private readonly SScribe_Batch_File_Handler $file_handler;

	/**
	 * Batch session handler for session lifecycle operations.
	 *
	 * @var SScribe_Batch_Session_Handler
	 */
	private readonly SScribe_Batch_Session_Handler $session_handler;



	/**
	 * Export log instance.
	 *
	 * @var \SScribe_Export_Log|null
	 */
	private ?\SScribe_Export_Log $export_log = null;

	/**
	 * Current lock token for export operations.
	 *
	 * @var string|null
	 */
	private ?string $current_lock_token = null;

	/**
	 * Static flag to ensure shutdown cleanup is registered only once.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Active temp directory for shutdown cleanup (static to survive object destruction).
	 *
	 * @var string|null
	 */
	private static ?string $cleanup_temp_dir = null;

	/**
	 * Zip handler for shutdown cleanup.
	 *
	 * @var SScribe_Zip_Handler|null
	 */
	private static ?SScribe_Zip_Handler $cleanup_zip_handler = null;

	/**
	 * Logger for shutdown cleanup.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private static ?SScribe_Logger_Interface $cleanup_logger = null;

	/**
	 * Adaptive metrics collector.
	 *
	 * @var \SScribe_Adaptive_Metrics|null
	 */
	private ?\SScribe_Adaptive_Metrics $adaptive_metrics = null;

	/**
	 * Rate limiter for export operations.
	 *
	 * @var SScribe_Export_Rate_Limiter|null
	 */
	private ?SScribe_Export_Rate_Limiter $rate_limiter = null;

	/**
	 * Cached capability to avoid repeated filter/validation calls per request.
	 *
	 * @var string|null
	 */
	private ?string $cached_required_capability = null;

	/**
	 * Export auditor.
	 *
	 * @var SScribe_Export_Auditor|null
	 */
	private ?SScribe_Export_Auditor $auditor = null;

	/**
	 * Resource monitor.
	 *
	 * @var SScribe_Export_Resource_Monitor|null
	 */
	private ?SScribe_Export_Resource_Monitor $resource_monitor = null;

	/**
	 * Lock manager for concurrent operations.
	 *
	 * @var SScribe_Export_Lock_Manager|null
	 */
	private ?SScribe_Export_Lock_Manager $lock_manager = null;

	/**
	 * Error handler for export failures.
	 *
	 * @var SScribe_Export_Error_Handler|null
	 */
	private ?SScribe_Export_Error_Handler $error_handler = null;

	/**
	 * Query controller for database operations.
	 *
	 * @var SScribe_Export_Query_Controller|null
	 */
	private ?SScribe_Export_Query_Controller $query_controller = null;

	/**
	 * Generate cryptographically secure random bytes with fallback.
	 *
	 * @param int $length Number of bytes.
	 * @return string Raw binary bytes.
	 */
	private static function secure_random_bytes( int $length ): string {
		try {
			return random_bytes( $length );
		} catch ( \Throwable $e ) {
			// Fallback for environments where random_bytes() fails.
			// Check crypto_strong to ensure openssl fallback is cryptographically secure.
			$strong = false;
			$bytes  = openssl_random_pseudo_bytes( $length, $strong );
			if ( $bytes && $strong ) {
				return $bytes;
			}
			return wp_generate_password( $length, false );
		}
	}

	/**
	 * Get the adaptive metrics instance (lazy-loaded).
	 *
	 * @return \SScribe_Adaptive_Metrics
	 */
	private function get_adaptive_metrics(): \SScribe_Adaptive_Metrics {
		return $this->adaptive_metrics ??= new \SScribe_Adaptive_Metrics();
	}

	/**
	 * Get minimum file size thresholds for export validation.
	 *
	 * Filterable via 'sscribe_min_export_file_sizes' to allow host-specific adjustment.
	 *
	 * @return array<string, int> Format => minimum size in bytes.
	 */
	private function get_min_file_sizes(): array {
		return apply_filters(
			'sscribe_min_export_file_sizes',
			array(
				'docx'     => 8192,
				'pdf'      => 8192,
				'html'     => 512,
				'markdown' => 50,
			)
		);
	}

	/**
	 * Validate export result and check file integrity.
	 *
	 * @param object $result   Export result object.
	 * @param string $format   Export format.
	 * @param int    $page_id  Page ID.
	 * @return array Result with keys: is_valid, error, category, context.
	 */
	private function validate_export_result( object $result, string $format, int $page_id ): array {
		$min_sizes = $this->get_min_file_sizes();
		$min_size  = $min_sizes[ $format ] ?? 100;

		if ( ! $result->is_success() ) {
			$result_data = $result->get_data();
			return array(
				'is_valid' => false,
				'error'    => $result->get_error(),
				'category' => $result_data['error_category'] ?? 'unknown',
				'context'  => is_array( $result_data ) ? $result_data : array(),
			);
		}

		$file_path = $result->get_data()['path'] ?? '';
		$file_size = $result->get_data()['size'] ?? 0;
		clearstatcache( true, $file_path );
		$actual_size = ( ! empty( $file_path ) && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;

		if ( $actual_size < $min_size ) {
			$size_error = sprintf(
			/* translators: 1: Format, 2: Actual size, 3: Minimum size. */

				__( '%1$s file appears empty or corrupted (size: %2$d bytes, minimum: %3$d bytes).', 'sscribe-export-site-pages' ),
				strtoupper( $format ),
				$actual_size,
				$min_size
			);
			return array(
				'is_valid' => false,
				'error'    => $size_error,
				'category' => 'empty_file',
				'context'  => array(
					'file_path'     => $file_path,
					'actual_size'   => $actual_size,
					'reported_size' => $file_size,
				),
			);
		}

		// Warn if reported size differs from actual size by more than 10% — possible truncation or metadata issue.
		if ( $file_size > 0 ) {
			$size_diff_ratio = abs( $actual_size - $file_size ) / $file_size;
			if ( $size_diff_ratio > 0.10 ) {
				$this->logger->warning(
					'Export file size mismatch',
					array(
						'format'        => $format,
						'page_id'       => $page_id,
						'reported_size' => $file_size,
						'actual_size'   => $actual_size,
						'diff_ratio'    => round( $size_diff_ratio * 100, 1 ) . '%',
					)
				);
			}
		}

		return array(
			'is_valid' => true,
			'error'    => null,
			'category' => 'success',
			'context'  => array(
				'file_path'   => $file_path,
				'actual_size' => $actual_size,
			),
		);
	}

	/**
	 * Dispatch export to all formats for a single page.
	 *
	 * @param array  $page_data   Page data from collector.
	 * @param string $temp_dir    Temporary directory path.
	 * @param int    $page_index  Page index in export order.
	 * @param int    $total       Total pages.
	 * @param array  $formats     Formats to export.
	 * @param string $session_id  Session ID.
	 * @param array  &$session    Session array (passed by reference for tracking).
	 * @param int    $page_id     Page ID.
	 * @return array Results with keys: export_success, successful_formats, export_errors.
	 */
	private function dispatch_formats( array $page_data, string $temp_dir, int $page_index, int $total, array $formats, string $session_id, array &$session, int $page_id ): array {
		$export_success     = false;
		$successful_formats = array();
		$export_errors      = array();

		foreach ( $formats as $format ) {
			$format_start = microtime( true );

			try {
				$exporter = \SScribe_Exporter_Factory::create( $format );
			} catch ( SScribe_Validation_Exception $e ) {
				$this->logger->error( 'Invalid export format requested', array( 'format' => $format ) );
				$export_errors[] = array(
					'page_id'   => $page_id,
					'format'    => $format,
					'message'   => 'Invalid format: ' . $format,
					'category'  => 'configuration',
					'retryable' => false,
				);
				continue;
			}

			$attempt = 0;
			$result  = null;

			while ( $attempt < self::MAX_RETRIES ) {
				try {
					$result = $exporter->export( $page_data, $temp_dir, $page_index, $total );
				} catch ( \Throwable $e ) {
					$result_data    = array(
						'error_category' => 'transient',
						'error_message'  => $e->getMessage(),
					);
					$result = new SScribe_Export_Result( false, $result_data );
				}

				$is_transient = false;
				if ( ! $result->is_success() ) {
					$result_data    = $result->get_data();
					$error_category = $result_data['error_category'] ?? 'unknown';
					$is_transient   = in_array( $error_category, self::RETRY_TRANSIENT_CATEGORIES, true );
				}

				if ( $result->is_success() || ! $is_transient ) {
					break;
				}

				// Do not retry after the final attempt — accept current result as-is.
				if ( $attempt >= self::MAX_RETRIES - 1 ) {
					break;
				}

				// Retry delay: capped at 100ms to avoid blocking the FPM worker for
				// extended periods. Skip delay on attempt 0 to fail fast on genuine errors.
				// Attempt 0: no delay (fail fast), Attempt 1: 100ms, Attempt 2: 100ms.
				$delay_ms = min( 100, 100 * ( 2 ** $attempt ) );
				if ( $attempt > 0 ) {
					usleep( $delay_ms * 1000 );
				}
				++$attempt;

				// Recreate exporter for clean state on next attempt.
				try {
					$exporter = \SScribe_Exporter_Factory::create( $format );
				} catch ( SScribe_Validation_Exception $e ) {
					$this->logger->error( 'Invalid export format on retry', array( 'format' => $format ) );
					break;
				}
			}

			// Free exporter immediately after use to prevent memory buildup across format iterations.
			$exporter = null;

			$format_elapsed         = microtime( true ) - $format_start;
			$format_key             = 'format_time_' . $format;
			$session[ $format_key ] = min( 3600, ( $session[ $format_key ] ?? 0 ) + $format_elapsed );

			$file_path = $result->get_data()['path'] ?? '';
			$file_size = $result->get_data()['size'] ?? 0;

			$validation = $this->validate_export_result( $result, $format, $page_id );

			if ( ! $validation['is_valid'] ) {
				$export_errors[] = array(
					'format'   => strtoupper( $format ),
					'message'  => $validation['error'],
					'category' => $validation['category'],
					'context'  => $validation['context'],
				);

				if ( $this->export_log ) {
					$this->export_log->log_format_result( $page_id, $format, false, '', $validation['error'] );
				}

				if ( 'empty_file' === $validation['category'] ) {
					$this->logger->error(
						ucfirst( $format ) . ' export produced empty/corrupted file',
						array(
							'page_id'       => $page_id,
							'file_path'     => $file_path,
							'actual_size'   => $validation['context']['actual_size'] ?? 0,
							'reported_size' => $file_size,
							'min_size'      => 100,
						)
					);
				}
			} else {
				$export_success       = true;
				$successful_formats[] = $format;

				$format_size_key             = 'format_size_' . $format;
				$session[ $format_size_key ] = min( 1073741824, ( $session[ $format_size_key ] ?? 0 ) + ( $validation['context']['actual_size'] ?? 0 ) );

				$format_pages_key             = 'format_pages_' . $format;
				$session[ $format_pages_key ] = min( 10000, ( $session[ $format_pages_key ] ?? 0 ) + 1 );

				if ( $this->export_log ) {
					$this->export_log->log_format_result( $page_id, $format, true, $file_path );
				}

				$this->logger->debug(
					ucfirst( $format ) . ' generated successfully',
					array(
						'file'        => basename( $file_path ),
						'page_id'     => $page_id,
						'format'      => $format,
						'actual_size' => $validation['context']['actual_size'] ?? 0,
						'duration'    => round( $format_elapsed, 3 ),
					)
				);
			}
		}

		return array(
			'export_success'     => $export_success,
			'successful_formats' => $successful_formats,
			'export_errors'      => $export_errors,
		);
	}

	/**
	 * Get the rate limiter instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Rate_Limiter
	 */
	private function get_rate_limiter(): SScribe_Export_Rate_Limiter {
		return $this->rate_limiter ??= new SScribe_Export_Rate_Limiter();
	}

	/**
	 * Get the auditor instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Auditor
	 */
	private function get_auditor(): SScribe_Export_Auditor {
		return $this->auditor ??= new SScribe_Export_Auditor();
	}

	/**
	 * Get the resource monitor instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Resource_Monitor
	 */
	private function get_resource_monitor(): SScribe_Export_Resource_Monitor {
		return $this->resource_monitor ??= new SScribe_Export_Resource_Monitor();
	}

	/**
	 * Get the lock manager instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Lock_Manager
	 */
	private function get_lock_manager(): SScribe_Export_Lock_Manager {
		return $this->lock_manager ??= new SScribe_Export_Lock_Manager( $this->logger );
	}

	/**
	 * Get the error handler instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Error_Handler
	 */
	private function get_error_handler(): SScribe_Export_Error_Handler {
		return $this->error_handler ??= new SScribe_Export_Error_Handler();
	}

	/**
	 * Get the query controller instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Query_Controller
	 */
	private function get_query_controller(): SScribe_Export_Query_Controller {
		if ( null === $this->query_controller ) {
			$this->query_controller = new SScribe_Export_Query_Controller(
				$this->get_rate_limiter(),
				$this->get_diagnostics(),
				$this->collector,
				$this->logger,
				$this->zip_handler,
				$this->get_adaptive_metrics(),
				$this->get_error_handler()
			);
		}
		return $this->query_controller;
	}

	/**
	 * Get the diagnostics instance (lazy-loaded).
	 *
	 * @return SScribe_Diagnostics
	 */
	private function get_diagnostics(): SScribe_Diagnostics {
		return $this->diagnostics ??= new SScribe_Diagnostics();
	}

	/**
	 * Initialize the batch processor.
	 *
	 * @param SScribe_Page_Collector|null        $collector      Page collector.
	 * @param SScribe_Zip_Handler|null           $zip_handler    Zip handler.
	 * @param SScribe_Session|null               $session         Session.
	 * @param SScribe_Logger_Interface|null      $logger          Logger.
	 * @param SScribe_Batch_File_Handler|null    $file_handler   File handler.
	 * @param SScribe_Batch_Session_Handler|null $session_handler Session handler.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Session $session = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Batch_File_Handler $file_handler = null,
		?SScribe_Batch_Session_Handler $session_handler = null
	) {
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 5 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector       = $collector ?? new SScribe_Page_Collector();
		$this->zip_handler     = $zip_handler ?? new SScribe_Zip_Handler();
		$this->session         = $session ?? new SScribe_Session();
		$this->logger          = $logger ?? SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$this->file_handler    = $file_handler ?? new SScribe_Batch_File_Handler(
			$this->get_rate_limiter(),
			$this->zip_handler,
			$this->logger,
			new SScribe_Export_Auditor()
		);
		$this->session_handler = $session_handler ?? new SScribe_Batch_Session_Handler(
			$this->session,
			$this->zip_handler,
			$this->logger,
			new SScribe_Export_Auditor(),
			$this->get_rate_limiter(),
			new SScribe_Export_Lock_Manager( $this->logger )
		);

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function(
				array( self::class, 'shutdown_cleanup' )
			);
		}
	}

	/**
	 * Verify rate limit hasn't been exceeded.
	 *
	 * @return bool True if rate limit check passes.
	 */
	private function check_rate_limit(): bool {
		return $this->get_rate_limiter()->check_rate_limit( $this->get_required_capability() );
	}

	/**
	 * Log an audit event.
	 *
	 * @param string $action  Action name.
	 * @param array  $context Additional context.
	 */
	private function audit_log( string $action, array $context = array() ): void {
		$this->get_auditor()->log( $action, $context );
	}

	/**
	 * Check if there's enough memory for processing.
	 *
	 * @param int $buffer_mb Additional buffer in MB.
	 * @return bool True if memory is available.
	 */
	private function is_memory_available( int $buffer_mb = 10 ): bool {
		return $this->get_resource_monitor()->is_memory_available( $buffer_mb );
	}

	/**
	 * Check if there's enough time remaining for batch processing.
	 *
	 * @param float $batch_start_time Start time of batch.
	 * @param int   $buffer_seconds   Safety buffer in seconds.
	 * @return bool True if time is available.
	 */
	private function is_time_available( float $batch_start_time, int $buffer_seconds = 10 ): bool {
		return $this->get_resource_monitor()->is_time_available( $batch_start_time, $buffer_seconds );
	}

	/**
	 * Calculate remaining processing time.
	 *
	 * @param float $batch_start_time Start time of batch.
	 * @return float Remaining time in seconds.
	 */
	private function get_remaining_time( float $batch_start_time ): float {
		return $this->get_resource_monitor()->get_remaining_time( $batch_start_time );
	}

	/**
	 * Get current memory usage as percentage.
	 *
	 * @return float Memory usage percentage.
	 */
	private function get_memory_usage_percent(): float {
		return $this->get_resource_monitor()->get_memory_usage_percent();
	}

	/**
	 * Adjust batch size based on available resources.
	 *
	 * @param array  $formats Export formats being processed.
	 * @param string $hint    Optional hint from previous batch ('memory', 'timeout').
	 */
	private function optimize_batch_size( array $formats = array(), string $hint = '' ): void {
		$this->batch_size = $this->get_resource_monitor()->get_optimal_batch_size( $formats, $hint );

		$this->logger->debug(
			'Batch size optimized',
			array(
				'formats'        => $formats,
				'optimized_size' => $this->batch_size,
				'pause_hint'     => $hint,
			)
		);
	}

	/**
	 * Get warning message if memory might be insufficient.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array|null Warning array or null if okay.
	 */
	private function get_memory_warning( int $page_count, array $formats ): ?array {
		return $this->get_resource_monitor()->get_memory_warning( $page_count, $formats );
	}

	/**
	 * Get the capability required for export operations.
	 *
	 * @return string Capability name.
	 */
	private function get_required_capability(): string {
		if ( null !== $this->cached_required_capability ) {
			return $this->cached_required_capability;
		}

		$this->cached_required_capability = SScribe_Capabilities::get_required();
		return $this->cached_required_capability;
	}

	/**
	 * Verify the session belongs to the current user.
	 *
	 * @param array  $session   Session data.
	 * @param string $session_id Session identifier.
	 * @return bool True if user owns the session.
	 */
	private function validate_session_ownership( array $session, string $session_id ): bool {
		$current_user_id = get_current_user_id();

		if ( ! isset( $session['user_id'] ) ) {
			$this->audit_log(
				'session_missing_user_id',
				array(
					'session_id'      => $session_id,
					'reason'          => 'missing_user_id',
					'attempting_user' => $current_user_id,
				)
			);
			return false;
		}

		if ( (int) $session['user_id'] !== $current_user_id ) {
			$this->audit_log(
				'session_access_denied',
				array(
					'session_id'      => $session_id,
					'session_user'    => $session['user_id'] ?? 'unknown',
					'attempting_user' => $current_user_id,
				)
			);
			return false;
		}

		// Log successful session access for complete audit trail.
		$this->audit_log(
			'session_access_ok',
			array(
				'session_id'      => $session_id,
				'session_user'    => $session['user_id'],
				'attempting_user' => $current_user_id,
			)
		);

		return true;
	}

	/**
	 * Start a new export session via AJAX.
	 */
	public function ajax_start_export(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! $this->check_rate_limit() ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$this->get_diagnostics()->self_heal();

		$memoryRaised = wp_raise_memory_limit( 'admin' );
		$this->logger->debug( 'Memory limit raised', array( 'result' => $memoryRaised ) );

		$this->audit_log( 'export_started' );
		$this->logger->debug( '=== START EXPORT ===' );

		$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		// Validate post_status against allowlist to prevent exporting trash/auto-draft content.
		$allowed_statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			$post_status = 'publish';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization via array_map on next line.
		$formats_raw   = isset( $_POST['formats'] ) ? wp_unslash( (array) $_POST['formats'] ) : array();
		$formats_input = array_map( 'sanitize_text_field', $formats_raw );
		$formats       = ! empty( $formats_input ) ? $formats_input : self::DEFAULT_FORMATS;

		$formats = array_values(
			array_filter(
				$formats,
				function ( $format ) {
					return \SScribe_Exporter_Factory::is_supported( $format );
				}
			)
		);

		if ( empty( $formats ) ) {
			$formats = self::DEFAULT_FORMATS;
		}

		$post_type        = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		$valid_post_types = array_values( get_post_types( array( 'public' => true ) ) );
		$valid_post_types = array_merge( $valid_post_types, array( 'any' ) );
		// Remove post types that don't make sense for content export.
		$valid_post_types = array_values( array_diff( $valid_post_types, array( 'attachment' ) ) );
		if ( ! in_array( $post_type, $valid_post_types, true ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => sprintf(
						/* translators: %s: Submitted post type. */
						__( 'Invalid post type "%s".', 'sscribe-export-site-pages' ),
						$post_type
					),
				),
				400
			);
		}

		$this->logger->debug(
			'Export params',
			array(
				'language'    => $language,
				'post_status' => $post_status,
				'post_type'   => $post_type,
				'formats'     => $formats,
			)
		);

		$user_id = get_current_user_id();

		if ( null !== $this->session->get_active_session_data( $user_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'You already have an export in progress. Please wait for it to complete or refresh the page.', 'sscribe-export-site-pages' ),
				),
				409
			);
		}

		if ( ! empty( $language ) && $this->collector->is_wpml_active() ) {
			$valid_languages = wp_list_pluck( $this->collector->get_wpml_languages(), 'code' );
			if ( ! in_array( $language, $valid_languages, true ) ) {
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Invalid language code specified.', 'sscribe-export-site-pages' ),
					),
					400
				);
			}
		}

		$page_ids = $this->collector->get_page_ids( $language, $post_status, $post_type );
		$total    = count( $page_ids );

		$current_lang = 'default';
		if ( $this->collector->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook, not our filter.
			$current_lang = apply_filters( 'wpml_current_language', null );
		}

		$this->logger->debug(
			'Page IDs retrieved',
			array(
				'total'              => $total,
				'language_requested' => $language,
				'post_status'        => $post_status,
				'current_wpml_lang'  => $current_lang ?? 'n/a',
				'ids_sample'         => array_slice( $page_ids, 0, 10 ),
				'memory_usage'       => size_format( memory_get_usage( true ) ),
				'memory_peak'        => size_format( memory_get_peak_usage( true ) ),
			)
		);

		if ( 0 === $total ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'No pages found matching the selected criteria.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}

		try {
			$temp_dir = $this->zip_handler->create_temp_dir();
			self::$cleanup_temp_dir    = $temp_dir;
			self::$cleanup_zip_handler = $this->zip_handler;
			self::$cleanup_logger      = $this->logger;
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Failed to create temp directory',
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Failed to initialize export directory. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		$session_id = $this->session->create(
			array(
				'temp_dir'          => $temp_dir,
				'total'             => $total,
				'processed'         => 0,
				'status'            => 'processing',
				'language'          => $language,
				'post_type'         => $post_type,
				'post_status'       => $post_status,
				'formats'           => $formats,
				'errors'            => array(),
				'structured_errors' => array(),
				'start_time'        => microtime( true ),
				'cancelled'         => false,
				'user_id'           => $user_id,
			)
		);

		// Store page_ids in separate transient to avoid bloating session autoload.
		$this->session->set_page_ids( $session_id, $page_ids );

		$this->logger->debug(
			'Session created',
			array(
				'session_id' => $session_id,
				'temp_dir'   => $temp_dir,
			)
		);

		if ( empty( $session_id ) ) {
			// Clean up orphaned temp directory that was created before session failed.
			if ( ! empty( $temp_dir ) && is_dir( $temp_dir ) ) {
				$this->zip_handler->delete_directory( $temp_dir );
			}
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Failed to create export session. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		// Post-creation TOCTOU defence: verify OUR session is the one tracked as active.
		// If a concurrent request created another session for the same user between
		// has_active_session() and create(), the transient will point to the other
		// session. In that case, clean up and reject instead of proceeding with both.
		if ( $user_id ) {
			$active_sid = get_transient( 'sscribe_active_sid_' . $user_id );
			if ( $active_sid !== $session_id && is_string( $active_sid ) && '0' !== $active_sid ) {
				$this->logger->warning(
					'Concurrent session creation detected — cleaning up duplicate',
					array(
						'user_id'         => $user_id,
						'our_session'     => $session_id,
						'winning_session' => $active_sid,
					)
				);
				$this->session->delete( $session_id );
				if ( ! empty( $temp_dir ) && is_dir( $temp_dir ) ) {
					$this->zip_handler->delete_directory( $temp_dir );
				}
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Another export was started. Please try again.', 'sscribe-export-site-pages' ),
					),
					409
				);
			}
		}

		$this->export_log = new SScribe_Export_Log( $session_id );
		$this->export_log->set_total_pages( $total );

		$this->logger->set_session_id( $session_id );

		$export_stats = new SScribe_Export_Stats();
		$export_stats->start_export(
			$session_id,
			$user_id,
			array(
				'total_pages' => $total,
				'formats'     => $formats,
			)
		);

		$memory_warning = $this->get_memory_warning( $total, $formats );

		$response = array(
			'session_id'     => $session_id,
			'total'          => $total,
			'batch_size'     => $this->batch_size,
			'message'        => sprintf(
				/* translators: %d: Number of pages found. */

				__( 'Found %d pages. Starting export...', 'sscribe-export-site-pages' ),
				$total
			),
		);
		if ( null !== $memory_warning ) {
			$response['memory_warning'] = $memory_warning;
		}

		$sscribe_is_debug = SSCRIBE_DEBUG;
		if ( $sscribe_is_debug ) {
			$response['debug_info'] = array(
				'page_ids_count'    => $total,
				'language'          => $language,
				'post_status'       => $post_status,
				'post_type'         => $post_type,
				'current_wpml_lang' => $current_lang ?? 'n/a',
				'temp_dir'          => basename( $temp_dir ),
				'session_type'      => $this->session->get_storage_type(),
				'memory_usage'      => size_format( memory_get_usage( true ) ),
				'php_version'       => PHP_VERSION,
			);
		}

		SScribe_AJAX_Guard::success( $response );
	}

	/**
	 * Process a batch of pages via AJAX.
	 */
	public function ajax_process_batch(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! $this->check_rate_limit() ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		// Self-heal at most once per minute (not on every batch) to avoid
		// expensive DB queries (clear_orphaned_locks, clear_stale_sessions)
		// and filesystem scans (clear_old_temp_files) on every batch iteration.
		$last_heal = get_transient( 'sscribe_last_self_heal' );
		if ( ! $last_heal || time() - (int) $last_heal > 60 ) {
			$this->get_diagnostics()->self_heal();
			set_transient( 'sscribe_last_self_heal', time(), 120 );
		}

		$max_time = (int) apply_filters( 'sscribe_max_execution_time', 150 );
		if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( $max_time ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$ob_level_before = ob_get_level();
		ob_start();
		// Initialize batch timing variables BEFORE the try block so they are always
		// defined when build_batch_response() is called (even if an exception fires
		// before $batch_start_time = microtime(true) inside the try).
		$batch_start_time = microtime( true );
		$batch_duration  = 0.0;
		try {
			$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
			$session    = $this->session->get( $session_id );

			$formats = isset( $session['formats'] ) ? $session['formats'] : self::DEFAULT_FORMATS;

			if ( in_array( 'pdf', $formats, true ) && function_exists( 'set_time_limit' ) ) {
				$pdf_max_time = (int) apply_filters( 'sscribe_pdf_max_execution_time', 150 );

				set_time_limit( $pdf_max_time ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$this->logger->debug(
				'Process batch called',
				array(
					'session_id'    => $session_id,
					'session_found' => ! empty( $session ),
				)
			);

			if ( ! $session ) {
				$lock_key = 'sscribe_lock_' . $session_id;
				delete_transient( $lock_key );
				$this->logger->debug(
					'ERROR: Session not found, cleared orphaned lock',
					array(
						'session_id' => $session_id,
					)
				);
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Export session expired or not found. Please start again.', 'sscribe-export-site-pages' ),
					),
					404
				);
			}

			$lock_ttl        = (int) apply_filters( 'sscribe_lock_ttl', 180 );
			$stale_threshold = (int) apply_filters( 'sscribe_lock_stale_threshold', 140 );

			// Validate ownership and session integrity BEFORE acquiring lock.
			// This avoids a false-lock window where the lock is held but the batch
			// is rejected — another process would unnecessarily back off.
			if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
					),
					403
				);
			}

			if ( ! $this->session->validate( $session_id ) ) {
				$this->logger->debug(
					'ERROR: Session validation failed',
					array(
						'session_keys'   => array_keys( $session ),
						'page_ids_count' => isset( $session['page_ids'] ) ? count( $session['page_ids'] ) : 'not set',
						'total'          => $session['total'] ?? 'not set',
					)
				);
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Export session data corrupted. Please start again.', 'sscribe-export-site-pages' ),
					),
					500
				);
			}

			$this->current_lock_token = $this->get_lock_manager()->acquire_lock( $session_id, $lock_ttl, $stale_threshold );

			if ( null === $this->current_lock_token ) {
				$this->logger->debug( 'Lock acquisition failed — another process holds the lock', array( 'session_id' => $session_id ) );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'status'  => 'locked',
						'retry'   => true,
						'message' => __( 'A batch is already processing. Please wait.', 'sscribe-export-site-pages' ),
					),
					429
				);
			}

			$lock_token = $this->current_lock_token;

			// Re-read session after acquiring lock to get fresh data.
			// The initial read at line 953 may have stale data if another
			// process was mid-update when we read it.
			$session = $this->session->get( $session_id );
			if ( null === $session ) {
				$this->get_lock_manager()->release_lock( $session_id, $lock_token );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Export session expired during lock acquisition.', 'sscribe-export-site-pages' ),
					),
					404
				);
			}

			if ( ! empty( $session['cancelled'] ) ) {
				$this->logger->debug( 'Export was cancelled' );
				$export_stats = new SScribe_Export_Stats();
				$export_stats->fail_export( $session_id, 'Export cancelled by user' );
				$this->restore_ob_level( $ob_level_before );
				$this->cleanup_cancelled_export( $session );
				$this->session->delete( $session_id );
				$this->release_lock( $session_id, $lock_token );
				SScribe_AJAX_Guard::error(
					array(
						'message'   => __( 'Export was cancelled.', 'sscribe-export-site-pages' ),
						'cancelled' => true,
					),
					499
				);
			}

			$page_ids          = $this->session->get_page_ids( $session_id );
			$processed         = $session['processed'];
			$total             = $session['total'];
			$temp_dir          = $session['temp_dir'];
			$errors            = isset( $session['errors'] ) ? $session['errors'] : array();
			$structured_errors = isset( $session['structured_errors'] ) && is_array( $session['structured_errors'] ) ? $session['structured_errors'] : array();
			$start_time        = isset( $session['start_time'] ) ? $session['start_time'] : microtime( true );
			$formats           = isset( $session['formats'] ) ? $session['formats'] : self::DEFAULT_FORMATS;
			// Get pause hint from previous batch to adjust batch size accordingly.
			$pause_hint = isset( $session['last_pause_reason'] ) ? $session['last_pause_reason'] : '';
			$this->optimize_batch_size( $formats, $pause_hint );
			// NOTE: Do NOT overwrite $session_id from session data - the POST value is canonical.
			// Using the POST value prevents session data tampering attacks.

			// Validate temp_dir is within allowed uploads directory to prevent path traversal attacks.
			$upload_dir        = wp_upload_dir();
			$allowed_temp_base = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports';
			// realpath() resolves symlinks and normalizes paths. Both base and temp must be
			// resolved to ensure the strpos comparison works on servers with symlinked dirs
			// (common on ServerAvatar, Cloudflare, OpenLiteSpeed, and managed hosting).
			// NOTE: If the allowed base directory doesn't exist yet, realpath() returns false.
			// In that case, we fall back to ensuring the temp_dir starts with the non-resolved
			// allowed_base path. This is safe because we create the dir via wp_mkdir_p() below.
			$real_allowed_base = realpath( $allowed_temp_base );
			// Recreate temp dir if it was deleted between batches (e.g., crashed PHP process).
			// This prevents false "corrupted session" errors on valid sessions.
			if ( ! empty( $temp_dir ) && ! is_dir( $temp_dir ) ) {
				wp_mkdir_p( $temp_dir );
			}
			$real_temp_dir = realpath( $temp_dir );
			// Reject unresolved paths to prevent path traversal attacks.
			// realpath() returns false if the path doesn't exist or can't be resolved.
			// Use trailing DIRECTORY_SEPARATOR to prevent /sscribe-exports-evil passing as /sscribe-exports.
			// If real_allowed_base is false (directory doesn't exist yet), use safe fallback comparison.
			$path_valid = true;
			if ( false === $real_temp_dir ) {
				// temp_dir doesn't exist and couldn't be created - this is a genuine error.
				$path_valid = false;
			} elseif ( false !== $real_allowed_base ) {
				// Both resolved - check containment with trailing separator.
				$safe_base = rtrim( $real_allowed_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
				if ( 0 !== strpos( $real_temp_dir, $safe_base ) ) {
					$path_valid = false;
				}
			} else {
				// real_allowed_base is false - directory doesn't exist yet.
				// Fall back to non-resolved path comparison (safe because we use DIRECTORY_SEPARATOR boundary).
				$safe_base = rtrim( $allowed_temp_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
				if ( 0 !== strpos( $real_temp_dir, $safe_base ) ) {
					$path_valid = false;
				}
			}
			if ( ! $path_valid ) {
				$this->release_lock( $session_id, $lock_token );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Export session corrupted (invalid temp directory path). Please start again.', 'sscribe-export-site-pages' ),
					),
					500
				);
			}

			if ( null === $this->export_log ) {
				$this->export_log = new SScribe_Export_Log( $session_id );
			}

			$this->logger->set_session_id( $session_id );

			$this->logger->debug(
				'Session state',
				array(
					'total_pages'  => $total,
					'processed'    => $processed,
					'remaining'    => $total - $processed,
					'error_count'  => count( $errors ),
					'memory_usage' => size_format( memory_get_usage( true ) ),
					'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
				)
			);

			$batch = array_slice( $page_ids, $processed, $this->batch_size );

			// Only prefetch images/child pages for batches of 3+ pages to avoid
			// disproportionate overhead for tiny final batches.
			if ( count( $batch ) >= 3 ) {
				$this->collector->get_featured_images_batch( $batch );
				$this->collector->get_child_pages_batch( $batch );
			}

			$this->logger->debug(
				'Batch details',
				array(
					'batch_size_setting' => $this->batch_size,
					'batch_count'        => count( $batch ),
					'batch_ids'          => $batch,
				)
			);

			if ( empty( $batch ) ) {
				$this->logger->debug( 'Batch empty, finalizing export' );
				$this->restore_ob_level( $ob_level_before );
				// Finalize UNDER lock to prevent a concurrent process from also
				// detecting an empty batch and racing to finalize the same session.
				try {
					$this->finalize_export( $session_id, $session, $lock_token );
				} finally {
					$this->release_lock( $session_id, $lock_token );
				}
				return;
			}

			$current_page_title      = '';
			$batch_start_time        = microtime( true );
			$memory_paused           = false;
			$timeout_paused          = false;
			$processed_in_this_batch = 0;
			$current_batch_page_id   = null;
			$paused_reason           = '';

			// Reset time limit immediately before batch work starts (not just at function
			// entry) so that any setup overhead (session reads, image prefetch, etc.)
			// does not eat into the per-batch budget.
			$batch_time_limit = (int) apply_filters( 'sscribe_max_execution_time', 150 );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( $batch_time_limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			// Suspend cache invalidation during bulk processing to avoid flooding
			// the object cache layer with clean_post_cache() calls on every page.
			wp_suspend_cache_invalidation( true );

			try {
				foreach ( $batch as $page_id ) {
					$current_batch_page_id = $page_id;

					$timeout_buffer = (int) apply_filters( 'sscribe_timeout_buffer_seconds', 15 );
					if ( $processed_in_this_batch > 0 && ! $this->is_time_available( $batch_start_time, $timeout_buffer ) ) {
						$timeout_paused = true;
						$this->logger->debug(
							'Timeout approaching, pausing batch for continuation',
							array(
								'elapsed_time'   => round( microtime( true ) - $batch_start_time, 2 ),
								'remaining_time' => round( $this->get_remaining_time( $batch_start_time ), 2 ),
								'processed'      => $processed,
								'total'          => $total,
							)
						);
						break;
					}

					$memory_threshold_mb = (int) apply_filters( 'sscribe_memory_threshold_mb', 10 );
					if ( $processed_in_this_batch > 0 && ! $this->is_memory_available( $memory_threshold_mb ) ) {
						$memory_paused = true;
						$this->logger->debug(
							'Memory threshold approaching limit, pausing batch',
							array(
								'memory_usage'   => size_format( memory_get_usage( true ) ),
								'memory_percent' => $this->get_memory_usage_percent(),
								'processed'      => $processed,
								'total'          => $total,
							)
						);
						break;
					}

					$page_start_time = microtime( true );
					$this->logger->debug(
						"Processing page ID: {$page_id}",
						array(
							'batch_index' => $processed + 1,
							'total'       => $total,
						)
					);

					if ( $this->export_log ) {
						$log_data = $this->export_log->get_log();
						if ( isset( $log_data['pages'][ $page_id ] ) && 'processing' === $log_data['pages'][ $page_id ]['status'] ) {
							$this->logger->debug(
								"Retrying page {$page_id} after previous crash",
								array(
									'page_id'   => $page_id,
									'memory_mb' => round( memory_get_usage( true ) / 1024 / 1024 ),
								)
							);
						}
					}

					do_action( 'sscribe_before_export_page', $page_id, $session['language'] ?? '' );

					if ( $this->export_log ) {
						$this->export_log->update_page_status( $page_id, 'processing' );
					}

					$page_data = $this->collector->get_page_data( $page_id );

					if ( ! $page_data ) {
						$error_msg = sprintf(
						/* translators: %d: Page ID. */

							__( 'Failed to collect data for page ID %d.', 'sscribe-export-site-pages' ),
							$page_id
						);
						$this->logger->debug(
							"ERROR: {$error_msg}",
							array(
								'page_id' => $page_id,
								'memory'  => size_format( memory_get_usage( true ) ),
							)
						);
						$errors[] = $error_msg;

						if ( $this->export_log ) {
							$this->export_log->log_page_failure( $page_id, $error_msg );
							$this->export_log->flush();
						}

						++$processed;
						continue;
					}

					$current_page_title = $page_data['title'];

					if ( $this->export_log ) {
						$this->export_log->log_page_start( $page_id, $page_data['title'], $page_data['slug'] ?? '' );
						$this->export_log->flush();
					}

					$this->logger->debug(
						'Page data collected',
						array(
							'title' => $current_page_title,
							'id'    => $page_id,
							'slug'  => $page_data['slug'] ?? 'n/a',
							'lang'  => $page_data['language'] ?? 'n/a',
						)
					);

					$page_index         = $processed + 1;
					$export_success     = false;
					$export_errors      = array();
					$successful_formats = array();

					$pre_export_memory_mb = (int) apply_filters( 'sscribe_min_memory_per_page_mb', 64 );
					if ( ! $this->is_memory_available( $pre_export_memory_mb ) ) {
						$error_msg = sprintf(
						/* translators: %d: Page ID. */

							__( 'Skipped page %d - insufficient memory to proceed.', 'sscribe-export-site-pages' ),
							$page_id
						);
						$this->logger->debug(
							"Skipped page due to memory: {$page_id}",
							array(
								'page_id'      => $page_id,
								'memory_usage' => size_format( memory_get_usage( true ) ),
								'memory_limit' => ini_get( 'memory_limit' ),
							)
						);
						$errors[] = $error_msg;
						if ( $this->export_log ) {
								$this->export_log->log_page_failure( $page_id, 'Insufficient memory for export' );
								$this->export_log->flush();
						}
						++$processed;
						continue;
					}

					try {
						$page_data['_batch_start_time'] = $batch_start_time;
						$dispatch_result                = $this->dispatch_formats( $page_data, $temp_dir, $page_index, $total, $formats, $session_id, $session, $page_id );
						$export_success                 = $dispatch_result['export_success'];
						$successful_formats             = $dispatch_result['successful_formats'];
						$export_errors                  = $dispatch_result['export_errors'];
					} catch ( \Throwable $e ) {

						$error_msg = sprintf(
						/* translators: %s: Error message. */

							__( 'Critical error: %s', 'sscribe-export-site-pages' ),
							$e->getMessage()
						);
						$export_errors[] = array(
							'format'   => 'SYSTEM',
							'message'  => $error_msg,
							'category' => 'critical_error',
							'context'  => array(
								'page_id'         => $page_id,
								'page_title'      => $page_data['title'] ?? '',
								'exception_class' => get_class( $e ),
								// NOTE: exception_file/line removed from frontend response - only keep in server logs.
								'memory_usage'    => size_format( memory_get_usage( true ) ),
								'memory_peak'     => size_format( memory_get_peak_usage( true ) ),
								'memory_limit'    => ini_get( 'memory_limit' ),
							),
						);

						$this->logger->error(
							'Critical error during page export',
							array(
								'page_id'      => $page_id,
								'error'        => $e->getMessage(),
								'memory_usage' => size_format( memory_get_usage( true ) ),
								'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
							)
						);
					}

					$page_duration = round( microtime( true ) - $page_start_time, 3 );

					if ( ! $export_success ) {
						$string_export_errors = array();
						$error_msg            = sprintf(
						/* translators: %s: Page title. */

							__( 'Failed to generate exports for "%s".', 'sscribe-export-site-pages' ),
							$page_data['title']
						);

						foreach ( $export_errors as $export_error ) {
							$string_export_errors[] = sprintf(
								'%s: %s',
								$export_error['format'],
								$export_error['message'] ?? ''
							);
						}

						$errors[] = $error_msg . ' ' . implode( ', ', $string_export_errors );

						if ( $this->export_log ) {
							$this->export_log->log_page_failure( $page_id, implode( '; ', $string_export_errors ), $formats );
						}

						$detailed_errors = array();
						foreach ( $export_errors as $format_error ) {
							$fmt       = strtolower( $format_error['format'] );
							$err_msg   = $format_error['message'] ?? '';
							$context   = $format_error['context'];
							$diagnosis = $this->get_diagnostics()->diagnose_page_error( $page_id, $fmt, $err_msg, $context );

							if ( ! empty( $format_error['category'] ) && 'unknown' !== $format_error['category'] ) {
								$diagnosis['category'] = $format_error['category'];
							}

							if ( ! empty( $context['fix_steps'] ) && empty( $diagnosis['fix'] ) ) {
								$diagnosis['fix'] = $context['fix_steps'];
							}

							$detailed_errors[] = $diagnosis;
						}

						$structured_errors[] = array(
							'page_id'     => $page_id,
							'page_title'  => $page_data['title'],
							'message'     => $error_msg,
							'errors'      => $export_errors,
							'diagnostics' => $detailed_errors,
							'time'        => current_time( 'mysql' ),
						);

						$this->logger->debug(
							'ERROR: Export failed',
							array(
								'page_id'        => $page_id,
								'title'          => $page_data['title'],
								'duration_sec'   => $page_duration,
								'errors'         => $export_errors,
								'diagnosis'      => $detailed_errors,
								'memory_at_fail' => size_format( memory_get_usage( true ) ),
							)
						);
					} elseif ( $this->export_log ) {
						$this->export_log->log_page_success( $page_id, $successful_formats );
					}

					do_action( 'sscribe_after_export_page', $page_id, $formats, $export_success );

					if ( function_exists( 'clean_post_cache' ) ) {
						clean_post_cache( $page_id );
					}

					$page_data = null;

					++$processed;
					++$processed_in_this_batch;

					// Run garbage collection after every page to reclaim memory promptly,
					// not only every 10 pages as before.
					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}

					$current_session = $this->session->get( $session_id );
					if ( ! empty( $current_session['cancelled'] ) ) {
						$this->logger->debug(
							'Mid-batch cancellation detected',
							array(
								'session_id' => $session_id,
								'processed'  => $processed,
							)
						);
						break;
					}
				}
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'Batch processing failed uncaught',
					array(
						'error'       => $e->getMessage(),
						'page_id'     => $current_batch_page_id ?? 'unknown',
						'processed'   => $processed,
						'memory_used' => size_format( memory_get_usage( true ) ),
					)
				);
				// Always log to PHP error_log as fallback regardless of logger state.
				if ( SScribe_Logger::is_logging_enabled() ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback error logging when logger is available.
						'SScribe batch error: ' . $e->getMessage() . ' | Page: ' . ( $current_batch_page_id ?? 'unknown' )
					);
				}
			} finally {
				// Restore cache invalidation after bulk processing.
				wp_suspend_cache_invalidation( false );

				// Persist session state BEFORE releasing lock to prevent race condition.
				// Between lock release and session update, another process could acquire
				// the lock and read stale session data, causing duplicate processing.
				$batch_duration = microtime( true ) - $batch_start_time;

				$this->logger->debug(
					'Batch completed',
					array(
						'processed_now'      => max( 0, $processed - $session['processed'] ),
						'batch_duration_sec' => round( $batch_duration, 3 ),
						'total_processed'    => $processed,
						'total_errors'       => count( $errors ),
						'memory_usage'       => size_format( memory_get_usage( true ) ),
						'memory_peak'        => size_format( memory_get_peak_usage( true ) ),
					)
				);

				$total_errors              = count( $errors );
				$total_structured_errors   = count( $structured_errors );
				$errors_trimmed            = $total_errors > self::MAX_STORED_ERRORS;
				$structured_errors_trimmed = $total_structured_errors > self::MAX_STORED_ERRORS;

				if ( $errors_trimmed ) {
					$trimmed_count = $total_errors - self::MAX_STORED_ERRORS;
					$errors        = array_slice( $errors, 0, self::MAX_STORED_ERRORS - 1 );
					$errors[]      = sprintf(
					/* translators: %d: Number of additional errors not stored. */
						__( '... and %d more errors occurred (see export log for full details).', 'sscribe-export-site-pages' ),
						$trimmed_count
					);
					$this->logger->warning(
						'Error array capped to prevent memory exhaustion',
						array(
							'stored'  => self::MAX_STORED_ERRORS,
							'trimmed' => $trimmed_count,
							'total'   => $total_errors,
						)
					);
				}

				if ( $structured_errors_trimmed ) {
					$trimmed_count       = $total_structured_errors - self::MAX_STORED_ERRORS;
					$structured_errors   = array_slice( $structured_errors, 0, self::MAX_STORED_ERRORS - 1 );
					$structured_errors[] = array(
						'page_id'     => 0,
						'page_title'  => '...',
						'message'     => sprintf(
							/* translators: %d: Number of additional errors not stored. */
							__( '... and %d more errors occurred.', 'sscribe-export-site-pages' ),
							$trimmed_count
						),
						'errors'      => array(),
						'diagnostics' => array(),
						'time'        => current_time( 'mysql' ),
					);
					$this->logger->warning(
						'Structured error array capped to prevent memory exhaustion',
						array(
							'stored'  => self::MAX_STORED_ERRORS,
							'trimmed' => $trimmed_count,
							'total'   => $total_structured_errors,
						)
					);
				}

				// Derive $paused_reason inside finally BEFORE building $update_data
				// so the hint is correctly persisted for the next batch call.
				$paused_reason = $memory_paused ? 'memory' : ( $timeout_paused ? 'timeout' : '' );

				$update_data = array(
					'processed'         => $processed,
					'errors'            => $errors,
					'structured_errors' => $structured_errors,
					'start_time'        => $start_time,
					'last_pause_reason' => $paused_reason,
					// Store exact counts BEFORE trim to avoid off-by-one errors when the
					// synthetic "... and N more" message is appended to the errors array.
					'error_count'        => $total_errors,
					'structured_count'   => $total_structured_errors,
				);

				// Preserve cancelled flag if set (mid-batch cancellation at line 1431 breaks
				// from loop but finally still runs - must not clear the flag on next batch call).
				if ( ! empty( $session['cancelled'] ) ) {
					$update_data['cancelled'] = true;
				}

				$format_keys = array( 'format_time_docx', 'format_time_pdf', 'format_time_html', 'format_time_markdown', 'format_size_docx', 'format_size_pdf', 'format_size_html', 'format_size_markdown', 'format_pages_docx', 'format_pages_pdf', 'format_pages_html', 'format_pages_markdown' );
				// Also persist any custom format keys that are not in the hardcoded list
				// (e.g. format_time_epub) to ensure custom formats are preserved across batches.
				foreach ( $session as $key => $value ) {
					if ( is_string( $key ) && ( str_starts_with( $key, 'format_time_' ) || str_starts_with( $key, 'format_size_' ) || str_starts_with( $key, 'format_pages_' ) ) && ! in_array( $key, $format_keys, true ) ) {
						if ( str_starts_with( $key, 'format_time_' ) || str_starts_with( $key, 'format_size_' ) ) {
							$update_data[ $key ] = (float) ( $value ?? 0 );
						} else {
							$update_data[ $key ] = (int) ( $value ?? 0 );
						}
					}
				}
				foreach ( $format_keys as $key ) {
					if ( isset( $session[ $key ] ) ) {
						// Coerce to expected type: format_time/size are float, format_pages is int.
						if ( str_starts_with( $key, 'format_time_' ) || str_starts_with( $key, 'format_size_' ) ) {
							$update_data[ $key ] = (float) ( $session[ $key ] ?? 0 );
						} else {
							$update_data[ $key ] = (int) ( $session[ $key ] ?? 0 );
						}
					}
				}

				$update_result = $this->session->update( $session_id, $update_data );
				if ( ! $update_result ) {
					$this->logger->error(
						'Session update failed',
						array(
							'session_id' => $session_id,
							'data_keys'  => array_keys( $update_data ),
						)
					);
				}

				if ( $this->export_log ) {
					$this->export_log->flush();
				}

				$this->release_lock( $session_id, $lock_token );
				$this->restore_ob_level( $ob_level_before );
				$this->collector->clear_page_caches();
			}

			$percentage = ( $total > 0 && $processed > 0 ) ? round( ( $processed / $total ) * 100 ) : 0;
			$is_done    = ( $processed >= $total );

			// Detect mid-batch cancellation: re-read session to check if cancelled flag was
			// set during the foreach loop. If so, return cancelled=true so the frontend
			// stops polling immediately instead of scheduling another batch request.
			$mid_batch_cancelled = false;
			if ( ! $is_done ) {
				$session_snapshot = $this->session->get( $session_id );
				if ( ! empty( $session_snapshot['cancelled'] ) ) {
					$mid_batch_cancelled = true;
				}
			}

			$this->logger->debug(
				'Progress check',
				array(
					'processed'  => $processed,
					'total'      => $total,
					'percentage' => $percentage,
					'is_done'    => $is_done,
				)
			);

			$elapsed           = microtime( true ) - $start_time;
			$avg_time_per_page = $processed > 0 ? $elapsed / $processed : 0;
			$remaining_pages   = $total - $processed;

			if ( $is_done ) {
				$this->logger->debug( 'All pages processed, finalizing' );
				$update_data['processed']         = $processed;
				$update_data['errors']            = $errors;
				$update_data['structured_errors'] = $structured_errors;
				$update_data['status']            = 'finalizing';
				if ( ! $this->session->update( $session_id, $update_data ) ) {
					$this->logger->error( 'Final session update failed', array( 'session_id' => $session_id ) );
				}

				$error_diagnostics = array();
				if ( ! empty( $structured_errors ) ) {
					$error_diagnostics = $this->build_error_diagnostics_payload( $structured_errors, $errors );
				}

				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::success(
					array(
						'status'            => 'finalizing',
						'processed'         => $processed,
						'total'             => $total,
						'percentage'        => 95,
						'message'           => __( 'Packaging files into ZIP archive...', 'sscribe-export-site-pages' ),
						'time_remaining'    => 0,
						'error_diagnostics' => $error_diagnostics ? $error_diagnostics : null,
					)
				);
				return; // Ensure no further code executes after success response.
			}

			$response = $this->build_batch_response(
				$processed,
				$total,
				$current_page_title,
				$avg_time_per_page,
				$memory_paused,
				$timeout_paused,
				$structured_errors,
				$errors,
				$batch_duration,
				$batch_start_time
			);

			// Notify frontend when export was cancelled mid-batch so it stops polling.
			if ( $mid_batch_cancelled ) {
				$response['cancelled'] = true;
			}

			SScribe_AJAX_Guard::success( $response );
		} finally {
			$this->restore_ob_level( $ob_level_before );
		}
	}

	/**
	 * Build the batch progress response array.
	 *
	 * @param int    $processed         Number of pages processed.
	 * @param int    $total             Total pages.
	 * @param string $current_page_title Current page title.
	 * @param float  $avg_time_per_page Average time per page.
	 * @param bool   $memory_paused     Whether paused due to memory.
	 * @param bool   $timeout_paused    Whether paused due to timeout.
	 * @param array  $structured_errors Structured errors array.
	 * @param array  $errors            Simple errors array.
	 * @param float  $batch_duration    Duration of batch in seconds.
	 * @param float  $batch_start_time  Start time of batch.
	 * @return array Response array.
	 */
	private function build_batch_response( int $processed, int $total, string $current_page_title, float $avg_time_per_page, bool $memory_paused, bool $timeout_paused, array $structured_errors, array $errors, float $batch_duration, float $batch_start_time ): array {
		$percentage      = ( $total > 0 && $processed > 0 ) ? round( ( $processed / $total ) * 100 ) : 0;
		$remaining_pages = $total - $processed;
		$time_remaining  = max( 0, round( $avg_time_per_page * $remaining_pages ) );

		$paused_reason_text = '';
		if ( $memory_paused ) {
			$paused_reason_text = 'memory';
		} elseif ( $timeout_paused ) {
			$paused_reason_text = 'timeout';
		}

		$response = array(
			'status'         => 'processing',
			'processed'      => $processed,
			'total'          => $total,
			'percentage'     => $percentage,
			'current_page'   => $current_page_title,
			'time_remaining' => $time_remaining,
			'memory_paused'  => $memory_paused,
			'timeout_paused' => $timeout_paused,
			'paused_reason'  => $paused_reason_text,
			'message'        => $memory_paused
				? sprintf(
					/* translators: 1: Current page number, 2: Total pages. */

					__( 'Processing %1$d of %2$d pages... (Paused briefly to manage memory - will resume automatically)', 'sscribe-export-site-pages' ),
					$processed,
					$total
				)
				: ( $timeout_paused
					? sprintf(
						/* translators: 1: Current page number, 2: Total pages. */

						__( 'Processing %1$d of %2$d pages... (Paused to prevent timeout - will resume automatically)', 'sscribe-export-site-pages' ),
						$processed,
						$total
					)
					: sprintf(
						/* translators: 1: Current page number, 2: Total pages. */

						__( 'Processing %1$d of %2$d pages...', 'sscribe-export-site-pages' ),
						$processed,
						$total
					)
				),
		);

		if ( ! empty( $structured_errors ) ) {
			$response['error_diagnostics'] = $this->build_error_diagnostics_payload( $structured_errors, $errors );
		}

		if ( $memory_paused ) {
			$response['resume_guidance'] = __( 'The export paused briefly to manage server memory. It will resume automatically. No action needed.', 'sscribe-export-site-pages' );
		} elseif ( $timeout_paused ) {
			$response['resume_guidance'] = __( 'The export paused briefly to prevent a server timeout. It will resume automatically. No action needed.', 'sscribe-export-site-pages' );
		}

		$sscribe_is_debug = SSCRIBE_DEBUG;
		if ( $sscribe_is_debug ) {
			$response['debug_info'] = array(
				'batch_size'          => $this->batch_size,
				'errors_so_far'       => count( $errors ),
				'last_batch_duration' => round( $batch_duration, 3 ),
				'memory_usage'        => size_format( memory_get_usage( true ) ),
				'memory_peak'         => size_format( memory_get_peak_usage( true ) ),
				'avg_time_per_page'   => round( $avg_time_per_page, 3 ),
				'elapsed_time'        => round( microtime( true ) - $batch_start_time, 2 ),
			);
		}

		return $response;
	}

	/**
	 * Restore output buffer level to target.
	 *
	 * @param int $target_level Target buffer level.
	 */
	private function restore_ob_level( int $target_level ): void {
		while ( ob_get_level() > $target_level ) {
			ob_end_clean();
		}
	}

	/**
	 * Finalize the export via AJAX.
	 */
	public function ajax_finalize_export(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
		}

		if ( ! $this->check_rate_limit() ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				),
				429
			);
		}

		$session_id = isset( $_POST['session_id'] )
			? sanitize_text_field( wp_unslash( $_POST['session_id'] ) )
			: '';

		if ( empty( $session_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}

		$session = $this->session->get( $session_id );

		if ( ! $session ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'not_finalizing',
					'message' => __( 'Export session not found. Please start again.', 'sscribe-export-site-pages' ),
				),
				404
			);
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		$status = $session['status'] ?? '';
		$temp_dir = $session['temp_dir'] ?? '';

		// Handle stuck sessions: if status is 'pending' but the temp directory no longer
		// exists (cleaned up after a crash), auto-reset the session and return 410 Gone
		// instead of a confusing 409 Conflict.
		if ( 'pending' === $status && ! empty( $temp_dir ) && ! is_dir( $temp_dir ) ) {
			$this->session->update(
				$session_id,
				array(
					'status' => 'failed',
					'error'  => 'Previous export failed and was automatically cleared.',
				)
			);
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'session_cleared',
					'message' => __( 'Previous export failed and was cleared. Please try again.', 'sscribe-export-site-pages' ),
				),
				410
			);
			return;
		}

		if ( 'finalizing' !== $status && 'completing' !== $status ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'not_finalizing',
					'message' => __( 'Export is not in the finalizing state.', 'sscribe-export-site-pages' ),
				),
				409
			);
			return;
		}

		// Lock TTL: use dynamic value based on file count in temp dir.
			// Large exports with many files need more time for ZIP creation.
			// 2 seconds per file with 120s minimum and 600s maximum.
			$file_count_raw = glob( trailingslashit( $session['temp_dir'] ) . '*' );
			$file_count     = is_array( $file_count_raw ) ? count( $file_count_raw ) : 0;
			$lock_ttl      = max( 120, min( 600, $file_count * 2 ) );

			$lock_token = $this->get_lock_manager()->acquire_lock( $session_id, $lock_ttl, (int) ( $lock_ttl * 0.85 ) );
		if ( null === $lock_token ) {
			$this->logger->debug( 'Finalize race detected — another request holds the lock', array( 'session_id' => $session_id ) );
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'race_detected',
					'message' => __( 'Export is being finalized by another request. Please wait.', 'sscribe-export-site-pages' ),
				),
				409
			);
			return;
		}

		if ( 'completing' === $status ) {
			// Check if the completing timestamp is too old - if so, the previous
			// finalize may have crashed and we should allow retry.
			$completing_since = $session['completing_since'] ?? 0;
			if ( $completing_since > 0 && ( time() - $completing_since ) < $lock_ttl ) {
				$this->release_lock( $session_id, $lock_token );
				SScribe_AJAX_Guard::error(
					array(
						'code'    => 'already_completing',
						'message' => __( 'Export is already being finalized. Please wait.', 'sscribe-export-site-pages' ),
					),
					409
				);
				// No return needed — Guard::error() always exits.
			}
			// completing_since is too old (> lock_ttl), treat as stale and allow retry.
		}

		try {
			$this->finalize_export( $session_id, $session, $lock_token );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Finalize export threw exception',
				array(
					'session_id'      => $session_id,
					'error'           => $e->getMessage(),
					'exception_class' => get_class( $e ),
					'file'            => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);
			$this->release_lock( $session_id, $lock_token );
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'finalize_exception',
					'message' => __( 'Export finalization failed. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}
	}

	/**
	 * Complete the export process and package files.
	 *
	 * @param string      $session_id Export session ID.
	 * @param array       $session    Session data.
	 * @param string|null $lock_token Optional lock token to release on completion.
	 */
	private function finalize_export( string $session_id, array $session, ?string $lock_token = null ): void {
		// Clear shutdown cleanup tracking — finalize_export handles temp_dir cleanup itself.
		self::$cleanup_temp_dir    = null;
		self::$cleanup_zip_handler = null;
		self::$cleanup_logger      = null;

		if ( null === $this->export_log ) {
			$this->export_log = new SScribe_Export_Log( $session_id );
		}

		$export_stats = new SScribe_Export_Stats();

		if ( ! $this->session->update(
			$session_id,
			array(
				'status'           => 'completing',
				'completing_since' => time(),
			)
		) ) {
			$this->logger->warning( 'Session status update failed', array( 'session_id' => $session_id ) );
		}
		$session['status']           = 'completing';
		$session['completing_since'] = time();

		$this->logger->set_session_id( $session_id );

		if ( function_exists( 'set_time_limit' ) ) {

			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			set_time_limit( 300 );
		}

		$this->logger->debug(
			'=== FINALIZE EXPORT ===',
			array(
				'session_id'   => $session_id,
				'total'        => $session['total'],
				'errors_count' => count( $session['errors'] ?? array() ),
				'errors'       => $session['errors'] ?? array(),
				'processed'    => $session['processed'] ?? 'not set',
			)
		);

		try {
			$has_language = ! empty( $session['language'] );
			$lang_code    = $has_language ? $session['language'] : '';
			$site_slug    = sanitize_file_name( get_bloginfo( 'name' ) );
			$site_slug    = strtolower( substr( $site_slug, 0, 20 ) );

			if ( empty( $site_slug ) ) {
				$site_slug = 'export';
			}

			$lang_name = $has_language ? strtoupper( $lang_code ) : 'All Languages';
			$flag_url  = '';
			if ( $this->collector->is_wpml_active() && $has_language ) {
				$wpml_languages = $this->collector->get_wpml_languages();
				foreach ( $wpml_languages as $wl ) {
					if ( isset( $wl['code'] ) && $wl['code'] === $lang_code ) {
						$lang_name = $wl['name'] ?? strtoupper( $lang_code );
						$flag_url  = $wl['flag_url'] ?? '';
						break;
					}
				}
			}
			$lang_metadata = array(
				'lang_code' => $has_language ? $lang_code : 'all',
				'lang_name' => $lang_name,
				'flag_url'  => $flag_url,
			);

			$formats = isset( $session['formats'] ) ? $session['formats'] : self::DEFAULT_FORMATS;

			$format_suffix = count( $formats ) > 1 ? 'ALL-FORMATS' : strtoupper( $formats[0] );
			$timestamp     = gmdate( 'Y-m-d-His' );
			$lang_suffix   = $has_language ? strtoupper( $lang_code ) : 'ALL-LANGS';

			$zip_name = sprintf(
				'%s-%s-%s-%s-%s',
				$site_slug,
				$timestamp,
				$lang_suffix,
				$format_suffix,
				substr( bin2hex( self::secure_random_bytes( 3 ) ), 0, 6 )
			);

			$this->logger->debug(
				'Creating ZIP',
				array(
					'zip_name' => $zip_name,
					'temp_dir' => $session['temp_dir'],
					'language' => $lang_code,
					'formats'  => $formats,
				)
			);

			$files_before = array();
			foreach ( $formats as $format ) {
				$ext   = 'markdown' === $format ? 'md' : $format;
				$found = glob( trailingslashit( $session['temp_dir'] ) . '*.' . $ext );
				if ( $found ) {
					$files_before[ $format ] = count( $found );
				}
			}
			$this->logger->debug( 'Files in temp dir BEFORE ZIP', $files_before );

			$total_generated_files = array_sum( $files_before );
			if ( 0 === $total_generated_files ) {
				$this->logger->debug(
					'No files generated — all pages likely failed',
					array(
						'temp_dir' => $session['temp_dir'],
						'formats'  => $formats,
					)
				);

				if ( $this->export_log ) {
					$this->export_log->mark_failed( 'No files generated — all pages failed' );
					$this->export_log->flush();
				}

				$export_stats->fail_export( $session_id, 'No files generated' );

				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				$this->release_lock( $session_id, $lock_token );
				$this->get_diagnostics()->self_heal();

				SScribe_AJAX_Guard::error(
					array(
						'message'   => __( 'No files were generated — all pages failed to export. Check the export format selected and try again.', 'sscribe-export-site-pages' ),
						'guidance'  => __( 'If you selected PDF format, verify that the PDF export works before running a bulk export. Try exporting a single page first.', 'sscribe-export-site-pages' ),
						'fix_steps' => array(
							__( 'Select DOCX, HTML, or Markdown format instead of PDF-only.', 'sscribe-export-site-pages' ),
							__( 'If PDF is needed, contact your plugin developer to verify PDF is configured correctly.', 'sscribe-export-site-pages' ),
						),
					),
					500
				);
			}

			$zip_path = $this->zip_handler->create_zip( $session['temp_dir'], $zip_name, $formats, $has_language, $lang_metadata, $session_id );

			if ( ! $zip_path ) {
				$this->logger->debug(
					'ERROR: Failed to create ZIP',
					array(
						'temp_dir'       => $session['temp_dir'],
						'expected_files' => $files_before,
					)
				);

				if ( $this->export_log ) {
					$this->export_log->mark_failed( 'Failed to create ZIP package' );
					$this->export_log->flush();
				}

				$export_stats->fail_export( $session_id, 'Failed to create ZIP package' );

				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				$this->release_lock( $session_id, $lock_token );
				$this->get_diagnostics()->self_heal();

				$zip_error = array(
					'page_id'     => 0,
					'page_title'  => __( 'ZIP package', 'sscribe-export-site-pages' ),
					'message'     => __( 'Failed to create ZIP package. Please try again.', 'sscribe-export-site-pages' ),
					'errors'      => array(
						array(
							'format'   => 'ZIP',
							'message'  => __( 'Failed to create ZIP package.', 'sscribe-export-site-pages' ),
							'category' => 'zip_creation',
							'context'  => array(
								'temp_dir'       => $session['temp_dir'],
								'expected_files' => $files_before,
							),
						),
					),
					'diagnostics' => array(
						$this->get_diagnostics()->diagnose_page_error(
							0,
							'zip',
							'Failed to create ZIP package.',
							array(
								'temp_dir'       => $session['temp_dir'],
								'expected_files' => $files_before,
							)
						),
					),
					'time'        => current_time( 'mysql' ),
				);

				$error_diagnostics = $this->build_error_diagnostics_payload( array( $zip_error ), $session['errors'] ?? array() );

				$error_response = array(
					'message'           => __( 'Failed to create ZIP package. Please try again.', 'sscribe-export-site-pages' ),
					'guidance'          => $error_diagnostics['guidance'],
					'fix_steps'         => $error_diagnostics['fix_steps'],
					'technical'         => $error_diagnostics['technical'],
					'error_diagnostics' => $error_diagnostics,
				);

				$sscribe_is_debug = SSCRIBE_DEBUG;
				if ( $sscribe_is_debug ) {
					$error_response['debug_info'] = array(
						'temp_dir_exists' => is_dir( $session['temp_dir'] ),
						'files_in_temp'   => count( $files_before ),
					);
				}

				SScribe_AJAX_Guard::error( $error_response, 500 );
			}

			$zip             = new ZipArchive();
			$zip_open        = $zip->open( $zip_path );
			$total_files_zip = 0;
			if ( true === $zip_open ) {
				// Count only actual file entries (not directory entries which end with '/').
				$total_files_zip = 0;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
				$zip_file_count = $zip->numFiles;
				for ( $i = 0; $i < $zip_file_count; $i++ ) {
					$stat = $zip->statIndex( $i );
					if ( $stat && substr( $stat['name'], -1 ) !== '/' ) {
						++$total_files_zip;
					}
				}
				$zip->close();
			}

			// Cross-check: warn if ZIP has fewer files than expected from temp dir.
			$expected_file_count = array_sum( $files_before );
			if ( $expected_file_count > 0 && $total_files_zip < $expected_file_count ) {
				$this->logger->warning(
					'ZIP may be incomplete',
					array(
						'expected_files' => $expected_file_count,
						'actual_files'   => $total_files_zip,
						'zip_path'       => $zip_path,
					)
				);
			}

			if ( $total_files_zip <= 0 ) {
				$this->logger->error(
					'ZIP created but contains no files',
					array(
						'zip_path'     => $zip_path,
						'files_before' => $files_before,
					)
				);

				if ( $this->export_log ) {
					$this->export_log->mark_failed( 'ZIP package is empty' );
					$this->export_log->flush();
				}

				if ( file_exists( $zip_path ) ) {
					wp_delete_file( $zip_path );
				}

				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				$this->release_lock( $session_id, $lock_token );

				SScribe_AJAX_Guard::error(
					array(
						'message'   => __( 'Export packaging failed — the ZIP archive was empty. Please try again.', 'sscribe-export-site-pages' ),
						'guidance'  => __( 'This can happen if temporary export files were deleted before packaging completed. Click "Try Again" to restart the export.', 'sscribe-export-site-pages' ),
						'fix_steps' => array(
							__( 'Click "Try Again" to restart the export.', 'sscribe-export-site-pages' ),
							__( 'If this keeps happening, check that your server has enough disk space.', 'sscribe-export-site-pages' ),
						),
					),
					500
				);
			}

			$this->logger->debug(
				'ZIP created successfully',
				array(
					'zip_path' => $zip_path,
					'zip_size' => function_exists( 'wp_filesize' ) && file_exists( $zip_path )
						? size_format( wp_filesize( $zip_path ) )
						: __( 'unknown', 'sscribe-export-site-pages' ),
				)
			);

			$this->logger->debug(
				'ZIP contents verified',
				array(
					'total_files_in_zip' => $total_files_zip,
					'expected_pages'     => $session['total'],
				)
			);

			if ( $this->export_log ) {
				$this->export_log->mark_complete( $zip_path, $total_files_zip );
				$this->export_log->flush();
			}

			$duration         = microtime( true ) - ( $session['start_time'] ?? microtime( true ) );
			$zip_size         = function_exists( 'wp_filesize' ) && file_exists( $zip_path ) ? (int) wp_filesize( $zip_path ) : 0;
			// Use the pre-trimmed error count stored in the session to avoid off-by-one
			// errors from the synthetic "... and N more" message appended after trimming.
			$error_count      = (int) ( $session['error_count'] ?? count( $session['errors'] ?? array() ) );
			$successful_pages = max( 0, ( $session['total'] ?? 0 ) - $error_count );
			$export_stats->complete_export(
				$session_id,
				array(
					'successful_pages' => $successful_pages,
					'failed_pages'     => $error_count,
					'duration'         => $duration,
					'file_size_mb'     => $zip_size / 1048576,
				)
			);
			$structured_errors = isset( $session['structured_errors'] ) && is_array( $session['structured_errors'] ) ? $session['structured_errors'] : array();

			$download_url = $this->zip_handler->get_ajax_download_url( basename( $zip_path ) );

			$this->logger->debug(
				'Export complete',
				array(
					'download_url'   => $download_url,
					'total_time_sec' => microtime( true ) - ( $session['start_time'] ?? microtime( true ) ),
				)
			);

			$this->audit_log(
				'export_completed',
				array(
					'total_pages'  => $session['total'],
					'errors'       => $error_count,
					'filename'     => basename( $zip_path ),
					'duration_sec' => microtime( true ) - ( $session['start_time'] ?? microtime( true ) ),
				)
			);

			$formats    = isset( $session['formats'] ) ? $session['formats'] : self::DEFAULT_FORMATS;
			$session_pt = $session['post_type'] ?? 'page';

			// Re-read session from DB to get latest format_time values in case batch
			// request timed out before persisting but finalize is running now.
			$fresh_session = $this->session->get( $session_id );
			if ( ! $fresh_session ) {
				$fresh_session = array();
			}

			foreach ( $formats as $fmt ) {
				$format_time_key  = 'format_time_' . $fmt;
				$format_size_key  = 'format_size_' . $fmt;
				$format_pages_key = 'format_pages_' . $fmt;

				$elapsed_seconds = (float) ( $fresh_session[ $format_time_key ] ?? $session[ $format_time_key ] ?? 0 );
				$total_bytes     = (int) ( $fresh_session[ $format_size_key ] ?? $session[ $format_size_key ] ?? 0 );
				$pages_exported  = (int) ( $fresh_session[ $format_pages_key ] ?? $session[ $format_pages_key ] ?? 0 );
				$total_mb        = $total_bytes / 1048576;

				if ( $pages_exported > 0 && $elapsed_seconds > 0 ) {
					$this->get_adaptive_metrics()->save( $fmt, $pages_exported, $elapsed_seconds, $total_mb, $session_pt );
				}
			}

			$log_summary       = $this->export_log ? $this->export_log->get_summary() : array();
			$error_diagnostics = $this->build_error_diagnostics_payload( $structured_errors, $session['errors'] ?? array() );

			// Surface ZIP file count mismatch to frontend so the user sees a warning.
			$zip_warning = '';
			if ( $expected_file_count > 0 && $total_files_zip < $expected_file_count ) {
				$zip_warning = sprintf(
					/* translators: 1: Number of files in ZIP, 2: Number of expected files. */
					__( 'Warning: ZIP may be incomplete — expected %1$d files, found %2$d in archive.', 'sscribe-export-site-pages' ),
					$total_files_zip,
					$expected_file_count
				);
			}

			$response = array(
				'status'            => 'complete',
				'processed'         => $session['total'],
				'total'             => $session['total'],
				'percentage'        => 100,
				'download_url'      => $download_url,
				'filename'          => basename( $zip_path ),
				'errors'            => $session['errors'] ?? array(),
				'error_diagnostics' => $error_diagnostics,
				'log_summary'       => $log_summary,
				'session_id'        => $session_id,
				'created_at'        => $session['start_time'] ?? microtime( true ),
				'message'           => sprintf(
					/* translators: %d: Number of pages exported. */

					_n(
						'Export complete! %d page exported successfully.',
						'Export complete! %d pages exported successfully.',
						$session['total'],
						'sscribe-export-site-pages'
					),
					$session['total']
				) . ( $error_count > 0 ? sprintf(
					/* translators: %d: Number of errors. */

					' ' . _n( '(%d error)', '(%d errors)', $error_count, 'sscribe-export-site-pages' ),
					$error_count
				) : '' ),
			);
			if ( $zip_warning ) {
				$response['zip_warning'] = $zip_warning;
			}

			$sscribe_is_debug = SSCRIBE_DEBUG;
			if ( $sscribe_is_debug && file_exists( $zip_path ) ) {
				$response['debug_info'] = array(
					'files_in_temp_count' => count( $files_before ),
					'total_files_in_zip'  => $total_files_zip,
					'expected_pages'      => $session['total'],
					'errors_count'        => $error_count,
					'language'            => $session['language'] ?? '',
					'post_status'         => $session['post_status'] ?? '',
					'total_time_sec'      => microtime( true ) - ( $session['start_time'] ?? microtime( true ) ),
					'memory_peak'         => size_format( memory_get_peak_usage( true ) ),
					'zip_size'            => function_exists( 'wp_filesize' )
						? size_format( wp_filesize( $zip_path ) )
						: __( 'unknown', 'sscribe-export-site-pages' ),
				);
			}

			// Delete session BEFORE releasing lock to prevent another process
			// from acquiring the lock and reading a deleted session.
			$this->session->delete( $session_id );
			$this->release_lock( $session_id, $lock_token );
			SScribe_AJAX_Guard::success( $response );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Finalize export crashed',
				array(
					'session_id'      => $session_id,
					'error'           => $e->getMessage(),
					'exception_class' => get_class( $e ),
					'file'            => basename( $e->getFile() ) . ':' . $e->getLine(),
					'memory_usage'    => size_format( memory_get_usage( true ) ),
				)
			);

			$this->release_lock( $session_id, $lock_token );
			$this->session->update( $session_id, array( 'status' => 'finalizing' ) );

			SScribe_AJAX_Guard::error(
				array(
					'message'   => __( 'Export finalization failed. Please try again.', 'sscribe-export-site-pages' ),
					'guidance'  => __( 'An unexpected error occurred while packaging the export. Click "Try Again" to resume from where it left off.', 'sscribe-export-site-pages' ),
					'fix_steps' => array(
						__( 'Click "Try Again" to retry the export.', 'sscribe-export-site-pages' ),
						__( 'If the problem persists, check your server PHP error logs for details.', 'sscribe-export-site-pages' ),
					),
				),
				500
			);
		}
	}

	/**
	 * Check for active session on page load - used to restore UI after browser reload.
	 */
	public function ajax_check_active_session(): void {
		$this->session_handler->ajax_check_active_session();
	}

	/**
	 * Run health check diagnostics via AJAX.
	 */
	public function ajax_health_check(): void {
		$this->get_query_controller()->ajax_health_check( $this->get_required_capability() );
	}

	/**
	 * Handle file download via AJAX.
	 */
	public function ajax_download(): void {
		$this->file_handler->ajax_download();
	}

	/**
	 * Get export status counts via AJAX.
	 */
	public function ajax_get_status_counts(): void {
		$this->get_query_controller()->ajax_get_status_counts( $this->get_required_capability() );
	}

	/**
	 * Cancel an ongoing export via AJAX.
	 */
	public function ajax_cancel_export(): void {
		$this->session_handler->ajax_cancel_export();
	}

	/**
	 * Remove temporary files after a cancelled export.
	 *
	 * @param array $session Session data.
	 */
	private function cleanup_cancelled_export( array $session ): void {
		if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
			$this->zip_handler->delete_directory( $session['temp_dir'] );
			$this->logger->debug(
				'Cleaned up temp directory for cancelled export',
				array(
					'temp_dir' => $session['temp_dir'],
				)
			);
		}
		if ( $this->export_log ) {
			$this->export_log->delete();
		}
	}

	/**
	 * Delete an export via AJAX.
	 */
	public function ajax_delete_export(): void {
		$this->file_handler->ajax_delete_export();
	}

	/**
	 * Get export log entries via AJAX.
	 */
	public function ajax_get_export_log(): void {
		$this->get_query_controller()->ajax_get_export_log( $this->get_required_capability() );
	}

	/**
	 * Assemble diagnostics data for error responses.
	 *
	 * @param array $structured_errors Structured error data.
	 * @param array $string_errors     String error data.
	 * @return array Diagnostics payload.
	 */
	private function build_error_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		return $this->get_error_handler()->build_diagnostics_payload( $structured_errors, $string_errors );
	}

	/**
	 * Clear export session via AJAX.
	 */
	public function ajax_clear_session(): void {
		$this->session_handler->ajax_clear_session();
	}

	/**
	 * Release export lock for a session.
	 *
	 * @param string      $session_id Session ID.
	 * @param string|null $lock_token Lock token to release.
	 * @return bool True if lock was released.
	 */
	private function release_lock( string $session_id, ?string $lock_token = null ): bool {
		return $this->get_lock_manager()->release_lock( $session_id, $lock_token ?? $this->current_lock_token );
	}

	/**
	 * Run preflight checks before export via AJAX.
	 */
	public function ajax_preflight_check(): void {
		$this->get_query_controller()->ajax_preflight_check( $this->get_required_capability() );
	}

	/**
	 * Get export preview via AJAX.
	 */
	public function ajax_get_export_preview(): void {
		$this->get_query_controller()->ajax_get_export_preview( $this->get_required_capability() );
	}

	/**
	 * Get recent exports list via AJAX.
	 */
	public function ajax_get_recent_exports(): void {
		$this->get_query_controller()->ajax_get_recent_exports( $this->get_required_capability() );
	}

	/**
	 * Get support information via AJAX.
	 */
	public function ajax_get_support_info(): void {
		$this->get_query_controller()->ajax_get_support_info( $this->get_required_capability() );
	}

	/**
	 * Refresh download nonce via AJAX.
	 */
	public function ajax_refresh_download_nonce(): void {
		$this->file_handler->ajax_refresh_download_nonce();
	}

	/**
	 * Static shutdown handler — cleans up any orphaned temp directory if the
	 * export was interrupted before finalize_export() could run.
	 *
	 * Registered once per process via register_shutdown_function() in __construct.
	 */
	public static function shutdown_cleanup(): void {
		$temp_dir = self::$cleanup_temp_dir;
		if ( null === $temp_dir || ! is_dir( $temp_dir ) ) {
			self::$cleanup_temp_dir    = null;
			self::$cleanup_zip_handler = null;
			self::$cleanup_logger      = null;
			return;
		}

		$zip_handler = self::$cleanup_zip_handler;
		$logger     = self::$cleanup_logger;

		self::$cleanup_temp_dir    = null;
		self::$cleanup_zip_handler = null;
		self::$cleanup_logger      = null;

		if ( $zip_handler && $logger ) {
			$zip_handler->delete_directory( $temp_dir );
			$logger->debug(
				'Shutdown cleanup removed orphaned temp directory',
				array( 'temp_dir' => $temp_dir )
			);
		}
	}
}
