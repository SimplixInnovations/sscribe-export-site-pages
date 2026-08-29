# SScribe developer reference

This reference documents integration points that are intentionally excluded from the WordPress.org readme to keep it below the directory parser's 10 KiB limit.

## Private storage override

SScribe uses a site-isolated directory below `get_temp_dir()` by default. Hosts that require a dedicated private volume may define `SSCRIBE_PRIVATE_STORAGE_DIR` in `wp-config.php` before WordPress loads the plugin:

```php
define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '/absolute/private/writable/path' );
```

The path must already exist, be absolute, canonical, writable, free of symbolic links, and outside `ABSPATH`, `WP_CONTENT_DIR`, the uploads base directory, and the web server document root. SScribe creates only its own site-keyed descendants below this base.

## Filters

### `sscribe_max_execution_time`

Maximum batch-export execution time in seconds. Default: `120`.

### `sscribe_pdf_max_execution_time`

Maximum PDF-heavy execution time in seconds. Default: `150`.

### `sscribe_pdf_max_html_size`

Maximum HTML bytes passed to mPDF before truncation. Default: `5_242_880`.

### `sscribe_use_chunked_page_ids`

Force-enable or disable chunked page-ID loading. Default: automatic based on page count.

### `sscribe_export_options_{$format}`

Filters per-format options for `pdf`, `docx`, `markdown`, or `html`.

Parameters: `(array $format_options, int $page_id, string $session_id)`.

### `sscribe_batch_size`

Pages processed per AJAX request. Values are bounded from `1` through `20`. Default: `5`.

### `sscribe_rate_limit_admin`

Hourly export-request limit for users with `manage_options`. Default: `1000`.

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

## Actions

### `sscribe_before_export_page`

Runs before a page is exported. Parameters: `(int $page_id, string $language)`.

### `sscribe_after_export_page`

Runs after a page export attempt. Parameters: `(int $page_id, array $formats, bool $export_success)`.

### `sscribe_cleanup_exports`

Scheduled hook that removes expired export files.

### `sscribe_cleanup_sessions`

Scheduled hook that removes stale sessions and durable page queues.

## Public classes

* `SScribe_Export_All_Formats_Wrapper`: exports a page to every supported format with per-format error isolation.
* `SScribe_Exporter_Factory`: constructs an exporter for a supported format.
* `SScribe_Exporter_Interface`: contract implemented by exporter classes.
