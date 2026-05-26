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
				$this->auditor->log( 'download_orphaned_denied', array( 'filename' => $filename ) );
				status_header( 403 );
				wp_die( esc_html__( 'Invalid file access.', 'sscribe-export-site-pages' ) );
			}

			$export_info = $exports[ $filename ];
			if ( isset( $export_info['user_id'] ) && get_current_user_id() !== (int) $export_info['user_id'] ) {
				$this->auditor->log( 'download_access_denied', array( 'filename' => $filename ) );
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

			$this->auditor->log( 'download', array( 'filename' => $filename ) );

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
	 * Delete an export via AJAX.
	 */
	public function ajax_delete_export(): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ) ), 403 );
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ), 403 );
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
				$this->auditor->log( 'delete_access_denied', array( 'filename' => $filename ) );
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

		$this->auditor->log( 'export_deleted', array( 'filename' => $filename ) );

		SScribe_AJAX_Guard::success( array( 'message' => __( 'Export deleted.', 'sscribe-export-site-pages' ) ) );
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
		$capability = apply_filters( 'sscribe_export_capability', 'manage_options' );

		if ( ! SScribe_Capabilities::is_allowed( $capability ) ) {
			$this->auditor->log(
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
	 * Verify rate limit hasn't been exceeded.
	 *
	 * @return bool True if rate limit check passes.
	 */
	private function check_rate_limit(): bool {
		return $this->rate_limiter->check_rate_limit( $this->get_required_capability() );
	}
}
