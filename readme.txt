=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, pdf, multilingual, rtl
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.9.7
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
* PHPStan static analysis (level 6)
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

= 3.9.7 =

* Critical: HTML exporter kses allowlist only allowed media elements (video/audio/iframe/canvas/svg) — all standard WordPress content (p, div, h1-h6, a, img, ul, ol, li, table, blockquote) was stripped to plain text. Changed to wp_kses_allowed_html('post') merged with media elements.
* Critical: JS batch processor timeout (120s) matched PHP execution window — race condition produced false network errors when PHP used the full window. JS timeout increased to 180s with 60s grace window.
* Critical: RTL DOCX exporter referenced Noto Sans Arabic font removed in v3.7.6. Arabic/Hebrew/Persian DOCX exports silently rendered without the font. Changed to universally-available Arial.
* Critical: Deactivator called cleanup_export_files() on deactivation, destroying all exports and logs on temporary deactivation (troubleshooting/staging). Export cleanup moved exclusively to uninstall.php.
* Fix: Session lock used set_transient() which on Redis/Memcached (persistent object cache) is wp_cache_set() — NOT atomic. Changed to dual mechanism: wp_cache_add() on persistent cache (atomic SET NX), set_transient() on MySQL (atomic INSERT).
* Fix: ZIP lock acquisition failure returned unindexed exports — file existed on disk but download handler rejected it. Added exponential backoff retry (100ms→200ms→400ms) and always indexes the file.
* Fix: Rate limit error message claimed "60 requests per minute" when actual limit was 200. Sync'd message to correct value.
* Fix: Diagnostics hook counting used has_action() which always returns true because WP core always registers admin_menu callbacks. Changed to class/method existence checks for truthful bootstrap verification.
* Fix: Baseline time estimates inconsistent across PHP (1.5s), JS (1.2s), and template (~1.2s) for DOCX. All synced to 1.5s.
* Fix: PDF max_html_size limit error omitted filter name for raising the limit. Added "sscribe_pdf_max_html_size" to the message.
* Fix: Image processor silently dropped external CDN images (Cloudinary, AWS S3, Bunny, imgix) in PDF exports. Added sscribe_allowed_image_hosts filter with CDN whitelist support.
* Fix: $_GET['activate-multi'] accessed without sanitization in redirect handler. Added sanitize_key() consistency.
* Fix: Status cards no loading feedback during refreshStatusAndLanguageCounts(). Added .sscribe-loading class indicator.
* Fix: Removed unused SScribe_Deactivator::cleanup_export_files() method — dead code after deactivation fix.
* Fix: Added content output tests for HTML and Markdown exporters — asserts p, h1, a, img, ul/li, frontmatter, and content structure survive export.
* Fix: Added wp_kses_allowed_html(), wp_kses(), wp_filter_content_tags(), number_format_i18n(), wp_using_ext_object_cache() stubs to test bootstrap for integration tests.
* Perf: Upgrade notice bumped to reflect all 13 audit-fix patches. 340 PHPUnit tests passing, PHPStan Level 6 clean, PHPCS clean.
* Fix: Fixed docblock alignment in class-sscribe-html-exporter.php (PHPCBF auto-fix)
* Fix: Added missing WordPress function ignores to phpstan.neon (esc_sql, is_multisite, switch_to_blog, restore_current_blog)
* Fix: Added esc_sql() standalone function stub and $wpdb->posts property to test bootstrap for PHPUnit compatibility

= 3.9.5 =

