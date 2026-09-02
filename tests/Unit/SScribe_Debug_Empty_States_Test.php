<?php
/**
 * Phase 18 regression: the debug-tab partial must preserve the
 * empty-state DOM so the JS layer can show the right empty message
 * for any of the five documented states without falling back to a
 * blank console.
 *
 * Why this test exists:
 *
 *   The debug console is a heavily stateful UI: log level filter,
 *   session filter, search box, debug toggle, rotated-logs dropdown,
 *   and a banner for stale (debug-off) entries. When the user's
 *   filter set produces zero matches — or the log file is genuinely
 *   empty — the UI must show a meaningful message and (where useful)
 *   a CTA. Before this audit, several of those DOM nodes had been
 *   refactored away and the JS renderLogs() branch ran into a missing
 *   $empty element, which left the console card looking like a
 *   rendering bug.
 *
 * This test renders the partial into a buffer (with a mocked
 * SScribe_Settings / SScribe_Helpers) and asserts the five empty-state
 * DOM elements the JS depends on are preserved with the right initial
 * shape and accessibility hooks:
 *
 *   1. `#sscribe-debug-empty` — primary empty state, starts hidden.
 *   2. The CTA button inside it (`#sscribe-debug-empty-enable`) — used
 *      by the JS to offer "Enable Debug Logging" when debug is off.
 *   3. The stale banner (`#sscribe-debug-stale-banner`) — starts hidden,
 *      JS unhides it when fetching entries with debug_enabled=false.
 *   4. The rotated-body placeholder
 *      (`#sscribe-debug-rotated-body` containing
 *      `.sscribe-debug-rotated-empty`) — preserved across AJAX refreshes.
 *   5. The entry-count element
 *      (`#sscribe-debug-entry-count` with `aria-live="polite"`) — the
 *      polite live region that announces the result of each refresh.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Debug_Empty_States_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Render the debug tab partial into a string with the given option
	 * state. The partial reads SScribe_Settings::get_debug_settings()
	 * which delegates to get_option(), and the bootstrap stub for
	 * get_option() reads from $GLOBALS['sscribe_test_options'].
	 */
	private function render_partial( array $options = array() ): string {
		$GLOBALS['sscribe_test_options'] = $options;

		ob_start();
		try {
			require SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-debug-tab.php';
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
		$this->assertNotSame( '', $html, 'partial must produce output' );
		return $html;
	}

	public function test_primary_empty_state_element_is_preserved_with_initial_hidden_class(): void {
		$html = $this->render_partial();

		// The empty-state node must exist. Specifically:
		//   - id="sscribe-debug-empty"
		//   - class starts with "sscribe-debug-empty "
		//   - includes "sscribe-hidden" so it does not flash on initial paint
		$this->assertStringContainsString(
			'id="sscribe-debug-empty"',
			$html,
			'#sscribe-debug-empty must be present in the partial so the JS renderLogs() empty-state branch can show it'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="sscribe-debug-empty\b[^"]*\bsscribe-hidden\b[^"]*"/',
			$html,
			'#sscribe-debug-empty must carry both sscribe-debug-empty and sscribe-hidden initially so it does not flash before JS sets the right state'
		);
	}

	public function test_primary_empty_state_text_and_cta_are_preserved(): void {
		$html = $this->render_partial();

		// The CTA button must exist inside the empty state. Without it,
		// users with debug disabled would have no inline way to enable
		// debug logging — they'd have to scroll back up to the toggle.
		$this->assertStringContainsString(
			'id="sscribe-debug-empty-enable"',
			$html,
			'#sscribe-debug-empty-enable CTA must be inside the empty state'
		);

		// The default empty-state message must be present and translated.
		$this->assertStringContainsString(
			'No log entries yet. Enable debug mode and run an export to see logs.',
			$html,
			'the default empty-state message must be present so JS can read it as the no_entries fallback text'
		);

		// The CTA button text must be human-readable, not a key or empty.
		$this->assertMatchesRegularExpression(
			'/<button[^>]*id="sscribe-debug-empty-enable"[^>]*>\s*Enable Debug Logging\s*<\/button>/',
			$html,
			'CTA button must render the Enable Debug Logging text'
		);
	}

	public function test_stale_banner_is_hidden_initially(): void {
		// The stale banner is the warning that appears when debug is
		// disabled but entries from a prior run are still visible.
		// On initial server render (debug=off, no entries) it must be
		// hidden, otherwise the user sees a banner saying "showing
		// previous logs" while there are no logs to show.
		$html = $this->render_partial();

		// Match the <div id="sscribe-debug-stale-banner" ...> element
		// regardless of attribute order, then verify the opening tag
		// contains the sscribe-hidden class.
		$matched = preg_match(
			'/<div\b[^>]*\bid="sscribe-debug-stale-banner"[^>]*>/',
			$html,
			$open_tag
		);
		$this->assertSame(
			1,
			$matched,
			'#sscribe-debug-stale-banner element must exist in the DOM'
		);
		$this->assertMatchesRegularExpression(
			'/\bsscribe-hidden\b/',
			(string) $open_tag[0],
			'#sscribe-debug-stale-banner must be sscribe-hidden on initial paint so it does not display with empty content'
		);

		// The banner must have aria-live=polite so screen readers
		// announce when JS unhides it.
		$this->assertStringContainsString(
			'aria-live="polite"',
			(string) $open_tag[0],
			'stale banner must have aria-live=polite so the announcement does not interrupt the user'
		);
	}

	public function test_rotated_empty_state_is_preserved_in_dom(): void {
		$html = $this->render_partial();

		// The rotated-body placeholder must contain a node with class
		// "sscribe-debug-rotated-empty" so the JS renderRotatedFiles()
		// can swap the placeholder with the real file list (or leave
		// the placeholder when no files exist).
		$this->assertStringContainsString(
			'id="sscribe-debug-rotated-body"',
			$html,
			'#sscribe-debug-rotated-body must be present so the JS rotated-files branch can target it'
		);
		$this->assertStringContainsString(
			'sscribe-debug-rotated-empty',
			$html,
			'.sscribe-debug-rotated-empty must be present so the empty rotated state is preserved'
		);

		// The initial "No rotated log files." placeholder message must
		// ship in the DOM; without it the user sees a blank panel until
		// the first AJAX fetch resolves.
		$this->assertStringContainsString(
			'No rotated log files.',
			$html,
			'the rotated-empty placeholder text must be present so the panel is not blank on initial paint'
		);
	}

	public function test_entry_count_live_region_is_preserved(): void {
		$html = $this->render_partial();

		// The entry-count element must have aria-live=polite so screen
		// readers announce the result of each refresh. Without this
		// attribute, screen-reader users have no audible feedback that
		// the console updated.
		$this->assertMatchesRegularExpression(
			'/<span[^>]*\bid="sscribe-debug-entry-count"[^>]*\baria-live="polite"[^>]*>/',
			$html,
			'#sscribe-debug-entry-count must have aria-live=polite so screen readers announce refresh results'
		);

		// The element must have aria-atomic="true" so the whole label
		// is re-announced on every refresh, not just the changed part.
		$this->assertStringContainsString(
			'aria-atomic="true"',
			$html,
			'#sscribe-debug-entry-count must have aria-atomic=true so the full count is re-announced'
		);
	}

	public function test_toggle_reflects_debug_enabled_state_from_settings(): void {
		// When debug is disabled, the toggle must ship unchecked and
		// aria-checked=false. When enabled, checked + aria-checked=true.
		$html_off = $this->render_partial(
			array( \SScribe_Settings::OPT_DEBUG_ENABLED => false )
		);
		// Extract the opening tag of #sscribe-debug-enabled so attribute
		// order is irrelevant. Without this extraction, naive regexes
		// that assume "id before role" break on a refactor.
		$matched_off = preg_match(
			'/<input\b[^>]*\bid="sscribe-debug-enabled"[^>]*>/',
			$html_off,
			$off_tag
		);
		$this->assertSame( 1, $matched_off );
		$this->assertStringContainsString(
			'role="switch"',
			(string) $off_tag[0],
			'debug toggle must render role=switch so it is announced as a switch by screen readers'
		);
		$this->assertStringContainsString(
			'aria-checked="false"',
			(string) $off_tag[0],
			'debug toggle must render aria-checked=false when debug is disabled'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/(?:^|\s)checked(?:\s|=)/',
			(string) $off_tag[0],
			'debug toggle must NOT carry the checked attribute when debug is disabled'
		);

		$html_on = $this->render_partial(
			array( \SScribe_Settings::OPT_DEBUG_ENABLED => true )
		);
		$matched_on = preg_match(
			'/<input\b[^>]*\bid="sscribe-debug-enabled"[^>]*>/',
			$html_on,
			$on_tag
		);
		$this->assertSame( 1, $matched_on );
		$this->assertStringContainsString(
			'aria-checked="true"',
			(string) $on_tag[0],
			'debug toggle must render aria-checked=true when debug is enabled'
		);
		$this->assertMatchesRegularExpression(
			'/(?:^|\s)checked(?:\s|=)/',
			(string) $on_tag[0],
			'debug toggle must render with the checked attribute when debug is enabled'
		);
	}

	public function test_wp_debug_notice_is_preserved_only_when_wp_debug_enabled(): void {
		// The WP_DEBUG banner must only appear when WP_DEBUG is truthy
		// at the partial level. Otherwise production sites would ship a
		// banner warning operators that "logs may contain sensitive
		// information" on a default install — which is misleading.
		$GLOBALS['sscribe_test_options'] = array();

		// Force WP_DEBUG false.
		if ( \defined( 'WP_DEBUG' ) ) {
			// Already defined by the test bootstrap; assertion below
			// only checks the partial's behavior under the current
			// definition.
		}

		$html = $this->render_partial();

		if ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertStringContainsString(
				'sscribe-debug-wp-debug-notice',
				$html,
				'when WP_DEBUG is enabled the WP_DEBUG banner must be present'
			);
		} else {
			$this->assertStringNotContainsString(
				'sscribe-debug-wp-debug-notice',
				$html,
				'when WP_DEBUG is disabled the WP_DEBUG banner must NOT be present'
			);
		}
	}

	public function test_empty_state_dom_is_stable_when_debug_enabled(): void {
		// Switching debug_enabled must NOT remove the empty-state DOM.
		// The JS layer is the one that rewrites the text inside the
		// same element; if the partial omitted the empty-state element
		// when debug is enabled, the JS would have nothing to target
		// when the user later disables debug while empty.
		$html_off = $this->render_partial(
			array( \SScribe_Settings::OPT_DEBUG_ENABLED => false )
		);
		$html_on  = $this->render_partial(
			array( \SScribe_Settings::OPT_DEBUG_ENABLED => true )
		);

		$this->assertStringContainsString( 'id="sscribe-debug-empty"', $html_off );
		$this->assertStringContainsString( 'id="sscribe-debug-empty"', $html_on );
		$this->assertStringContainsString( 'id="sscribe-debug-empty-enable"', $html_off );
		$this->assertStringContainsString( 'id="sscribe-debug-empty-enable"', $html_on );
	}
}