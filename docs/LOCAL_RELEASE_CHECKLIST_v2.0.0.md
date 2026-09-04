# SScribe v2.0.0 — Local Final Release Checklist

GitHub Actions availability is not a prerequisite for merging the finished
source. It **is** still necessary to verify the exact final source/package
before tagging or uploading to WordPress.org.

Run this checklist from `develop`. Any tracked fix must be committed before
final evidence is generated. At final closure, `main` is fast-forwarded to
the exact certified `develop` SHA.

For Bash/Git Bash evidence commands, enable fail-fast pipeline behavior before
using `tee`, otherwise a failing command can be masked by a successful `tee`:

```bash
set -euo pipefail
```

> **Critical evidence rule:** never write the exact final SHA/checksum/statuses
> into tracked release-evidence Markdown and commit them. Final exact-SHA proof
> lives in gitignored `dist/` evidence files and generated manifests. If any
> tracked file changes after final evidence is generated, discard that evidence,
> commit the source change, and repeat final certification.

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

## 3. Preliminary source validation

```bash
composer version:check
composer test
composer stan
composer cs
composer i18n:check
composer test:a11y
composer test:format-matrix
composer test:perf
composer test:coverage
composer test:coverage:check
npm run lint
```

All commands must exit 0. Do not remove tests, lower thresholds, add broad
suppressions, or convert failures into warnings to obtain a green result.

## 4. Real WordPress minimum/current validation

Install the repository WordPress testbench and run it:

```bash
composer test:wp:install
composer test:wp
composer test:wp:uninstall
```

At minimum, separately confirm the declared minimum pair **WordPress 6.1 /
PHP 8.2** and the current tested target **WordPress 7.1**. Exercise every PHP
version required by the repository's supported/CI matrix when those runtimes
are available locally.

## 5. Build the preliminary exact package

```bash
composer release
```

The canonical artifact is:

```text
dist/sscribe-export-site-pages-2.0.0.zip
```

Do not test one ZIP and upload another.

## 6. Official WordPress Plugin Check

Create or choose a clean WordPress installation with the official Plugin Check
plugin installed. Set:

```bash
export SSCRIBE_WP_ROOT=/absolute/path/to/wordpress
# Optional when wp is not already on PATH:
export SSCRIBE_WP_BIN=/absolute/path/to/wp
```

Install/activate the exact ZIP and run Plugin Check directly. Save the output
under the ignored evidence directory:

```bash
mkdir -p dist/evidence
"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin install   "$PWD/dist/sscribe-export-site-pages-2.0.0.zip" --force --activate

"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin check   sscribe-export-site-pages 2>&1 | tee dist/evidence/plugin-check-preliminary.log
```

The final `bash bin/release-audit.sh` comes later, after strict evidence has
been prepared.

## 7. Browser/runtime and clean-install validation

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

Write the executed clean-install/runtime result to an **untracked** evidence
file such as `dist/evidence/clean-install.md`. It must describe the exact ZIP,
environment, commands/actions performed, and pass/fail result.

## 8. WordPress.org reviewer source access

Before upload, make the exact v2.0.0 source and build inputs publicly accessible
and maintained, as required by the WordPress.org plugin guidelines when build
tooling is omitted from the deployed ZIP.

The canonical repository may be made public, or an equivalent maintained
public source mirror may be used, but it must contain the exact corresponding
tagged source plus the build tooling/documentation used to produce the ZIP.
Private/reviewer-only access is not sufficient for this release.

## 9. Resolve tracked release blockers, then freeze source

After preliminary checks and external requirements are genuinely satisfied,
update `docs/RELEASE_BLOCKERS_v2.0.0.md` so every DEFERRED row that is actually
closed becomes RESOLVED with a truthful evidence description.

Do **not** put the final commit SHA, ZIP checksum, or other self-referential
final values into this tracked document.

Commit every tracked source/documentation change. Then verify:

```bash
git status --short
git rev-parse HEAD
```

The tracked working tree must be clean. The resulting commit is now the
candidate final source SHA.

If blocker 18 (public source/build access) is not actually resolved, stop:
the release is not ready.

## 10. Re-run the required release signals on the exact frozen SHA

Because committing blocker/source changes creates a new SHA, rerun final
release evidence after the source is frozen.

First regenerate dependency/build inputs from the locked source and ensure they
do not dirty tracked files:

```bash
FINAL_SHA="$(git rev-parse HEAD)"
printf '%s\n' "$FINAL_SHA"

composer install --no-interaction --no-progress
composer vendor:prefix
git status --short
```

If `git status --short` shows any tracked change, stop. Review and commit the
legitimate change, then restart section 10 from the new SHA.

The real package builder is `composer release`
(`php scripts/build-release.php`). **Do not use `composer release:prepare`
for packaging**; that command is the interactive version-bump preparation
workflow.

Because the builder intentionally cleans `dist/`, capture its output outside
`dist/` first, then copy it back after the build:

