# Plugin Check Warnings — v2.0.0

Auditable triage for every WordPress.org Plugin Check warning that
ships with the sScribe Export Site Pages submission.

## Why this exists

The release pipeline runs [`wordpress/plugin-check-action@v1`](https://github.com/wordpress/plugin-check-action)
in strict mode (with `include-experimental: true`) against the
build artifact at `./dist/sscribe-export-site-pages/`. Plugin Check
exits non-zero on any error OR warning. The action produces a JSON
report at `dist/plugin-check/plugin-check-report.json` that this
document reconciles against the triage table below.

A regression that introduces a NEW warning not listed here — or that
re-introduces a `fixed` warning — fails the
`composer test:plugin-check-triage` gate before the release tag
is cut.

## Triage process

1. CI runs Plugin Check on the built dist tree (Phase 33 contract).
2. The Plugin Check JSON report is persisted to
   `dist/plugin-check/plugin-check-report.json` and uploaded as a
   workflow artifact (`plugin-check-report`).
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
| Plugin Check exit code       | `0` (zero errors, zero warnings) |
| Strict mode                  | `true` |
| `include-experimental`       | `true` |
| Last audit run               | `bin/release-audit.sh` |
| Last audit log               | `/tmp/release-audit-plugincheck.log` |

## Warnings table

The table records warnings discovered during release hardening even when they
are fixed before the final certified ZIP. The final Plugin Check run must still
exit with zero errors and zero warnings.

| Warning code | Source | Severity | Status | Remediation | Owner |
|--------------|--------|----------|--------|-------------|-------|
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | `includes/class-sscribe-operational-logger.php` | warning | `fixed` | Replaced the dynamic `$GLOBALS[$lock_key]` shutdown recursion lock with class-scoped static state in v2.0.3. | Simplix Innovations |

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

```bash
# 1. Re-run Plugin Check against the published artifact.
wp plugin check dist/sscribe-export-site-pages --allow-root

# 2. Confirm the JSON report matches this doc.
diff <(jq -S '.[] | .code' dist/plugin-check/plugin-check-report.json | sort -u) \
     <(awk -F'|' '/^\| / && $2 !~ /Warning code/ && $2 !~ /_/ { gsub(/ /, "", $2); print $2 }' \
        docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md | sort -u)

# 3. Run the triage gate.
composer test:plugin-check-triage
```

A clean diff + a green gate = the Plugin Check contract holds.
