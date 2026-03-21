=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Donate link: https://simplixi.com
Tags: export, docx, word, documentation, multilingual, page export, to pdf, export word, export site
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
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
6. ZIP download ready with secure auto-deletion notice

== Changelog ==

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

= 1.0.0 =
First release of SScribe – Export Site Pages to Word Documents. Export your entire site to beautiful DOCX files with multilingual and SEO support.
