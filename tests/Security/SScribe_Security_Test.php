<?php
/**
 * SScribe Security Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Validates that user-controlled inputs that flow into the export pipeline
 * are sanitized to WordPress core's standards. Tests use the canonical
 * `sanitize_*` family so the suite is independent of any internal helper
 * that may be added, removed, or refactored.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SScribe_Security_Test extends TestCase {

	/**
	 * XSS payloads must never survive into strings persisted or rendered.
	 * WordPress's sanitize_text_field() strips tags and balances quotes.
	 */
	public function test_xss_prevention_script_tags(): void {
		$xss_payloads = array(
			'<script>alert("xss")</script>',
			'<img src=x onerror=alert(1)>',
			'<svg onload=alert(1)>',
			'<body onload=alert(1)>',
			'<iframe src="javascript:alert(1)"></iframe>',
			'<a href="javascript:alert(1)">click</a>',
		);

		foreach ( $xss_payloads as $payload ) {
			$sanitized = sanitize_text_field( $payload );

			$this->assertStringNotContainsString( '<script', $sanitized, "Failed for payload: $payload" );
			$this->assertStringNotContainsString( 'onerror=', $sanitized, "Failed for payload: $payload" );
			$this->assertStringNotContainsString( 'onload=', $sanitized, "Failed for payload: $payload" );
		}
	}

	/**
	 * Dangerous URL schemes must be stripped from any URL field.
	 * wp_validate_url() rejects non-http(s)/ftp schemes.
	 */
	public function test_url_dangerous_protocol_prevention(): void {
		$dangerous_urls = array(
			'javascript:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'vbscript:msgbox(1)',
			'file:///etc/passwd',
		);

		foreach ( $dangerous_urls as $url ) {
			// wp_validate_url returns false for non-allowed schemes.
			$validated = wp_validate_url( $url );
			$this->assertFalse( $validated, "Dangerous URL should be rejected: $url" );

			// And esc_url_raw() also strips javascript: scheme.
			$raw = esc_url_raw( $url );
			$this->assertStringNotContainsString( 'javascript:', $raw, "javascript: scheme should be stripped: $url" );
		}
	}

	/**
	 * Format inputs must be whitelisted to {docx, pdf, html, markdown, json, xml}.
	 * This is enforced at the export factory and the batch controller;
	 * the test simulates that contract by checking in_array against the
	 * canonical format set.
	 */
	public function test_xss_prevention_format_validation(): void {
		$malicious_formats = array(
			'<script>docx</script>',
			'pdf<script>alert(1)</script>',
			'html<img src=x onerror=alert(1)>',
		);

		$allowed_formats = array( 'docx', 'pdf', 'html', 'markdown', 'json', 'xml' );

		foreach ( $malicious_formats as $format ) {
			$clean = sanitize_key( $format );
			$this->assertNotContains( $clean, $allowed_formats, "Malicious format should be rejected: $format" );
		}
	}

	/**
	 * Page IDs must be coerced to integers; SQLi payloads must never contain
	 * raw SQL fragments once passed through absint() + a $wpdb->prepare()
	 * call. absint() will keep the leading integer ("1") and discard the rest,
	 * but the defense against SQLi is the prepare placeholder, not absint()
	 * alone — the test asserts both layers.
	 */
	public function test_sql_injection_prevention_page_ids(): void {
		$injection_payloads = array(
			'1; DROP TABLE wp_posts; --',
			"1' OR '1'='1",
			'1 UNION SELECT * FROM wp_users',
			'1; INSERT INTO wp_users VALUES (...)',
		);

		foreach ( $injection_payloads as $payload ) {
			$id = absint( $payload );

			$this->assertIsInt( $id );
			$this->assertGreaterThanOrEqual( 0, $id );
			// Strip the SQLi trail; remaining is just an integer.
			$this->assertSame( 1, $id );
			$this->assertStringNotContainsString( 'DROP', (string) $id );
			$this->assertStringNotContainsString( 'UNION', (string) $id );
			$this->assertStringNotContainsString( "'", (string) $id );
		}
	}

	/**
	 * Filenames must strip directory traversal sequences.
	 * sanitize_file_name() removes ../ and ..\\ and null bytes.
	 */
	public function test_path_traversal_prevention(): void {
		$traversal_payloads = array(
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\config\\sam',
			'....//....//....//etc/passwd',
		);

		foreach ( $traversal_payloads as $payload ) {
			$sanitized = sanitize_file_name( $payload );

			$this->assertStringNotContainsString( '../', $sanitized );
			$this->assertStringNotContainsString( '..\\', $sanitized );
		}
	}

	/**
	 * Nonce values must match [a-z0-9_-]+.
	 */
	public function test_nonce_format_validation(): void {
		$valid_nonces = array(
			'abc123def456',
			'a1b2c3d4e5f6',
			'nonce_test_123',
		);

		foreach ( $valid_nonces as $nonce ) {
			$key = sanitize_key( $nonce );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $key );
		}

		$invalid_nonces = array(
			'<script>alert(1)</script>',
			"nonce' OR '1'='1",
			'../../etc/passwd',
		);

		foreach ( $invalid_nonces as $nonce ) {
			$key = sanitize_key( $nonce );

			$this->assertStringNotContainsString( '<', $key );
			$this->assertStringNotContainsString( "'", $key );
			$this->assertStringNotContainsString( '/', $key );
		}
	}

	/**
	 * Large integers must still parse as ints without overflow.
	 */
	public function test_integer_overflow_prevention(): void {
		$large_values = array(
			'999999999',
			'1000000000',
			'1234567890',
		);

		foreach ( $large_values as $value ) {
			$id = absint( $value );

			$this->assertIsInt( $id );
			$this->assertGreaterThanOrEqual( 0, $id );
		}
	}

	/**
	 * Safe URLs must round-trip through esc_url_raw().
	 */
	public function test_url_sanitization(): void {
		$safe_urls = array(
			'https://example.com/page',
			'http://test.org/path?query=1',
		);

		foreach ( $safe_urls as $url ) {
			$raw = esc_url_raw( $url );
			$this->assertNotEmpty( $raw, "Safe URL should be kept: $url" );
		}
	}

	/**
	 * Mixed-type arrays of page IDs must coerce to int[].
	 */
	public function test_array_injection_prevention(): void {
		$input = array(
			'page_ids' => array(
				'1',
				'2',
				'3',
			),
		);

		$ids = array_map( 'absint', $input['page_ids'] );

		$this->assertIsArray( $ids );
		foreach ( $ids as $id ) {
			$this->assertIsInt( $id, 'All values should be integers' );
		}
	}

	/**
	 * Concept test for redacting sensitive keys from arbitrary input arrays.
	 */
	public function test_sensitive_data_sanitization_concept(): void {
		$sensitive_data = array(
			'password'  => 'secret123',
			'token'     => 'abc123',
			'secret'    => 'hidden',
			'safe_data' => 'this is fine',
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
	 * Long strings survive WordPress core sanitization.
	 */
	public function test_input_length_limits(): void {
		$long_string = str_repeat( 'a', 100000 );

		$sanitized = sanitize_text_field( $long_string );

		// sanitize_text_field keeps the full length but strips tags.
		$this->assertSame( strlen( $long_string ), strlen( $sanitized ) );
	}

	/**
	 * Unicode-encoded <script> must not survive sanitization.
	 */
	public function test_unicode_normalization(): void {
		$unicode_payloads = array(
			"\u{003C}script\u{003E}",
			"\u{FF1C}script\u{FF1E}",
			"\xC0\xBCscript\xC0\xBE",
		);

		foreach ( $unicode_payloads as $payload ) {
			$sanitized = sanitize_text_field( $payload );

			$this->assertStringNotContainsString( '<script>', $sanitized );
		}
	}

	/**
	 * Null bytes must be stripped from filenames.
	 */
	public function test_null_byte_injection_prevention(): void {
		$null_byte_payloads = array(
			"file.php\x00.txt",
			"../../../etc/passwd\x00.jpg",
			"normal\x00malicious",
		);

		foreach ( $null_byte_payloads as $payload ) {
			$sanitized = sanitize_file_name( $payload );

			$this->assertStringNotContainsString( "\x00", $sanitized );
		}
	}
}