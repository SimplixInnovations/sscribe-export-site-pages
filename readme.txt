=== SScribe Export Site Pages ===
Contributors: simplixinnovations
Tags: export, docx, pdf, html, markdown
Requires at least: 6.1
Tested up to: 7.1
Stable tag: 2.0.2
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
* 256 MB of PHP memory is recommended for large PDF exports

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
2. Select the SScribe ZIP file, install it, and activate the plugin.
3. Open SScribe Export in the WordPress admin menu.

== Frequently Asked Questions ==

= Which content can I export? =

SScribe exports WordPress pages and registered public post types. You can select all available content or narrow the export by type, author, status, date, language, taxonomy, or individual item.

= Does SScribe support RTL languages? =

Yes. The exporters detect RTL languages and apply direction-aware document structure and fonts. The admin interface also supports WordPress RTL mode.

= Does SScribe work with WPML? =

Yes. When WPML is active, you can export one language or all registered languages. SScribe preserves the selected language metadata in the exported documents.

= Can a large export resume after the browser closes? =

Yes. Export progress and the complete normalized page-ID queue are stored in expiring, non-autoloaded WordPress options. Reopening the export can continue from the saved offset while the session remains valid.

= Where are exported files stored? =

Archives, temporary files, and logs use a plugin-owned directory below the operating system's temporary directory, outside WordPress and public upload paths. The directory is isolated per WordPress site. Existing archives from older versions are copied, hash-verified, and only then removed from the old location.

= How long are archives kept? =

Completed archives expire after 72 hours by default and are removed by scheduled cleanup. A site administrator can delete an archive sooner from Recent Exports.

= Who can download an export? =

Only an authenticated user with the delegated export capability can download an archive that user owns. Download links use short-lived, single-use tokens and never expose the storage path.

= Does SScribe send content to an external service? =

No. SScribe does not send exported content to Simplix Innovations or another content-processing service. Local Media Library files are read from the site. Same-site images that cannot be resolved locally may be fetched through the WordPress safe HTTP API; other hosts are blocked unless a developer explicitly allows them.

= What happens when the plugin is uninstalled? =

Uninstall removes plugin options, scheduled hooks, saved export sessions and page queues, private archives and logs, and any recognized legacy export directory. Deactivation alone keeps data so exports can resume after reactivation.

== Privacy ==

SScribe stores short-lived export sessions, page queues, archives, operational logs, and security audit records on the WordPress site. Archives expire after 72 hours by default; sessions and logs are cleaned on a schedule. The plugin registers WordPress personal-data exporter and eraser callbacks for user-linked records. Exported page content is not transmitted to Simplix Innovations.

Operational security records can include an HMAC-protected representation of the request IP address, user ID, action, timestamp, and result. They are used to enforce rate limits and investigate export activity.

== License ==

SScribe is free software licensed under GPL-2.0-or-later.

The bundled Amiri fonts in `assets/fonts/amiri/` use the SIL Open Font License 1.1. The full text is included at `assets/fonts/amiri/OFL.txt`.

The bundled PhpOffice/PhpWord library uses LGPL-3.0-only. Its notice is included at `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt`.

The bundled mPDF library uses GPL-2.0-only. Required notices for bundled dependencies remain alongside their source in the plugin package.

== Development ==

Canonical source repository: https://github.com/SimplixInnovations/sscribe-export-site-pages

The released ZIP is built from this repository with the documented, deterministic build commands below; no private pre-built runtime blobs are substituted into the submission package. Because the production ZIP excludes development/build tooling such as scripts and tests, the canonical repository (or an equivalent maintained source mirror containing the exact tagged source and build tooling) must be publicly accessible for a WordPress.org submission, so the exact tagged source used for each public submission is accessible to reviewers. Reviewer-only or private access is not treated as a substitute for the public source availability required by the WordPress.org plugin guidelines.

Required tools and versions:

* PHP 8.2 or later (8.4 recommended for local development)
* Composer 2.x (locked via composer.lock)
* Node.js 24 or later (pinned via GitHub Actions setup-node)
* Git for source checkout

Build a clean submission archive from a fresh checkout:

    git clone https://github.com/SimplixInnovations/sscribe-export-site-pages.git
    cd sscribe-export-site-pages
    composer install
    composer vendor:prefix
    composer test
    composer stan
    composer cs
    composer release

The last command runs scripts/build-release.php and writes dist/sscribe-export-site-pages-<version>.zip plus its SHA-256 sidecar. The build process strips comments, prunes unused fonts, prefixes third-party namespaces via Strauss, removes dev-only paths, and verifies the ZIP against the certification contract before it is ever uploaded to WordPress.org.

A detailed description of every build transformation (paths excluded, comments stripped, namespaces prefixed, fonts pruned, AI artifacts sanitized) lives at docs/BUILD_TRANSFORMATIONS.md in the repository. The release evidence trail lives at docs/RELEASE_REPORT_v2.0.0.md.

== Changelog ==

= 2.0.2 =
* Release-system corrections only. Same shipped runtime as 2.0.1 (branch-coverage tests; PHPUnit 11 migration; portable WP testbench extension loader; defensive `try/catch` in `SScribe_Logger::get_log_file()`; tightened `phpunit-wp.xml`; `composer full` split into three gates).

= 2.0.1 =
* Refactored the release-blocker registry (Phase 70) so dynamic blockers resolve at certification time from ignored `dist/` evidence instead of requiring a tracked commit to flip `DEFERRED → RESOLVED` — eliminating the SHA circularity the v2.0.0 closeout identified.
* Corrected the tag policy (Phase 54) so `gh release create --verify-tag` is correctly identified as SHA-binding via GitHub's signed-tag store rather than cryptographic signing; cryptographic tag signing is now recorded as recommended (when the maintainer has signing configured), not required.
* Bumped the public release target from 2.0.0 to 2.0.1. The historical `v2.0.0` tag is preserved as a documented artifact (`docs/RELEASE_REPORT_v2.0.0.md`); the live public release target is `v2.0.1`.
* Removed live source SHA / ZIP SHA-256 / build-timestamp values from any tracked release report; authoritative final identities now live in ignored, regenerated `dist/*.json` evidence files.
* Same shipped runtime as 2.0.0 — no behavioural, security, or compatibility changes for end users.

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

= 2.0.2 =
No user-facing changes.

= 2.0.1 =
Release-system corrections only. No behavioural, security, or compatibility changes for end users. Historical v2.0.0 release was not publicly shipped; v2.0.1 is the public release target.

= 2.0.0 =
Moves export data to private storage with verified legacy migration, makes large multilingual exports durable, and fixes shared-host activation when the temp directory is root-owned but world-writable.
