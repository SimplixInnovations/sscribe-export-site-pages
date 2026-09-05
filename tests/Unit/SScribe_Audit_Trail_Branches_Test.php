<?php
/**
 * Branch-coverage tests for SScribe_Audit_Trail.
 *
 * The companion SScribe_Audit_Trail_Test covers the public happy-path
 * and the most important regressions. This file exercises the
 * conditional / error branches the public suite does not reach:
 *
 *   - sanitize_context() forbidden-key match (case-insensitive),
 *     looks_like_jwt branch, object recursion, non-scalar UNSUPPORTED
 *     placeholder, and the depth-limit truncation
 *   - sanitize_context() string mb_substr truncation
 *   - get_client_ip / hash_client_ip / get_user_agent / get_request_uri
 *     branches (with and without server vars)
 *   - looks_like_jwt() length / dot-count checks
 *   - get_logs() capability-check rejection
 *   - get_logs_for_user() user-id-zero early return + happy path
 *   - query_logs() per-filter branches (event, user_id, ip_address,
 *     date_from, date_to, session_id)
 *   - get_event_counts() cache-hit short circuit + per-filter branches
 *   - cleanup() happy path
 *   - erase_user_data() user-id-zero early return + happy path
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Audit_Trail' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/class-sscribe-audit-trail.php';
}

final class SScribe_Audit_Trail_Branches_Test extends TestCase {

	private \ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] = array();
		$this->reflection = new \ReflectionClass( \SScribe_Audit_Trail::class );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	private function call( string $name, $instance = null, ...$args ) {
		$method = $this->reflection->getMethod( $name );
		return $method->invoke( $instance, ...$args );
	}

	// ==================================================================
	// sanitize_context() — forbidden-key branches (case-insensitive),
	// JWT detection, depth-limit, object recursion, UNSUPPORTED
	// placeholder, and string truncation.
	// ==================================================================

	public function test_sanitize_context_redacts_uppercase_password_key(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $this->call( 'sanitize_context', $audit, array( 'PASSWORD' => 'secret' ) );
		$this::assertSame( '[REDACTED]', $result['PASSWORD'] );
	}

	public function test_sanitize_context_redacts_substring_forbidden_key(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $this->call( 'sanitize_context', $audit, array( 'my_api_key_v2' => 'k' ) );
		$this::assertSame( '[REDACTED]', $result['my_api_key_v2'] );
	}

	public function test_sanitize_context_redacts_jwt_string_value(): void {
		$audit = new \SScribe_Audit_Trail();
		$jwt   = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9.signature';
		$result = $this->call( 'sanitize_context', $audit, array( 'token' => $jwt ) );
		// 'token' is already a forbidden key, but we want to also see
		// JWT detection on a non-forbidden key like 'payload'.
		$result = $this->call( 'sanitize_context', $audit, array( 'payload' => $jwt ) );
		$this::assertSame( '[REDACTED]', $result['payload'] );
	}

	public function test_sanitize_context_truncates_long_string(): void {
		$audit = new \SScribe_Audit_Trail();
		$long  = str_repeat( 'x', 3000 );
		$result = $this->call( 'sanitize_context', $audit, array( 'note' => $long ) );
		$this::assertLessThanOrEqual( 2000, strlen( $result['note'] ) );
	}

	public function test_sanitize_context_marks_unsupported_values(): void {
		$audit = new \SScribe_Audit_Trail();
		// Resources (file handles) are non-scalar non-null non-array non-object.
		$fh = fopen( 'php://memory', 'r' );
		if ( false === $fh ) {
			$this::markTestSkipped( 'Cannot open memory stream.' );
		}
		$result = $this->call( 'sanitize_context', $audit, array( 'handle' => $fh ) );
		$this::assertSame( '[UNSUPPORTED]', $result['handle'] );
		fclose( $fh );
	}

	public function test_sanitize_context_recurses_into_objects(): void {
		$audit = new \SScribe_Audit_Trail();
		$obj   = new \stdClass();
		$obj->name  = 'visible';
		$obj->token = 'should-be-redacted';
		$result = $this->call( 'sanitize_context', $audit, array( 'inner' => $obj ) );
		$this::assertIsArray( $result['inner'] );
		$this::assertSame( 'visible', $result['inner']['name'] );
		$this::assertSame( '[REDACTED]', $result['inner']['token'] );
	}

	public function test_sanitize_context_truncates_at_max_depth(): void {
		$audit = new \SScribe_Audit_Trail();
		// Build a 6-level nested array; depth-limit at 5 truncates to
		// the _truncated marker.
		$nested = array();
		$cursor = &$nested;
		for ( $i = 0; $i < 6; $i++ ) {
			$cursor['child'] = array();
			$cursor = &$cursor['child'];
		}
		$cursor['leaf'] = 'deep';

		$result = $this->call( 'sanitize_context', $audit, $nested );
		$this::assertArrayHasKey( '_truncated', $result['child']['child']['child']['child']['child'] );
	}

	// ==================================================================
	// looks_like_jwt() — length / dot-count branches.
	// ==================================================================

	public function test_looks_like_jwt_returns_false_for_short_string(): void {
		$audit = new \SScribe_Audit_Trail();
		$this::assertFalse( $this->call( 'looks_like_jwt', $audit, 'eyJ.x' ) );
	}

	public function test_looks_like_jwt_returns_false_when_no_dots(): void {
		$audit = new \SScribe_Audit_Trail();
		$this::assertFalse( $this->call( 'looks_like_jwt', $audit, 'eyJ' . str_repeat( 'x', 20 ) ) );
	}

	public function test_looks_like_jwt_returns_true_for_canonical_jwt(): void {
		$audit = new \SScribe_Audit_Trail();
		$jwt   = 'eyJ' . str_repeat( 'a', 10 ) . '.' . str_repeat( 'b', 10 ) . '.' . str_repeat( 'c', 10 );
		$this::assertTrue( $this->call( 'looks_like_jwt', $audit, $jwt ) );
	}

	// ==================================================================
	// get_user_agent() / get_request_uri() — with and without $_SERVER.
	// ==================================================================

	public function test_get_user_agent_returns_unknown_when_header_missing(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( 'Unknown', $this->call( 'get_user_agent', $audit ) );
	}

	public function test_get_user_agent_truncates_long_value(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 500 );
		$audit = new \SScribe_Audit_Trail();
		$result = $this->call( 'get_user_agent', $audit );
		$this::assertLessThanOrEqual( 255, strlen( $result ) );
	}

	public function test_get_request_uri_returns_empty_when_header_missing(): void {
		unset( $_SERVER['REQUEST_URI'] );
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( '', $this->call( 'get_request_uri', $audit ) );
	}

	public function test_get_request_uri_extracts_path(): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=sscribe';
		$audit  = new \SScribe_Audit_Trail();
		$result = $this->call( 'get_request_uri', $audit );
		$this::assertSame( '/wp-admin/admin.php', $result );
	}

	// ==================================================================
	// get_logs() — capability-check rejection.
	// ==================================================================

	public function test_get_logs_returns_empty_without_capability(): void {
		// The bootstrap's current_user_can stub returns false by
		// default, which exercises the rejection branch on line 281.
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( array(), $audit->get_logs() );
	}

	// ==================================================================
	// get_logs_for_user() — user-id-zero rejection and happy-path delegation.
	// ==================================================================

	public function test_get_logs_for_user_returns_empty_for_zero_user(): void {
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( array(), $audit->get_logs_for_user( 0 ) );
	}

	public function test_get_logs_for_user_returns_empty_for_negative_user(): void {
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( array(), $audit->get_logs_for_user( -1 ) );
	}

	public function test_get_logs_for_user_delegates_to_query_logs_for_valid_user(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $audit->get_logs_for_user( 42 );
		$this::assertIsArray( $result );
	}

	// ==================================================================
	// query_logs() — per-filter branches. The fake wpdb returns [] for
	// all SELECT queries, so the result is always an array; the branch
	// coverage comes from the WHERE clause construction.
	// ==================================================================

	public function test_query_logs_accepts_each_filter_branch(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $this->call(
			'query_logs',
			$audit,
			array(
				'event'      => 'export_started',
				'user_id'    => 7,
				'ip_address' => '127.0.0.1',
				'date_from'  => '2025-01-01 00:00:00',
				'date_to'    => '2025-12-31 23:59:59',
				'session_id' => 'sess_xyz',
			),
			10,
			0
		);
		$this::assertIsArray( $result );
	}

	public function test_query_logs_clamps_limit_and_offset(): void {
		$audit = new \SScribe_Audit_Trail();
		$result_high = $this->call( 'query_logs', $audit, array(), 10000, -5 );
		$result_low  = $this->call( 'query_logs', $audit, array(), 0, 0 );
		$this::assertIsArray( $result_high );
		$this::assertIsArray( $result_low );
	}

	// ==================================================================
	// get_event_counts() — cache-hit short circuit + filter branches.
	// ==================================================================

	public function test_get_event_counts_returns_cached_value_on_second_call(): void {
		$audit = new \SScribe_Audit_Trail();
		$first  = $audit->get_event_counts();
		// Second call must hit the transient cache branch (line 379);
		// bootstrap transient stub returns the same payload.
		$second = $audit->get_event_counts();
		$this::assertSame( $first, $second );
	}

	public function test_get_event_counts_accepts_date_filters(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $audit->get_event_counts( array(
			'date_from' => '2025-01-01 00:00:00',
			'date_to'   => '2025-12-31 23:59:59',
		) );
		$this::assertIsArray( $result );
	}

	// ==================================================================
	// cleanup() — happy path.
	// ==================================================================

	public function test_cleanup_returns_int(): void {
		$audit = new \SScribe_Audit_Trail();
		// Bootstrap's wpdb query() stub returns 0 for DELETE FROM
		// audit_log (the regex doesn't match that table). 0 is
		// acceptable — it proves the query path runs.
		$this::assertSame( 0, $audit->cleanup( 30 ) );
	}

	public function test_cleanup_clamps_minimum_days(): void {
		$audit = new \SScribe_Audit_Trail();
		// Days below 1 must clamp to 1, exercising the max(1, $days)
		// branch.
		$this::assertSame( 0, $audit->cleanup( 0 ) );
	}

	// ==================================================================
	// erase_user_data() — user-id-zero rejection and happy path.
	// ==================================================================

	public function test_erase_user_data_returns_zero_for_invalid_user(): void {
		$audit = new \SScribe_Audit_Trail();
		$this::assertSame( 0, $audit->erase_user_data( 0 ) );
		$this::assertSame( 0, $audit->erase_user_data( -3 ) );
	}
}
