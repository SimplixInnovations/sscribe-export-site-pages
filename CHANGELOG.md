# Changelog

All notable changes to this project will be documented in this file.

## [1.1.2] - 2026-06-25

### Fixed
- PDF export: closed the CSS-keyword font crash path that 1.1.1 left latent. WordPress themes ship base CSS containing `font-family: serif` (Twenty-series themes literally have it); mPDF's `setCSS` walks the `serif_fonts` / `sans_fonts` chains whose first entries are `dejavuserifcondensed` / `dejavusanscondensed` — TTFs the release ZIP does not ship. The 2026-06-24 `DejaVuSansCondensed.ttf` report hit the backup-substitution path; this closes the CSS-resolution and fontdata-entry paths on the same crash class (`DejaVuSerifCondensed.ttf`, `FreeSans.ttf`, `Sun-ExtA.ttf`). The `fonttrans` remap is now unconditional for 22 CSS keywords / named families, and `fontdata` overrides pin `dejavusanscondensed`, `dejavuserifcondensed`, `freesans`, `freemono`, `sun-exta`, `sun-extb` to the shipped DejaVu faces. Characters DejaVu does not cover render as mPDF's internal `?` tofu but the export no longer crashes.

### Added
- New regression test `SScribe_PDF_Exporter_CSS_Keyword_Font_Policy_Test` pins both halves of the new policy (fonttrans remap + fontdata overrides).
- Runtime verification harness `scripts/verify_runtime.php` boots the shipped dist in isolated child processes with WP stubs and runs 17 checks across the activation / class-autoload / exporter / mPDF / preflight surface. Catches activation-time fatals before WP.org submission.
- End-to-end PDF render harness `scripts/verify_e2e.php` exercises mPDF against realistic HTML (serif / sans-serif / monospace, all common named CSS families, CJK, Cyrillic, Greek, Arabic). Renders a real 144 KB PDF; previously crashed on the first `font-family: serif` paragraph.

## [1.1.1] - 2026-05-18

### Added
- `filemtime()` cache busting for admin CSS/JS assets in development mode
- `composer i18n:make-pot` script for generating translation files
- PHPDoc comment enforcement (`FunctionComment`, `VariableComment`) via PHPCS

### Changed
- Adaptive metrics now post-type-aware: keys use format + post_type for accurate per-content-type estimates
- Language code regex widened from {2} to {2,3} for WPML 3-letter codes (ZHT, ZHS)
- Deactivator no longer drops database tables on deactivation (tables only removed on uninstall)
- Added defensive load_plugin_textdomain() call for non-.org installations
- One-time migration for adaptive metrics schema change (preserves historical data)
- `load_plugin_textdomain()` now fires on `init` hook (WordPress.org compliant)
- Removed blanket `WordPress.Security.EscapeOutput` exclusion on admin partials
- Test expectations updated to match actual asset enqueue count

### Fixed
- JavaScript syntax error: stray quote character in refreshStatusAndLanguageCounts method
- Duplicate JavaScript function definition (copyViaTextarea)
- XSS vulnerability in history empty state (unescaped HTML interpolation)
- Critical PHP fatal error: missing $zip_handler property in SScribe_Admin
- PHP fatal error on null: missing null safety in diagnostics PHPWord version detection
- PCRE1 incompatibility: possessive regex quantifiers in content parser
- Missing version bumps in 8 source files (export-*, test, .pot)
- `SScribe_Admin_Test::test_enqueue_admin_assets_only_runs_for_plugin_pages` assertion count

### Removed
- Dead code: unused $sscribe_seo_plugins variable and template reference
- Duplicate CSS .sscribe-step-badge selector
- Stale @deprecated 3.7.6 version reference

### Security
- Content-Security-Policy headers added to plugin admin page
- Path traversal protection strengthened with realpath + str_starts_with validation
- Download TOCTOU race condition guard added
- Multiple security hardening improvements (see changelog above)
