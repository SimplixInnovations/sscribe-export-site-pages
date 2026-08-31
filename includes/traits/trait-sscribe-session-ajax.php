<?php
/**
 * SScribe Session AJAX Trait
 *
 * Owns the three AJAX endpoints that mutate or query session
 * state: check active session, cancel export, clear session.
 *
 * ajax_cancel_export() acquires the export lock before mutating
 * session state so the cancel phase cannot run concurrently
 * with a batch iteration that holds the same lock.
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
 * Plus SScribe_Batch_Session_Helpers for
 * get_required_capability() and check_rate_limit_decision().
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
 * Session AJAX endpoints : check active / cancel / clear.
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

		$decision = $this->check_rate_limit_decision( 'export_read' );
		if ( ! $decision->allowed ) {

			wp_send_json_success(
				array(
					'has_active'   => false,
					'rate_limited' => true,
					'retry_in'     => $decision->retry_after_ms,
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
	 * 409 Conflict with retry hint : the JS client retries
	 * within a few seconds. Once the lock is held, the mutation
	 * phase (re-read → flag cancelled → update → cleanup →
	 * delete) runs atomically with respect to the batch.
	 */
	public function ajax_cancel_export(): void {

		$session_id = SScribe_AJAX_Guard::post_text( 'session_id', '', 16 );

		if ( empty( $session_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_session_id',
					'message' => __( 'Invalid session.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}

		$session = $this->get( $session_id );
		if ( ! $session ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'session_expired',
					'message' => __( 'Session not found.', 'sscribe-export-site-pages' ),
				),
				404
			);
		}

		if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'session_ownership',
					'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$decision = $this->check_rate_limit_decision( 'export_start' );
		if ( ! $decision->allowed ) {
			\SScribe_Rate_Limit_Response::emit( $decision );
		}

		$lock_token = $this->get_lock_manager()->acquire_lock( $session_id, 30, 25 );
		if ( null === $lock_token ) {
			\SScribe_Lock_Response::emit_conflict( $session_id, 5000 );
		}

		try {

			$session = $this->get( $session_id );
			if ( ! $session ) {
				SScribe_AJAX_Guard::success(
					array( 'message' => __( 'Session already cleared.', 'sscribe-export-site-pages' ) )
				);
			}

			if ( ! $this->validate_session_ownership( $session, $session_id ) ) {
				SScribe_AJAX_Guard::error(
					array(
						'code'    => 'session_ownership',
						'message' => __( 'Invalid session access.', 'sscribe-export-site-pages' ),
					),
					403
				);
			}

			$session['cancelled'] = true;
			$update_ok            = $this->update( $session_id, $session );
			if ( ! $update_ok ) {

				$this->get_logger()->warning(
					'Cancel mutation: update returned false (concurrent writer won race)',
					array( 'session_id' => $session_id )
				);
			}

			try {
				$this->cleanup_cancelled_export( $session );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Cancel cleanup: temp_dir teardown failed',
					array(
						'session_id' => $session_id,
						'error'      => $e->getMessage(),
					)
				);
			}

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

		$decision = $this->check_rate_limit_decision( 'export_start' );
		if ( ! $decision->allowed ) {
			\SScribe_Rate_Limit_Response::emit( $decision );
		}

		$user_id = get_current_user_id();
		$force   = SScribe_AJAX_Guard::post_boolean( 'force' );

		if ( $force ) {
			$deleted = $this->clear_user_sessions( $user_id );
			$this->get_logger()->debug(
				'Force cleared all sessions for user',
				array(
					'user_id'       => $user_id,
					'deleted_count' => $deleted,
				)
			);
		} else {
			$this->clear_user_sessions( $user_id );
			$this->get_logger()->debug( 'Cleared sessions for user', array( 'user_id' => $user_id ) );
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

		// Cancel hook owns the per-session export log: deletion has to fire
		// here or the entry leaks until the next 3-day cleanup sweep.
		if ( ! empty( $session['session_id'] ) && class_exists( '\\SScribe_Export_Log', false ) ) {
			\SScribe_Export_Log::delete_by_session( (string) $session['session_id'] );
		}
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
	 * trait MUST override get_logger().
	 *
	 * @return \SScribe_Logger_Interface
	 */
	abstract protected function get_logger(): \SScribe_Logger_Interface;
}
