<?php
/**
 * SScribe Batch File Handler
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles file download, deletion, and nonce refresh AJAX requests.
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 */
class SScribe_Batch_File_Handler {

	/**
	 * Rate limiter instance.
	 *
	 * @var SScribe_Export_Rate_Limiter
	 */
	private readonly SScribe_Export_Rate_Limiter $rate_limiter;

	/**
	 * ZIP handler instance.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private readonly SScribe_Zip_Handler $zip_handler;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Export auditor instance.
	 *
	 * @var SScribe_Export_Auditor
	 */
	private readonly SScribe_Export_Auditor $auditor;

	/**
	 * Initialize the file handler.
	 *
	 * @param SScribe_Export_Rate_Limiter|null $rate_limiter Rate limiter.
	 * @param SScribe_Zip_Handler|null         $zip_handler  ZIP handler.
	 * @param SScribe_Logger_Interface|null    $logger       Logger.
	 * @param SScribe_Export_Auditor|null      $auditor      Export auditor.
	 */
	public function __construct(
		?SScribe_Export_Rate_Limiter $rate_limiter = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Export_Auditor $auditor = null
	) {
		$this->rate_limiter = $rate_limiter ?? new SScribe_Export_Rate_Limiter();
		$this->zip_handler  = $zip_handler ?? new SScribe_Zip_Handler();
		$this->logger       = $logger ?? SScribe_Logger::instance();
		$this->auditor      = $auditor ?? new SScribe_Export_Auditor();
	}

	/**
	 * Handle file download via AJAX.
	 */
	public function ajax_download(): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Security check failed. The download link may have expired. Please refresh the page and try again.', 'sscribe-export-site-pages' ) );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Permission denied.', 'sscribe-export-site-pages' ) );
		}

		$rate_check = $this->check_rate_limit();
		if ( false === $rate_check ) {
			status_header( 429 );
			wp_die( esc_html__( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ) );
		}

		$raw_filename = isset( $_GET['file'] ) && is_string( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$filename     = sanitize_file_name( $raw_filename );

		if ( '' === $filename || ! hash_equals( $raw_filename, $filename ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename ) ) {
			status_header( 400 );
			wp_die( esc_html__( 'Invalid file request.', 'sscribe-export-site-pages' ) );
		}

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
			if (
				false === $real_path
				|| false === $real_dir
				|| ! str_starts_with( $real_path, $safe_dir )
				|| 'zip' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) )
				|| ! is_file( $real_path )
				|| ! is_readable( $real_path )
			) {
				status_header( 400 );
				wp_die( esc_html__( 'Invalid file request.', 'sscribe-export-site-pages' ) );
			}

			$exports = $this->zip_handler->get_export_entry( $filename );
			if ( null === $exports ) {
				$this->auditor->log( 'download_orphaned_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}

			$export_info   = $exports;
			$stored_user_id = isset( $export_info['user_id'] ) ? (int) $export_info['user_id'] : 0;
			if ( $stored_user_id <= 0 || get_current_user_id() !== $stored_user_id ) {
				$this->auditor->log( 'download_access_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}

			// Single-use per-row download token: the URL embeds a token that
			// was rotated at URL build time. consume_dl_token validates the
			// presented value against the row with hash_equals, then rotates
			// the token again so any replay (browser history, server-log
			// leak, accidental Slack share) returns 403 instead of the ZIP.
			$raw_token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			if ( ! $this->zip_handler->consume_dl_token( $filename, $raw_token ) ) {
				$this->auditor->log( 'download_token_rejected', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'This download link has already been used or has expired. Refresh the export panel to get a fresh link.', 'sscribe-export-site-pages' ) );
			}

			$ascii_filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename ) ?? $filename;
			$file_size      = filesize( $real_path );
			if ( false === $file_size ) {
				status_header( 500 );
				wp_die( esc_html__( 'Unable to read the export size. Please regenerate the export.', 'sscribe-export-site-pages' ) );
			}

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $ascii_filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
			header( 'Content-Length: ' . $file_size );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: no-referrer' );

			while ( ob_get_level() ) {
				ob_end_clean();
			}

			$this->auditor->log( 'download', array( 'filename' => $filename ) );

			flush();

			$previous_ignore_user_abort = ignore_user_abort( true );

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 360 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$read_result = readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Canonical plugin-owned ZIP path is streamed directly.
			if ( false === $read_result ) {
				$this->logger->warning(
					'readfile() returned false : possible partial read',
					array(
						'filename' => $filename,
						'path'     => $real_path,
					)
				);
			}
			ignore_user_abort( (bool) $previous_ignore_user_abort );
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
	 * Delete an export via AJAX.
	 */
	public function ajax_delete_export(): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->check_rate_limit();
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'     => 'rate_limited',
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$raw_filename = isset( $_POST['file'] ) && is_string( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$filename     = sanitize_file_name( $raw_filename );

		if ( '' === $filename || ! hash_equals( $raw_filename, $filename ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_filename',
					'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}

		$export_info = $this->zip_handler->get_export_entry( $filename );

		if ( null === $export_info ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'export_not_found',
					'message' => __( 'Export not found.', 'sscribe-export-site-pages' ),
				),
				404
			);
		}

		$stored_user_id = isset( $export_info['user_id'] ) ? (int) (string) $export_info['user_id'] : 0;
		if ( $stored_user_id <= 0 || get_current_user_id() !== $stored_user_id ) {
			$this->auditor->log( 'delete_access_denied', array( 'filename' => $filename ) );
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! $this->zip_handler->delete_export( $filename, get_current_user_id() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'delete_failed',
					'message' => __( 'The export could not be deleted.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		$this->auditor->log( 'export_deleted', array( 'filename' => $filename ) );

		SScribe_AJAX_Guard::success( array( 'message' => __( 'Export deleted.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * Refresh download nonce via AJAX.
	 */
	public function ajax_refresh_download_nonce(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->check_rate_limit();
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'     => 'rate_limited',
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		SScribe_AJAX_Guard::success(
			array(
				'nonce' => wp_create_nonce( 'sscribe_download' ),
			)
		);
	}

	/**
	 * Get the capability required for export operations.
	 *
	 * @return string Capability name.
	 */
	private function get_required_capability(): string {
		return SScribe_Capabilities::get_required();
	}

	/**
	 * Verify rate limit hasn't been exceeded.
	 *
	 * @return bool True when allowed, false when limited or contended.
	 */
	private function check_rate_limit(): bool {
		return $this->rate_limiter->check_rate_limit( $this->get_required_capability() );
	}
}
