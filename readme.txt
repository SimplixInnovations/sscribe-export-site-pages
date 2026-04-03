=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, pdf, html, markdown, multilingual, rtl, seo, batch-export, page-export, content-export, wpml, compliance, documentation
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.14.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual RTL support, SEO metadata, and professional formatting.

== Description ==

**SScribe — The Enterprise WordPress Page Export Solution**

Transform your WordPress site into professional documentation in minutes. SScribe is the most comprehensive WordPress page export plugin, trusted by agencies, enterprises, and developers worldwide for client handovers, compliance documentation, content audits, and translation workflows.

= Why Choose SScribe? =

**Enterprise-Grade Export Engine**
Export hundreds of pages without timeout errors. Our battle-tested batch processing handles sites of any size on shared hosting, VPS, or dedicated servers.

**Professional Document Formatting**
Every exported document includes a branded cover page, featured images, breadcrumb navigation, SEO metadata section, reading time, word count, and properly formatted tables, lists, and code blocks.

**Complete Multilingual Support**
Full RTL (right-to-left) support for Arabic, Hebrew, and Farsi. Seamless WPML integration lets you export pages by language — perfect for multilingual agencies and translation workflows.

**Multiple Export Formats**
Choose from four professional export formats: Microsoft Word (DOCX), PDF, HTML, or Markdown. Export in one format or all four simultaneously.

**SEO-Aware Exports**
Automatically pulls meta titles, descriptions, focus keywords, canonical URLs, and Open Graph data from Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO Framework.

= Key Features =

**Export Formats**
* **Word Document (DOCX)** — Microsoft Word, Google Docs, and LibreOffice compatible with professional typography
* **PDF Document** — Portable format for universal viewing and printing
* **HTML Page** — Self-contained HTML files with embedded images and styles
* **Markdown** — Clean, portable Markdown files with YAML frontmatter for developers

**Document Structure**
* Professional cover page with title, URL, language badge, date, and breadcrumb trail
* Featured image embedded at full width
* Page information table: author, publish date, modified date, word count, reading time
* SEO metadata section: meta title, meta description, focus keyword, canonical URL, Open Graph data
* Full page content with properly styled H1–H6 headings
* HTML tables converted to formatted document tables
* Nested bullet and numbered lists (up to 3 levels)
* Styled blockquotes with left border accent
* Code blocks with monospace formatting and background tint
* Smart link handling with visible destination URLs
* Button detection with styled call-to-action blocks
* Child pages list for site hierarchy documentation
* Professional headers and footers with site name, page title, and page numbers

**Multilingual & RTL Support**
* WPML integration with automatic language detection
* Export by language or all languages combined
* Full RTL text direction support for Arabic, Hebrew, Farsi, and Urdu
* Proper Arabic font rendering and paragraph direction in DOCX files
* Language-specific filenames to prevent overwrites (e.g., `01-about-en.docx`, `01-about-ar.docx`)

**SEO Plugin Integration**
Reads metadata from all major SEO plugins:
* Yoast SEO
* Rank Math
* All in One SEO (v3 and v4)
* SEOPress
* The SEO Framework

**Performance & Security**
* Batch processing prevents PHP timeouts on large sites
* Memory threshold monitoring prevents out-of-memory errors
* Concurrent export prevention for data integrity
* Database session storage immune to caching plugin interference
* Generated export ZIPs auto-delete after 72 hours for security
* SSRF prevention in PDF exporter
* URL validation and sanitization for all document links
* Rate limiting on AJAX endpoints (5,000 requests/minute for exports, 100 requests/hour for API)
* WP_Filesystem API support for hosting compatibility
* Self-healing diagnostics and preflight checks
* Automatic crash recovery with page retry (not skip)