* Fix: Added missing closing &lt;/div&gt; for #sscribe-tab-history — Support tab was inaccessible when History tab display:none was applied
* Fix: Removed duplicate id="sscribe-preview-desc" from History tab callout section
* Fix: Replaced direct $this-&gt;logger-&gt; calls with $this-&gt;get_logger()-&gt; in SScribe_Exporter::add_cover_page() and add_featured_image() to prevent null pointer fatal errors
* Fix: Removed duplicate var self = this; in showPreview() and renamed inner e parameter to ev to prevent shadowing
* Fix: Added sscribe/mpdf-tmp/ directory cleanup to SScribe_Deactivator and uninstall.php
* Fix: Pass $user_id to cleanup_user_locks() in force-clear path to prevent orphaned lock transients
* Fix: HTML export images now use HTTP URL instead of file:// for standalone portability
* Fix: Added check_rate_limit() to ajax_download() for consistent rate limiting across all AJAX handlers
* Fix: Implemented log rotation at 10MB in SScribe_Logger — rotates to timestamped backup instead of silently discarding entries
* Fix: SScribe_Session::update() lock acquisition uses transient with retry loop for multi-process safety
* Fix: get_breadcrumbs() uses batch get_posts() to avoid N+1 queries on WPML sites
* Fix: Added ".." guard to is_path_in_scope() to prevent path traversal attacks
* Fix: Auto-enable chunked page loading when total pages exceeds 500 to prevent memory exhaustion
* Fix: Expanded kses allowlist in HTML exporter to preserve video, audio, iframe, canvas, svg elements
* Fix: Added export_posts and publish_posts to SScribe_Capabilities::ALLOWED array
* Fix: Added PHPWord version-aware handling in safe_text() to prevent double-encoding
* Fix: Added ARIA tab roles (role="tab", role="tablist", role="tabpanel") and aria-selected state management for accessibility
* Fix: SScribe_Export_Log::cleanup_old_logs() increased default from 2h to 6h and skips active logs (status: started/processing/finalizing)

= 3.9.4 =

* Sec: Fixed PHP object injection risk in migrate_legacy_session() — replaced maybe_unserialize() with unserialize($raw, ['allowed_classes' => false])
* Sec: Wrapped all SVG icon output in wp_kses_post() for explicit WordPress.org escaping compliance
* Fix: Updated .distignore to exclude LICENSE*, CREDITS.txt, composer.json, composer.lock, ruleset.xml from distribution
* Fix: Updated build-release.php to prune COPYING.LESSER, LICENSE, .github_changelog_generator from vendor-prefixed

= 3.9.3 =

* Fix: Version synchronization across all source files
* Fix: Stale version references cleaned up

= 3.8.6 =

* CHANGED: Arabic PDF rendering — DejaVu Sans → XB Riyaz for superior Arabic typography. XB Riyaz is purpose-built for Arabic script with professional letterforms, already bundled with mPDF (zero additional ZIP size).
* CHANGED: PDF exporter default_font for RTL changed from `dejavusans` to `xbriyaz`; fonttrans mappings redirected accordingly
* CHANGED: SScribe_Font_Helper now returns XB Riyaz instead of DejaVu Sans
* All 339 PHPUnit tests passing, PHPStan level 6 clean, PHPCS clean

= 3.7.6 =

* CRITICAL: Arabic PDF rendering — replaced NotoSansArabic with DejaVu Sans (bundled with mPDF). NotoSansArabic contains OpenType MarkGlyphSets that mPDF 8.x cannot process, causing ~96% failure on Arabic PDF exports. DejaVu Sans has zero MarkGlyphSets issues and supports full Arabic Unicode range.
* REMOVED: `SSCRIBE_FONT_ARABIC` / `SSCRIBE_FONT_ARABIC_BOLD` constants and NotoSansArabic font files (~488 KB) from plugin distribution
* CHANGED: HTML exporter no longer embeds NotoSansArabic @font-face — uses system font stack for RTL preview
* CHANGED: SScribe_Font_Helper now points to DejaVu Sans (vendor-prefixed/mpdf) for font path resolution
* All 339 PHPUnit tests passing, PHPStan level 6 clean, PHPCS clean

= 3.7.5 =

* PRODUCTION RELEASE: Bloat stripped — ttfonts/ reduced from 83 files (87 MB) to 36 files (19 MB); only DejaVu family (Sans/Condensed/Serif/Mono), Free family (Sans/Serif/Mono), and OCR-B retained
* PRODUCTION RELEASE: All rare-script fonts removed (CJK, Thai, Lao, Khmer, Myanmar, Ethiopic, Cherokee, Tibetan, ancient scripts, Hebrew, Syriac, Sinhala, Indic, etc.) — fonttrans maps xbriyaz/lateef/KFGQPC to NotoSansArabic
* PRODUCTION RELEASE: .distignore enhanced with 18 new segment-level patterns for vendor-prefixed dev file exclusion (composer.json, phpstan.neon, phpunit.xml, .gitattributes, CREDITS.txt, ruleset.xml, etc.)
* PRODUCTION RELEASE: build-release.php font_excludes expanded from 20 to 47 entries for future-proof ZIP bloat protection
* PRODUCTION RELEASE: Version bump to 3.7.3 — SSCRIBE_VERSION constant, plugin header, stable tag, CSS header, and .pot file all synced
* All 338+ PHPUnit tests passing, PHPStan level 6 clean, PHPCS clean

