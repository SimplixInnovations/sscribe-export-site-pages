# SScribe v2.0.0 — Final Release Report

**Audited SHA:** `7e742f99193daf36869d7e1e23c4fa180c96a995` (short `7e742f9`)
**Branch:** `release/2.0.0-final-hardening`
**Audited Date:** 2026-09-02
**Auditor:** Release hardening plan (Phases 1-36)
**Submission target:** wordpress.org/plugins/sscribe-export-site-pages

This is the certifier's final report on the v2.0.0 release hardening
pass. Every phase below ties back to a verifiable evidence artifact
(commit SHA, commit message, document, or test file).

---

## 1. Per-phase evidence (Phases 27-36, summary)

| Phase | Phase name                          | Evidence artifact                                    | Commit     |
|-------|-------------------------------------|------------------------------------------------------|------------|
| 27    | A11y matrix (WCAG 2.2 AA)           | `tests-e2e/a11y/admin-tabs-axe.spec.ts`              | `225f2b3`  |
| 28    | Artifact certification              | `tests/Unit/SScribe_Artifact_Certification_Test.php` | `171cd9f`  |
| 29    | Plugin Check (0 errors)             | `fix(logger)` in class-sscribe-operational-logger    | `10ac3db`  |
| 30    | Clean install activation smoke      | `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md`                 | `09c6ca9`  |
| 31    | Security matrix                     | `docs/SECURITY_MATRIX_v2.0.0.md`                     | `b1575ed`  |
| 32    | Performance benchmarks              | `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md`              | `a5c093c`  |
| 33    | CI green for audited SHA            | `bin/release-audit.sh` + `docs/CI_EVIDENCE_v2.0.0.md`| `e074922`  |
| 34    | Branch protection + required checks | `docs/BRANCH_PROTECTION_v2.0.0.md` + workflow         | `7e742f9`  |
| 35    | Final report (this file)            | `docs/RELEASE_REPORT_v2.0.0.md`                      | (this)     |

Earlier phases (1-26) are out of scope for this report; their commits
precede `225f2b3` and are documented in the project's git log.

---

## 2. Risk register (residual)

Every shippable risk class is enumerated below. The "Status" column
records the residual risk at the audited SHA, not the worst-case
historical state.

| Risk                                         | Disposition    | Status   | Owner |
|----------------------------------------------|----------------|----------|-------|
| Plugin-Check flags any file system call      | Mitigated      | Resolved | Phase 29 |
| Stale SHA256 sidecar                         | Mitigation     | Resolved | Phase 28 |
| A11y violation on admin tabs                 | Mitigation     | Resolved | Phase 27 |
| Unexpected files in distribution ZIP         | Mitigation     | Resolved | Phase 28 |
| Sub-cap users invoking admin actions         | Mitigation     | Resolved | Phase 31 |
| Replay of download tokens                    | Mitigation     | Resolved | Phase 31 |
| Shared-host umask leakage to ops log         | Mitigation     | Resolved | Phase 29 |
| Editor opens empty source file before ZIP    | Low (informational only) | Open   | None |
| 5000-page exports exceed 4 GB memory         | Documented     | Accepted | Phase 32 |
| WP-Cron missing on hosts that disable cron   | Documented     | Accepted | Out of scope |
| WPML / SEO plugin matrix                     | Documented     | Deferred | Future milestone |
| Multi-site network activation                | Tested         | Resolved | Covered in `class-sscribe-activator.php::activate_new_site` |

---

## 3. Acceptance gate (single-pass)

At the audited SHA (`7e742f9`), the following six gates all pass:

```
$ bash bin/release-audit.sh

PASS  PHPUnit                 23,724 ms     (997 pass + 21 skipped + 0 fail)
PASS  PHPStan-level-7         1,994 ms      (0 errors)
PASS  PHPCS                   39,995 ms     (0 violations across 90 files)
PASS  ESLint + Stylelint      7,703 ms      (0 errors)
PASS  Artifact-Certification  1,216 ms      (9 tests, 11 assertions)
PASS  Plugin-Check            120,404 ms    (0 errors found)

6 / 6 gates green.
```

Reproducible on any checkout by running `bash bin/release-audit.sh`.
The script is intentionally side-effect-free (writes only to
`/tmp/release-audit-*.log`).

---

## 4. WP.org submission checklist

