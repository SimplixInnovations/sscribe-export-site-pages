# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [3.7.7] - 2026-05-13

### Changed
- **Arabic PDF rendering**: Switched from DejaVu Sans to **XB Riyaz** (bundled with mPDF) for superior Arabic typography — XB Riyaz is purpose-built for Arabic script with professional letterforms, while DejaVu Sans is a general-purpose font with basic Arabic coverage
- Font helper: `get_arabic_font_path()` now returns XB Riyaz (regular/bold) instead of DejaVu Sans
- PDF exporter: `default_font` for RTL changed from `dejavusans` to `xbriyaz`; fonttrans mappings redirected from dejavusans to xbriyaz
- XB Riyaz is mPDF's standard bundled Arabic font — already in vendor-prefixed, zero additional ZIP size

## [3.7.6] - 2026-05-13

### Changed
- **Arabic PDF rendering**: Replaced NotoSansArabic with DejaVu Sans (bundled with mPDF) — eliminates `MarkGlyphSets - Not tested yet` error that caused ~96% Arabic PDF failures
- Removed `SSCRIBE_FONT_ARABIC` / `SSCRIBE_FONT_ARABIC_BOLD` constants and NotoSansArabic font files (~488 KB) from plugin distribution
- HTML exporter: Removed NotoSansArabic `@font-face` CSS — now relies on system font stack (`system-ui, -apple-system, sans-serif`) for RTL browser preview
- Font helper: `get_arabic_font_path()` now points to DejaVu Sans in vendor-prefixed/mpdf
- Font helper: `get_arabic_font_url()` returns empty string (DejaVu Sans is not web-accessible)
- XB Riyaz, Lateef, and Uthman retained as mPDF Arabic shaping fallbacks

### Removed
- `assets/fonts/notosansarabic/` directory (NotoSansArabic-Regular.ttf, NotoSansArabic-Bold.ttf)
- `SSCRIBE_FONT_ARABIC` and `SSCRIBE_FONT_ARABIC_BOLD` constants from plugin bootstrap

## [3.7.1] - 2026-05-12

### Fixed
- CRITICAL: DOCX export — Arabic/RTL list items now render with correct complexScript font settings (bidi/rtl/complexScript) — previously showed squares in Microsoft Word
- CRITICAL: DOCX export — safe_text() preg_replace null return guard prevents TypeError crash on PCRE backtrack limit exhaustion
- HIGH: Content parser — Added safe_replace() wrapper guards all preg_replace calls against null return from PCRE backtrack/recursion limit exhaustion
- HIGH: Content parser — Button extraction now handles preg_match_all returning false (PCRE error) instead of silently dropping buttons
- HIGH: PDF export — Reordered NotoSansArabic font validation to happen before font file search, eliminating E_WARNING from scandir() on missing directory
- MEDIUM: HTML exporter — Removed unused $text_align variable (dead code)

## [3.6.5] - 2026-05-11

### Fixed
- CRITICAL: Arabic PDF rendering — `useOTL=0xFF` and `useKashida=75` for proper character shaping and joining
- CRITICAL: Arabic PDF — `default_font` conditional (notosansarabic for RTL, manrope otherwise)
- CRITICAL: Arabic PDF — `lang2fonts` mapping for ar/fa/ur/he to notosansarabic
- CRITICAL: Arabic PDF — Strip `@font-face` from HTML before `WriteHTML()` to prevent HTTP self-request
- CRITICAL: DOCX — ZipArchive integrity check after save catches malformed ZIPs before packaging
- CRITICAL: DOCX — Division by zero guard in `render_table()` for empty tables
- HIGH: Image scaling — Always scale to target width (both up and down) for uniform appearance
- HIGH: PDF images — `width: 100%` instead of `max-width` for proper rendering
- MEDIUM: `safe_text()` — iconv UTF-8 normalization, form-feed removal, line-ending normalization, long string soft-hyphen breaking
- MEDIUM: PDF metadata — `SetTitle`, `SetAuthor`, `SetCreator`, `SetSubject`, `SetKeywords` populated
- MEDIUM: Mid-batch cancellation — re-read session after each page for responsive cancel

## [3.6.3] - 2026-05-11

### Fixed
- CRITICAL: Stale lock race condition — `delete_transient()` before `set_transient()` prevents double batch processing that corrupts DOCX files
- CRITICAL: Uninitialized `$output_path` in DOCX exporter catch block — prevents silent failure on partial file cleanup
- HIGH: Unbounded page cache growth — `clear_page_caches()` called in batch finally block to prevent OOM on large exports
- MEDIUM: Dead code `$recently_started_window` removed from session check
- MEDIUM: DOCX minimum size threshold raised from 1KB to 4KB (valid OOXML ZIP is never < 4KB)
- MEDIUM: `set_time_limit()` silenced with `@` for restricted hosting environments
- MEDIUM: Double `finalize_export()` guard — session status set to 'completing' before ZIP creation
- MEDIUM: Export log `mark_complete()`/`mark_failed()` now explicitly flush to disk

## [3.6.2] - 2026-05-11

### Fixed
- PDF font helper: `find_font_file()` now returns just the filename instead of full path (prevents mPDF double-path resolution for B/M/L font variants)

## [3.6.1] - 2026-05-11

### Fixed
- mPDF font configuration: fontdata entries must use relative filenames when fontDir is set to absolute path (prevents double-path resolution causing "Cannot find TTF" errors on Linux servers)
- Admin CSS: Moved CSS custom properties from `.sscribe-master-container` scope to `:root` scope so modals rendered outside the container can access design tokens

## [3.6.0] - 2026-05-10

### Fixed
- PHPCS: Fixed 56 indentation errors in `class-sscribe-pdf-exporter.php` (line 417 return statement indentation)

## [3.56.1] - 2026-05-10

### Fixed
- PDF export: Font discovery now uses case-insensitive regex matching to handle Linux servers with case-sensitive filesystems
- PDF export: Added `find_font_file()` helper method for all font variants (Manrope R/B/M/L, NotoSansArabic R/B)
- PDF export: Enhanced error logging now includes directory contents when font validation fails
- Admin CSS: Modal content/header/body now have explicit fallback values for CSS custom properties
- Admin CSS: Added fallback `#FFFFFF` background to `.sscribe-modal-content` and `.sscribe-modal-body`
- Admin CSS: Added fallback `#F8FAFC` background and `#E2E8F0` border to `.sscribe-modal-header`
- Admin CSS: Added fallback box-shadow values to `.sscribe-modal-content`

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
