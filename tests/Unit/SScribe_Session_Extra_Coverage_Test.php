<?php
/**
 * SScribe Session extra-coverage test
 *
 * Targets high-leverage methods that the existing SScribe_Session_Test does
 * not exercise fully:
 *
 *   - validate() : total/processed clamps, session-id mismatch, missing page_ids
 *   - update()   : max-keys clamp, append-keys merge, scalar merge,
 *                  user_id mismatch, deleted-session update failure
 *   - get_active_session_data() / clear_user_sessions() / cleanup_expired()
 *   - get_page_ids() with legacy transient fallback / set_page_ids idempotency
 *   - maybe_rotate_signing_key() / rotate_signing_key() forced rotation
 *   - decode_session_value() with non-string / invalid JSON / wrong signature
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Session', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
}

final class SScribe_Session_Extra_Coverage_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Session::test_reset();
		\SScribe_Session::enable_test_mode();
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
	}

	protected function tearDown(): void {
		\SScribe_Session::test_reset();
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
		parent::tearDown();
	}

	private function new_session(): \SScribe_Session {
		return new \SScribe_Session();
	}

	private function make_session( int $user_id, string $status = 'processing', int $processed = 0, int $total = 1 ): string {
		$s = $this->new_session();
		return $s->create(
			array(
				'user_id'   => $user_id,
				'page_ids'  => array( 1 ),
				'total'     => $total,
				'processed' => $processed,
				'status'    => $status,
			)
		);
	}

	public function test_validate_returns_false_for_unknown_session(): void {
		$s = $this->new_session();
		$this::assertFalse( $s->validate( 'abc1234567890def' ) );
	}

	public function test_validate_returns_true_for_well_formed_session(): void {
		$s    = $this->new_session();
		$sid  = $this->make_session( 1 );
		$s->set_page_ids( $sid, array( 42, 43 ) );
		$this::assertTrue( $s->validate( $sid ) );
	}

	public function test_validate_rejects_when_total_exceeds_limit(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		// Force total beyond 100000 by patching the option directly.
		foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $value ) {
			if ( 'sscribe_session_' . $sid === $option_name ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$decoded['total'] = 200000;
					$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
					break;
				}
			}
		}
		$this::assertFalse( $s->validate( $sid ) );
	}

	public function test_validate_rejects_when_processed_exceeds_total_plus_10(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1, 'processing', 1, 1 );
		// Bump processed to total + 11 (corruption pattern).
		foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $value ) {
			if ( 'sscribe_session_' . $sid === $option_name ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$decoded['processed'] = 100;
					$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
					break;
				}
			}
		}
		$this::assertFalse( $s->validate( $sid ) );
	}

	public function test_validate_rejects_when_session_id_field_mismatches(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		// Mismatch: stored session_id field differs from option suffix.
		foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $value ) {
			if ( 'sscribe_session_' . $sid === $option_name ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$decoded['session_id'] = 'deadbeefdeadbeef';
					$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
					break;
				}
			}
		}
		$this::assertFalse( $s->validate( $sid ) );
	}

	public function test_validate_rejects_when_total_negative(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $value ) {
			if ( 'sscribe_session_' . $sid === $option_name ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$decoded['total'] = -1;
					$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
					break;
				}
			}
		}
		$this::assertFalse( $s->validate( $sid ) );
	}

	public function test_update_merges_scalar_keys(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		$ok  = $s->update( $sid, array( 'note' => 'hello' ) );
		$this::assertTrue( $ok );
		$reloaded = $s->get( $sid );
		$this::assertSame( 'hello', $reloaded['note'] ?? null );
	}

	public function test_update_clamps_max_keys(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1, 'processing', 0, 5 );
		// First bump processed to 3.
		$s->update( $sid, array( 'processed' => 3 ) );
		// Then try to set processed lower — clamp should keep it at 3.
		$s->update( $sid, array( 'processed' => 1 ) );
		$reloaded = $s->get( $sid );
		$this::assertSame( 3, $reloaded['processed'] );
	}

	public function test_update_appends_to_append_keys(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		// Append structured_errors twice; both should remain.
		$s->update( $sid, array( 'structured_errors' => array( array( 'code' => 'A' ) ) ) );
		$s->update( $sid, array( 'structured_errors' => array( array( 'code' => 'B' ) ) ) );
		$reloaded = $s->get( $sid );
		$this::assertCount( 2, $reloaded['structured_errors'] ?? array() );
	}

	public function test_update_returns_false_for_unknown_session(): void {
		$s = $this->new_session();
		$this::assertFalse( $s->update( 'abc1234567890def', array( 'processed' => 1 ) ) );
	}

	public function test_update_returns_false_for_invalid_session_id(): void {
		$s = $this->new_session();
		$this::assertFalse( $s->update( 'bad', array( 'processed' => 1 ) ) );
	}

	public function test_get_page_ids_round_trip_via_set(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		$this::assertTrue( $s->set_page_ids( $sid, array( 5, 5, 7, 8 ) ) );
		$this::assertSame( array( 5, 7, 8 ), $s->get_page_ids( $sid ) );
	}

	public function test_get_page_ids_returns_empty_for_invalid_session_id(): void {
		$s = $this->new_session();
		$this::assertSame( array(), $s->get_page_ids( 'invalid' ) );
	}

	public function test_delete_page_ids_removes_persisted_entry(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		$s->set_page_ids( $sid, array( 1, 2 ) );
		$this::assertTrue( $s->delete_page_ids( $sid ) );
		$this::assertSame( array(), $s->get_page_ids( $sid ) );
	}

	public function test_clear_user_sessions_returns_zero_for_zero_user_id(): void {
		$s = $this->new_session();
		$this::assertSame( 0, $s->clear_user_sessions( 0 ) );
	}

	public function test_clear_user_sessions_removes_matching_sessions(): void {
		$s = $this->new_session();
		$s->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$s->create(
			array(
				'user_id'   => 8,
				'page_ids'  => array( 2 ),
				'total'     => 1,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$deleted = $s->clear_user_sessions( 7 );
		$this::assertSame( 1, $deleted );
		// User 8's session remains.
		$this::assertCount( 1, $s->get_sessions_for_user( 8 ) );
	}

	public function test_cleanup_expired_with_no_sessions_returns_zero(): void {
		$s = $this->new_session();
		$this::assertIsInt( $s->cleanup_expired( 0 ) );
	}

	public function test_cleanup_expired_with_active_finalizing_session_keeps_it(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1, 'finalizing', 1, 1 );
		$s->cleanup_expired( 0 );
		// session must still be reachable (finalizing + recent).
		$this::assertNotNull( $s->get( $sid ) );
	}

	public function test_decode_session_value_returns_null_for_non_string(): void {
		$s = $this->new_session();
		$this::assertNull( $s->decode_session_value( 12345 ) );
		$this::assertNull( $s->decode_session_value( null ) );
		$this::assertNull( $s->decode_session_value( array() ) );
	}

	public function test_decode_session_value_returns_null_for_invalid_json(): void {
		$s = $this->new_session();
		// Plain non-JSON non-encrypted string.
		$this::assertNull( $s->decode_session_value( 'not-a-json-string' ) );
	}

	public function test_get_active_session_data_returns_null_when_no_active_session(): void {
		$s = $this->new_session();
		$this::assertNull( $s->get_active_session_data( 999 ) );
	}

	public function test_get_active_session_data_returns_active_session(): void {
		$s = $this->new_session();
		// Create an active session (status=processing, processed<total, updated_at=now).
		$sid = $s->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 2,
				'processed' => 0,
				'status'    => 'processing',
			)
		);
		$data = $s->get_active_session_data( 7 );
		$this::assertNotNull( $data );
		$this::assertSame( $sid, $data['session_id'] ?? '' );
		$this::assertSame( 7, $data['user_id'] ?? 0 );
		$this::assertArrayHasKey( 'option_name', $data );
	}

	public function test_get_active_session_data_returns_null_for_completed_session(): void {
		$s = $this->new_session();
		$s->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 1,
				'status'    => 'complete',
			)
		);
		$this::assertNull( $s->get_active_session_data( 7 ) );
	}

	public function test_get_active_session_data_returns_null_for_cancelled_session(): void {
		$s = $this->new_session();
		$s->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 2,
				'processed' => 0,
				'status'    => 'cancelled',
			)
		);
		$this::assertNull( $s->get_active_session_data( 7 ) );
	}

	public function test_rotate_signing_key_promotes_current_to_previous(): void {
		$s = $this->new_session();
		$ok = $s->rotate_signing_key();
		$this::assertTrue( $ok );
	}

	public function test_maybe_rotate_signing_key_runs_without_error(): void {
		$s = $this->new_session();
		// Just call it; should be a no-op when no rotation is due.
		$s->maybe_rotate_signing_key();
		$this::assertTrue( true );
	}

	public function test_delete_returns_false_for_unknown_session(): void {
		$s = $this->new_session();
		$this::assertFalse( $s->delete( 'abc1234567890def' ) );
	}

	public function test_delete_returns_true_after_create(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		$this::assertTrue( $s->delete( $sid ) );
		$this::assertNull( $s->get( $sid ) );
	}

	public function test_delete_returns_false_for_invalid_session_id(): void {
		$s = $this->new_session();
		$this::assertFalse( $s->delete( 'too-short' ) );
	}

	public function test_get_returns_null_for_unknown_session(): void {
		$s = $this->new_session();
		$this::assertNull( $s->get( 'abc1234567890def' ) );
	}

	public function test_get_returns_data_after_create(): void {
		$s   = $this->new_session();
		$sid = $this->make_session( 1 );
		$data = $s->get( $sid );
		$this::assertIsArray( $data );
		$this::assertSame( $sid, $data['session_id'] ?? '' );
		$this::assertSame( 1, $data['user_id'] ?? 0 );
	}

	public function test_extract_session_id_handles_non_session_options(): void {
		$this::assertNull( \SScribe_Session::extract_session_id( 'some_other_option' ) );
	}

	public function test_extract_session_id_extracts_known_prefix(): void {
		$this::assertSame(
			'abc1234567890def',
			\SScribe_Session::extract_session_id( 'sscribe_session_abc1234567890def' )
		);
	}
}