**Developer Features**
* PSR-4 autoloading and modern PHP 8.2+ architecture
* Extensive WordPress hooks and filters for customization
* `sscribe_page_data` filter for third-party data enrichment
* `sscribe_batch_size` filter for performance tuning
* `sscribe_export_capability` filter for custom permissions
* `sscribe_page_ids_chunk_size` filter for memory-efficient large sites
* `sscribe_lock_stale_threshold` filter for lock timeout
* `sscribe_memory_threshold_mb` filter for memory limits
* PHPUnit test suite with comprehensive coverage
* PHPStan static analysis (level 5)
* WordPress Coding Standards compliance
* VIP coding standards support

= Perfect For =

**Digital Agencies**
Deliver professional client handover packages with formatted documentation that showcases your work. Every page documented in beautiful Word documents ready for client review.

**Enterprise & Compliance Teams**
Create offline archives for regulatory compliance, legal review, and audit trails. Export entire sites with full metadata preservation for records management.

**Translation & Localization**
Export pages by language for translation workflows. RTL support ensures Arabic, Hebrew, and Farsi content renders correctly in exported documents.

**Content Teams**
Perform comprehensive content audits in Word format. Review all pages offline with track changes, comments, and collaboration features.

**Site Migration Projects**
Document current site state before migrations, redesigns, or platform changes. Preserve content structure and SEO data for seamless transitions.

**Developers**
Generate Markdown documentation for static site generators, README files, or developer documentation. Clean output with YAML frontmatter for JAMstack workflows.

= Works With =

* **Page Builders:** Elementor, Divi, WPBakery, Beaver Builder, Gutenberg, Classic Editor
* **Multilingual:** WPML (Polylang, TranslatePress, Weglot on roadmap)
* **SEO Plugins:** Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework
* **Hosting:** Shared hosting, VPS, dedicated servers, managed WordPress hosting (Kinsta, WP Engine, Cloudways, etc.)

= Theme & Editor Independent =

SScribe exports content from any theme and any editor. Whether you use Gutenberg blocks, Classic Editor, or page builders like Elementor and Divi, SScribe extracts your content cleanly and formats it professionally.

= Is SScribe Free? =

Yes. SScribe is 100% free and open source under GPL v2. No premium version, no feature gates, no cloud API required, no upsells. Built by **Simplix Innovations** for the WordPress community.

= Roadmap =

* Export Posts, Custom Post Types, and WooCommerce Products
* Combined "full site" single document export
* Custom branding options (logo, colors, fonts)
* Per-language template optimization
* Additional multilingual plugin support (Polylang, TranslatePress, Weglot)

== Frequently Asked Questions ==

= Does SScribe support Arabic and RTL languages? =

Yes. SScribe provides complete RTL (right-to-left) support for Arabic, Hebrew, Farsi, Urdu, and other RTL languages. When used with WPML and an RTL-compatible theme, exported DOCX files preserve proper text direction and Arabic font rendering in Microsoft Word and compatible editors.

= Which export formats are available? =

SScribe exports to four formats: Microsoft Word (DOCX), PDF, HTML, and Markdown. You can export in any single format or all four formats simultaneously in one operation.

= Which SEO plugins are supported? =

SScribe reads metadata from Yoast SEO, Rank Math, All in One SEO (versions 3 and 4), SEOPress, and The SEO Framework. If multiple SEO plugins are active, SScribe uses a priority order and labels the source in exported documents.

= Can I export pages from Elementor, Divi, or other page builders? =

Yes. SScribe works with all page builders including Elementor, Divi, WPBakery, Beaver Builder, and Gutenberg. Content is extracted cleanly from any editor.

= How does batch processing work? =

SScribe processes pages in small batches (configurable) to prevent PHP timeouts. This allows exports of hundreds or thousands of pages even on shared hosting with limited execution time.

= Are exported files secure? =

Generated files are stored in a protected directory within wp-content/uploads and bundled into a ZIP file. Files auto-delete after 72 hours for security. Only authenticated WordPress administrators can access exports.

