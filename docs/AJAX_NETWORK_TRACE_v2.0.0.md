# AJAX Network Trace — v2.0.0

Auditable network-level trace for every AJAX endpoint the plugin
registers. A reviewer or independent auditor uses this document to
answer: "What does each AJAX endpoint accept, who may call it, what
does it return?"

## Why this exists

Every AJAX action the plugin ships is a network surface. WP.org
reviewers, security researchers, and customers all want a single
canonical answer to: which actions exist, what capability guards
them, what nonce action string protects them, what rate-limit
bucket applies, and what response shape the action returns. The
canonical inventory lives here so a regression that adds a new
action without updating this doc fails the
`composer test:ajax-network-trace` gate.

## Conventions

| Column           | Meaning                                                                          |
|------------------|----------------------------------------------------------------------------------|
| Action           | The WordPress `wp_ajax_*` hook name (e.g. `wp_ajax_sscribe_start_export`).       |
| Handler          | The plugin method that handles the action.                                       |
| Capability       | The `current_user_can()` capability required. Empty = `sscribe_export` default.  |
| Nonce action     | The string passed to `wp_create_nonce()` / `check_ajax_referer()`.               |
| Rate-limit bucket | The throttle bucket the `verify_request_authorization()` guard uses.            |
| Response shape   | `wp_send_json_success( $payload )` / `wp_send_json_error( $message )`.           |

## Endpoints

| Action                                       | Handler                       | Capability         | Nonce action          | Rate-limit bucket | Response shape        |
|----------------------------------------------|-------------------------------|--------------------|-----------------------|-------------------|-----------------------|
| `wp_ajax_sscribe_start_export`               | `ajax_start_export`           | `sscribe_export`   | `sscribe_batch`       | `batch_write`     | `success{export_id}`   |
| `wp_ajax_sscribe_process_batch`              | `ajax_process_batch`          | `sscribe_export`   | `sscribe_batch`       | `batch_write`     | `success{progress}`    |
| `wp_ajax_sscribe_finalize_export`            | `ajax_finalize_export`        | `sscribe_export`   | `sscribe_batch`       | `batch_write`     | `success{download}`    |
| `wp_ajax_sscribe_download`                   | `ajax_download`               | `sscribe_export`   | `sscribe_download`    | `download_read`   | `success{file_url}`    |
| `wp_ajax_sscribe_get_status_counts`          | `ajax_get_status_counts`      | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{counts}`      |
| `wp_ajax_sscribe_get_all_status_counts`      | `ajax_get_all_status_counts`  | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{counts}`      |
| `wp_ajax_sscribe_cancel_export`              | `ajax_cancel_export`          | `sscribe_export`   | `sscribe_batch`       | `batch_write`     | `success{}`            |
| `wp_ajax_sscribe_delete_export`              | `ajax_delete_export`          | `sscribe_export`   | `sscribe_download`    | `download_read`   | `success{}`            |
| `wp_ajax_sscribe_get_export_log`             | `ajax_get_export_log`         | `sscribe_export`   | `sscribe_download`    | `download_read`   | `success{log}`         |
| `wp_ajax_sscribe_clear_session`              | `ajax_clear_session`          | `sscribe_export`   | `sscribe_batch`       | `batch_write`     | `success{}`            |
| `wp_ajax_sscribe_preflight_check`            | `ajax_preflight_check`        | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{result}`      |
| `wp_ajax_sscribe_get_export_preview`         | `ajax_get_export_preview`     | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{preview}`     |
| `wp_ajax_sscribe_get_recent_exports`         | `ajax_get_recent_exports`     | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{exports}`     |
| `wp_ajax_sscribe_get_support_info`           | `ajax_get_support_info`       | `sscribe_health`   | `sscribe_health_nonce`| `health_read`     | `success{info}`        |
| `wp_ajax_sscribe_check_active_session`       | `ajax_check_active_session`   | `sscribe_export`   | `sscribe_batch`       | `status_read`     | `success{session}`     |
| `wp_ajax_sscribe_debug_save_settings`        | `ajax_debug_save_settings`    | `manage_options`   | `sscribe_debug`       | `debug_write`     | `success{}`            |
| `wp_ajax_sscribe_debug_fetch_logs`           | `ajax_debug_fetch_logs`       | `manage_options`   | `sscribe_debug`       | `debug_read`      | `success{logs}`        |
| `wp_ajax_sscribe_debug_clear_logs`           | `ajax_debug_clear_logs`       | `manage_options`   | `sscribe_debug`       | `debug_write`     | `success{}`            |
| `wp_ajax_sscribe_debug_export_logs`          | `ajax_debug_export_logs`      | `manage_options`   | `sscribe_debug`       | `debug_read`      | `success{file}`        |
| `wp_ajax_sscribe_debug_get_files`            | `ajax_debug_get_rotated_log_files` | `manage_options`| `sscribe_debug`       | `debug_read`      | `success{files}`       |
| `wp_ajax_sscribe_debug_fetch_rotated`        | `ajax_debug_fetch_rotated`    | `manage_options`   | `sscribe_debug`       | `debug_read`      | `success{content}`     |
| `wp_ajax_sscribe_debug_delete_rotated`       | `ajax_debug_delete_rotated`   | `manage_options`   | `sscribe_debug`       | `debug_write`     | `success{}`            |
| `wp_ajax_sscribe_debug_refresh_nonce`        | `ajax_debug_refresh_nonce`    | `manage_options`   | `sscribe_debug`       | `debug_read`      | `success{nonce}`       |

## Guard surface

Every action above flows through `SScribe_Loader::add_guarded_ajax_action()`
which wraps the callback with `verify_request_authorization()`. That helper
runs (in order):

1. `check_ajax_referer( $nonce_action, '_ajax_nonce' )`
2. `current_user_can( $capability )`
3. Rate-limit check via the bucket declared in the table.
4. `display_errors` disable (so `wp_send_json_*` is the only path back to the
   browser).
5. `wp_send_json_success( $payload )` or `wp_send_json_error( $message )`.

The Phase 49 AJAX security inventory contract asserts that every shipped AJAX
action flows through this guard. The Phase 66 network-trace contract asserts
the SAME set of actions is enumerated in this doc — a regression that adds
a new AJAX handler without adding a row to the trace table fails CI.

## What this contract does NOT cover

- REST endpoints (separate `WP_REST_Server` namespace; not currently used by
  the plugin).
- Public/unauthenticated endpoints: zero `wp_ajax_nopriv_sscribe_*` actions
  are ever registered. Every action requires `current_user_can`.

## How an independent auditor verifies this

```bash
# 1. Compare the trace doc against the source tree.
composer test:ajax-network-trace

# 2. Cross-check against the AJAX security inventory.
composer test:ajax-security

# 3. Inspect the trace JSON manifest at dist/ajax-network-trace-manifest.json.
jq '.' dist/ajax-network-trace-manifest.json
```

A green `composer test:ajax-network-trace` + a green
`composer test:ajax-security` = the AJAX surface is fully accounted for.
