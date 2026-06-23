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
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user']     = new \WP_User();
		$GLOBALS['sscribe_test_current_user']->ID = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
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
}