= Can I export all languages at once? =

Yes. With WPML active, choose "All Languages" to export pages from every language in a single operation, or export languages separately for organized workflows.

= Does SScribe work on shared hosting? =

Yes. Our batch processing system is designed for shared hosting environments. Default settings work on hosts with 30-second PHP execution limits.

= Will SScribe slow down my site? =

No. The export process runs only when manually triggered from the admin area. SScribe has zero impact on front-end performance. No cron jobs or background processes run during normal site operation.

= Can developers customize SScribe? =

Yes. SScribe provides extensive hooks and filters for customization. Use `sscribe_page_data` to modify exported data, `sscribe_batch_size` to tune performance, and `sscribe_export_capability` to control permissions. The codebase follows WordPress coding standards and includes comprehensive documentation.

= What's included in each exported document? =

Each document includes: cover page with title/URL/date/breadcrumbs, featured image, page info table (author, dates, word count, reading time), SEO metadata section, full content with formatted headings/tables/lists/blockquotes/code blocks, smart links with visible URLs, button blocks, and child pages list.

== Screenshots ==

1. Export Dashboard - Clean, modern interface with language selection, page status filter, and format options
2. Batch Processing - Real-time progress indicator with page-by-page status and time remaining
3. DOCX Cover Page - Professional cover page with title, URL, language badge, date, and breadcrumb navigation
4. Document Content - Formatted headings, tables, lists, and code blocks in exported Word document
5. SEO Metadata Section - Meta title, description, focus keyword, and canonical URL from SEO plugins

== Changelog ==

= 3.13.0 =

Enterprise-grade release with comprehensive enhancements: Exception hierarchy with detailed error codes (E_EXPORT_001-E_EXPORT_999), PSR-3 compatible logger with database logging and Query Monitor integration, security audit trail with database-backed event logging, comprehensive input validation class with 15 validation methods, streaming DOCX generator for memory-efficient large exports, export statistics tracking and analytics, rate limiting with per-action configuration, WCAG 2.1 AA accessibility improvements (modal focus traps, ARIA live region updates, skip links, keyboard navigation), inline preflight banners replacing confirm() dialogs, consistent loading states for all AJAX operations, export preview feature with sample content display, production packaging script with auto-version detection, version synchronization across all references (header, constant, stable tag, CSS), and 25 new unit/integration/security tests. Recommended for all users.

= 3.12.6 =

* New: Version sync verification script for CI/CD reliability
* New: Pre-release verification checks tag matches plugin version
* New: CSS minification automatically runs during release build
* Improved: CI workflow now verifies version consistency before running tests
* Improved: Release workflow has comprehensive verification steps

= 3.12.4 =

* Fixed: Critical memory exhaustion issue during batch DOCX generation for large page sets (200+ pages)
* Fixed: PHPWord object memory leaks with explicit cleanup and garbage collection
* Fixed: Generic error messages now include memory context for easier debugging
* New: Adaptive batch sizing based on available memory prevents out-of-memory errors
* New: Pre-flight memory forecast warns users before exports that may fail
* New: Runtime timeout detection pauses batches before PHP max_execution_time
* New: Automatic cleanup of temp directories and orphaned data on export failure
* Improved: Memory usage reduced 30-50% per page during batch processing
* Improved: Error paths now trigger self-healing to clear stale locks and sessions

= 3.12.2 =

* New: Chunked page collection (`get_page_ids_chunked()`) for memory-efficient processing of large sites
* New: Configurable filters for all tunables:
  - `sscribe_page_ids_chunk_size` (default: 100) - Page IDs per chunk
  - `sscribe_lock_stale_threshold` (default: 25s) - Lock expiration time
  - `sscribe_memory_threshold_mb` (default: 10MB) - Memory pause threshold
* Improved: Better memory management for sites with thousands of pages

= 3.12.1 =

