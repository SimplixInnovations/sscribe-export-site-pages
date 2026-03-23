=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, word, documentation, multilingual
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.7.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.

== Description ==

Turn your entire WordPress site into professional documentation in minutes.

**SScribe – Export Site Pages to Word Documents** by **Simplix Innovations** scans your pages, detects languages (including Arabic and RTL via WPML), and generates a beautifully formatted Microsoft Word DOCX document for each page, bundled into a secure ZIP file.

Perfect for client handovers, legal reviews, content audits, translation workflows, offline approvals, or compliance documentation – without changing your theme, editor, or SEO stack.

= Key Features =

✅ **Works with Any Theme & Editor**
Export content from Classic Editor, Gutenberg, or page builders – completely theme-independent.

✅ **Multilingual & RTL Support**
Currently integrates with WPML to detect active languages and export one language at a time, including full right-to-left languages like Arabic. Roadmap: additional multilingual plugin support (Polylang, TranslatePress, Weglot).

✅ **Beautiful DOCX (Word) Documents**
Each page becomes a Microsoft Word-compatible DOCX with clean typography, proper headings, and smart handling of lists, tables, and links. Roadmap: PDF and combined site document formats.

✅ **SEO-Aware Exports**
Pulls meta title, description, and focus keyword from popular SEO plugins into a dedicated SEO section:
- Yoast SEO
- Rank Math
- All in One SEO (v3 & v4)
- SEOPress
- The SEO Framework

✅ **Rich Document Structure**
Every document includes:
- Professional cover page with title, URL, language, date, breadcrumbs
- Featured image embedded at top
- Page info table (author, dates, word count, reading time)
- SEO section (meta title, description, focus keyword)
- Full content with H1–H6 headings, paragraphs, lists, blockquotes, code blocks
- HTML tables converted to formatted DOCX tables
- Smart link handling with visible URLs
- Button detection with destination URLs
- Child pages list for site structure
- Header & footer with site name, page title, and page numbers

✅ **Secure ZIP Packages**
All DOCX files bundled into one ZIP, stored temporarily and auto-deleted after 1 hour for security.

✅ **Performance-Safe Processing**
Pages processed in small batches to avoid timeouts on shared hosting or high-traffic servers.

= What Each Document Includes =

🎨 **Professional Cover Page** – Large title, URL, language, date, breadcrumb path
🖼 **Featured Image** – Embedded full-width at top of content
📋 **Page Info Table** – URL, author, published, modified, word count, reading time
🔍 **SEO Section** – Meta title, description, focus keyword (from supported SEO plugins)
🗺 **Breadcrumb Trail** – Full hierarchy: Home › Parent › Page
📄 **Full Page Content** – All text, headings H1–H6, paragraphs
🔗 **Smart Links** – Internal/external links clearly labeled with URLs shown
🔘 **Button Detection** – Buttons rendered as styled blocks with destination URL
📊 **HTML Tables** – Converted to professional formatted DOCX tables
🔢 **Nested Lists** – Bullet + numbered lists with up to 3 nesting levels
💬 **Blockquotes** – Styled with left border and italic formatting
💻 **Code Blocks** – Monospace, background-tinted code sections
📁 **Child Pages** – Linked list of sub-pages at bottom of document
📑 **Header & Footer** – Site name + page title header; URL + page number footer
🚫 **Shortcode Handling** – Shortcodes cleanly stripped with placeholder marker

= Document Format (DOCX) =

- Professional sans-serif typography (Arial default, customizable via filters)
- US Letter (8.5 × 11 in) or A4 page size
- 1-inch margins with header/footer space
- 6 styled heading levels
- Auto page numbering
- Clickable hyperlinks
- Microsoft Word, Google Docs, and LibreOffice compatible

= Multilingual & Arabic Support =

- Automatically detects **WPML** and lists all active languages
- Export **one language at a time** – ideal for agencies preparing English and Arabic documentation separately
- Works with RTL languages like **Arabic**, provided your WordPress install and theme support RTL
- Document content respects text direction so Arabic content remains readable in Microsoft Word
- **Roadmap:** Support for Polylang, TranslatePress, Weglot, and other multilingual plugins

= SEO Plugin Support =

Reads metadata from these popular SEO plugins:
- Yoast SEO
- Rank Math
- All in One SEO (v3 & v4)
- SEOPress
- The SEO Framework

If multiple SEO plugins are active, uses priority order and clearly labels the source.

= Performance & Security =

- Pages processed in **small batches** (default: 3 at a time) to avoid PHP timeouts
- Generated ZIP files stored in `wp-content/uploads` in a dedicated, non-indexed folder
- **Auto-deleted after 1 hour** for security

