=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, multilingual, seo, pdf, posts, pages
Requires at least: 6.0
Tested up to: 6.10
Stable tag: 3.6.4
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

= 3.6.4 =

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

= 3.6.4 =

Fixes 100% PDF export failure (root cause: missing fontDir), infinite retry loop on export failure, session cache consistency, and 5 other bugs. Strongly recommended for all users.

= 3.52.0 =

Security audit passed with zero vulnerabilities. Memory management hardened, batch processing improved, GDPR compliance enhanced. Recommended update for all users.

= 3.50.4 =

Critical bug fixes: format names display, PHP 8.5 compatibility, ZIP lock safety, CSS/JS fixes, accessibility. Recommended update for all users.