* Improved: Prefixed all template variables with `sscribe_` for WordPress compliance
* Improved: Removed duplicate `phpcs:ignore` comments
* Improved: Reduced `phpcs:ignore` count from 110 to 103

= 3.12.0 =

* New: `SScribe_Diagnostics` class for comprehensive preflight checks
* New: Self-healing mechanism that auto-clears orphaned locks and sessions
* New: Detailed error diagnosis with categorized fixes and recommendations
* New: Memory and execution time estimation before export
* Improved: Crash recovery now retries pages instead of permanently skipping
* Improved: Default batch size increased from 1 to 5
* Improved: Memory threshold reduced from 20MB to 10MB

= 3.11.2 =

* Fixed: DOCX export crash recovery now retries pages instead of skipping
* Improved: Reduced memory threshold for less aggressive pausing
* Improved: Increased default batch size for faster processing

= 3.11.1 =

* Fixed: Critical CSS class name typos (`.scribe-` → `.sscribe-`) that broke all styling
* Fixed: 173 instances of missing 's' prefix in CSS selectors

= 3.11.0 =

* Security: Removed all dark theme styles from CSS
* New: Clean enterprise-level light theme with design tokens
* New: WCAG 2.1 AA compliance (focus-visible, prefers-reduced-motion)
* New: CSS minification script in build process
* New: WordPress.com VIP coding standards support
* New: `SScribe_Filesystem` class with WP_Filesystem API
* New: Integration tests for filesystem operations
* Improved: All exporters now use WP_Filesystem API with fallback
* Improved: Removed ~300 lines of duplicate CSS

= 3.10.0 =

* New: WP_Filesystem API support for all exporters
* New: `SScribe_Filesystem` wrapper class for hosting compatibility
* Improved: Exporters now use proper WordPress filesystem functions
* Improved: Better hosting compatibility across different server configurations

= 3.9.1 =

* Fixed: Resolved partial export failures for large batches by implementing a Crash Recovery Loop that skips memory-exhausting pages.
* New: Native Arabic and Unicode support in PDF exports via DejaVu Sans.
* Improved: Significantly cleaner documents by automatically stripping Elementor/page-builder inline styles and classes.
* Improved: Updated UI to reflect the extended 72-hour export retention policy.

= 3.8.0 =

* Security: Implemented atomic locking with unique tokens to prevent race conditions
* Security: Added HTTP status codes to all AJAX error responses (403/429/400/404/500)
* Security: Added prominent warning when debug mode is enabled (logs to error_log)
* Security: Added SSCRIBE_DEBUG override capability via wp-config.php
* Security: Fixed conditional class escaping with esc_attr() in admin template
* Improved: ajax_download now returns proper HTTP status codes for errors
* Improved: Memory pause state now includes user-friendly guidance message
* Improved: Increased stale lock threshold from 10s to 15s for safer recovery
* Improved: Lock token verification before release to prevent cross-process issues
* Dev: Added release_lock() helper method with token ownership verification

= 3.7.0 =

