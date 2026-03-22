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
	private $batch_size = 3;

	/**
	 * Page collector instance.
	 *
	 * @var SScribe_Page_Collector
	 */
	private $collector;

	/**
	 * Exporter instance.
	 *
	 * @var SScribe_Exporter
	 */
	private $exporter;

	/**
	 * ZIP handler instance.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private $zip_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Filter the number of pages processed per AJAX batch.
		 *
		 * Increase for faster exports on powerful servers.
		 * Decrease if you experience PHP timeout errors on shared hosting.
		 *
		 * @param int $batch_size Default batch size. Default 3.
		 */
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 3 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector   = new SScribe_Page_Collector();
		$this->exporter    = new SScribe_Exporter();
		$this->zip_handler = new SScribe_Zip_Handler();
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

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				)
			);
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		$page_ids = $this->collector->get_page_ids( $language );
		$total    = count( $page_ids );

		if ( 0 === $total ) {
			wp_send_json_error(
				array(
					'message' => __( 'No published pages found for this language.', 'sscribe-export-site-pages' ),
				)
			);
		}

		// Create temp directory for this export session.
		$temp_dir = $this->zip_handler->create_temp_dir();

		// Store session data in transient.
		$session_id = wp_generate_password( 16, false );
		set_transient(
			'sscribe_export_' . $session_id,
			array(
				'page_ids'  => $page_ids,
				'temp_dir'  => $temp_dir,
				'total'     => $total,
				'processed' => 0,
				'language'  => $language,
				'errors'    => array(),
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'total'      => $total,
				'batch_size' => $this->batch_size,
				'message'    => sprintf(
					/* translators: %d: number of pages */
					__( 'Found %d pages. Starting export...', 'sscribe-export-site-pages' ),
					$total
				),
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

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				)
			);
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session    = get_transient( 'sscribe_export_' . $session_id );

		if ( ! $session ) {
			wp_send_json_error(
				array(
					'message' => __( 'Export session expired. Please start again.', 'sscribe-export-site-pages' ),
				)
			);
		}

		$page_ids  = $session['page_ids'];
		$processed = $session['processed'];
		$total     = $session['total'];
		$temp_dir  = $session['temp_dir'];
		$errors    = $session['errors'];

		// Get the next batch of page IDs.
		$batch = array_slice( $page_ids, $processed, $this->batch_size );

		if ( empty( $batch ) ) {
			// All pages processed — create ZIP.
			$this->finalize_export( $session_id, $session );
			return;
		}

		// Process each page in the batch.
		foreach ( $batch as $page_id ) {
			/**
			 * Fires before a page is exported to DOCX.
			 *
			 * @param int    $page_id  The page ID being exported.
			 * @param string $language The language code.
			 */
			do_action( 'sscribe_before_export_page', $page_id, $session['language'] );

			$page_data = $this->collector->get_page_data( $page_id );

			if ( ! $page_data ) {
				$errors[] = sprintf(
					/* translators: %d: page ID */
					__( 'Failed to collect data for page ID %d.', 'sscribe-export-site-pages' ),
					$page_id
				);
				$processed++;
				continue;
			}

			$result = $this->exporter->generate_docx( $page_data, $temp_dir );

			if ( ! $result ) {
				$errors[] = sprintf(
					/* translators: %s: page title */
					__( 'Failed to generate DOCX for "%s".', 'sscribe-export-site-pages' ),
					$page_data['title']
				);
			}

			/**
			 * Fires after a page has been exported to DOCX.
			 *
			 * @param int          $page_id The page ID.
			 * @param string|false $result  Path to DOCX or false on failure.
			 */
			do_action( 'sscribe_after_export_page', $page_id, $result );

			$processed++;
		}

		// Update session.
		$session['processed'] = $processed;
		$session['errors']    = $errors;
		set_transient( 'sscribe_export_' . $session_id, $session, HOUR_IN_SECONDS );

		$percentage = ( $total > 0 ) ? round( ( $processed / $total ) * 100 ) : 100;
		$is_done    = ( $processed >= $total );

		if ( $is_done ) {
			$this->finalize_export( $session_id, $session );
			return;
		}

		wp_send_json_success(
			array(
				'status'     => 'processing',
				'processed'  => $processed,
				'total'      => $total,
				'percentage' => $percentage,
				'message'    => sprintf(
					/* translators: 1: processed count, 2: total count */
					__( 'Processing %1$d of %2$d pages...', 'sscribe-export-site-pages' ),
					$processed,
					$total
				),
			)
		);
	}

	/**
	 * Finalize the export by creating ZIP and returning download URL.
	 *
	 * @param string $session_id The session ID.
	 * @param array  $session    The session data.
	 */
	private function finalize_export( $session_id, $session ) {
		$lang_code = ! empty( $session['language'] ) ? $session['language'] : 'all';
		$site_slug = sanitize_file_name( get_bloginfo( 'name' ) );
		$zip_name  = 'sscribe-export-' . $lang_code . '-' . $site_slug . '-' . gmdate( 'Y-m-d-His' );

		$zip_path = $this->zip_handler->create_zip( $session['temp_dir'], $zip_name );

		// Only clean up session AFTER we know the outcome.
		if ( ! $zip_path ) {
			// Keep transient alive so the user could potentially retry.
			// But mark it as failed so the JS can surface the error.
			wp_send_json_error(
				array(
					'message' => __( 'Failed to create ZIP package. Please try again.', 'sscribe-export-site-pages' ),
				)
			);
			return;
		}

		// ZIP created successfully — now clean up session.
		delete_transient( 'sscribe_export_' . $session_id );

		$download_url = $this->zip_handler->get_ajax_download_url( basename( $zip_path ) );

		wp_send_json_success(
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
					__( 'Export complete! %d pages exported successfully.', 'sscribe-export-site-pages' ),
					$session['total']
				),
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
}
