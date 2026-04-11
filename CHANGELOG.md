# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

## [3.30.12] - 2026-04-11

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
