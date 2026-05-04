<?php
/**
 * Handles batch AJAX processing of page exports.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch processor for AJAX-based page exports.
 */
class SScribe_Batch_Processor {

	/**
	 * Maximum number of errors to retain in memory per export session.
	 *
	 * Beyond this limit, errors are still logged to disk but not stored
	 * in the session array to prevent OOM on large exports (500+ pages).
	 *
	 * @var int
	 */
	private const MAX_STORED_ERRORS = 50;

	/**
	 * Number of pages to process per batch.
	 *
	 * @var int
	 */
	private int $batch_size = 5;

	/**
	 * Diagnostics instance for preflight checks and error diagnosis.
	 *
	 * @var SScribe_Diagnostics
	 */
	private SScribe_Diagnostics $diagnostics;

	/**
	 * Page collector instance.
	 *
	 * @var SScribe_Page_Collector
	 */
	private readonly \SScribe_Page_Collector $collector;

	/**
	 * ZIP handler instance.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private readonly \SScribe_Zip_Handler $zip_handler;

	/**
	 * Session handler instance.
	 *
	 * @var SScribe_Session
	 */
	private readonly \SScribe_Session $session;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly \SScribe_Logger_Interface $logger;

	/**
	 * Audit trail instance for security logging.
	 *
	 * @var SScribe_Audit_Trail
	 */
	private readonly \SScribe_Audit_Trail $audit_trail;

	/**
	 * Export log instance.
	 *
	 * @var SScribe_Export_Log
	 */
	private ?\SScribe_Export_Log $export_log = null;

	/**
	 * Current lock token for atomic lock verification.
	 *
	 * @var string|null
	 */
	private ?string $current_lock_token = null;

	/**
	 * Rate limit: 200 requests per minute per user by default.
	 *
	 * Admins get up to 1000/min via the sscribe_rate_limit_admin filter.
	 * 200 req/min supports large batch exports where the AJAX client polls
	 * every few seconds per page across multiple concurrent format renders.
	 */
	private const RATE_LIMIT_MAX = 200;

	/**
	 * Rate limit: Time window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Constructor.
	 *
	 * @param SScribe_Page_Collector|null   $collector   Page collector instance.
	 * @param SScribe_Zip_Handler|null      $zip_handler ZIP handler instance.
	 * @param SScribe_Session|null          $session     Session handler instance.
	 * @param SScribe_Logger_Interface|null $logger      Logger instance.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Session $session = null,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 5 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector   = $collector ?? new SScribe_Page_Collector();
		$this->zip_handler = $zip_handler ?? new SScribe_Zip_Handler();
		$this->session     = $session ?? new SScribe_Session();
		$this->logger      = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->diagnostics = new SScribe_Diagnostics();
		$this->audit_trail = new SScribe_Audit_Trail();
	}

	/**
	 * Check rate limit for current user using WordPress transients.
	 *
	 * @return bool True if within limits, false if exceeded.
	 */
	private function check_rate_limit(): bool {
		$user_id = get_current_user_id();

		// For authenticated users, use user ID. For anonymous users, use IP hash
		// to prevent cross-user rate-limiting collisions.
		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $user_id;
		} else {
			$remote_ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
			$transient_key = 'sscribe_rate_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now = time();

		// Administrators get a higher rate limit to support large exports.
		// Use get_required_capability() to ensure whitelist validation (not raw filter).
		$rate_limit = current_user_can( $this->get_required_capability() )
			? (int) apply_filters( 'sscribe_rate_limit_admin', 1000 )
			: self::RATE_LIMIT_MAX;

		$data = get_transient( $transient_key );

		if ( false === $data ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( isset( $data['reset_at'] ) && $data['reset_at'] <= $now ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( $data['count'] >= $rate_limit ) {
			return false;
		}

		++$data['count'];
		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );

		return true;
	}

	/**
	 * Log an action for audit trail.
	 *
	 * @param string $action  Action name.
	 * @param array  $context Additional context.
	 * @return void
	 */
	private function audit_log( string $action, array $context = array() ): void {
		$user_id      = get_current_user_id();
		$current_user = wp_get_current_user();
		$username     = ( $current_user && $current_user->exists() ) ? $current_user->user_login : 'unknown';

		$log_entry = array(
			'action'    => $action,
			'user_id'   => $user_id,
			'username'  => $username,
			'ip'        => SScribe_Helpers::get_client_ip(),
			'timestamp' => current_time( 'mysql' ),
			'context'   => $context,
		);

		$this->logger->debug( "[AUDIT] {$action}", $log_entry );

		$event_type = $this->map_action_to_event( $action );
		if ( $event_type ) {
			$this->audit_trail->log( $event_type, $context );
		}
	}

	/**
	 * Map action name to audit event type.
	 *
	 * @param string $action Action name.
	 * @return string|null Event type constant or null if not mappable.
	 */
	private function map_action_to_event( string $action ): ?string {
		$map = array(
			'export_started'    => SScribe_Audit_Trail::EVENT_EXPORT_STARTED,
			'export_completed'  => SScribe_Audit_Trail::EVENT_EXPORT_COMPLETED,
			'export_failed'     => SScribe_Audit_Trail::EVENT_EXPORT_FAILED,
			'export_cancelled'  => SScribe_Audit_Trail::EVENT_EXPORT_CANCELLED,
			'download'          => SScribe_Audit_Trail::EVENT_DOWNLOAD,
			'download_denied'   => SScribe_Audit_Trail::EVENT_DOWNLOAD_DENIED,
			'delete_export'     => SScribe_Audit_Trail::EVENT_DELETE,
			'session_cleared'   => SScribe_Audit_Trail::EVENT_SESSION_CLEARED,
			'preflight_check'   => SScribe_Audit_Trail::EVENT_PREFLIGHT_CHECK,
			'rate_limited'      => SScribe_Audit_Trail::EVENT_RATE_LIMITED,
			'permission_denied' => SScribe_Audit_Trail::EVENT_PERMISSION_DENIED,
			'invalid_nonce'     => SScribe_Audit_Trail::EVENT_INVALID_NONCE,
			'session_hijack'    => SScribe_Audit_Trail::EVENT_SESSION_HIJACK_ATTEMPT,
		);

		return $map[ $action ] ?? null;
	}

	/**
	 * Check if enough memory is available.
	 *
	 * @param int $buffer_mb Buffer in MB to keep available.
	 * @return bool True if memory is available.
	 */
	private function is_memory_available( int $buffer_mb = 10 ): bool {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return true;
		}

		$used      = memory_get_usage( true );
		$available = $limit - $used;

		return $available > ( $buffer_mb * 1024 * 1024 );
	}

	/**
	 * Check if enough time remains before PHP timeout.
	 *
	 * @param float $batch_start_time The microtime when batch processing started.
	 * @param int   $buffer_seconds   Seconds to keep as buffer before timeout.
	 * @return bool True if time is available, false if approaching timeout.
	 */
	private function is_time_available( float $batch_start_time, int $buffer_seconds = 10 ): bool {
		$max_execution = (int) ini_get( 'max_execution_time' );

		// If max_execution_time is 0 (unlimited) or not set, always return true.
		if ( $max_execution <= 0 ) {
			return true;
		}

		$elapsed   = microtime( true ) - $batch_start_time;
		$remaining = $max_execution - $elapsed;

		return $remaining > $buffer_seconds;
	}

	/**
	 * Get remaining time before timeout.
	 *
	 * @param float $batch_start_time The microtime when batch processing started.
	 * @return float Remaining seconds, or -1 if unlimited.
	 */
	private function get_remaining_time( float $batch_start_time ): float {
		$max_execution = (int) ini_get( 'max_execution_time' );

		if ( $max_execution <= 0 ) {
			return -1; // Unlimited.
		}

		$elapsed   = microtime( true ) - $batch_start_time;
		$remaining = $max_execution - $elapsed;

		return max( 0, $remaining );
	}