= Use Cases =

✔️ **Agency Client Handovers** – Deliver professional site documentation
✔️ **Legal & Compliance** – Offline archives for regulatory review
✔️ **Content Audits** – Review all pages in Word format
✔️ **Translation Workflows** – Export per language for translators
✔️ **Offline Approvals** – Share formatted docs with stakeholders without WordPress access
✔️ **Site Migrations** – Document current state before moving platforms

= Works With =

- **Editors:** Classic Editor, Gutenberg, Elementor, Divi, WPBakery, Beaver Builder
- **Multilingual:** WPML (more coming soon)
- **SEO:** Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework
- **Hosting:** Works on shared hosting, VPS, dedicated, and managed WordPress hosting

= Is SScribe Free? =

Yes. SScribe is 100% free and open-source, developed by **Simplix Innovations**. No premium version, no feature gates, no cloud API required.

= Roadmap =

🚀 **Coming Soon:**
- Export Posts, Custom Post Types, and WooCommerce Products
- PDF export format
- Combined "full site" document option
- Custom branding (logo, colors, fonts in templates)
- Per-language template optimization (e.g., Arabic-optimized layouts)
- Additional multilingual plugin support (Polylang, TranslatePress, Weglot)

== Installation ==

1. Upload the `sscribe-export-site-pages` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Tools → SScribe Export** in your WordPress admin
4. Select language (if WPML active), choose options, and click "Generate Documentation Package"
5. Download your ZIP file with one DOCX per page

== Frequently Asked Questions ==

= Does it support Arabic and other right-to-left languages? =

Yes. When used with WPML and a theme that supports RTL, the plugin exports Arabic and other RTL languages into DOCX files that preserve right-to-left reading order in Microsoft Word and compatible editors.

= Which content types are supported in version 1? =

Version 1 focuses on **Pages only** to guarantee stability and performance. Support for Posts, Custom Post Types, and WooCommerce Products is planned for future releases.

= Does SScribe work with Elementor, Divi, or WPBakery? =

Yes. SScribe exports content from any theme and page builder including Elementor, Divi, WPBakery, Beaver Builder, and Gutenberg.

= Which SEO plugins are supported? =

It currently reads SEO metadata from Yoast SEO, Rank Math, All in One SEO (v3 & v4), SEOPress, and The SEO Framework.

= Can I use SScribe to create client documentation? =

Absolutely. SScribe is designed for agency workflows. Export every page into a professionally formatted Word document package that's ready to deliver to clients.

= Are my documents and ZIP files secure? =

Generated DOCX files are saved inside your site's uploads directory and bundled into a ZIP file which is automatically deleted after 1 hour. Only users with access to your WordPress admin and file system can access the ZIP during that window.

= Can I export all languages at once? =

Version 1 exports one language at a time for cleaner processing and organization. Multi-language batch export is on the roadmap.

= Does it work on shared hosting? =

Yes. The plugin processes pages in small batches (default: 3 at a time) to avoid PHP timeout limits on shared hosting environments.

= Will it slow down my site? =

No. The export process runs only when you manually trigger it from the admin area. It has zero impact on front-end performance.

= Can I customize the document template? =

Version 1 uses a professional default template. Future versions will include customization options for branding (logo, colors, fonts) via WordPress filters and settings.

= Is SScribe free? =

Yes. SScribe is 100% free and open-source, developed by Simplix Innovations. No premium version, no feature gates, no cloud API required.

== Screenshots ==

1. Admin interface showing language selection and export options
2. Progress indicator during batch processing
3. Example DOCX output showing cover page with branding
4. Example page content with styled headings, tables, and links
5. SEO section showing metadata from Rank Math

== Changelog ==

= 1.7.1 =
* Fix: Plugin header now displays correctly with proper glassmorphism effect
* Fix: "Post Status" label changed to "Page Status" for accuracy
* Fix: Status options with zero pages are now disabled and cannot be selected
* Fix: "What Each Document Includes" section now shows colored icon backgrounds
* Fix: Cancel export button styling improved
* Fix: JavaScript now updates disabled state when language selection changes
* Fix: PHP CodeSniffer compliance — replaced unlink() with wp_delete_file()
* Fix: PHP CodeSniffer compliance — added ignore for ini_set() usage
* Fix: PHP CodeSniffer compliance — prefixed all internal variables with sscribe_