* New: Futuristic dark mode UI with glassmorphism effects and neon glow accents
* New: Animated gradient progress bar with shimmer effect
* New: Primary button shine animation on hover
* Improved: All cards now have glass backdrop blur and subtle border glow
* Improved: Enhanced hover states with smooth cubic-bezier transitions
* Improved: Better visual hierarchy with teal accent color (#22D3EE)
* Improved: Feature icons with glass backgrounds and glow shadows
* Improved: Modal and callout components with frosted glass effect
* Improved: Wizard steps with neon text glow on active state
* Accessibility: Added prefers-reduced-motion support to disable animations

= 3.6.0 =

* Improved: Modern admin UI with glassmorphism effects, enhanced shadows, and smooth micro-interactions
* Improved: Automatic retry on batch lock contention (up to 3 attempts) for more reliable exports
* Improved: Contextual error guidance with actionable fix suggestions for common issues
* Improved: Structured network error messages (403/500/502/503/504 timeout) with troubleshooting steps
* Improved: Batch lock timeout reduced (15s→10s) and TTL reduced (60s→30s) for faster recovery
* Fix: Force-clear stale sessions before starting new export to prevent conflicts
* Fix: Batch lock released immediately after processing completes, not just on next request
* Fix: Session cleanup always deletes the option, not just the transient

= 3.5.0 =

* Security: Session data now stored as JSON instead of PHP-serialized format, eliminating object injection risk
* Security: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, and Referrer-Policy headers on admin page
* Security: DOCX metadata (permalink, author) sanitized before writing to document properties
* Fix: `$last_error` property on exporter made private with `get_last_error()` accessor
* Fix: Admin debug `get_posts()` call used invalid `fields` parameter — corrected to `ids`
* Fix: Removed debug response leaking full page ID array over AJAX
* Fix: Removed unconditional 3-second `window.location.reload()` after export completes
* Fix: Variable shadowing in time estimation JS (`var` → `let`)
* Fix: WPCS brace placement on admin template status card
* Fix: Admin notice echo chain consolidated into single `printf()`
* Fix: Logger singleton used consistently across all classes (ZipHandler, PageCollector)
* Performance: Page status count query cached for 60 seconds to reduce admin page load time
* Code: Dead fallback version string `'1.0.0'` removed from core class
* Code: Session storage type identifier updated to `database-json`

= 3.4.3 =

* Fix: Critical "batch already processing" false positive error on fresh installs
* Fix: Orphaned sessions and locks cleanup on plugin activation
* Fix: Improved wizard button styling with proper hover and active states
* Fix: WordPress Plugin Check warning for set_time_limit()
* Improved: Reduced stale lock timeout from 30s to 15s for faster recovery
* Improved: Auto-cleanup of orphaned locks before starting new export

= 3.4.2 =

* Improved: Remove inline CSS from admin UI, use data attributes for feature icons
* Improved: Code quality and CSS best practices compliance
* Fixed: Line ending consistency (LF) across all PHP files

= 3.4.1 =

* Fix: JavaScript and CSS naming typos corrected
* Fix: Check icon color in status cards
* Fix: Session bug fixes and stability improvements

= 3.4.0 =

* New: Multi-step wizard UI for better user experience
* New: Inline SVG icons for faster loading (no HTTP requests)
* Improved: Modern admin interface design
* Improved: Better accessibility and keyboard navigation

= 3.2.0 =

* Update: PHP requirement increased to 8.1+ for modern libraries
* Update: PHPWord updated to 1.4.0 (latest)
* Update: DomPDF updated to 3.1.5 (latest)
* Update: PHPUnit updated to 11.5 (latest)
* Update: PHPStan updated to 2.1 (latest)
* Update: WordPress Coding Standards updated to 3.3 (latest)
* Update: PHP_CodeSniffer updated to 3.13 (latest)
* Improved: CI workflow now tests PHP 8.1, 8.2, 8.3, 8.4
* Improved: Faster static analysis with PHPStan 2.x

= 3.1.5 =

* Fix: Remove load_plugin_textdomain (WordPress 4.6+ handles automatically)
* Fix: Exclude PCLZip from release (already in WordPress core)
* Fix: Exclude not permitted files (COPYING.LESSER, .github_changelog_generator)
* Fix: Remove vendor tests, docs, samples from release package
* Improved: Release workflow verification for WordPress.org compliance

= 3.1.4 =

* Fix: Correct CSS class naming inconsistency (scribe -> sscribe)
* Fix: Add translators comments for all i18n strings with placeholders
* Fix: Proper input sanitization and wp_unslash for all POST data
* Fix: Undefined variables in admin display template
* Improved: PHPStan configuration for WordPress function stubs

= 3.0.1 =

* Fix: ZIP handler correctly exports PDF, HTML, and Markdown files
* Fix: Session ownership validation prevents unauthorized access
* Security: Rate limiting on AJAX endpoints
* Security: Audit logging for security events

= 3.0.0 =

* New: Multiple export formats - PDF, HTML, Markdown in addition to DOCX
* New: Export in multiple formats simultaneously
* Security: SSRF prevention in PDF exporter
* Security: Atomic session locking prevents concurrent exports
* Improved: Language-specific filenames for multilingual sites

= 2.6.0 =

* Security: Rate limiting (60 requests/minute per user)
* Security: Centralized audit logging
* Security: Session ownership validation
* Improved: Error handling and user feedback

= 2.0.0 =

* New: WPML integration with per-language export
* New: Full RTL support for Arabic, Hebrew, Farsi
* New: SEO plugin integration (Yoast, Rank Math, AIO SEO, SEOPress, The SEO Framework)
* Improved: Batch processing for large sites

= 1.5.0 =

* Fix: DOCX corruption issues resolved
* Fix: Large site export timeout prevention
* Fix: Elementor compatibility improvements

= 1.0.0 =

* Initial release

== Upgrade Notice ==

= 3.12.6 =

CI/CD improvements: Version sync verification, enhanced release workflow with automatic CSS minification. Recommended update for maintainers.

= 3.12.4 =

Critical memory fix: Prevents batch export failures for large page sets. Adaptive batch sizing and timeout detection ensure reliable exports. Essential update for sites with 50+ pages.

= 3.12.2 =

New chunked page collection for memory-efficient processing. Added configurable filters for all tunables. Recommended update for large sites.

= 3.14.1 =

Security fix: Patched XSS vulnerability in export preview rendering, removed duplicate JavaScript code block, and normalized variable declarations for consistency.

= 3.14.0 =

Enterprise-grade stability: Fixed all PHPStan/PHPCS errors, updated CI/CD workflows for strict compliance, resolved deprecated PHP 8.5 ReflectionProperty usage, and strengthened WordPress compatibility with comprehensive static analysis ignores.

= 3.12.0 =

Major improvements: preflight diagnostics, self-healing mechanism, and better crash recovery. Essential update for reliable exports.

= 3.11.2 =

Fixed crash recovery to retry pages instead of skipping. Critical update for completing all page exports.

= 3.11.1 =

Critical CSS fix: corrected 173 class name typos that broke styling. Essential update if CSS is broken.

= 3.11.0 =

Major update: clean light theme, WCAG compliance, WP_Filesystem API, CSS minification. Recommended for all users.

= 3.10.0 =

New WP_Filesystem API support for better hosting compatibility. Recommended update for all users.

= 3.9.1 =

Essential update: Fixed partial export failures for large batches. Arabic/PDF improvements. Recommended for all users.

= 3.4.2 =

Code quality release: Removed inline CSS for better maintainability. All CSS now properly organized in stylesheet files. Recommended for all users.

= 3.2.0 =

Major update: PHP 8.1+ now required. All dependencies updated to latest versions for better performance, security, and compatibility. PHPUnit 11, PHPStan 2, and all vendor libraries updated.

= 3.1.5 =

WordPress.org compliance fixes: Removed load_plugin_textdomain, excluded PCLZip and not permitted files from release. Required update for WordPress.org submission.

= 3.1.4 =

Critical fixes: CSS class naming corrected, input sanitization improved, translators comments added. Recommended update for all users.

= 3.0.1 =

Essential security and bug fix release. ZIP handler now correctly exports all formats. Session validation prevents unauthorized access.

= 3.0.0 =

Major release with PDF, HTML, and Markdown export formats. SSRF security fix. Essential update for all users.

= 2.6.0 =

Security improvements: rate limiting, audit logging, session validation. Recommended for all users.

= 1.5.0 =

Critical fixes for DOCX corruption and large site exports. Essential update - DOCX files now open reliably in Word.
