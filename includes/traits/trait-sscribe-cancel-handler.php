<?php
/**
 * SScribe Cancel Handler Trait
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 *
 * Owns the session-cancellation surface: the AJAX entrypoints that
 * delegate to SScribe_Session for cancel/clear (the implementation
 * : including the export-lock race fix : now lives on the session
 * itself via the SScribe_Session_AJAX trait), plus the local
 * temp-directory + export-log teardown that the batch step also
 * invokes when it detects a cancelled session mid-run.
 *
 * Using classes MUST provide:
 *  - the session collaborator (delegation target for AJAX endpoints)
 *  - the zip_handler, logger, and export_log properties (cleanup)
 *  - the export_log property is nullable (lazy-initialized)
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
 * Session cancel + cleanup : invoked by both the batch step and the
 * dedicated cancel/clear AJAX endpoints.
 */
trait SScribe_Cancel_Handler {

	/**
	 * Cancel an ongoing export via AJAX.
	 *
	 * Delegates to SScribe_Session::ajax_cancel_export() : the
	 * implementation now lives on the session itself, including
	 * the export-lock acquire/release race fix.
	 */
	public function ajax_cancel_export(): void {
		$this->session->ajax_cancel_export();
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
		if ( $this->export_log ) {
			$this->export_log->delete();
		}
	}

	/**
	 * Clear export session via AJAX.
	 *
	 * Delegates to SScribe_Session::ajax_clear_session().
	 */
	public function ajax_clear_session(): void {
		$this->session->ajax_clear_session();
	}
}
