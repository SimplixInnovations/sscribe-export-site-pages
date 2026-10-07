=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Tags: export, docx, pdf, html, markdown
Requires at least: 6.1
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual and RTL support.

== Description ==

SScribe turns WordPress pages into portable documents for content handovers, audits, translation work, and offline records.

= Export formats =

* **DOCX** for Microsoft Word, Google Docs, and LibreOffice
* **PDF** for a consistent portable document
* **HTML** as a single styled page with SEO meta tags
* **Markdown** with YAML front matter

= Highlights =

* Bounded AJAX batches designed for large sites
* Resumable export sessions with durable page queues
* WPML, Polylang, and TranslatePress language selection
* RTL output for Arabic, Farsi, Urdu, and other Arabic-script languages
* SEO metadata from Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO Framework
* Cover pages, headings, tables, lists, code blocks, and images
* Private archive and log storage outside public web directories
* Capability, ownership, nonce, and single-use download-token checks
* Automatic cleanup of expired archives and sessions

= Requirements =

* WordPress 6.1 or newer
* PHP 8.2 or newer
* PHP extensions: cURL, DOM, GD, XML, and ZIP
* Session encryption: Sodium, or OpenSSL with AES-256-GCM support
* 256 MB of PHP memory is recommended for large PDF exports

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
2. Select the SScribe ZIP file, install it, and activate the plugin.
3. Open SScribe Export in the WordPress admin menu.

== Frequently Asked Questions ==

= Which content can I export? =

SScribe exports WordPress pages, posts, and registered public custom post types. You can narrow an export by post type, post status, and, when WPML, Polylang, or TranslatePress is active, language.

= Does SScribe support RTL languages? =

Yes. The exporters detect RTL languages and apply direction-aware document structure and fonts. The admin interface also supports WordPress RTL mode. In RTL PDFs, links are shown as styled text and list bullets sit on the left; DOCX keeps both fully RTL.

= Does SScribe work with WPML, Polylang, or TranslatePress? =

Yes. Export one language or all, with language metadata and RTL output. TranslatePress pages are translated into the selected language.

= Can a large export resume after the browser closes? =

Yes. Export progress and the complete normalized page-ID queue are stored in expiring, non-autoloaded WordPress options. Reopening the export can continue from the saved offset while the session remains valid.

= Where are exported files stored? =

Archives, temporary files, and logs use a site-isolated private directory outside WordPress and public upload paths. SScribe prefers validated PHP/operating-system temporary locations and can fall back to another validated non-public base or an administrator-defined private base. Existing archives from older versions are copied, hash-verified, and only then removed from the old location.

= How long are archives kept? =

Completed archives expire after 72 hours by default and are removed by scheduled cleanup. A site administrator can delete an archive sooner from Recent Exports.

= Who can download an export? =

Only an authenticated user with the delegated export capability can download an archive that user owns. Download links use single-use tokens tied to the archive record and never expose the storage path. The archive itself expires automatically according to the configured retention window.

= Does SScribe send content to an external service? =

No. SScribe does not send exported content to Simplix Innovations or another content-processing service. Local Media Library files are read from the site. Same-site images that cannot be resolved locally may be fetched through the WordPress safe HTTP API. These requests go from your WordPress server to your own configured site/media host and can expose standard HTTP request metadata, such as the server IP address, to that host. Other hosts are blocked by default. If a developer explicitly adds hosts through the `sscribe_allowed_image_hosts` filter, image embedding may send HTTP GET requests to those administrator-approved hosts; the site operator is responsible for reviewing the terms and privacy policy of any host they add.

= What happens when the plugin is uninstalled? =

Uninstall removes plugin options, scheduled hooks, saved export sessions and page queues, private archives and logs, and any recognized legacy export directory. Deactivation alone keeps data so exports can resume after reactivation.

== External services ==

SScribe does not connect to Simplix Innovations or any third-party service, and it does not send exported content anywhere. During an export it may issue HTTP GET requests for images only, through the WordPress safe HTTP API. By default the request goes only to your own site host so that a Media Library file that cannot be read from disk can still be embedded; other hosts are blocked. A developer can add approved hosts with the `sscribe_allowed_image_hosts` filter. Those requests send standard HTTP request metadata, such as your server's IP address, to the fetched host, so review the terms and privacy policy of any host you add.

== Privacy ==

SScribe stores short-lived export sessions, page queues, archives, operational logs, and security audit records on the WordPress site. Archives expire after 72 hours by default; sessions and logs are cleaned on a schedule. The plugin registers WordPress personal-data exporter and eraser callbacks for user-linked records. Exported page content is not transmitted to Simplix Innovations.

Operational security records can include an HMAC-protected representation of the request IP address, user ID, action, timestamp, and result. They are used to enforce rate limits and investigate export activity.

== License ==

SScribe is free software licensed under GPL-2.0-or-later.

The bundled PhpOffice/PhpWord library uses LGPL-3.0-only. Its notice is included at `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt`.

The bundled TCPDF 7.0.11 and its Tecnick tc-lib runtime dependencies use LGPL-3.0-or-later. Its license notice and the required notices for all bundled dependencies remain alongside their source in the plugin package.

Bundled LGPL-3.0 code is GPL-compatible under the GPLv3 option of SScribe's GPL-2.0-or-later license.

== Development ==

Canonical source repository: https://github.com/SimplixInnovations/sscribe-export-site-pages

The exact tagged source used for each public WordPress.org submission is accessible to reviewers.

Required tools: PHP 8.2+, Composer 2.x, Node.js 24+, and Git.

Build from a clean checkout:

    composer install
    composer vendor:prefix
    composer release

Build transformations are documented in docs/BUILD_TRANSFORMATIONS.md.

== Changelog ==

= 2.0.0 =
* Added Polylang and TranslatePress language support alongside WPML.
* Added meta description, canonical, and robots tags to HTML exports.
* The finished export now lists any page or format that failed, and the export log records it.
* Moved archives, logs, and working files from public uploads to site-isolated private storage, with verified migration of legacy data.
* Large exports resume reliably, with memory and time sized per batch.
* Hardened path validation, permissions, symlink handling, cleanup, uninstall, download containment, admin output escaping, and session and lock handling.
* Fixed PDFs with images, block tables in DOCX, code samples, Markdown captions, Arabic PDF links, the All status filter, and custom post type exports.
* Fixed activation on managed hosting and containers, SQLite upgrades, and large-site caching.
* Added responsive layouts, RTL keyboard support, and accessibility fixes in the admin screens.
* Supports PHP 8.2 to 8.5 and WordPress 6.1 and later.

= 1.9.0 =
* Rebuilt the WordPress admin screens and corrected export, history, accessibility, and download-flow defects.

= 1.2.0 =
* Added public custom post type exports and WCAG 2.2 AA accessibility improvements.

= 1.1.0 =
* Initial public release with DOCX, PDF, HTML, Markdown, WPML, RTL, batch processing, and secure downloads.

== Upgrade Notice ==

= 2.0.0 =
Adds Polylang and TranslatePress, moves export data to private storage with verified migration, and fixes PDF, DOCX, Markdown, and status-filter export problems. No manual migration is required.
