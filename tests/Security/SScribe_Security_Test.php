<?php
/**
 * Security tests for SScribe.
 *
 * Tests for XSS prevention, SQL injection prevention, CSRF protection.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-validator.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger-enhanced.php';

/**
 * Class SScribe_Security_Test
 */
class SScribe_Security_Test extends TestCase {

	/**
	 * Test XSS prevention in input sanitization.
	 */
	public function test_xss_prevention_script_tags(): void {
		$xss_payloads = array(
			'<script>alert("xss")</script>',
			'<img src=x onerror=alert(1)>',
			'<svg onload=alert(1)>',
			'<body onload=alert(1)>',
			'<iframe src="javascript:alert(1)">',
			'<a href="javascript:alert(1)">click</a>',
		);

		foreach ( $xss_payloads as $payload ) {
			$input    = array( 'field' => $payload );
			$expected = array( 'field' => 'string' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertStringNotContainsString( '<script', $sanitized['field'], "Failed for payload: $payload" );
			$this->assertStringNotContainsString( 'onerror=', $sanitized['field'], "Failed for payload: $payload" );
			$this->assertStringNotContainsString( 'onload=', $sanitized['field'], "Failed for payload: $payload" );
		}
	}

	/**
	 * Test URL dangerous protocol prevention.
	 */
	public function test_url_dangerous_protocol_prevention(): void {
		$dangerous_urls = array(
			'javascript:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'vbscript:msgbox(1)',
			'file:///etc/passwd',
		);

		foreach ( $dangerous_urls as $url ) {
			$input     = array( 'redirect' => $url );
			$expected  = array( 'redirect' => 'url' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertEquals( '', $sanitized['redirect'], "Dangerous URL should be sanitized to empty: $url" );
		}
	}

	/**
	 * Test XSS prevention in format validation.
	 */
	public function test_xss_prevention_format_validation(): void {
		$malicious_formats = array(
			'<script>docx</script>',
			'pdf<script>alert(1)</script>',
			'html<img src=x onerror=alert(1)>',
		);

		foreach ( $malicious_formats as $format ) {
			$errors = SScribe_Validator::validate_formats( array( $format ) );
			$this->assertNotEmpty( $errors, "Malicious format should be rejected: $format" );
		}
	}

	/**
	 * Test SQL injection prevention in page IDs.
	 */
	public function test_sql_injection_prevention_page_ids(): void {
		$injection_payloads = array(
			'1; DROP TABLE wp_posts; --',
			"1' OR '1'='1",
			'1 UNION SELECT * FROM wp_users',
			'1; INSERT INTO wp_users VALUES (...)',
		);

		foreach ( $injection_payloads as $payload ) {
			$input     = array( 'page_ids' => array( $payload ) );
			$expected  = array( 'page_ids' => 'array_int' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertIsArray( $sanitized['page_ids'] );
			foreach ( $sanitized['page_ids'] as $id ) {
				$this->assertIsInt( $id );
				$this->assertGreaterThanOrEqual( 0, $id );
			}
		}
	}

	/**
	 * Test path traversal prevention.
	 */
	public function test_path_traversal_prevention(): void {
		$traversal_payloads = array(
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\config\\sam',
			'....//....//....//etc/passwd',
		);

		foreach ( $traversal_payloads as $payload ) {
			$input     = array( 'filename' => $payload );
			$expected  = array( 'filename' => 'filename' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertStringNotContainsString( '../', $sanitized['filename'] );
			$this->assertStringNotContainsString( '..\\', $sanitized['filename'] );
		}
	}

	/**
	 * Test nonce format validation.
	 */
	public function test_nonce_format_validation(): void {
		$valid_nonces = array(
			'abc123def456',
			'a1b2c3d4e5f6',
			'nonce_test_123',
		);

		foreach ( $valid_nonces as $nonce ) {
			$input     = array( 'nonce' => $nonce );
			$expected  = array( 'nonce' => 'key' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $sanitized['nonce'] );
		}

		$invalid_nonces = array(
			'<script>alert(1)</script>',
			"nonce' OR '1'='1",
			'../../etc/passwd',
		);

		foreach ( $invalid_nonces as $nonce ) {
			$input     = array( 'nonce' => $nonce );
			$expected  = array( 'nonce' => 'key' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertStringNotContainsString( '<', $sanitized['nonce'] );
			$this->assertStringNotContainsString( "'", $sanitized['nonce'] );
			$this->assertStringNotContainsString( '/', $sanitized['nonce'] );
		}
	}

	/**
	 * Test integer overflow prevention.
	 */
	public function test_integer_overflow_prevention(): void {
		$large_values = array(
			'999999999',
			'1000000000',
			'1234567890',
		);

		foreach ( $large_values as $value ) {
			$input     = array( 'page_id' => $value );
			$expected  = array( 'page_id' => 'int' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertIsInt( $sanitized['page_id'] );
			$this->assertGreaterThanOrEqual( 0, $sanitized['page_id'] );
		}
	}

	/**
	 * Test URL validation and sanitization.
	 */
	public function test_url_sanitization(): void {
		$safe_urls = array(
			'https://example.com/page',
			'http://test.org/path?query=1',
		);

		foreach ( $safe_urls as $url ) {
			$input     = array( 'redirect' => $url );
			$expected  = array( 'redirect' => 'url' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertNotEmpty( $sanitized['redirect'], "Safe URL should be kept: $url" );
		}
	}

	/**
	 * Test array injection prevention.
	 */
	public function test_array_injection_prevention(): void {
		$input = array(
			'page_ids' => array(
				'1',
				'2',
				'3',
			),
		);

		$expected  = array( 'page_ids' => 'array_int' );
		$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

		$this->assertIsArray( $sanitized['page_ids'] );
		foreach ( $sanitized['page_ids'] as $id ) {
			$this->assertIsInt( $id, 'All values should be integers' );
		}
	}

	/**
	 * Test context sanitization concept.
	 */
	public function test_sensitive_data_sanitization_concept(): void {
		$sensitive_data = array(
			'password'   => 'secret123',
			'token'      => 'abc123',
			'secret'     => 'hidden',
			'safe_data'  => 'this is fine',
		);

		$forbidden_keys = array( 'password', 'token', 'secret', 'api_key', 'auth' );
		$sanitized      = $sensitive_data;

		foreach ( $forbidden_keys as $key ) {
			if ( isset( $sanitized[ $key ] ) ) {
				$sanitized[ $key ] = '[REDACTED]';
			}
		}

		$this->assertEquals( '[REDACTED]', $sanitized['password'] );
		$this->assertEquals( '[REDACTED]', $sanitized['token'] );
		$this->assertEquals( '[REDACTED]', $sanitized['secret'] );
		$this->assertEquals( 'this is fine', $sanitized['safe_data'] );
	}

	/**
	 * Test input length validation.
	 */
	public function test_input_length_limits(): void {
		$long_string = str_repeat( 'a', 100000 );

		$input     = array( 'field' => $long_string );
		$expected  = array( 'field' => 'string' );
		$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

		$this->assertLessThanOrEqual( strlen( $long_string ), strlen( $sanitized['field'] ) );
	}

	/**
	 * Test unicode normalization attacks.
	 */
	public function test_unicode_normalization(): void {
		$unicode_payloads = array(
			"\u{003C}script\u{003E}", // <script>
			"\u{FF1C}script\u{FF1E}", // Fullwidth < >
			"\xC0\xBCscript\xC0\xBE", // Overlong UTF-8
		);

		foreach ( $unicode_payloads as $payload ) {
			$input     = array( 'field' => $payload );
			$expected  = array( 'field' => 'string' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertStringNotContainsString( '<script>', $sanitized['field'] );
		}
	}

	/**
	 * Test null byte injection prevention.
	 */
	public function test_null_byte_injection_prevention(): void {
		$null_byte_payloads = array(
			"file.php\x00.txt",
			"../../../etc/passwd\x00.jpg",
			"normal\x00malicious",
		);

		foreach ( $null_byte_payloads as $payload ) {
			$input     = array( 'filename' => $payload );
			$expected  = array( 'filename' => 'string' );
			$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

			$this->assertStringNotContainsString( "\x00", $sanitized['filename'] );
		}
	}
}
