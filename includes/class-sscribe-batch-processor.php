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
	 * Rate limit: Maximum requests per minute per user.
	 */
	private const RATE_LIMIT_MAX = 5000;

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
		$user_id       = get_current_user_id();
		$transient_key = 'sscribe_rate_' . $user_id;
		$now           = time();

		// Administrators get a higher rate limit to support large exports.
		$rate_limit = current_user_can( apply_filters( 'sscribe_export_capability', 'manage_options' ) )
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
		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW );

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
			'ip'        => $this->get_client_ip(),
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
	 * Get client IP address.
	 *
	 * Prioritizes REMOTE_ADDR to prevent IP spoofing via HTTP headers.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( empty( $ip ) ) {
			$ip = '0.0.0.0';
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
	 * Optimize batch size based on available memory.
	 *
	 * Dynamically adjusts the batch size to prevent memory exhaustion.
	 * Estimates ~3MB per page average for DOCX generation, with 20% safety margin.
	 *
	 * @return void
	 */
	private function optimize_batch_size(): void {
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
		// - PDF: ~8MB (DomPDF + rendering).
		$memory_per_page = 1; // Base 1MB for page data collection.

		if ( in_array( 'docx', $formats, true ) ) {
			$memory_per_page += 4; // PHPWord overhead.
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$memory_per_page += 7; // DomPDF overhead.
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$memory_per_page += 0.5;
		}

		// Convert to bytes, add 50MB overhead for PHP/WordPress core.
		$total_mb = ( $page_count * $memory_per_page ) + 50;

		return $total_mb * 1024 * 1024;
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

		// SECURITY: Validate capability against whitelist to prevent malicious plugins from lowering permissions.
		if ( ! self::is_allowed_capability( $capability ) ) {
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
	 * Validate that a capability string is in the allowed whitelist.
	 *
	 * Prevents malicious plugins from lowering required capabilities via filters.
	 *
	 * @param string $capability The capability to validate.
	 * @return bool True if allowed.
	 */
	private static function is_allowed_capability( string $capability ): bool {
		$allowed = array(
			'manage_options',
			'edit_pages',
			'publish_pages',
			'delete_pages',
			'export',
		);

		return in_array( $capability, $allowed, true );
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

		if ( ! isset( $session['user_id'] ) ) {
			return true;
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

		$this->audit_log( 'export_started' );
		$this->logger->debug( '=== START EXPORT ===' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				),
				403 // HTTP 403 Forbidden.
			);
			return;
		}

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

		$this->logger->debug(
			'Export params',
			array(
				'language'    => $language,
				'post_status' => $post_status,
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

		$page_ids = $this->collector->get_page_ids( $language, $post_status );
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

		$temp_dir = $this->zip_handler->create_temp_dir();

		$session_id = $this->session->create(
			array(
				'page_ids'    => $page_ids,
				'temp_dir'    => $temp_dir,
				'total'       => $total,
				'processed'   => 0,
				'language'    => $language,
				'post_status' => $post_status,
				'formats'     => $formats,
				'errors'      => array(),
				'start_time'  => time(),
				'cancelled'   => false,
				'user_id'     => $user_id,
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
				'page_ids_sample'   => array_slice( $page_ids, 0, 20 ),
				'language'          => $language,
				'post_status'       => $post_status,
				'current_wpml_lang' => $current_lang ?? 'n/a',
				'temp_dir'          => $temp_dir,
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

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403 // HTTP 403 Forbidden.
			);
			return;
		}

		$max_time = (int) apply_filters( 'sscribe_max_execution_time', 120 );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklisted -- Required for batch processing large page content from page builders (Elementor/Divi) where default 30s timeout causes failures. This is a standard practice for export plugins.
			set_time_limit( $max_time );
		}
		wp_raise_memory_limit( 'admin' );

		// Optimize batch size based on available memory to prevent exhaustion.
		$this->optimize_batch_size();

		$ob_level_before = ob_get_level();
		ob_start();

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session    = $this->session->get( $session_id );

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
		$stale_threshold = (int) apply_filters( 'sscribe_lock_stale_threshold', 25 );

		if ( $existing_lock ) {
			// Parse existing lock: format is "timestamp|token" for atomic operations.
			$lock_parts = explode( '|', $existing_lock );
			$lock_time  = (int) $lock_parts[0];
			$lock_age   = $current_time - $lock_time;

			if ( $lock_age > $stale_threshold ) {
				// Stale lock detected - use atomic compare-and-swap via WP's transient race condition handling.
				// We attempt to acquire the lock atomically by setting a new value.
				$this->logger->debug(
					'Detected stale lock, attempting atomic acquisition',
					array(
						'session_id' => $session_id,
						'lock_age'   => $lock_age,
					)
				);
				// Set new lock with token for ownership verification.
				$lock_acquired = set_transient( $lock_key, $current_time . '|' . $lock_token, 30 );
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
			set_transient( $lock_key, $current_time . '|' . $lock_token, 30 );
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

		$page_ids   = $session['page_ids'];
		$processed  = $session['processed'];
		$total      = $session['total'];
		$temp_dir   = $session['temp_dir'];
		$errors     = isset( $session['errors'] ) ? $session['errors'] : array();
		$start_time = isset( $session['start_time'] ) ? $session['start_time'] : time();
		$formats    = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );
		$session_id = $session['session_id'] ?? '';

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
					$exporter = \SScribe_Exporter_Factory::create( $format );

					if ( ! $exporter ) {
						continue;
					}

					$result = $exporter->export( $page_data, $temp_dir, $page_index, $total );

					if ( $result->is_success() ) {
						$export_success       = true;
						$successful_formats[] = $format;
						$file_path            = $result->get_data()['path'] ?? '';

						if ( $this->export_log ) {
							$this->export_log->log_format_result( $page_id, $format, true, $file_path );
						}

						$this->logger->debug(
							ucfirst( $format ) . ' generated successfully',
							array(
								'file'    => basename( $file_path ),
								'page_id' => $page_id,
								'format'  => $format,
							)
						);
					} else {
						$export_errors[] = sprintf(
							'%s: %s',
							strtoupper( $format ),
							$result->get_error()
						);

						if ( $this->export_log ) {
							$this->export_log->log_format_result( $page_id, $format, false, '', $result->get_error() );
						}
					}
				}
			} catch ( \Error $e ) {
				// Catch fatal errors (like out of memory) that would otherwise crash the entire export.
				$error_msg = sprintf(
					/* translators: %s: Error message. */
					__( 'Critical error: %s', 'sscribe-export-site-pages' ),
					$e->getMessage()
				);
				$export_errors[] = $error_msg;

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
				$error_msg = sprintf(
					/* translators: %s: Page title. */
					__( 'Failed to generate exports for "%s".', 'sscribe-export-site-pages' ),
					$page_data['title']
				);
				$errors[] = $error_msg . ' ' . implode( ', ', $export_errors );

				if ( $this->export_log ) {
					$this->export_log->log_page_failure( $page_id, implode( '; ', $export_errors ), $formats );
				}

				$detailed_errors = array();
				foreach ( $export_errors as $format_error ) {
					$parts             = explode( ':', $format_error, 2 );
					$fmt               = strtolower( trim( $parts[0] ) );
					$err_msg           = trim( $parts[1] ?? $format_error );
					$detailed_errors[] = $this->diagnostics->diagnose_page_error( $page_id, $fmt, $err_msg );
				}

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

		$update_result = $this->session->update(
			$session_id,
			array(
				'processed'  => $processed,
				'errors'     => $errors,
				'start_time' => $start_time,
			)
		);

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
			$this->release_lock( $session_id );
			$this->restore_ob_level( $ob_level_before );
			$this->finalize_export( $session_id, $session );
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
	 * @param int $target_level The ob level to restore to.
	 * @return void
	 */
	private function restore_ob_level( int $target_level ): void {
		while ( ob_get_level() > $target_level ) {
			ob_end_clean();
		}
	}

	/**
	 * Finalize the export by creating ZIP and returning download URL.
	 *
	 * @param string $session_id The session ID.
	 * @param array  $session    The session data.
	 * @return void
	 */
	private function finalize_export( string $session_id, array $session ): void {
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

		$lang_code = ! empty( $session['language'] ) ? $session['language'] : 'all';
		$site_slug = sanitize_file_name( get_bloginfo( 'name' ) );
		$formats   = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );

		$format_suffix = count( $formats ) > 1 ? 'ALL' : strtoupper( $formats[0] );
		$pages_count   = $session['total'];

		$zip_name = 'sscribe-export-' . $lang_code . '-' . $site_slug . '-' . gmdate( 'Y-m-d-His' ) . '-pages-' . $pages_count . '-' . $format_suffix;

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

		$zip_path = $this->zip_handler->create_zip( $session['temp_dir'], $zip_name, $formats );

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
			delete_transient( 'sscribe_lock_' . $session_id );

			// Run self-heal to clear any orphaned data from this failed export.
			$this->diagnostics->self_heal();

			$error_response = array(
				'message' => __( 'Failed to create ZIP package. Please try again.', 'sscribe-export-site-pages' ),
			);

			$sscribe_is_debug = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;
			if ( $sscribe_is_debug ) {
				$error_response['debug_info'] = array(
					'temp_dir_exists' => is_dir( $session['temp_dir'] ),
					'files_in_temp'   => $files_before,
				);
			}

			wp_send_json_error( $error_response, 500 ); // HTTP 500 Internal Server Error.
			return;
		}

		$this->logger->debug(
			'ZIP created successfully',
			array(
				'zip_path' => $zip_path,
				'zip_size' => size_format( filesize( $zip_path ) ),
			)
		);

		$zip             = new ZipArchive();
		$zip_open        = $zip->open( $zip_path );
		$files_in_zip    = array();
		$total_files_zip = 0;
		if ( true === $zip_open ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			$total_files_zip = $zip->numFiles;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$filename       = $zip->getNameIndex( $i );
				$files_in_zip[] = $filename;
			}
			$zip->close();
		}
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

		$this->session->delete( $session_id );

		$error_count = count( $session['errors'] ?? array() );

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

		$log_summary = $this->export_log ? $this->export_log->get_summary() : array();

		$response = array(
			'status'       => 'complete',
			'processed'    => $session['total'],
			'total'        => $session['total'],
			'percentage'   => 100,
			'download_url' => $download_url,
			'filename'     => basename( $zip_path ),
			'errors'       => $session['errors'] ?? array(),
			'log_summary'  => $log_summary,
			'message'      => sprintf(
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
		if ( $sscribe_is_debug ) {
			$response['debug_info'] = array(
				'files_in_temp'      => $files_before,
				'total_files_in_zip' => $total_files_zip,
				'expected_pages'     => $session['total'],
				'zip_files_sample'   => array_slice( $files_in_zip, 0, 20 ),
				'page_ids_requested' => $session['page_ids'] ?? array(),
				'errors_detailed'    => $session['errors'] ?? array(),
				'language'           => $session['language'] ?? '',
				'post_status'        => $session['post_status'] ?? '',
				'total_time_sec'     => time() - ( $session['start_time'] ?? time() ),
				'memory_peak'        => size_format( memory_get_peak_usage( true ) ),
				'zip_size'           => size_format( filesize( $zip_path ) ),
				'log_summary'        => $log_summary,
			);
		}

		wp_send_json_success( $response );
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
		check_ajax_referer( 'sscribe_download', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) );
		}

		$filename  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file_path = $this->zip_handler->get_export_dir() . '/' . $filename;

		if ( empty( $filename ) || ! file_exists( $file_path ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'File not found or has expired. Please generate a new export.', 'sscribe-export-site-pages' ) );
		}

		$real_path = realpath( $file_path );
		$real_dir  = realpath( $this->zip_handler->get_export_dir() );

		if ( false === $real_path || false === $real_dir || ! str_starts_with( $real_path, $real_dir ) || 'zip' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			status_header( 400 );
			wp_die( esc_html__( 'Invalid file request.', 'sscribe-export-site-pages' ) );
		}

		$exports = get_option( 'sscribe_export_index', array() );
		if ( isset( $exports[ $filename ] ) && is_array( $exports[ $filename ] ) ) {
			$export_info = $exports[ $filename ];
			if ( isset( $export_info['user_id'] ) && get_current_user_id() !== (int) $export_info['user_id'] ) {
				$this->audit_log( 'download_access_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}
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

		flush();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct download
		readfile( $file_path );
		exit;
	}

	/**
	 * AJAX handler: Get status counts for a language.
	 *
	 * @return void
	 */
	public function ajax_get_status_counts(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		$counts = $this->collector->get_post_status_counts( $language );

		wp_send_json_success( array( 'counts' => $counts ) );
	}

	/**
	 * AJAX handler: Cancel an in-progress export.
	 *
	 * @return void
	 */
	public function ajax_cancel_export(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

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
		check_ajax_referer( 'sscribe_download', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

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

		$export_info = $exports[ $filename ] ?? array();
		$stored_user_id = isset( $export_info['user_id'] ) ? (int) (string) $export_info['user_id'] : 0;
		if ( $stored_user_id <= 0 || get_current_user_id() !== $stored_user_id ) {
    			$this->audit_log( 'delete_access_denied', array( 'filename' => $filename ) );
    			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
    			return;
		}

		$file_path = $this->zip_handler->get_export_dir() . '/' . $filename;

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
		check_ajax_referer( 'sscribe_download', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

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

		wp_send_json_success( array( 'log' => $log_data ) );
	}

	/**
	 * AJAX handler: Clear stuck sessions.
	 *
	 * @return void
	 */
	public function ajax_clear_session(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
			return;
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
				// Try JSON first (current format), fall back to PHP unserialization (legacy).
				$data = json_decode( $session->option_value, true );
				if ( ! is_array( $data ) ) {
					$data = maybe_unserialize( $session->option_value );
				}
				if ( is_array( $data ) && isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
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
	public function run_preflight_diagnostics( array $formats = array() ): array {
		$checks      = array();
		$has_error   = false;
		$has_warning = false;

		// Check ZIP extension.
		$zip_available           = class_exists( 'ZipArchive' );
		$checks['zip_extension'] = array(
			'name'      => __( 'ZIP Extension', 'sscribe-export-site-pages' ),
			'status'    => $zip_available ? 'ok' : 'error',
			'message'   => $zip_available
				? __( 'ZipArchive extension is available.', 'sscribe-export-site-pages' )
				: __( 'ZipArchive extension is missing. Export cannot create ZIP packages.', 'sscribe-export-site-pages' ),
			'fix_steps' => $zip_available ? null : array(
				__( 'Contact your hosting provider.', 'sscribe-export-site-pages' ),
				__( 'Request enabling the ZipArchive PHP extension.', 'sscribe-export-site-pages' ),
			),
		);
		if ( ! $zip_available ) {
			$has_error = true;
		}

		// Check DOM extension (needed for PDF).
		if ( in_array( 'pdf', $formats, true ) ) {
			$dom_available           = class_exists( 'DOMDocument' );
			$checks['dom_extension'] = array(
				'name'      => __( 'DOM Extension (PDF)', 'sscribe-export-site-pages' ),
				'status'    => $dom_available ? 'ok' : 'error',
				'message'   => $dom_available
					? __( 'DOM extension is available for PDF generation.', 'sscribe-export-site-pages' )
					: __( 'DOM extension is missing. PDF export will fail.', 'sscribe-export-site-pages' ),
				'fix_steps' => $dom_available ? null : array(
					__( 'Contact your hosting provider.', 'sscribe-export-site-pages' ),
					__( 'Request enabling the DOM PHP extension.', 'sscribe-export-site-pages' ),
				),
			);
			if ( ! $dom_available ) {
				$has_error = true;
			}
		}

		// Check mbstring extension (recommended).
		$mbstring_available           = extension_loaded( 'mbstring' );
		$checks['mbstring_extension'] = array(
			'name'      => __( 'Multibyte String', 'sscribe-export-site-pages' ),
			'status'    => $mbstring_available ? 'ok' : 'warning',
			'message'   => $mbstring_available
				? __( 'mbstring extension is available for Unicode support.', 'sscribe-export-site-pages' )
				: __( 'mbstring extension is missing. Unicode handling may be limited.', 'sscribe-export-site-pages' ),
			'fix_steps' => $mbstring_available ? null : array(
				__( 'Contact your hosting provider.', 'sscribe-export-site-pages' ),
				__( 'Request enabling the mbstring PHP extension.', 'sscribe-export-site-pages' ),
			),
		);
		if ( ! $mbstring_available ) {
			$has_warning = true;
		}

		// Check memory limit.
		$memory_limit       = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_limit_mb    = round( $memory_limit / 1024 / 1024 );
		$memory_ok          = $memory_limit >= 128 * 1024 * 1024; // 128MB minimum.
		$memory_recommended = $memory_limit >= 256 * 1024 * 1024; // 256MB recommended.

		$memory_status          = $memory_recommended ? 'ok' : ( $memory_ok ? 'warning' : 'error' );
		$checks['memory_limit'] = array(
			'name'      => __( 'Memory Limit', 'sscribe-export-site-pages' ),
			'status'    => $memory_status,
			'message'   => sprintf(
				/* translators: %s: Memory limit in MB. */
				__( 'PHP memory limit: %sMB.', 'sscribe-export-site-pages' ),
				$memory_limit_mb
			) . ( $memory_recommended
				? ''
				: ( $memory_ok
					? ' ' . __( 'Recommended: 256MB or higher for large exports.', 'sscribe-export-site-pages' )
					: ' ' . __( 'Minimum 128MB required. Export may fail.', 'sscribe-export-site-pages' )
				)
			),
			'fix_steps' => $memory_recommended ? null : array(
				__( 'Add to wp-config.php: define(\'WP_MEMORY_LIMIT\', \'256M\');', 'sscribe-export-site-pages' ),
				__( 'Or contact your hosting provider to increase memory_limit.', 'sscribe-export-site-pages' ),
			),
		);
		if ( ! $memory_ok ) {
			$has_error = true;
		} elseif ( ! $memory_recommended ) {
			$has_warning = true;
		}

		// Check disk space.
		$upload_dir = $this->zip_handler->get_export_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$disk_free        = is_dir( $upload_dir ) && is_writable( $upload_dir ) ? disk_free_space( $upload_dir ) : false;
		$disk_free_mb     = $disk_free ? round( $disk_free / 1024 / 1024 ) : 0;
		$disk_ok          = $disk_free && $disk_free >= 50 * 1024 * 1024; // 50MB minimum.
		$disk_recommended = $disk_free && $disk_free >= 200 * 1024 * 1024; // 200MB recommended.

		$disk_status          = $disk_recommended ? 'ok' : ( $disk_ok ? 'warning' : 'error' );
		$checks['disk_space'] = array(
			'name'      => __( 'Disk Space', 'sscribe-export-site-pages' ),
			'status'    => $disk_status,
			'message'   => sprintf(
				/* translators: %s: Available disk space in MB. */
				__( 'Available disk space: %sMB.', 'sscribe-export-site-pages' ),
				$disk_free_mb
			) . ( $disk_recommended
				? ''
				: ( $disk_ok
					? ' ' . __( 'Recommended: 200MB or more.', 'sscribe-export-site-pages' )
					: ' ' . __( 'Insufficient disk space. Export may fail.', 'sscribe-export-site-pages' )
				)
			),
			'fix_steps' => $disk_recommended ? null : array(
				__( 'Delete old export files from the history.', 'sscribe-export-site-pages' ),
				__( 'Clear WordPress cache and temporary files.', 'sscribe-export-site-pages' ),
				__( 'Contact your hosting provider to increase storage.', 'sscribe-export-site-pages' ),
			),
		);
		if ( ! $disk_ok ) {
			$has_error = true;
		} elseif ( ! $disk_recommended ) {
			$has_warning = true;
		}

		// Check uploads directory writable.
		$is_writable                  = wp_is_writable( $upload_dir );
		$checks['directory_writable'] = array(
			'name'      => __( 'Upload Directory', 'sscribe-export-site-pages' ),
			'status'    => $is_writable ? 'ok' : 'error',
			'message'   => $is_writable
				? sprintf(
					/* translators: %s: Directory path. */
					__( 'Export directory is writable: %s', 'sscribe-export-site-pages' ),
					$upload_dir
				)
				: sprintf(
					/* translators: %s: Directory path. */
					__( 'Export directory is not writable: %s', 'sscribe-export-site-pages' ),
					$upload_dir
				),
			'fix_steps' => $is_writable ? null : array(
				__( 'Verify wp-content/uploads directory exists.', 'sscribe-export-site-pages' ),
				__( 'Set directory permissions to 755.', 'sscribe-export-site-pages' ),
				__( 'Contact hosting support if the issue persists.', 'sscribe-export-site-pages' ),
			),
		);
		if ( ! $is_writable ) {
			$has_error = true;
		}

		// Determine overall status.
		$status = $has_error ? 'error' : ( $has_warning ? 'warning' : 'ready' );

		return array(
			'status'  => $status,
			'checks'  => $checks,
			'message' => $has_error
				? __( 'Some requirements are not met. Export may fail.', 'sscribe-export-site-pages' )
				: ( $has_warning
					? __( 'Export can proceed but some optimizations are recommended.', 'sscribe-export-site-pages' )
					: __( 'All systems ready for export.', 'sscribe-export-site-pages' )
				),
		);
	}

	/**
	 * AJAX handler: Run pre-flight diagnostics.
	 *
	 * @return void
	 */
	public function ajax_preflight_check(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

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
				'upload_dir'    => $this->zip_handler->get_export_dir(),
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
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'docx';

		$allowed_formats = array( 'docx', 'pdf', 'html', 'markdown' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'docx';
		}

		$pages      = $this->collector->get_page_ids( $language, $post_status );
		$page_count = count( $pages );

		$times_per_page = array(
			'docx'     => 1.2,
			'pdf'      => 8,
			'html'     => 1,
			'markdown' => 0.5,
		);

		$seconds_per_page = isset( $times_per_page[ $format ] ) ? $times_per_page[ $format ] : 2;
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

		$size_per_page = array(
			'docx'     => 0.5,
			'pdf'      => 2,
			'html'     => 0.3,
			'markdown' => 0.1,
		);

		$size_mb = $page_count * ( isset( $size_per_page[ $format ] ) ? $size_per_page[ $format ] : 0.5 );
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
}
