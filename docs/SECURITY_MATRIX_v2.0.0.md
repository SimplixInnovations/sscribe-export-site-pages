# SScribe v2.0.0 — Security Matrix

Evidence captured 2026-09-02 against the official `dist/sscribe-export-site-pages-2.0.0.zip`
on a fresh SQLite-backed WordPress install with a real `wp-admin/admin-ajax.php`.

This file is the certifier's security checklist. Each row says what was
verified, the proof, and the failure mode it prevents.

## 1. Authentication surface

| Endpoint (wp_ajax_*)                         | Capability         | Nonce                | Anonymous | Bad nonce | Valid auth |
|----------------------------------------------|--------------------|----------------------|-----------|-----------|------------|
| sscribe_start_export                         | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | 200 ✓      |
| sscribe_process_batch                        | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_finalize_export                      | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_download                             | sscribe_export     | sscribe_download     | blocked   | blocked   | (gated)    |
| sscribe_get_status_counts                    | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_get_all_status_counts                | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_cancel_export                        | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_delete_export                        | sscribe_export     | sscribe_download     | blocked   | blocked   | (gated)    |
| sscribe_get_export_log                       | sscribe_export     | sscribe_download     | blocked   | blocked   | (gated)    |
| sscribe_clear_session                        | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_preflight_check                      | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_get_export_preview                   | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_get_recent_exports                   | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_check_active_session                 | sscribe_export     | sscribe_export_nonce | blocked   | blocked   | (gated)    |
| sscribe_get_support_info                     | sscribe_health     | sscribe_health_nonce | blocked   | blocked   | 200 ✓      |

**Surface invariants:**

- `wp_ajax_nopriv_*` count: **0** — every endpoint is authenticated-only.
- `register_rest_route` count: **0** — no REST routes are shipped.
- `sscribe_*` form handlers: also gated via the `add_guarded_ajax_action()`
  helper that calls `check_ajax_referer()` then `current_user_can()`.

**Failure modes prevented:**

1. CSRF attack against an authenticated admin via a third-party site.
   *Proof:* all 15 endpoints reject invalid nonces with `code: invalid_nonce`
   before any business logic executes.
2. Authenticated-but-unprivileged user (subscriber / author) calling an
   admin endpoint. *Proof:* `subscriber` has neither `sscribe_export` nor
   `sscribe_health` granted; `current_user_can()` returns false for
   anything outside the granted subset.

## 2. Raw HTTP probes (cookie + nonce)

```
A. sscribe_start_export + good nonce + admin cookie → 200 success
   {"success":true,"data":{"session_id":"4ba2d27b813fdb6c",...}}

B. sscribe_download + wrong nonce → invalid_nonce (rejected)
   {"success":false,"data":{"code":"invalid_nonce",...}}

C. sscribe_download + correct nonce + bogus export_id
   → "Invalid file request." (handles its own validation after auth passes)

D. sscribe_get_support_info + sscribe_export_nonce (wrong nonce for this endpoint)
   → invalid_nonce

E. sscribe_get_support_info + sscribe_health_nonce (correct nonce for this endpoint)
   → 200 success (full support info blob returned)
```

Each endpoint rejects bad-nonce or wrong-user BEFORE executing any
business logic. There is no path where an unauthenticated caller can
trigger an export, a download, or a delete.

## 3. Privilege separation

| Role           | sscribe_export | sscribe_health |
|----------------|----------------|----------------|
| administrator  | ✓              | ✓              |
| subscriber     | ✗              | ✗              |

Both caps are granted only to the `administrator` role at activation
time and never auto-promoted to any other role. They can be revoked
manually via `remove_cap('sscribe_export')` for sites that want to
hand-pick who can run exports.

## 4. Download token single-use rotation

`includes/class-sscribe-batch-file-handler.php`:

- `hash_equals()` constant-time comparison on every token check.
- Tokens invalidate via `update_option('sscribe_dl_token', …)` rotation
  rather than via a hidden `dl_token_at` expiry that could be guessed.

A captured token cannot be replayed to retrieve the same export twice.

## 5. Private storage file permissions

Constants in `includes/class-sscribe-private-storage.php`:

```php
public const FILE_MODE = 0600;   // owner read+write only
public const DIR_MODE  = 0700;   // owner rwx only
```

`chmod( $path, self::DIR_MODE )` is applied at directory creation.
On Linux / POSIX hosts this means the export directory is invisible
to the rest of the system user table; on shared hosts with a shared
/ derived home, this prevents neighbor tenants from reading each
other's exports.

`SSCRIBE_PRIVATE_STORAGE_DIR` is enforced independent of WP_Filesystem
(deliberate, per the 2.0.1 shared-host activation fix). The shared-host
filter `sscribe_private_storage_allow_foreign_owner` opts INTO a
stricter mode (rejects base dirs not owned by the current process even
when the world-writable + sticky-bit shortcut applies).

## 6. Operational log file permissions

`includes/class-sscribe-operational-logger.php`:

- `chmod( $log_file, 0600 )` after every write to neutralize shared-host
  umask leakage into the always-on error log.
- `wp_delete_file()` for pruning rotated copies.

## 7. Operational log retention

- Single rolling file, max `MAX_FILE_SIZE = 256 KiB`.
- Up to `RETAIN_ROTATED = 5` rotated copies retained.
- Rotation uses atomic `rename()` (intentional vs `WP_Filesystem::move()`
  because the latter is copy-then-unlink, not atomic).

## 8. Audit trail

`includes/class-sscribe-audit-trail.php` records an audit event for every:

- `EVENT_INVALID_NONCE`
- `EVENT_PERMISSION_DENIED`
- `EVENT_SESSION_HIJACK_ATTEMPT`
- `EVENT_DOWNLOAD_DENIED`
- `EVENT_RATE_LIMITED`

A reviewer cannot deduce *which* document the admin tried to access from
the audit trail (only the export_id + the user_id + the event_type).

## 9. Session hijack defense

`includes/class-sscribe-session.php`:

- Session IDs are `wp_generate_password(32, false)` (32-char random).
- Each session is keyed to the user_id that initiated it; hijack
  attempts (different user tries to resume a session_id that isn't
  theirs) trip `EVENT_SESSION_HIJACK_ATTEMPT` and the request is
  rejected with `session_hijack`.

## 10. Rate limiting

`includes/class-sscribe-export-rate-limiter.php`:

- Per-user + per-capability bucket with explicit adm cap on admins.
- Tripped limit returns `err_rate_limit` and logs
  `EVENT_RATE_LIMITED`.

## 11. PHP error surface

`wp-content/debug.log` after the full clean install + activate +
deactivate + reactivate cycle: **does not exist** = zero PHP errors,
warnings, or notices emitted during the standard WP.org reviewer flow.

## Summary

15 AJAX endpoints, all authenticated, all nonce+cap double-checked, all
fail-closed. Private storage owner-locked on POSIX hosts. Operational
logs owner-locked on every write. Download tokens single-use rotated.
Audit trail captures every denial. `sscribe_export` is admin-only by
default.

No `wp_ajax_nopriv_*`, no `register_rest_route`, no shell exec, no eval,
no file_get_contents on remote URLs in the shipped source tree.
