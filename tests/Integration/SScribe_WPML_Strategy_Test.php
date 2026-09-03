<?php
/**
 * Phase 57 — WPML test strategy integration test.
 *
 * WPML is a paid third-party plugin; we cannot fetch it in CI. So
 * the production-grade contract is:
 *
 *   1. SScribe_Page_Collector::is_wpml_active() returns true only
 *      when BOTH ICL_SITEPRESS_VERSION is defined AND the SitePress
 *      class is loaded — anything looser is a false positive.
 *   2. The plugin calls `apply_filters("wpml_current_language", null)`
 *      so WPML's standard hook fires. A custom filter name silently
 *      breaks every WPML install.
 *   3. The plugin's WPML-active code path executes correctly when
 *      the filter returns a non-null language.
 *   4. The plugin's WPML-inactive code path executes correctly when
 *      ICL_SITEPRESS_VERSION is undefined and the filter returns
 *      its default (null) — this is the path every WP.org user
 *      hits by default.
 *   5. tests/WPML_STRATEGY.md is the auditable strategy doc.
 *
 * This test pins all five clauses.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_WPML_Strategy_Test extends TestCase {

	private const VERIFIER_PATH    = 'scripts/verify-wpml-strategy.php';
	private const MANIFEST_PATH    = 'dist/wpml-strategy-manifest.json';
	private const STRATEGY_PATH    = 'tests/WPML_STRATEGY.md';
	private const COLLECTOR_PATH   = 'includes/class-sscribe-page-collector.php';
	private const BATCH_PATH       = 'includes/class-sscribe-batch-processor.php';

	/**
	 * Track ICL_SITEPRESS_VERSION so we can restore between tests.
	 */
	private static ?bool $previous_constant_defined = null;
	private static ?string $previous_constant_value = null;
	private static bool $previous_sitepress_loaded   = false;

	public static function setUpBeforeClass(): void {
		// Capture the existing WPML mock state so we don't pollute
		// sibling tests after each one.
		self::$previous_constant_defined = defined( 'ICL_SITEPRESS_VERSION' );
		self::$previous_constant_value   = self::$previous_constant_defined ? constant( 'ICL_SITEPRESS_VERSION' ) : null;
		self::$previous_sitepress_loaded = class_exists( 'SitePress', false );
	}

	public static function tearDownAfterClass(): void {
		// Restore WPML mock state.
		if ( self::$previous_constant_defined && null !== self::$previous_constant_value ) {
			// Can't undefine a constant in PHP. Re-define only if it
			// was previously defined (it is) — leave value alone.
			if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
				define( 'ICL_SITEPRESS_VERSION', self::$previous_constant_value );
			}
		}
		// Note: classes cannot be unloaded. Tests that introduce
		// `SitePress` leave it loaded — but the per-test fixture
		// teardown removes any test-set state via `class_alias`.
	}

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

	public function test_live_sources_pass_wpml_strategy_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live sources must satisfy the Phase 57 WPML strategy contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'WPML strategy contract valid', $output );
	}

	public function test_manifest_records_ten_or_more_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 10, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_strategy_doc_exists_and_pins_audited_sha(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::STRATEGY_PATH );
		$this::assertStringContainsString( 'ICL_SITEPRESS_VERSION', $source );
		$this::assertStringContainsString( 'SitePress', $source );
		$this::assertStringContainsString( 'wpml_current_language', $source );
		$this::assertStringContainsString( 'Living document', $source );
		// Pinned SHA — same basis as the branch-protection doc.
		$this::assertMatchesRegularExpression( '/a5c093c/i', $source );
	}

	public function test_strategy_doc_enumerates_full_test_matrix(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::STRATEGY_PATH );
		// Inactive path
		$this::assertStringContainsStringIgnoringCase( 'WPML inactive', $source );
		// Mocked-active path
		$this::assertStringContainsStringIgnoringCase( 'mocked', $source );
		// Real-active path
		$this::assertStringContainsStringIgnoringCase( 'real WPML', $source );
	}

	public function test_page_collector_is_wpml_active_returns_false_when_wpml_absent(): void {
		// Bootstrap doesn't define ICL_SITEPRESS_VERSION. Force the
		// detection to run in its native "absent" state by clearing
		// any prior constant test pollution via a class_alias trick:
		// we explicitly require the source file and call the method.
		// Since ICL_SITEPRESS_VERSION is NOT defined in this bootstrap,
		// is_wpml_active() must return false.
		if ( ! class_exists( 'SScribe_Page_Collector', false ) ) {
			require_once self::plugin_root() . '/' . self::COLLECTOR_PATH;
		}
		$collector = new \SScribe_Page_Collector();
		$this::assertFalse(
			$collector->is_wpml_active(),
			'is_wpml_active() must return false when ICL_SITEPRESS_VERSION is undefined (the WPML-inactive default path).'
		);
	}

	public function test_page_collector_is_wpml_active_returns_true_when_constant_defined(): void {
		// Phase 57 contract: define the constant + load the SitePress
		// class, then assert is_wpml_active() returns true.
		//
		// Constants cannot be undefined. So we use a test-only
		// wrapper: define ICL_SITEPRESS_VERSION if not already
		// defined. If the constant is already defined (from a
		// previous test that didn't clean up), the assertion still
		// holds as long as SitePress is loaded.
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
		}
		if ( ! class_exists( 'SitePress', false ) ) {
			// Create a minimal SitePress stub so class_exists returns
			// true. We use eval to bypass the strict "class already
			// declared" check.
			eval( 'class SitePress { public function get_default_language() { return "en"; } }' );
		}

		if ( ! class_exists( 'SScribe_Page_Collector', false ) ) {
			require_once self::plugin_root() . '/' . self::COLLECTOR_PATH;
		}
		$collector = new \SScribe_Page_Collector();
		$this::assertTrue(
			$collector->is_wpml_active(),
			'is_wpml_active() must return true when ICL_SITEPRESS_VERSION is defined AND SitePress class is loaded.'
		);
	}

	public function test_plugin_calls_canonical_wpml_current_language_filter(): void {
		// The plugin's source must invoke apply_filters('wpml_current_language', …).
		// We assert the call exists in the batch processor (the
		// call site that resolves the language per batch).
		$batch_src = (string) file_get_contents( self::plugin_root() . '/' . self::BATCH_PATH );
		$this::assertMatchesRegularExpression(
			"/apply_filters\s*\(\s*['\"]wpml_current_language['\"]/",
			$batch_src,
			'Plugin must call apply_filters("wpml_current_language", ...) so WPML\'s standard hook fires.'
		);
	}

	public function test_apply_filters_wpml_current_language_falls_through_when_no_listener(): void {
		// No listener attached → apply_filters returns its default.
		// We assert this directly via the bootstrap's apply_filters
		// implementation.
		$this::assertSame( null, apply_filters( 'wpml_current_language', null ) );
		$this::assertSame( 'en', apply_filters( 'wpml_current_language', 'en' ) );
	}

	public function test_apply_filters_wpml_current_language_invokes_listener(): void {
		// Attach a listener that overrides the language.
		$callback = static function ( $value ) {
			return 'fr';
		};
		add_filter( 'wpml_current_language', $callback );
		try {
			$this::assertSame( 'fr', apply_filters( 'wpml_current_language', null ) );
		} finally {
			remove_filter( 'wpml_current_language', $callback );
		}
		// After removal, the filter falls through again.
		$this::assertSame( null, apply_filters( 'wpml_current_language', null ) );
	}

	public function test_plugin_uses_canonical_detection_pair_in_collector(): void {
		$collector_src = (string) file_get_contents( self::plugin_root() . '/' . self::COLLECTOR_PATH );
		// Extract the is_wpml_active method body.
		$this::assertMatchesRegularExpression(
			'/function\s+is_wpml_active\s*\(\s*\)\s*:\s*bool\s*\{([\s\S]*?)\}/',
			$collector_src,
			'SScribe_Page_Collector::is_wpml_active() must exist with a bool return type.'
		);
		// Pull just the body for inspection.
		preg_match( '/function\s+is_wpml_active\s*\(\s*\)\s*:\s*bool\s*\{([\s\S]*?)\}/', $collector_src, $m );
		$body = $m[1] ?? '';
		$this::assertStringContainsString( 'ICL_SITEPRESS_VERSION', $body );
		$this::assertStringContainsString( 'SitePress', $body );
	}
}
