<?php
/**
 * SScribe Rate Limiter Buckets Decision-Path Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Rate_Limiter_Buckets_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_options']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_filters']          = array();
		$GLOBALS['sscribe_test_current_user_id']  = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_options']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_filters']          = array();
		$GLOBALS['sscribe_test_current_user_id']  = 0;
		parent::tearDown();
	}

	public function test_decision_path_distinguishes_quota_from_contention(): void {
		$limiter  = new \SScribe_Export_Rate_Limiter();
		$decision = $limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );
		$this->assertTrue( $decision->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_ALLOWED, $decision->reason );
		$this->assertSame( 200, $decision->http_status() );
	}

	public function test_decision_path_returns_quota_exceeded_after_200_calls(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();
		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );
		}
		$decision = $limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );
		$this->assertFalse( $decision->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_QUOTA_EXCEEDED, $decision->reason );
		$this->assertSame( 429, $decision->http_status() );
	}

	public function test_decision_path_reports_limiter_contention(): void {
		$bucket_key = 'sscribe_rate_export_start_1';
		$lock_key   = 'sscribe_rate_lock_' . substr( hash( 'sha256', $bucket_key ), 0, 32 );
		$lock_value = time() . '|another-request';
		add_option( $lock_key, $lock_value, '', false );

		$limiter  = new \SScribe_Export_Rate_Limiter();
		$decision = $limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );

		$this->assertFalse( $decision->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_LIMITER_CONTENTION, $decision->reason );
		$this->assertSame( 503, $decision->http_status() );
		$this->assertGreaterThanOrEqual( 200, $decision->retry_after_ms );
	}

	public function test_decision_path_unknown_bucket_falls_back_to_default(): void {
		$limiter  = new \SScribe_Export_Rate_Limiter();
		$decision = $limiter->check_rate_limit_decision( 'sscribe_export', 'unknown-bucket' );
		$this->assertTrue( $decision->allowed );
		$this->assertSame( \SScribe_Export_Rate_Limiter::BUCKET_DEFAULT, $decision->bucket );
	}

	public function test_decision_path_distinct_buckets_have_distinct_counters(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();
		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );
		}
		$denied = $limiter->check_rate_limit_decision( 'sscribe_export', 'export_start' );
		$this->assertFalse( $denied->allowed );

		$still_open = $limiter->check_rate_limit_decision( 'sscribe_export', 'export_batch' );
		$this->assertTrue( $still_open->allowed, 'export_batch must remain independent of export_start' );
	}
}
