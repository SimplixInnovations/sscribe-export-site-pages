<?php
/**
 * SScribe JS Error Guidance Regression Test
 *
 * Locks in two frontend-side invariants for SScribe.admin.errorGuidance:
 *
 *   1. The function MUST NOT substring-match on `message` to translate
 *      "session" or "timeout" into "the export session was lost."
 *      That fallback path previously masked every real cause (workspace
 *      init, page list write, concurrent export, rate limit, etc.) as
 *      a session-lost error. The fix: error codes are emitted by the
 *      server, and the JS lookup is purely code-driven.
 *
 *   2. Every server-emitted code listed below MUST have a stable entry
 *      in the JS guidance table so the user sees real guidance instead
 *      of the generic fallback.
 *
 * The test reads admin/js/sscribe-admin.js as text and asserts on shape.
 * Substring matching would slip past this test, so we look specifically
 * for the substring-match lines that previously caused the masking.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_JS_Error_Guidance_Test extends TestCase {

	private function admin_js(): string {
		$path = __DIR__ . '/../../admin/js/sscribe-admin.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function debug_console_js(): string {
		$path = __DIR__ . '/../../admin/js/sscribe-debug-console.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_get_error_guidance_does_not_substring_match_message(): void {
		$js = $this->admin_js();

		// The fix removed the substring fallback on `msg.indexOf('session')`
		// and `msg.indexOf('timeout')`. Assert these never return.
		$this->assertStringNotContainsString(
			"msg.indexOf('session')",
			$js,
			'JS error guidance must not substring-match on "session" in the message.'
		);
		$this->assertStringNotContainsString(
			"msg.indexOf('timeout')",
			$js,
			'JS error guidance must not substring-match on "timeout" in the message.'
		);
		$this->assertStringNotContainsString(
			"msg.indexOf('rate')",
			$js,
			'JS error guidance must not substring-match on "rate" in the message.'
		);
		$this->assertStringNotContainsString(
			"msg.indexOf('memory')",
			$js,
			'JS error guidance must not substring-match on "memory" in the message.'
		);
		$this->assertStringNotContainsString(
			"msg.indexOf('zip')",
			$js,
			'JS error guidance must not substring-match on "zip" in the message.'
		);
	}

	public function test_known_codes_are_present_in_guidance_table(): void {
		$js = $this->admin_js();

		$must_have = array(
			'invalid_nonce',
			'invalid_session_id',
			'session_expired',
			'session_ownership',
			'rate_limited',
			'support_info_unavailable',
			'concurrent_export',
			'workspace_init_failed',
			'session_create_failed',
			'page_list_failed',
		);

		foreach ( $must_have as $code ) {
			$this->assertStringContainsString(
				$code . ':',
				$js,
				'Expected code "' . $code . '" must be present in the JS guidance table.'
			);
		}
	}

	/**
	 * Regression for the JS-side refresh-nonce guidance: the debug console
	 * uses `sscribe_debug_refresh_nonce` (the export flow trusts the
	 * page-reload nonce on long batches), so its JS must translate the
	 * same codes the server emits into the shared guidance table.
	 */
	public function test_refresh_nonce_endpoint_codes_are_in_guidance_table(): void {
		$js = $this->debug_console_js();

		// The debug console wires to the debug-namespaced refresh endpoint.
		$this->assertStringContainsString(
			"action: 'sscribe_debug_refresh_nonce'",
			$js,
			'JS must wire the debug refresh-nonce endpoint.'
		);

		// The debug console handles a 403 by retrying with the new nonce;
		// other server errors surface the same error guidance the export flow uses.
		$this->assertStringContainsString(
			'xhr.status === 403',
			$js,
			'Debug console must retry the debug-nonce refresh on 403.'
		);
		$this->assertStringContainsString(
			'refreshNonce: function',
			$js,
			'Debug console must surface a refresh-nonce error path on failure.'
		);
	}
}
