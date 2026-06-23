<?php
/**
 * SScribe Cancel Handler Trait
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 *
 * Owns the session-cancellation surface: the AJAX entrypoints that
 * delegate to the session handler for cancel/clear, plus the local
 * temp-directory + export-log teardown that the batch step also
 * invokes when it detects a cancelled session mid-run.
 *
 * Using classes MUST provide:
 *  - the session_handler collaborator (delegation target)
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
 * Session cancel + cleanup — invoked by both the batch step and the
 * dedicated cancel/clear AJAX endpoints.
 */
trait SScribe_Cancel_Handler {

	/**
	 * Cancel an ongoing export via AJAX.
	 */
	public function ajax_cancel_export(): void {
		$this->session_handler->ajax_cancel_export();
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
	 */
	public function ajax_clear_session(): void {
		$this->session_handler->ajax_clear_session();
	}
}
