<?php
/**
 * SScribe Session AJAX Trait
 *
 * Owns the three AJAX endpoints that mutate or query session
 * state: check active session, cancel export, clear session.
 *
 * Extracted from SScribe_Batch_Session_Handler (now removed) and
 * folded into SScribe_Session so that all session state AND all
 * session-mutating endpoints live in one class — eliminating the
 * dual-ownership race where the cancel endpoint could mutate
 * state concurrently with a running batch iteration.
 *
 * The cancel race fix lives in ajax_cancel_export(): the
 * mutation phase is wrapped in acquire_lock() / release_lock()
 * so it can never run concurrently with a batch that holds the
 * same export lock. The batch's next acquire_lock will fail
 * after cancel completes, returning a 429 to the client and
 * effectively terminating the batch.
 *
 * Using classes MUST provide lazy accessors for these
 * collaborators (see SScribe_Session for the canonical
 * implementation):
 *
 *   - get_rate_limiter()      → SScribe_Export_Rate_Limiter
 *   - get_auditor()           → SScribe_Export_Auditor
 *   - get_zip_handler()       → SScribe_Zip_Handler
 *   - get_lock_manager()      → SScribe_Export_Lock_Manager
 *
 * Plus the standard SScribe_Batch_Session_Helpers trait
 * (for get_required_capability() and check_rate_limit()).
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
 * Session AJAX endpoints — check active / cancel / clear.
 *
 * Folded into SScribe_Session via this trait so that one class
 * owns session state AND the endpoints that mutate it. See the
 * file docblock for the cancel-race rationale.
 */
trait SScribe_Session_AJAX {

	use SScribe_Batch_Session_Helpers;

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

		$rate_check = $this->check_rate_limit();
		if ( false === $rate_check ) {
			// Rate limit tripped — signal the UI to skip the restore rather
			// than show a stale "session active" banner.
			wp_send_json_success(
				array(
					'has_active'   => false,
					'rate_limited' => true,
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

		$session_data = $this->get_active_session_data( $user_id );

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
	 *
	 * Acquires the export lock before mutating session state so
	 * the cancellation cannot race with a batch iteration that
	 * holds the same lock. If the lock is already held, returns
	 * 409 Conflict with retry hint — the JS client retries
	 * within a few seconds. Once the lock is held, the mutation
	 * phase (re-read → flag cancelled → update → cleanup →
	 * delete) runs atomically with respect to the batch.
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

		// Pre-lock read for ownership check. Cheap and avoids
		// holding the lock across a 403 path.
		$session = $this->get( $session_id );
		if ( ! $session ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Session not found.', 'sscribe-export-site-pages' ) ), 404 );
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ) ), 403 );
		}

		$rate_check = $this->check_rate_limit();
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

		// Acquire the export lock — race fix. If a batch
		// iteration holds it, return 409 with retry hint and let
		// the JS client retry. Once the lock is held, the
		// mutation below runs atomically with respect to the
		// batch: when this finally releases, the batch's next
		// acquire_lock will fail and the batch is effectively
		// dead (returns 429 to its own caller).
		$lock_token = $this->get_lock_manager()->acquire_lock( $session_id, 30, 25 );
		if ( null === $lock_token ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'     => 'batch_in_progress',
					'message'  => __( 'A batch is processing. Try again in a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 5000,
				),
				409
			);
		}

		try {
			// Re-read inside the lock. The session may have
			// been updated by a concurrent iteration that
			// finished just before we acquired the lock, or
			// cleared by an earlier cancel that beat us.
			$session = $this->get( $session_id );
			if ( ! $session ) {
				SScribe_AJAX_Guard::success(
					array( 'message' => __( 'Session already cleared.', 'sscribe-export-site-pages' ) )
				);
			}

			// Defensive ownership re-check after the re-read.
			if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
				SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ) ), 403 );
			}

			$session['cancelled'] = true;
			$this->update( $session_id, $session );
			$this->cleanup_cancelled_export( $session );
			$this->delete( $session_id );

			SScribe_AJAX_Guard::success( array( 'message' => __( 'Export cancelled.', 'sscribe-export-site-pages' ) ) );
		} finally {
			$this->get_lock_manager()->release_lock( $session_id, $lock_token );
		}
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

		$rate_check = $this->check_rate_limit();
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

		$user_id = get_current_user_id();
		$force   = isset( $_POST['force'] ) && filter_var( wp_unslash( $_POST['force'] ), FILTER_VALIDATE_BOOLEAN );

		if ( $force ) {
			$deleted = $this->clear_user_sessions( $user_id );
			$this->get_logger()->debug(
				'Force cleared all sessions for user',
				array(
					'user_id'       => $user_id,
					'deleted_count' => $deleted,
				)
			);

			$this->cleanup_user_locks( $user_id );
		} else {
			$this->cleanup_expired( 60 );
			$this->get_logger()->debug( 'Cleared expired sessions for user', array( 'user_id' => $user_id ) );
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
			$this->get_zip_handler()->delete_directory( $session['temp_dir'] );
			$this->get_logger()->debug(
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
		$this->get_lock_manager()->cleanup_user_locks( $user_id );
	}

	/**
	 * Verify the session belongs to the current user.
	 *
	 * @param array  $session    Session data.
	 * @param string $session_id Session identifier.
	 * @return bool True if user owns the session.
	 */
	private function validate_session_ownership( array $session, string $session_id ): bool {
		$current_user_id = get_current_user_id();

		if ( ! isset( $session['user_id'] ) ) {
			$this->get_auditor()->log(
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
			$this->get_auditor()->log(
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
	 * Get the rate limiter (used by the shared SScribe_Batch_Session_Helpers trait).
	 *
	 * Concrete classes using this trait MUST override this
	 * method to return a SScribe_Export_Rate_Limiter instance.
	 *
	 * @return SScribe_Export_Rate_Limiter
	 */
	abstract protected function get_rate_limiter(): SScribe_Export_Rate_Limiter;

	/**
	 * Get the auditor (used by validate_session_ownership).
	 *
	 * @return SScribe_Export_Auditor
	 */
	abstract protected function get_auditor(): SScribe_Export_Auditor;

	/**
	 * Get the ZIP handler (used by cleanup_cancelled_export).
	 *
	 * @return SScribe_Zip_Handler
	 */
	abstract protected function get_zip_handler(): SScribe_Zip_Handler;

	/**
	 * Get the lock manager (used by ajax_cancel_export's race fix
	 * and cleanup_user_locks).
	 *
	 * @return SScribe_Export_Lock_Manager
	 */
	abstract protected function get_lock_manager(): SScribe_Export_Lock_Manager;

	/**
	 * Get the logger (used by ajax_clear_session and
	 * cleanup_cancelled_export). Concrete classes using this
	 * trait MUST override this method.
	 *
	 * @return \SScribe_Logger_Interface
	 */
	abstract protected function get_logger(): \SScribe_Logger_Interface;
}
