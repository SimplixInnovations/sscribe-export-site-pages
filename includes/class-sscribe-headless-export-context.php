<?php
/**
 * SScribe Headless Export Context
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
 * Export context for runs with nobody watching, such as WP-CLI or cron.
 */
final class SScribe_Headless_Export_Context implements SScribe_Export_Context_Interface {

	/**
	 * User the export runs for.
	 *
	 * @var int
	 */
	private int $owner_user_id;

	/**
	 * Optional listener for step payloads.
	 *
	 * @var callable|null
	 */
	private $progress;

	/**
	 * Whether the memory limit was already raised by this instance.
	 *
	 * @var bool
	 */
	private bool $memory_raised = false;

	/**
	 * Set up the context.
	 *
	 * @param int           $owner_user_id User the export runs for.
	 * @param callable|null $progress      Called with each successful step payload.
	 */
	public function __construct( int $owner_user_id, ?callable $progress = null ) {
		$this->owner_user_id = $owner_user_id;
		$this->progress      = $progress;
	}

	/**
	 * User the export runs for.
	 *
	 * @return int
	 */
	public function owner_user_id(): int {
		return $this->owner_user_id;
	}

	/**
	 * Nobody is waiting on a response.
	 *
	 * @return bool
	 */
	public function is_interactive(): bool {
		return false;
	}

	/**
	 * Lift the time limit entirely and raise memory once.
	 *
	 * @param int $seconds Requested time limit in seconds, unused here.
	 * @return void
	 */
	public function prepare_long_request( int $seconds ): void {
		unset( $seconds );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		if ( $this->memory_raised ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );
		$this->memory_raised = true;
	}

	/**
	 * Pass the payload to the listener, if any.
	 *
	 * @param array<string|int, mixed> $payload Step payload.
	 * @return void
	 */
	public function report_progress( array $payload ): void {
		if ( null === $this->progress ) {
			return;
		}

		( $this->progress )( $payload );
	}
}
