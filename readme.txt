=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, multilingual, seo, pdf
Requires at least: 6.0
Tested up to: 6.10
Stable tag: 3.47.0
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual RTL support, SEO metadata, and professional formatting.

== Description ==

**SScribe — The Enterprise WordPress Page Export Solution**

Transform your WordPress site into professional documentation in minutes. SScribe is the most comprehensive WordPress page export plugin, trusted by agencies, enterprises, and developers worldwide for client handovers, compliance documentation, content audits, and translation workflows.

=== Why Choose SScribe? ===

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

=== Key Features ===

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
* Composer classmap autoloading with optimized class loading
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

=== Perfect For ===

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

=== Works With ===

* **Page Builders:** Elementor, Divi, WPBakery, Beaver Builder, Gutenberg, Classic Editor
* **Multilingual:** WPML (Polylang, TranslatePress, Weglot on roadmap)
* **SEO Plugins:** Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework
* **Hosting:** Shared hosting, VPS, dedicated servers, managed WordPress hosting (Kinsta, WP Engine, Cloudways, etc.)

=== Theme & Editor Independent ===

SScribe exports content from any theme and any editor. Whether you use Gutenberg blocks, Classic Editor, or page builders like Elementor and Divi, SScribe extracts your content cleanly and formats it professionally.

=== Is SScribe Free? ===

Yes. SScribe is 100% free and open source under GPL v2. No premium version, no feature gates, no cloud API required, no upsells. Built by **Simplix Innovations** for the WordPress community.

== Installation ==

= Automatic Installation =

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for "SScribe Export Site Pages"
3. Click **Install Now** then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Click **Activate** after installation completes

= FTP Installation =

1. Extract the plugin ZIP file
2. Upload the `sscribe-export-site-pages` folder to `/wp-content/plugins/`
3. Activate the plugin from the **Plugins** menu in WordPress

= Requirements =

* WordPress 6.0 or higher
* PHP 8.2 or higher
* Recommended: 256MB PHP memory limit for large exports

= First-Time Setup =

After activation, find **SScribe Export** in your WordPress admin menu. No additional configuration required — just select your pages and export!

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

= 3.47.0 =

