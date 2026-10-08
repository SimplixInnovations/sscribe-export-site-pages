# SScribe developer reference

This reference documents integration points that are intentionally excluded from the WordPress.org readme to keep it below the directory parser's 10 KiB limit.

## Private storage override

By default SScribe stores archives, working files, and logs in `wp_upload_dir()['basedir'] . '/sscribe-export-site-pages/{key}/sscribe-exports'`, where `{key}` is a random 32-character value generated once per site and kept in the non-autoloaded `sscribe_storage_key` option. The container and the storage folder receive `.htaccess`, `web.config`, and `index.php` deny files, managed folders are created owner-only, and archives are only served through the nonce-, capability-, owner-, and single-use-token-checked download endpoint. nginx does not read those files, so add:

```nginx
location ~* /uploads/(sites/[0-9]+/)?sscribe-export-site-pages/ { deny all; }
```

Hosts that require a dedicated private volume may replace the default by defining `SSCRIBE_PRIVATE_STORAGE_DIR` in `wp-config.php`:

```php
define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '/absolute/private/writable/path' );
```

Alternatively, return one or more absolute base directories from the `sscribe_private_storage_base_candidates` filter (the constant wins when both are set). An override must already exist, be absolute, writable, not a symbolic link, and not world-writable without the sticky bit; a foreign-owned base can be accepted with the `sscribe_private_storage_allow_foreign_owner` filter. An unusable override fails closed instead of falling back to uploads. SScribe creates only its own key-named descendants below the chosen base.

When storage cannot be created, the SScribe Export screen and the Plugins screen show a notice naming the folder to check. Files written by 2.0.0 to its earlier temporary-directory location are moved into the current folder once; completion is recorded in the `sscribe_storage_migrated_v2` option.

## Multilingual plugins

SScribe reads languages from one multilingual plugin at a time. When several are active, WPML wins, then Polylang, then TranslatePress, so existing WPML sites keep their behavior. `SScribe_Page_Collector::get_multilingual_provider()` returns `wpml`, `polylang`, `translatepress`, or an empty string.

* WPML and Polylang: each translation is its own page, so an export for one language contains that language's pages.
* TranslatePress: pages are stored once in the default language. SScribe exports every page, translated into the selected language with `trp_translate()`. Language codes are the site locales passed through `sanitize_key()`, for example `fr_fr`.
* Without a multilingual plugin the Language step is hidden and every page is exported. Page language comes from the site locale, and RTL is applied for Arabic-script and Hebrew locales.

## Filters

### `sscribe_max_execution_time`

Maximum batch-export execution time in seconds. Default: `150`.

### `sscribe_pdf_max_execution_time`

Maximum PDF-heavy execution time in seconds. Default: `150`.

### `sscribe_pdf_max_html_size`

Maximum page HTML size, in bytes, the PDF engine will render. A larger page gets no PDF and the finished export lists it as a problem; other formats are unaffected. Default: `5_242_880`. Clamped to 64 KB to 50 MB; `0` disables the limit.

### `sscribe_use_chunked_page_ids`

Force-enable or disable chunked page-ID loading. Default: automatic based on page count.

### `sscribe_export_options_{$format}`

Filters per-format options for `pdf`, `docx`, `markdown`, or `html`.

Parameters: `(array $format_options, int $page_id, string $session_id)`.

### `sscribe_batch_size`

Pages processed per AJAX request. Values are bounded from `1` through `20`. Default: `5`.

### `sscribe_min_export_file_sizes`

Per-format minimum export file size, in bytes, used to detect empty or corrupted artifacts before they can ship. Keys: `docx`, `pdf`, `html`, `markdown`. Defaults: `8192`, `8192`, `512`, `50`.

### `sscribe_rate_limit_admin`

Per-60-second export-request limit. Default: `500` for users with `manage_options`, `200` otherwise.

### `sscribe_pdf_memory_soft_margin_bytes`

