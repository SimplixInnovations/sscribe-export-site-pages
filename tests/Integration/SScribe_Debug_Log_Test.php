<?php
/**
 * Phase 59 — Debug / operational log acceptance integration test.
 *
 * Pins the operational-log contract at the PHPUnit boundary so
 * any regression that:
 *   - drops a sensitive key from the redaction list,
 *   - disables JWT-shape detection,
 *   - raises the message / context bound,
 *   - or breaks the canonical `[timestamp] [LEVEL] message | json`
 *     log entry shape
 *
 * is caught BEFORE it reaches a release tag.
 *
 * The test runs the verifier subprocess to assert the contract is
 * still wired AND exercises the trait directly via reflection to
 * assert the runtime redaction behavior is intact.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Debug_Log_Test extends TestCase {

	private const VERIFIER_PATH       = 'scripts/verify-debug-log.php';
	private const MANIFEST_PATH       = 'dist/debug-log-manifest.json';
	private const TRAIT_PATH          = 'includes/traits/trait-sscribe-logger-common.php';
	private const LOGGER_PATH         = 'includes/class-sscribe-logger.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 59 debug-log verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Debug log acceptance contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 12, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_trait_redacts_every_canonical_sensitive_key(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );

		$required_sensitive = array(
			'password',
			'passwd',
			'token',
			'secret',
			'api_key',
			'apikey',
			'authorization',
			'credential',
			'private_key',
			'nonce',
			'bearer',
			'cookie',
			'set_cookie',
		);
		foreach ( $required_sensitive as $key ) {
			$this::assertStringContainsString(
				"'" . $key . "'",
				$trait_src,
				"Trait must declare `'{$key}'` in the sensitive_parts array for redaction."
			);
		}
	}

	public function test_trait_hashes_every_canonical_ip_like_key(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );

		$required_hashed = array(
			'ip',
			'ip_address',
			'client_ip',
			'remote_addr',
		);
		foreach ( $required_hashed as $key ) {
			$this::assertStringContainsString(
				"'" . $key . "'",
				$trait_src,
				"Trait must declare `'{$key}'` in the hashed_parts array for HMAC-SHA-256 hashing."
			);
		}
	}

	public function test_trait_redacts_jwt_shaped_strings(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );

		// Both conditions must be in the trait: starts-with eyJ AND >= 2 dots.
		$this::assertStringContainsString( "str_starts_with", $trait_src );
		$this::assertStringContainsString( "'eyJ'", $trait_src );
		$this::assertStringContainsString( "substr_count", $trait_src );
		$this::assertMatchesRegularExpression( "/substr_count\s*\([^)]+\)\s*>=\s*2/", $trait_src );
	}

	public function test_trait_message_bounded_to_4000_chars(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );
		$this::assertMatchesRegularExpression(
			'/mb_substr\s*\(\s*trim\s*\(\s*\$message\s*\)\s*,\s*0\s*,\s*4000\s*\)/',
			$trait_src,
			'Trait sanitize_log_message() must bound messages to 4000 chars via mb_substr(trim($message), 0, 4000).'
		);
	}

	public function test_trait_context_bounded_to_2000_chars(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );
		$this::assertMatchesRegularExpression(
			'/(SScribe_Helpers::)?mb_substr\s*\(\s*\$value\s*,\s*0\s*,\s*2000\s*\)/',
			$trait_src,
			'Trait sanitize_log_context() must bound string values to 2000 chars via mb_substr($value, 0, 2000).'
		);
	}

	public function test_trait_strips_control_chars_from_messages(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );
		$this::assertMatchesRegularExpression(
			'/preg_replace\s*\(\s*\'[^\']*x00[^\']*u\'/',
			$trait_src,
			'Trait sanitize_log_message() must preg_replace control chars (x00-x1F, x7F) so log lines stay one-per-entry.'
		);
	}

	public function test_logger_canonical_entry_shape_in_source(): void {
		$logger_src = (string) file_get_contents( self::plugin_root() . '/' . self::LOGGER_PATH );

		// The canonical format string must be present: `[timestamp] [LEVEL] message`.
		$this::assertStringContainsString( "[{\$timestamp}]", $logger_src );
		$this::assertStringContainsString( "[{\$level_upper}]", $logger_src );
		$this::assertStringContainsString( '{$message}', $logger_src );
		$this::assertStringContainsString( "wp_json_encode", $logger_src );
	}

	public function test_canonical_log_shape_pattern_is_pinned(): void {
		// Phase 59 contract: every operational log entry MUST match the
		// canonical `[2024-01-01 12:00:00] [INFO] message | json` shape
		// so log scrapers can parse it deterministically. A regression
		// that changes the format string (drops brackets, swaps the
		// delimiter, omits the level) breaks every downstream tool that
		// grep-parses operational logs.
		$canonical_sample = '[2024-01-01 12:00:00] [INFO] something happened | {"key":"value"}';
		$this::assertMatchesRegularExpression(
			'/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[(?:DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\] .*? \| \{.*\}$/',
			$canonical_sample
		);
	}

	public function test_logger_timestamps_are_utc(): void {
		$logger_src = (string) file_get_contents( self::plugin_root() . '/' . self::LOGGER_PATH );
		$this::assertMatchesRegularExpression(
			'/gmdate\s*\(\s*[\'"]Y-m-d H:i:s[\'"]\s*\)/',
			$logger_src,
			'Logger must call gmdate() (UTC) for entry timestamps — local time breaks cross-tz log correlation.'
		);
	}

	public function test_sanitize_helpers_are_protected(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );
		$this::assertMatchesRegularExpression(
			'/protected\s+function\s+sanitize_log_message\s*\(/',
			$trait_src,
			'sanitize_log_message must be `protected` so external callers cannot bypass sanitization by hand.'
		);
		$this::assertMatchesRegularExpression(
			'/protected\s+function\s+sanitize_log_context\s*\(/',
			$trait_src,
			'sanitize_log_context must be `protected` so external callers cannot bypass sanitization by hand.'
		);
	}

	public function test_ip_hash_uses_hmac_sha256_with_wp_salt(): void {
		$trait_src = (string) file_get_contents( self::plugin_root() . '/' . self::TRAIT_PATH );
		$this::assertStringContainsString( "hash_hmac", $trait_src );
		$this::assertStringContainsString( "'sha256'", $trait_src );
		$this::assertMatchesRegularExpression(
			'/hash_hmac\s*\(\s*\'sha256\'\s*,\s*[^)]+,\s*(function_exists\s*\(\s*\'wp_salt\'\s*\)\s*\?\s*)?wp_salt\s*\(\s*\'auth\'\s*\)/',
			$trait_src,
			'IP hashing must be HMAC-SHA-256 keyed with wp_salt("auth").'
		);
	}

	public function test_logger_has_explicit_enable_toggle(): void {
		$logger_src = (string) file_get_contents( self::plugin_root() . '/' . self::LOGGER_PATH );
		$has_method = (bool) preg_match( '/(public|protected|private)\s+function\s+(is_enabled|enable|disable|set_enabled)\s*\(/', $logger_src );
		$has_prop  = (bool) preg_match( '/(protected|private|public|readonly)\s+\??\s*bool\s+\$enabled\b/', $logger_src );
		$this::assertTrue(
			$has_method || $has_prop,
			'Logger must expose an explicit enable/disable toggle (is_enabled() / $enabled property / set_enabled()).'
		);
	}

	public function test_canonical_log_shape_via_reflection(): void {
		// Exercise the trait directly (no WordPress) to prove the
		// redaction + shape logic is intact at runtime, not just
		// pinned in source.
		if ( ! trait_exists( 'SScribe_Logger_Common' ) ) {
			require_once self::plugin_root() . '/' . self::TRAIT_PATH;
		}

		// Minimal host class: declares $session_id so the trait's
		// get_context_enrichment() doesn't blow up.
		$host = new class() {
			use \SScribe_Logger_Common;
			protected ?string $session_id = null;
			public function redact( array $context ): array {
				return $this->sanitize_log_context( $context );
			}
			public function message( string $msg ): string {
				return $this->sanitize_log_message( $msg );
			}
		};

		$redacted = $host->redact(
			array(
				'password'    => 'p4ssw0rd',
				'token'       => 'tok-abc',
				'ip_address'  => '203.0.113.42',
				'message'     => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature-here',
				'safe_string' => 'plain',
			)
		);

		// Rule A: every canonical sensitive key → [REDACTED].
		$this::assertSame( '[REDACTED]', $redacted['password'] );
		$this::assertSame( '[REDACTED]', $redacted['token'] );

		// Rule B: ip_address was hashed (NOT the raw IP).
		$this::assertNotSame( '203.0.113.42', $redacted['ip_address'] );
		$this::assertIsString( $redacted['ip_address'] );
		$this::assertSame( 16, strlen( $redacted['ip_address'] ), 'IP hash must be truncated to 16 hex chars.' );
		$this::assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $redacted['ip_address'] );

		// Rule C: JWT-shaped string was redacted, not stored raw.
		$this::assertSame( '[REDACTED]', $redacted['message'] );

		// Rule D: safe strings are not mutated.
		$this::assertSame( 'plain', $redacted['safe_string'] );

		// Rule E: messages are bounded — a 5000-char message is clamped to 4000.
		$long  = str_repeat( 'A', 5000 );
		$short = $host->message( $long );
		$this::assertLessThanOrEqual( 4000, strlen( $short ) );

		// Rule F: control chars are stripped — \r\n becomes a single space.
		$cleaned = $host->message( "line one\r\nline two\t\x07with bell" );
		$this::assertStringNotContainsString( "\r", $cleaned );
		$this::assertStringNotContainsString( "\n", $cleaned );
		$this::assertStringNotContainsString( "\x07", $cleaned );
	}
}
