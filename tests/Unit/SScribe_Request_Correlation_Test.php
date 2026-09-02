<?php
/**
 * Phase 19 regression: every SScribe AJAX endpoint must echo a
 * correlation request_id in its JSON body so a single user-perceived
 * action can be traced across the client, the server, and the
 * operational log.
 *
 * Why this test exists:
 *
 *   SScribe_Request_Id generates a per-request id in the form
 *   `ssr_<16 hex>`. The id is stamped into:
 *
 *     - HTTP response body (the `request_id` key on every JSON payload)
 *     - HTTP response header (X-SScribe-Request-Id)
 *     - Every operational-log record (request_id field)
 *
 *   `SScribe_AJAX_Guard::success()` and `::error()` are the single
 *   entry points that perform this stamping. Before Phase 19, eight
 *   admin-debug AJAX handlers called `wp_send_json_success()` directly,
 *   bypassing the guard. Their responses carried no `request_id`, so
 *   when an operator needed to correlate a debug-tab action with a
 *   server-side log line, the trail went cold.
 *
 *   The fix routes all eight handlers through `SScribe_AJAX_Guard::success()`.
 *   This test pins two guarantees:
 *
 *     1. The guard helpers stamp the request_id correctly (unit test).
 *     2. No admin-debug handler calls `wp_send_json_success()` directly
 *        any more (static source check).
 *
 *   Together these prevent re-introducing the gap. If a future change
 *   reverts one of the eight handlers, the static check fails. If the
 *   guard helpers lose their stamping logic, the unit test fails.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Request_Correlation_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Request_Id::reset();
		// Drop any leftover X-SScribe-Request-Id from a prior test.
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] );
		unset( $_SERVER['X_SSCRIBE_REQUEST_ID'] );
	}

	protected function tearDown(): void {
		\SScribe_Request_Id::reset();
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] );
		unset( $_SERVER['X_SSCRIBE_REQUEST_ID'] );
		parent::tearDown();
	}

	/**
	 * Invoke SScribe_AJAX_Guard::success() inside an output buffer
	 * and return the decoded JSON payload. The bootstrap's
	 * wp_send_json_success echoes + throws, so this is the only way
	 * to observe the response.
	 *
	 * @param mixed $data
	 * @return array<string,mixed>
	 */
	private function invoke_success_and_capture( mixed $data ): array {
		ob_start();
		try {
			\SScribe_AJAX_Guard::success( $data );
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_success stub throws after echoing.
		}
		$raw = (string) ob_get_clean();
		$decoded = json_decode( $raw, true );
		$this->assertIsArray(
			$decoded,
			'SScribe_AJAX_Guard::success() must echo a JSON payload (got: ' . $raw . ')'
		);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/**
	 * Same pattern for the error path.
	 *
	 * @param mixed  $data
	 * @param int|null $status_code
	 * @return array<string,mixed>
	 */
	private function invoke_error_and_capture( mixed $data, ?int $status_code = 500 ): array {
		ob_start();
		try {
			\SScribe_AJAX_Guard::error( $data, $status_code );
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_error stub throws after echoing.
		}
		$raw = (string) ob_get_clean();
		$decoded = json_decode( $raw, true );
		$this->assertIsArray(
			$decoded,
			'SScribe_AJAX_Guard::error() must echo a JSON payload (got: ' . $raw . ')'
		);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	public function test_success_helper_stamps_request_id_into_response_payload(): void {
		// Pass a small array payload. The response must include the
		// `request_id` key with a value matching the documented pattern
		// `ssr_<16 hex>` — no exceptions, no fallback values.
		$payload = $this->invoke_success_and_capture( array( 'entries' => array( 'a', 'b' ) ) );

		$this->assertArrayHasKey( 'data', $payload, 'response envelope must contain data' );
		/** @var array<string,mixed> $data */
		$data = $payload['data'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'request_id', $data, 'Phase 19: success() payload must carry a request_id for cross-tier correlation' );
		$this->assertMatchesRegularExpression(
			'/^ssr_[0-9a-f]{16}$/',
			(string) ( $data['request_id'] ?? '' ),
			'request_id must match the canonical ssr_<16 hex> format'
		);
		$this->assertSame( array( 'a', 'b' ), $data['entries'], 'caller-supplied fields must survive unchanged' );
	}

	public function test_success_helper_preserves_caller_supplied_request_id(): void {
		// When the client supplies a valid X-SScribe-Request-Id header
		// the server echoes that exact id back so the user-perceived
		// operation can be correlated across browser + server + log.
		$supplied = 'ssr_abcdef0123456789';
		$_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] = $supplied;

		$payload = $this->invoke_success_and_capture( array( 'x' => 1 ) );
		$this->assertIsArray( $payload['data'] ?? null );
		/** @var array<string,mixed> $data */
		$data = $payload['data'];
		$this->assertSame( $supplied, $data['request_id'] ?? null, 'success() must echo the client-supplied request_id when valid' );
	}

	public function test_success_helper_generates_fresh_request_id_when_none_supplied(): void {
		// No client header -> server generates a fresh id. Two calls
		// in the same request MUST share the same id (cached), so the
		// two responses can be correlated with each other AND with the
		// operational log records emitted between them.
		$first  = $this->invoke_success_and_capture( array( 'n' => 1 ) );
		$second = $this->invoke_success_and_capture( array( 'n' => 2 ) );

		/** @var array<string,mixed> $first_data */
		$first_data = $first['data'];
		/** @var array<string,mixed> $second_data */
		$second_data = $second['data'];

		$this->assertMatchesRegularExpression(
			'/^ssr_[0-9a-f]{16}$/',
			(string) ( $first_data['request_id'] ?? '' )
		);
		$this->assertSame(
			$first_data['request_id'] ?? null,
			$second_data['request_id'] ?? null,
			'a single PHP request must reuse the same request_id across multiple success() calls'
		);
	}

	public function test_error_helper_stamps_request_id_into_response_payload(): void {
		// The error path is equally important for correlation — a 5xx
		// AJAX response that lacks request_id leaves the operator with
		// no way to find the matching operational-log record.
		$payload = $this->invoke_error_and_capture(
			array(
				'code'    => 'ajax_5xx',
				'message' => 'Something went wrong.',
			),
			500
		);

		/** @var array<string,mixed> $data */
		$data = $payload['data'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'request_id', $data, 'Phase 19: error() payload must carry a request_id' );
		$this->assertMatchesRegularExpression(
			'/^ssr_[0-9a-f]{16}$/',
			(string) ( $data['request_id'] ?? '' )
		);
		$this->assertSame( 'ajax_5xx', $data['code'] ?? null, 'error payload must preserve caller-supplied fields' );
	}

	public function test_admin_debug_source_no_longer_calls_wp_send_json_success_directly(): void {
		// The regression lock for Phase 19. The eight admin-debug AJAX
		// handlers used to call wp_send_json_success() directly,
		// bypassing the guard and dropping the request_id. If any
		// future change reintroduces the bypass, this test fails.
		$path = SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin-debug.php';
		$this->assertFileExists( $path );
		$source = (string) file_get_contents( $path );

		// Strip line-comments and block-comments so a docblock mention
		// of the function name (if any) does not trigger a false
		// positive. We only care about code.
		$code = preg_replace( '#/\*.*?\*/#s', '', $source ) ?? $source;
		$code = preg_replace( '#//[^\n]*#', '', $code ) ?? $code;
		// Also strip PHPDoc /** ... */ blocks that survive the strip above.
		$code = preg_replace( '#/\*\*.*?\*/#s', '', $code ) ?? $code;

		$this->assertDoesNotMatchRegularExpression(
			'/\bwp_send_json_success\s*\(/',
			$code,
			'Phase 19: admin-debug AJAX handlers must call SScribe_AJAX_Guard::success() instead of wp_send_json_success() so every response carries request_id. Bypassing the guard drops the correlation id.'
		);
	}

	public function test_admin_debug_every_registered_action_has_a_corresponding_handler(): void {
		// The eight admin-debug add_action() calls each wire to a
		// public ajax_debug_* method. If a new action is registered
		// without a handler, the AJAX endpoint returns 0 silently and
		// the JS layer sees an unhandled error — and the handler would
		// never have a chance to stamp request_id. This catches that
		// mismatch as part of the correlation guarantee.
		//
		// The action-to-handler mapping is intentionally not derived
		// from the action name (e.g. sscribe_debug_get_files maps to
		// ajax_debug_get_rotated_log_files). We parse the add_action()
		// lines themselves so the test reflects actual wiring, not
		// an assumed convention.
		$path = SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin-debug.php';
		$source = (string) file_get_contents( $path );

		preg_match_all(
			"/add_action\(\s*'wp_ajax_(sscribe_debug_[a-z0-9_]+)'\s*,\s*array\(\s*\\\$this\s*,\s*'(ajax_[a-zA-Z0-9_]+)'\s*\)\s*\)/",
			$source,
			$matches,
			PREG_SET_ORDER
		);
		$this->assertNotEmpty( $matches, 'sanity: at least one admin-debug action must be registered' );

		$debug_class = new \ReflectionClass( \SScribe_Admin_Debug::class );
		$seen_actions = array();
		foreach ( $matches as $pair ) {
			$action = $pair[1];
			$method = $pair[2];
			$this->assertNotContains(
				$action,
				$seen_actions,
				"admin-debug action {$action} registered twice"
			);
			$seen_actions[] = $action;
			$this->assertTrue(
				$debug_class->hasMethod( $method ),
				"admin-debug action {$action} is registered but SScribe_Admin_Debug::{$method}() is missing — endpoint would silently return 0 and bypass request_id stamping"
			);
		}

		// Eight actions, eight handlers — match the inventory documented
		// in admin/class-sscribe-admin-debug.php (register_hooks()).
		$this->assertSame(
			8,
			count( $matches ),
			'admin-debug action count drifted from the documented inventory of 8'
		);
	}
}