= 3.7.2 =

* FIX: Arabic PDF rendering — disabled mPDF autoLangToFont/autoArabic/autoScriptToLang which were overriding custom NotoSansArabic font with missing bundled fonts (xbriyaz/lateef)
* FIX: Arabic PDF rendering — merged default fontDir + fontData from mPDF config to restore fallback font chain (freeserif, dejavusanscondensed)
* FIX: Arabic PDF rendering — added fonttrans redirects for xbriyaz/lateef/arial to notosansarabic for robust CSS font-family resolution
* FIX: Arabic PDF rendering — improved LTR/RTL font stacks with multi-font fallback chains
* FIX: Arabic PDF rendering — restored bundled mPDF ttfonts/ (83 files) in vendor-prefixed for fallback font availability
* All 338+ PHPUnit tests passing, PHPStan level 6 clean, PHPCS clean

= 3.7.1 =

* CRITICAL: DOCX export — Arabic/RTL list items now render with correct complexScript font settings (bidi/rtl/complexScript) — previously showed squares in Microsoft Word
* CRITICAL: DOCX export — safe_text() preg_replace null return guard prevents TypeError crash on PCRE backtrack limit exhaustion
* HIGH: Content parser — Added safe_replace() wrapper guards all preg_replace calls against null return from PCRE backtrack/recursion limit exhaustion
* HIGH: Content parser — Button extraction now handles preg_match_all returning false (PCRE error) instead of silently dropping buttons
* HIGH: PDF export — Reordered NotoSansArabic font validation to happen before font file search, eliminating E_WARNING from scandir() on missing directory
* MEDIUM: HTML exporter — Removed unused $text_align variable (dead code)
* All 338+ PHPUnit tests passing, PHPStan level 6 clean, PHPCS clean

= 3.7.0 =

* PDF export: Font discovery now uses case-insensitive regex matching to handle Linux servers with case-sensitive filesystems
* PDF export: Added `find_font_file()` helper method for all font variants (Manrope R/B/M/L, NotoSansArabic R/B)
* PDF export: Enhanced error logging now includes directory contents when font validation fails
* Admin CSS: Modal content/header/body now have explicit fallback values for CSS custom properties
* Admin CSS: Added fallback `#FFFFFF` background to `.sscribe-modal-content` and `.sscribe-modal-body`
* Admin CSS: Added fallback `#F8FAFC` background and `#E2E8F0` border to `.sscribe-modal-header`
* Admin CSS: Added fallback box-shadow values to `.sscribe-modal-content`
* All 338 PHPUnit tests passing

= 3.52.0 =

* Security: Comprehensive zero-vulnerability security audit completed across entire codebase — all 37+ PHP files, 1,536-line JS file, and admin templates reviewed and passed
* Security: All 15 AJAX endpoints verified with wp_verify_nonce() + current_user_can() on every handler
* Security: 100% JSON-only session storage (no serialize/unserialize) — immune to PHP object injection attacks
* Security: SQL injection protection verified — all $wpdb queries use $wpdb->prepare() with proper %s/%d placeholders
* Security: Path traversal prevention confirmed — realpath() + scope validation on all file operations
* Security: SSRF protection verified — wp_http_validate_url() + scheme whitelist (http/https only) on all remote requests
* Security: IP addresses in audit trail hashed with HMAC-SHA256 and AUTH_SALT — GDPR compliant
* Security: sanitize_url() blocks javascript:, data:, vbscript:, file: schemes — only http, https, mailto, tel allowed
* Memory: Explicit unset() + gc_collect_cycles() after every DOCX page to prevent batch accumulation
* Memory: 5MB HTML size guard in PDF exporter prevents out-of-memory on oversized content
* Memory: validate_memory_availability() pre-flight check estimates memory needs before starting export
* Architecture: Batch processor processes pages in configurable chunks (max 20/page) preventing PHP timeouts
* Architecture: Dedicated sscribe_sessions database table replaces wp_options storage — eliminates autoload bloat
* Architecture: Dependency injection container with circular dependency detection and re-entrancy guards
* Performance: wp_reset_postdata() after every WP_Query prevents $post global pollution
* a11y: Screen reader aria-labels on all export log buttons and interactive elements
* i18n: mb_strtoupper() fallback for hosts without the mbstring extension

