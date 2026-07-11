<?php
/**
 * SScribe Export Finalizer Trait
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 *
 * Owns the end-of-pipeline step: the AJAX entrypoint that decides whether
 * the session is in a finalizable state, and the heavy lifter that
 * packages generated files into the per-language ZIP archive. Together
 * they account for the second-largest slice of the batch processor.
 *
 * Using classes MUST provide:
 *  - session/lock/logger/diagnostics collaborators (private getters)
 *  - the count_temp_dir_files() helper for lock-TTL sizing
 *  - the build_error_diagnostics_payload() helper for failure responses
 *  - the release_lock() helper used in the failure paths
 *  - the static cleanup state properties (cleanup_temp_dir,
 *    cleanup_zip_handler, cleanup_logger) for shutdown coordination
 *  - the session_handler collaborator for the catch-all finalize races
 *  - SSCRIBE_PLUGIN_DIR / wp_* / SScribe_* globals + constants
 *
 * SScribe_Batch_Processor already provides all of the above.
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
 * Finalize the export pipeline : package files into the final ZIP.
 */
trait SScribe_Export_Finalizer {

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

		$file_count = $this->count_temp_dir_files( $session['temp_dir'] );
		$lock_ttl   = max( 120, min( 600, $file_count * 2 ) );

			$lock_token = $this->get_lock_manager()->acquire_lock( $session_id, $lock_ttl, (int) ( $lock_ttl * 0.85 ) );
		if ( null === $lock_token ) {
			$this->logger->debug( 'Finalize race detected : another request holds the lock', array( 'session_id' => $session_id ) );
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

				return;
			}
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
				substr( bin2hex( self::random_suffix_bytes( 3 ) ), 0, 6 )
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

				$found = glob( trailingslashit( $session['temp_dir'] ) . '*/*.' . $ext );
				if ( $found ) {
					$files_before[ $format ] = count( $found );
				}
			}
			$this->logger->debug( 'Files in temp dir BEFORE ZIP', $files_before );

			$total_generated_files = array_sum( $files_before );
			if ( 0 === $total_generated_files ) {
				$this->logger->debug(
					'No files generated : all pages likely failed',
					array(
						'temp_dir' => $session['temp_dir'],
						'formats'  => $formats,
					)
				);

				if ( $this->export_log ) {
					$this->export_log->mark_failed( 'No files generated : all pages failed' );
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
						'message'   => __( 'No files were generated : all pages failed to export. Check the export format selected and try again.', 'sscribe-export-site-pages' ),
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

				$total_files_zip = 0;

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$zip_file_count = $zip->numFiles;
				for ( $i = 0; $i < $zip_file_count; $i++ ) {
					$stat = $zip->statIndex( $i );
					if ( $stat && substr( $stat['name'], -1 ) !== '/' ) {
						++$total_files_zip;
					}
				}
				$zip->close();
			}

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
						'message'   => __( 'Export packaging failed : the ZIP archive was empty. Please try again.', 'sscribe-export-site-pages' ),
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

			$zip_warning = '';
			if ( $expected_file_count > 0 && $total_files_zip < $expected_file_count ) {
				$zip_warning = sprintf(
					/* translators: 1: Number of files expected, 2: Number of files found in the ZIP archive. */
					__( 'Warning: ZIP may be incomplete : expected %1$d files, found %2$d in archive.', 'sscribe-export-site-pages' ),
					$expected_file_count,
					$total_files_zip
				);
			}

			$response = array(
				'status'            => 'complete',
				'processed'         => $session['total'],
				'total'             => $session['total'],
				'pages'             => $session['total'],
				'percentage'        => 100,
				'download_url'      => $download_url,
				'filename'          => basename( $zip_path ),
				'formats'           => isset( $session['formats'] ) ? $session['formats'] : array(),
				'errors'            => $session['errors'] ?? array(),
				'error_diagnostics' => $error_diagnostics,
				'log_summary'       => $log_summary,
				'session_id'        => $session_id,
				'created_at'        => $session['start_time'] ?? microtime( true ),
				'file_size'         => $zip_size,
				'size'              => $zip_size,
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
}
