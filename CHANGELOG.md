# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

## [3.35.0] - 2026-05-01

* Security: Health check AJAX endpoint now enforces capability check for authenticated diagnostics
* Reliability: Batch processor caps in-memory error arrays at 50 entries to prevent OOM
* Reliability: Image processor replaces `@getimagesize` suppression with structured logging
* Reliability: Container circular dependency exceptions include offending service key
* UX: Dark mode card contrast improved
* Maintenance: Deactivation now cleans all plugin options, transients, tables, and export files
* Maintenance: Removed dead wizard CSS (130+ lines)
* Maintenance: Removed PHPCS exclusion for class-sscribe-error.php
* Build: Regenerated minified assets

## [3.34.0] - 2026-04-30

### Security & Robustness
- Health check AJAX now applies rate limiting to authenticated requests to prevent abuse (previously only unauthenticated reachability check was rate-limited)
- Lock TTL increased from 30s to 45s with configurable filter `sscribe_lock_ttl`; stale threshold increased from 25s to 35s with filter `sscribe_lock_stale_threshold` — prevents false "batch already running" errors on slow servers processing PDF-heavy exports

### Debugging & Diagnostics
- PDF exporter now logs captured DomPDF output buffer in debug mode when buffer is non-empty — enables troubleshooting of DomPDF render warnings that previously went unseen
- Session `get()` now logs detailed debug info (option_name, raw type) on session not found, unexpected types, and JSON decode failures — enables diagnosis of "session not found" errors caused by transient/storage issues rather than actual expiry

### Code Quality
- Fixed duplicate `ajax_health_check()` method that was accidentally introduced during prior refactoring — PHP now properly declares the method only once
- Fixed malformed PHP in `ajax_finalize_export()` where dangling code (nonce check, rate limit check, session validation) was left outside the method body after a refactor — caused fatal PHP parse errors during test suite load
- All LSP diagnostics clean across modified files

## [3.31.0] - 2026-04-27

### Security
- AJAX endpoints now verify capability before nonce to fail fast on unauthorized requests (12 endpoints hardened)
- Session signing key now throws `RuntimeException` when no key material is available instead of falling back to insecure prefix
- Removed `'unsafe-inline'` from CSP `script-src` directive to prevent inline script injection
- Batch processing lock now verifies `set_transient()` return and fails securely with HTTP 503

### Architecture
- Container adds circular dependency detection with re-entrancy guard
- Container validates factory return types and throws on non-object resolution
- Autoloader adds in-request cache to eliminate repeated `file_exists()` calls
- Shared logger helpers extracted into `SScribe_Logger_Common` trait to eliminate duplication
- Logger request ID generation unified to use cryptographically secure `random_int()`

### Performance
- Dedicated `sscribe_sessions` table replaces wp_options storage for session data
- Added `INDEX idx_export_session_id` to export stats table
- DomPDF output streams directly to file instead of loading into memory
- Cron overlap protection prevents concurrent cleanup job execution

### Code Quality
- Replaced all direct `error_log()` calls with structured logger in exporter and page collector
- Version-aware upgrade system with incremental schema migrations

### Features
- Structured JSON logger (`SScribe_Logger_Structured`) for production observability with machine-parseable log entries
- `SSCRIBE_DEBUG_PUBLIC` constant allows independent control of admin debug display

## [3.30.13] - 2026-04-11

### Fixed
- Recent Exports now displays the correct language badge (AR, HE, etc.) instead of always showing "EN" — language metadata is stored in the export index at ZIP creation time.
- Download, trash, and log action icons in Recent Exports no longer return 404 — corrected `icons_url` from `admin/img/` to `assets/icons/` in JS localization.
- PDF export failures now provide enterprise-grade structured diagnostics — error category, severity, actionable fix steps, and technical context (memory, HTML size, libxml errors, DomPDF details) surface in both the Export Log viewer modal and the frontend error UI.

### Changed
- Recent Exports list uses the `sscribe_export_index` option as the authoritative data source instead of unreliable filename parsing with glob.
- `create_zip()` accepts `$lang_metadata` parameter to store `lang_code`, `lang_name`, and `flag_url` in the export index.
- PDF exporter returns detailed failure context including `error_category`, `html_size`, `memory_usage`, `memory_peak`, `memory_limit`, `page_id`, `language`, `is_rtl`, `libxml_errors`, and `exception_class`.
- Batch processor propagates structured errors with `format`, `message`, `category`, and `context` through the pipeline, with aggregated `error_diagnostics` in AJAX responses.
- Export Log viewer modal renders categorized diagnostics with color-coded severity badges, ordered fix steps, and collapsible technical details.

