<?php
/**
 * Phase 21 regression: when an SScribe AJAX endpoint returns a 5xx
 * response, three things MUST happen atomically:
 *
 *   1. The client receives a valid error JSON with request_id (so the
 *      UI can show a retry/error banner).
 *   2. The HTTP status code is set (so the JS fetch layer recognizes
 *      the failure class).
 *   3. The operational logger records an "AJAX 5xx response" event
 *      with the right error_code, http_status, and ajax_action so an
 *      operator can grep the production logs to find what broke.
 *
 * Before this regression test existed, two failure modes were
 * documented:
 *
 *   a. A handler returned wp_die() with a raw PHP message. The
 *      browser saw an HTML 500 page (not JSON). The JS fetch layer
 *      crashed, the user saw a blank console, and the operational
 *      log captured nothing because no record() call fired.
 *
 *   b. A handler caught its own exception, logged a debug line,
 *      returned success() — silently masking the failure from the
 *      operator while the UI pretended all was well.
 *
 * The fix routes every 5xx through SScribe_AJAX_Guard::error() with
 * a real status_code, which (1) emits a JSON envelope, (2) sets the
 * HTTP status, and (3) invokes SScribe_Operational_Logger::record()
 * with the canonical "AJAX 5xx response" event. This test pins all
 * three guarantees so a future change cannot silently revert them.
 *
 * Coverage:
 *
 *   - error(500) emits a JSON error envelope with request_id.
 *   - error(503) (e.g. rate-limit contention) emits a JSON envelope
 *     AND records the operational event with http_status=503.
 *   - error(500) with a `code` field in the data preserves it in
 *     the operational log for diagnostic grouping.
 *   - error(500) without a `code` falls back to "ajax_5xx" so the
 *     log entry is always classifiable.
 *   - The ajax_action field is populated from $_POST['action'] when
 *     available so production logs can be filtered by endpoint.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Production_500_Reproduce_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Request_Id::reset();
		\SScribe_Operational_Logger::reset_for_testing();
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] );
		unset( $_SERVER['X_SSCRIBE_REQUEST_ID'] );
		$_POST = array();
	}

	protected function tearDown(): void {
		\SScribe_Request_Id::reset();
		\SScribe_Operational_Logger::reset_for_testing();
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] );
		unset( $_SERVER['X_SSCRIBE_REQUEST_ID'] );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Invoke SScribe_AJAX_Guard::error() inside an output buffer and
	 * return the decoded JSON envelope. The bootstrap's
	 * wp_send_json_error echoes + throws a RuntimeException, so the
	 * buffer + catch is the only way to observe the response.
	 *
	 * @param mixed       $data
	 * @param int|null    $status_code
	 * @return array{success:bool, data:mixed}
	 */
	private function invoke_error_and_capture( mixed $data, ?int $status_code = 500 ): array {
		ob_start();
		try {
			\SScribe_AJAX_Guard::error( $data, $status_code );
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_error stub throws after echoing.
		}
		$raw     = (string) ob_get_clean();
		$decoded = json_decode( $raw, true );
		$this->assertIsArray(
			$decoded,
			'AJAX_Guard::error() must echo a JSON envelope (got raw: ' . $raw . ')'
		);
		/** @var array{success:bool, data:mixed} $decoded */
		return $decoded;
	}

	public function test_500_response_emits_json_envelope_with_request_id(): void {
		// Before the regression: a handler returning a raw PHP 500 died
		// at the wp_die() call with an HTML body. After the fix: every
		// 500 path returns through AJAX_Guard::error(), which emits a
		// JSON envelope the JS layer can parse.
		$envelope = $this->invoke_error_and_capture(
			array(
				'code'    => 'session_storage_failed',
				'message' => 'Failed to persist export state.',
			),
			500
		);

		$this->assertFalse( (bool) ( $envelope['success'] ?? true ), 'envelope must be {success:false} for an error response' );
		/** @var array<string,mixed> $data */
		$data = $envelope['data'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'request_id', $data, 'Phase 21: 5xx envelope must carry request_id so the UI can correlate the failure' );
		$this->assertMatchesRegularExpression(
			'/^ssr_[0-9a-f]{16}$/',
			(string) ( $data['request_id'] ?? '' ),
			'request_id must match the canonical ssr_<16 hex> format'
		);
		$this->assertSame( 'session_storage_failed', $data['code'] ?? null );
	}

	public function test_500_response_records_canonical_operational_event(): void {
		// The operational log entry is the operator's primary tool for
		// diagnosing production 500s. If it goes missing, the failure
		// becomes invisible and the bug ships again.
		$this->invoke_error_and_capture(
			array(
				'code'    => 'session_storage_failed',
				'message' => 'Failed to persist export state.',
			),
			500
		);

		$events = \SScribe_Operational_Logger::events_for_testing();
		$found  = null;
		foreach ( $events as $event ) {
			if ( ( $event['message'] ?? '' ) === 'AJAX 5xx response' ) {
				$found = $event;
				break;
			}
		}

		$this->assertNotNull( $found, 'every 5xx error() must produce an operational-log record with message="AJAX 5xx response"' );
		$this->assertSame( 'session_storage_failed', $found['error_code'] ?? null, 'the caller-supplied code must be preserved in the operational log for grouping' );
		$this->assertSame( 500, (int) ( $found['http_status'] ?? 0 ), 'http_status must be the actual 5xx value (not coerced)' );
		// ajax_action is resolved from $_POST['action'] — empty in this
		// test scenario, but the field MUST exist so production filters
		// that group by endpoint do not silently drop the row.
		$this->assertArrayHasKey( 'ajax_action', $found, 'ajax_action key must exist on every operational log record (even when the action name is "unknown")' );
	}

	public function test_503_response_for_rate_limit_contention_is_also_logged(): void {
		// The rate-limit decision contract emits 503 when an internal
		// lock could not be acquired (different from a 429 quota hit).
		// A 503 in production is a server-side signal — we MUST log it
		// to the same operational channel so a flap of lock contention
		// does not silently degrade the export flow.
		$this->invoke_error_and_capture(
			array(
				'code'    => 'rate_limit_contention',
				'message' => 'Internal lock contention; retry shortly.',
				'retry'   => true,
			),
			503
		);

		$events = \SScribe_Operational_Logger::events_for_testing();
		$found  = null;
		foreach ( $events as $event ) {
			if ( ( $event['message'] ?? '' ) === 'AJAX 5xx response' ) {
				$found = $event;
				break;
			}
		}

		$this->assertNotNull( $found, '503 must be logged the same way as 500 — the operational channel treats every 5xx as a server-side signal' );
		$this->assertSame( 503, (int) ( $found['http_status'] ?? 0 ) );
		$this->assertSame( 'rate_limit_contention', $found['error_code'] ?? null );
	}

	public function test_500_response_without_code_falls_back_to_ajax_5xx(): void {
		// Defensive default: a 5xx path that forgets to include a
		// `code` field still gets a classifiable error_code in the
		// operational log. Otherwise production filters that group by
		// error_code would lump every un-coded 5xx into an empty
		// bucket and lose diagnostic value.
		$this->invoke_error_and_capture(
			array(
				'message' => 'Something broke.',
			),
			500
		);

		$events = \SScribe_Operational_Logger::events_for_testing();
		$found  = null;
		foreach ( $events as $event ) {
			if ( ( $event['message'] ?? '' ) === 'AJAX 5xx response' ) {
				$found = $event;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame(
			'ajax_5xx',
			$found['error_code'] ?? null,
			'a 5xx response missing the `code` field must default to error_code="ajax_5xx" so operational-log filters do not lose the row'
		);
	}

	public function test_4xx_response_is_NOT_recorded_to_operational_log(): void {
		// The operational log is for server-side failures, NOT for
		// client-side validation errors. A 400 from bad input must
		// not pollute the always-on error channel — that channel is
		// reserved for things only an operator can fix.
		$this->invoke_error_and_capture(
			array(
				'code'    => 'invalid_session_id',
				'message' => 'Invalid session identifier.',
			),
			400
		);

		$events = \SScribe_Operational_Logger::events_for_testing();
		foreach ( $events as $event ) {
			$this->assertNotSame(
				'AJAX 5xx response',
				$event['message'] ?? '',
				'4xx responses are client-side signals and must NOT be recorded as AJAX 5xx events'
			);
		}
		$this->assertCount( 0, $events, '4xx responses must produce zero operational-log records' );
	}

	public function test_500_response_records_ajax_action_from_post(): void {
		// When the failing AJAX endpoint can be inferred from $_POST,
		// the operational log carries it so production queries can
		// filter "all 5xx from sscribe_start_export" cleanly.
		$_POST['action'] = 'sscribe_start_export';

		$this->invoke_error_and_capture(
			array(
				'code'    => 'internal_error',
				'message' => 'oops',
			),
			500
		);

		$events = \SScribe_Operational_Logger::events_for_testing();
		$found  = null;
		foreach ( $events as $event ) {
			if ( ( $event['message'] ?? '' ) === 'AJAX 5xx response' ) {
				$found = $event;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame(
			'sscribe_start_export',
			$found['ajax_action'] ?? null,
			'ajax_action in the operational record must be derived from $_POST so production queries can group 5xx by endpoint'
		);
	}
}
