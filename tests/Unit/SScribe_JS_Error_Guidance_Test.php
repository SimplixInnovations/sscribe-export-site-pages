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
	 * Regression for the ajax_refresh_nonce add-codes fix: the JS
	 * must (a) know about the `sscribe_refresh_nonce` AJAX action
	 * (so the long-batch 403 retry handshake has a client endpoint)
	 * and (b) translate every code that the server-side handler
	 * emits (`invalid_nonce`, `permission_denied`) into the guidance
	 * table — without this, the JS would surface a generic fallback
	 * for nonce refresh failures after long-running exports.
	 */
	public function test_refresh_nonce_endpoint_codes_are_in_guidance_table(): void {
		$js = $this->admin_js();

		// Client wires to the sscribe_refresh_nonce AJAX action.
		$this->assertStringContainsString(
			"action: 'sscribe_refresh_nonce'",
			$js,
			'JS must wire the nonce refresh endpoint: sscribe_refresh_nonce.'
		);

		// The two codes the server-side ajax_refresh_nonce() actually
		// emits MUST be in the guidance table so the user sees real
		// guidance instead of the generic fallback.
		$refresh_codes = array(
			'invalid_nonce',
			'permission_denied',
		);
		foreach ( $refresh_codes as $code ) {
			$this->assertStringContainsString(
				$code . ':',
				$js,
				'ajax_refresh_nonce() may emit "' . $code . '" — it must be in the JS guidance table.'
			);
		}
	}
}
