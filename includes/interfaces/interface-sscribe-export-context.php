<?php
/**
 * SScribe Export Context Interface
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
 * Describes who is running an export and how the run is hosted.
 *
 * The AJAX handlers pass one implementation, while WP-CLI or cron can pass
 * another without the export core caring which.
 */
interface SScribe_Export_Context_Interface {

	/**
	 * User the export runs on behalf of.
	 *
	 * @return int
	 */
	public function owner_user_id(): int;

	/**
	 * Whether a person is waiting on the other end of the request.
	 *
	 * @return bool
	 */
	public function is_interactive(): bool;

	/**
	 * Give the current process enough time and memory for a long step.
	 *
	 * @param int $seconds Requested time limit in seconds.
	 * @return void
	 */
	public function prepare_long_request( int $seconds ): void;

	/**
	 * Receive the payload of a successful step.
	 *
	 * @param array<string|int, mixed> $payload Step payload.
	 * @return void
	 */
	public function report_progress( array $payload ): void;
}
