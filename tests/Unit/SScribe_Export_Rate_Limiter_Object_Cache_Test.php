<?php
/**
 * Phase 14 regression: persistent object-cache path must use TWO keys
 * per logical counter so wp_cache_incr() operates on a scalar integer.
 *
 * Before the fix, the persistent object-cache branch read an array
 * ({count, reset_at}) via wp_cache_get() and then called wp_cache_incr()
 * on the same key. On Redis that mixed-type operation fails; on backends
 * that silently fall back to "set to 1" when incr is rejected the
 * counter never advances past 1, so a single client could exhaust the
 * budget with the first call and every subsequent call would still
 * appear to allow a fresh request. This file guards against that
 * regression by exercising the persistent cache path and asserting:
 *
 *   1. The counter advances monotonically across N successive calls.
 *   2. The :count key holds an integer, never an array.
 *   3. The :reset key holds an integer timestamp, never an array.
 *   4. wp_cache_incr() is the operation that drives the counter, not
 *      a fresh wp_cache_set() on every call.
 *   5. The 201st call returns false (quota exhausted) when the budget is 200.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Rate_Limiter_Object_Cache_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']              = array();
		$GLOBALS['sscribe_test_options']                 = array();
		$GLOBALS['sscribe_test_wp_cache']                = array();
		$GLOBALS['sscribe_test_current_user_id']         = 1;
		$GLOBALS['sscribe_test_current_user_can']        = null;
		$GLOBALS['sscribe_test_filters']                 = array();
		$GLOBALS['sscribe_test_using_ext_object_cache']  = true;
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']              = array();
		$GLOBALS['sscribe_test_options']                 = array();
		$GLOBALS['sscribe_test_wp_cache']                = array();
		$GLOBALS['sscribe_test_current_user_id']         = null;
		$GLOBALS['sscribe_test_current_user_can']        = null;
		$GLOBALS['sscribe_test_filters']                 = array();
		$GLOBALS['sscribe_test_using_ext_object_cache']  = false;
		parent::tearDown();
	}

	/**
	 * Phase 68 #20 — redis rate-limit counter: the persistent
	 * object cache path (Redis, Memcached) must advance the
	 * counter monotonically and never store an array on the
	 * :count key.
	 */
	public function test_persistent_cache_path_advances_counter_monotonically(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertTrue(
				$limiter->check_rate_limit(),
				"Call {$i} should be allowed under the 200/min default budget."
			);
		}

		$cache = $GLOBALS['sscribe_test_wp_cache'];

		// Both :count and :reset keys must exist and hold scalars.
		$count_key = null;
		$reset_key = null;
		foreach ( array_keys( $cache ) as $key ) {
			if ( str_ends_with( $key, ':count' ) ) {
				$count_key = $key;
			} elseif ( str_ends_with( $key, ':reset' ) ) {
				$reset_key = $key;
			}
		}

		$this->assertNotNull( $count_key, 'persistent cache path must write a :count key' );
		$this->assertNotNull( $reset_key, 'persistent cache path must write a :reset key' );
		$this->assertIsInt( $cache[ $count_key ], ':count must hold a scalar integer, not an array' );
		$this->assertIsInt( $cache[ $reset_key ], ':reset must hold a scalar integer timestamp' );

		$this->assertSame(
			50,
			$cache[ $count_key ],
			'after 50 successful calls :count must equal 50 (i.e. wp_cache_incr ran, not "set to 1")'
		);
	}

	public function test_persistent_cache_path_never_stores_array_on_count_key(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		// First call seeds the keys.
		$limiter->check_rate_limit();

		$count_key = null;
		foreach ( array_keys( $GLOBALS['sscribe_test_wp_cache'] ) as $key ) {
			if ( str_ends_with( $key, ':count' ) ) {
				$count_key = $key;
				break;
			}
		}
		$this->assertNotNull( $count_key );
		$this->assertIsInt(
			$GLOBALS['sscribe_test_wp_cache'][ $count_key ],
			':count must NEVER be an array — wp_cache_incr would crash on mixed-type'
		);
	}

	public function test_persistent_cache_path_exhausts_after_budget(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 200; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit() );
		}
		$this->assertFalse(
			$limiter->check_rate_limit(),
			'201st call on the persistent cache path must return quota_exceeded, not silently reset'
		);
	}

	public function test_decision_shape_for_persistent_cache_quota_exceeded(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 200; $i++ ) {
			$limiter->check_rate_limit_decision();
		}
		$decision = $limiter->check_rate_limit_decision();

		$this->assertFalse( $decision->allowed );
		$this->assertSame( \SScribe_Rate_Limit_Decision::REASON_QUOTA_EXCEEDED, $decision->reason );
		$this->assertSame( 'rate_limited', $decision->error_code() );
		$this->assertSame( 429, $decision->http_status() );
		$this->assertGreaterThanOrEqual( 1000, $decision->retry_after_ms );
		$this->assertSame( 0, $decision->remaining );
		$this->assertIsInt( $decision->reset_at );
	}

	public function test_admin_filtered_limit_on_persistent_cache_path(): void {
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_filters']         = array(
			array(
				'hook'     => 'sscribe_rate_limit_admin',
				'callback' => static fn(): int => 5,
			),
		);

		$limiter = new \SScribe_Export_Rate_Limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit() );
		}
		$this->assertFalse( $limiter->check_rate_limit() );
	}
}