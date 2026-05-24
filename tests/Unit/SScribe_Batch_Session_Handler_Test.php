<?php
/**
 * SScribe Batch Session Handler Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Batch_Session_Handler_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user']     = new \WP_User();
		$GLOBALS['sscribe_test_current_user']->ID = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user']     = null;
		parent::tearDown();
	}

	public function test_can_instantiate_with_no_args(): void {
		$handler = new \SScribe_Batch_Session_Handler();
		$this->assertInstanceOf( \SScribe_Batch_Session_Handler::class, $handler );
	}

	public function test_can_instantiate_with_dependencies(): void {
		$handler = new \SScribe_Batch_Session_Handler(
			new \SScribe_Session(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor(),
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Export_Lock_Manager( \SScribe_Logger::instance() )
		);
		$this->assertInstanceOf( \SScribe_Batch_Session_Handler::class, $handler );
	}

	public function test_ajax_check_active_session_returns_has_active_false_when_no_session(): void {
		$handler = new \SScribe_Batch_Session_Handler(
			new \SScribe_Session(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor(),
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Export_Lock_Manager( \SScribe_Logger::instance() )
		);

		$_POST['nonce'] = 'valid_nonce';

		try {
			$handler->ajax_check_active_session();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX success response sent', $e->getMessage() );
		}
	}

	public function test_ajax_check_active_session_fails_without_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;

		$handler = new \SScribe_Batch_Session_Handler(
			new \SScribe_Session(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor(),
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Export_Lock_Manager( \SScribe_Logger::instance() )
		);

		$_POST['nonce'] = 'valid_nonce';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$handler->ajax_check_active_session();
	}

	public function test_ajax_clear_session_succeeds_without_force(): void {
		$handler = new \SScribe_Batch_Session_Handler(
			new \SScribe_Session(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor(),
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Export_Lock_Manager( \SScribe_Logger::instance() )
		);

		$_POST['nonce'] = 'valid_nonce';
		$_POST['force'] = false;

		try {
			$handler->ajax_clear_session();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'AJAX success response sent', $e->getMessage() );
		}
	}

	public function test_ajax_clear_session_fails_without_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;

		$handler = new \SScribe_Batch_Session_Handler(
			new \SScribe_Session(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor(),
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Export_Lock_Manager( \SScribe_Logger::instance() )
		);

		$_POST['nonce'] = 'valid_nonce';
		$_POST['force'] = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		$handler->ajax_clear_session();
	}
}