	/**
	 * Get memory usage as percentage.
	 *
	 * @return float Memory usage percentage.
	 */
	private function get_memory_usage_percent(): float {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return 0.0;
		}

		$used = memory_get_usage( true );
		return round( ( $used / $limit ) * 100, 1 );
	}

	/**
	 * Optimize batch size based on available memory and format type.
	 *
	 * Dynamically adjusts the batch size to prevent memory exhaustion.
	 * Estimates ~5MB per page average for DOCX generation, with 20% safety margin.
	 * Reduces batch size to 2 when PDF format is included to prevent timeouts.
	 *
	 * @param array $formats Export formats selected for this session.
	 * @return void
	 */
	private function optimize_batch_size( array $formats = array() ): void {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		// Estimate memory per page:
		// - Page data collection: ~500KB
		// - Content parsing (DOMDocument): ~1MB
		// - PHPWord DOCX generation: ~2-3MB
		// Total: ~4MB average per page, use 5MB for safety margin.
		$memory_per_page = 5 * 1024 * 1024;

		// Reserve 20% safety margin.
		$safe_available = $available * 0.8;

		// Calculate safe batch size.
		$safe_batch_size = (int) floor( $safe_available / $memory_per_page );

		// Apply configured batch size as upper limit, but allow reduction for memory.
		$configured_size  = (int) apply_filters( 'sscribe_batch_size', 5 );
		$this->batch_size = max( 1, min( $safe_batch_size, $configured_size, 20 ) );

		// Further reduce batch size when PDF format is included to prevent timeouts.
		// PDF generation via mPDF is ~3s/page — large batches exceed PHP max_execution_time.
		if ( in_array( 'pdf', $formats, true ) && $this->batch_size > 2 ) {
			$this->batch_size = 2;
		}

		$this->logger->debug(
			'Batch size optimized for available memory',
			array(
				'memory_limit'    => size_format( $memory_limit ),
				'current_usage'   => size_format( $current_usage ),
				'available'       => size_format( $available ),
				'safe_available'  => size_format( $safe_available ),
				'memory_per_page' => size_format( $memory_per_page ),
				'configured_size' => $configured_size,
				'optimized_size'  => $this->batch_size,
			)
		);
	}

	/**
	 * Calculate estimated memory requirement for an export.
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export formats selected.
	 * @return int Estimated memory requirement in bytes.
	 */
	private function calculate_export_memory_requirement( int $page_count, array $formats ): int {
		// Base memory per page varies by format:
		// - HTML: ~1MB
		// - Markdown: ~0.5MB
		// - DOCX: ~5MB (PHPWord + DOMDocument)
		// - PDF: ~4MB (mPDF + rendering).
		$memory_per_page = 1; // Base 1MB for page data collection.

		if ( in_array( 'docx', $formats, true ) ) {
			$memory_per_page += 4; // PHPWord overhead.
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$memory_per_page += 3; // mPDF overhead.
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$memory_per_page += 0.5;
		}

		// Convert to bytes, add 50MB overhead for PHP/WordPress core.
		$total_mb = ( $page_count * $memory_per_page ) + 50;

		return (int) ( $total_mb * 1024 * 1024 );
	}

	/**
	 * Get memory warning message if export may fail due to memory constraints.
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export formats selected.
	 * @return array|null Warning array with 'level' and 'message', or null if no warning.
	 */
	private function get_memory_warning( int $page_count, array $formats ): ?array {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		$estimated_need = $this->calculate_export_memory_requirement( $page_count, $formats );
		$safe_available = $available * 0.8; // 20% safety margin.

		// No warning if we have enough memory.
		if ( $estimated_need <= $safe_available ) {
			return null;
		}

		$estimated_mb   = round( $estimated_need / 1024 / 1024 );
		$available_mb   = round( $available / 1024 / 1024 );
		$limit_mb       = round( $memory_limit / 1024 / 1024 );
		$recommended_mb = ceil( $estimated_mb / 128 ) * 128;

		if ( $estimated_need > $available ) {
			return array(
				'level'          => 'error',
				'message'        => sprintf(
					/* translators: 1: Estimated memory needed, 2: Available memory, 3: Recommended memory */
					__( 'Warning: Export requires ~%1$dMB but only %2$dMB available. Increase PHP memory_limit to %3$dMB+ for reliable export.', 'sscribe-export-site-pages' ),
					$estimated_mb,
					$available_mb,
					$recommended_mb
				),
				'estimated_mb'   => $estimated_mb,
				'available_mb'   => $available_mb,
				'recommended_mb' => $recommended_mb,
			);
		}

		return array(
			'level'        => 'warning',
			'message'      => sprintf(
				/* translators: 1: Estimated memory needed, 2: Available memory, 3: Percentage */
				__( 'Note: Export will use ~%1$dMB of %2$dMB available (%3$d%%). Consider increasing memory for safety.', 'sscribe-export-site-pages' ),
				$estimated_mb,
				$available_mb,
				round( ( $estimated_mb / $available_mb ) * 100 )
			),
			'estimated_mb' => $estimated_mb,
			'available_mb' => $available_mb,
		);
	}

	/**
	 * Get the required capability for export operations.
	 *
	 * @return string WordPress capability slug.
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
	 * Validate that the current user owns the session.
	 *
	 * @param array  $session    Session data.
	 * @param string $session_id Session ID for logging.
	 * @return bool True if ownership is valid, false otherwise.
	 */
	private function validate_session_ownership( array $session, string $session_id ): bool {
		$current_user_id = get_current_user_id();

		// SECURITY: Legacy sessions without user_id are no longer accessible.
		// This prevents session hijacking on sessions created before the user_id fix.
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
	 * AJAX handler: Start export process.
	 *
	 * @return void
	 */
	public function ajax_start_export(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				),
				403 // HTTP 403 Forbidden.
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
				),
				429 // HTTP 429 Too Many Requests.
			);
			return;
		}

		// Self-healing: clear orphaned locks and sessions before starting.
		$this->diagnostics->self_heal();

		// Raise memory limit for export operations.
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

		// Post type: 'page', 'post', or 'any' (both). Default to 'page' for backward compatibility.
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

		$this->cleanup_user_locks( $user_id );

		if ( $this->session->has_active_session( $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You already have an export in progress. Please wait for it to complete or refresh the page.', 'sscribe-export-site-pages' ),
				),
				409 // HTTP 409 Conflict.
			);
			return;
		}

		if ( ! empty( $language ) && $this->collector->is_wpml_active() ) {
			$valid_languages = wp_list_pluck( $this->collector->get_wpml_languages(), 'code' );
			if ( ! in_array( $language, $valid_languages, true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid language code specified.', 'sscribe-export-site-pages' ),
					),
					400 // HTTP 400 Bad Request.
				);
				return;
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
			wp_send_json_error(
				array(
					'message' => __( 'No pages found matching the selected criteria.', 'sscribe-export-site-pages' ),
				),
				400 // HTTP 400 Bad Request.
			);
			return;
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
			wp_send_json_error(
				array(
					'message' => __( 'Failed to initialize export directory. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
			return;
		}

		// DOCX exports always use per-page PHPWord generation.
		// The streaming DOCX generator was removed because it produced a
		// single COMBINED.docx with broken formatting and duplicated XML
		// headers on resumed batches.  The per-page exporter (SScribe_DOCX_Exporter)
		// produces rich, valid DOCX files with proper styling, RTL support,
		// and explicit memory cleanup after each page.

		$session_id = $this->session->create(
			array(
				'page_ids'          => $page_ids,
				'temp_dir'          => $temp_dir,
				'total'             => $total,
				'processed'         => 0,
				'status'            => 'processing',
				'language'          => $language,
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
			wp_send_json_error(
				array(
					'message' => __( 'Failed to create export session. Please try again.', 'sscribe-export-site-pages' ),
				),
				500 // HTTP 500 Internal Server Error.
			);
			return;
		}

		$this->export_log = new SScribe_Export_Log( $session_id );
		$this->export_log->set_total_pages( $total );

		// Calculate memory forecast and warn if export may fail.
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

		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler: Process next batch.
	 *
	 * @return void
	 */
	public function ajax_process_batch(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403 // HTTP 403 Forbidden.
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				),
				429 // HTTP 429 Too Many Requests.
			);
			return;
		}

		$max_time = (int) apply_filters( 'sscribe_max_execution_time', 120 );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklisted -- Required for batch processing large page content from page builders (Elementor/Divi) where default 30s timeout causes failures. This is a standard practice for export plugins.
			set_time_limit( $max_time );
		}
		wp_raise_memory_limit( 'admin' );

		$ob_level_before = ob_get_level();
		ob_start();

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session    = $this->session->get( $session_id );

		// Optimize batch size based on available memory and format type.
		// PDF (mPDF) is ~3s/page and needs smaller batches to prevent timeouts.
		$formats = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
		$this->optimize_batch_size( $formats );

		// Increase time limit for PDF-heavy exports.
		// mPDF rendering is CPU-intensive (~3s/page); allow more time per batch.
		if ( in_array( 'pdf', $formats, true ) && function_exists( 'set_time_limit' ) ) {
			$pdf_max_time = (int) apply_filters( 'sscribe_pdf_max_execution_time', 300 );
				// phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklisted -- PDF export requires extended execution time. mPDF rendering is ~3s per page; with batch_size=2, each batch needs ~6s+ overhead.
			set_time_limit( $pdf_max_time );
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
			wp_send_json_error(
				array(
					'message' => __( 'Export session expired or not found. Please start again.', 'sscribe-export-site-pages' ),
				),
				404 // HTTP 404 Not Found.
			);
			return;
		}

		// Implement atomic locking with unique token to prevent race conditions.
		$lock_key        = 'sscribe_lock_' . $session_id;
		$lock_token      = wp_generate_password( 32, false );
		$existing_lock   = get_transient( $lock_key );
		$current_time    = time();
		$lock_ttl        = (int) apply_filters( 'sscribe_lock_ttl', 45 );
		$stale_threshold = (int) apply_filters( 'sscribe_lock_stale_threshold', 35 );

		if ( $existing_lock ) {
			// Parse existing lock: format is "timestamp|token" for atomic operations.
			$lock_parts = explode( '|', $existing_lock );
			$lock_time  = isset( $lock_parts[0] ) ? (int) $lock_parts[0] : 0;
			$lock_age   = $current_time - $lock_time;

			if ( $lock_age > $stale_threshold ) {
				// Stale lock detected - use atomic compare-and-swap via WP's transient race condition handling.
				// We attempt to acquire the lock atomically by setting a new value.
				$this->logger->debug(
					'Detected stale lock, attempting atomic acquisition',
					array(
						'session_id' => $session_id,
						'lock_age'   => $lock_age,
						'lock_ttl'   => $lock_ttl,
					)
				);
				// Set new lock with token for ownership verification.
				$lock_acquired = set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl );
				if ( ! $lock_acquired ) {
					// Another process acquired the lock between our check and set.
					$this->logger->debug( 'Lock acquisition failed - another process won', array( 'session_id' => $session_id ) );
					$this->restore_ob_level( $ob_level_before );
					wp_send_json_error(
						array(
							'status'  => 'locked',
							'retry'   => true,
							'message' => __( 'A batch is already processing. Please wait.', 'sscribe-export-site-pages' ),
						),
						429 // HTTP 429 Too Many Requests.
					);
					return;
				}
			} else {
				// Active lock exists - reject the request.
				$this->logger->debug( 'Batch is already processing concurrently', array( 'session_id' => $session_id ) );
				$this->restore_ob_level( $ob_level_before );
				wp_send_json_error(
					array(
						'status'  => 'locked',
						'retry'   => true,
						'message' => __( 'A batch is already processing. Please wait.', 'sscribe-export-site-pages' ),
					),
					429 // HTTP 429 Too Many Requests.
				);
				return;
			}
		} else {
			// No existing lock - acquire atomically.
			$lock_acquired = set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl );
			if ( ! $lock_acquired ) {
				// Transient storage unavailable - fail securely.
				$this->logger->warning( 'Lock transient unavailable, aborting batch', array( 'session_id' => $session_id ) );
				$this->restore_ob_level( $ob_level_before );
				wp_send_json_error(
					array(
						'status'  => 'error',
						'retry'   => true,
						'message' => __( 'Unable to acquire processing lock. Please try again.', 'sscribe-export-site-pages' ),
					),
					503
				);
				return;
			}
		}

		// Store lock token for later verification (used when releasing).
		$this->current_lock_token = $lock_token;

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			$this->release_lock( $session_id );
			$this->restore_ob_level( $ob_level_before );
			wp_send_json_error(
				array(
					'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
				),
				403 // HTTP 403 Forbidden.
			);
			return;
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
			wp_send_json_error(
				array(
					'message' => __( 'Export session data corrupted. Please start again.', 'sscribe-export-site-pages' ),
				),
				500 // HTTP 500 Internal Server Error.
			);
			return;
		}

		if ( ! empty( $session['cancelled'] ) ) {
			$this->logger->debug( 'Export was cancelled' );
			$this->restore_ob_level( $ob_level_before );
			$this->cleanup_cancelled_export( $session );
			$this->session->delete( $session_id );
			$this->release_lock( $session_id );
			wp_send_json_error(
				array(
					'message'   => __( 'Export was cancelled.', 'sscribe-export-site-pages' ),
					'cancelled' => true,
				),
				499 // HTTP 499 Client Closed Request (non-standard but conveys meaning).
			);
			return;
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
		$memory_paused           = false; // Track if batch was paused due to memory.
		$timeout_paused          = false; // Track if batch was paused due to timeout.
		$processed_in_this_batch = 0;

		foreach ( $batch as $page_id ) {
			// Timeout check - pause batch if approaching PHP max_execution_time.
			// This prevents fatal timeout errors during batch processing.
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

			// Only pause for memory if we have successfully processed at least 1 page in this request.
			// This prevents an infinite loop where the first page continually aborts due to high base memory.
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

			// Check if previous batch crashed while processing this page.
			// Instead of skipping, clear the stale "processing" status and retry.
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

			// Pre-export memory check - skip page if memory critically low.
			// This prevents fatal memory errors during export.
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

					// Track per-format timing for adaptive metrics.
					$format_elapsed         = microtime( true ) - $format_start;
					$format_key             = 'format_time_' . $format;
					$session[ $format_key ] = ( $session[ $format_key ] ?? 0 ) + $format_elapsed;

					if ( $result->is_success() ) {
						$file_path = $result->get_data()['path'] ?? '';
						$file_size = $result->get_data()['size'] ?? 0;

						// Validate exported file exists and has reasonable size.
						$actual_size = ( ! empty( $file_path ) && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;
						$min_sizes   = array(
							'docx'     => 1024,  // 1KB minimum for valid DOCX.
							'pdf'      => 512,   // 500 bytes minimum for valid PDF.
							'html'     => 100,   // 100 bytes minimum for valid HTML.
							'markdown' => 50,    // 50 bytes minimum for valid Markdown.
						);
						$min_size    = $min_sizes[ $format ] ?? 100;

						if ( $actual_size < $min_size ) {
							// File is too small — likely corrupted or empty.
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

							// Use actual file size for metrics.
							$format_size_key             = 'format_size_' . $format;
							$session[ $format_size_key ] = ( $session[ $format_size_key ] ?? 0 ) + $actual_size;

							// Track per-format page count for accurate metrics.
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
				// Catch both Errors and Exceptions to prevent export crashes.
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
					$diagnosis = $this->diagnostics->diagnose_page_error( $page_id, $fmt, $err_msg, $context );

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

			// Free up memory for the next iterations in large batches.
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $page_id );
			}

			// CRITICAL: Explicitly release page data and exporter objects to prevent memory accumulation.
			// PHPWord and DOMDocument objects can consume 2-5MB per page and are not automatically
			// garbage collected between batch iterations due to circular references.
			$page_data = null;
			$exporter  = null;
			unset( $page_data, $exporter );

			++$processed;
			++$processed_in_this_batch;

			// Force garbage collection every 3 pages to reclaim memory from circular references.
			// This is critical for PHPWord objects which retain references to parent documents.
			if ( 0 === $processed % 3 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

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

		// CRITICAL: Cap error arrays to prevent OOM on large exports (500+ pages).
		// Errors beyond the limit remain logged to disk via export_log but are not
		// stored in the session to keep memory bounded.
		$total_errors              = count( $errors );
		$total_structured_errors   = count( $structured_errors );
		$errors_trimmed            = $total_errors > self::MAX_STORED_ERRORS;
		$structured_errors_trimmed = $total_structured_errors > self::MAX_STORED_ERRORS;

		if ( $errors_trimmed ) {
			$trimmed_count = $total_errors - self::MAX_STORED_ERRORS;
			$errors        = array_slice( $errors, 0, self::MAX_STORED_ERRORS );
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
			$structured_errors   = array_slice( $structured_errors, 0, self::MAX_STORED_ERRORS );
			$structured_errors[] = array(
				'page_id'     => 0,
				'page_title'  => __( 'Summary', 'sscribe-export-site-pages' ),
				'message'     => sprintf(
					/* translators: %d: Number of additional structured errors not stored. */
					__( '%d additional errors occurred — full details available in the export log.', 'sscribe-export-site-pages' ),
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

		// Build session update data, only including format metrics that have values.
		$update_data = array(
			'processed'         => $processed,
			'errors'            => $errors,
			'structured_errors' => $structured_errors,
			'start_time'        => $start_time,
		);

		// Persist per-format timing/size metrics across batches.
		$format_keys = array( 'format_time_docx', 'format_time_pdf', 'format_time_html', 'format_time_markdown', 'format_size_docx', 'format_size_pdf', 'format_size_html', 'format_size_markdown', 'format_pages_docx', 'format_pages_pdf', 'format_pages_html', 'format_pages_markdown' );
		foreach ( $format_keys as $key ) {
			if ( isset( $session[ $key ] ) ) {
				$update_data[ $key ] = $session[ $key ];
			}
		}

		$update_result = $this->session->update( $session_id, $update_data );

		$this->logger->debug( 'Session update result', array( 'success' => $update_result ) );

		// Flush export log to disk after each batch.
		if ( $this->export_log ) {
			$this->export_log->flush();
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
			$this->session->update( $session_id, $update_data );
			$this->release_lock( $session_id );
			$this->restore_ob_level( $ob_level_before );

			$error_diagnostics = array();
			if ( ! empty( $structured_errors ) ) {
				$error_diagnostics = $this->build_error_diagnostics_payload( $structured_errors, $errors );
			}

			wp_send_json_success(
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
			return;
		}

		// Release the lock so the next batch request can proceed.
		$this->release_lock( $session_id );

		$this->restore_ob_level( $ob_level_before );

		// Build response with pause status for memory or timeout.
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

		wp_send_json_success( $response );
	}

	/**
	 * Restore output buffering to the target level.
	 *
	 * Uses ob_end_clean() (not ob_end_flush()) since we always want to discard
	 * buffers, never flush them. Also handles the "headers already sent" scenario
	 * gracefully — if output was already started by a third-party plugin (e.g. WPML
	 * emitting whitespace/BOM before AJAX response), the buffer is discarded and we
	 * allow the JSON response to continue, relying on the ob_start() guard above to
	 * isolate each handler.
	 *
	 * @param int $target_level The ob level to restore to.
	 * @return void
	 */
	private function restore_ob_level( int $target_level ): void {
		// First, unconditionally drain ALL buffers down to target level.
		// This ensures that any pre-existing output (e.g. from WPML) is discarded
		// before we attempt to send the JSON response. Doing this first prevents
		// corrupted AJAX responses where JSON is prepended with stray HTML/PHP warnings.
		while ( ob_get_level() > $target_level ) {
			ob_end_clean();
		}

		// If headers were already sent by a third-party plugin (WPML BOM/whitespace),
		// the main output may still contain garbage before our JSON payload.
		// Attempt one final drain of any remaining buffers — this is a best-effort
		// recovery so the client at least gets parseable JSON.
		if ( headers_sent() && ob_get_level() > $target_level ) {
			while ( ob_get_level() > $target_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * AJAX handler: Finalize export and create ZIP.
	 *
	 * Called by the JS pollFinalize() function after all pages are processed.
	 * Checks capability, rate limit, nonce, session validity, and finalizing status
	 * before delegating to the private finalize_export() method.
	 *
	 * @return void
	 */
	public function ajax_finalize_export(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				),
				429 // HTTP 429 Too Many Requests.
			);
			return;
		}

		$session_id = isset( $_POST['session_id'] )
			? sanitize_text_field( wp_unslash( $_POST['session_id'] ) )
			: '';

		if ( empty( $session_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ),
				),
				400
			);
			return;
		}

		$session = $this->session->get( $session_id );

		if ( ! $session ) {
			wp_send_json_error(
				array(
					'code'    => 'not_finalizing',
					'message' => __( 'Export session not found. Please start again.', 'sscribe-export-site-pages' ),
				),
				404
			);
			return;
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		$status = $session['status'] ?? '';
		if ( 'finalizing' !== $status ) {
			wp_send_json_error(
				array(
					'code'    => 'not_finalizing',
					'message' => __( 'Export is not in the finalizing state.', 'sscribe-export-site-pages' ),
				),
				409 // HTTP 409 Conflict.
			);
			return;
		}

		$this->finalize_export( $session_id, $session );
	}

	/**
	 * Finalize the export by creating ZIP and returning download URL.
	 *
	 * @param string $session_id The session ID.
	 * @param array  $session    The session data.
	 * @return void
	 */
	private function finalize_export( string $session_id, array $session ): void {
		// Increase time limit for ZIP finalization.
		// Creating a ZIP with many PDF files (each 1-5MB) is I/O intensive and can
		// exceed the default batch time limit, causing a 404 "session not found" error.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklisted -- ZIP creation with many large files (especially PDF) requires extended time.
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

			// Build language metadata for export index (used by Recent Exports UI).
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

				// Clean up temp directory to prevent disk space leak on failure.
				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				// Clean up the session and lock so user can retry.
				$this->session->delete( $session_id );
				$this->release_lock( $session_id );

				// Run self-heal to clear any orphaned data from this failed export.
				$this->diagnostics->self_heal();

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
						$this->diagnostics->diagnose_page_error(
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

				wp_send_json_error( $error_response, 500 ); // HTTP 500 Internal Server Error.
				return;
			}

			// Verify ZIP contains files. An empty ZIP means something went wrong during packaging.
			$zip             = new ZipArchive();
			$zip_open        = $zip->open( $zip_path );
			$total_files_zip = 0;
			if ( true === $zip_open ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
				$total_files_zip = $zip->numFiles;
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

				// Remove the empty ZIP.
				if ( file_exists( $zip_path ) ) {
					wp_delete_file( $zip_path );
				}

				// Clean up temp directory.
				if ( ! empty( $session['temp_dir'] ) && is_dir( $session['temp_dir'] ) ) {
					$this->zip_handler->delete_directory( $session['temp_dir'] );
				}

				// DO NOT delete the session so the user can retry.
				$this->release_lock( $session_id );

				wp_send_json_error(
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
				return;
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

			$error_count       = count( $session['errors'] ?? array() );
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

			// Save adaptive metrics for future time/size estimates.
			$formats = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
			foreach ( $formats as $fmt ) {
				$format_time_key  = 'format_time_' . $fmt;
				$format_size_key  = 'format_size_' . $fmt;
				$format_pages_key = 'format_pages_' . $fmt;

				$elapsed_seconds = (float) ( $session[ $format_time_key ] ?? 0 );
				$total_bytes     = (int) ( $session[ $format_size_key ] ?? 0 );
				$pages_exported  = (int) ( $session[ $format_pages_key ] ?? 0 );
				$total_mb        = $total_bytes / 1048576;

				if ( $pages_exported > 0 && $elapsed_seconds > 0 ) {
					$this->save_export_metrics( $fmt, $pages_exported, $elapsed_seconds, $total_mb );
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

			// CRITICAL: Delete session ONLY after everything else succeeded and right before sending success.
			// If anything above throws, the session remains intact so the client can retry.
			$this->release_lock( $session_id );
			$this->session->delete( $session_id );
			wp_send_json_success( $response );
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

			// DO NOT delete the session on crash so the client can retry.
			$this->release_lock( $session_id );

			wp_send_json_error(
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
	 * AJAX handler: System health check.
	 *
	 * For unauthenticated requests: returns minimal reachability check.
	 * For authenticated requests: returns full diagnostics + boot state.
	 * Rate limiting is applied to authenticated requests to prevent abuse.
	 *
	 * @return void
	 */
	public function ajax_health_check(): void {
		// For unauthenticated requests, return minimal reachability check only.
		if ( ! is_user_logged_in() ) {
			wp_send_json_success(
				array(
					'status'      => 'ok',
					'server_time' => current_time( 'mysql' ),
					'server_utc'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return;
		}

		// Authenticated requests require export capability.
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		// Authenticated requests are rate-limited to prevent abuse.
		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				),
				429 // HTTP 429 Too Many Requests.
			);
			return;
		}

		$diagnostics = $this->diagnostics->check_ajax_health();
		$boot        = $this->diagnostics->get_boot_diagnostics();

		wp_send_json_success(
			array(
				'ajax_health' => $diagnostics,
				'boot_state'  => $boot,
				'server_time' => current_time( 'mysql' ),
				'server_utc'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * AJAX handler: Download ZIP file.
	 *
	 * Note: This endpoint serves binary file data, so error responses use wp_die()
	 * instead of JSON. This is intentional for proper file download handling.
	 * HTTP status codes are set appropriately for each error type.
	 *
	 * @return void
	 */
	public function ajax_download(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) );
		}

		check_ajax_referer( 'sscribe_download', 'nonce' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		$export_dir = ''; // Initialize to satisfy PHPStan (assigned in try block below).

		// SECURITY: Wrap get_export_dir() to catch potential \InvalidArgumentException
		// from SScribe_Security::protect_directory() (path scope validation).
		try {
			$export_dir = $this->zip_handler->get_export_dir();

			$file_path = $export_dir . '/' . $filename;

			if ( empty( $filename ) || ! file_exists( $file_path ) ) {
				status_header( 404 );
				wp_die( esc_html__( 'File not found or has expired. Please generate a new export.', 'sscribe-export-site-pages' ) );
			}

			$real_path = realpath( $file_path );
			$real_dir  = realpath( $export_dir );

			// SECURITY: Require trailing separator to prevent path-prefix attacks
			// (e.g., /var/www/exports_evil passing for /var/www/exports).
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

			$ascii_filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename );

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $ascii_filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
			header( 'Content-Length: ' . filesize( $file_path ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'X-Content-Type-Options: nosniff' );

			if ( ob_get_level() ) {
				ob_end_clean();
			}

			// Log successful download for audit trail.
			$this->audit_log( 'download', array( 'filename' => $filename ) );

			// SECURITY FIX: Re-check file existence immediately before read to prevent TOCTOU race condition.
			// File could be deleted by cleanup cron between initial check and actual read.
			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				status_header( 404 );
				wp_die( esc_html__( 'File no longer available. Please regenerate the export.', 'sscribe-export-site-pages' ) );
			}

		flush();
		// Force the script to continue even if client disconnects (prevents partial ZIP sends).
		ignore_user_abort( true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct download
		$read_result = readfile( $file_path );
		if ( false === $read_result ) {
			$this->logger->warning(
				'readfile() returned false — possible partial read',
				array(
					'filename' => $filename,
					'path'     => $file_path,
				)
			);
		}
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
	 * AJAX handler: Get status counts for a language and post type.
	 *
	 * @return void
	 */
	public function ajax_get_status_counts(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		$language  = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$counts = $this->collector->get_post_status_counts( $language, $post_type );

		wp_send_json_success( array( 'counts' => $counts ) );
	}

	/**
	 * AJAX handler: Cancel an in-progress export.
	 *
	 * @return void
	 */
	public function ajax_cancel_export(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		$session = $this->session->get( $session_id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => __( 'Session not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$session['cancelled'] = true;
		$this->session->update( $session_id, $session );
		$this->cleanup_cancelled_export( $session );
		$this->session->delete( $session_id );
		delete_transient( 'sscribe_lock_' . $session_id );

		wp_send_json_success( array( 'message' => __( 'Export cancelled.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * Clean up temporary files for a cancelled export.
	 *
	 * @param array $session Session data.
	 * @return void
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
	 * AJAX handler: Delete an export file.
	 *
	 * @return void
	 */
	public function ajax_delete_export(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		check_ajax_referer( 'sscribe_download', 'nonce' );

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		$exports = get_option( 'sscribe_export_index', array() );

		if ( ! isset( $exports[ $filename ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Export not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		$export_info    = $exports[ $filename ] ?? array();
		$stored_user_id = isset( $export_info['user_id'] ) ? (int) (string) $export_info['user_id'] : 0;
		if ( $stored_user_id <= 0 || get_current_user_id() !== $stored_user_id ) {
				$this->audit_log( 'delete_access_denied', array( 'filename' => $filename ) );
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
				return;
		}

		// SECURITY: Wrap get_export_dir() to catch potential \InvalidArgumentException
		// from SScribe_Security::protect_directory() (path scope validation).
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
			wp_send_json_error(
				array( 'message' => __( 'Server misconfiguration: export directory is invalid.', 'sscribe-export-site-pages' ) ),
				500
			);
			return;
		}

		$file_path = $export_dir . '/' . $filename;

		// Defense-in-depth: verify file is within export directory.
		$real_path = realpath( $file_path );
		$real_dir  = realpath( $export_dir );
		if ( ! $real_path || ! $real_dir || ! str_starts_with( $real_path, $real_dir . '/' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file path.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		unset( $exports[ $filename ] );
		update_option( 'sscribe_export_index', $exports, false );

		// Also delete the associated log file if it exists.
		SScribe_Export_Log::delete_by_filename( $filename );

		$this->audit_log( 'export_deleted', array( 'filename' => $filename ) );

		wp_send_json_success( array( 'message' => __( 'Export deleted.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * AJAX handler: Get export log details.
	 *
	 * @return void
	 */
	public function ajax_get_export_log(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		check_ajax_referer( 'sscribe_download', 'nonce' );

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		$exports = get_option( 'sscribe_export_index', array() );

		if ( ! isset( $exports[ $filename ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Export not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		$export_info = $exports[ $filename ];
		if ( isset( $export_info['user_id'] ) && get_current_user_id() !== (int) $export_info['user_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$log_data = SScribe_Export_Log::get_log_by_filename( $filename );

		if ( ! $log_data ) {
			wp_send_json_error( array( 'message' => __( 'Log not found for this export.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		$structured_errors = $this->build_structured_errors_from_log( $log_data );
		$diagnostics       = $this->build_error_diagnostics_payload( $structured_errors, wp_list_pluck( $log_data['errors'] ?? array(), 'message' ) );

		wp_send_json_success(
			array(
				'log'         => $log_data,
				'diagnostics' => $diagnostics,
			)
		);
	}

	/**
	 * Build a normalized diagnostics payload for AJAX responses.
	 *
	 * @param array $structured_errors Structured page error entries.
	 * @param array $string_errors     Legacy string errors.
	 * @return array
	 */
	private function build_error_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		$categories      = array();
		$guidance_map    = array();
		$fix_steps_map   = array();
		$technical_items = array();
		$diagnostics     = array();
		$html_sizes      = array();
		$memory_peaks    = array();
		$memory_limits   = array();
		$exception_types = array();
		$formats         = array();
		$page_ids        = array();
		$libxml_count    = 0;

		foreach ( $structured_errors as $entry ) {
			$page_id       = (int) ( $entry['page_id'] ?? 0 );
			$page_ids[]    = $page_id;
			$format_errors = isset( $entry['errors'] ) && is_array( $entry['errors'] ) ? $entry['errors'] : array();

			if ( ! empty( $entry['diagnostics'] ) && is_array( $entry['diagnostics'] ) ) {
				foreach ( $entry['diagnostics'] as $diagnosis ) {
					$diagnostics[] = $diagnosis;
				}
			} else {
				foreach ( $format_errors as $format_error ) {
					$diagnostics[] = $this->diagnostics->diagnose_page_error(
						$page_id,
						strtolower( $format_error['format'] ?? 'unknown' ),
						$format_error['message'] ?? '',
						is_array( $format_error['context'] ?? null ) ? $format_error['context'] : array()
					);
				}
			}

			foreach ( $format_errors as $format_error ) {
				$context   = is_array( $format_error['context'] ?? null ) ? $format_error['context'] : array();
				$formats[] = $format_error['format'] ?? 'UNKNOWN';

				if ( isset( $context['html_size'] ) ) {
					$html_sizes[] = (int) $context['html_size'];
				}

				if ( isset( $context['memory_peak'] ) ) {
					$memory_peaks[] = (int) $context['memory_peak'];
				}

				if ( ! empty( $context['memory_limit'] ) ) {
					$memory_limits[] = (string) $context['memory_limit'];
				}

				if ( ! empty( $context['exception_class'] ) ) {
					$exception_types[] = (string) $context['exception_class'];
				}

				if ( ! empty( $context['libxml_errors'] ) && is_array( $context['libxml_errors'] ) ) {
					$libxml_count += count( $context['libxml_errors'] );
				}

				if ( ! empty( $context ) ) {
					$technical_items[] = array(
						'page_id'    => $page_id,
						'page_title' => $entry['page_title'] ?? '',
						'format'     => $format_error['format'] ?? 'UNKNOWN',
						'category'   => $format_error['category'] ?? 'unknown',
						'context'    => $context,
					);
				}
			}
		}

		foreach ( $diagnostics as $diagnosis ) {
			$category                = $diagnosis['category'] ?? 'unknown';
			$categories[ $category ] = true;

			$guidance = $this->get_error_guidance_for_category( $category );
			if ( ! empty( $guidance ) ) {
				$guidance_map[ $guidance ] = true;
			}

			$fixes = $diagnosis['fix'] ?? array();
			if ( is_array( $fixes ) ) {
				foreach ( $fixes as $fix_step ) {
					$fix_steps_map[ $fix_step ] = true;
				}
			}

			$technical = $diagnosis['technical'] ?? array();
			if ( ! empty( $technical ) ) {
				$technical_items[] = array(
					'page_id'  => $diagnosis['page_id'] ?? 0,
					'format'   => strtoupper( $diagnosis['format'] ?? 'unknown' ),
					'category' => $category,
					'context'  => $technical,
				);
			}
		}

		if ( empty( $diagnostics ) && ! empty( $string_errors ) ) {
			foreach ( $string_errors as $error_message ) {
				$diagnosis     = $this->diagnostics->diagnose_page_error( 0, 'system', (string) $error_message );
				$diagnostics[] = $diagnosis;
				$categories[ $diagnosis['category'] ?? 'unknown' ] = true;
				$guidance = $this->get_error_guidance_for_category( $diagnosis['category'] ?? 'unknown' );
				if ( ! empty( $guidance ) ) {
					$guidance_map[ $guidance ] = true;
				}
				foreach ( $diagnosis['fix'] ?? array() as $fix_step ) {
					$fix_steps_map[ $fix_step ] = true;
				}
			}
		}

		return array(
			'total_errors' => count( $string_errors ),
			'categories'   => array_keys( $categories ),
			'guidance'     => implode( "\n\n", array_keys( $guidance_map ) ),
			'fix_steps'    => array_keys( $fix_steps_map ),
			'technical'    => array(
				'formats'            => array_unique( $formats ),
				'page_ids_count'     => count( array_unique( $page_ids ) ),
				'max_html_size'      => empty( $html_sizes ) ? 0 : max( $html_sizes ),
				'max_memory_peak'    => empty( $memory_peaks ) ? 0 : max( $memory_peaks ),
				'memory_limits'      => array_unique( $memory_limits ),
				'exceptions'         => array_unique( $exception_types ),
				'libxml_error_count' => $libxml_count,
				'entries'            => $technical_items,
			),
			'entries'      => $diagnostics,
		);
	}

	/**
	 * Build structured error entries from log data.
	 *
	 * @param array $log_data Export log data.
	 * @return array
	 */
	private function build_structured_errors_from_log( array $log_data ): array {
		$structured_errors = array();
		$pages             = isset( $log_data['pages'] ) && is_array( $log_data['pages'] ) ? $log_data['pages'] : array();

		foreach ( $pages as $page_id => $page ) {
			$page_errors = array();
			$formats_raw = isset( $page['formats'] ) && is_array( $page['formats'] ) ? $page['formats'] : array();

			// Normalize: handle both plain array ['docx','pdf'] and
			// associative array ['docx' => ['success'=>true,...]].
			$formats = array();
			foreach ( $formats_raw as $key => $value ) {
				if ( is_int( $key ) && is_string( $value ) ) {
					// Plain format-name array — no per-format detail available.
					$formats[ $value ] = array(
						'success' => true,
						'file'    => '',
						'error'   => '',
					);
				} else {
					$formats[ $key ] = $value;
				}
			}

			foreach ( $formats as $format => $format_data ) {
				if ( ! is_array( $format_data ) ) {
					continue;
				}
				if ( ! empty( $format_data['success'] ) || empty( $format_data['error'] ) ) {
					continue;
				}

				$page_errors[] = array(
					'format'   => strtoupper( (string) $format ),
					'message'  => (string) $format_data['error'],
					'category' => 'unknown',
					'context'  => array(
						'page_id'    => (int) $page_id,
						'page_title' => $page['title'] ?? '',
						'memory'     => $page['memory'] ?? '',
					),
				);
			}

			if ( empty( $page_errors ) && ! empty( $page['error'] ) ) {
				$page_errors[] = array(
					'format'   => 'SYSTEM',
					'message'  => (string) $page['error'],
					'category' => 'unknown',
					'context'  => array(
						'page_id'    => (int) $page_id,
						'page_title' => $page['title'] ?? '',
						'memory'     => $page['memory'] ?? '',
					),
				);
			}

			if ( empty( $page_errors ) ) {
				continue;
			}

			$structured_errors[] = array(
				'page_id'    => (int) $page_id,
				'page_title' => $page['title'] ?? '',
				'message'    => $page['error'] ?? '',
				'errors'     => $page_errors,
				'time'       => $page['end_time'] ?? '',
			);
		}

		return $structured_errors;
	}

	/**
	 * Get user-facing guidance for an error category.
	 *
	 * @param string $category Error category.
	 * @return string
	 */
	private function get_error_guidance_for_category( string $category ): string {
		$guidance_map = array(
			'memory_exhausted'    => __( 'The server ran out of memory during export. Large PDF renders often need a higher PHP memory limit.', 'sscribe-export-site-pages' ),
			'timeout'             => __( 'The export is hitting a server time limit before rendering can finish. Reduce load or increase execution time.', 'sscribe-export-site-pages' ),
			'pdf_generation'      => __( 'mPDF could not render the page successfully. Review the technical details for HTML size, memory usage, and libxml parsing problems.', 'sscribe-export-site-pages' ),
			'pdf_missing_library' => __( 'The mPDF library is missing from the plugin install, so PDF export cannot start.', 'sscribe-export-site-pages' ),
			'pdf_filesystem'      => __( 'The PDF was generated but could not be written to disk. Review filesystem access and output path details.', 'sscribe-export-site-pages' ),
			'permissions'         => __( 'The server does not have permission to write required export files. Check upload directory access.', 'sscribe-export-site-pages' ),
			'zip_extension'       => __( 'ZIP creation failed because the server is missing ZIP support or the archive step could not complete.', 'sscribe-export-site-pages' ),
			'zip_creation'        => __( 'The export finished processing pages but failed while packaging the ZIP archive.', 'sscribe-export-site-pages' ),
			'docx_generation'     => __( 'DOCX generation failed for at least one page. Complex content or resource pressure may be involved.', 'sscribe-export-site-pages' ),
			'critical_error'      => __( 'A low-level PHP error interrupted the export. Review the technical context and server logs for the failing component.', 'sscribe-export-site-pages' ),
			'unknown'             => __( 'Review the diagnostics below and your server error log for the most specific failure details.', 'sscribe-export-site-pages' ),
		);

		return $guidance_map[ $category ] ?? $guidance_map['unknown'];
	}

	/**
	 * AJAX handler: Clear stuck sessions.
	 *
	 * @return void
	 */
	public function ajax_clear_session(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

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
			// Clean up any orphaned lock transients.
			$this->cleanup_user_locks();
		} else {
			$this->session->cleanup_expired( 60 );
			$this->logger->debug( 'Cleared expired sessions for user', array( 'user_id' => $user_id ) );
		}

		wp_send_json_success( array( 'message' => __( 'Session cleared.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * Clean up orphaned lock transients from the database.
	 *
	 * IMPORTANT: When called without user_id, only expired locks are removed.
	 * This prevents accidentally deleting active locks from other users.
	 *
	 * @param int|null $user_id Optional user ID to clean specific user's locks.
	 * @return void
	 */
	private function cleanup_user_locks( ?int $user_id = null ): void {
		global $wpdb;

		if ( null !== $user_id ) {
			// Sessions are stored as raw wp_options (sscribe_session_*), not transients.
			$session_pattern = $wpdb->esc_like( 'sscribe_session_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation.
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
					$session_pattern
				)
			);

			foreach ( $sessions as $session ) {
				// Try JSON first (current format).
				$data = json_decode( $session->option_value, true );

				// SECURITY: Do NOT use maybe_unserialize() here — it enables object injection.
				// Legacy PHP-serialized sessions that fail JSON decode are skipped intentionally.
				// Those sessions will be naturally cleaned up by the 4-hour expiry in SScribe_Session::cleanup_expired().
				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					if ( isset( $data['session_id'] ) ) {
						delete_transient( 'sscribe_lock_' . $data['session_id'] );
					}
					delete_option( $session->option_name );
				}
			}
			return;
		}

		// Only delete EXPIRED locks when called without user_id.
		// This prevents race conditions where active exports lose their locks.
		$now                  = time();
		$lock_timeout_pattern = $wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation.
		$expired_locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$lock_timeout_pattern,
				$now
			)
		);

		foreach ( $expired_locks as $expired ) {
			// Extract the session_id from the timeout option name.
			$session_id = str_replace( '_transient_timeout_sscribe_lock_', '', $expired->option_name );
			delete_transient( 'sscribe_lock_' . $session_id );
		}
	}

	/**
	 * Safely release a lock by verifying token ownership.
	 *
	 * This prevents race conditions where one process could delete
	 * another process's lock.
	 *
	 * @param string $session_id The session ID for the lock.
	 * @return bool True if lock was released, false if not owned or doesn't exist.
	 */
	private function release_lock( string $session_id ): bool {
		$lock_key = 'sscribe_lock_' . $session_id;
		$lock     = get_transient( $lock_key );

		if ( ! $lock ) {
			// Lock doesn't exist, nothing to release.
			return true;
		}

		// Parse lock value: format is "timestamp|token".
		$lock_parts = explode( '|', $lock );
		$lock_token = $lock_parts[1] ?? '';

		// Only release if we own the lock (token matches).
		if ( $this->current_lock_token && $lock_token === $this->current_lock_token ) {
			delete_transient( $lock_key );
			$this->current_lock_token = null;
			return true;
		}

		// We don't own this lock.
		return false;
	}

	/**
	 * Run pre-flight diagnostics before export starts.
	 *
	 * Checks system requirements, permissions, and resources.
	 *
	 * @param array $formats Requested export formats.
	 * @return array Array with 'status' (ready/warning/error) and 'checks' array.
	 */
	/**
	 * AJAX handler: Run pre-flight diagnostics.
	 *
	 * @return void
	 */
	public function ajax_preflight_check(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization via array_map on next line.
		$formats_raw   = isset( $_POST['formats'] ) ? wp_unslash( (array) $_POST['formats'] ) : array();
		$formats_input = array_map( 'sanitize_text_field', $formats_raw );
		$formats       = ! empty( $formats_input ) ? $formats_input : array( 'docx' );

		$page_count = isset( $_POST['page_count'] ) ? absint( $_POST['page_count'] ) : 0;

		$diagnostics = $this->diagnostics->run_preflight( $page_count, $formats );

		$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
		if ( $sscribe_is_debug ) {
			$diagnostics['debug_info'] = array(
				'php_version'   => PHP_VERSION,
				'memory_limit'  => ini_get( 'memory_limit' ),
				'max_execution' => ini_get( 'max_execution_time' ),
				'upload_dir'    => basename( $this->zip_handler->get_export_dir() ),
			);
		}

		wp_send_json_success( $diagnostics );
	}

	/**
	 * AJAX handler: Get export preview.
	 *
	 * @return void
	 */
	public function ajax_get_export_preview(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'docx';

		$allowed_formats = array( 'docx', 'pdf', 'html', 'markdown' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'docx';
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$pages      = $this->collector->get_page_ids( $language, $post_status, $post_type );
		$page_count = count( $pages );

		// Adaptive time estimation based on actual export history.
		// Falls back to conservative baseline estimates for first export.
		$seconds_per_page = $this->get_adaptive_seconds_per_page( $format );
		$total_seconds    = $page_count * $seconds_per_page;

		if ( $total_seconds < 60 ) {
			$estimated_time = sprintf(
				/* translators: %d: Number of seconds. */
				_n( '%d second', '%d seconds', $total_seconds, 'sscribe-export-site-pages' ),
				ceil( $total_seconds )
			);
		} else {
			$minutes        = ceil( $total_seconds / 60 );
			$estimated_time = sprintf(
				/* translators: %d: Number of minutes. */
				_n( '%d minute', '%d minutes', $minutes, 'sscribe-export-site-pages' ),
				$minutes
			);
		}

		// Adaptive file size estimation based on actual export history.
		$megabytes_per_page = $this->get_adaptive_mb_per_page( $format );
		$size_mb            = $page_count * $megabytes_per_page;
		if ( $size_mb < 1 ) {
			$file_size_estimate = round( $size_mb * 1024 ) . ' KB';
		} else {
			$file_size_estimate = round( $size_mb, 1 ) . ' MB';
		}

		$sample_page = null;
		if ( ! empty( $pages ) ) {
			$sample_id   = $pages[0];
			$sample_post = get_post( $sample_id );
			if ( $sample_post ) {
				$sample_page = array(
					'title'   => $sample_post->post_title,
					'url'     => get_permalink( $sample_id ),
					'content' => wp_kses_post( wp_trim_words( strip_shortcodes( $sample_post->post_content ), 50 ) ),
				);
			}
		}

		$preview_data = array(
			'total_pages'        => $page_count,
			'format'             => $format,
			'estimated_time'     => $estimated_time,
			'file_size_estimate' => $file_size_estimate,
			'language'           => '' !== $language ? $language : __( 'All Languages', 'sscribe-export-site-pages' ),
			'post_status'        => $post_status,
		);

		if ( $sample_page ) {
			$preview_data = array_merge( $preview_data, $sample_page );
		}

		wp_send_json_success( $preview_data );
	}

	/**
	 * AJAX handler: Get recent exports list.
	 *
	 * @return void
	 */
	public function ajax_get_recent_exports(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		$exports = get_option( 'sscribe_export_index', array() );
		$user_id = get_current_user_id();

		/**
		 * List of recent exports.
		 *
		 * @var array<int, array{filename: string, url: string, size: int, time: int, date: string, lang_code: string, lang_name: string, flag_url: string}>
		 */
		$result = array();

		foreach ( $exports as $filename => $data ) {
			if ( isset( $data['user_id'] ) && (int) $data['user_id'] !== $user_id ) {
				continue;
			}

			$file_path = $this->zip_handler->get_export_dir() . '/' . $filename;
			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			$result[] = array(
				'filename'       => $filename,
				'url'            => $this->zip_handler->get_ajax_download_url( $filename ),
				'size'           => filesize( $file_path ),
				'size_formatted' => size_format( filesize( $file_path ) ),
				'time'           => $data['time'] ?? filemtime( $file_path ),
				'date'           => wp_date( ( get_option( 'date_format' ) ?: 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ?: 'H:i' ), $data['time'] ?? filemtime( $file_path ) ),
				'lang_code'      => $data['lang_code'] ?? '',
				'lang_name'      => $data['lang_name'] ?? '',
				'flag_url'       => $data['flag_url'] ?? '',
			);
		}

		// Sort by time descending (newest first).
		$sorted = $result;
		uasort(
			$sorted,
			/**
			 * Compare exports by time for sorting.
			 *
			 * @param array{filename: string, url: string, size: int, time: int, date: string, lang_code: string, lang_name: string, flag_url: string} $a First export.
			 * @param array{filename: string, url: string, size: int, time: int, date: string, lang_code: string, lang_name: string, flag_url: string} $b Second export.
			 * @return int Comparison result.
			 */
			function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		wp_send_json_success( array( 'exports' => array_slice( array_values( $sorted ), 0, 10 ) ) );
	}

	/**
	 * AJAX handler: Get support/debug information.
	 *
	 * @return void
	 */
	public function ajax_get_support_info(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		try {
			$support_info = $this->diagnostics->get_support_info();
			wp_send_json_success( $support_info );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Support info AJAX failed',
				array(
					'error' => $e->getMessage(),
					'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);

			wp_send_json_error(
				array(
					'message' => __( 'Unable to load support information right now. Please try again later.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}
	}

	/**
	 * Get adaptive seconds-per-page estimate for a format.
	 *
	 * Uses actual metrics from previous exports stored in the sscribe_export_metrics
	 * option. Falls back to conservative baseline for the first export.
	 * Blends historical average with baseline to avoid wild swings.
	 *
	 * @param string $format Export format.
	 * @return float Seconds per page estimate.
	 */
	private function get_adaptive_seconds_per_page( string $format ): float {
		// Conservative baselines for first-time exports.
		$baselines = array(
			'docx'     => 1.5,
			'pdf'      => 8.0,
			'html'     => 1.0,
			'markdown' => 0.5,
		);

		$baseline = $baselines[ $format ] ?? 2.0;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'][ $format ]['avg_seconds_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $format ]['avg_seconds_per_page'];
		$samples    = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		if ( $samples < 3 ) {
			// Not enough data — blend 70% baseline + 30% historical.
			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		// Enough data — blend 20% baseline + 80% historical for stability.
		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Get adaptive megabytes-per-page estimate for a format.
	 *
	 * @param string $format Export format.
	 * @return float MB per page estimate.
	 */
	private function get_adaptive_mb_per_page( string $format ): float {
		$baselines = array(
			'docx'     => 0.5,
			'pdf'      => 2.0,
			'html'     => 0.3,
			'markdown' => 0.1,
		);

		$baseline = $baselines[ $format ] ?? 0.5;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'][ $format ]['avg_mb_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $format ]['avg_mb_per_page'];
		$samples    = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		if ( $samples < 3 ) {
			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Save export metrics for adaptive estimation.
	 *
	 * Called after each export completes to record actual performance data.
	 * Uses exponential moving average so recent exports weigh more.
	 *
	 * @param string $format      Export format.
	 * @param int    $page_count  Number of pages exported.
	 * @param float  $elapsed_sec Total elapsed seconds.
	 * @param float  $total_mb    Total file size in MB.
	 * @return void
	 */
	private function save_export_metrics( string $format, int $page_count, float $elapsed_sec, float $total_mb ): void {
		if ( $page_count <= 0 ) {
			return;
		}

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'] ) ) {
			$metrics['formats'] = array();
		}
		if ( ! isset( $metrics['formats'][ $format ] ) ) {
			$metrics['formats'][ $format ] = array(
				'avg_seconds_per_page' => 0,
				'avg_mb_per_page'      => 0,
				'sample_count'         => 0,
			);
		}

		$new_seconds = $elapsed_sec / $page_count;
		$new_mb      = $total_mb / $page_count;

		$existing_seconds = (float) ( $metrics['formats'][ $format ]['avg_seconds_per_page'] ?? 0 );
		$existing_mb      = (float) ( $metrics['formats'][ $format ]['avg_mb_per_page'] ?? 0 );
		$samples          = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		// Exponential moving average: α = 0.3 (recent exports weigh more).
		$alpha = 0.3;

		if ( 0 === $samples ) {
			$metrics['formats'][ $format ]['avg_seconds_per_page'] = $new_seconds;
			$metrics['formats'][ $format ]['avg_mb_per_page']      = $new_mb;
		} else {
			$metrics['formats'][ $format ]['avg_seconds_per_page'] = round(
				( $existing_seconds * ( 1 - $alpha ) ) + ( $new_seconds * $alpha ),
				4
			);
			$metrics['formats'][ $format ]['avg_mb_per_page']      = round(
				( $existing_mb * ( 1 - $alpha ) ) + ( $new_mb * $alpha ),
				4
			);
		}

		++$metrics['formats'][ $format ]['sample_count'];
		$metrics['last_export'] = current_time( 'mysql' );

		update_option( 'sscribe_export_metrics', $metrics, false );
	}
}
