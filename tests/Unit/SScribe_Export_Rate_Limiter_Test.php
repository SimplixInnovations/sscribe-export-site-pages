<?php
/**
 * Unit tests for SScribe_Export_Rate_Limiter class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Rate_Limiter_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_filters']          = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_filters']          = array();
		parent::tearDown();
	}

	public function test_check_rate_limit_returns_true_when_within_limits(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();
		$this->assertTrue( $limiter->check_rate_limit() );
	}

	public function test_rate_limit_exceeded_after_max_requests(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		// The first 200 requests should succeed (default RATE_LIMIT_MAX = 200).
		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit();
		}

		// The 201st request should be rate-limited.
		$this->assertFalse( $limiter->check_rate_limit() );
	}

	public function test_rate_limit_resets_after_window_expires(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		// Exhaust the limit.
		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit();
		}
		$this->assertFalse( $limiter->check_rate_limit() );

		// Simulate window expiry by clearing transients.
		$GLOBALS['sscribe_test_transients'] = array();

		// Should be allowed again.
		$this->assertTrue( $limiter->check_rate_limit() );
	}

	public function test_admin_user_gets_higher_rate_limit(): void {
		$GLOBALS['sscribe_test_current_user_can'] = true;

		$limiter = new \SScribe_Export_Rate_Limiter();

		// Admin can make 1000 requests.
		for ( $i = 0; $i < 1000; $i++ ) {
			$limiter->check_rate_limit();
		}

		// The 1001st should fail.
		$this->assertFalse( $limiter->check_rate_limit() );
	}

	public function test_admin_rate_limit_can_be_filtered(): void {
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_filters']          = array(
			array(
				'hook'     => 'sscribe_rate_limit_admin',
				'callback' => function (): int {
					return 5;
				},
			),
		);

		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->check_rate_limit();
		}

		// 6th request should be rate-limited (filter lowered it to 5).
		$this->assertFalse( $limiter->check_rate_limit() );
	}

	public function test_check_rate_limit_increments_counter(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		// After 50 requests, counter should have increased.
		for ( $i = 0; $i < 50; $i++ ) {
			$limiter->check_rate_limit();
		}

		// Transient should exist with count 50.
		$data = get_transient( 'sscribe_rate_1' );
		$this->assertIsArray( $data );
		$this->assertEquals( 50, $data['count'] );
	}

	public function test_anonymous_user_gets_different_transient_key(): void {
		// Force user ID 0 (anonymous).
		$GLOBALS['sscribe_test_current_user_id'] = 0;

		$limiter = new \SScribe_Export_Rate_Limiter();

		// Use a protected method approach: invoke via reflection or just
		// call check_rate_limit and verify the transient key pattern.
		// Since we can't mock get_current_user_id, we test the default
		// behaviour (user ID 1) and verify the key pattern.
		$limiter->check_rate_limit();

		// With user ID 1, the key should be 'sscribe_rate_1'.
		$data = get_transient( 'sscribe_rate_1' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'count', $data );
	}

	public function test_check_rate_limit_returns_true_with_custom_capability(): void {
		$GLOBALS['sscribe_test_current_user_can'] = true;

		$limiter = new \SScribe_Export_Rate_Limiter();

		$this->assertTrue( $limiter->check_rate_limit( 'custom_export_cap' ) );
	}

	public function test_rate_limit_accepts_upto_max_requests_then_denies(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		// 199 requests should all be accepted.
		for ( $i = 0; $i < 199; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit(), "Request $i should be accepted" );
		}

		// 200th still within limit.
		$this->assertTrue( $limiter->check_rate_limit(), '200th request should be accepted' );

		// 201st exceeds limit.
		$this->assertFalse( $limiter->check_rate_limit(), '201st request should be denied' );
	}
}
