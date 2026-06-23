<?php
/**
 * SScribe Session Status Trait
 *
 * Extracted from SScribe_Batch_Processor to reduce file complexity.
 *
 * Owns the active-session probe — the AJAX endpoint that the front-end
 * uses on page load to decide whether to restore an in-flight export
 * (e.g. after a browser reload mid-batch).
 *
 * Using classes MUST provide the session_handler collaborator.
 * SScribe_Batch_Processor already does.
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
 * Active-session probe — used by the front-end to restore in-flight
 * exports after a page reload.
 */
trait SScribe_Session_Status {

	/**
	 * Check for active session on page load - used to restore UI after browser reload.
	 */
	public function ajax_check_active_session(): void {
		$this->session_handler->ajax_check_active_session();
	}
}
