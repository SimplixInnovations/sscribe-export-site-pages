<?php
/**
 * Read-only AJAX query endpoints for SScribe export operations.
 *
 * Handles all AJAX requests that query export state, system health,
 * preflight diagnostics, preview data, recent exports, and support
 * information. These endpoints never mutate export state.
 *
 * @package       SScribe
 * @since         1.1.5
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only AJAX query controller for export operations.
 *
 * Encapsulates all non-mutating AJAX endpoints used by the admin UI
 * to display export status, health checks, preview estimates, and
 * support information. Requires constructor injection of the same
 * services the batch processor uses.
 *
 * @since 1.1.5
 */
class SScribe_Export_Query_Controller {

	/**
	 * Rate limiter for endpoint protection.
	 *
	 * @var SScribe_Export_Rate_Limiter
	 * @since 1.1.5
	 */
	private readonly SScribe_Export_Rate_Limiter $rate_limiter;

	/**
	 * Diagnostics instance for health checks and preflight.
	 *
	 * @var SScribe_Diagnostics
	 * @since 1.1.5
	 */
	private readonly SScribe_Diagnostics $diagnostics;

	/**
	 * Page collector for querying pages.
	 *
	 * @var SScribe_Page_Collector
	 * @since 1.1.5
	 */
	private readonly SScribe_Page_Collector $collector;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 * @since 1.1.5
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * ZIP handler (for download URLs, export dir resolution).
	 *
	 * @var SScribe_Zip_Handler
	 * @since 1.1.5
	 */
	private readonly SScribe_Zip_Handler $zip_handler;

	/**
	 * Adaptive metrics for time/size estimation.
	 *
	 * @var SScribe_Adaptive_Metrics
	 * @since 1.1.5
	 */
	private readonly SScribe_Adaptive_Metrics $adaptive_metrics;

	/**
	 * Error handler for building diagnostics payloads.
	 *
	 * @var SScribe_Export_Error_Handler
	 * @since 1.1.5
	 */
	private readonly SScribe_Export_Error_Handler $error_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.1.5
	 *
	 * @param SScribe_Export_Rate_Limiter|null  $rate_limiter    Rate limiter.
	 * @param SScribe_Diagnostics|null          $diagnostics     Diagnostics instance.
	 * @param SScribe_Page_Collector|null       $collector       Page collector.
	 * @param SScribe_Logger_Interface|null     $logger          Logger instance.
	 * @param SScribe_Zip_Handler|null          $zip_handler     ZIP handler.
	 * @param SScribe_Adaptive_Metrics|null     $adaptive_metrics Adaptive metrics.
	 * @param SScribe_Export_Error_Handler|null $error_handler   Error handler.
	 */
	public function __construct(
		?SScribe_Export_Rate_Limiter $rate_limiter = null,
		?SScribe_Diagnostics $diagnostics = null,
		?SScribe_Page_Collector $collector = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Adaptive_Metrics $adaptive_metrics = null,
		?SScribe_Export_Error_Handler $error_handler = null
	) {
		$this->rate_limiter    = $rate_limiter ?? new SScribe_Export_Rate_Limiter();
		$this->diagnostics     = $diagnostics ?? new SScribe_Diagnostics();
		$this->collector       = $collector ?? new SScribe_Page_Collector();
		$this->logger          = $logger ?? SScribe_Logger::instance();
		$this->zip_handler     = $zip_handler ?? new SScribe_Zip_Handler();
		$this->adaptive_metrics = $adaptive_metrics ?? new SScribe_Adaptive_Metrics();
		$this->error_handler   = $error_handler ?? new SScribe_Export_Error_Handler();
	}

