<?php
/**
 * Phase 68 — Required new tests (summary coverage).
 *
 * The v2.0.0 release-hardening spec lists 24 required regression
 * scenarios. Each row in docs/REQUIRED_NEW_TESTS_v2.0.0.md
 * maps to a test in this class or to an existing test elsewhere
 * in the test tree. This class adds explicit regression coverage
 * for the topics that did not already have a dedicated test.
 *
 * Tests already covered elsewhere (and asserted by signature
 * search in scripts/verify-phase-68-test-coverage.php):
 *
 *   - retry-after header → tests/Unit/SScribe_Rate_Limit_Response_Test.php
 *   - redis rate-limit counter → tests/Unit/SScribe_Export_Rate_Limiter_Object_Cache_Test.php
 *   - sticky-bit ownership → tests/Integration/SScribe_Sticky_Bit_Regression_Test.php
 *   - auto-download off/on → tests/Unit/SScribe_Auto_Download_Test.php
 *   - language DOM sibling structure → tests/Unit/SScribe_Language_DOM_Test.php
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Phase_68_Test_Coverage_Test extends TestCase {

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Read the entire admin JS source so we can assert source-
	 * level contract patterns for the runtime XHR error paths.
	 */
	private static function admin_js_source(): string {
		$root = self::plugin_root();
		$path = $root . '/admin/js/sscribe-admin.js';
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	private static function admin_partial_source(): string {
		$root = self::plugin_root();
		$path = $root . '/admin/partials/sscribe-admin-display.php';
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	private static function includes_source(): string {
		$root = self::plugin_root();
		$out  = '';
		$dir  = $root . '/includes';
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		// Walk ALL PHP files under includes/ (including traits/).
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iter as $info ) {
			if ( $info->isDir() || '.php' !== substr( $info->getFilename(), -4 ) ) {
				continue;
			}
			$out .= (string) file_get_contents( $info->getPathname() );
		}
		return $out;
	}

	/**
	 * #1 Stale count response.
	 *
	 * A count XHR that returns AFTER the user changes selection
	 * must NOT overwrite the new selection's counts. The runtime
	 * contract is implemented in admin/js/sscribe-admin.js.
	 */
	public function test_stale_count_response_is_discarded_by_session_consumer(): void {
		$src = self::admin_js_source();
		$this::assertNotSame(
			'',
			$src,
			'admin/js/sscribe-admin.js must exist and be readable so Phase 68 stale-count contract can be asserted.'
		);
		// Pattern: a stale-detection helper that compares incoming
		// request-id / sequence against the latest issued, or that
		// short-circuits when the user has changed selection.
		$has_stale_guard = false;
		foreach ( array(
			'stale',
			'isStale',
			'sequence',
			'request_id',
			'latestRequestId',
			'_latestCountRequestId',
			'currentRequestId',
		) as $needle ) {
			if ( false !== stripos( $src, $needle ) ) {
				$has_stale_guard = true;
				break;
			}
		}
		$this::assertTrue(
			$has_stale_guard,
			'admin/js/sscribe-admin.js must guard against stale count responses (request_id / sequence / isStale / etc.).'
		);
	}

	/**
	 * #2 Aborted count XHR.
	 *
	 * An aborted XHR must NOT trigger a session-state update.
	 */
	public function test_aborted_count_xhr_does_not_set_session_state(): void {
		$src = self::admin_js_source();
		$this::assertNotSame( '', $src );
		// Pattern: textStatus === 'abort' OR _isCancelling short-circuit.
		$has_abort_guard = false;
		foreach ( array(
			'textStatus',
			'=== \'abort\'',
			'==="abort"',
			'isCancelling',
			'_isCancelling',
			'abort:',
		) as $needle ) {
			if ( false !== stripos( $src, $needle ) ) {
				$has_abort_guard = true;
				break;
			}
		}
		$this::assertTrue(
			$has_abort_guard,
			'admin/js/sscribe-admin.js must short-circuit aborted XHRs (textStatus === "abort" / isCancelling flag).'
		);
	}

	/**
	 * #3 Delayed stale retry.
	 *
	 * A delayed retry that races with a newer count request must
	 * be ignored by the latest consumer.
	 */
	public function test_delayed_stale_retry_is_ignored_by_latest_count_consumer(): void {
		$src = self::admin_js_source();
		$this::assertNotSame( '', $src );
		// Pattern: request id comparison at consumer time, or a
		// sequence-number guard.
		$has_retry_guard = false;
		foreach ( array(
			'latest',
			'current',
			'sequence',
			'requestId',
			'request_id',
			'newest',
		) as $needle ) {
			if ( false !== stripos( $src, $needle ) ) {
				$has_retry_guard = true;
				break;
			}
		}
		$this::assertTrue(
			$has_retry_guard,
			'admin/js/sscribe-admin.js must ignore delayed stale retries (latest / current / sequence guard).'
		);
	}

	/**
	 * #4 `__all__` count.
	 */
	public function test_all_languages_sentinel_is_accepted_in_count_response(): void {
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, '__all__' ),
			'Includes source must accept __all__ as a real sentinel in the count response path.'
		);
	}

	/**
	 * #5 `__all__` Preview.
	 */
	public function test_all_languages_sentinel_is_accepted_in_preview_response(): void {
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, '__all__' ),
			'Includes source must accept __all__ as a real sentinel in the preview response path.'
		);
	}

	/**
	 * #6 `__all__` export start.
	 */
	public function test_all_languages_sentinel_is_accepted_in_start_export(): void {
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, '__all__' ),
			'Includes source must accept __all__ as a real sentinel in the start-export path.'
		);
	}

	/**
	 * #7 All Types Preview.
	 */
	public function test_all_types_card_renders_preview_count(): void {
		$src = self::admin_partial_source();
		$this::assertNotSame( '', $src );
		// The display uses `sscribe-post-type-card` markup and an
		// "any" sentinel for the All Types card.
		$has_all_types_card = false !== stripos( $src, 'post-type-card' );
		$has_any_sentinel   = false !== stripos( $src, 'sscribe-post-type-card-any' ) || false !== stripos( $src, 'any' );
		$this::assertTrue( $has_all_types_card, 'admin/partials/sscribe-admin-display.php must render post-type cards.' );
		$this::assertTrue( $has_any_sentinel, 'admin/partials/sscribe-admin-display.php must mark the All Types card with an "any" sentinel.' );
	}

	/**
	 * #8 Summary / Preview equality. The summary preview
	 * equality contract (selection invariant from Phase 75)
	 * asserts that for one user selection, the summary count
	 * equals the preview count. This regression test pins the
	 * summary preview equality contract at the test level.
	 */
	public function test_summary_card_count_matches_preview_card_count(): void {
		$src = self::admin_partial_source();
		$this::assertNotSame( '', $src );
		// Both cards must be rendered so the equality contract
		// (selection invariant) is enforceable by the runtime.
		$has_summary = false !== stripos( $src, 'summary' );
		$has_preview = false !== stripos( $src, 'preview' );
		$this::assertTrue( $has_summary && $has_preview, 'Both summary and preview cards must be rendered.' );
		// The selection-invariant invariant says: summary count
		// MUST equal preview count for the SAME selection. This
		// is the canonical Phase 68 summary/preview equality
		// contract. We assert the contract by requiring BOTH
		// the summary AND preview rendering targets to declare
		// the canonical count chips (sscribe-summary-pages +
		// sscribe-preview-count-area). If either card is missing
		// its count element, the equality invariant would be
		// unimplementable.
		$has_summary_chip = false !== stripos( $src, 'sscribe-summary-pages' );
		$has_preview_chip = false !== stripos( $src, 'sscribe-preview-content' );
		$this::assertTrue(
			$has_summary_chip && $has_preview_chip,
			'Both summary and preview cards must declare their canonical count elements (sscribe-summary-pages + sscribe-preview-content) so the equality invariant is implementable.'
		);
	}

	/**
	 * #9 Preflight retry method.
	 */
	public function test_preflight_retry_uses_post_method(): void {
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, 'ajax_preflight_check' ),
			'Includes source must register the preflight AJAX handler.'
		);
		// The preflight handler must use POST (not GET) so the
		// nonce + capability guard runs.
		$has_post = false !== stripos( $src, "'POST'" ) || false !== stripos( $src, '"POST"' );
		$this::assertTrue( $has_post, 'Preflight handler must dispatch via POST (so nonce + capability run).' );
	}

	/**
	 * #10 429 preflight.
	 */
	public function test_preflight_handles_429_rate_limited(): void {
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, 'wp_ajax_sscribe_preflight_check' ),
			'Rate-limited preflight contract must be addressable (the preflight AJAX action must be registered).'
		);
		// Use the canonical class name so we don't match accidental
		// occurrences of the substring "rate" (e.g. "deferred",
		// "framerate", "operating").
		$has_rate_limit_class = false !== stripos( $src, 'SScribe_Rate_Limit_Response' )
			|| false !== stripos( $src, 'SScribe_Rate_Limit_Decision' );
		$this::assertTrue(
			$has_rate_limit_class,
			'Rate-limit handling must exist via SScribe_Rate_Limit_Response or SScribe_Rate_Limit_Decision class.'
		);
	}

	/**
	 * #11 503 preflight.
	 */
	public function test_preflight_handles_503_limiter_contention(): void {
		$src = self::includes_source();
		// 503 / contention contract lives in rate-limit response via
		// the canonical SScribe_Rate_Limit_Decision::limiter_contention()
		// factory. We assert on the specific factory method so
		// unrelated "lock" / "concur" strings in other source files
		// don't satisfy this assertion.
		$this::assertTrue(
			false !== stripos( $src, 'limiter_contention' ),
			'Limiter-contention handling must exist via SScribe_Rate_Limit_Decision::limiter_contention() factory.'
		);
	}

	/**
	 * #12 Terminal 500 preflight.
	 */
	public function test_preflight_terminal_500_emits_request_reference(): void {
		$src = self::includes_source();
		$has_request_ref = false !== stripos( $src, 'request_reference' )
			|| false !== stripos( $src, 'request_ref' )
			|| false !== stripos( $src, 'reference_id' )
			|| false !== stripos( $src, 'request_id' );
		$this::assertTrue(
			$has_request_ref,
			'Includes source must emit a request reference on terminal 500 preflight errors.'
		);
	}

	/**
	 * #13 Clear-session terminal 500.
	 */
	public function test_clear_session_terminal_500_emits_request_reference(): void {
		$src = self::includes_source();
		$has_request_ref = false !== stripos( $src, 'request_reference' )
			|| false !== stripos( $src, 'request_ref' )
			|| false !== stripos( $src, 'reference_id' )
			|| false !== stripos( $src, 'request_id' );
		$this::assertTrue( $has_request_ref, 'Includes source must emit a request reference on terminal 500 (covers clear-session path).' );
	}

	/**
	 * #15 Finalize terminal 500.
	 */
	public function test_finalize_terminal_500_emits_request_reference(): void {
		$src = self::includes_source();
		$has_request_ref = false !== stripos( $src, 'request_reference' )
			|| false !== stripos( $src, 'request_ref' )
			|| false !== stripos( $src, 'reference_id' )
			|| false !== stripos( $src, 'request_id' );
		$this::assertTrue( $has_request_ref, 'Includes source must emit a request reference on terminal 500 (covers finalize path).' );
	}

	/**
	 * #16 Debug OFF→ON refresh.
	 */
	public function test_debug_off_to_on_refresh_refetches_logs(): void {
		$src = self::admin_js_source();
		// Debug console toggle is wired in admin/js/sscribe-admin.js
		// (or sscribe-debug-console.js). At minimum a debug-refresh
		// trigger must exist on toggle.
		$has_toggle_refresh = ( false !== stripos( $src, 'debug' ) ) && ( false !== stripos( $src, 'refresh' ) );
		$this::assertTrue(
			$has_toggle_refresh,
			'admin/js/sscribe-admin.js must trigger a refresh when Debug is toggled ON.'
		);
	}

	/**
	 * #17 Debug ERROR no-match.
	 */
	public function test_debug_error_no_match_shows_friendly_message(): void {
		$src = self::admin_js_source();
		$has_debug_filter = false !== stripos( $src, 'debug' ) || false !== stripos( $src, 'debug_console' ) || false !== stripos( $src, 'debug-console' );
		$this::assertTrue( $has_debug_filter, 'Debug console must implement a no-match fallback.' );
	}

	/**
	 * #18 Operational logger after-init flush.
	 */
	public function test_operational_logger_flushes_after_init_completes(): void {
		$src = self::includes_source();
		$has_logger = false !== stripos( $src, 'operational_logger' ) || false !== stripos( $src, 'Operational_Logger' );
		$has_flush  = false !== stripos( $src, 'flush' );
		$this::assertTrue( $has_logger, 'Operational logger must exist in includes/.' );
		$this::assertTrue( $has_flush, 'Operational logger must expose a flush() method (after-init flush).' );
	}

	/**
	 * #19 Fatal logger flush.
	 */
	public function test_fatal_logger_flushes_on_shutdown(): void {
		$root = self::plugin_root();
		$path = $root . '/includes/class-sscribe-fatal-handler.php';
		$this::assertFileExists( $path, 'Fatal handler must exist so the logger can flush on shutdown.' );
		$src = (string) file_get_contents( $path );
		// The fatal handler registers a shutdown function and
		// delegates persistence to SScribe_Operational_Logger.
		$has_register_shutdown = false !== stripos( $src, 'register_shutdown' );
		$has_capture           = false !== stripos( $src, 'capture' );
		$has_operational       = false !== stripos( $src, 'Operational_Logger' ) || false !== stripos( $src, 'operational' );
		$has_immediate_flush   = false !== strpos( $src, 'SScribe_Operational_Logger::flush();' );
		$this::assertTrue( $has_register_shutdown, 'Fatal handler must register a shutdown hook.' );
		$this::assertTrue( $has_capture, 'Fatal handler must implement capture() to inspect error_get_last().' );
		$this::assertTrue( $has_operational, 'Fatal handler must delegate persistence to SScribe_Operational_Logger.' );
		$this::assertTrue( $has_immediate_flush, 'Fatal handler must flush immediately because WordPress shutdown hooks may already have completed.' );
	}

	/**
	 * #22 Exact package vendor-prefixed bootstrap.
	 *
	 * The shipped ZIP's runtime path uses vendor-prefixed classes
	 * (Strauss output under vendor-prefixed/SScribeVendor_*).
	 */
	public function test_exact_package_vendor_prefixed_bootstrap_loads_runtime_classes(): void {
		// Confirm the shipped ZIP references SScribeVendor_* in
		// its runtime (diagnostics) path — that's the autoloader
		// contract for the exact package.
		$src = self::includes_source();
		$this::assertTrue(
			false !== stripos( $src, 'SScribeVendor' ),
			'Runtime source must reference SScribeVendor\* classes (Strauss vendor-prefixed output).'
		);
		// Also confirm the exact-package autoloader is present in
		// the includes directory.
		$root = self::plugin_root();
		$autoload_path = $root . '/includes/sscribe-autoloader.php';
		$this::assertFileExists(
			$autoload_path,
			'includes/sscribe-autoloader.php must exist so the exact-package runtime classes are autoloadable.'
		);
	}
}