= 3.50.4 =

* Critical: Fixed format names displaying as "0,1" instead of "docx,pdf" in export logs and admin UI
* Critical: Fixed PHP 8.5 deprecations — removed all ReflectionMethod::setAccessible() calls
* Bug: Fixed ZIP export index lock permanently held on exception by wrapping in try/finally
* Bug: Fixed onPostTypeChange double-counting page totals when post_type="any"
* Bug: Fixed admin UI showing raw bytes instead of formatted size (1.2 MB) for AJAX-refreshed history
* Bug: Fixed hardcoded WP_CONTENT_DIR upload path in PDF exporter breaking multisite/Bedrock
* Bug: Fixed Apache 2.2-only .htaccess syntax to support Apache 2.4+
* Perf: Fixed check_session_health doing full table scan on wp_options via JSON value LIKE search
* Sec: Fixed ReDoS vulnerability in button extraction regex pattern (possessive quantifiers)

== Upgrade Notice ==

= 3.9.7 =

Comprehensive audit-fix release: 13 bug fixes including critical HTML content stripping, RTL DOCX font regression, deactivation data loss, session lock atomicity on Redis/Memcached, ZIP lock race creating orphaned exports, and JS/PHP timeout race condition. All 340 PHPUnit tests passing, PHPStan Level 6 clean, PHPCS clean. Recommended update for all users.

= 3.9.2 =

Complete UI/UX revamp with enhanced format selection cards, improved visual hierarchy, refined user experience, and detailed PCP compliance justifications. All existing functionality preserved. Recommended update for all users.

= 3.9.1 =

Complete UI/UX revamp with enhanced format selection cards, improved visual hierarchy, refined user experience, and detailed PCP compliance justifications. All existing functionality preserved. Recommended update for all users.

= 3.8.9 =

UI/UX revamp: improved admin interface with enhanced format selection, better visual hierarchy, and refined user experience. All existing functionality preserved. Recommended update for all users.

= 3.8.8 =

CSS compatibility fix: adds -webkit-backdrop-filter for Safari 9+ and iOS 9+ support. All declaration-no-important warnings reviewed and preserved where needed for admin UI override. Recommended update for all users.

= 3.8.7 =

PCP compliance update. Fixes: heredoc replacement, parse_url→wp_parse_url, direct file access protection, Domain Path header, rmdir→WP_Filesystem, DB prepared statements. load_plugin_textdomain required for non-wordpress.org hosted plugins. Recommended update.

= 3.7.6 =

Fixes Arabic PDF rendering failure caused by NotoSansArabic OpenType MarkGlyphSets incompatibility with mPDF 8.x. Switches to DejaVu Sans (bundled with mPDF) which has full Arabic Unicode support and zero MarkGlyphSets issues. Recommended update for all users exporting Arabic/RTL content to PDF.

= 3.7.2 =

Fixes Arabic PDF rendering (text showing as squares). mPDF auto-detection was overriding the custom NotoSansArabic font. All 83 bundled font files are now present in the prefixed build. Recommended update for Arabic/RTL users.

= 3.7.1 =

Fixes Arabic/RTL text rendering in DOCX list items (complex script font support), adds PCRE backtrack safety guards across all string processing, and removes dead code. Recommended update for all users with RTL/multilingual content.

= 3.7.0 =

Fixes 100% PDF export failure (root cause: missing fontDir), infinite retry loop on export failure, session cache consistency, and 5 other bugs. Strongly recommended for all users.

= 3.52.0 =

Security audit passed with zero vulnerabilities. Memory management hardened, batch processing improved, GDPR compliance enhanced. Recommended update for all users.

= 3.50.4 =

Critical bug fixes: format names display, PHP 8.5 compatibility, ZIP lock safety, CSS/JS fixes, accessibility. Recommended update for all users.