= 1.7.0 =
* Critical fix: Export now reliably processes ALL pages — replaced transient-based session storage (corrupted by caching plugins) with file-based JSON storage
* Critical fix: Session data now immune to Redis/Memcached key limits, WP Rocket purges, LightSpeed optimization, and Cloudflare cache
* New: Post status filter — export Published, Draft, Private, Scheduled, Pending, or All pages
* New: "All Languages" option — export pages from all WPML languages in one operation
* New: Export settings UI with post status dropdown selector
* New: Scheduled hourly cleanup for expired session files (4-hour TTL)
* Improvement: Better error messages when session data is corrupted or expired
* Improvement: Export success message now shows error count when applicable

= 1.6.0 =
* Critical fix: Export now completes fully for all pages — fixed output buffer (ob_start) nesting that was destroying WordPress's own buffers and corrupting AJAX JSON responses
* Critical fix: Removed unnecessary ob_start() from ajax_start_export() which caused buffer level imbalances
* Critical fix: Output buffer restoration now uses saved-level approach (while ob_get_level() > $saved) instead of ob_get_level() > 0 which was blindly closing WordPress core buffers
* Critical fix: Arabic/Hebrew page filenames now human-readable — uses title transliteration instead of URL-encoded slug which produced hex strings like 'd8b9d986...'
* Fix: Page collector ob_start() now also uses saved-level approach for safe nesting
* Fix: ob_start/ob_end now properly paired — no more orphaned buffers across multiple AJAX requests

= 1.5.0 =
* Critical fix: DOCX files now open correctly in Word — added XML non-character stripping (U+FFFE, U+FFFF, surrogates)
* Critical fix: Export no longer stops mid-way on large sites — output buffering prevents Elementor stray HTML from corrupting AJAX JSON
* Critical fix: Session transient extended to 4 hours and refreshed per batch — exports of 100+ pages complete fully
* Fix: Replaced @set_time_limit() with ini_set() — removes PHPCS Squiz.PHP.DiscouragedFunctions warning
* Fix: Icon font characters (FontAwesome, Eicons) stripped from DOCX content — no more boxes/corruption
* Fix: Zero-width spaces and invisible formatting characters stripped — no more empty paragraphs
* Fix: HTML comments stripped in content parser — Elementor template data no longer bleeds into text
* Fix: CSS variable declarations that survive style tag stripping are now cleaned from content

= 1.4.1 =
* Fix: Added PHPCS ignore for set_time_limit() warning (function is safe for batch processing)
* Fix: Corrected "Tested up to" version format to 6.9 (WordPress.org requires major.minor only)

= 1.4.0 =
* Critical fix: Pages now export completely (106+ pages) — fixed PHP timeout on Elementor pages by reducing default batch size to 1 and adding per-batch time limit extension
* Critical fix: Static re-entry guard now uses try/finally to ensure it always resets even when apply_filters throws an exception
* Critical fix: Elementor inline CSS no longer appears as text in exported documents — style/script/svg blocks are now stripped before content parsing
* Critical fix: Cover page title no longer garbled for Arabic — removed mb_strtoupper() which is meaningless and harmful for RTL scripts
* Critical fix: ZIP download no longer reloads wrong page — fixed window.location.href download trigger replaced with hidden iframe
* Fix: DOCX filenames now use zero-padded sequential numbers (001-slug.docx) instead of raw WordPress post IDs
* Fix: Complete RTL paragraph direction support for all remaining document elements (cover page, tables, buttons, images)
* Fix: Arabic/RTL documents now correctly set default paragraph bidi direction

= 1.3.1 =
* Fix: Complete RTL bidi support for Arabic/Hebrew documents (cover page title, URL, breadcrumbs, horizontal rules, buttons, image placeholders, content tables)
* Fix: Removed dead get_download_url() method from zip-handler (security cleanup)
* Improvement: Consolidated changelog for clarity

= 1.3.0 =
* Updated: Tested up to WordPress 6.9
* Fix: Replaced esc_html__() with __() in PHPWord calls to prevent double-encoding in DOCX output
* Fix: Added RTL bidi support to info tables, SEO tables, cover page metadata, and heading styles
* Fix: Made test bootstrap version dynamic (reads from main plugin file)
* Improvement: Removed unnecessary screenshot-6 (5 screenshots sufficient)
* Improvement: Removed internal audit documents from public repo

= 1.2.1 =
* Fix: Added paragraph-level RTL bidi support for proper Arabic/Hebrew text rendering in Word
* Fix: Added explicit cell widths in render_table() for Google Docs/LibreOffice compatibility
* Fix: Added return statements after wp_send_json_error() for security robustness
* Fix: Validated language parameter against WPML active languages list
* Fix: Removed dead $is_header variable in parse_table()
* Fix: Improved shortcode stripping with WordPress strip_shortcodes() + conservative regex
* Fix: Restored libxml_use_internal_errors() state after DOM parsing
* Fix: Fixed indentation issues in class-sscribe.php
* Added: Screenshots folder with placeholder images for WordPress.org submission
* Updated: Tested up to WordPress 6.8

