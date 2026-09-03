<?php
/**
 * Phase 49 — AJAX security inventory integration test.
 *
 * Every AJAX action the plugin exposes via admin-ajax.php must be
 * registration-secured: either routed through SScribe_Loader's
 * `add_guarded_ajax_action()` (which wraps the callback with nonce +
 * capability checks via SScribe_AJAX_Guard::with_guard) OR wrapped in
 * a centralized `verify_request_authorization()` that runs
 * check_ajax_referer + current_user_can.
 *
 * Any `wp_ajax_nopriv_sscribe_*` registration is forbidden — every
 * export endpoint requires authentication.
 *
 * The verifier at scripts/verify-ajax-security.php produces a
 * manifest the integration test re-checks, so the gate is locked
 * against future regressions without a developer manually running
 * the verifier.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Ajax_Security_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-ajax-security.php';
	private const MANIFEST_PATH = 'dist/ajax-security-manifest.json';
	private const LOADER_PATH   = 'includes/class-sscribe-loader.php';
	private const GUARD_PATH    = 'includes/class-sscribe-ajax-guard.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_tree_passes_ajax_security_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the AJAX security inventory contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'AJAX security inventory contract valid', $output );
	}

	public function test_manifest_records_no_nopriv_registrations(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertSame( 0, $payload['nopriv_count'], 'wp_ajax_nopriv_sscribe_* must never be registered.' );
		$this::assertTrue( $payload['canonical_nonce'], 'Canonical nonce action sscribe_export_nonce must be referenced.' );
	}

	public function test_manifest_inventory_covers_all_canonical_actions(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$actions = array_column( $payload['rows'], 'action' );
		sort( $actions );

		$expected_canonical = array(
			'sscribe_cancel_export',
			'sscribe_check_active_session',
			'sscribe_clear_session',
			'sscribe_delete_export',
			'sscribe_download',
			'sscribe_finalize_export',
			'sscribe_get_all_status_counts',
			'sscribe_get_export_log',
			'sscribe_get_export_preview',
			'sscribe_get_recent_exports',
			'sscribe_get_status_counts',
			'sscribe_get_support_info',
			'sscribe_preflight_check',
			'sscribe_process_batch',
			'sscribe_start_export',
		);
		foreach ( $expected_canonical as $expected ) {
			$this::assertContains( $expected, $actions, $expected . ' must be in the AJAX inventory.' );
		}
	}

	public function test_every_action_is_guarded_or_locally_authorized(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		foreach ( $payload['rows'] as $row ) {
			$this::assertTrue(
				$row['guarded_loader'] || $row['local_authz'],
				$row['action'] . ' must be guarded via add_guarded_ajax_action() or verify_request_authorization().'
			);
		}
	}

	public function test_loader_exposes_add_guarded_ajax_action(): void {
		// Source-level guard: the loader method that wraps every AJAX
		// callback with nonce + capability checks must remain public.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::LOADER_PATH );
		$this::assertMatchesRegularExpression(
			'/public\s+function\s+add_guarded_ajax_action\s*\(/',
			$source
		);
	}

	public function test_ajax_guard_enforces_nonce_and_capability_in_with_guard(): void {
		// with_guard() is the centralised wrapper that the loader's
		// add_guarded_ajax_action() pipes every guarded action through.
		// A regression that drops the nonce or capability check from
		// with_guard() silently exposes every guarded action.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::GUARD_PATH );
		$this::assertMatchesRegularExpression(
			'/public\s+static\s+function\s+with_guard\s*\(/',
			$source
		);
		$this::assertStringContainsString( 'check_ajax_referer', $source );
		$this::assertStringContainsString( 'current_user_can', $source );
	}

	public function test_verifier_pins_canonical_nonce_contract(): void {
		// The verifier must check the canonical nonce action name.
		// A future refactor that silently swaps it for a per-endpoint
		// nonce breaks the documented contract.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( "sscribe_export_nonce", $source );
		$this::assertStringContainsString( "'nopriv_count'", $source );
		$this::assertStringContainsString( "'guarded_loader'", $source );
		$this::assertStringContainsString( "'local_authz'", $source );
	}
}
