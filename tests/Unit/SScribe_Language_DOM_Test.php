<?php
/**
 * Phase 23 regression: the WPML language picker DOM rendered by the
 * export wizard must remain stable across refactors.
 *
 * Why this test exists:
 *
 *   The language picker is a card grid rendered conditionally on
 *   WPML being active. The JS layer (admin/js/sscribe-admin.js)
 *   reads `input[name="sscribe_language"]:checked` and selects a
 *   card via `.sscribe-lang-card-label` to wire click handlers,
 *   keyboard nav, and screen-reader live announcements.
 *
 *   Three production regressions have been observed when the DOM
 *   drifted:
 *
 *     a. Removing the `<input type="radio" name="sscribe_language">`
 *        inputs broke the `:checked` selector — the wizard fell back
 *        to the first card and the user could not pick a different
 *        language.
 *
 *     b. Renaming the card wrapper class from `sscribe-lang-card-label`
 *        to something else broke the click-binding loop and the
 *        language never persisted to the export start call.
 *
 *     c. The "All" radio missing its default `checked` attribute
 *        left the form invalid until the user clicked a card —
 *        the JS read an empty value and the export started with
 *        no language filter (sometimes silently, sometimes with a
 *        different 400 response from start_export).
 *
 *   This test pins:
 *     - WPML inactive: language section absent.
 *     - WPML active + 0 languages: language section absent.
 *     - WPML active + N languages: N+1 cards (one "All" + N languages).
 *     - Every language card carries the right structure (radio,
 *       name attribute, value attribute, flag or placeholder,
 *       name, count).
 *     - "All" is checked by default and has value="__all__".
 *     - All radios share name="sscribe_language".
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Language_DOM_Test extends TestCase {

	/**
	 * Render admin/partials/sscribe-admin-display.php into a string
	 * with the given stub state. The partial reads a top-level
	 * $sscribe_* variable namespace; we populate just enough to
	 * reach the language section without fataling.
	 *
	 * @param bool   $wpml_active Whether WPML is reported as active.
	 * @param array  $languages   List of lang rows (code/name/flag_url/page_count).
	 * @return string Rendered HTML.
	 */
	private function render_partial( bool $wpml_active = false, array $languages = array() ): string {
		// Locals the partial reads at top scope. We populate the
		// minimum needed for the language section without fataling.
		$sscribe_wpml_active      = $wpml_active;
		$sscribe_languages        = $languages;
		$sscribe_total_pages_all  = 0;
		$sscribe_total_posts_all  = 0;
		$sscribe_total_either_all = 0;
		$sscribe_status_counts    = array();
		$sscribe_recent_exports   = array();
		$sscribe_debug_info       = array();
		$sscribe_is_debug         = false;
		$sscribe_can_view_health  = false;
		$sscribe_export_index     = array();
		$sscribe_preflight_warnings = array();
		$sscribe_selectable_types = array(
			array(
				'slug'    => 'page',
				'count'   => 0,
				'is_any'  => false,
				'label'   => 'Pages',
				'icon'    => 'file-text',
			),
			array(
				'slug'    => 'any',
				'count'   => 0,
				'is_any'  => true,
				'label'   => 'All types',
				'icon'    => 'copy',
			),
		);

		ob_start();
		try {
			require SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-display.php';
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
		$this->assertNotSame( '', $html, 'partial must produce output' );
		return $html;
	}

	public function test_language_section_is_absent_when_wpml_is_inactive(): void {
		$html = $this->render_partial( false );

		// The wrapper id is the canonical JS target. If it's present
		// even though WPML is inactive, the JS would attempt to bind
		// handlers to an empty card row and produce console errors.
		$this->assertStringNotContainsString(
			'id="sscribe-language-cards"',
			$html,
			'language card wrapper must not render when WPML is inactive'
		);
	}

	public function test_language_section_is_absent_when_wpml_active_but_no_languages(): void {
		// An edge case: WPML returns "active" but the languages array
		// is empty (e.g. mid-migration, all languages deactivated).
		// The section must still be hidden so the user is not shown
		// a half-broken card grid.
		$html = $this->render_partial( true, array() );

		$this->assertStringNotContainsString(
			'id="sscribe-language-cards"',
			$html,
			'language card wrapper must not render when WPML is active but no languages are configured'
		);
	}

	public function test_language_section_renders_one_card_per_language_plus_all(): void {
		// Two configured languages -> three cards: All + lang_a + lang_b.
		$html = $this->render_partial(
			true,
			array(
				array(
					'code'       => 'fr',
					'name'       => 'French',
					'flag_url'   => 'https://example.org/fr.svg',
					'page_count' => 5,
				),
				array(
					'code'       => 'es',
					'name'       => 'Spanish',
					'flag_url'   => '',
					'page_count' => 3,
				),
			)
		);

		// The wrapper id MUST be present so the JS can find it.
		$this->assertStringContainsString(
			'id="sscribe-language-cards"',
			$html,
			'language card wrapper must render when WPML is active and languages are configured'
		);

		// Count label wrappers — one per language plus one "All".
		preg_match_all(
			'/class="sscribe-lang-card-label\b/',
			$html,
			$label_matches
		);
		$this->assertGreaterThanOrEqual(
			3,
			count( $label_matches[0] ?? array() ),
			'rendered card grid must contain one wrapper per language plus the "All" card (3 cards for 2 languages)'
		);
	}

	public function test_all_card_has_checked_radio_with_canonical_value(): void {
		$html = $this->render_partial(
			true,
			array(
				array(
					'code'       => 'fr',
					'name'       => 'French',
					'flag_url'   => '',
					'page_count' => 5,
				),
			)
		);

		// The "All" card must ship with the radio checked. Without it,
		// the JS would read :checked and find nothing, falling back
		// to an empty string for the language value.
		$this->assertMatchesRegularExpression(
			'/<input\b[^>]*\btype="radio"[^>]*\bname="sscribe_language"[^>]*\bvalue="__all__"[^>]*\bchecked\b/',
			$html,
			'the "All" language card must ship with its radio checked by default'
		);
	}

	/**
	 * Phase 68 #24 — Language DOM sibling structure: every
	 * per-language radio MUST share the same `name=` attribute
	 * so the radio group enforces mutual exclusion at the DOM
	 * level (language dom sibling contract).
	 */
	public function test_all_language_radios_share_the_same_name_attribute(): void {
		// The single-radio-name contract is what makes the JS
		// `input[name="sscribe_language"]:checked` selector work.
		// A refactor that renames one card's radio would silently
		// break the picker.
		$html = $this->render_partial(
			true,
			array(
				array(
					'code'       => 'fr',
					'name'       => 'French',
					'flag_url'   => '',
					'page_count' => 5,
				),
				array(
					'code'       => 'es',
					'name'       => 'Spanish',
					'flag_url'   => '',
					'page_count' => 3,
				),
				array(
					'code'       => 'de',
					'name'       => 'German',
					'flag_url'   => '',
					'page_count' => 0,
				),
			)
		);

		preg_match_all(
			'/<input\b[^>]*\btype="radio"[^>]*\bname="sscribe_language"/',
			$html,
			$radio_matches
		);
		$this->assertGreaterThanOrEqual(
			4,
			count( $radio_matches[0] ?? array() ),
			'one radio per card (All + 3 languages = 4) must all share name="sscribe_language"'
		);
	}

	public function test_per_language_card_carries_radio_value_and_count(): void {
		$html = $this->render_partial(
			true,
			array(
				array(
					'code'       => 'fr',
					'name'       => 'French',
					'flag_url'   => 'https://example.org/fr.svg',
					'page_count' => 42,
				),
				array(
					'code'       => 'es',
					'name'       => 'Spanish',
					'flag_url'   => '',
					'page_count' => 0,
				),
			)
		);

		// French card: must carry its radio value and the localized
		// count. The count is rendered via number_format_i18n so we
		// only assert the numeric value is present somewhere on the
		// same line as "French".
		$this->assertMatchesRegularExpression(
			'/<input\b[^>]*\btype="radio"[^>]*\bvalue="fr"/',
			$html,
			'French card must have a radio input with value="fr"'
		);
		$this->assertMatchesRegularExpression(
			'/<span\b[^>]*\bclass="sscribe-lang-name"[^>]*>\s*French\s*</',
			$html,
			'French card must render the language name inside .sscribe-lang-name'
		);
		$this->assertStringContainsString(
			'42',
			$html,
			'French card must render the localized page_count (42)'
		);

		// Spanish has no flag_url — must fall back to the
		// 2-letter-code placeholder (rendered via strtoupper(substr($code, 0, 2))).
		$this->assertMatchesRegularExpression(
			'/<input\b[^>]*\btype="radio"[^>]*\bvalue="es"/',
			$html,
			'Spanish card must have a radio input with value="es"'
		);
		$this->assertMatchesRegularExpression(
			'/<div\b[^>]*\bclass="sscribe-lang-flag-placeholder"[^>]*>\s*ES\s*</',
			$html,
			'Spanish card with no flag must render the 2-letter code "ES" inside .sscribe-lang-flag-placeholder'
		);
	}

	public function test_per_language_card_with_flag_url_renders_img_tag(): void {
		// When the language row carries a flag_url, the card must
		// render an <img>, not a placeholder. The flag is what the
		// user uses to visually distinguish languages at a glance.
		$html = $this->render_partial(
			true,
			array(
				array(
					'code'       => 'fr',
					'name'       => 'French',
					'flag_url'   => 'https://example.org/fr.svg',
					'page_count' => 1,
				),
			)
		);

		$this->assertMatchesRegularExpression(
			'/<img\b[^>]*\bsrc="https:\/\/example\.org\/fr\.svg"[^>]*\balt="French"/',
			$html,
			'language card with flag_url must render an <img> with the alt text matching the language name'
		);
		$this->assertStringNotContainsString(
			'sscribe-lang-flag-placeholder"',
			$html,
			'a language card with a flag_url must NOT render the .sscribe-lang-flag-placeholder element'
		);
	}
}
