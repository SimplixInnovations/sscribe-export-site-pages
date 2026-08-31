<?php
/**
 * SScribe Rate Limit Decision Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Rate_Limit_Decision_Test extends TestCase {

	public function test_allowed_factory_sets_positive_state(): void {
		$d = \SScribe_Rate_Limit_Decision::allowed( 'export', 500, 499, 1700000000 );
		$this->assertTrue( $d->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_ALLOWED, $d->reason );
		$this->assertSame( 'export', $d->bucket );
		$this->assertSame( 500, $d->limit );
		$this->assertSame( 499, $d->remaining );
		$this->assertSame( 1700000000, $d->reset_at );
		$this->assertSame( 200, $d->http_status() );
		$this->assertSame( 'rate_limit_allowed', $d->error_code() );
	}

	public function test_quota_exceeded_factory_returns_429(): void {
		$d = \SScribe_Rate_Limit_Decision::quota_exceeded( 'export', 500, 15000, 1700000015 );
		$this->assertFalse( $d->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_QUOTA_EXCEEDED, $d->reason );
		$this->assertSame( 429, $d->http_status() );
		$this->assertSame( 'rate_limited', $d->error_code() );
		$this->assertGreaterThanOrEqual( 1000, $d->retry_after_ms );
		$this->assertSame( 15000, $d->retry_after_ms );
		$this->assertSame( 0, $d->remaining );
		$this->assertSame( 1700000015, $d->reset_at );
	}

	public function test_quota_exceeded_below_one_second_retry_is_clamped(): void {
		$d = \SScribe_Rate_Limit_Decision::quota_exceeded( 'export', 500, 100, 1700000001 );
		$this->assertGreaterThanOrEqual( 1000, $d->retry_after_ms );
	}

	public function test_limiter_contention_returns_503(): void {
		$d = \SScribe_Rate_Limit_Decision::limiter_contention( 'export_batch', 500, 750 );
		$this->assertFalse( $d->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_LIMITER_CONTENTION, $d->reason );
		$this->assertSame( 503, $d->http_status() );
		$this->assertSame( 'rate_limiter_busy', $d->error_code() );
		$this->assertSame( 750, $d->retry_after_ms );
		$this->assertNull( $d->remaining );
		$this->assertNull( $d->reset_at );
	}

	public function test_limiter_contention_below_200ms_is_clamped(): void {
		$d = \SScribe_Rate_Limit_Decision::limiter_contention( 'export_batch', 500, 50 );
		$this->assertGreaterThanOrEqual( 200, $d->retry_after_ms );
	}

	public function test_decision_is_immutable(): void {
		$d = \SScribe_Rate_Limit_Decision::allowed( 'export', 100, 99, 1700000000 );
		$reflection = new \ReflectionClass( $d );
		$properties = $reflection->getProperties();
		foreach ( $properties as $p ) {
			$this->assertTrue( $p->isReadOnly(), "Property {$p->getName()} must be readonly" );
		}
	}
}
