# SScribe v2.0.0 — Local Final Release Checklist

GitHub Actions availability is not a prerequisite for merging the finished
source. It **is** still necessary to verify the final merged source/package
before tagging or uploading to WordPress.org. Run this checklist from a clean
checkout of the final `main` SHA.

## 1. Clean dependency install

```bash
composer install --no-interaction --no-progress
npm ci --ignore-scripts
composer audit --locked --format=plain --abandoned=fail
npm run test:audit-helper
npm run audit:js
```

## 2. Build-time vendor isolation

```bash
composer vendor:prefix
php scripts/verify-strauss-config.php --built
```

## 3. Core source validation

```bash
composer test
composer stan
composer cs
composer i18n:check
composer test:a11y
composer test:format-matrix
composer test:perf
```

All commands must exit 0. Do not remove tests, lower thresholds, add broad
suppressions, or convert failures into warnings to obtain a green result.

## 4. Real WordPress minimum/current validation

Install the SQLite-backed WordPress testbench:

```bash
composer test:wp:install
composer test:wp
composer test:wp:uninstall
```

At minimum, separately confirm the declared minimum pair **WordPress 6.1 /
PHP 8.2** and the current tested target **WordPress 7.1**.

## 5. Build the exact submission package

```bash
composer release:prepare
```

The canonical artifact is:

```
dist/sscribe-export-site-pages-2.0.0.zip
```

Record its SHA-256 sidecar. Do not test one ZIP and upload another.

## 6. Official WordPress Plugin Check

Create or choose a clean WordPress installation that has the official Plugin
Check plugin installed, then set:

```bash
export SSCRIBE_WP_ROOT=/absolute/path/to/wordpress
# Optional when wp is not already on PATH:
export SSCRIBE_WP_BIN=/absolute/path/to/wp
```

Install/activate the **exact** v2.0.0 ZIP in that WordPress instance and run:

```bash
bash bin/release-audit.sh
```

The release audit is fail-closed if Plugin Check cannot run.

## 7. Browser/runtime export validation

```bash
npx playwright install chromium --with-deps
npm run test:e2e:smoke
npm run test:e2e:full
```

Manually verify on the production-like site as well:

- Auto-download is OFF after a fresh page load.
- Enabling Auto-download downloads exactly once; disabling it does not.
- Pages / Posts / All Types counts are correct.
- All Languages works for counts, Preview, Start, batch processing, finalize,
  summary and the exported object count.
- Status counts respect selected Content Type + Language.
- Preview count equals Export Summary and the actual export total.
- A hard preflight error cannot be bypassed.
- Warning-only preflight requires explicit Continue.
- Debug OFF → ON refreshes the console state.
- ERROR filter with zero matches shows a filter-empty state, not a Debug-off
  prompt.
- Run DOCX, PDF, HTML, Markdown and All Formats.
- Test a small export and a representative larger multilingual export.
- Confirm no repeated 429/500 retry storm.
- If a terminal failure occurs, confirm a request reference is visible and the
  operational log contains the correlated failure without secrets/full paths.
- Download token is single-use and the archive is inaccessible without the
  authorized flow.

## 8. WordPress.org reviewer source access

Before uploading the ZIP, make the exact v2.0.0 source/build inputs accessible
to WordPress.org reviewers. The working repository may remain private, but the
reviewer-accessible source snapshot/mirror must include the exact corresponding
source plus build tooling/documentation used to produce the ZIP.

## 9. Close release blockers

After the checks above pass, update
`docs/RELEASE_BLOCKERS_v2.0.0.md` so every DEFERRED row is RESOLVED with the
actual local evidence (final SHA, command/result, ZIP SHA-256, and runtime
environment). Then run:

```bash
composer test:release-blockers
```

It must exit 0 before creating `v2.0.0` or uploading to WordPress.org.

## Release rule

**Merge readiness and release readiness are different.** The source may be
merged once reviewed, but no release tag / WordPress.org upload should happen
until every item above is complete on the final merged SHA.
