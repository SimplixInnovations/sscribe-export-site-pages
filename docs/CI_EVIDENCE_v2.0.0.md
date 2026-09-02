# SScribe v2.0.0 — CI Evidence (audited SHA)

Captured 2026-09-02 on commit `a5c093ca5672171f932a5d458755acd3649b3594`
(short SHA `a5c093c`) of branch `release/2.0.0-final-hardening`.

Every gate below is reproducible via `bash bin/release-audit.sh`. The
script is intentionally side-effect-free (writes only to `/tmp/release-audit-*.log`)
so it can be run from any checkout.

## Gate results

| Gate                                | Duration     | Result          |
|-------------------------------------|--------------|-----------------|
| PHPUnit (full suite, Unit+Integration+Security) | 23,724 ms    | **997 pass + 21 skipped + 0 fail** |
| PHPStan level 7                     | 1,994 ms     | **0 errors**    |
| PHPCS (90 files scanned)            | 39,995 ms    | **0 violations** |
| ESLint + Stylelint                  | 7,703 ms     | **0 errors**    |
| Artifact Certification (9 tests)    | 1,216 ms     | **9 pass + 11 assertions** |
| Plugin-Check (WP.org official)      | 120,404 ms   | **0 errors found** |

6 / 6 gates green.

## PHPUnit composition

997 tests in 3668 assertions spread across the three testsuites:

- `Unit` — 700+ tests for invariant contracts (string ops, sanitizers,
  parsers, walkers, lock manager, etc.). Stand-alone, no WP needed.
- `Integration` — 200+ tests covering AJAX handlers, export pipeline
  end-to-end (with mocked providers), cron, filesystem.
- `Security` — 50+ tests for nonces, caps, file permissions, atomic
  rotation, session hijack defense.

The 21 skipped tests are platform-conditional (Windows-specific race
tests or WP-CLI-only paths that don't apply to the local testbed).

## PHPStan envelope

Level 7 fully clean:

```
$ vendor/bin/phpstan analyse --memory-limit=1G --no-progress

 [OK] No errors
```

The plugin source tree, the bundled Strauss-prefixed libraries, and the
build script all analyze at level 7 with 0 errors.

## PHPCS envelope

90 PHP files across `includes/`, `admin/`, `uninstall.php`, and
`sscribe-export-site-pages.php`. Zero style violations. The plugin
uses the project-local ruleset at `phpcs.xml` (WPCS + PSR-12 + sScribe
specific extensions for explicit type declarations and final classes).

## ESLint + Stylelint envelope

- `admin/js/*.js` — all script files clean against the project-local
  ESLint configuration (ES modules + no-unused-vars + prefer-const).
- `admin/css/*.css` — all stylesheets clean against the project-local
  Stylelint configuration (zero color-hex-length errors, zero
  disallowed patterns).

## Artifact certification

The ZIP in `dist/sscribe-export-site-pages-2.0.0.zip` matches:

- The staging directory `dist/sscribe-export-site-pages/`.
- The SHA256 sidecar `dist/sscribe-export-site-pages-2.0.0.sha256`.

Both files are first-class artifacts tracked by the build pipeline
(`scripts/build-release.php`). No dev-only paths (tests/, scripts/,
.github/, .git*, vendor/, .editorconfig, etc.) are in the ZIP.

## Plugin-Check evidence

Captured earlier in Phase 29 and reproducible via:

```bash
cd /c/tmp/wp-a11y
WP_CLI_PHP_ARGS="-d extension=pdo_sqlite -d extension=sqlite3" \
  /c/Users/Ahmed/AppData/Roaming/Composer/vendor/bin/wp \
    plugin check sscribe-export-site-pages --allow-root
```

Output (from a fresh run):

```
Success: Checks complete. No errors found.
```

All five originally-flagged issues (chmod × 2, rename × 1, unlink × 1,
error_log × 1) were either swapped for WP-canonical equivalents
(`wp_delete_file()`) or annotated with documented `phpcs:ignore`
comments justifying each direct PHP filesystem call.

## Reproducing

```bash
bash bin/release-audit.sh
```

Runs all gates sequentially with quiet stdout (full output captured to
`/tmp/release-audit-*.log`). Exits 0 only if all gates pass.

## Why this SHA was chosen

`a5c093c` is the final commit of Phase 32 (performance benchmarks
documentation). Subsequent commits will only land on top of this SHA
if they:

1. Don't reduce the PHPUnit pass count.
2. Don't introduce PHPStan errors at level 7.
3. Don't add PHPCS violations.
4. Don't break ESLint / Stylelint.
5. Don't reintroduce Plugin-Check errors.

A CI job wired to `bin/release-audit.sh` would be the natural
maintainer gate for that contract.

## What this file is NOT

This file is not a substitute for the actual CI pipeline in
`.github/workflows/`. It is the audit log captured at the audited SHA.
The exact-same gate (`bin/release-audit.sh`) is meant to be wired into
the CI for future commits so the green state is preserved by automation
rather than by re-running the audit manually each release.

## Linked certification artifacts

- `docs/BUILD_TRANSFORMATIONS.md` — what the build pipeline applies.
- `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md` — clean install evidence.
- `docs/SECURITY_MATRIX_v2.0.0.md` — security inventory + probes.
- `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md` — perf envelope.
- `tests/Unit/SScribe_Artifact_Certification_Test.php` — ZIP locks.
- `bin/release-audit.sh` — single-pass gate runner.
