<?php
/**
 * SScribe Batch Session Handler
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
 * Handles session lifecycle AJAX requests: check active, clear, cancel.
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 */
class SScribe_Batch_Session_Handler {

	/**
	 * Session manager.
	 *
	 * @var SScribe_Session
	 */
	private readonly SScribe_Session $session;

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
	 * Rate limiter instance.
	 *
	 * @var SScribe_Export_Rate_Limiter
	 */
	private readonly SScribe_Export_Rate_Limiter $rate_limiter;

	/**
	 * Lock manager instance.
	 *
	 * @var SScribe_Export_Lock_Manager
	 */
	private readonly SScribe_Export_Lock_Manager $lock_manager;

	/**
	 * Initialize the session handler.
	 *
	 * @param SScribe_Session|null                $session      Session manager.
	 * @param SScribe_Zip_Handler|null            $zip_handler  ZIP handler.
	 * @param SScribe_Logger_Interface|null       $logger       Logger.
	 * @param SScribe_Export_Auditor|null         $auditor      Export auditor.
	 * @param SScribe_Export_Rate_Limiter|null    $rate_limiter Rate limiter.
	 * @param SScribe_Export_Lock_Manager|null    $lock_manager Lock manager.
	 */
	public function __construct(
		?SScribe_Session $session = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Export_Auditor $auditor = null,
		?SScribe_Export_Rate_Limiter $rate_limiter = null,
		?SScribe_Export_Lock_Manager $lock_manager = null
	) {
		$this->session      = $session ?? new SScribe_Session();
		$this->zip_handler  = $zip_handler ?? new SScribe_Zip_Handler();
		$this->logger       = $logger ?? SScribe_Logger::instance();
		$this->auditor      = $auditor ?? new SScribe_Export_Auditor();
		$this->rate_limiter = $rate_limiter ?? new SScribe_Export_Rate_Limiter();
		$this->lock_manager = $lock_manager ?? new SScribe_Export_Lock_Manager( $this->logger );
	}

	/**
	 * Check for active session on page load - used to restore UI after browser reload.
	 */
	public function ajax_check_active_session(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
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
			// Return empty success to avoid disrupting the page-load check flow.
			wp_send_json_success(
				array(
					'has_active' => false,
				)
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
				'has_active' => true,
				'session_id' => $session_data['session_id'] ?? '',
				'status'     => $session_data['status'] ?? '',
				'processed'  => (int) ( $session_data['processed'] ?? 0 ),
				'total'      => (int) ( $session_data['total'] ?? 0 ),
				'percentage' => $session_data['total'] > 0
					? round( ( $session_data['processed'] / $session_data['total'] ) * 100 )
					: 0,
			)
		);
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
	 * Clean up stale locks for a user.
	 *
	 * @param int|null $user_id User ID.
	 */
	private function cleanup_user_locks( ?int $user_id = null ): void {
		$this->lock_manager->cleanup_user_locks( $user_id );
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
			$this->auditor->log(
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
			$this->auditor->log(
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