Available-memory threshold that triggers cycle collection. Default: `32 * MB_IN_BYTES`.

### `sscribe_pdf_memory_hard_margin_bytes`

Available-memory threshold that aborts PDF rendering safely. Default: `8 * MB_IN_BYTES`.

### `sscribe_image_cache_ttl`

Remote-image cache lifetime in seconds. Default: `1800`; non-positive values disable the cache.

### `sscribe_min_memory_per_page_mb`

Available memory target between batch items. Default: `32`.

### `sscribe_session_rotation_days`

Minimum interval between HMAC signing-key rotations. Default: `30` days. The immediately previous key remains valid for in-flight sessions.

### `sscribe_storage_layout`

Override the live export leaf directory name. Default: `sscribe-exports`. Values are restricted to `[a-z0-9._-]{1,60}`, with no leading dot, no `..` segments, and no path separators. Path-traversal payloads and unsafe characters silently fall back to the default. Legacy public locations used by prior plugin releases (`wp-content/uploads/sscribe-exports`) remain fixed regardless of this filter so existing artifacts can still be located and migrated.

### `sscribe_allowed_post_types`

Post types offered in the Content type step. Default: every registered public post type except attachments.

### `sscribe_allowed_image_hosts`

Extra hosts images may be downloaded from when they cannot be read from the Media Library. Default: the site, home, and uploads hosts only.

### `sscribe_export_capability` and `sscribe_health_capability`

Capabilities required to export and to read the Support tab. Defaults: `sscribe_export` and `sscribe_health`, which activation grants to administrators.

### `sscribe_page_data`

Filters the data collected for one page before it is exported. Parameters: `(array $page_data, int $page_id)`. Returning a non-array skips the page, and the finished export lists it as a problem.

### `sscribe_sanitize_export_html`

Filters page HTML before the DOCX parser reads it. Parameters: `(string $html)`.

### `sscribe_strip_unrendered_shortcodes`

Whether shortcodes that are still present in rendered content (their plugin or
builder is inactive) are removed before parsing. Only lowercase tags that carry
attributes, close themselves, or have a matching closing tag are treated as
shortcodes; their inner content is kept and bracketed prose such as `[sic]`,
`[1]` or `[USD]` is left alone. Default: `true`.

The parser also turns iframes, video, audio, object and embed elements into a
paragraph linking to their source, drops decorative inline SVG and icon-font
glyphs, promotes lazy-loaded image sources over placeholder data URIs, and
keeps whitespace inside `<pre>` blocks.

### `sscribe_html_export_show_seo`

Show an "SEO Metadata" box in the body of HTML exports. Default: `false`. Parameters: `(bool $show, array $page_data)`. The meta description, canonical URL, and robots tags are always written to the HTML head.

### `sscribe_docx_colors` and `sscribe_docx_section_settings`

DOCX color palette and PhpWord section settings. `sscribe_docx_section_settings` receives `(array $settings, bool $is_rtl)`.

### `sscribe_docx_append_image_url`

Print each image's source URL under the image in DOCX exports. Default: `true`. Parameters: `(bool $append, string $src)`.

### `sscribe_max_content_image_bytes` and `sscribe_max_featured_image_bytes`

Largest image, in bytes, embedded in a document. Defaults: 10 MB for content images and 5 MB for featured images, clamped to 50 MB.

### `sscribe_pdf_max_content_images`

Most content images embedded per PDF page. Default: `20`, clamped to `0` to `100`.

### `sscribe_session_ttl`

Lifetime of a saved export session in seconds. Default: one day, clamped to one hour to seven days.

### `sscribe_lock_ttl` and `sscribe_lock_stale_threshold`

Batch lock lifetime and the age after which another request may take a lock over, in seconds. Defaults: `180` and `140`.

### `sscribe_timeout_buffer_seconds`, `sscribe_soft_deadline_ratio`, and `sscribe_memory_threshold_mb`

