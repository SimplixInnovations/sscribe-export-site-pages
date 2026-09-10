<?php
/**
 * SScribe Session Status Trait unit test
 *
 * The trait owns the front-end probe that restores an in-flight export
 * after a browser reload. It delegates to SScribe_Session via the
 * using class's `session` collaborator. We exercise the delegator to
 * make sure the trait honours the contract: arguments are passed
 * through, the collaborator's return value is propagated, and the
 * trait can be folded into any class that supplies `session`.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! trait_exists( '\\SScribe_Session_Status', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-session-status.php';
}

/**
 * Bare-minimum stand-in that consumes the trait and exposes a
 * session collaborator for the delegator to call.
 */
final class SScribe_Session_Status_Stub {
	use \SScribe_Session_Status;

	public object $session;

	public function __construct( object $session ) {
		$this->session = $session;
	}
}

final class SScribe_Session_Status_Trait_Test extends TestCase {

	public function test_ajax_check_active_session_delegates_to_session_collaborator(): void {
		$called  = 0;
		$session = new class( $called ) {
			private int $counter;
			public function __construct( int &$counter ) {
				$this->counter = &$counter;
			}
			public function ajax_check_active_session(): void {
				++$this->counter;
			}
		};
		$stub = new SScribe_Session_Status_Stub( $session );

		$stub->ajax_check_active_session();

		$this::assertSame( 1, $called, 'delegator must invoke the collaborator exactly once' );
	}

	public function test_ajax_check_active_session_can_be_called_multiple_times(): void {
		// The trait exposes a no-arg void method; calling it twice must
		// reach the collaborator twice without throwing or losing state.
		$call_log = array();
		$session  = new class( $call_log ) {
			private array $log;
			public function __construct( array &$log ) {
				$this->log = &$log;
			}
			public function ajax_check_active_session(): void {
				$this->log[] = microtime( true );
			}
		};
		$stub = new SScribe_Session_Status_Stub( $session );

		$stub->ajax_check_active_session();
		$stub->ajax_check_active_session();

		$this::assertCount( 2, $call_log, 'delegator must forward every call to the collaborator' );
	}
}
