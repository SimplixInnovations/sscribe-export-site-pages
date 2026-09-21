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
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_options']          = array();
		$GLOBALS['sscribe_test_wp_cache']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user_id']  = null;
		$GLOBALS['sscribe_test_filters']          = array();
		unset( $GLOBALS['sscribe_test_before_wpdb_option_delete'] );
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_options']          = array();
		$GLOBALS['sscribe_test_wp_cache']         = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user_id']  = null;
		$GLOBALS['sscribe_test_filters']          = array();
		unset( $GLOBALS['sscribe_test_before_wpdb_option_delete'] );
		parent::tearDown();
	}

	public function test_check_rate_limit_returns_true_when_within_limits(): void {
		$limiter = new \SScribe_Export_Rate_Limiter();
		$this->assertTrue( $limiter->check_rate_limit() );
	}

	public function test_database_micro_lock_contention_fails_closed(): void {
		$bucket_key = 'sscribe_rate_export_1';
		$lock_key   = 'sscribe_rate_lock_' . substr( hash( 'sha256', $bucket_key ), 0, 32 );
		$lock_value = time() . '|another-request';
		add_option( $lock_key, $lock_value, '', false );

		$limiter = new \SScribe_Export_Rate_Limiter();
		$this->assertFalse( $limiter->check_rate_limit() );
		$this->assertSame( $lock_value, get_option( $lock_key ) );
	}

	/**
	 * Regression: stale DB-lock reclamation must not delete a successor lock.
	 *
	 * Simulate another request replacing the stale row after this request has
	 * observed it but before the ownership-conditional delete executes. The
	 * limiter must fail closed and preserve the successor's value.
	 */
	public function test_stale_database_lock_reclamation_preserves_successor(): void {
		$bucket_key = 'sscribe_rate_export_1';
		$lock_key   = 'sscribe_rate_lock_' . substr( hash( 'sha256', $bucket_key ), 0, 32 );
		$stale      = ( time() - 10 ) . '|stale-owner';
		$successor  = time() . '|successor-owner';
		add_option( $lock_key, $stale, '', false );

		$GLOBALS['sscribe_test_before_wpdb_option_delete'] = static function ( array $where ) use ( $lock_key, $successor ): void {
			if ( ( $where['option_name'] ?? '' ) === $lock_key ) {
				$GLOBALS['sscribe_test_options'][ $lock_key ] = $successor;
			}
		};

		$limiter  = new \SScribe_Export_Rate_Limiter();
		$decision = $limiter->check_rate_limit_decision();

		$this->assertFalse( $decision->allowed, 'A changed stale lock must fail closed instead of admitting a concurrent request.' );
		$this->assertSame( 'limiter_contention', $decision->reason );
		$this->assertSame( $successor, get_option( $lock_key ), 'Stale reclamation must never delete a successor lock.' );
	}

	public function test_database_lock_release_preserves_successor(): void {
		$lock_key  = 'sscribe_rate_lock_release_regression';
		$token     = 'original-owner';
		$stored    = time() . '|' . $token;
		$successor = time() . '|successor-owner';
		add_option( $lock_key, $stored, '', false );

		$GLOBALS['sscribe_test_before_wpdb_option_delete'] = static function ( array $where ) use ( $lock_key, $successor ): void {
			if ( ( $where['option_name'] ?? '' ) === $lock_key ) {
				$GLOBALS['sscribe_test_options'][ $lock_key ] = $successor;
			}
		};

		$release = \Closure::bind(
			static function ( \SScribe_Export_Rate_Limiter $limiter ) use ( $lock_key, $token ): void {
				$limiter->release_lock( $lock_key, $token );
			},
			null,
			\SScribe_Export_Rate_Limiter::class
		);

		$release( new \SScribe_Export_Rate_Limiter() );

		$this->assertSame(
			$successor,
			get_option( $lock_key ),
			'Releasing an old lock must never delete a successor acquired after ownership was observed.'
		);
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

	/**
	 * Regression: HTTP_CF_CONNECTING_IP must be validated as a real IP.
	 * Before the fix, the header was used after only sanitize_text_field(),
	 * allowing a non-IP string to bypass per-IP rate limiting via header
	 * spoofing when the server was not actually behind Cloudflare.
	 */
	public function test_get_client_ip_rejects_invalid_cf_header(): void {
		$limiter  = new \SScribe_Export_Rate_Limiter();
		// setAccessible(true) is deprecated in PHP 8.1+. Use Closure::bind
		// to invoke the private method via a scoped callable.
		$method   = \Closure::bind(
			function ( $limiter ) {
				return $limiter->get_client_ip();
			},
			null,
			\SScribe_Export_Rate_Limiter::class
		);

		$original_cf  = $_SERVER['HTTP_CF_CONNECTING_IP']  ?? null;
		$original_xf  = $_SERVER['HTTP_X_FORWARDED_FOR']   ?? null;
		$original_ra  = $_SERVER['REMOTE_ADDR']           ?? null;

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '<script>alert(1)</script>';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = 'not-an-ip';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
		$GLOBALS['sscribe_test_filters']  = array(
			array(
				'hook'     => 'sscribe_trusted_ip_headers',
				'callback' => static fn(): array => array( 'CF-Connecting-IP', 'X-Forwarded-For' ),
			),
		);

		try {
			$ip = $method( $limiter );
			$this->assertSame( '203.0.113.5', $ip, 'Malformed CF/XF headers must fall through to REMOTE_ADDR' );
		} finally {
			if ( null === $original_cf ) {
				unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
			} else {
				$_SERVER['HTTP_CF_CONNECTING_IP'] = $original_cf;
			}
			if ( null === $original_xf ) {
				unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
			} else {
				$_SERVER['HTTP_X_FORWARDED_FOR'] = $original_xf;
			}
			if ( null === $original_ra ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $original_ra;
			}
		}
	}

	/**
	 * Regression: HTTP_X_FORWARDED_FOR must accept IPv6 addresses.
	 * Before the fix, the regex /^([0-9.]+,?)+$/i only matched IPv4,
	 * so all IPv6 clients behind a proxy fell through to REMOTE_ADDR
	 * and were bucketed into a single rate-limit group.
	 */
	public function test_get_client_ip_accepts_ipv6_in_x_forwarded_for(): void {
		$limiter  = new \SScribe_Export_Rate_Limiter();
		// setAccessible(true) is deprecated in PHP 8.1+. Use Closure::bind
		// to invoke the private method via a scoped callable.
		$method   = \Closure::bind(
			function ( $limiter ) {
				return $limiter->get_client_ip();
			},
			null,
			\SScribe_Export_Rate_Limiter::class
		);

		$original_cf  = $_SERVER['HTTP_CF_CONNECTING_IP']  ?? null;
		$original_xf  = $_SERVER['HTTP_X_FORWARDED_FOR']   ?? null;
		$original_ra  = $_SERVER['REMOTE_ADDR']           ?? null;

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '2001:db8::dead:beef';
		$_SERVER['REMOTE_ADDR']           = '127.0.0.1';
		$GLOBALS['sscribe_test_filters']  = array(
			array(
				'hook'     => 'sscribe_trusted_ip_headers',
				'callback' => static fn(): array => array( 'CF-Connecting-IP', 'X-Forwarded-For' ),
			),
		);

		try {
			$ip = $method( $limiter );
			$this->assertSame( '2001:db8::1', $ip, 'CF takes precedence when valid IPv6' );
		} finally {
			if ( null === $original_cf ) {
				unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
			} else {
				$_SERVER['HTTP_CF_CONNECTING_IP'] = $original_cf;
			}
			if ( null === $original_xf ) {
				unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
			} else {
				$_SERVER['HTTP_X_FORWARDED_FOR'] = $original_xf;
			}
			if ( null === $original_ra ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $original_ra;
			}
		}
	}

	/**
	 * Regression (audit #23): different buckets must be tracked independently.
	 *
	 * Before the fix, batch continuation calls and the initial export start
	 * shared the same 'export' counter, so a 1000-page export's 200 batch
	 * calls could exhaust the 200/min "start a new export" budget mid-flight
	 * and rate-limit the export's own continuation. The fix added an
	 * 'export_batch' bucket so the two concerns don't share a counter.
	 */
	public function test_buckets_are_independent(): void {
		// Earlier test_anonymous_user_gets_different_transient_key sets
		// current_user_id=0 and does not reset it. Reset here so the keys
		// in this test are the documented `_1` / `_batch_1` form.
		$GLOBALS['sscribe_test_current_user_id']  = 1;
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_filters']          = array();

		$limiter = new \SScribe_Export_Rate_Limiter();

		// Exhaust the 'export' bucket.
		for ( $i = 0; $i < 200; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit( 'sscribe_export', 'export' ) );
		}
		$this->assertFalse( $limiter->check_rate_limit( 'sscribe_export', 'export' ), 'export bucket should now be exhausted' );

		// The 'export_batch' bucket is a fresh counter — must still allow.
		$this->assertTrue(
			$limiter->check_rate_limit( 'sscribe_export', 'export_batch' ),
			'exhausted export bucket must not affect export_batch bucket'
		);

		// Both buckets expose their counters under their own transient keys.
		$export_count        = get_transient( 'sscribe_rate_export_1' );
		$export_batch_count  = get_transient( 'sscribe_rate_export_batch_1' );
		$this->assertIsArray( $export_count );
		$this->assertIsArray( $export_batch_count );
		$this->assertSame( 200, $export_count['count'] );
		$this->assertSame( 1, $export_batch_count['count'] );
	}
}
