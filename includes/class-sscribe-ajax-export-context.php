<?php
/**
 * SScribe AJAX Export Context
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
 * Export context for requests coming from the admin screen over AJAX.
 */
final class SScribe_Ajax_Export_Context implements SScribe_Export_Context_Interface {

	/**
	 * Whether the memory limit was already raised by this instance.
	 *
	 * @var bool
	 */
	private bool $memory_raised;

	/**
	 * Set up the context.
	 *
	 * The finalize handler never raised the memory limit, so it passes false
	 * to keep that request exactly as it was.
	 *
	 * @param bool $raise_memory Whether prepare_long_request() should raise the memory limit.
	 */
	public function __construct( bool $raise_memory = true ) {
		$this->memory_raised = ! $raise_memory;
	}

	/**
	 * The logged-in user making the request.
	 *
	 * @return int
	 */
	public function owner_user_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * A browser is waiting for the response.
	 *
	 * @return bool
	 */
	public function is_interactive(): bool {
		return true;
	}

	/**
	 * Keep running after a client disconnect, apply the time limit and raise
	 * the admin memory limit the first time round.
	 *
	 * @param int $seconds Requested time limit in seconds.
	 * @return void
	 */
	public function prepare_long_request( int $seconds ): void {
		ignore_user_abort( true );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $seconds ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		if ( $this->memory_raised ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );
		$this->memory_raised = true;
	}

	/**
	 * Progress travels back in the AJAX response, so nothing to do here.
	 *
	 * @param array<string|int, mixed> $payload Step payload.
	 * @return void
	 */
	public function report_progress( array $payload ): void {
		unset( $payload );
	}
}