When a batch stops early to stay inside PHP limits: seconds kept in reserve (default `15`), the share of the time limit a batch may use once it has exported a page (default `0.5`), and free memory in MB required to continue (default `10`).

### `sscribe_page_ids_chunk_size`

Page IDs read per query when chunked loading is on. Clamped to `1` to `1000`.

### `sscribe_cleanup_interval` and `sscribe_audit_cleanup_interval`

WP-Cron schedules for export cleanup and audit-log cleanup. Defaults: `hourly` and `daily`.

### `sscribe_private_storage_base_candidates` and `sscribe_private_storage_allow_foreign_owner`

Candidate base directories for private storage, and whether a base owned by another system user may be used. Parameters for the second: `(bool $allow, string $base)`. Default: `false`.

### `sscribe_trusted_ip_headers`

Request header names, such as `cf-connecting-ip`, trusted for the client IP behind a proxy. Up to 10 are read. Default: none, so `REMOTE_ADDR` is used.

### `sscribe_format_option_keys`

Format option keys accepted from the export request.

### `sscribe_debug_refresh_interval_ms`

Debug console auto-refresh interval. Default: `10000`, clamped to 5 to 300 seconds.

### `sscribe_enable_log_full_scan`

Allow the export log to scan the full log file when an entry is not indexed. Default: `true`.

## Actions

### `sscribe_before_export_page`

Runs before a page is exported. Parameters: `(int $page_id, string $language)`.

### `sscribe_after_export_page`

Runs after a page export attempt. Parameters: `(int $page_id, array $formats, bool $export_success)`.

### `sscribe_cleanup_exports`

Scheduled hook that removes expired export files.

### `sscribe_cleanup_sessions`

Scheduled hook that removes stale sessions and durable page queues.

### `sscribe_cleanup_audit_trail`

Scheduled hook that removes expired audit-trail rows.

## Markdown front matter presets

The Markdown exporter reads the format option `sscribe_md_frontmatter_preset`
(admin select, or `wp sscribe export --md-preset=<preset>`). `sscribe` is the
plugin's full metadata block and stays the default; `hugo`, `jekyll`, `astro`
and `obsidian` emit the keys those tools expect (UTC ISO 8601 dates, `draft`
or `published` flags, `permalink`, `heroImage`, `aliases` and so on) and omit
the duplicated H1 and source line from the body; `none` writes no front
matter. Every preset adds an `sscribe:` block with the source URL, post id,
post type and language so a migrated file can be traced back. Presets are
built by `SScribe_Markdown_Front_Matter::render()`.

## Archive manifest

Every archive carries two generated entries at its root:

* `manifest.json` (schema `sscribe-export-manifest/1`): generator and site
  identity, the export session, formats and languages, one record per
  document (`path`, `format`, `lang`, `bytes`, `sha256`, and the source
  `post` with id, type, title, URL and `modified_utc`), and totals.
* `INDEX.md`: a human-readable table with one row per source post and a
  link to each of its documents, followed by any files that have no source
  record.

Records are collected while pages render, as JSON lines in the dotfile
`.sscribe-manifest.jsonl` inside the session temp directory, so they survive
request boundaries, retries and resumes; a retried page replaces its earlier
record. The sidecar never ships. Checksums are computed from the final bytes
when the ZIP is assembled, so `sha256` always matches the archive content.
Timestamps are UTC in `YYYY-MM-DDTHH:MM:SSZ` form. `SScribe_Export_Manifest`
exposes `record()`, `load()`, `build()`, `to_json()` and
`render_index_markdown()`.

## Public classes

* `SScribe_Export_All_Formats_Wrapper`: exports a page to every supported format with per-format error isolation.
* `SScribe_Exporter_Factory`: constructs an exporter for a supported format.
* `SScribe_Export_Manifest`: builds the `manifest.json` and `INDEX.md` entries shipped in every archive.
* `SScribe_Exporter_Interface`: contract implemented by exporter classes.
