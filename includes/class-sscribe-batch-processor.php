<?php
/**
 * SScribe Batch Processor
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles batch export processing with rate limiting and resource monitoring.
 */
class SScribe_Batch_Processor {

	private const MAX_STORED_ERRORS = 50;

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
	 * Get the adaptive metrics instance (lazy-loaded).
	 *
	 * @return \SScribe_Adaptive_Metrics
	 */
	private function get_adaptive_metrics(): \SScribe_Adaptive_Metrics {
		return $this->adaptive_metrics ??= new \SScribe_Adaptive_Metrics();
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
		return $this->lock_manager ??= new SScribe_Export_Lock_Manager();
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
	 * @param SScribe_Page_Collector|null   $collector   Page collector.
	 * @param SScribe_Zip_Handler|null      $zip_handler Zip handler.
	 * @param SScribe_Session|null          $session     Session.
	 * @param SScribe_Logger_Interface|null $logger      Logger.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Session $session = null,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 5 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector = $collector ?? new SScribe_Page_Collector();
		$this->zip_handler = $zip_handler ?? new SScribe_Zip_Handler();
		$this->session = $session ?? new SScribe_Session();
		$this->logger = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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
	 * @param array $formats Export formats being processed.
	 */
	private function optimize_batch_size( array $formats = array() ): void {
		$this->batch_size = $this->get_resource_monitor()->get_optimal_batch_size( $formats );

		$this->logger->debug(
			'Batch size optimized',
			array(
				'formats'        => $formats,
				'optimized_size' => $this->batch_size,
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
		$capability = apply_filters( 'sscribe_export_capability', 'manage_options' );

		if ( ! SScribe_Capabilities::is_allowed( $capability ) ) {
			$this->audit_log(
				'invalid_capability_blocked',
				array(
					'requested_capability' => $capability,
					'fallback'             => 'manage_options',
				)
			);
			return 'manage_options';
		}

		return $capability;
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
				'session_hijack',
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

		wp_raise_memory_limit( 'admin' );

		$this->audit_log( 'export_started' );
		$this->logger->debug( '=== START EXPORT ===' );

		$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization via array_map on next line.
		$formats_raw   = isset( $_POST['formats'] ) ? wp_unslash( (array) $_POST['formats'] ) : array();
		$formats_input = array_map( 'sanitize_text_field', $formats_raw );
		$formats       = ! empty( $formats_input ) ? $formats_input : array( 'docx' );

		$formats = array_filter(
			$formats,
			function ( $format ) {
				return \SScribe_Exporter_Factory::is_supported( $format );
			}
		);

		if ( empty( $formats ) ) {
			$formats = array( 'docx' );
		}

		$post_type        = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		$valid_post_types = array( 'page', 'post', 'any' );
		if ( ! in_array( $post_type, $valid_post_types, true ) ) {
			$post_type = 'page';
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

		if ( $this->session->has_active_session( $user_id ) ) {
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
				'current_wpml_lang'  => $current_lang,
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
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Failed to create temp directory (random_bytes/permission issue)',
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
				'page_ids'          => $page_ids,
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
				'start_time'        => time(),
				'cancelled'         => false,
				'user_id'           => $user_id,
			)
		);

		$this->logger->debug(
			'Session created',
			array(
				'session_id' => $session_id,
				'temp_dir'   => $temp_dir,
			)
		);

		if ( empty( $session_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Failed to create export session. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
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
			'memory_warning' => $memory_warning,
			'message'        => sprintf(
				/* translators: %d: Number of pages found. */

				__( 'Found %d pages. Starting export...', 'sscribe-export-site-pages' ),
				$total
			),
		);

		$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
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

		$max_time = (int) apply_filters( 'sscribe_max_execution_time', 120 );
		if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( $max_time ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$ob_level_before = ob_get_level();
		ob_start();

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session    = $this->session->get( $session_id );

		$formats = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
		$this->optimize_batch_size( $formats );

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

		$lock_ttl        = (int) apply_filters( 'sscribe_lock_ttl', 150 );
		$stale_threshold = (int) apply_filters( 'sscribe_lock_stale_threshold', 120 );

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

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			$this->release_lock( $session_id );
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
			$this->release_lock( $session_id );
			$this->restore_ob_level( $ob_level_before );
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Export session data corrupted. Please start again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		if ( ! empty( $session['cancelled'] ) ) {
			$this->logger->debug( 'Export was cancelled' );
			$this->restore_ob_level( $ob_level_before );
			$this->cleanup_cancelled_export( $session );
			$this->session->delete( $session_id );
			$this->release_lock( $session_id );
			SScribe_AJAX_Guard::error(
				array(
					'message'   => __( 'Export was cancelled.', 'sscribe-export-site-pages' ),
					'cancelled' => true,
				),
				499
			);
		}

		$page_ids          = $session['page_ids'];
		$processed         = $session['processed'];
		$total             = $session['total'];
		$temp_dir          = $session['temp_dir'];
		$errors            = isset( $session['errors'] ) ? $session['errors'] : array();
		$structured_errors = isset( $session['structured_errors'] ) && is_array( $session['structured_errors'] ) ? $session['structured_errors'] : array();
		$start_time        = isset( $session['start_time'] ) ? $session['start_time'] : time();
		$formats           = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
		$session_id        = $session['session_id'] ?? '';

		$this->export_log = new SScribe_Export_Log( $session_id );

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

		if ( ! empty( $batch ) ) {
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
			$this->release_lock( $session_id );
			$this->finalize_export( $session_id, $session );
			return;
		}

		$current_page_title      = '';
		$batch_start_time        = microtime( true );
		$memory_paused           = false;
		$timeout_paused          = false;
		$processed_in_this_batch = 0;
		$current_batch_page_id   = null;

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

				do_action( 'sscribe_before_export_page', $page_id, $session['language'] );

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

				$pre_export_memory_mb = (int) apply_filters( 'sscribe_pre_export_memory_threshold_mb', 5 );
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
					foreach ( $formats as $format ) {
						$format_start = microtime( true );
						$exporter     = \SScribe_Exporter_Factory::create( $format );

						if ( ! $exporter ) {
							$this->logger->warning( 'Unsupported export format skipped during batch processing', array( 'format' => $format ) );
							continue;
						}

						$result = $exporter->export( $page_data, $temp_dir, $page_index, $total );

						$format_elapsed         = microtime( true ) - $format_start;
						$format_key             = 'format_time_' . $format;
						$session[ $format_key ] = ( $session[ $format_key ] ?? 0 ) + $format_elapsed;

						if ( $result->is_success() ) {
							$file_path = $result->get_data()['path'] ?? '';
							$file_size = $result->get_data()['size'] ?? 0;

							$actual_size = ( ! empty( $file_path ) && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;
							$min_sizes   = array(
								'docx'     => 4096,
								'pdf'      => 4096,
								'html'     => 100,
								'markdown' => 50,
							);
							$min_size    = $min_sizes[ $format ] ?? 100;

							if ( $actual_size < $min_size ) {

								$size_error = sprintf(
								/* translators: 1: Format, 2: Actual size, 3: Minimum size. */

									__( '%1$s file appears empty or corrupted (size: %2$d bytes, minimum: %3$d bytes).', 'sscribe-export-site-pages' ),
									strtoupper( $format ),
									$actual_size,
									$min_size
								);
								$export_errors[] = array(
									'format'   => strtoupper( $format ),
									'message'  => $size_error,
									'category' => 'empty_file',
									'context'  => array(
										'file_path'     => $file_path,
										'actual_size'   => $actual_size,
										'reported_size' => $file_size,
									),
								);

								if ( $this->export_log ) {
									$this->export_log->log_format_result( $page_id, $format, false, '', $size_error );
								}

								$this->logger->error(
									ucfirst( $format ) . ' export produced empty/corrupted file',
									array(
										'page_id'       => $page_id,
										'file_path'     => $file_path,
										'actual_size'   => $actual_size,
										'reported_size' => $file_size,
										'min_size'      => $min_size,
									)
								);
							} else {
								$export_success       = true;
								$successful_formats[] = $format;

								$format_size_key             = 'format_size_' . $format;
								$session[ $format_size_key ] = ( $session[ $format_size_key ] ?? 0 ) + $actual_size;

								$format_pages_key             = 'format_pages_' . $format;
								$session[ $format_pages_key ] = ( $session[ $format_pages_key ] ?? 0 ) + 1;

								if ( $this->export_log ) {
									$this->export_log->log_format_result( $page_id, $format, true, $file_path );
								}

								$this->logger->debug(
									ucfirst( $format ) . ' generated successfully',
									array(
										'file'        => basename( $file_path ),
										'page_id'     => $page_id,
										'format'      => $format,
										'actual_size' => $actual_size,
										'duration'    => round( $format_elapsed, 3 ),
									)
								);
							}
						} else {
							$result_data     = $result->get_data();
							$export_errors[] = array(
								'format'   => strtoupper( $format ),
								'message'  => $result->get_error(),
								'category' => $result_data['error_category'] ?? 'unknown',
								'context'  => is_array( $result_data ) ? $result_data : array(),
							);

							if ( $this->export_log ) {
								$this->export_log->log_format_result( $page_id, $format, false, '', $result->get_error() );
							}
						}
					}
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
							'exception_file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
							'memory_usage'    => memory_get_usage( true ),
							'memory_peak'     => memory_get_peak_usage( true ),
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
				$exporter  = null;

				++$processed;
				++$processed_in_this_batch;

				if ( 0 === $processed % 3 && function_exists( 'gc_collect_cycles' ) ) {
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
		} finally {
			// Persist session state BEFORE releasing lock to prevent race condition.
			// Between lock release and session update, another process could acquire
			// the lock and read stale session data, causing duplicate processing.
			$batch_duration = microtime( true ) - $batch_start_time;

			$this->logger->debug(
				'Batch completed',
				array(
					'processed_now'      => $processed - $session['processed'],
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
				$errors        = array_slice( $errors, 0, self::MAX_STORED_ERRORS );
				$this->logger->warning(
					'Error array capped to prevent memory exhaustion',
					array(
						'stored'  => self::MAX_STORED_ERRORS,
						'trimmed' => $trimmed_count,
						'total'   => $total_errors,
						'message' => sprintf(
							/* translators: %d: Number of additional errors not stored. */

							__( '... and %d more errors occurred (see export log for full details).', 'sscribe-export-site-pages' ),
							$trimmed_count
						),
					)
				);
			}

			if ( $structured_errors_trimmed ) {
				$trimmed_count = $total_structured_errors - self::MAX_STORED_ERRORS;
				$structured_errors = array_slice( $structured_errors, 0, self::MAX_STORED_ERRORS );
				$this->logger->warning(
					'Structured error array capped to prevent memory exhaustion',
					array(
						'stored'  => self::MAX_STORED_ERRORS,
						'trimmed' => $trimmed_count,
						'total'   => $total_structured_errors,
					)
				);
			}

			$update_data = array(
				'processed'         => $processed,
				'errors'            => $errors,
				'structured_errors' => $structured_errors,
				'start_time'        => $start_time,
			);

			$format_keys = array( 'format_time_docx', 'format_time_pdf', 'format_time_html', 'format_time_markdown', 'format_size_docx', 'format_size_pdf', 'format_size_html', 'format_size_markdown', 'format_pages_docx', 'format_pages_pdf', 'format_pages_html', 'format_pages_markdown' );
			foreach ( $format_keys as $key ) {
				if ( isset( $session[ $key ] ) ) {
					$update_data[ $key ] = $session[ $key ];
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

			$this->release_lock( $session_id );
			$this->restore_ob_level( $ob_level_before );
			$this->collector->clear_page_caches();
		}

		$percentage = ( $total > 0 ) ? round( ( $processed / $total ) * 100 ) : 100;
		$is_done    = ( $processed >= $total );

		$this->logger->debug(
			'Progress check',
			array(
				'processed'  => $processed,
				'total'      => $total,
				'percentage' => $percentage,
				'is_done'    => $is_done,
			)
		);

		$elapsed           = time() - $start_time;
		$avg_time_per_page = $processed > 0 ? $elapsed / $processed : 0;
		$remaining_pages   = $total - $processed;
		$time_remaining    = round( $avg_time_per_page * $remaining_pages );

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
		}

		$paused_reason = '';
		if ( $memory_paused ) {
			$paused_reason = 'memory';
		} elseif ( $timeout_paused ) {
			$paused_reason = 'timeout';
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
			'paused_reason'  => $paused_reason,
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

		$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
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

		SScribe_AJAX_Guard::success( $response );
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

		if ( headers_sent() && ob_get_level() > $target_level ) {
			while ( ob_get_level() > $target_level ) {
				ob_end_clean();
			}
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
		}

		$status = $session['status'] ?? '';
		if ( 'finalizing' !== $status && 'completing' !== $status ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'not_finalizing',
					'message' => __( 'Export is not in the finalizing state.', 'sscribe-export-site-pages' ),
				),
				409
			);
		}

		if ( 'completing' === $status ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'already_completing',
					'message' => __( 'Export is already being finalized. Please wait.', 'sscribe-export-site-pages' ),
				),
				409
			);
		}

		$this->finalize_export( $session_id, $session );
	}

	/**
	 * Complete the export process and package files.
	 *
	 * @param string $session_id Export session ID.
	 * @param array  $session    Session data.
	 */
	private function finalize_export( string $session_id, array $session ): void {

		if ( null === $this->export_log ) {
			$this->export_log = new SScribe_Export_Log( $session_id );
		}

		if ( ! $this->session->update( $session_id, array( 'status' => 'completing' ) ) ) {
			$this->logger->warning( 'Session status update failed', array( 'session_id' => $session_id ) );
		}
		$session['status'] = 'completing';

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

			$formats = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );

			$format_suffix = count( $formats ) > 1 ? 'ALL-FORMATS' : strtoupper( $formats[0] );
			$timestamp     = gmdate( 'Y-m-d-His' );
			$lang_suffix   = $has_language ? strtoupper( $lang_code ) : 'ALL-LANGS';

			$zip_name = sprintf(
				'%s-%s-%s-%s',
				$site_slug,
				$timestamp,
				$lang_suffix,
				$format_suffix
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

				$export_stats = new SScribe_Export_Stats();
				$export_stats->fail_export( $session_id, 'No files generated' );

				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				$this->release_lock( $session_id );
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

			$zip_path = $this->zip_handler->create_zip( $session['temp_dir'], $zip_name, $formats, $has_language, $lang_metadata );

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

				$export_stats = new SScribe_Export_Stats();
				$export_stats->fail_export( $session_id, 'Failed to create ZIP package' );

				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				$this->release_lock( $session_id );
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

				$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
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
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
				$total_files_zip = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
				$zip->close();
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

				$this->release_lock( $session_id );

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

			$export_stats = new SScribe_Export_Stats();
			$duration     = time() - ( $session['start_time'] ?? time() );
			$zip_size     = function_exists( 'wp_filesize' ) && file_exists( $zip_path ) ? (int) wp_filesize( $zip_path ) : 0;
			$error_count  = count( $session['errors'] ?? array() );
			$export_stats->complete_export(
				$session_id,
				array(
					'successful_pages' => $session['total'],
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
					'total_time_sec' => time() - ( $session['start_time'] ?? time() ),
				)
			);

			$this->audit_log(
				'export_completed',
				array(
					'total_pages'  => $session['total'],
					'errors'       => $error_count,
					'filename'     => basename( $zip_path ),
					'duration_sec' => time() - ( $session['start_time'] ?? time() ),
				)
			);

			$formats    = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
			$session_pt = $session['post_type'] ?? 'page';
			foreach ( $formats as $fmt ) {
				$format_time_key  = 'format_time_' . $fmt;
				$format_size_key  = 'format_size_' . $fmt;
				$format_pages_key = 'format_pages_' . $fmt;

				$elapsed_seconds = (float) ( $session[ $format_time_key ] ?? 0 );
				$total_bytes     = (int) ( $session[ $format_size_key ] ?? 0 );
				$pages_exported  = (int) ( $session[ $format_pages_key ] ?? 0 );
				$total_mb        = $total_bytes / 1048576;

				if ( $pages_exported > 0 && $elapsed_seconds > 0 ) {
					$this->get_adaptive_metrics()->save( $fmt, $pages_exported, $elapsed_seconds, $total_mb, $session_pt );
				}
			}

			$log_summary       = $this->export_log ? $this->export_log->get_summary() : array();
			$error_diagnostics = $this->build_error_diagnostics_payload( $structured_errors, $session['errors'] ?? array() );

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

			$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
			if ( $sscribe_is_debug && file_exists( $zip_path ) ) {
				$response['debug_info'] = array(
					'files_in_temp_count' => count( $files_before ),
					'total_files_in_zip'  => $total_files_zip,
					'expected_pages'      => $session['total'],
					'errors_count'        => $error_count,
					'language'            => $session['language'] ?? '',
					'post_status'         => $session['post_status'] ?? '',
					'total_time_sec'      => time() - ( $session['start_time'] ?? time() ),
					'memory_peak'         => size_format( memory_get_peak_usage( true ) ),
					'zip_size'            => function_exists( 'wp_filesize' )
						? size_format( wp_filesize( $zip_path ) )
						: __( 'unknown', 'sscribe-export-site-pages' ),
				);
			}

			$this->release_lock( $session_id );
			$this->session->delete( $session_id );
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

			$this->release_lock( $session_id );

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
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			status_header( 403 );
			wp_send_json_error(
				array( 'message' => esc_html__( 'Security check failed.', 'sscribe-export-site-pages' ) )
			);
			return;
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			status_header( 403 );
			wp_send_json_error(
				array( 'message' => esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) )
			);
			return;
		}

		if ( ! $this->check_rate_limit() ) {
			status_header( 429 );
			wp_send_json_error(
				array( 'message' => esc_html__( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) )
			);
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_success(
				array(
					'has_active' => false,
				)
			);
			return;
		}

		$session_data = $this->session->get_active_session_data( $user_id );

		if ( null === $session_data ) {
			wp_send_json_success(
				array(
					'has_active' => false,
				)
			);
			return;
		}

		// Return active session info for UI restoration.
		wp_send_json_success(
			array(
				'has_active'  => true,
				'session_id'  => $session_data['session_id'] ?? '',
				'status'      => $session_data['status'] ?? '',
				'processed'   => (int) ( $session_data['processed'] ?? 0 ),
				'total'      => (int) ( $session_data['total'] ?? 0 ),
				'percentage'  => $session_data['total'] > 0
					? (int) ( ( $session_data['processed'] / $session_data['total'] ) * 100 )
					: 0,
			)
		);
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
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Security check failed. The download link may have expired — please refresh the page and try again.', 'sscribe-export-site-pages' ) );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) );
		}

		if ( ! $this->check_rate_limit() ) {
			status_header( 429 );
			wp_die( esc_html__( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ) );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		$export_dir = '';

		try {
			$export_dir = $this->zip_handler->get_export_dir();

			$file_path = $export_dir . '/' . $filename;

			if ( empty( $filename ) || ! file_exists( $file_path ) ) {
				status_header( 404 );
				wp_die( esc_html__( 'File not found or has expired. Please generate a new export.', 'sscribe-export-site-pages' ) );
			}

			$real_path = realpath( $file_path );
			$real_dir  = realpath( $export_dir );

			$safe_dir = false !== $real_dir ? rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR : '';
			if ( false === $real_path || false === $real_dir || ! str_starts_with( $real_path, $safe_dir ) || 'zip' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
				status_header( 400 );
				wp_die( esc_html__( 'Invalid file request.', 'sscribe-export-site-pages' ) );
			}

			$exports = get_option( 'sscribe_export_index', array() );
			if ( ! isset( $exports[ $filename ] ) || ! is_array( $exports[ $filename ] ) ) {
				$this->audit_log( 'download_orphaned_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}

			$export_info = $exports[ $filename ];
			if ( isset( $export_info['user_id'] ) && get_current_user_id() !== (int) $export_info['user_id'] ) {
				$this->audit_log( 'download_access_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}

			$ascii_filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename ) ?? $filename;

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $ascii_filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
			header( 'Content-Length: ' . filesize( $file_path ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'X-Content-Type-Options: nosniff' );

			while ( ob_get_level() ) {
				ob_end_clean();
			}

			$this->audit_log( 'download', array( 'filename' => $filename ) );

			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				status_header( 404 );
				wp_die( esc_html__( 'File no longer available. Please regenerate the export.', 'sscribe-export-site-pages' ) );
			}

			flush();

			ignore_user_abort( true );

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 360 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$read_result = readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct download
			if ( false === $read_result ) {
				$this->logger->warning(
					'readfile() returned false — possible partial read',
					array(
						'filename' => $filename,
						'path'     => $file_path,
					)
				);
			}
			ignore_user_abort( false );
			exit;
		} catch ( \InvalidArgumentException $e ) {
			$this->logger->error(
				'Export directory access failed during download',
				array(
					'exception' => $e->getMessage(),
					'filename'  => $filename,
				)
			);
			status_header( 500 );
			wp_die( esc_html__( 'Server misconfiguration: export directory is invalid or inaccessible.', 'sscribe-export-site-pages' ) );
		}
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
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ) ), 400 );
		}

		$session = $this->session->get( $session_id );
		if ( ! $session ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Session not found.', 'sscribe-export-site-pages' ) ), 404 );
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ) ), 403 );
		}

