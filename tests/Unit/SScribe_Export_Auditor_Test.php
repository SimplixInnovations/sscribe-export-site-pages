<?php
/**
 * SScribe Export Auditor Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Auditor_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$user = new \WP_User();
		$user->user_login = 'testadmin';
		$user->ID = 1;
		$GLOBALS['sscribe_test_current_user'] = $user;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_current_user'] );
		parent::tearDown();
	}

	public function test_can_instantiate_with_no_args(): void {
		$auditor = new \SScribe_Export_Auditor();
		$this->assertInstanceOf( \SScribe_Export_Auditor::class, $auditor );
	}

	public function test_log_export_started_does_not_throw(): void {
		$auditor = new \SScribe_Export_Auditor();
		$auditor->log( 'export_started' );
		$this->assertTrue( true );
	}

	public function test_log_export_completed_does_not_throw(): void {
		$auditor = new \SScribe_Export_Auditor();
		$auditor->log( 'export_completed' );
		$this->assertTrue( true );
	}

	public function test_log_export_failed_does_not_throw(): void {
		$auditor = new \SScribe_Export_Auditor();
		$auditor->log( 'export_failed' );
		$this->assertTrue( true );
	}

	public function test_log_download_does_not_throw(): void {
		$auditor = new \SScribe_Export_Auditor();
		$auditor->log( 'download' );
		$this->assertTrue( true );
	}

	public function test_log_unrecognised_action_does_not_throw(): void {
		$auditor = new \SScribe_Export_Auditor();
		$auditor->log( 'unknown_action_xyz' );
		$this->assertTrue( true );
	}

	public function test_log_with_audit_trail_mock_calls_log_method(): void {
		$audit_trail = $this->createMock( \SScribe_Audit_Trail::class );
		$audit_trail->expects( $this->once() )->method( 'log' );

		$logger = $this->createMock( \SScribe_Logger_Interface::class );
		$logger->expects( $this->once() )->method( 'debug' );

		$auditor = new \SScribe_Export_Auditor( $audit_trail, $logger );
		$auditor->log( 'export_started', array( 'page_count' => 10 ) );
	}

	public function test_log_with_unrecognised_action_only_calls_debug(): void {
		$audit_trail = $this->createMock( \SScribe_Audit_Trail::class );
		$audit_trail->expects( $this->never() )->method( 'log' );

		$logger = $this->createMock( \SScribe_Logger_Interface::class );
		$logger->expects( $this->once() )->method( 'debug' );

		$auditor = new \SScribe_Export_Auditor( $audit_trail, $logger );
		$auditor->log( 'bogus_action' );
	}

	public function test_log_with_context_includes_context_data(): void {
		$logger = $this->createMock( \SScribe_Logger_Interface::class );
		$logger->expects( $this->once() )
			->method( 'debug' )
			->with(
				$this->stringContains( '[AUDIT]' ),
				$this->arrayHasKey( 'context' )
			);

		$auditor = new \SScribe_Export_Auditor( null, $logger );
		$auditor->log( 'export_started', array( 'page_ids' => array( 1, 2, 3 ) ) );
	}
}
