<?php
/**
 * Handles batch AJAX processing of page exports.
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Batch_Processor
 *
 * Processes pages in small batches via AJAX to avoid timeouts.
 */
class SScribe_Batch_Processor {


	/**
	 * Number of pages to process per batch.
	 *
	 * @var int
	 */
	private $batch_size = 1;

	/**
	 * Page collector instance.
	 *
	 * @var SScribe_Page_Collector
	 */
	private $collector;

	/**
	 * ZIP handler instance.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private $zip_handler;

	/**
	 * Session handler instance.
	 *
	 * @var SScribe_Session
	 */
	private $session;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger
	 */
	private $logger;

	/**
	 * Rate limit: Maximum requests per minute per user.
	 */
	private const RATE_LIMIT_MAX = 60;

	/**
	 * Rate limit: Time window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 1 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector   = new SScribe_Page_Collector();
		$this->zip_handler = new SScribe_Zip_Handler();
		$this->session     = new SScribe_Session();
		$this->logger      = new SScribe_Logger( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Check rate limit for current user.
	 *
	 * @return bool True if within limits, false if exceeded.
	 */
	private function check_rate_limit(): bool {
		$user_id      = get_current_user_id();
		$transient_key = "sscribe_rate_{$user_id}";
		$count         = (int) get_transient( $transient_key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Log an action for audit trail.
	 *
	 * @param string $action Action name.
	 * @param array  $context Additional context.
	 */
	private function audit_log( string $action, array $context = array() ): void {
		$user_id   = get_current_user_id();
		$user      = get_user_by( 'id', $user_id );
		$username  = $user ? $user->user_login : 'unknown';

		$log_entry = array(
			'action'     => $action,
			'user_id'    => $user_id,
			'username'   => $username,
			'ip'         => $this->get_client_ip(),
			'timestamp'  => current_time( 'mysql' ),
			'context'    => $context,
		);

		$this->logger->debug( "[AUDIT] {$action}", $log_entry );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Get the required capability for export operations.
	 *
	 * @return string WordPress capability slug.
	 */
	private function get_required_capability() {
		/**
		 * Filter the capability required to run SScribe exports.
		 *
		 * @param string $capability WordPress capability slug. Default 'manage_options'.
		 */
		return apply_filters( 'sscribe_export_capability', 'manage_options' );
	}

	/**
	 * AJAX handler: Start export process.
	 *
	 * @return void
	 */
	public function ajax_start_export() {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
				)
			);
		}

		$this->audit_log( 'export_started' );
		$this->logger->debug( '=== START EXPORT ===' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
		$formats_raw   = isset( $_POST['formats'] ) ? wp_unslash( $_POST['formats'] ) : array();
		$formats_input = is_array( $formats_raw ) ? array_map(
			function( $f ) {
				return sanitize_text_field( $f );
			},
			$formats_raw
		) : array();
		$formats = ! empty( $formats_input ) ? $formats_input : array( 'docx' );

		$formats = array_filter( $formats, function( $format ) {
			return \SScribe_Exporter_Factory::is_supported( $format );
		} );

		if ( empty( $formats ) ) {
			$formats = array( 'docx' );
		}

		$this->logger->debug( 'Export params', array( 'language' => $language, 'post_status' => $post_status, 'formats' => $formats ) );

		// Validate language code against active WPML languages when WPML is present.
		if ( ! empty( $language ) && $this->collector->is_wpml_active() ) {
			$valid_languages = wp_list_pluck( $this->collector->get_wpml_languages(), 'code' );
			if ( ! in_array( $language, $valid_languages, true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid language code specified.', 'sscribe-export-site-pages' ),
					)
				);
				return;
			}
		}

		$page_ids = $this->collector->get_page_ids( $language, $post_status );
		$total    = count( $page_ids );
		
		// Get current language context for debugging
		$current_lang = 'default';
		if ( $this->collector->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			$current_lang = apply_filters( 'wpml_current_language', null );
		}

		$this->logger->debug( 'Page IDs retrieved', array(
			'total' => $total,
			'language_requested' => $language,
			'post_status' => $post_status,
			'current_wpml_lang' => $current_lang,
			'ids_sample' => array_slice( $page_ids, 0, 10 ),
			'memory_usage' => size_format( memory_get_usage( true ) ),
			'memory_peak' => size_format( memory_get_peak_usage( true ) ),
		) );

		if ( 0 === $total ) {
			wp_send_json_error(
				array(
					'message' => __( 'No pages found matching the selected criteria.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		// Create temp directory for this export session.
		$temp_dir = $this->zip_handler->create_temp_dir();

		// Store session data in file-based storage (immune to caching plugins).
		$session_id = $this->session->create(
			array(
				'page_ids'   => $page_ids,
				'temp_dir'   => $temp_dir,
				'total'      => $total,
				'processed'  => 0,
				'language'   => $language,
				'post_status'=> $post_status,
				'formats'    => $formats,
				'errors'     => array(),
				'start_time' => time(),
				'cancelled'  => false,
			)
		);

		$this->logger->debug( 'Session created', array(
			'session_id' => $session_id,
			'temp_dir'   => $temp_dir,
		) );

		if ( empty( $session_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to create export session. Please try again.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		wp_send_json_success(
			array_merge(
				array(
					'session_id' => $session_id,
					'total'      => $total,
					'batch_size' => $this->batch_size,
					'message'    => sprintf(
						/* translators: %d: number of pages */
						__( 'Found %d pages. Starting export...', 'sscribe-export-site-pages' ),
						$total
					),
				),
				defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ? array(
					'debug_info' => array(
						'page_ids_count' => $total,
						'page_ids_all' => $page_ids,
						'page_ids_sample' => array_slice( $page_ids, 0, 20 ),
						'language' => $language,
						'post_status' => $post_status,
						'current_wpml_lang' => $current_lang ?? 'n/a',
						'temp_dir' => $temp_dir,
						'session_file' => $this->session->get_storage_dir() . 'sscribe-session-' . $session_id . '.json',
						'memory_usage' => size_format( memory_get_usage( true ) ),
						'php_version' => PHP_VERSION,
					),
				) : array()
			)
		);
	}

	/**
	 * AJAX handler: Process next batch.
	 *
	 * @return void
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		// Attempt to extend execution time for slow page-builder rendering.
		if ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.max_execution_time_Blacklisted, Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for large Elementor exports.
			ini_set( 'max_execution_time', '120' );
		}
		wp_raise_memory_limit( 'admin' );

		// Record the current ob level BEFORE our ob_start().
		// We use this to restore exactly to this level, never touching
		// WordPress's own buffers (gzip, etc.) that were active before us.
		$ob_level_before = ob_get_level();

		// Start our buffer to capture stray HTML from page builders that could
		// corrupt our JSON response if it echoes during apply_filters('the_content').
		ob_start();

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session    = $this->session->get( $session_id );

		$this->logger->debug( 'Process batch called', array(
			'session_id' => $session_id,
			'session_found' => ! empty( $session ),
		) );

		if ( ! $session ) {
			$this->logger->debug( 'ERROR: Session not found' );
			// Restore to exactly the level before our ob_start(), then respond.
			$this->restore_ob_level( $ob_level_before );
			wp_send_json_error(
				array(
					'message' => __( 'Export session expired or not found. Please start again.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		// Validate session integrity.
		if ( ! $this->session->validate( $session_id ) ) {
			$this->logger->debug( 'ERROR: Session validation failed', array(
				'session_keys' => array_keys( $session ),
				'page_ids_count' => isset( $session['page_ids'] ) ? count( $session['page_ids'] ) : 'not set',
				'total' => $session['total'] ?? 'not set',
			) );
			$this->restore_ob_level( $ob_level_before );
			wp_send_json_error(
				array(
					'message' => __( 'Export session data corrupted. Please start again.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		// Check if export was cancelled.
		if ( ! empty( $session['cancelled'] ) ) {
			$this->logger->debug( 'Export was cancelled' );
			$this->restore_ob_level( $ob_level_before );
			$this->session->delete( $session_id );
			wp_send_json_error(
				array(
					'message' => __( 'Export was cancelled.', 'sscribe-export-site-pages' ),
					'cancelled' => true,
				)
			);
			return;
		}

		$page_ids  = $session['page_ids'];
		$processed = $session['processed'];
		$total     = $session['total'];
		$temp_dir  = $session['temp_dir'];
		$errors    = isset( $session['errors'] ) ? $session['errors'] : array();
		$start_time = isset( $session['start_time'] ) ? $session['start_time'] : time();
		$formats   = isset( $session['formats'] ) ? $session['formats'] : array( 'docx' );

		$this->logger->debug( 'Session state', array(
			'total_pages' => $total,
			'processed' => $processed,
			'remaining' => $total - $processed,
			'error_count' => count( $errors ),
			'memory_usage' => size_format( memory_get_usage( true ) ),
			'memory_peak' => size_format( memory_get_peak_usage( true ) ),
		) );

		// Get the next batch of page IDs.
		$batch = array_slice( $page_ids, $processed, $this->batch_size );

		// Pre-fetch all featured images for this batch (N+1 optimization).
		if ( ! empty( $batch ) ) {
			$this->collector->get_featured_images_batch( $batch );
		}

		$this->logger->debug( 'Batch details', array(
			'batch_size_setting' => $this->batch_size,
			'batch_count' => count( $batch ),
			'batch_ids' => $batch,
		) );

		if ( empty( $batch ) ) {
			$this->logger->debug( 'Batch empty, finalizing export' );
			// All pages processed — create ZIP.
			$this->restore_ob_level( $ob_level_before );
			$this->finalize_export( $session_id, $session );
			return;
		}

		// Track current page for progress display.
		$current_page_title = '';
		$batch_start_time = microtime( true );

		// Process each page in the batch.
		foreach ( $batch as $page_id ) {
			$page_start_time = microtime( true );
			$this->logger->debug( "Processing page ID: {$page_id}", array(
				'batch_index' => $processed + 1,
				'total' => $total,
			) );
			
			/**
			 * Fires before a page is exported to DOCX.
			 *
			 * @param int    $page_id  The page ID being exported.
			 * @param string $language The language code.
			 */
			do_action( 'sscribe_before_export_page', $page_id, $session['language'] );

			$page_data = $this->collector->get_page_data( $page_id );

			if ( ! $page_data ) {
				$error_msg = sprintf(
					/* translators: %d: page ID */
					__( 'Failed to collect data for page ID %d.', 'sscribe-export-site-pages' ),
					$page_id
				);
				$this->logger->debug( "ERROR: {$error_msg}", array(
					'page_id' => $page_id,
					'memory' => size_format( memory_get_usage( true ) ),
				) );
				$errors[] = $error_msg;
				$processed++;
				continue;
			}

			$current_page_title = $page_data['title'];
			$this->logger->debug( "Page data collected", array( 
				'title' => $current_page_title, 
				'id' => $page_id,
				'slug' => $page_data['slug'] ?? 'n/a',
				'lang' => $page_data['language'] ?? 'n/a',
			) );

			$page_index = $processed + 1;
			$export_success = false;
			$export_errors = array();

			foreach ( $formats as $format ) {
				$exporter = \SScribe_Exporter_Factory::create( $format );
				
				if ( ! $exporter ) {
					continue;
				}

				$result = $exporter->export( $page_data, $temp_dir, $page_index, $total );

				if ( $result->is_success() ) {
					$export_success = true;
					$this->logger->debug( ucfirst( $format ) . " generated successfully", array( 
						'file' => basename( $result->get_data()['path'] ?? '' ),
						'page_id' => $page_id,
						'format' => $format,
					) );
				} else {
					$export_errors[] = sprintf(
						'%s: %s',
						strtoupper( $format ),
						$result->get_error()
					);
				}
			}

			$page_duration = round( microtime( true ) - $page_start_time, 3 );
			
			if ( ! $export_success ) {
				$error_msg = sprintf(
					/* translators: %s: page title */
					__( 'Failed to generate exports for "%s".', 'sscribe-export-site-pages' ),
					$page_data['title']
				);
				$errors[] = $error_msg . ' ' . implode( ', ', $export_errors );

				$this->logger->debug( "ERROR: Export failed", array(
					'page_id'        => $page_id,
					'title'          => $page_data['title'],
					'duration_sec'   => $page_duration,
					'errors'         => $export_errors,
				) );
			}

			/**
			 * Fires after a page has been exported.
			 *
			 * @param int    $page_id The page ID.
			 * @param array  $formats The formats exported.
			 * @param bool   $success Whether export succeeded.
			 */
			do_action( 'sscribe_after_export_page', $page_id, $formats, $export_success );

			$processed++;
		}

		$batch_duration = microtime( true ) - $batch_start_time;

		$this->logger->debug( 'Batch completed', array(
			'processed_now' => $processed - $session['processed'],
			'batch_duration_sec' => round( $batch_duration, 3 ),
			'total_processed' => $processed,
			'total_errors' => count( $errors ),
			'memory_usage' => size_format( memory_get_usage( true ) ),
			'memory_peak' => size_format( memory_get_peak_usage( true ) ),
		) );

		// Update session with timing data.
		$update_result = $this->session->update(
			$session_id,
			array(
				'processed' => $processed,
				'errors'    => $errors,
				'start_time' => $start_time,
			)
		);

		$this->logger->debug( 'Session update result', array( 'success' => $update_result ) );

		$percentage = ( $total > 0 ) ? round( ( $processed / $total ) * 100 ) : 100;
		$is_done    = ( $processed >= $total );

		$this->logger->debug( 'Progress check', array(
			'processed' => $processed,
			'total' => $total,
			'percentage' => $percentage,
			'is_done' => $is_done,
		) );

		// Calculate time remaining estimate.
		$elapsed = time() - $start_time;
		$avg_time_per_page = $processed > 0 ? $elapsed / $processed : 0;
		$remaining_pages = $total - $processed;
		$time_remaining = round( $avg_time_per_page * $remaining_pages );

		if ( $is_done ) {
			$this->logger->debug( 'All pages processed, finalizing' );
			$this->restore_ob_level( $ob_level_before );
			$this->finalize_export( $session_id, $session );
			return;
		}

		// Restore output buffer to pre-batch level before sending JSON.
		$this->restore_ob_level( $ob_level_before );

		wp_send_json_success(
			array_merge(
				array(
					'status'            => 'processing',
					'processed'         => $processed,
					'total'             => $total,
					'percentage'        => $percentage,
					'current_page'      => $current_page_title,
					'time_remaining'    => $time_remaining,
					'message'           => sprintf(
						/* translators: 1: processed count, 2: total count */
						__( 'Processing %1$d of %2$d pages...', 'sscribe-export-site-pages' ),
						$processed,
						$total
					),
				),
				defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ? array(
					'debug_info' => array(
						'batch_size' => $this->batch_size,
						'errors_so_far' => count( $errors ),
						'last_batch_duration' => round( $batch_duration, 3 ),
						'memory_usage' => size_format( memory_get_usage( true ) ),
						'memory_peak' => size_format( memory_get_peak_usage( true ) ),
						'avg_time_per_page' => round( $avg_time_per_page, 3 ),
					),
				) : array()
			)
		);
	}

	/**
	 * Restore output buffering to the level it was at before this batch started.
	 *
	 * This is the safe way to handle our ob_start() without accidentally closing
	 * WordPress's own output buffers (gzip compression, core handling, etc.).
	 * We close only the buffers WE opened, leaving WP's buffers untouched.
	 *
	 * @param int $target_level The ob level to restore to.
	 * @return void
	 */
	private function restore_ob_level( $target_level ) {
		while ( ob_get_level() > $target_level ) {
			ob_end_clean();
		}
	}

	/**
	 * Finalize the export by creating ZIP and returning download URL.
	 *
	 * @param string $session_id The session ID.
	 * @param array  $session    The session data.
	 */
	private function finalize_export( $session_id, $session ) {
		$this->logger->debug( '=== FINALIZE EXPORT ===', array(
			'session_id' => $session_id,
			'total' => $session['total'],
			'errors_count' => count( $session['errors'] ),
			'errors' => $session['errors'],
			'processed' => $session['processed'] ?? 'not set',
		) );

		$lang_code = ! empty( $session['language'] ) ? $session['language'] : 'all';
		$site_slug = sanitize_file_name( get_bloginfo( 'name' ) );
		$zip_name  = 'sscribe-export-' . $lang_code . '-' . $site_slug . '-' . gmdate( 'Y-m-d-His' );

		$this->logger->debug( 'Creating ZIP', array( 
			'zip_name' => $zip_name, 
			'temp_dir' => $session['temp_dir'],
			'language' => $lang_code,
		) );

		// Count DOCX files BEFORE creating ZIP
		$docx_files_before = glob( trailingslashit( $session['temp_dir'] ) . '*.docx' );
		$docx_count_before = $docx_files_before ? count( $docx_files_before ) : 0;
		$this->logger->debug( 'DOCX files in temp dir BEFORE ZIP', array( 
			'count' => $docx_count_before,
			'files' => $docx_files_before ? array_map( 'basename', $docx_files_before ) : array(),
		) );

		$zip_path = $this->zip_handler->create_zip( $session['temp_dir'], $zip_name );

		if ( ! $zip_path ) {
			$this->logger->debug( 'ERROR: Failed to create ZIP', array(
				'temp_dir' => $session['temp_dir'],
				'expected_files' => $docx_count_before,
			) );
			wp_send_json_error(
				array_merge(
					array(
						'message' => __( 'Failed to create ZIP package. Please try again.', 'sscribe-export-site-pages' ),
					),
					defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ? array(
						'debug_info' => array(
							'temp_dir_exists' => is_dir( $session['temp_dir'] ),
							'docx_files_in_temp' => $docx_count_before,
						),
					) : array()
				)
			);
			return;
		}

		$this->logger->debug( 'ZIP created successfully', array( 
			'zip_path' => $zip_path,
			'zip_size' => size_format( filesize( $zip_path ) ),
		) );

		// Verify ZIP contents
		$zip = new ZipArchive();
		$zip_open = $zip->open( $zip_path );
		$docx_in_zip = 0;
		$zip_files = array();
		if ( $zip_open === true ) {
			$docx_in_zip = 0;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property
				$filename = $zip->getNameIndex( $i );
				$zip_files[] = $filename;
				if ( pathinfo( $filename, PATHINFO_EXTENSION ) === 'docx' ) {
					$docx_in_zip++;
				}
			}
			$zip->close();
		}
		$this->logger->debug( 'ZIP contents verified', array(
			'total_files_in_zip' => count( $zip_files ),
			'docx_files_in_zip' => $docx_in_zip,
			'expected_pages' => $session['total'],
			'match' => $docx_in_zip === $session['total'],
		) );

		// ZIP created successfully — now clean up session.
		$this->session->delete( $session_id );

		$error_count = count( $session['errors'] );

		$download_url = $this->zip_handler->get_ajax_download_url( basename( $zip_path ) );

		$this->logger->debug( 'Export complete', array( 
			'download_url' => $download_url,
			'total_time_sec' => time() - ( $session['start_time'] ?? time() ),
		) );

		$this->audit_log( 'export_completed', array(
			'total_pages' => $session['total'],
			'errors' => $error_count,
			'filename' => basename( $zip_path ),
			'duration_sec' => time() - ( $session['start_time'] ?? time() ),
		) );

		wp_send_json_success(
			array_merge(
				array(
					'status'       => 'complete',
					'processed'    => $session['total'],
					'total'        => $session['total'],
					'percentage'   => 100,
					'download_url' => $download_url,
					'filename'     => basename( $zip_path ),
					'errors'       => $session['errors'],
					'message'      => sprintf(
						/* translators: %d: number of pages */
						_n(
							'Export complete! %d page exported successfully.',
							'Export complete! %d pages exported successfully.',
							$session['total'],
							'sscribe-export-site-pages'
						),
						$session['total']
					) . ( $error_count > 0 ? sprintf(
						/* translators: %d: number of errors */
						' ' . _n( '(%d error)', '(%d errors)', $error_count, 'sscribe-export-site-pages' ),
						$error_count
					) : '' ),
				),
				defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ? array(
					'debug_info' => array(
						'docx_files_in_temp' => $docx_count_before,
						'docx_files_in_zip' => $docx_in_zip,
						'expected_pages' => $session['total'],
						'match' => $docx_in_zip === $session['total'],
						'difference' => $session['total'] - $docx_in_zip,
						'page_ids_requested' => $session['page_ids'],
						'errors_detailed' => $session['errors'],
						'language' => $session['language'] ?? '',
						'post_status' => $session['post_status'] ?? '',
						'total_time_sec' => time() - ( $session['start_time'] ?? time() ),
						'memory_peak' => size_format( memory_get_peak_usage( true ) ),
						'zip_size' => size_format( filesize( $zip_path ) ),
					),
				) : array()
			)
		);
	}

	/**
	 * AJAX handler: Download ZIP file.
	 *
	 * @return void
	 */
	public function ajax_download() {
		check_ajax_referer( 'sscribe_download', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) );
		}

		$filename  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file_path = $this->zip_handler->get_export_dir() . '/' . $filename;

		if ( empty( $filename ) || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'File not found or has expired. Please generate a new export.', 'sscribe-export-site-pages' ) );
		}

		// Validate it's a ZIP file in our export directory.
		$real_path = realpath( $file_path );
		$real_dir  = realpath( $this->zip_handler->get_export_dir() );

		if ( strpos( $real_path, $real_dir ) !== 0 || pathinfo( $filename, PATHINFO_EXTENSION ) !== 'zip' ) {
			wp_die( esc_html__( 'Invalid file request.', 'sscribe-export-site-pages' ) );
		}

		// Sanitize for ASCII Content-Disposition (strip non-ASCII and quotes).
		$ascii_filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename );

		// Serve the file with RFC 5987-compliant encoding.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $ascii_filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	/**
	 * AJAX handler: Get status counts for a language.
	 *
	 * @return void
	 */
	public function ajax_get_status_counts() {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ) );
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
	public function ajax_cancel_export() {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ) );
			return;
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ) ) );
			return;
		}

		// Mark session as cancelled.
		$session = $this->session->get( $session_id );
		if ( $session ) {
			$session['cancelled'] = true;
			$this->session->update( $session_id, $session );
		}

		wp_send_json_success( array( 'message' => __( 'Export cancelled.', 'sscribe-export-site-pages' ) ) );
	}
}
