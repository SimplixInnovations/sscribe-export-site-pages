=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, pdf, html, markdown, multilingual, rtl, wordpress
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual RTL support, SEO metadata, and professional formatting.

== Description ==

SScribe transforms WordPress pages into professional documents for client handovers, compliance documentation, content audits, and translation workflows.

= Export Formats =

* **DOCX** — Microsoft Word, Google Docs, LibreOffice compatible
* **PDF** — Portable format for universal viewing
* **HTML** — Self-contained files with embedded styles
* **Markdown** — Clean output with YAML frontmatter

= Features =

* Batch processing for sites of any size
* RTL support for Arabic, Hebrew, Farsi, Urdu
* WPML integration with language-specific exports
* SEO metadata from Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Professional document formatting: cover page, headings, tables, lists, code blocks
* Secure ZIP downloads with 72-hour auto-deletion
* Export session tracking with crash recovery

= Requirements =

* WordPress 6.0+
* PHP 8.2+
* 256MB memory recommended for large exports

== Installation ==

1. Upload the plugin to your `/wp-content/plugins/` directory
2. Activate through the 'Plugins' menu in WordPress
3. Go to SScribe Export in your admin menu to start exporting

== Frequently Asked Questions ==

= Does SScribe support RTL languages? =

Yes. Arabic, Hebrew, Farsi, and other RTL languages are fully supported in both DOCX and PDF exports.

= Which WordPress page builders are supported? =

SScribe works with Elementor, Divi, WPBakery, Beaver Builder, Gutenberg, and Classic Editor.

= Are exported files secure? =

Yes. ZIP files are stored in a protected uploads directory and auto-delete after 72 hours. Only logged-in administrators can access exports.

= Does SScribe work with WPML? =

Yes. You can export pages by individual language or all languages at once.

== Hooks ==

= Actions =

`sscribe_before_export_page`
Fires before a page is exported during batch processing.

Parameters: `(int $page_id, string $language)`

`sscribe_after_export_page`
Fires after a page has been exported during batch processing.

Parameters: `(int $page_id, array $formats, bool $export_success)`

`sscribe_cleanup_exports`
Cron hook for cleaning up expired export files. Triggered daily by WordPress cron.

`sscribe_cleanup_sessions`
Cron hook for cleaning up stale export sessions. Triggered daily by WordPress cron.

`sscribe_debug_log`
Fires when debug logging occurs during export operations.

Parameters: `(string $level, string $message, array $context)`

= Filters =

`sscribe_max_execution_time`
Override the maximum PHP execution time for batch exports.

Parameters: `(int $seconds)` — Default: 120

`sscribe_pdf_max_execution_time`
Override the maximum PHP execution time for PDF-heavy exports.

Parameters: `(int $seconds)` — Default: 300

`sscribe_pdf_max_html_size`
Maximum HTML size (in bytes) passed to mPDF before truncation.

Parameters: `(int $bytes)` — Default: 5,242,880 (5MB)

== Changelog ==

= 1.1.1 =

* Fixed duplicate JavaScript function definition (copyViaTextarea)
* Fixed JS syntax error: stray quote character in refreshStatusAndLanguageCounts method
* Fixed unescaped HTML in history empty state (XSS prevention)
* Fixed null safety in diagnostics PHPWord version detection
* Fixed possessive regex quantifiers for PCRE1 compatibility (content parser)
* Improved language code extraction to support 2-3 letter codes (WPML ZHT, ZHS)
* Added post-type-aware adaptive metrics for more accurate time estimates
* Removed duplicate CSS step-badge selector
* Added missing .distignore entries for development files

= 1.1.0 =

* Initial release with full feature set: DOCX, PDF, HTML, and Markdown export
* Batch processing engine with configurable chunk sizes
* RTL support for Arabic, Hebrew, Farsi, and Urdu
* WPML integration for language-specific exports
* SEO metadata integration with Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Secure ZIP downloads with automatic 72-hour cleanup
* Session tracking with crash recovery and retry logic
* Memory monitoring and timeout protection
* GDPR-compliant audit trail with HMAC-SHA256 hashed IP addresses

== Upgrade Notice ==

= 1.1.1 =

Bug fixes and improvements: JavaScript syntax fix, security hardening, and better WPML language code support.

= 1.1.0 =

Initial release. Export WordPress pages to DOCX, PDF, HTML, or Markdown with full multilingual RTL support, SEO metadata integration, and secure ZIP downloads.