* Critical: Fixed format names displaying as "0,1" instead of "docx,pdf" in export logs and admin UI
* Critical: Fixed PHP 8.5 deprecations — removed all ReflectionMethod::setAccessible() calls from test suite
* Bug: Fixed shared cron lock key (sscribe_cron_cleanup_lock) preventing session cleanup when exports also run cleanup
* Bug: Fixed ZIP export index lock permanently held on exception by wrapping in try/finally
* Bug: Fixed minified JS referencing non-existent element ID (#sscribe-status-label → #sscribe-status-text)
* Bug: Fixed invalid CSS var() syntax with space before parens in minified admin JS
* Bug: Fixed onPostTypeChange double-counting page totals when post_type="any"
* Bug: Fixed admin UI showing raw bytes (1234567) instead of formatted size (1.2 MB) for AJAX-refreshed history
* Bug: Fixed hardcoded WP_CONTENT_DIR upload path in PDF exporter breaking multisite/Bedrock configurations
* Bug: Fixed Apache 2.2-only .htaccess syntax in PDF temp directory to support Apache 2.4+
* Perf: Fixed render_vendor_dependency_notice instantiating SScribe_Diagnostics on every admin page (now cached with static)
* Perf: Fixed check_session_health doing full table scan on wp_options via JSON value LIKE search
* Sec: Fixed ReDoS vulnerability in button extraction regex pattern (possessive quantifiers)
* Sec: Added wp_reset_postdata() after WP_Query in page collector to prevent $post global pollution
* UX: Fixed .sscribe-export-bar .sscribe-time-estimate inheriting unwanted background/border from generic rule
* a11y: Added filename context to aria-label on export log buttons for screen reader users
* i18n: Added mb_strtoupper fallback for hosts without mbstring extension
* Compatibility: Added TOC TabLeader constant guard for prefixed PHPWord versions
* Misc: Readme synced to v3.47.0, changelog entries added, version sync script passes, zero test warnings

= 3.36.0 =

* Tests: Added comprehensive unit test coverage for 9 previously untested classes (Exporter, Zip Handler, Diagnostics, AJAX Handlers, Logger Enhanced, Export Format, Helpers, Image Processor, RTL Helper)
* Tests: Updated bootstrap with 12 new WordPress function mocks for proper test isolation
* Tests: Added wp-phpunit/wp-phpunit to require-dev dependencies
* Tests: Fixed test data to include all expected array keys (permalink, author, dates, word_count) eliminating undefined key warnings
* Tooling: Updated composer.json scripts, added ci:full workflow
* Deep-dive: Confirmed CSS !important, JS innerHTML, and @file_put_contents usage patterns are safe
* Quality: 338 tests, 763 assertions, zero new errors or failures introduced

= 3.35.1 =

* Security: Health check AJAX endpoint now enforces capability check (export_site_pages) for authenticated diagnostics
* Reliability: Batch processor caps in-memory error arrays at 50 entries to prevent OOM on 500+ page exports — errors beyond limit remain in disk-based export log
* Reliability: Image processor replaces `@getimagesize` error suppression with structured `set_error_handler()` logging
* Reliability: Container circular dependency exceptions now include the offending service key for faster debugging
* UX: Dark mode card contrast improved — primary-light opacity 0.08→0.15, checked-state ring 0.1→0.2
* Maintenance: Self-heal lock threshold increased from 60s to 300s to prevent false positives on slow servers
* Maintenance: Deactivation now cleans up all plugin options, transients, database tables, and export files
* Maintenance: Removed dead wizard CSS (130+ lines of unused styles for removed multi-step wizard UI)
* Maintenance: Removed class-sscribe-error.php PHPCS exclusion — file now fully compliant
* Maintenance: Trimmed readme.txt changelog to 10 recent versions for WordPress.org compliance
* Maintenance: Re-enabled composer `platform-check` for accurate PHP version validation
* Build: Regenerated minified assets (54KB CSS, 45KB JS)

= 3.34.0 =

* Security: Health check AJAX now applies rate limiting to authenticated requests to prevent abuse
* Security: Lock TTL increased from 30s to 45s to prevent false "batch already running" on slow PDF-heavy servers
* Debug: PDF exporter now logs DomPDF output buffer in debug mode for render troubleshooting
* Debug: Session get() now logs detailed debug info on not-found, unexpected type, and JSON decode failures
* Code quality: Fixed duplicate ajax_health_check() method causing PHP fatal error on test load
* Code quality: Fixed malformed ajax_finalize_export() with orphaned code outside method body
* Code quality: Full test suite passes (193 tests, 494 assertions); LSP diagnostics clean

= 3.33.2 =

* Fix: DOCX content was not being generated — cover page only (~17KB files)
* Fix: ZIP subfolder structure misinterpreting page slugs like SD-AR as language codes

= 3.33.1 =

* Fix: Wizard initialization now shows Post Type panel (step 0) on load
* Fix: Hardened export finalization with try/catch to prevent silent crashes at 96%
* Fix: Deferred session deletion until after successful response, allowing "Try Again" to work after crashes
* Fix: Added empty ZIP validation to catch packaging failures with clear error messages
* Fix: Support Information panel now loads gracefully even if dependencies fail
* Fix: Replaced filesize() with wp_filesize() for safer file size checks

= 3.33.0 =

* Feature: Post type selection - export Pages, Posts, or Both
* Feature: Added Step 0 wizard for post type selection in admin UI
* Feature: Added dynamic post counts per post type (pages, posts, both)
* Feature: Added onPostTypeChange handler to refresh counts when post type changes
* Feature: Added post_type parameter to all page collector methods
* Feature: Added resolve_post_type_for_query() helper for WP_Query compatibility
* Feature: Added updateStatusCounts() JS method for cleaner code organization
* Feature: AJAX endpoint now accepts post_type parameter for status counts
* Feature: Export preview now includes post_type in the request
* Enhanced: Updated wizard navigation to handle 4 steps with post type as Step 0
* Enhanced: Updated export button to require post type selection before enabling
* Enhanced: get_page_data() now accepts post_type parameter for post support
* Enhanced: get_child_pages_batch() and get_child_pages() now support post type filtering
* Enhanced: get_post_status_counts() now accepts post_type parameter
* Enhanced: get_total_all_statuses() and get_page_count_only() now support post types
* Enhanced: Page Status section now shows combined counts for "Both" post type
* Enhanced: All languages card shows combined page+post count when "Both" selected
* Quality: Fixed duplicate get_child_pages_batch() method that was causing fatal errors
* Quality: Fixed missing get_breadcrumbs() method that was causing PHPStan errors
* Quality: Added translators comment for proper internationalization
* Quality: PHPUnit 193 tests passing
* Quality: PHPStan 0 errors
* Quality: PHPCS 68/68 compliant

= 3.32.0 =

* Compliance: Replaced JSON column types with LONGTEXT for MySQL 5.6 compatibility
* Compliance: Replaced INDEX with KEY in dbDelta SQL for cross-database compatibility
* Compliance: Multisite-aware uninstall with switch_to_blog loop for network cleanup
* Compliance: Added missing uninstall cleanup for sscribe_sessions table and schema version
* Compliance: Replaced header() redirect with wp_safe_redirect()
* Compliance: Standardized readme.txt heading format for WordPress.org parser
* Compliance: Replaced manual cron unschedule with wp_clear_scheduled_hook()
* Build: Excluded build/ and WPScan/ directories from PHPCS scanning
* Build: Production release package excludes all development and test files

= 3.31.0 =

* Security: AJAX endpoints now verify capability before nonce to fail fast on unauthorized requests
* Security: Session signing key throws RuntimeException when no key material available
* Security: Removed unsafe-inline from CSP script-src directive
* Security: Batch processing lock verifies set_transient return and fails securely
* Architecture: Container adds circular dependency detection with re-entrancy guard
* Architecture: Container validates factory return types
* Architecture: Autoloader adds in-request cache to eliminate repeated file_exists calls
* Architecture: Shared logger helpers extracted into trait to eliminate duplication
* Architecture: Logger request ID generation unified to use cryptographically secure random_int
* Performance: Dedicated ssessions table replaces wp_options storage
* Performance: Added index on export_session_id in stats table
* Performance: DomPDF output streams directly to file instead of loading into memory
* Performance: Cron overlap protection prevents concurrent cleanup jobs
* Quality: Replaced direct error_log calls with structured logger
* Quality: Version-aware upgrade system with incremental schema migrations
* Feature: Structured JSON logger for production observability
* Feature: SSCRIBE_DEBUG_PUBLIC constant for independent admin debug control

= 3.30.13 =

* Fixed: Recent Exports now shows the correct language badge (AR, HE, etc.) instead of always showing "EN" — language metadata is now stored in the export index
* Fixed: Download, trash, and log icons in Recent Exports no longer return 404 — corrected icon URL path from admin/img/ to assets/icons/
* Fixed: Enterprise-grade PDF error reporting — structured diagnostics with error category, severity, fix steps, and technical context (memory, HTML size, libxml errors) now surface in both the Export Log viewer and error UI
* Improved: Recent Exports list now uses the export index as authoritative data source instead of unreliable filename parsing
* Improved: PDF exporter returns detailed failure context including HTML size, memory usage, DomPDF details, and libxml parsing errors
* Improved: Batch processor propagates structured errors with category, severity, and actionable fix steps through the entire pipeline

= 3.30.12 =

* Fixed: Arabic/RTL text in DOCX exports now renders correctly with complex script font support — no more square characters in Microsoft Word
* Fixed: ZIP structure now organizes multi-language exports into FORMAT/LANG/ subfolders (e.g., DOCX/AR/P001-Title.docx) instead of flat file lists
* Fixed: ZIP finalization no longer times out with large PDF-heavy exports — time limit increased to 300 seconds
* Improved: `with_complex_script()` helper ensures all DOCX text elements (cover page, breadcrumbs, headings, body) use the correct font for Arabic, Hebrew, and Farsi

= 3.30.5 =

* Fixed: PHPCS compliance — added missing doc comments and translators notes for RTL/Arabic code
* Fixed: PHPCS alignment and spacing issues in exporter classes
* Fixed: Added phpdocs for image processor silenced functions
* Fixed: Resolved all CI lint warnings for zero-issue production release
* Improved: Complete plugin audit passed — spelling, version sync, security, WordPress compliance

= 3.30.0 =

* New: Full RTL/Arabic language support with Arabic word segmentation and RTL helper
* New: Image processing and optimization for exported documents
* New: Streaming DOCX generator for memory-efficient large exports
* New: UTF-8 BOM support for RTL markdown files
* New: Arabic font integration (NotoSansArabic) for DOCX exports
* Improved: HTML exporter now supports RTL direction with proper styling
* Improved: PDF exporter supports RTL content with Arabic fonts
* Improved: Markdown exporter adds BOM marker for RTL language compatibility
* Improved: .gitignore updated to exclude agent metadata directories

== Upgrade Notice ==

= 3.47.0 =

Critical bug fixes: format names display, PHP 8.5 compatibility, cron lock conflict, ZIP lock safety, CSS/JS fixes, accessibility improvements. Zero test warnings. Recommended update for all users.

= 3.36.0 =

Comprehensive test coverage added for 9 previously untested classes. 338 tests, 763 assertions. Recommended update for all users.

= 3.35.1 =

Security: Health check AJAX endpoint now enforces capability check for authenticated diagnostics. Reliability: Batch processor caps in-memory error arrays at 50 entries to prevent OOM on large exports. Recommended update for all users.

= 3.33.0 =

New: Post type selection — export Pages, Posts, or Both with dynamic counts per type. Enhanced batch processing, status filtering, and wizard navigation. Recommended update for all users.

= 3.32.0 =

Compliance: MySQL 5.6 compatibility (LONGTEXT replaces JSON columns), multisite-aware uninstall, wp_safe_redirect, and schema version cleanup. Recommended update for all users.

= 3.31.0 =

Security: AJAX capability verification before nonce, session signing key enforcement, CSP unsafe-inline removal. Architecture: DI container with circular dependency detection, dedicated ssessions table, structured JSON logger. Essential update for all users.









