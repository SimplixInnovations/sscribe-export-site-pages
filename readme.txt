=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, pdf, html, markdown, pages, multilingual, rtl, wpml
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.1.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages and posts to professional DOCX, PDF, HTML, or Markdown files with multilingual RTL support, SEO metadata, and secure ZIP download.

== Description ==

SScribe transforms WordPress pages into professional documents for client handovers, compliance documentation, content audits, and translation workflows.

**Export Formats:**

* **DOCX** — Microsoft Word, Google Docs, LibreOffice compatible
* **PDF** — Portable format for universal viewing
* **HTML** — Self-contained files with embedded styles
* **Markdown** — Clean output with YAML frontmatter

**Key Features:**

* Batch processing for sites of any size
* RTL support for Arabic, Hebrew, Farsi, Urdu, and other RTL languages
* WPML integration with language-specific exports
* SEO metadata from Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Professional document formatting: cover page, headings, tables, lists, code blocks
* Secure ZIP downloads with automatic 72-hour deletion
* Export session tracking with crash recovery

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

Yes. Arabic, Hebrew, Farsi, Urdu, and other RTL languages are fully supported in DOCX, PDF, HTML, and Markdown exports. The plugin automatically detects RTL content and applies appropriate styling.

= Which WordPress page builders are supported? =

SScribe works with Elementor, Divi, WPBakery, Beaver Builder, Gutenberg, and Classic Editor.

= Are exported files secure? =

Yes. ZIP files are stored in a protected uploads directory and automatically delete after 72 hours. Only logged-in administrators with appropriate capabilities can access exports.

= Does SScribe work with WPML? =

Yes. You can export pages by individual language or all languages simultaneously. The plugin integrates with WPML's language registry to provide accurate language metadata.

= What happens if an export is interrupted? =

SScribe uses a session tracking system with crash recovery. If your browser closes during an export, you can resume it from the admin panel without losing progress.

== Changelog ==

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
* RTL support for Arabic, Hebrew, Farsi, and Urdu
* WPML integration for language-specific exports
* SEO metadata integration with Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework
* Secure ZIP downloads with automatic 72-hour cleanup
* Session tracking with crash recovery and retry logic
* Memory monitoring and timeout protection
* GDPR-compliant audit trail with HMAC-SHA256 hashed IP addresses

== Upgrade Notice ==

= 1.0.0 =
Initial release. Export WordPress pages to DOCX, PDF, HTML, or Markdown with full multilingual RTL support, SEO metadata integration, and secure ZIP downloads.

== Filters ==

= `sscribe_max_execution_time` =
Override the maximum PHP execution time for batch exports.

Parameters: `(int $seconds)` — Default: 120

= `sscribe_pdf_max_execution_time` =
Override the maximum PHP execution time for PDF-heavy exports.

Parameters: `(int $seconds)` — Default: 150

= `sscribe_pdf_max_html_size` =
Maximum HTML size (in bytes) passed to mPDF before truncation.

Parameters: `(int $bytes)` — Default: 5,242,880 (5MB)

= `sscribe_use_chunked_page_ids` =
Force enable or disable chunked page ID loading for sites with very large numbers of pages.

Parameters: `(bool)` — Default: null (auto-detect based on page count)

== Actions ==

= `sscribe_before_export_page` =
Fires before a page is exported during batch processing.

Parameters: `(int $page_id, string $language)`

= `sscribe_after_export_page` =
Fires after a page has been exported during batch processing.

Parameters: `(int $page_id, array $formats, bool $export_success)`

= `sscribe_cleanup_exports` =
Cron hook for cleaning up expired export files. Triggered daily.

= `sscribe_cleanup_sessions` =
Cron hook for cleaning up stale export sessions. Triggered daily.
