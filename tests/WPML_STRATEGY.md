# WPML Test Strategy (v2.0.0)

This document is the **auditable contract** for how the SScribe plugin
verifies multilingual correctness without taking a hard dependency on
WPML or Polylang. It is referenced by Phase 57 and consumed by the
`scripts/verify-wpml-strategy.php` gate.

## Why this document exists

WPML and Polylang are heavyweight third-party plugins that:
- Cannot be installed in CI without an active license
- Are not always present on the WP.org shared-host target audience
- Are versioned independently of WordPress core

A regression that breaks the WPML-inactive path (the default case) is
a P0 incident; a regression that breaks the WPML-active path is a P1.
Both must be tested, but with different strategies.

## Detection contract

The plugin detects WPML via `SScribe_Page_Collector::is_wpml_active()`:

```php
return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
```

This is the canonical WPML constant + class pair. A future maintainer
who swaps in `function_exists('icl_get_languages')` (which only proves
the function is callable, not that WPML is loaded) breaks the contract.

## Polylang and TranslatePress

Neither plugin is a hard dependency. `SScribe_Page_Collector::get_multilingual_provider()`
picks exactly one provider, in this order: WPML, Polylang, TranslatePress.
All language-aware code goes through `is_multilingual_active()`,
`get_languages()` and the private `apply_language_to_query()` /
`restore_language_after_query()` pair, so the WPML path above is unchanged.

- **Polylang** is detected by `POLYLANG_VERSION` plus the public
  `pll_languages_list()` and `pll_get_post_language()` functions.
  Languages come from `pll_languages_list( array( 'fields' => '' ) )`.
  Queries are scoped with the `lang` query var (an empty string means all
  languages). A page's language is the first two letters of its Polylang
  locale, so RTL detection works even when a site uses custom slugs.
- **TranslatePress** is detected by the `TRP_Translate_Press` class.
  Languages are the `publish-languages` locales in the `trp_settings`
  option, exposed as `sanitize_key( $locale )` codes (`fr_FR` -> `fr_fr`).
  TranslatePress keeps every language on one post, so queries are never
  narrowed; instead `get_page_data( $id, $language )` runs the title,
  content, excerpt and breadcrumbs through `trp_translate()` and converts
  URLs with the `url_converter` component. Without a target language, or
  with the default language, the page is exported untouched.

Both paths are covered by `tests/Unit/SScribe_Page_Collector_Multilingual_Test.php`,
which loads minimal stand-ins for each plugin's API in separate processes.

## Test matrix

| Path                              | How tested                                  | Frequency |
|-----------------------------------|---------------------------------------------|-----------|
| WPML inactive (default)           | Unit + integration on every CI run          | Every PR  |
| WPML active (mocked hook listener)| Unit + integration via `apply_filters` mock | Every PR  |
| WPML active (real WPML)           | Manual smoke test in `bin/release-audit.sh` | Pre-tag   |
| Polylang active (mocked API)      | Unit, separate process                      | Every PR  |
| TranslatePress active (mocked API)| Unit, separate process                      | Every PR  |
| Polylang / TranslatePress (real)  | Manual smoke test                           | Pre-tag   |

### Path 1 — WPML inactive

- WP runs with NO WPML plugin active.
- `ICL_SITEPRESS_VERSION` is undefined.
- `class_exists('SitePress')` is false.
- `is_wpml_active()` returns `false`.
- `apply_filters('wpml_current_language', null)` returns `null`.
- The plugin must produce an export in the site's default language
  with **no warnings, no notices, no fatal errors**.

This is the only path we run in CI (it's the path every WP.org user
hits by default).

### Path 2 — WPML active (mocked)

- A PHPUnit test attaches a closure to `wpml_current_language`.
- `is_wpml_active()` is forced to return `true` via a test double.
- `apply_filters('wpml_current_language', null)` returns the closure's
  response.
- The plugin's language-filter path executes without errors.

This catches the "I added an `is_wpml_active()` branch but forgot the
`apply_filters` call" regression.

### Path 3 — WPML active (real)

Pre-tag smoke test (manual + `bin/release-audit.sh` against a local
WP install with WPML). Not auto-tested because:
- WPML is a paid product; CI cannot fetch it.
- The smoke test is part of the release audit (Phase 70).

A failure here blocks the tag; a fix lands before the tag moves.

## What this strategy does NOT cover

- Polylang Pro and TranslatePress add-ons (for example automatic
  translation or SEO slug translation) beyond the public APIs listed above.
- WPML String Translation — the plugin ships its own `.pot`; WPML's
  string translation is a no-op overlay, not a substitute.
- WPML Translation Management — out of scope; the plugin exports
  existing translations, not the workflow to create them.

## Living document

This file is part of the v2.0.0 release hardening audit. Any change
to the WPML detection contract (`is_wpml_active()`, the filter name,
the WPML constant) MUST:
1. Update `tests/WPML_STRATEGY.md`.
2. Update `includes/class-sscribe-page-collector.php` accordingly.
3. Update `scripts/verify-wpml-strategy.php` to assert the new form.
4. Update `tests/Integration/SScribe_WPML_Strategy_Test.php`.

Audited SHA: `a5c093c` — same audit basis as the branch-protection doc.
