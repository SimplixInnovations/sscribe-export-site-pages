<?php
/**
 * SScribe Export Rate Limiter Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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

		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit();
		}

		$this->assertFalse( $limiter->check_rate_limit() );
	}

	public function test_rate_limit_resets_after_window_expires(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit();
		}
		$this->assertFalse( $limiter->check_rate_limit() );

		$GLOBALS['sscribe_test_transients'] = array();

		$this->assertTrue( $limiter->check_rate_limit() );
	}

	public function test_admin_user_gets_higher_rate_limit(): void {
		$GLOBALS['sscribe_test_current_user_can'] = true;

		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 1000; $i++ ) {
			$limiter->check_rate_limit();
		}

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

		$this->assertFalse( $limiter->check_rate_limit() );
	}

	public function test_check_rate_limit_increments_counter(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 50; $i++ ) {
			$limiter->check_rate_limit();
		}

		// Key format: sscribe_rate_{bucket}_{user_id} — bucket defaults to 'export', user_id is 1.
		$data = get_transient( 'sscribe_rate_export_1' );
		$this->assertIsArray( $data );
		$this->assertEquals( 50, $data['count'] );
	}

	public function test_anonymous_user_gets_different_transient_key(): void {

		$GLOBALS['sscribe_test_current_user_id'] = 0;

		$limiter = new \SScribe_Export_Rate_Limiter();

		$limiter->check_rate_limit();

		// Anonymous user transient key uses IP hash, not numeric user ID.
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$anon_key  = 'sscribe_rate_export_anon_' . substr( hash( 'sha256', $ip ), 0, 12 );
		$data      = get_transient( $anon_key );
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

		for ( $i = 0; $i < 199; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit(), "Request $i should be accepted" );
		}

		$this->assertTrue( $limiter->check_rate_limit(), '200th request should be accepted' );

		$this->assertFalse( $limiter->check_rate_limit(), '201st request should be denied' );
	}
}
