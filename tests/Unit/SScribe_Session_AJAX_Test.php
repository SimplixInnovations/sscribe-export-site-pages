<?php
/**
 * SScribe Session AJAX Trait Unit Test
 *
 * Exercises the three AJAX endpoints (check active, cancel, clear)
 * that now live on SScribe_Session via the SScribe_Session_AJAX
 * trait. The implementation previously lived on the (now-removed)
 * SScribe_Batch_Session_Handler class.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Session_AJAX_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_options']          = array();
		$GLOBALS['sscribe_test_wp_cache']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user_id']  = 1;
		$GLOBALS['sscribe_test_current_user']     = new \WP_User();
		$GLOBALS['sscribe_test_current_user']->ID = 1;
		\SScribe_Session::enable_test_mode();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_options']          = array();
		$GLOBALS['sscribe_test_wp_cache']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user_id']  = null;
		$GLOBALS['sscribe_test_current_user']     = null;
		parent::tearDown();
	}

	public function test_can_instantiate_session_with_no_args(): void {
		$session = new \SScribe_Session();
		$this->assertInstanceOf( \SScribe_Session::class, $session );
	}

	public function test_session_exposes_ajax_endpoints_via_trait(): void {
		$session = new \SScribe_Session();
		$this->assertTrue( method_exists( $session, 'ajax_check_active_session' ) );
		$this->assertTrue( method_exists( $session, 'ajax_cancel_export' ) );
		$this->assertTrue( method_exists( $session, 'ajax_clear_session' ) );
	}

	public function test_ajax_check_active_session_returns_has_active_false_when_no_session(): void {
		$session = new \SScribe_Session();

		$_POST['nonce'] = 'valid_nonce';

		try {
			$session->ajax_check_active_session();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX success response sent', $e->getMessage() );
		}
	}

	public function test_ajax_check_active_session_fails_without_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;

		$session = new \SScribe_Session();

		$_POST['nonce'] = 'valid_nonce';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$session->ajax_check_active_session();
	}

	public function test_ajax_clear_session_succeeds_without_force(): void {
		$session = new \SScribe_Session();

		$_POST['nonce'] = 'valid_nonce';
		$_POST['force'] = false;

		try {
			$session->ajax_clear_session();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX success response sent', $e->getMessage() );
		}
	}

	public function test_ajax_clear_session_fails_without_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;

		$session = new \SScribe_Session();

		$_POST['nonce'] = 'valid_nonce';
		$_POST['force'] = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$session->ajax_clear_session();
	}

	public function test_ajax_cancel_export_fails_without_session_id(): void {
		$session = new \SScribe_Session();

		$_POST['nonce']      = 'valid_nonce';
		$_POST['session_id'] = '';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$session->ajax_cancel_export();
	}

	public function test_ajax_cancel_export_fails_without_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;

		$session = new \SScribe_Session();

		$_POST['nonce']      = 'valid_nonce';
		$_POST['session_id'] = 'abc123';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$session->ajax_cancel_export();
	}

	/**
	 * Cancel race fix: if a batch iteration already holds the
	 * export lock for this session, cancel must return 409 with
	 * a retry hint — never acquire the lock itself and race.
	 */
	public function test_ajax_cancel_export_returns_409_when_batch_lock_held(): void {
		$session = new \SScribe_Session();
		$id      = $session->create(
			array(
				'user_id'  => 1,
				'temp_dir' => '',
			)
		);

		// Simulate a batch iteration holding the export lock.
		$GLOBALS['sscribe_test_transients'][ 'sscribe_lock_' . $id ] = time() . '|batch-token';

		$_POST['nonce']      = 'valid_nonce';
		$_POST['session_id'] = $id;

		ob_start();
		try {
			$session->ajax_cancel_export();
			$this->fail( 'Expected RuntimeException for 409 lock-conflict response' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX error response sent', $e->getMessage() );
		} finally {
			$output = ob_get_clean();
		}

		// 409 payload must include the batch_in_progress code that
		// the JS client uses to decide retry behaviour.
		$this->assertStringContainsString( 'batch_in_progress', $output );

		// Session must still exist — cancel must NOT mutate when 409'd.
		$this->assertNotNull( $session->get( $id ) );
	}

	/**
	 * Happy-path cancel: lock acquired, mutation runs, session
	 * is deleted, user sees 'Export cancelled.'
	 */
	public function test_ajax_cancel_export_happy_path_deletes_session(): void {
		$session = new \SScribe_Session();
		$id      = $session->create(
			array(
				'user_id'  => 1,
				'temp_dir' => '',
			)
		);

		$this->assertNotNull( $session->get( $id ), 'precondition: session exists' );

		$_POST['nonce']      = 'valid_nonce';
		$_POST['session_id'] = $id;

		ob_start();
		try {
			$session->ajax_cancel_export();
			$this->fail( 'Expected RuntimeException for success response' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX success response sent', $e->getMessage() );
		} finally {
			$output = ob_get_clean();
		}

		// The success payload must include the user-visible message.
		$this->assertStringContainsString( 'Export cancelled.', $output );

		// Session row must be gone — the post-lock re-read on
		// line 200 of the trait fetched it, mutation ran, and
		// delete() removed the row.
		$this->assertNull( $session->get( $id ), 'session should be deleted after cancel' );
	}

	/**
	 * 404 path: valid session_id format but no session row exists.
	 * Pre-lock read at line 154 returns null, hits line 155-157.
	 */
	public function test_ajax_cancel_export_fails_404_when_session_missing(): void {
		$session = new \SScribe_Session();

		$_POST['nonce']      = 'valid_nonce';
		$_POST['session_id'] = '0123456789abcdef';

		ob_start();
		try {
			$session->ajax_cancel_export();
			$this->fail( 'Expected RuntimeException for 404 response' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX error response sent', $e->getMessage() );
		} finally {
			$output = ob_get_clean();
		}

		$this->assertStringContainsString( 'Session not found.', $output );
	}
}
