# Changelog

All notable changes to this project will be documented in this file.

## [1.1.1] - 2026-05-18

### Fixed
- JavaScript syntax error: stray quote character in refreshStatusAndLanguageCounts method
- Duplicate JavaScript function definition (copyViaTextarea)
- XSS vulnerability in history empty state (unescaped HTML interpolation)
- Critical PHP fatal error: missing $zip_handler property in SScribe_Admin
- PHP fatal error on null: missing null safety in diagnostics PHPWord version detection
- PCRE1 incompatibility: possessive regex quantifiers in content parser
- Missing version bumps in 8 source files (export-*, test, .pot)

### Changed
- Adaptive metrics now post-type-aware: keys use format + post_type for accurate per-content-type estimates
- Language code regex widened from {2} to {2,3} for WPML 3-letter codes (ZHT, ZHS)
- Deactivator no longer drops database tables on deactivation (tables only removed on uninstall)
- Added defensive load_plugin_textdomain() call for non-.org installations
- One-time migration for adaptive metrics schema change (preserves historical data)

### Removed
- Dead code: unused $sscribe_seo_plugins variable and template reference
- Duplicate CSS .sscribe-step-badge selector
- Stale @deprecated 3.7.6 version reference

### Security
- Content-Security-Policy headers added to plugin admin page
- Path traversal protection strengthened with realpath + str_starts_with validation
- Download TOCTOU race condition guard added
- Multiple security hardening improvements (see changelog above)

## [Unreleased]

### Added
- `filemtime()` cache busting for admin CSS/JS assets in development mode
- `composer i18n:make-pot` script for generating translation files
- PHPDoc comment enforcement (`FunctionComment`, `VariableComment`) via PHPCS

### Changed
- `load_plugin_textdomain()` now fires on `init` hook (WordPress.org compliant)
- Removed blanket `WordPress.Security.EscapeOutput` exclusion on admin partials
- Test expectations updated to match actual asset enqueue count

### Fixed
- `SScribe_Admin_Test::test_enqueue_admin_assets_only_runs_for_plugin_pages` assertion count