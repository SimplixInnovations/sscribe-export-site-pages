=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Tags: export, docx, pdf, html, markdown
Requires at least: 6.1
Tested up to: 7.1
Stable tag: 2.0.3
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual and RTL support.

== Description ==

SScribe turns WordPress pages into portable documents for content handovers, audits, translation work, and offline records.

= Export formats =

* **DOCX** for Microsoft Word, Google Docs, and LibreOffice
* **PDF** for a consistent portable document
* **HTML** with packaged styles and media
* **Markdown** with YAML front matter

= Highlights =

* Bounded AJAX batches designed for large sites
* Resumable export sessions with durable page queues
* WPML language selection and language metadata
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
* 256 MB of PHP memory is recommended for large PDF exports

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
2. Select the SScribe ZIP file, install it, and activate the plugin.
3. Open SScribe Export in the WordPress admin menu.

== Frequently Asked Questions ==

= Which content can I export? =

SScribe exports WordPress pages, posts, and registered public custom post types. You can narrow an export by post type, post status, and, when WPML is active, language.

= Does SScribe support RTL languages? =

Yes. The exporters detect RTL languages and apply direction-aware document structure and fonts. The admin interface also supports WordPress RTL mode.

= Does SScribe work with WPML? =

Yes. When WPML is active, you can export one language or all registered languages. SScribe preserves the selected language metadata in the exported documents.

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

== Privacy ==

SScribe stores short-lived export sessions, page queues, archives, operational logs, and security audit records on the WordPress site. Archives expire after 72 hours by default; sessions and logs are cleaned on a schedule. The plugin registers WordPress personal-data exporter and eraser callbacks for user-linked records. Exported page content is not transmitted to Simplix Innovations.

Operational security records can include an HMAC-protected representation of the request IP address, user ID, action, timestamp, and result. They are used to enforce rate limits and investigate export activity.

== License ==

SScribe is free software licensed under GPL-2.0-or-later.

The bundled PhpOffice/PhpWord library uses LGPL-3.0-only. Its notice is included at `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt`.

The bundled TCPDF 6.11.3 library uses LGPL-3.0-or-later. Its license notice and the required notices for all bundled dependencies remain alongside their source in the plugin package.

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

= 2.0.3 =
* Fixed activation on managed hosting and container environments by accepting validated writable private bases without weakening public-path or symlink protections.
* Removed a dynamic global shutdown lock flagged by WordPress Plugin Check; the operational logger now uses class-scoped state and retains the documented five rotated logs plus the live log.
* Hardened export finalization so archive publication stops when durable session state, locks, ZIP integrity, or export metadata cannot be safely committed.
* Hardened admin-rendered dynamic HTML and URL attributes against attribute injection while preserving same-origin download and media URL checks.
* Improved database portability, session/key persistence, lock renewal, stale-lock takeover, and concurrent export cleanup behavior.
* Fixed session-admission, cancellation, scheduled cleanup, and privacy-erasure races so live export locks cannot be removed by competing requests.
* Fixed registered public custom post type exports so selectable custom content is hydrated and exported instead of being rejected after selection.
* Hardened cross-platform release tooling, real-WordPress compatibility coverage, and exact-package certification.

= 2.0.2 =
* Release-system hardening plus runtime reliability fixes, including storage/activation compatibility and export-path corrections.

= 2.0.1 =
* Release-evidence and tag-policy corrections only; runtime unchanged from 2.0.0.

= 2.0.0 =
* Moved archives, logs, and working files from public uploads to site-isolated private storage, with verified migration of legacy data.
* Persisted complete page-ID queues in non-autoloaded expiring options so exports with hundreds of pages can resume reliably.
* Hardened path validation, permissions, symlink handling, cleanup, uninstall, archive ownership, and download containment.
* Bounded PDF font discovery to supported font extensions and dedicated directories.
* Added responsive 375 px, 768 px, and desktop layouts, RTL keyboard behavior, and explicit support-panel busy states.
* Updated release gates for PHP 8.2 through 8.5, locked dependency audits, strict WordPress Plugin Check, readme limits, ZIP limits, and SHA-256 output.
* Loosened the private-storage ownership check so shared-host installs where /tmp is owned by root but world-writable can activate the plugin; admins can still pin a stricter rule with the new `sscribe_private_storage_allow_foreign_owner` filter.

= 1.9.0 =
* Rebuilt the WordPress admin screens and corrected export, history, accessibility, and download-flow defects.

= 1.2.0 =
* Added public custom post type exports and WCAG 2.2 AA accessibility improvements.

= 1.1.0 =
* Initial public release with DOCX, PDF, HTML, Markdown, WPML, RTL, batch processing, and secure downloads.

== Upgrade Notice ==

= 2.0.3 =
Fixes private-storage activation compatibility, Plugin Check compliance, export finalization reliability, custom post type exports, session concurrency safety, and admin-output hardening. No manual data migration is required.

= 2.0.2 =
Release hardening and runtime reliability fixes; no manual migration action is required.

= 2.0.1 =
Release-system corrections only. No behavioural, security, or compatibility changes for end users. Historical v2.0.0 release was not publicly shipped; v2.0.1 contained release-system corrections only.

= 2.0.0 =
Moves export data to private storage with verified legacy migration, makes large multilingual exports durable, and fixes shared-host activation when the temp directory is root-owned but world-writable.
