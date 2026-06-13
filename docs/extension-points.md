# SScribe Export Site Pages — Extension Points

This document describes the public hooks and integration points available
to plugin developers who want to extend SScribe without forking it.

All hooks follow the `sscribe_*` naming convention. The plugin does **not**
guarantee source compatibility across minor versions — only the contracts
documented here.

---

## Filters

### `sscribe_export_options_{$format}`

Fires inside `SScribe_Batch_Processor::dispatch_formats()` for each format
that the user selected (one of `pdf`, `docx`, `markdown`, `html`).

Receives the per-format options collected from the admin UI's
`SScribe_*_options` panel (page size, template choice, embed-images toggle,
etc.) and returns the (possibly modified) options map that the exporter
should use.

**Signature**

```php
apply_filters( 'sscribe_export_options_pdf', array $format_options, int $page_id, string $session_id );
```

| Argument | Type | Description |
|----------|------|-------------|
| `$format_options` | `array` | Sanitized options for the current page. Keys are format-prefixed (e.g. `sscribe_pdf_page_size`). Values are strings (checkboxes → `"1"` or `""`). |
| `$page_id` | `int` | The WordPress post ID being exported. |
| `$session_id` | `string` | The active batch session ID (useful for correlating logs). |

**Built-in listeners**

The four built-in exporters register listeners that read the keys
relevant to their format and apply them to the export pipeline:

| Exporter | Listens on | Reads keys |
|----------|-----------|------------|
| `SScribe_PDF_Exporter` | `sscribe_export_options_pdf` | `sscribe_pdf_page_size`, `sscribe_pdf_include_images`, `sscribe_pdf_include_page_numbers` |
| `SScribe_DOCX_Exporter` | `sscribe_export_options_docx` | `sscribe_docx_template`, `sscribe_docx_include_images`, `sscribe_docx_include_toc` |
| `SScribe_Markdown_Exporter` | `sscribe_export_options_markdown` | `sscribe_md_include_frontmatter`, `sscribe_md_include_featured_image`, `sscribe_md_absolute_urls` |
| `SScribe_HTML_Exporter` | `sscribe_export_options_html` | `sscribe_html_include_css`, `sscribe_html_responsive_images` |

**Third-party use case**

A developer adding a new option to the PDF exporter would:

1. Add the input to the `admin/partials/sscribe-admin-display.php` PDF
   panel. The JS auto-collects it (no code change).
2. Register a filter listener in the exporter (or a small extension
   plugin) that reads the new key from `$format_options` and applies
   it to the export pipeline.

Example: a "PDF font family" extension plugin would add a `<select>`
to the PDF panel and register:

```php
add_filter( 'sscribe_export_options_pdf', function ( $options ) {
    if ( ! empty( $options['sscribe_pdf_font_family'] ) ) {
        // Persist somewhere the PDF exporter can read, e.g. a
        // transient keyed by the current session.
        update_option( 'myext_pdf_font', $options['sscribe_pdf_font_family'] );
    }
    return $options;
} );
```

The exporter would then read `get_option( 'myext_pdf_font' )` during
its own setup.

### `sscribe_batch_size`

Filters the number of pages per AJAX chunk in a batch export.

```php
apply_filters( 'sscribe_batch_size', 5 );
```

Return an `int` between 1 and 20. Lower values reduce per-request
memory but increase the number of HTTP round trips.

### `sscribe_pdf_memory_soft_margin_bytes` / `sscribe_pdf_memory_hard_margin_bytes`

Filters the mPDF exporter's memory-pressure thresholds.

- **Soft margin** (default `32 * MB_IN_BYTES`): when remaining memory
  drops below this, the exporter triggers `gc_collect_cycles()` and
  continues.
- **Hard margin** (default `8 * MB_IN_BYTES`): when remaining memory
  drops below this, the exporter aborts with a `SScribe_Result::failure`.

Lowering the soft margin for memory-constrained shared hosts can prevent
fatal OOMs; raising the hard margin is a last-resort safety knob.

---

## Public classes

### `SScribe_Export_All_Formats_Wrapper`

Static utility for fan-out exports to every supported format in a
single call, with per-format error isolation.

```php
$results = SScribe_Export_All_Formats_Wrapper::export_page(
    $page_data,         // array — content + metadata
    $output_dir,        // string — where to write the outputs
    1,                  // int — page index (1-based)
    10,                 // int — total pages
    array( 'pdf' )      // array|null — format whitelist; null = all
);

$successes = SScribe_Export_All_Formats_Wrapper::successful_formats( $results );
$failures  = SScribe_Export_All_Formats_Wrapper::failed_formats( $results );
```

`export_page()` returns a map keyed by format string. Each entry has:

| Key | Type | Description |
|-----|------|-------------|
| `success` | `bool` | Whether the format produced a usable file. |
| `result` | `SScribe_Result` | The exporter's structured result (data on success, error message on failure). |
| `error` | `?string` | Convenience accessor for the error message. |

A failure in one format never aborts the others — that's the whole
point of the wrapper.

### `SScribe_Exporter_Factory`

The factory used internally. Exposed for third-party code that wants
to construct a specific exporter by format string:

```php
$exporter = SScribe_Exporter_Factory::create( 'pdf' );
$result   = $exporter->export( $page_data, $output_dir, $index, $total );
```

Throws `SScribe_Validation_Exception` for unknown formats.

### `SScribe_Exporter_Interface`

The contract every exporter implements:

```php
public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result;
public function get_extension(): string;
public function get_mime_type(): string;
```

Third-party exporters can be added via WordPress's standard
`SScribe_Exporter_Factory::create()` by hooking into the
`SScribe_Export_Format` enum or wrapping the factory itself — the
factory is a thin `match` over the enum's cases, so adding a new
format means adding a new enum case + factory arm.

---

## Logger API

`SScribe_Logger::instance()` is the canonical way to obtain a logger
singleton:

```php
$logger = SScribe_Logger::instance();        // default settings
$logger = SScribe_Logger::instance( true, 'my_prefix' );
```

Both logger implementations (`SScribe_Logger`,
`SScribe_Logger_Enhanced`) implement
`SScribe_Logger_Interface` and expose the standard PSR-3-style level
methods (`debug`, `info`, `notice`, `warning`, `error`, `critical`,
`alert`, `emergency`) plus the generic `log( $level, $message, $context )`.

The shared level dispatch + session-id setter + priority helpers
live in `SScribe_Logger_Common` trait. New logger implementations
should `use` that trait rather than redefining the boilerplate.

---

## Session storage

`SScribe_Session` is the canonical storage layer for batch state.
`SScribe_Batch_Session_Handler` is the AJAX coordinator that wraps
it. Use the storage layer directly in CLI/CRON code; use the handler
in `wp_ajax_*` callbacks.

```php
$session = new SScribe_Session();
$id      = $session->create( array( 'user_id' => get_current_user_id(), /* ... */ ) );
$state   = $session->get( $id );
$session->update( $id, array( 'current_page' => 5 ) );
$session->delete( $id );
```

---

## What's intentionally NOT public

- **No public method to mutate exporter state outside `export()`.** A
  third party that wants a new format option should use the filter
  hook above, not poke at exporter internals.
- **No direct DB access for audit / stats tables.** The wrapper
  classes (`SScribe_Audit_Trail`, `SScribe_Export_Stats`) are the
  only supported entry points.
- **The `SScribe_Container` service container** is internal. It is
  a simple registry, not a DI framework; don't depend on its layout.