= 1.2.0 =
* Security: Fixed double-encoding issue in cover page title
* Security: Fixed transient deletion order in finalize_export() for better retry handling
* Security: Fixed Content-Disposition header injection vulnerability (RFC 5987 encoding)
* Security: Fixed path traversal vulnerability in url_to_local_path() with realpath() validation
* Security: Added image extension whitelist for local image processing
* Feature: Added RTL/BiDi support for Arabic, Hebrew, and other RTL languages
* Feature: Added `sscribe_batch_size` filter for developer customization
* Feature: Added `sscribe_export_capability` filter for multisite workflows
* Feature: Added `sscribe_page_data` filter for third-party data enrichment
* Feature: Added `sscribe_before_export_page` and `sscribe_after_export_page` action hooks
* Feature: Added `sscribe_docx_section_settings` filter for document customization
* Fix: Prevented DOCX filename collision by prefixing with page ID
* Fix: Added Unicode fallback for word count (Arabic, CJK support)
* Fix: Added setup_postdata() for page builder compatibility (Elementor, Divi, etc.)
* Fix: Added re-entry guard for the_content filter to prevent recursion
* Fix: Removed [H1] prefix from DOCX headings for cleaner output
* Fix: Internationalized hardcoded strings ('Path: ', 'EXTERNAL AUDIT AND DOCUMENTATION')
* Fix: Cached wp_upload_dir() calls for better performance
* Fix: Improved WPML language detection using wpml_get_active_languages()
* Fix: Added efficient get_page_count_only() method to avoid N+1 queries
* Fix: Internationalized hero description in admin interface
* Fix: Removed dead $export_url variable
* Improvement: Catch Throwable instead of Exception for better error handling
* Improvement: Added WP_DEBUG guard on error_log calls

= 1.1.3 =
* Fixed WordPress coding standards throughout codebase
* Fixed precision alignment and inline comment formatting
* Added cleanup commands to composer.json
* Updated phpstan configuration
* Added comprehensive code documentation

= 1.1.2 =
* Fixed precision alignment in multiple files
* Added doc comments to SEO plugin detection methods
* Excluded content-parser from variable naming rule (PHP DOM properties)

= 1.1.1 =
* Fixed PHPCS issues in exporter class
* Excluded PCLZip from PHPWord autoloader
* Escaped SVG data attributes for security
* Removed load_plugin_textdomain call

= 1.1.0 =
* Added PSR-4 autoloading via Composer classmap
* Added PHPUnit testing infrastructure with 16 unit tests
* Added PHPStan static analysis (level 5)
* Added GitHub Actions CI/CD pipeline for PHP 7.4-8.3
* Fixed WordPress coding standards throughout codebase
* Fixed missing $features array in admin display template
* Removed AGENTS.md from repository tracking

= 1.0.0 =
* Initial public release on WordPress.org
* Export all Pages to DOCX format
* WPML language detection and single-language export
* SEO plugin integration (Yoast, Rank Math, AIOSEO, SEOPress, SEO Framework)
* Professional DOCX template with cover page, page info, breadcrumbs
* Rich content support: headings, lists, tables, blockquotes, code blocks
* Smart link and button detection
* Secure ZIP packaging with 1-hour auto-deletion
* Performance-safe batch processing

== Upgrade Notice ==

= 1.6.0 =
Critical fixes for export completion and Arabic/non-Latin filename handling. Exports now complete fully without stopping mid-way, and filenames are now human-readable for all languages. Essential update for all users.

= 1.5.0 =
Critical fixes for DOCX corruption, large site exports, and Elementor compatibility. Essential update for all users — DOCX files now open reliably in Word.

= 1.4.1 =
Minor fix release: PHPCS compliance and corrected WordPress.org version format. Safe to skip if already on 1.4.0.

= 1.4.0 =
Critical bug fixes for large sites with Elementor: PHP timeout prevention, proper error handling, CSS stripping, RTL fixes, and sequential filenames. Essential update for all users.

= 1.3.1 =
Complete RTL/Arabic DOCX support with proper text direction in all document elements. Security hardening and code cleanup. Recommended for all users.

= 1.1.3 =
Maintenance release: Fixed coding standards, improved documentation, and updated development tooling. No user-facing changes.

= 1.1.0 =
Developer release: Added autoloading, testing infrastructure, static analysis, and CI/CD. No user-facing changes.

= 1.0.0 =
First release of SScribe – Export Site Pages to Word Documents. Export your entire site to beautiful DOCX files with multilingual and SEO support.
