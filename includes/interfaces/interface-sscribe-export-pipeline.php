<?php
/**
 * SScribe Export Pipeline Interface
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
 * The three steps of an export: start a session, work through its pages,
 * then package the result.
 */
interface SScribe_Export_Pipeline_Interface {

	/**
	 * Create an export session for the job.
	 *
	 * @param SScribe_Export_Job               $job     What to export.
	 * @param SScribe_Export_Context_Interface $context Who is running the export and how.
	 * @return SScribe_Export_Outcome
	 */
	public function start_export_job( SScribe_Export_Job $job, SScribe_Export_Context_Interface $context ): SScribe_Export_Outcome;

	/**
	 * Process the next batch of pages for a session.
	 *
	 * @param string                           $session_id Export session ID.
	 * @param SScribe_Export_Context_Interface $context    Who is running the export and how.
	 * @return SScribe_Export_Outcome
	 */
	public function process_batch_step( string $session_id, SScribe_Export_Context_Interface $context ): SScribe_Export_Outcome;

	/**
	 * Package a session that has finished processing.
	 *
	 * @param string                           $session_id Export session ID.
	 * @param SScribe_Export_Context_Interface $context    Who is running the export and how.
	 * @return SScribe_Export_Outcome
	 */
	public function finalize_session( string $session_id, SScribe_Export_Context_Interface $context ): SScribe_Export_Outcome;
}
