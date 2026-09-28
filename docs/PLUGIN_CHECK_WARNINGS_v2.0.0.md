# Plugin Check Warnings — v2.0.0

Auditable triage for every WordPress.org Plugin Check warning that
ships with the sScribe Export Site Pages submission.

## Why this exists

The release pipeline runs [`wordpress/plugin-check-action@v1`](https://github.com/wordpress/plugin-check-action)
in strict mode (with `include-experimental: true`) against the
build artifact at `./dist/sscribe-export-site-pages/`. Plugin Check
exits non-zero on any error OR warning in the CI action. Local evidence is
captured separately as upstream `FILE:` sections plus JSON arrays in
`dist/evidence/plugin-check.log`, or the exact upstream zero-finding success
message. Generic JSON, tables, headers, incomplete reports and trailing output
are rejected. A completed command sidecar is required; parsing alone is never
certification.

A regression that introduces a NEW warning not listed here — or that
re-introduces a `fixed` warning — fails the
`composer test:plugin-check-triage` gate before the release tag
is cut.

## Triage process

1. CI runs Plugin Check on the built dist tree (Phase 33 contract).
2. Run the exact-artifact capture command below and retain both the raw report
   and `plugin-check-evidence.json` with its command exit status and hashes.
3. Any warning emitted by the report MUST appear in the **Warnings
   table** below with one of four statuses:

   | Status         | Meaning                                                                                       |
   |----------------|-----------------------------------------------------------------------------------------------|
   | `fixed`        | The warning has been remediated in the current audited SHA. The fix commit is recorded.       |
   | `acknowledged` | The warning is a known false positive or a third-party artefact we cannot remediate. Justification recorded. |
   | `in_progress`  | The warning is being remediated; owner + ETA recorded.                                        |
   | `deferred`     | The warning is real but out of scope for the current release. Tracked in a GitHub issue.      |

4. A regression that introduces a NEW warning (i.e. a warning code
   that does not appear in the table below) fails CI with the
   message: `New Plugin Check warning: <code> — add a triage row.`

## Current status (audited SHA)

| Metric                       | Value |
|------------------------------|-------|
| Audited SHA                  | see `dist/release-pipeline-manifest.json` `source_sha` |
| Plugin Check exit code       | Unverified for corrected SHA; release HOLD |
| Strict mode                  | `true` |
| `include-experimental`       | `true` |
| Last audit run               | `bin/release-audit.sh` |
| Last audit log               | `/tmp/release-audit-plugincheck.log` |

## Warnings table

The table records warnings discovered during release hardening even when they
are fixed before the final certified ZIP. The CI action still requires zero errors and warnings. The local triage gate
allows only exact code + plugin-relative file + warning severity rows marked
`acknowledged`; `fixed`, `in_progress`, `deferred` and all errors fail.

| Warning code | Source | Severity | Status | Remediation | Owner |
|--------------|--------|----------|--------|-------------|-------|
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | `includes/class-sscribe-operational-logger.php` | warning | `fixed` | Replaced the dynamic `$GLOBALS[$lock_key]` shutdown recursion lock with class-scoped static state in v2.0.3. | Simplix Innovations |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/class-sscribe-audit-trail.php` | warning | `acknowledged` | Intentional one-shot `table_exists()` schema probe on a plugin-owned table (regex-validated identifier, `esc_sql`-quoted, result cached in `$table_exists_cache`). Not a user-input query. `phpcs:ignore` documents the exemption. | Simplix Innovations |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/class-sscribe-audit-trail.php` | warning | `acknowledged` | Same probe; caching is the class-level `$table_exists_cache` boolean, not the object cache. | Simplix Innovations |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/class-sscribe-logger-enhanced.php` | warning | `acknowledged` | Same one-shot schema probe pattern as audit trail. | Simplix Innovations |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/class-sscribe-logger-enhanced.php` | warning | `acknowledged` | Same probe; result cached in `$table_exists_cache`. | Simplix Innovations |

## Adding a new warning

When CI surfaces a Plugin Check warning not in the table:

1. Reproduce the warning locally with `wp plugin check sscribe-export-site-pages --allow-root`.
2. Classify the warning as `fixed`, `acknowledged`, `in_progress`, or `deferred`.
3. For `fixed`: ship the fix in the same release, record the commit SHA in the Remediation column.
4. For `acknowledged`: cite the third-party library or WP false-positive reference. The justification MUST hold up under an independent WP.org reviewer.
5. For `in_progress` / `deferred`: link the GitHub issue in the Remediation column and assign an Owner.
6. Commit this doc with the manifest update. The CI gate re-validates the table on every push.

## Plugin Check source-level guardrails

In addition to running Plugin Check, the verifier enforces the
canonical source-level rules that prevent common Plugin Check
warnings from ever shipping:

- No PHP short open tag (`<?` other than `<?php`).
- No `extract($_POST)` / `extract($_GET)` / `extract($_REQUEST)`.
- No direct `$_GET` / `$_POST` / `$_REQUEST` read in non-AJAX contexts without nonce + capability check.
- No `eval()` call anywhere under `includes/` or `admin/`.
- No `fclose($h)` without a matching `fopen(...)` / `tmpfile()` upstream.
- No `unlink()` on a path not inside `wp_upload_dir()` or
  `SSCRIBE_PRIVATE_STORAGE_DIR`.
- No `mkdir()` not gated on `wp_mkdir_p()` + `SScribe_Filesystem::is_owned_by_current_process()`.

Each rule above is enforced by `scripts/verify-security-scan.php`
(security baseline) and `scripts/verify-mkdir-containment.php`
(FS containment). A regression that breaks one of these rules
surfaces as a CI failure independent of Plugin Check itself.

## CI integration

| Gate                                                | Job / step                       |
|-----------------------------------------------------|----------------------------------|
| Plugin Check CI contract (job exists, strict, etc.) | `composer test:plugin-check` (Phase 33) |
| Plugin Check warning triage (this doc + manifest)   | `composer test:plugin-check-triage` (Phase 64) |
| PHPUnit integration pin                            | `tests/Integration/SScribe_Plugin_Check_Triage_Test.php` |
| Release gate                                         | `bin/release-audit.sh` → `Plugin-Check-Triage` |

## How an independent auditor verifies this

Use the [exact-artifact capture and re-verification commands below](#exact-artifact-local-capture-release-hold)
from a clean checkout of the audited SHA. Retain the release ZIP, raw report,
and completed evidence sidecar together. The strict verifier checks the
checkout SHA, ZIP/report hashes, command exit status, and each finding's exact
code, plugin-relative file, severity, and allowed triage status. A code-only
comparison cannot establish those requirements.

## Exact-artifact local capture (release HOLD)

On the final published SHA, commit all tracked changes, build the release ZIP,
and use an isolated WordPress installation with Plugin Check installed. The
command extracts the ZIP into a fresh private temporary directory and checks
that directory, never an unrelated installed slug. It rejects unsafe ZIP paths
and symlinks, records HEAD and ZIP bytes before and after the command, captures
all output plus exit status, then runs the actual strict verifier:

```sh
php scripts/capture-plugin-check.php /absolute/path/release.zip /absolute/path/isolated-wordpress
```

The command sets `SSCRIBE_WP_ROOT` for the isolated installation and loads
`scripts/plugin-check-cli-bootstrap.php` through WP-CLI `--require` so the
official Plugin Check CLI can initialize runtime checks before WordPress loads.
Its `after_wp_load` hook switches this process to `en_US`, keeping the exact
upstream English clean-report marker stable without changing WordPress options.

For independent re-verification of the saved evidence:

```sh
SSCRIBE_RELEASE_CERTIFICATION=1 \
SSCRIBE_SOURCE_SHA="$(git rev-parse HEAD)" \
SSCRIBE_RELEASE_ZIP=/absolute/path/release.zip \
php scripts/verify-plugin-check-triage.php
```

The supplied source SHA must match checkout HEAD, tracked files must be clean,
and the sidecar must match actual ZIP/report SHA-256 bytes and completed exit
code zero. Missing evidence is BLOCKED and fails strict certification. This
capture does not establish release approval or replace the other exact-SHA
runtime gates. PHP, WordPress, WP-CLI, PHPUnit and POT regeneration were not run
in the implementation environment; the POT was updated from changed literals
and references. Locally run `php scripts/make-pot.php`, the focused PHPUnit
report/command/private-storage suites, and the full required runtime gates.