	/**
	 * AJAX handler: System health check.
	 *
	 * For unauthenticated requests: returns minimal reachability check.
	 * For authenticated requests: returns full diagnostics + boot state.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Capability required for full health
	 *                                  data. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_health_check( string $export_capability = 'manage_options' ): void {
		if ( ! is_user_logged_in() ) {
			SScribe_AJAX_Guard::success(
				array(
					'status'      => 'ok',
					'server_time' => current_time( 'mysql' ),
					'server_utc'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		if ( ! $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ) ),
				429
			);
			return;
		}

		$diagnostics = $this->diagnostics->check_ajax_health();
		$boot        = $this->diagnostics->get_boot_diagnostics();

		SScribe_AJAX_Guard::success(
			array(
				'ajax_health' => $diagnostics,
				'boot_state'  => $boot,
				'server_time' => current_time( 'mysql' ),
				'server_utc'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * AJAX handler: Get page status counts for a language and post type.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_get_status_counts( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		if ( ! $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Too many requests. Please wait.', 'sscribe-export-site-pages' ) ),
				429
			);
			return;
		}

		$language  = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$counts = $this->collector->get_post_status_counts( $language, $post_type );

		SScribe_AJAX_Guard::success( array( 'counts' => $counts ) );
	}

	/**
	 * AJAX handler: Get export log details.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_get_export_log( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $filename ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ) ),
				400
			);
			return;
		}

		$exports = get_option( 'sscribe_export_index', array() );

		if ( ! isset( $exports[ $filename ] ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Export not found.', 'sscribe-export-site-pages' ) ),
				404
			);
			return;
		}

		$export_info = $exports[ $filename ];
		if ( isset( $export_info['user_id'] ) && get_current_user_id() !== (int) $export_info['user_id'] ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		$log_data = SScribe_Export_Log::get_log_by_filename( $filename );

		if ( ! $log_data ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Log not found for this export.', 'sscribe-export-site-pages' ) ),
				404
			);
			return;
		}

		$structured_errors = $this->error_handler->build_structured_errors_from_log( $log_data );
		$string_errors     = is_array( $log_data['errors'] ?? null )
			? wp_list_pluck( $log_data['errors'], 'message' )
			: array();
		$diagnostics       = $this->error_handler->build_diagnostics_payload( $structured_errors, $string_errors );

		SScribe_AJAX_Guard::success(
			array(
				'log'         => $log_data,
				'diagnostics' => $diagnostics,
			)
		);
	}

	/**
	 * AJAX handler: Run pre-flight diagnostics.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_preflight_check( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		if ( ! $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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

		SScribe_AJAX_Guard::success( $diagnostics );
	}

	/**
	 * AJAX handler: Get export preview data.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_get_export_preview( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		if ( ! $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
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

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$pages      = $this->collector->get_page_ids( $language, $post_status, $post_type );
		$page_count = count( $pages );

		$seconds_per_page = $this->adaptive_metrics->get_seconds_per_page( $format, $post_type );
		$total_seconds    = (int) ( $page_count * $seconds_per_page );

		if ( $total_seconds < 60 ) {
			$estimated_time = sprintf(
			/* translators: %d: Number of seconds. */
				_n( '%d second', '%d seconds', $total_seconds, 'sscribe-export-site-pages' ),
				max( 1, ceil( $total_seconds ) )
			);
		} else {
			$minutes        = (int) ceil( $total_seconds / 60 );
			$estimated_time = sprintf(
			/* translators: %d: Number of minutes. */
				_n( '%d minute', '%d minutes', $minutes, 'sscribe-export-site-pages' ),
				$minutes
			);
		}

		$megabytes_per_page = $this->adaptive_metrics->get_mb_per_page( $format, $post_type );
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

		$language_display = '' !== $language ? $language : __( 'All Languages', 'sscribe-export-site-pages' );
		if ( '' !== $language && $this->collector->is_wpml_active() ) {
			$wpml_languages = $this->collector->get_wpml_languages();
			foreach ( $wpml_languages as $wl ) {
				if ( isset( $wl['code'] ) && $wl['code'] === $language ) {
					$language_display = $wl['name'] ?? strtoupper( $language );
					break;
				}
			}
		}

		$preview_data = array(
			'total_pages'        => $page_count,
			'format'             => $format,
			'estimated_time'     => $estimated_time,
			'file_size_estimate' => $file_size_estimate,
			'language'           => $language_display,
			'post_status'        => $post_status,
		);

		if ( null !== $sample_page ) {
			$preview_data = array_merge( $preview_data, $sample_page );
		}

		SScribe_AJAX_Guard::success( $preview_data );
	}

	/**
	 * AJAX handler: Get recent exports list.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_get_recent_exports( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		$exports = get_option( 'sscribe_export_index', array() );
		$user_id = get_current_user_id();

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
				'time'           => $data['created_at'] ?? filemtime( $file_path ),
				'date'           => wp_date(
					( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ? get_option( 'time_format' ) : 'H:i' ),
					$data['created_at'] ?? filemtime( $file_path ),
				),
				'lang_code'      => $data['lang_code'] ?? '',
				'lang_name'      => $data['lang_name'] ?? '',
				'flag_url'       => $data['flag_url'] ?? '',
			);
		}

		// Sort by time descending (newest first).
		$sorted = $result;
		uasort(
			$sorted,
			function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		SScribe_AJAX_Guard::success( array( 'exports' => array_slice( array_values( $sorted ), 0, 10 ) ) );
	}

	/**
	 * AJAX handler: Get support and debug information.
	 *
	 * @since 1.1.5
	 *
	 * @param string $export_capability Required capability. Default 'manage_options'.
	 *
	 * @return void
	 */
	public function ajax_get_support_info( string $export_capability = 'manage_options' ): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ),
				403
			);
			return;
		}

		if ( ! $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
			return;
		}

		try {
			$support_info = $this->diagnostics->get_support_info();
			SScribe_AJAX_Guard::success( $support_info );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Support info AJAX failed',
				array(
					'error' => $e->getMessage(),
					'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);

			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Unable to load support information right now. Please try again later.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}
	}
}
