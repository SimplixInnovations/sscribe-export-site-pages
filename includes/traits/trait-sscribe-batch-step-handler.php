<?php
/**
 * SScribe Batch Step Handler Trait
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 *
 * Owns the AJAX entrypoint that drives one chunk of pages through the
 * exporter pipeline (rate limit → session read → lock → per-page dispatch
 * → response build), plus the two helpers it relies on exclusively:
 * the response shape and the output-buffer restore used to clean up any
 * stray content the exporters may have written.
 *
 * Using classes MUST provide the supporting private API the trait calls
 * into : namely the session/lock/logger/diagnostics collaborators and the
 * resource/format helpers. SScribe_Batch_Processor already does.
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
 * Batch step handler : processes one chunk of pages per AJAX call.
 */
trait SScribe_Batch_Step_Handler {

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

		$rate_check = $this->check_rate_limit( 'export_batch' );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

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

		$batch_start_time = microtime( true );
		$batch_duration  = 0.0;
		try {
			$session_id = SScribe_AJAX_Guard::post_text( 'session_id', '', 16 );
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array( 'message' => __( 'Invalid export session identifier.', 'sscribe-export-site-pages' ) ),
					400
				);
			}
			$session    = $this->session->get( $session_id );

			$this->logger->debug(
				'Process batch called',
				array(
					'session_id'    => $session_id,
					'session_found' => ! empty( $session ),
				)
			);

			if ( ! $session ) {
				$this->get_lock_manager()->discard_lock( $session_id );
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

			$lock_ttl        = max( 30, min( 600, (int) apply_filters( 'sscribe_lock_ttl', 180 ) ) );
			$stale_threshold = max( 15, min( $lock_ttl - 5, (int) apply_filters( 'sscribe_lock_stale_threshold', 140 ) ) );

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
				$this->logger->debug( 'Lock acquisition failed : another process holds the lock', array( 'session_id' => $session_id ) );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array(
						'status'   => 'locked',
						'retry'    => true,
						'retry_in' => 60000,
						'message'  => __( 'A batch is already processing. Please wait.', 'sscribe-export-site-pages' ),
					),
					429
				);
			}

			$lock_token = $this->current_lock_token;

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

			if ( in_array( 'pdf', $formats, true ) && function_exists( 'set_time_limit' ) ) {
				$pdf_max_time = (int) apply_filters( 'sscribe_pdf_max_execution_time', 150 );

				set_time_limit( $pdf_max_time ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$pause_hint = isset( $session['last_pause_reason'] ) ? $session['last_pause_reason'] : '';
			$this->optimize_batch_size( $formats, $pause_hint );

			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
				$this->release_lock( $session_id, $lock_token );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array( 'message' => __( 'The WordPress uploads directory is unavailable.', 'sscribe-export-site-pages' ) ),
					500
				);
			}

			$allowed_temp_base = trailingslashit( (string) $upload_dir['basedir'] ) . 'sscribe-exports';
			$filesystem        = new SScribe_Filesystem();
			$path_valid       = str_starts_with( basename( (string) $temp_dir ), 'temp-' )
				&& ! is_link( (string) $temp_dir )
				&& SScribe_Filesystem::SSCRIBE_PATH_ALLOWED === $filesystem->is_path_safe_for_write( trailingslashit( (string) $temp_dir ) . '.sscribe-probe' );
			if ( ! $path_valid ) {
				$this->release_lock( $session_id, $lock_token );
				$this->restore_ob_level( $ob_level_before );
				SScribe_AJAX_Guard::error(
					array( 'message' => __( 'Export session corrupted (invalid temp directory path). Please start again.', 'sscribe-export-site-pages' ) ),
					500
				);
			}

			$real_allowed_base = realpath( $allowed_temp_base );

			if ( ! empty( $temp_dir ) && ! is_dir( $temp_dir ) ) {
				wp_mkdir_p( (string) $temp_dir );
			}
			$real_temp_dir = realpath( (string) $temp_dir );

			$path_valid = ! is_link( (string) $temp_dir );
			if ( false === $real_temp_dir ) {

				$path_valid = false;
			} elseif ( false !== $real_allowed_base ) {

				$safe_base = rtrim( $real_allowed_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
				if ( 0 !== strpos( $real_temp_dir, $safe_base ) ) {
					$path_valid = false;
				}
			} else {

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

			if ( ! empty( $batch ) ) {
				$this->collector->get_featured_images_batch( $batch );
				$this->collector->get_child_pages_batch( $batch );
				// Performance N+1 fix: warm the SEO postmeta cache for the
				// whole batch so per-page get_post_meta() calls inside the
				// six readers hit the in-memory cache instead of the DB.
				// Without this, a 200-page Yoast export = 1,400+ queries.
				$this->collector->prime_seo_meta_cache( $batch );
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

			$batch_time_limit = (int) apply_filters( 'sscribe_max_execution_time', 150 );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( $batch_time_limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			wp_suspend_cache_invalidation( true );

			$batch_log_data = $this->export_log ? $this->export_log->get_log() : null;

			$session_update_failed = false;

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

					$soft_deadline_ratio = (float) apply_filters( 'sscribe_soft_deadline_ratio', 0.5 );
					if ( $processed_in_this_batch > 0 && $soft_deadline_ratio > 0 && $soft_deadline_ratio < 1 ) {
						$remaining_time = $this->get_remaining_time( $batch_start_time );
						$max_exec       = (int) ini_get( 'max_execution_time' );
						if ( $max_exec > 0 && $remaining_time > 0 && $remaining_time < ( $max_exec * $soft_deadline_ratio ) && empty( $session['_soft_deadline_warned'] ) ) {
							$this->logger->debug(
								'Soft deadline crossed : remaining time below configured ratio',
								array(
									'remaining_time' => round( $remaining_time, 2 ),
									'max_execution'  => $max_exec,
									'ratio'          => $soft_deadline_ratio,
								)
							);
							$this->session->update( $session_id, array( '_soft_deadline_warned' => time() ) );
						}
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

					if ( null !== $batch_log_data && isset( $batch_log_data['pages'][ $page_id ] ) && 'processing' === $batch_log_data['pages'][ $page_id ]['status'] ) {
						$this->logger->debug(
							"Retrying page {$page_id} after previous crash",
							array(
								'page_id'   => $page_id,
								'memory_mb' => round( memory_get_usage( true ) / 1024 / 1024 ),
							)
						);
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

					$page_data = null;

					++$processed;
					++$processed_in_this_batch;

					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}

					$latest_session = $this->session->get( $session_id );
					if ( null === $latest_session || ! empty( $latest_session['cancelled'] ) ) {
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

				if ( SScribe_Logger::is_logging_enabled() ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback error logging when logger is available.
						'SScribe batch error: ' . $e->getMessage() . ' | Page: ' . ( $current_batch_page_id ?? 'unknown' )
					);
				}
			} finally {

				wp_suspend_cache_invalidation( false );

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

				$paused_reason = $memory_paused ? 'memory' : ( $timeout_paused ? 'timeout' : '' );

				$update_data = array(
					'processed'         => $processed,
					'errors'            => $errors,
					'structured_errors' => $structured_errors,
					'start_time'        => $start_time,
					'last_pause_reason' => $paused_reason,

					'error_count'        => $total_errors,
					'structured_count'   => $total_structured_errors,
				);

				if ( ! empty( $session['cancelled'] ) ) {
					$update_data['cancelled'] = true;
				}

				$format_keys = array( 'format_time_docx', 'format_time_pdf', 'format_time_html', 'format_time_markdown', 'format_size_docx', 'format_size_pdf', 'format_size_html', 'format_size_markdown', 'format_pages_docx', 'format_pages_pdf', 'format_pages_html', 'format_pages_markdown' );

				foreach ( $format_keys as $key ) {
					if ( isset( $session[ $key ] ) ) {

						if ( str_starts_with( $key, 'format_time_' ) || str_starts_with( $key, 'format_size_' ) ) {
							$update_data[ $key ] = (float) ( $session[ $key ] ?? 0 );
						} else {
							$update_data[ $key ] = (int) ( $session[ $key ] ?? 0 );
						}
					}
				}

				$update_result = $this->session->update( $session_id, $update_data );
				if ( ! $update_result ) {
					$session_update_failed = true;
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

			if ( $session_update_failed ) {
				SScribe_AJAX_Guard::error(
					array(
						'message' => __( 'Export progress could not be saved. The batch can be retried safely.', 'sscribe-export-site-pages' ),
						'retry'   => true,
					),
					500
				);
			}

			$percentage = ( $total > 0 && $processed > 0 ) ? round( ( $processed / $total ) * 100 ) : 0;
			$is_done    = ( $processed >= $total );

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
					SScribe_AJAX_Guard::error(
						array(
							'message' => __( 'Export completion state could not be saved. Please retry this step.', 'sscribe-export-site-pages' ),
							'retry'   => true,
						),
						500
					);
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
				return;
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
}