```bash
mkdir -p .cache
composer release 2>&1 | tee .cache/final-build.log
BUILD_TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

mkdir -p dist/evidence
cp .cache/final-build.log dist/evidence/build.log
```

The exact artifact must now be:

```text
dist/sscribe-export-site-pages-2.0.0.zip
dist/sscribe-export-site-pages-2.0.0.sha256
```

Now run and save the remaining required signal evidence against this same
frozen SHA/artifact:

```bash
composer version:check 2>&1 | tee dist/evidence/version-check.log

# Run the repository-required PHP syntax/version matrix here and save its
# consolidated output to dist/evidence/lint.log. The current CI contract
# validates syntax on PHP 8.2, 8.3, 8.4 and 8.5.

composer test 2>&1 | tee dist/evidence/test.log

{
  composer audit --locked --format=plain --abandoned=fail
  npm run test:audit-helper
  npm run audit:js
} 2>&1 | tee dist/evidence/audit.log

{
  npm ci --ignore-scripts
  npm run test:audit-helper
  npm run audit:js
  npm run lint
} 2>&1 | tee dist/evidence/frontend-quality.log

{
  composer test:wp:install
  composer test:wp
  composer test:wp:uninstall
} 2>&1 | tee dist/evidence/real-wp-tests.log

{
  composer test:coverage
  composer test:coverage:check
} 2>&1 | tee dist/evidence/coverage.log
```

Run official Plugin Check against the already-built exact ZIP and save the
final output:

```bash
"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin install \
  "$PWD/dist/sscribe-export-site-pages-2.0.0.zip" --force --activate

"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin check \
  sscribe-export-site-pages 2>&1 | tee dist/evidence/plugin-check.log
```

Repeat the clean-install smoke using this exact final ZIP and write the
executed result to `dist/evidence/clean-install.md`.

Then run final E2E:

```bash
{
  npm run test:e2e:smoke
  npm run test:e2e:full
} 2>&1 | tee dist/evidence/e2e.log
```

All commands must exit 0. Verify the tracked tree is still clean:

```bash
git status --short
test "$(git rev-parse HEAD)" = "$FINAL_SHA"
```

If any tracked source fix is needed, stop, commit the fix, delete stale final
evidence, and restart section 10.

## 11. Create untracked exact-SHA evidence inputs

Do not edit the tracked Phase 71/72 Markdown templates.

Create `dist/final-execution-evidence.json` using the actual output of
`git rev-parse HEAD`.

When GitHub never started the corresponding jobs, use `LOCAL_PASS` and the
non-empty local logs:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "generated_at": "<ISO_8601_TIMESTAMP>",
  "signals": {
    "version-check": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/version-check.log"},
    "lint": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/lint.log"},
    "test": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/test.log"},
    "audit": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/audit.log"},
    "frontend-quality": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/frontend-quality.log"},
    "real-wp-tests": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/real-wp-tests.log"},
    "coverage": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/coverage.log"},
    "plugin-check": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/plugin-check.log"},
    "e2e": {"status":"LOCAL_PASS","evidence":"local:dist/evidence/e2e.log"}
  }
}
```

If a GitHub job actually executed successfully on the exact final SHA, use
`SUCCESS` and its concrete `https://` execution URL instead.

Create `dist/release-certification-evidence.json`:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "builder_run_id": "local:dist/evidence/build.log",
  "builder_workflow": "composer release",
  "plugin_check_url": "local:dist/evidence/plugin-check.log",
  "clean_install_doc": "dist/evidence/clean-install.md",
  "build_timestamp": "<ISO_8601_TIMESTAMP>"
}
```

Both JSON files are ignored by Git and must remain uncommitted.

## 12. Strict final certification

First verify the tracked tree is still clean:

```bash
git status --short
```

Then run:

```bash
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence
```

Inspect:

- `dist/release-blockers-manifest.json`
- `dist/final-ci-state-manifest.json`
- `dist/exact-artifact-evidence-manifest.json`

All must report zero errors and strict release readiness where applicable.

Finally run the full fail-closed audit:

```bash
bash bin/release-audit.sh
```

The audit requires the official Plugin Check testbench configuration and must
exit 0.

## 13. Branch closure and tag rule

After strict certification passes, make no tracked changes.

Fast-forward `main` to the exact certified `develop` commit and push both.
Then verify:

```bash
git fetch origin --prune
git rev-parse origin/main
git rev-parse origin/develop
git diff --stat origin/main..origin/develop
git rev-list --left-right --count origin/main...origin/develop
```

Required result:

- both branches resolve to the exact certified SHA;
- no diff;
- ahead/behind is `0 0`.

Only after that may `v2.0.0` be created, subject to the repository tag policy,
signing requirements, and WordPress.org source-transparency requirement.

## Release rule

**Merge readiness and release readiness are different.** The source may be
merged once reviewed, but no release tag / WordPress.org upload may happen
until every item above is complete on one immutable final SHA and the generated
strict evidence binds to that exact SHA/artifact.
