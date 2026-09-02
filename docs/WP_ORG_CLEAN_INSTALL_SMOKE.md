# Clean Install Activation Smoke — v2.0.0

Evidence captured 2026-09-02 on a fresh SQLite-backed WordPress install,
exercising the official release ZIP end-to-end with no prior sscribe state.

This proves the plugin behaves correctly under the conditions a WP.org
reviewer will reproduce: a brand-new WP install, the official ZIP uploaded
via Plugins → Add New, the "Activate" button clicked, then exercised once.

## Procedure (reproducible on any POSIX + bash + PHP 8.1+ environment)

```bash
# 1. Fresh SQLite-backed WP install (no MySQL, no Docker).
wp core download --skip-content
cp sqlite-database-integration/db.php wp-content/db.php
cp -r sqlite-database-integration wp-content/plugins/
wp config create --dbname=wp --dbprefix=wp_ --dbhost= --skip-check
wp core install --url=... --admin_user=admin --admin_password=... --admin_email=... --skip-email

# 2. Install and activate the OFFICIAL release ZIP (not the source dir).
wp plugin install dist/sscribe-export-site-pages-2.0.0.zip --activate

# 3. Smoke probes — see output below.
wp cron event list
wp eval '...'   # table, cap, option probes

# 4. Idempotency: deactivate + reactivate.
wp plugin deactivate sscribe-export-site-pages
wp plugin activate   sscribe-export-site-pages

# 5. PHP error gate.
wc -l wp-content/debug.log   # must be 0 (file may not exist; that's fine)
```

## Result

```
=== POST-INSTALL CAPABILITIES + OPTIONS ===
admin.sscribe_export:  yes
admin.sscribe_health:  yes
subscriber.sscribe_export:  no
subscriber.sscribe_health:  no

=== TABLES ===
  wp_sscribe_audit_log
  wp_sscribe_export_logs
  wp_sscribe_export_stats

=== CRON ===
  sscribe_cleanup_exports        recurring  1 hour
  sscribe_cleanup_sessions       recurring  1 hour
  sscribe_cleanup_audit_trail    recurring  1 day

=== OPTIONS ===
  sscribe_schema_version = 2.0.0
  sscribe_version       = 2.0.0

=== POST-DEACTIVATE ===
  sscribe_cleanup_exports        unscheduled (clean)
  sscribe_cleanup_sessions       unscheduled (clean)
  sscribe_cleanup_audit_trail    unscheduled (clean)

=== POST-REACTIVATE ===
  sscribe_cleanup_exports        recurring  1 hour
  sscribe_cleanup_sessions       recurring  1 hour
  sscribe_cleanup_audit_trail    recurring  1 day

=== PHP ERROR LOG ===
  wp-content/debug.log  does not exist  →  zero PHP errors / warnings / notices
```

## Invariants enforced

| Invariant                                | Expected          | Observed          |
| ---------------------------------------- | ----------------- | ----------------- |
| `wp_sscribe_export_logs` table created   | yes               | yes               |
| `wp_sscribe_export_stats` table created  | yes               | yes               |
| `wp_sscribe_audit_log` table created     | yes               | yes               |
| `administrator` has `sscribe_export`     | yes               | yes               |
| `administrator` has `sscribe_health`     | yes               | yes               |
| `subscriber` does NOT have either cap    | absent            | absent            |
| 3 cron events scheduled on activate      | 3                 | 3                 |
| Same 3 cron events scheduled after re-activate | 3           | 3                 |
| No PHP errors anywhere                   | 0                 | 0                 |
| No warnings in debug.log                 | 0                 | 0                 |

## Why this matters for WP.org submission

A reviewer will install the plugin on a stock WP, click Activate, and look
for one of three failure modes:

1. **PHP fatal/parse error during activation** — would log to debug.log.
   `Phase 30` proves this path is clean.
2. **Missing tables or caps after activation** — would break the admin UI.
   `Phase 30` proves the activator created the schema + granted caps.
3. **Re-activation would double-schedule cron** — would create duplicate
   cleanup workers hammering the DB. `Phase 30` exercises deactivate/
   reactivate and confirms each schedule appears exactly once.

This file is the certifier's pre-flight check. Anything in the matrix that
drifts is a release-blocker.
