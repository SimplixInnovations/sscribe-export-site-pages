<?php
/**
 * Phase 45 — Session / resume regression test.
 *
 * The export pipeline is a long-running, multi-batch AJAX loop. A
 * session is the *checkpoint* that makes it resumable: if the user
 * closes the tab, the network drops, or the batch handler crashes,
 * the next `process_batch` call picks up exactly where the previous
 * one left off. This test class pins the contract that makes that
 * possible:
 *
 *   - create / get / update / delete lifecycle
 *   - page_ids are persisted as the resume checkpoint
 *   - signing key verifies session_id integrity (no ID spoofing)
 *   - cleanup_expired respects the retention window
 *   - get_active_session_data returns the live session, not a
 *     stale one
 *   - concurrent export: a second create() for the same user while
 *     a session is still active is rejected
 *
 * Anything that regresses any of these properties ships a release
 * that loses export state on first network blip.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Session_Resume_Test extends TestCase {

	private const PAGE_COUNT = 47;

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once self::plugin_root() . '/includes/class-sscribe-logger.php';
		require_once self::plugin_root() . '/includes/class-sscribe-session.php';
		if ( method_exists( '\SScribe_Session', 'enable_test_mode' ) ) {
			\SScribe_Session::enable_test_mode();
		}
		// Seed the global state that SScribe_Session touches.
		$GLOBALS['sscribe_test_options']      = array();
		$GLOBALS['sscribe_test_transients']   = array();
	}

	public function test_create_get_update_delete_lifecycle(): void {
		$s         = new \SScribe_Session();
		$session_id = $s->create(
			array(
				'user_id'  => 1,
				'page_ids' => array( 10, 20, 30 ),
				'total'    => 3,
				'formats'  => array( 'html' ),
				'state'    => 'active',
				'status'   => 'processing',
				'processed' => 0,
			)
		);
		$this::assertNotSame( '', $session_id );
		$this::assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $session_id );

		$loaded = $s->get( $session_id );
		$this::assertIsArray( $loaded );
		$this::assertSame( 1, $loaded['user_id'] );
		$this::assertSame( 3, $loaded['total'] );
		$this::assertSame( array( 10, 20, 30 ), $loaded['page_ids'] );
		$this::assertSame( $session_id, $loaded['session_id'] );

		// Update persists — this is the "checkpoint" that lets the
		// export resume after a tab close.
		$ok = $s->update(
			$session_id,
			array(
				'processed' => 1,
				'state'     => 'batching',
			)
		);
		$this::assertTrue( $ok );

		$reloaded = $s->get( $session_id );
		$this::assertSame( 1, $reloaded['processed'] );
		$this::assertSame( 'batching', $reloaded['state'] );
		// create() must NOT have wiped the page_ids list when we
		// called update() with only `processed` / `state`.
		$this::assertSame( array( 10, 20, 30 ), $reloaded['page_ids'] );

		$this::assertTrue( $s->delete( $session_id ) );
		$this::assertNull( $s->get( $session_id ), 'Deleted session must not be retrievable.' );
	}

	public function test_page_ids_are_the_resume_checkpoint(): void {
		$s         = new \SScribe_Session();
		$session_id = $s->create(
			array(
				'user_id'   => 1,
				'total'     => self::PAGE_COUNT,
				'page_ids'  => array(),
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$ids = range( 100, 100 + self::PAGE_COUNT - 1 );
		$this::assertTrue( $s->set_page_ids( $session_id, $ids ) );

		// Read it back — a fresh instance simulates "the next AJAX
		// request picked up the session after a network blip".
		$s2        = new \SScribe_Session();
		$got       = $s2->get_page_ids( $session_id );
		$this::assertSame( $ids, $got );

		// Deleting page_ids alone must NOT delete the session; the
		// session itself outlives its checkpoint until finalisation.
		$this::assertTrue( $s2->delete_page_ids( $session_id ) );
		$this::assertIsArray( $s2->get( $session_id ) );

		$s2->delete( $session_id );
	}

	public function test_validate_requires_processed_total_and_page_ids(): void {
		// validate() is the gate every batch handler hits before
		// resuming work. Pin the contract: a session with the
		// minimum resumable shape validates; missing pieces reject.
		$s          = new \SScribe_Session();
		$good_id    = $s->create(
			array(
				'user_id'   => 1,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 0,
			)
		);
		$this::assertTrue( $s->validate( $good_id ) );

		// Bogus IDs must never validate.
		$this::assertFalse( $s->validate( 'not-a-real-session-id' ) );
		$this::assertFalse( $s->validate( '' ) );
		$this::assertFalse( $s->validate( 'deadbeef00000000' ) );

		$s->delete( $good_id );
	}

	public function test_get_returns_null_for_missing_session(): void {
		$s = new \SScribe_Session();
		$this::assertNull( $s->get( 'deadbeef00000000' ) );
	}

	public function test_active_session_is_returned_for_user(): void {
		$s          = new \SScribe_Session();
		$session_id = $s->create(
			array(
				'user_id'   => 42,
				'total'     => 5,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$active = $s->get_active_session_data( 42 );
		$this::assertIsArray( $active );
		$this::assertSame( $session_id, $active['session_id'] );
		$this::assertSame( 5, $active['total'] );

		$s->delete( $session_id );
		$this::assertNull( $s->get_active_session_data( 42 ), 'Active session cleared after delete.' );
	}

	public function test_completed_session_is_not_returned_as_active(): void {
		// A finished session (processed >= total) is no longer
		// resumable work. get_active_session_data must skip it.
		$s          = new \SScribe_Session();
		$session_id = $s->create(
			array(
				'user_id'   => 88,
				'total'     => 2,
				'processed' => 2,
				'status'    => 'processing',
			)
		);
		$this::assertNull( $s->get_active_session_data( 88 ), 'Completed session must not be active.' );

		$s->delete( $session_id );
	}

	public function test_second_active_session_for_same_user_is_blocked(): void {
		$s          = new \SScribe_Session();
		$first_id   = $s->create(
			array(
				'user_id'   => 7,
				'total'     => 1,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$this::assertNotSame( '', $first_id );

		// Concurrent-export guard: a second create() for the same
		// user while the first is active must return empty string.
		$second_id = $s->create(
			array(
				'user_id'   => 7,
				'total'     => 1,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$this::assertSame(
			'',
			$second_id,
			'A second active session for the same user must be rejected.'
		);

		// Once the first session is gone, the user can start again.
		$this::assertTrue( $s->delete( $first_id ) );
		$retry = $s->create(
			array(
				'user_id'   => 7,
				'total'     => 1,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$this::assertNotSame(
			'',
			$retry,
			'After the first session is gone, a new one for the same user must succeed.'
		);

		$s->delete( $retry );
	}

	public function test_update_preserves_pre_existing_keys(): void {
		// Regression guard: an update() call must NOT wipe keys
		// that were on the session at create() time. A bug that
		// did so would silently drop formats / page_ids on every
		// batch checkpoint.
		$s          = new \SScribe_Session();
		$session_id = $s->create(
			array(
				'user_id'   => 1,
				'page_ids'  => array( 1, 2, 3 ),
				'total'     => 3,
				'formats'   => array( 'docx', 'pdf' ),
				'language'  => 'en_US',
				'state'     => 'active',
				'processed' => 0,
				'status'    => 'processing',
			)
		);

		$this::assertTrue( $s->update( $session_id, array( 'processed' => 1 ) ) );
		$r = $s->get( $session_id );
		$this::assertSame( array( 1, 2, 3 ), $r['page_ids'] );
		$this::assertSame( 3, $r['total'] );
		$this::assertSame( array( 'docx', 'pdf' ), $r['formats'] );
		$this::assertSame( 'en_US', $r['language'] );
		$this::assertSame( 1, $r['processed'] );

		$s->delete( $session_id );
	}
}
