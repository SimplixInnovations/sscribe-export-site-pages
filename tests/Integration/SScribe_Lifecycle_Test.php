<?php
/**
 * Phase 50 — Activation / deactivation / uninstall lifecycle test.
 *
 * WordPress exposes three plugin-lifecycle hooks:
 *
 *   1. Activation — register_activation_hook → SScribe_Activator::activate.
 *   2. Deactivation — register_deactivation_hook → SScribe_Deactivator::deactivate.
 *   3. Uninstall — uninstall.php runs only when WP_UNINSTALL_PLUGIN is defined.
 *
 * The contract:
 *   - The main plugin file wires both hooks to the canonical static
 *     methods.
 *   - SScribe_Activator::activate is public static.
 *   - SScribe_Deactivator::deactivate is public static.
 *   - Activator schedules the three sscribe_cleanup_* cron hooks.
 *   - Deactivator clears the same three cron hooks (symmetry — no orphan tasks).
 *   - Deactivator only cleans transients, never user-owned settings.
 *   - uninstall.php exits unless WP_UNINSTALL_PLUGIN is defined.
 *   - uninstall.php wipes sscribe_session_*, sscribe_page_ids_*, sscribe_log_*.
 *
 * The verifier at scripts/verify-lifecycle.php walks the source tree
 * and produces a manifest the integration test re-checks.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Lifecycle_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-lifecycle.php';
	private const MANIFEST_PATH = 'dist/lifecycle-manifest.json';
	private const MAIN_FILE     = 'sscribe-export-site-pages.php';
	private const ACTIVATOR     = 'includes/class-sscribe-activator.php';
	private const DEACTIVATOR   = 'includes/class-sscribe-deactivator.php';
	private const UNINSTALL     = 'uninstall.php';

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

	public function test_live_tree_passes_lifecycle_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the lifecycle contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Lifecycle contract valid', $output );
	}

	public function test_manifest_records_nineteen_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 19, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
	}

	public function test_activation_hook_targets_canonical_static_method(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::MAIN_FILE );
		$this::assertMatchesRegularExpression(
			'/register_activation_hook\s*\(\s*__FILE__\s*,\s*array\s*\(\s*[\'"]SScribe_Activator[\'"]\s*,\s*[\'"]activate[\'"]\s*\)\s*\)/',
			$source
		);
	}

	public function test_deactivation_hook_targets_canonical_static_method(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::MAIN_FILE );
		$this::assertMatchesRegularExpression(
			'/register_deactivation_hook\s*\(\s*__FILE__\s*,\s*array\s*\(\s*[\'"]SScribe_Deactivator[\'"]\s*,\s*[\'"]deactivate[\'"]\s*\)\s*\)/',
			$source
		);
	}

	public function test_activator_schedules_three_canonical_cron_hooks(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::ACTIVATOR );
		$this::assertStringContainsString( "wp_schedule_event( time(),", $source );
		$this::assertStringContainsString( "'sscribe_cleanup_exports'", $source );
		$this::assertStringContainsString( "'sscribe_cleanup_sessions'", $source );
		$this::assertStringContainsString( "'sscribe_cleanup_audit_trail'", $source );
	}

	public function test_deactivator_clears_three_canonical_cron_hooks(): void {
		// Symmetry: the cron hooks the activator schedules must be
		// cleared on deactivation. A regression that drops one leaves an
		// orphan scheduled task that runs against a deleted plugin.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DEACTIVATOR );
		$this::assertStringContainsString( "wp_clear_scheduled_hook( 'sscribe_cleanup_exports' )", $source );
		$this::assertStringContainsString( "wp_clear_scheduled_hook( 'sscribe_cleanup_sessions' )", $source );
		$this::assertStringContainsString( "wp_clear_scheduled_hook( 'sscribe_cleanup_audit_trail' )", $source );
	}

	public function test_uninstall_php_is_guarded_by_wp_uninstall_plugin_constant(): void {
		// uninstall.php must exit when WP_UNINSTALL_PLUGIN is not
		// defined; otherwise it could be executed by direct request.
		$path   = self::plugin_root() . '/' . self::UNINSTALL;
		$this::assertFileExists( $path );
		$source = (string) file_get_contents( $path );
		$this::assertMatchesRegularExpression(
			'/if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_UNINSTALL_PLUGIN[\'"]\s*\)\s*\)\s*\{[^}]*exit;/',
			$source
		);
	}

	public function test_uninstall_php_wipes_canonical_option_prefixes(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::UNINSTALL );
		$this::assertStringContainsString( "'sscribe_session_'", $source );
		$this::assertStringContainsString( "'sscribe_page_ids_'", $source );
		$this::assertStringContainsString( "'sscribe_log_'", $source );
	}

	public function test_deactivator_does_not_target_user_option_prefixes(): void {
		// Deactivation must only clean transients + cron. A regression
		// that deletes user-owned settings destroys data when the user
		// only deactivates (a common, recoverable action).
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DEACTIVATOR );
		$this::assertDoesNotMatchRegularExpression(
			'/delete_option\s*\(\s*[\'"](sscribe_settings|sscribe_export_settings|sscribe_options)/',
			$source,
			'Deactivator must not delete user-owned plugin settings.'
		);
	}

	public function test_activator_creates_database_tables_and_register_settings(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::ACTIVATOR );
		$this::assertMatchesRegularExpression( '/public\s+static\s+function\s+create_database_tables\s*\(/', $source );
		$this::assertMatchesRegularExpression( '/public\s+static\s+function\s+register_settings\s*\(/', $source );
		$this::assertStringContainsString( 'create_export_directory', $source );
	}
}
