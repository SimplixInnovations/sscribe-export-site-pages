=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Tags: export, docx, pdf, html, markdown
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.1.3
Requires PHP: 8.2
License: GPLv2 or later
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

* Batch processing for sites of any size
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

Yes. ZIP files are stored in a protected uploads directory and automatically delete after 72 hours. Only logged-in administrators with appropriate capabilities can access exports.

= Does SScribe work with WPML? =

Yes. You can export pages by individual language or all languages simultaneously. The plugin integrates with WPML's language registry to provide accurate language metadata.

= What happens if an export is interrupted? =

SScribe uses a session tracking system with crash recovery. If your browser closes during an export, you can resume it from the admin panel without losing progress.

= Does SScribe contact external servers or fetch images? =

No. All export processing happens on your own WordPress server. PDF and DOCX exports embed images that are already in your Media Library; no external HTTP requests are made during an export.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

The bundled Amiri font family (assets/fonts/amiri/) is licensed under the SIL Open Font License v1.1, which is compatible with GPL v2. See assets/fonts/amiri/OFL.txt for the full license text.

The bundled PhpOffice/PhpWord library (vendor-prefixed/phpoffice/) is licensed under the GNU Lesser General Public License v3.0 only. LGPL v3 is compatible with GPL v2-or-later: the LGPL permits redistribution of a combined work under GPL terms, and PhpOffice publishes its source under the same terms. The full LGPL v3 text is available at https://www.gnu.org/licenses/lgpl-3.0.html.

The bundled Mpdf library (vendor-prefixed/mpdf/) is licensed under the GNU General Public License v2 only.

== Changelog ==

= 1.1.3 =
* Added full v3 component library: .sscribe-button, .sscribe-input, .sscribe-select, .sscribe-textarea, .sscribe-card, .sscribe-panel, .sscribe-table (with tabular-nums numeric columns), .sscribe-breadcrumb, .sscribe-empty-state, .sscribe-progress, .sscribe-checkbox, .sscribe-radio, .sscribe-switch, .sscribe-segmented, .sscribe-chip, .sscribe-badge, .sscribe-code, .sscribe-kbd, .sscribe-form-row, .sscribe-form-grid, .sscribe-fieldset, .sscribe-modal-backdrop, .sscribe-toast-warning, [data-tooltip] (pure-CSS tooltip).
* Added semantic role token aliases (--ss-color-fg-primary, --ss-color-bg-surface, --ss-color-accent, --ss-color-success-fg, etc.) so the physical --ss-* palette can be rebalanced without touching component rules.
* Re-tuned dark-mode palette for true WCAG AA on dark surfaces: brand #7C75FF to #8E89FF, text-muted #8b8b96 to #a1a1aa.
* Unified focus ring outline rule across 14 control families (button, tab-btn, modal-close, button-icon, toast-dismiss, preflight-close, support-copy-text, format-option-field select, help-link, onboarding-dismiss, post-type/status/format/lang card labels, format-option-checkbox, bulk-select-all, history-check-label). All use `outline: 2px solid var(--ss-color-accent)`.
* Hit targets normalized: button 40px default, button-icon 40x40, modal-close 40x40, tab-btn 44px, btn-sm 32px, btn-lg 44px. Form inputs and selects 40px tall.
* Debug console CSS aligned to v3 component system; --ss-debug-* scoped tokens for the dark terminal chrome.
* Polish: status cards now use a 6-col grid (3-col at <=1100px, 2-col at <=640px) so the "All" card no longer wraps to a full-width orphan row.
* Polish: format cards switched to `repeat(5, minmax(0, 1fr))` so all 5 cards share equal width without horizontal overflow at intermediate viewports (1024-1280px).
* Polish: `--ss-text-muted` light value bumped from #71717a to #5a5f66 to clear WCAG AA against `--ss-bg` and `--ss-brand-soft` backgrounds; dark-mode override unchanged at #a1a1aa.
* Polish: format cards stack vertically on mobile (>=640px and below) via flex-column override of the grid, matching post-type and status rows.
* Polish: card-row gap tightened from var(--ss-space-2) (8px) to var(--ss-space-1) (4px) for tighter visual rhythm; status grid uses --ss-space-2 to preserve readability across 6 columns.
* Polish: hero-stats baseline verified to align with title x at 1024, 1280, 1440 viewports (padding-left on .sscribe-hero-stats calibrated at <=900px breakpoint).
* Verified: forced-colors media block covers all 14 focusable element families with 3px Highlight !important outlines; CanvasText applied to all text and border tokens; primary/success/danger buttons map to Highlight/HighlightText via forced-color-adjust: none for engines that support it (Edge, Chrome, Firefox 113+).

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

== Installation from GitHub Release ZIP ==

If you downloaded the plugin from the GitHub Releases page, the ZIP already contains a pre-built `vendor-prefixed/` directory with the namespaced PhpWord and mPDF libraries. No additional build step is required : just upload and activate.

If you cloned the repository directly, you must run the following once before activating the plugin:

    composer install
    composer vendor:prefix

This generates the `vendor-prefixed/` directory and the namespaced runtime shim that the plugin depends on. The `.distignore` file excludes both `vendor/` and `vendor-prefixed/` from Git tracking, so a fresh clone will not include them.

== Upgrade Notice ==

= 1.1.3 =
Full 1000% enterprise UI revamp: complete component library (button, input, table, breadcrumb, modal, toast, switch, segmented, chip, badge, code, kbd, empty-state), semantic role tokens (--ss-color-* aliases), dark-mode contrast tuned for true WCAG AA (brand #8E89FF, muted text #a1a1AA on dark), and unified focus ring outline across 14 control families. No PHP/JS changes, all class names preserved.

= 1.1.2 =
Recommended update: closes a latent PDF crash that affected pages with standard theme CSS. Exports now render successfully for English/Arabic content; out-of-coverage chars render as ? tofu but no longer crash.

= 1.1.1 =
Maintenance release: batch processing reliability improvements, crash recovery for interrupted exports, RTL formatting enhancements, and AJAX/security hardening.

= 1.0.0 =
Initial release. Export WordPress pages to DOCX, PDF, HTML, or Markdown with full multilingual RTL support, SEO metadata integration, and secure ZIP downloads.

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
Fires inside `SScribe_Batch_Processor::dispatch_formats()` for each selected format (`pdf`, `docx`, `markdown`, `html`). Receives the per-format options collected from the admin UI and returns the (possibly modified) options map the exporter should use. This is the supported extension point for adding new per-format options. See `docs/extension-points.md` for a full example and the full list of option keys.

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
