=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Tags: export, docx, pdf, html, markdown
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.1.7
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to professional DOCX, PDF, HTML, or Markdown files with multilingual RTL support and secure ZIP download.

== Description ==

SScribe transforms WordPress pages into professional documents for client handovers, compliance documentation, content audits, and translation workflows.

**Export Formats:**

* **DOCX** - Microsoft Word, Google Docs, LibreOffice compatible
* **PDF** - Portable format for universal viewing
* **HTML** - Self-contained files with embedded styles
* **Markdown** - Clean output with YAML frontmatter

**Key Features:**

* Bounded batch processing for large sites
* RTL support for Arabic, Farsi, Urdu, and other Arabic-script languages
* WPML integration with language-specific exports
* SEO metadata from Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Professional document formatting: cover page, headings, tables, lists, code blocks
* Secure ZIP downloads with automatic 72-hour deletion
* Export session tracking with crash recovery

== Screenshots ==

1. Export dashboard with language and format selection
2. Real-time batch progress with per-page status
3. Generated ZIP download with all four formats
4. Language and post-type filtering
5. Export history and download management

**Requirements:**

* WordPress 6.0 or higher
* PHP 8.2 or higher
* 256MB memory recommended for large exports

== Installation ==

1. Upload the `sscribe-export-site-pages` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **SScribe Export** in your admin menu to start exporting

== Frequently Asked Questions ==

= Does SScribe support RTL languages? =

Yes. Arabic, Farsi, Urdu, and other Arabic-script RTL languages are fully supported in DOCX, PDF, HTML, and Markdown exports. The plugin automatically detects RTL content and applies appropriate styling.

= Which WordPress page builders are supported? =

SScribe works with Elementor, Divi, WPBakery, Beaver Builder, Gutenberg, and Classic Editor.

= Are exported files secure? =

Yes. ZIP files are stored in a protected uploads directory and automatically delete after 72 hours. Only logged-in users with the plugin's delegated export capability can access their own exports.

= Does SScribe work with WPML? =

Yes. You can export pages by individual language or all languages simultaneously. The plugin integrates with WPML's language registry to provide accurate language metadata.

= What happens if an export is interrupted? =

SScribe uses a session tracking system with crash recovery. If your browser closes during an export, you can resume it from the admin panel without losing progress.

= Does SScribe contact external services or fetch images? =

SScribe does not send content to a third-party service. Media Library images are read directly from the local uploads directory. If exported page content references an image on the same WordPress site but outside that directory, SScribe may retrieve it through WordPress's safe HTTP API so it can be embedded. Other hosts are blocked by default; developers can explicitly add trusted image hosts with the `sscribe_allowed_image_hosts` filter.

== Privacy ==

SScribe stores short-lived export sessions, export archives, operational logs, and security audit records on the WordPress site. Export archives expire after 72 hours by default, export logs are periodically cleaned, and session records expire automatically. The plugin registers WordPress personal-data exporter and eraser callbacks for its user-linked records. It does not transmit exported page content to Simplix Innovations or another third-party service.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

The bundled Amiri font family (assets/fonts/amiri/) is licensed under the SIL Open Font License v1.1, which is compatible with GPL v2. See assets/fonts/amiri/OFL.txt for the full license text.

The bundled PhpOffice/PhpWord library (vendor-prefixed/phpoffice/) is licensed under the GNU Lesser General Public License v3.0 only. LGPL v3 is compatible with GPL v2-or-later: the LGPL permits redistribution of a combined work under GPL terms, and PhpOffice publishes its source under the same terms. Its license notice is included as `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt`.

The bundled Mpdf library (vendor-prefixed/mpdf/) is licensed under the GNU General Public License v2 only.

== Changelog ==

= 1.1.7 =
* Bumped development dependencies to latest stable versions across the board (PHPUnit 13, PHPStan 2.2, WPCS 3.4, vipwpcs 3.1, phpcompatibility-wp 2.1, ESLint 10, Prettier 3.9, Stylelint 17).
* Migrated PHPUnit test schema from annotations to native attributes (`#[DataProvider]`, `#[AllowMockObjectsWithoutExpectations]`).
* Bumped GitHub Actions to current major versions (checkout v6, setup-node v6, cache v6, upload-artifact v6) and Node LTS to 24.
* Removed dead `transition: width` declaration on the progress-bar finalizing state; the fill already animates via `transform: scaleX()` on its sibling class.

= 1.1.6 =
* Hardened archive ownership, download, deletion, cleanup, and index updates against path traversal, symlink, stale-lock, collision, and concurrency failures.
* Hardened DOCX, PDF, HTML, and Markdown generation against malformed filter data, unsafe temporary paths, oversized input, remote image resolution, and internal error disclosure.
* Added bounded session, privacy, audit, diagnostics, history, and debug-log reads to prevent unbounded memory use on large or damaged installations.
* Improved capability separation, personal-data erasure, log redaction, rate limiting, activation checks, and multisite lifecycle handling.
* Normalized the bundled PHPWord LGPL notice filename and tightened the release builder so development artifacts and unsupported file types cannot enter the distribution ZIP.
* Refined the export interface, debug console, progress states, and accessibility behavior.

