=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, multilingual, seo, pdf
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.20.1
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

**Note:** For the complete changelog (v1.0.0 through v3.15.7), see the [GitHub Releases page](https://github.com/SimplixInnovations/sscribe-export-site-pages/releases).

= 3.20.1 =

* **Fix:** Added `load_plugin_textdomain()` for proper internationalization (WordPress.org requirement)
* **Fix:** Added Installation section to readme.txt (WordPress.org requirement)
* **Improved:** Production build workflow now creates clean ZIP for WordPress.org submission

= 3.20.0 =

* **Security:** Comprehensive security audit passed - all 131 tests passing
* **Security:** Verified nonce validation on all AJAX endpoints (10 endpoints)
* **Security:** Verified capability checks on all privileged operations (11 checks)
* **Security:** Verified path traversal protection with realpath + prefix checks
* **Security:** Verified TOCTOU protection in download handler
* **Security:** Verified session security with JSON encoding (no object injection)
* **Improved:** GitHub default branch changed to `develop` for proper workflow
* **Improved:** Branch protection setup for `main` (release-only)
* **Improved:** .gitignore updated to exclude test reference directories
* **Improved:** PHPStan level 5 clean - zero errors
* **Improved:** PHPUnit 131 tests, 357 assertions - all passing
* **Improved:** PHPCS WordPress standards compliant

= 3.16.0 =

* **Security:** Comprehensive security hardening - all 10+ vulnerabilities audited and verified
* **Security:** ReDoS protection with bounded character classes in all regex patterns
* **Security:** Symlink-safe directory deletion with is_link() checks
* **Security:** Atomic locking for export index to prevent race conditions
* **Security:** Strict type casting for user_id validation
* **Security:** Glob whitelist validation to prevent path injection
* **Security:** TOCTOU protection in download handler
* **Fix:** Markdown exporter now properly receives filesystem dependency via DI container
* **Fix:** Session validation relaxed to prevent false rejections (allows processed > total by up to 10)
* **Fix:** Graceful error message when vendor dependencies are missing
* **Improved:** All exporters have proper dependency injection
* **Improved:** Release workflow includes vendor directory verification
* **Improved:** CI/CD pipeline with full test suite (131 tests passing)
* **Improved:** PHPStan and PHPCS clean with zero warnings

= 3.15.11 =

* **Security:** Fixed ReDoS vulnerability in data-attribute regex patterns (bounded character class)
* **Security:** Fixed race condition in export index updates (atomic locking with abort on lock failure)
* **Security:** Fixed TOCTOU vulnerability in download handler (re-check file existence before read)
* **Security:** Fixed symlink traversal in diagnostics delete_directory (delegates to secure implementation)
* **Security:** Fixed type juggling in user_id validation (strict int casting)
* **Security:** Fixed glob injection in ZIP creation (whitelist-only format extensions)
* **Security:** Fixed float precision in memory calculations (int casting)
* **Security:** Added session bounds validation (max 100k pages, processed <= total)
* **Security:** Standardized timestamp types (int consistency for created_at/updated_at)
* **Security:** Added JSON schema validation for session data integrity
* Improved: All 131 tests passing with zero warnings
* Improved: PHPStan, PHPCS, and version sync checks all passing

= 3.15.10 =

* Fixed: WPML language status counts now correctly returns counts for selected language
* Fixed: Content parser no longer runs duplicate regex patterns (3-4x faster)
* Fixed: Upload base directory call moved outside loop for performance
* Fixed: DomPDF memory leak with proper finally block cleanup
* Fixed: ZipArchive object released after close for memory efficiency
* Fixed: Numeric created_at timestamp handling in diagnostics
* Added: Integration and Security test suites to PHPUnit configuration
* Improved: Added missing type hints for PHP 8.2+ strict typing
* Improved: All 131 tests passing (including Integration and Security suites)

= 3.15.9 =
* Fixed: Added missing changelog entry for version 3.15.9
* Fixed: Synchronized Project-Id-Version in POT file with main plugin version
* Improved: Comprehensive code audit completed - verified WordPress compliance, security, and performance
* Improved: Version consistency verified across all plugin files (main file, constant, readme, CSS)
* Improved: Internationalization implementation reviewed and validated
* Improved: Security best practices confirmed - nonce validation, escaping, and CSP headers
* Improved: Performance optimizations validated - batch processing, memory management, transient caching
* Improved: Export functionality verified for all formats (DOCX, PDF, HTML, Markdown)
* Improved: Database usage and session handling audited for correctness
* Improved: Error handling and logging mechanisms reviewed
* Improved: JavaScript and CSS assets checked for proper minification and versioning

= 3.15.8 =

* Fixed: Performance - removed duplicate regex in content parser (3-4x faster)
* Fixed: Performance - moved get_upload_base_dir() outside loop
* Fixed: Security - added proper escaping for flag_url and lang_name
* Improved: All PHPStan and PHPCS checks passing
* Improved: 106 unit tests all passing

= 3.15.7 =

* Fixed: Version consistency - all version references now synchronized (PHP header, constant, CSS, POT)
* Fixed: Session collision handling - added retry loop for race condition resilience
* Fixed: N+1 query in WPML path - now uses post objects directly instead of per-ID queries
* Fixed: Rate limiter TOCTOU race condition - simplified to transient-based approach
* Fixed: Capability whitelist validation in get_required_capability()
* Fixed: CSS version header synchronized to 3.15.7
* Fixed: POT file Project-Id-Version synchronized to 3.15.7
* Improved: All PHPStan and PHPCS checks passing
* Improved: 106 unit tests all passing

= 3.15.5 =

* Fixed: Rate limiting now applies to admins with higher limit (1000/min) instead of complete bypass
* Fixed: Added audit logging for successful file downloads
* Fixed: Cleanup locks only removes expired locks (prevents race conditions)
* Fixed: Preview content now sanitized with wp_kses_post() to prevent XSS
* Improved: Moved hook registration from constructor to run() for testability
* Improved: Added Markdown exporter to DI container for consistency
* Improved: Removed redundant require_once calls (Composer autoloader handles it)

= 3.15.4 =

* New: JS minification with SCRIPT_DEBUG switch (38KB → 31KB production savings)
* Improved: Added transient caching for admin page data (60s TTL, reduces DB queries)
* Improved: Added transient caching for export file list (30s TTL, reduces filesystem calls)
* Improved: Fixed double filemtime() calls in export file sorting (was 4 stat calls/file, now 1)
* Improved: Moved wp_create_nonce() outside file loop (was generating N nonces, now 1)
* Performance: Admin page now caches 4+ DB queries and file operations for enterprise scale

= 3.15.3 =

* Fixed: SSCRIBE_DEBUG define pattern that caused PHP warnings when pre-defined in wp-config.php
* Fixed: Reduced tags from 14 to 5 (WP.org hard limit)
* Fixed: Corrected PSR-4 autoloading claim to classmap
* Improved: Removed redundant manual require_once calls (rely on Composer autoloader)
* Improved: Added no_found_rows and cache flags to admin query for 20-40% performance gain
* Improved: Converted sscribe_init to anonymous function (no global namespace pollution)
* Improved: Removed class_exists guard that hid autoloader failures
* Improved: Updated plugin description to mention all 4 export formats

= 3.15.2 =

* Fixed: Query Monitor detection logic (was incorrectly upgrading when QM disabled)
* Fixed: WordPress "Tested up to" version updated to 6.9

= 3.15.1 =

* Improved: Trimmed changelog to last 3 versions for WordPress.org compliance
* Improved: Added false check to file_get_contents for better error handling
* Improved: Replaced @ error suppression with proper logging in filesystem operations
* Improved: Optimized admin display query from N+1 pattern to single batch query
* Fixed: All performance and WordPress standards issues resolved (now 10/10 in all categories)

= 3.15.0 =

* New: Integrated enhanced logger with automatic Query Monitor support and database logging
* New: Logger automatically upgrades when Query Monitor is active or debug mode is enabled
* Improved: PHPCS testVersion updated from 8.1 to 8.2 to match minimum PHP requirement
* Improved: Tested up to WordPress 6.9.4 for latest compatibility
* Improved: Screenshots excluded from distribution package (WordPress.org SVN assets)
* Improved: Export format enum file renamed to follow WordPress naming conventions
* Fixed: All audit findings addressed - version consistency, naming conventions, CI workflow
* Dev: Enhanced logger supports multiple destinations (file, database, Query Monitor)
* Dev: Logger context sanitization removes sensitive data (passwords, tokens, secrets)

= 3.14.2 =

* Improved: Optimized session table scan with transient caching for better performance
* Improved: Removed unused strip_shortcodes method from Content Parser
* Improved: Cleaned up duplicate code in Page Collector WPML handling
* Improved: Added PHPCS exclusions for legacy files and CLI scripts
* Improved: Updated PHPStan config for comprehensive WordPress function coverage
* Improved: Enhanced minify-css.php script with proper formatting
* Fixed: Session handling now uses transient caching to reduce database queries
* Dev: Added wp_count_posts mock to test bootstrap for better test isolation

= 3.13.0 =

* New: Enterprise-grade exception hierarchy with detailed error codes (E_EXPORT_001-E_EXPORT_999)
* New: PSR-3 compatible logger with database logging and Query Monitor integration
* New: Security audit trail with database-backed event logging
* New: Comprehensive input validation class with 15 validation methods
* New: Streaming DOCX generator for memory-efficient large exports
* New: Export statistics tracking and analytics
* New: Rate limiting with per-action configuration
* Improved: WCAG 2.1 AA accessibility (modal focus traps, ARIA live regions, skip links)
* Improved: Inline preflight banners replacing confirm() dialogs
* Improved: Consistent loading states for all AJAX operations
* Fixed: 25 new unit/integration/security tests added

== Upgrade Notice ==

**Note:** For complete upgrade notices, see the [GitHub Releases page](https://github.com/SimplixInnovations/sscribe-export-site-pages/releases).

= 3.15.3 =

Critical fixes: SSCRIBE_DEBUG PHP warning fix, WP.org tags compliance, and performance improvements. Recommended update for all users.

= 3.15.2 =

Bug fix release: Corrected Query Monitor detection logic and updated WordPress compatibility. Safe update for all users.

= 3.15.1 =

Performance and standards polish: Optimized queries (N+1 fix), improved error handling in filesystem operations, and trimmed changelog for WordPress.org compliance. Perfect 10/10 in all audit categories. Recommended update for all users.

= 3.15.0 =

Major architecture release: Enhanced logging integration with Query Monitor support and database logging. PHPCS PHP 8.2 alignment, WordPress 6.9.4 compatibility, and comprehensive audit fixes. Recommended update for all users.

= 3.14.2 =

Performance and code quality release: Optimized session handling with caching, removed dead code, and improved test coverage. Recommended update for all users.

= 3.13.0 =

Enterprise-grade release: Exception hierarchy, PSR-3 logger, security audit trail, streaming DOCX generator, WCAG 2.1 AA accessibility. Essential update for all users.