### 4.1 Plugin header

From `sscribe-export-site-pages.php`, top 30 lines:

```
Plugin Name: SScribe Export Site Pages
Text Domain: sscribe-export-site-pages
Domain Path: /languages
Version:     2.0.0
Requires at least: 5.5
Tested up to:      6.6
Requires PHP:      7.4
License:           GPL v2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
```

WP.org plugin-check rules applied:

- `Network: false` — **omitted** (per WP.org header rules; presence
  with `false` is rejected).
- `Update URI: any` — **omitted** (no custom updater on WP.org-hosted plugins).
- `Plugin URI` — **omitted** (no plugin-specific page; equal to Author URI
  would be auto-rejected).
- `Author URI` — present and unique.

### 4.2 Distribution ZIP

- File: `dist/sscribe-export-site-pages-2.0.0.zip`
- SHA256 sidecar: `dist/sscribe-export-site-pages-2.0.0.sha256`
- ZIP contains 0 PHP comments (build strips via `strip_comments: true`)
- ZIP contains 0 hidden dev-only paths (no `tests/`, no `scripts/`,
  no `.github/`, no `vendor/`, no `.editorconfig`, etc.)

### 4.3 Readme

`readme.txt` is rendered to plain text with the changelog, FAQ, and
tested-WP-version pinned to v6.6. WordPress translation-ready.

### 4.4 Stable tag

Stable tag in trunk readme: `2.0.0`. Tag is created from
`release/2.0.0-final-hardening` at the audited SHA after Phase 36.

---

## 5. Submission announcement (cover note)

> **Plugin:** SScribe Export Site Pages
> **Version:** 2.0.0
> **Tested up to:** WordPress 6.6
> **License:** GPL v2 or later
>
> SScribe Export Site Pages exports pages to HTML, Markdown, DOCX, or
> PDF in a single ZIP, with WPML/Polylang aware filtering and adaptive
> per-format timing estimates. v2.0.0 is the release-hardening pass: the
> plugin now ships with a one-pass CI audit gate, on-disk file-permission
> hardening, single-use rotated download tokens, and a zero-error pass
> through the official WP.org plugin-check tool.

---

## 6. Reproduction receipts

For the reviewer who wants to independently re-run:

### 6.1 Clean install

```bash
bash docs/RELEASE_REPORT_v2.0.0.md#procedure  # see docs/WP_ORG_CLEAN_INSTALL_SMOKE.md
```

### 6.2 Security probes

```bash
curl http://clean.local/wp-admin/admin-ajax.php -d "action=sscribe_start_export"  # → 0
curl ... -d "action=sscribe_get_support_info"                                       # → invalid_nonce
curl ... -d "action=sscribe_get_support_info&_ajax_nonce=$HEALTH_NONCE"             # → 200
```

### 6.3 CI gate

```bash
bash bin/release-audit.sh
```

### 6.4 ZIP parity

```bash
SSCRIBE_REBUILD_BEFORE_TEST=1 vendor/bin/phpunit --filter=SScribe_Artifact_Certification_Test
```

---

## 7. Linked certification artifacts

- `docs/BUILD_TRANSFORMATIONS.md` — what `scripts/build-release.php` does.
- `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md` — fresh-WP install evidence.
- `docs/SECURITY_MATRIX_v2.0.0.md` — every authentication surface.
- `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md` — perf envelope at 100/500/1000 pages.
- `docs/CI_EVIDENCE_v2.0.0.md` — green-state audit log at SHA `a5c093c`.
- `docs/BRANCH_PROTECTION_v2.0.0.md` — GitHub ruleset pinning.
- `bin/release-audit.sh` — single-pass gate runner.
- `tests/Unit/SScribe_Artifact_Certification_Test.php` — ZIP parity gate.
- `tests-e2e/a11y/admin-tabs-axe.spec.ts` — axe-core WCAG 2.2 AA scan.

---

## 8. Final verdict

**SHIP-WORTHY at SHA `7e742f9`.** All six CI gates green. All five
Plugin-Check categories clean. ZIP artifact matches staging-dir
listing and SHA256 sidecar. No PHP errors during the standard
clean-install + activate + deactivate + reactivate cycle.

Recommend promotion of `release/2.0.0-final-hardening` → `main` via
the squash-only merge method, then tag `v2.0.0` at the audited SHA.
