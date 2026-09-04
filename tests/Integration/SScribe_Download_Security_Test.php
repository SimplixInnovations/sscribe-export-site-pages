<?php
/**
 * Phase 48 — Download security regression test.
 *
 * The download endpoint is gated by a single-use 32-hex token persisted
 * on the export row. The token is generated when the ZIP is created,
 * URL-embedded for the customer's first click, and atomically validated
 * + rotated on consume. A regression in any of these properties ships a
 * release that either:
 *
 *   - leaks the download URL across users (token not bound to row)
 *   - allows unlimited downloads (token not rotated on consume)
 *   - leaks timing info (non-constant-time compare)
 *   - fails open when the row is missing or empty
 *
 * This test exercises the live code path with planted fixtures:
 *   - rotate_dl_token returns a 32-hex value bound to the row.
 *   - consume_dl_token validates + rotates atomically.
 *   - The same token cannot be consumed twice (single-use).
 *   - Wrong / empty / missing tokens all fail closed.
 *   - normalize_zip_filename strips path traversal.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Download_Security_Test extends TestCase {

	private const ZIP_PATH = 'includes/class-sscribe-zip-handler.php';
	private const BATCH_PATH = 'includes/class-sscribe-batch-file-handler.php';
	private const VERIFIER_PATH = 'scripts/verify-download-security.php';
	private const MANIFEST_PATH = 'dist/download-security-manifest.json';
	private const EXPORT_FIXTURE = 'sscribe-fixture-export-2026-09-03.zip';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public static function setUpBeforeClass(): void {
		// The download-security manifest is a derived artifact of the
		// verifier script. A sibling test or `composer release` may have
		// deleted it between runs, and `test_manifest_records_all_ten_rules`
		// asserts the manifest exists on disk. Regenerating it here makes
		// the suite order-independent instead of relying on whichever
		// sibling test happened to call the verifier last.
		$root        = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn download-security verifier in setUpBeforeClass.' );
		}
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		if ( 0 !== $code ) {
			throw new \RuntimeException( 'Download-security verifier must exit 0 to seed manifest. Output: ' . $stdout . $stderr );
		}
	}

	private function load_zip_handler(): void {
		require_once self::plugin_root() . '/includes/class-sscribe-logger.php';
		require_once self::plugin_root() . '/includes/class-sscribe-export-lock-manager.php';
		require_once self::plugin_root() . '/includes/class-sscribe-zip-handler.php';
	}

	private function seed_export_row( string $filename, array $extra = array() ): string {
		$option_name         = 'sscribe_export_row_' . md5( $filename );
		$GLOBALS['sscribe_test_options'][ $option_name ] = array_merge(
			array(
				'filename'   => $filename,
				'user_id'    => 1,
				'session_id' => 'sess-test-001',
				'dl_token'   => '',
			),
			$extra
		);
		return $option_name;
	}

	public function test_rotate_dl_token_returns_32_hex_and_persists(): void {
		$this->load_zip_handler();

		$option_name = $this->seed_export_row( self::EXPORT_FIXTURE );
		$zip         = new \SScribe_Zip_Handler();

		$token = $zip->rotate_dl_token( self::EXPORT_FIXTURE );

		$this::assertIsString( $token );
		$this::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token, 'rotate_dl_token must return 32 lowercase hex chars' );
		$this::assertArrayHasKey( $option_name, $GLOBALS['sscribe_test_options'] );
		$this::assertSame( $token, $GLOBALS['sscribe_test_options'][ $option_name ]['dl_token'] );
	}

	public function test_consume_dl_token_accepts_correct_token_and_rotates(): void {
		$this->load_zip_handler();
		$option_name = $this->seed_export_row( self::EXPORT_FIXTURE );
		$zip         = new \SScribe_Zip_Handler();
		$original    = $zip->rotate_dl_token( self::EXPORT_FIXTURE );

		$this::assertTrue(
			$zip->consume_dl_token( self::EXPORT_FIXTURE, $original ),
			'consume_dl_token must accept the freshly rotated token.'
		);

		// The stored token must have rotated to a fresh value.
		$rotated = $GLOBALS['sscribe_test_options'][ $option_name ]['dl_token'];
		$this::assertIsString( $rotated );
		$this::assertNotSame( $original, $rotated, 'Token must rotate after a successful consume.' );
		$this::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $rotated );
	}

	public function test_consume_dl_token_rejects_already_consumed_token(): void {
		// Single-use: the SAME token presented twice must fail the second
		// time. This is the core anti-replay property.
		$this->load_zip_handler();
		$this->seed_export_row( self::EXPORT_FIXTURE );
		$zip      = new \SScribe_Zip_Handler();
		$original = $zip->rotate_dl_token( self::EXPORT_FIXTURE );

		$this::assertTrue( $zip->consume_dl_token( self::EXPORT_FIXTURE, $original ) );
		$this::assertFalse(
			$zip->consume_dl_token( self::EXPORT_FIXTURE, $original ),
			'After consume, the same token must be rejected.'
		);
	}

	public function test_consume_dl_token_rejects_wrong_token(): void {
		$this->load_zip_handler();
		$this->seed_export_row( self::EXPORT_FIXTURE );
		$zip = new \SScribe_Zip_Handler();
		$zip->rotate_dl_token( self::EXPORT_FIXTURE );

		$this::assertFalse(
			$zip->consume_dl_token( self::EXPORT_FIXTURE, '0123456789abcdef0123456789abcdef' )
		);
		$this::assertFalse(
			$zip->consume_dl_token( self::EXPORT_FIXTURE, 'not-a-token' )
		);
	}

	public function test_consume_dl_token_rejects_empty_filename_and_token(): void {
		// Fail closed: the validator must reject empty filename, empty
		// presented token, or both.
		$this->load_zip_handler();
		$zip = new \SScribe_Zip_Handler();
		$this::assertFalse( $zip->consume_dl_token( '', '0123456789abcdef0123456789abcdef' ) );
		$this::assertFalse( $zip->consume_dl_token( self::EXPORT_FIXTURE, '' ) );
		$this::assertFalse( $zip->consume_dl_token( '', '' ) );
	}

	public function test_consume_dl_token_rejects_missing_row(): void {
		// If the row never existed (or has been cleaned up), a presented
		// token must not authorize the download.
		$this->load_zip_handler();
		$zip = new \SScribe_Zip_Handler();
		$this::assertFalse(
			$zip->consume_dl_token( 'sscribe-nonexistent-' . wp_generate_password( 16, false ) . '.zip', '0123456789abcdef0123456789abcdef' )
		);
	}

	public function test_rotate_dl_token_returns_empty_for_unknown_row(): void {
		$this->load_zip_handler();
		$zip = new \SScribe_Zip_Handler();
		$this::assertSame(
			'',
			$zip->rotate_dl_token( 'sscribe-not-seeded-' . wp_generate_password( 16, false ) . '.zip' )
		);
	}

	public function test_zip_handler_normalizes_path_traversal_in_filename(): void {
		// The filename flows into an option key derived from md5(). Any
		// directory traversal in the presented filename must not bypass
		// the per-row storage scope.
		$this->load_zip_handler();
		$zip = new \SScribe_Zip_Handler();
		$this::assertFalse( $zip->consume_dl_token( '../etc/passwd', '0123456789abcdef0123456789abcdef' ) );
		$this::assertSame( '', $zip->rotate_dl_token( '/etc/passwd' ) );
	}

	public function test_live_tree_passes_download_security_verifier(): void {
		$root        = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this::assertSame(
			0,
			$code,
			'Live tree must satisfy the download-security contract. Output:' . "\n" . $stdout . $stderr
		);
		$this::assertStringContainsString( 'Download security contract valid', $stdout );
	}

	public function test_manifest_records_all_ten_rules(): void {
		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$this::assertFileExists( $abs );
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertSame( 10, $payload['rule_count'] );
		$this::assertSame( 10, $payload['passed_count'] );

		$rules = array_column( $payload['matrix'], 'rule' );
		sort( $rules );
		$expected = array(
			'batch_handler_logs_token_rejection',
			'batch_handler_validates_token',
			'consume_dl_token_acquires_lock',
			'consume_dl_token_fails_closed_on_empty_inputs',
			'consume_dl_token_rotates_on_success',
			'consume_dl_token_uses_hash_equals',
			'download_url_includes_nonce_and_token',
			'generate_dl_token_uses_random_bytes',
			'lock_manager_has_acquire_and_release',
			'token_format_is_32_hex',
		);
		$this::assertSame( $expected, $rules );
	}

	public function test_zip_handler_declares_all_required_security_methods(): void {
		// Source-level guard: a refactor that drops a critical method
		// is caught here before the verifier would re-pass against a
		// half-shipped API.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::ZIP_PATH );
		$this::assertMatchesRegularExpression( '/public\s+function\s+consume_dl_token\s*\(/', $source );
		$this::assertMatchesRegularExpression( '/public\s+function\s+rotate_dl_token\s*\(/', $source );
		$this::assertMatchesRegularExpression( '/public\s+function\s+get_ajax_download_url\s*\(/', $source );
		// normalize_zip_filename is private; the runtime path-traversal
		// test above exercises it indirectly. We pin the symbol exists
		// (private or public) so a future refactor that renames it
		// catches here even if visibility changes.
		$this::assertStringContainsString( 'normalize_zip_filename(', $source );
	}
}
