<?php
/**
 * SScribe Fatal Handler Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Fatal_Handler_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Fatal_Handler::reset_for_testing();
		$GLOBALS['sscribe_test_doing_ajax'] = false;
		$_POST = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		\SScribe_Fatal_Handler::reset_for_testing();
		unset($GLOBALS['sscribe_test_doing_ajax']);
		$_POST = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	private static function private_method(string $name): \Closure {
		return \Closure::bind(
			static function (...$args) use ($name) {
				return \SScribe_Fatal_Handler::$name(...$args);
			},
			null,
			\SScribe_Fatal_Handler::class
		);
	}

	public function test_sanitize_message_strips_control_characters(): void {
		$fn     = self::private_method('sanitize_message');
		$result = $fn("line one\x00\x01line two");
		$this->assertSame('line oneline two', $result);
	}

	public function test_sanitize_message_redacts_long_base64_strings(): void {
		$fn     = self::private_method('sanitize_message');
		$secret = str_repeat('A', 48);
		$result = $fn('Token: ' . $secret);
		$this->assertStringContainsString('<redacted>', $result);
		$this->assertStringNotContainsString($secret, $result);
	}

	public function test_sanitize_message_redacts_bearer_tokens(): void {
		$fn     = self::private_method('sanitize_message');
		$result = $fn('Auth header: Bearer abcdef123');
		$this->assertStringContainsString('<redacted>', $result);
		$this->assertStringNotContainsString('abcdef123', $result);
	}

	public function test_sanitize_message_redacts_nonce_query_strings(): void {
		$fn     = self::private_method('sanitize_message');
		$result = $fn('GET /foo?nonce=abc123&file=ok');
		$this->assertStringContainsString('nonce=<redacted>', $result);
		$this->assertStringNotContainsString('nonce=abc123', $result);
		$this->assertStringContainsString('file=ok', $result);
	}

	public function test_sanitize_message_bounds_length(): void {
		$fn     = self::private_method('sanitize_message');
		$result = $fn(str_repeat('x', 1000));
		$this->assertLessThanOrEqual(244, strlen($result));
		$this->assertStringEndsWith('...', $result);
	}

	public function test_is_fatal_category_matches_fatal_bitmask(): void {
		$fn = self::private_method('is_fatal_category');
		$this->assertTrue($fn(E_ERROR));
		$this->assertTrue($fn(E_PARSE));
		$this->assertTrue($fn(E_USER_ERROR));
		$this->assertFalse($fn(E_WARNING));
		$this->assertFalse($fn(E_NOTICE));
		$this->assertFalse($fn(E_DEPRECATED));
	}

	public function test_type_label_returns_stable_labels(): void {
		$fn = self::private_method('type_label');
		$this->assertSame('fatal_error', $fn(E_ERROR));
		$this->assertSame('parse_error', $fn(E_PARSE));
		$this->assertSame('user_error', $fn(E_USER_ERROR));
		$this->assertSame('unknown_fatal', $fn(0));
	}

	public function test_is_in_scope_accepts_files_inside_plugin_root(): void {
		$fn = self::private_method('is_in_scope');
		\SScribe_Fatal_Handler::boot('C:/fake/root');
		$this->assertTrue($fn('C:/fake/root/includes/foo.php'));
		$this->assertFalse($fn('C:/other-plugin/foo.php'));
		$this->assertFalse($fn(''));
	}

	public function test_external_file_is_in_scope_for_sscribe_ajax_action(): void {
		$fn = self::private_method('is_in_scope');
		\SScribe_Fatal_Handler::boot('/plugin/sscribe');
		$GLOBALS['sscribe_test_doing_ajax'] = true;
		$_REQUEST['action'] = 'sscribe_process_batch';

		$this->assertTrue($fn('/plugin/other-plugin/fatal.php'));
	}

	public function test_external_file_is_not_in_scope_for_unrelated_ajax_action(): void {
		$fn = self::private_method('is_in_scope');
		\SScribe_Fatal_Handler::boot('/plugin/sscribe');
		$GLOBALS['sscribe_test_doing_ajax'] = true;
		$_REQUEST['action'] = 'other_plugin_action';

		$this->assertFalse($fn('/plugin/other-plugin/fatal.php'));
	}

	public function test_external_file_is_not_in_scope_for_missing_or_non_scalar_ajax_action(): void {
		$fn = self::private_method('is_in_scope');
		\SScribe_Fatal_Handler::boot('/plugin/sscribe');
		$GLOBALS['sscribe_test_doing_ajax'] = true;

		$this->assertFalse($fn('/plugin/other-plugin/fatal.php'));
		$_REQUEST['action'] = array('sscribe_process_batch');
		$this->assertFalse($fn('/plugin/other-plugin/fatal.php'));
	}

	public function test_boot_is_idempotent(): void {
		\SScribe_Fatal_Handler::boot('/first');
		\SScribe_Fatal_Handler::boot('/second');
		$fn = self::private_method('is_in_scope');
		$this->assertTrue($fn('/first/includes/foo.php'));
		$this->assertFalse($fn('/second/includes/foo.php'));
	}
}