### Fixed
- Arabic/RTL text in DOCX exports now renders correctly with `complexScript` font support — no more square characters in Microsoft Word and compatible editors.
- ZIP structure now organizes multi-language exports into `FORMAT/LANG/` subfolders (e.g., `DOCX/AR/P001-Title.docx`) instead of flat file lists, removing redundant language suffix from filenames when in language folders.
- ZIP finalization no longer times out with large PDF-heavy exports — time limit increased to 300 seconds during `finalize_export()`.

### Changed
- `with_complex_script()` helper ensures all DOCX text elements (cover page, breadcrumbs, headings, body text) use the correct font for Arabic, Hebrew, and Farsi complex scripts.
- `build_filename()` accepts `$include_lang` parameter to control language suffix in filenames (stripped when language is already in folder path).
- `create_zip()` accepts `$has_language` parameter to organize ZIP into language subfolders when exporting all languages.

## [3.30.11] - 2026-04-11

### Fixed
- ZIP filename no longer shows "ALL-ALL" when exporting all formats and all languages. Now shows "ALL-LANGS" for multi-language and "ALL-FORMATS" for multi-format exports.
- PDF export no longer times out with HTTP 404. Batch size is reduced to 2 pages when PDF format is included, and per-batch execution time is increased to 300 seconds for PDF-heavy exports.
- PDF exporter now checks for DomPDF availability before attempting export and provides clear error messages with exception class names.
- Adaptive export metrics are now accurate — per-format timing and file sizes are tracked individually during batch processing instead of dividing total ZIP size equally across all formats.

## [3.30.10] - 2026-04-11

### Changed
- Export filenames now include the page title in its native language (e.g., `P001-Contact Us-EN.docx`, `P002-تواصل معنا-AR.docx`) instead of cryptic page IDs. Slug is not used as it is shared across all language variants.
- Export time and size estimates are now adaptive — they learn from actual export performance and improve over time instead of using static per-format guesses. Initial estimates use conservative baselines, then converge to real data after 3+ exports.

## [3.30.9] - 2026-04-11

### Fixed
- DOCX export now resilient to individual section/element failures — a bad cover page, image, table, or content element no longer kills the entire page export.
- Per-element try-catch in main content rendering so one malformed HTML element doesn't abort the page.
- Featured image handler now catches `\Throwable` (not just `\Exception`) to handle TypeErrors from image processing.

## [3.30.8] - 2026-04-11

### Fixed
- DOCX export error messages now include exception class name for diagnostics (previously empty messages made debugging impossible).
- Synced develop branch with main branch (was stuck at 3.30.3).

### Changed

## [3.30.7] - 2026-04-11

### Fixed
- DOCX export now produces separate per-page DOCX files for all page counts (removed broken streaming mode).
- Export Log viewer now loads correctly (fixed filename matching for non-sscribe-export- prefixed ZIPs).

### Changed

## [3.30.6] - 2026-04-10

### Fixed
- PHPCS: Array alignment in batch-processor, XSS escaping in exporter factory exception context.

### Changed
- Migrated remaining runtime Dompdf and PHPWord references to the `SScribeVendor\\` namespace.
- Updated CI and release workflows to enable `fileinfo` and keep Strauss available during prefixed vendor generation.
- Aligned the legacy release build script with the prefixed-vendor packaging flow.
- Improved admin discoverability with a top-level dashboard menu and first-activation redirect.
- Modernized GitHub workflows for Node 24-safe actions, rerun-safe release uploads, and main-head tag enforcement.
- Added regression coverage for successful post-activation admin redirects.
- Surfaced production bootstrap failures to administrators and registered admin entry hooks eagerly to prevent silent menu loss.

### Added
- Added repository hygiene files: `.editorconfig`, `CONTRIBUTING.md`, `package.json`, and `.wp-env.json`.

## [3.30.5] - 2026-04-10

### Existing baseline
- Current plugin release version as declared in the main plugin file and readme metadata.