		$session['cancelled'] = true;
		$this->session->update( $session_id, $session );
		$this->cleanup_cancelled_export( $session );
		$this->session->delete( $session_id );
		delete_transient( 'sscribe_lock_' . $session_id );

		SScribe_AJAX_Guard::success( array( 'message' => __( 'Export cancelled.', 'sscribe-export-site-pages' ) ) );
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
	}

	/**
	 * Delete an export via AJAX.
	 */
	public function ajax_delete_export(): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
		}

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $filename ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ) ), 400 );
		}

		$exports = get_option( 'sscribe_export_index', array() );

		if ( ! isset( $exports[ $filename ] ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Export not found.', 'sscribe-export-site-pages' ) ), 404 );
		}

		$export_info    = $exports[ $filename ] ?? array();
		$stored_user_id = isset( $export_info['user_id'] ) ? (int) (string) $export_info['user_id'] : 0;
		if ( $stored_user_id > 0 && get_current_user_id() !== $stored_user_id ) {
				$this->audit_log( 'delete_access_denied', array( 'filename' => $filename ) );
				SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
		}

		try {
			$export_dir = $this->zip_handler->get_export_dir();
		} catch ( \InvalidArgumentException $e ) {
			$this->logger->error(
				'Export directory access failed during delete',
				array(
					'exception' => $e->getMessage(),
					'filename'  => $filename,
				)
			);
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Server misconfiguration: export directory is invalid.', 'sscribe-export-site-pages' ) ),
				500
			);
		}

		$file_path = $export_dir . '/' . $filename;

		$real_path = realpath( $file_path );
		$real_dir  = realpath( $export_dir );
		if ( false === $real_path || false === $real_dir ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid file path.', 'sscribe-export-site-pages' ) ), 400 );
		}

		$safe_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( ! str_starts_with( $real_path, $safe_dir ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid file path.', 'sscribe-export-site-pages' ) ), 400 );
		}

		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		unset( $exports[ $filename ] );
		update_option( 'sscribe_export_index', $exports, false );

		SScribe_Export_Log::delete_by_filename( $filename );

		$this->audit_log( 'export_deleted', array( 'filename' => $filename ) );

		SScribe_AJAX_Guard::success( array( 'message' => __( 'Export deleted.', 'sscribe-export-site-pages' ) ) );
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
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$force   = isset( $_POST['force'] ) && filter_var( wp_unslash( $_POST['force'] ), FILTER_VALIDATE_BOOLEAN );

		if ( $force ) {
			$deleted = $this->session->clear_user_sessions( $user_id );
			$this->logger->debug(
				'Force cleared all sessions for user',
				array(
					'user_id'       => $user_id,
					'deleted_count' => $deleted,
				)
			);

			$this->cleanup_user_locks( $user_id );
		} else {
			$this->session->cleanup_expired( 60 );
			$this->logger->debug( 'Cleared expired sessions for user', array( 'user_id' => $user_id ) );
		}

		SScribe_AJAX_Guard::success( array( 'message' => __( 'Session cleared.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * Clean up stale locks for a user.
	 *
	 * @param int|null $user_id User ID.
	 */
	private function cleanup_user_locks( ?int $user_id = null ): void {
		$this->get_lock_manager()->cleanup_user_locks( $user_id );
	}

	/**
	 * Release export lock for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return bool True if lock was released.
	 */
	private function release_lock( string $session_id ): bool {
		return $this->get_lock_manager()->release_lock( $session_id, $this->current_lock_token );
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
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
		}

		SScribe_AJAX_Guard::success(
			array(
				'nonce' => wp_create_nonce( 'sscribe_download' ),
			)
		);
	}
}