= 1.1.5 =
* Removed production console noise and refined interface styling and compatibility.

= 1.1.4 =
* Fixed final-page truncation on large sites and hardened temporary-file and ZIP cleanup paths.
* Preserved Markdown option wiring and third-party license attribution.

= 1.1.3 =
* Refined controls, tables, dialogs, dark mode, high contrast, and keyboard focus states.

= 1.1.2 =
* Fixed latent PDF export crash: WordPress themes ship base CSS with `font-family: serif`; mPDF's chain resolution tried to load pruned DejaVu*Condensed / FreeSans / Sun-ExtA TTFs and crashed. fonttrans remap and fontdata overrides close the CSS-keyword, fontdata-entry, and backup-substitution paths on the same crash class.

= 1.1.1 =
* Fixed PHPCS warnings across all files
* Improved batch processing reliability
* Enhanced RTL document formatting
* Added crash recovery for interrupted exports
* Security hardening for AJAX endpoints and file downloads

= 1.0.0 =
* Initial release
* DOCX, PDF, HTML, and Markdown export support
* Batch processing engine with configurable chunk sizes
* RTL support for Arabic, Farsi, and Urdu
* WPML integration for language-specific exports
* SEO metadata integration with Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Secure ZIP downloads with automatic 72-hour cleanup
* Session tracking with crash recovery and retry logic
* Memory monitoring and timeout protection
* GDPR-compliant audit trail with HMAC-SHA256 hashed IP addresses

== Upgrade Notice ==

= 1.1.7 =
Maintenance release with development-tooling updates and a small admin CSS cleanup. No runtime behavior changes for end users.

== Filters ==

= `sscribe_max_execution_time` =
Override the maximum PHP execution time for batch exports.

Parameters: `(int $seconds)` - Default: 120

= `sscribe_pdf_max_execution_time` =
Override the maximum PHP execution time for PDF-heavy exports.

Parameters: `(int $seconds)` - Default: 150

= `sscribe_pdf_max_html_size` =
Maximum HTML size (in bytes) passed to mPDF before truncation.

Parameters: `(int $bytes)` - Default: 5,242,880 (5MB)

= `sscribe_use_chunked_page_ids` =
Force enable or disable chunked page ID loading for sites with very large numbers of pages.

Parameters: `(bool)` - Default: null (auto-detect based on page count)

= `sscribe_export_options_{$format}` =
Fires inside `SScribe_Batch_Processor::dispatch_formats()` for each selected format (`pdf`, `docx`, `markdown`, `html`). Receives the per-format options collected from the admin UI and returns the (possibly modified) options map the exporter should use.

Parameters: `(array $format_options, int $page_id, string $session_id)`

= `sscribe_batch_size` =
Filters the number of pages per AJAX chunk in a batch export. Return an `int` between 1 and 20; lower values reduce per-request memory but increase the number of HTTP round trips.

Parameters: `(int $pages)` - Default: 5

= `sscribe_rate_limit_admin` =
Filters the per-hour export request cap for users with the `manage_options` capability. Return an `int`.

Parameters: `(int $requests)` - Default: 1000

= `sscribe_pdf_memory_soft_margin_bytes` /
When remaining memory drops below this, the mPDF exporter triggers a `gc_collect_cycles()` and continues. Default: `32 * MB_IN_BYTES`.

= `sscribe_pdf_memory_hard_margin_bytes` =
When remaining memory drops below this, the mPDF exporter aborts with a `SScribe_Result::failure`. Default: `8 * MB_IN_BYTES`.

== Public classes ==

The following classes are part of the public API and may be used by extension plugins:

* `SScribe_Export_All_Formats_Wrapper` : static `export_page()` for fan-out exports to every supported format in a single call, with per-format error isolation. See `docs/extension-points.md` for usage.
* `SScribe_Exporter_Factory` : `create( string $format )` to construct a specific exporter.
* `SScribe_Exporter_Interface` : the contract every exporter implements; third-party exporters can plug in by extending the factory.

== Actions ==

= `sscribe_before_export_page` =
Fires before a page is exported during batch processing.

Parameters: `(int $page_id, string $language)`

= `sscribe_after_export_page` =
Fires after a page has been exported during batch processing.

Parameters: `(int $page_id, array $formats, bool $export_success)`

= `sscribe_cleanup_exports` =
Cron hook for cleaning up expired export files. Triggered hourly by default.

= `sscribe_cleanup_sessions` =
Cron hook for cleaning up stale export sessions. Triggered hourly by default.
