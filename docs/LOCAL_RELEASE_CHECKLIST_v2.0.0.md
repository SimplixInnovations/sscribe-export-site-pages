# SScribe Local Final Release Checklist — governance schema v2.0.0

> **Release identity:** derive the current release from `SSCRIBE_VERSION`. The `_v2.0.0` filename denotes the checklist schema, not the artifact version.

GitHub Actions availability is not a prerequisite for merging the finished
source. It **is** still necessary to verify the exact final source/package
before tagging or uploading to WordPress.org.

Run this checklist from a clean `main` checkout after the reviewed release pull request has merged. Any tracked fix must go through a new transient pull-request branch before final evidence is regenerated. The certified source SHA is `origin/main`.

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

Resolve the release version once at the start and substitute it for `{VERSION}` below. The authoritative value is the `SSCRIBE_VERSION` constant in `sscribe-export-site-pages.php`.

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
PHP 8.2** and the latest maintained **WordPress 7.1.x** release (currently 7.1.2). Exercise every PHP
version required by the repository's supported/CI matrix when those runtimes
are available locally.

## 5. Build the preliminary exact package

```bash
composer release
```

The canonical artifact is:

```text
dist/sscribe-export-site-pages-{VERSION}.zip
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
"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin install   "$PWD/dist/sscribe-export-site-pages-{VERSION}.zip" --force --activate

"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin check   sscribe-export-site-pages 2>&1 | tee dist/evidence/plugin-check-preliminary.log
```

The final `bash bin/release-audit.sh` comes later, after strict evidence has
been prepared.

## 7. Preliminary browser/runtime and clean-install validation

Run the automated browser suite against the preliminary package:

```bash
npx playwright install chromium --with-deps
npm run test:e2e:smoke
npm run test:e2e:full
```

Also exercise the complete Phase 69 runbook in
`docs/MANUAL_RUNTIME_TESTS_v2.0.0.md`: Standard WordPress, WordPress + WPML,
Redis object cache ON, Redis object cache OFF, OpenLiteSpeed, and
Cloudflare/proxy. At this preliminary stage the purpose is to expose defects
before the source is frozen. Do not create final certification JSON yet.

The production-like validation must include DOCX, PDF, HTML, Markdown, All
Formats, All Languages, Arabic/RTL, retry/finalize behavior, single-use
downloads, unauthorized-download rejection, Debug OFF/ON behavior,
deactivate/reactivate, and uninstall cleanup.

Any defect found here requires a tracked fix through a transient pull request
before final certification.

## 8. WordPress.org reviewer source access

Before upload, make the exact current-release source and build inputs publicly
accessible and maintained, as required when build tooling is omitted from the
deployed ZIP.

The canonical repository may be public, or an equivalent maintained public
source mirror may be used, but it must expose the exact source corresponding to
the release plus `composer.json`, `scripts/build-release.php`, and
`docs/BUILD_TRANSFORMATIONS.md`. Anonymous HTTP access must work.

Do not describe a private/reviewer-only source location as satisfying this
requirement.

## 9. Resolve tracked source/governance issues, then freeze source

Dynamic Phase 70 rows deliberately remain `DEFERRED` in
`docs/RELEASE_BLOCKERS_v2.0.0.md`; do **not** flip them to `RESOLVED` in
tracked Markdown. Strict certification upgrades them at runtime from ignored
`dist/` evidence. Editing the tracked registry after exact evidence is
generated would change the source SHA and invalidate that evidence.

Before freezing, finish every legitimate tracked source/documentation fix and
reconcile repository governance documented by the branch-policy/protection
contracts. Any tracked correction must merge through a transient pull request.

Then verify:

```bash
git status --short
git rev-parse HEAD
```

The tracked working tree must be clean. The resulting commit is the candidate
final source SHA. From this point onward, any tracked change invalidates final
evidence and requires certification to restart on the new SHA.

## 10. Re-run final signals on the exact frozen SHA and exact ZIP

Resolve and retain the frozen identity:

```bash
set -euo pipefail
FINAL_SHA="$(git rev-parse HEAD)"
VERSION="$(php -r '$s=file_get_contents("sscribe-export-site-pages.php"); preg_match("/define\\s*\\(\\s*[\x27\x22]SSCRIBE_VERSION[\x27\x22]\\s*,\\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/",$s,$m); echo $m[1] ?? "";')"
printf 'FINAL_SHA=%s\nVERSION=%s\n' "$FINAL_SHA" "$VERSION"

composer install --no-interaction --no-progress
composer vendor:prefix
git status --short
```

If dependency preparation changes tracked files, stop, review the change, merge
the legitimate correction, and restart from the new `origin/main`.

Build the exact package. Because the builder cleans `dist/`, capture build
output outside `dist/` first:

```bash
mkdir -p .cache
composer release 2>&1 | tee .cache/final-build.log
BUILD_TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

mkdir -p dist/evidence
cp .cache/final-build.log dist/evidence/build.log

ZIP="dist/sscribe-export-site-pages-${VERSION}.zip"
ZIP_SHA="$(sha256sum "$ZIP" | awk '{print $1}')"
printf 'ZIP=%s\nZIP_SHA=%s\n' "$ZIP" "$ZIP_SHA"
```

Run every final signal against this same frozen checkout/artifact and save
non-empty proof:

```bash
composer version:check 2>&1 | tee dist/evidence/version-check.log

# Run the repository-required PHP syntax/version matrix (8.2, 8.3, 8.4, 8.5)
# and save its consolidated output:
#   dist/evidence/lint.log

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

"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin install \
  "$PWD/$ZIP" --force --activate

"${SSCRIBE_WP_BIN:-wp}" --path="$SSCRIBE_WP_ROOT" plugin check \
  sscribe-export-site-pages 2>&1 | tee dist/evidence/plugin-check.log

{
  npm run test:e2e:smoke
  npm run test:e2e:full
} 2>&1 | tee dist/evidence/e2e.log
```

Now repeat the **actual final** clean-install/runtime work on `$ZIP`:

- execute the clean-install lifecycle and write
  `dist/evidence/clean-install.md`;
- exercise DOCX, PDF, HTML, Markdown, All Formats, All Languages, Arabic/RTL,
  retry, finalize, single-use download, and unauthorized-download rejection,
  writing `dist/evidence/runtime-exports.log`;
- execute all six Phase 69 environments from
  `docs/MANUAL_RUNTIME_TESTS_v2.0.0.md`, writing
  `dist/evidence/manual-runtime.log`.

These logs must describe the exact environment, actions/commands, and observed
PASS/FAIL result. The clean-install and runtime-export logs must include the
literal `$FINAL_SHA` and `$ZIP_SHA` values. The manual runtime log must also
include those two values and these exact markers after successful execution:

```text
[standard_wordpress] PASS
[wpml] PASS
[redis_on] PASS
[redis_off] PASS
[openlitespeed] PASS
[cloudflare_proxy] PASS
```

Do not mark an unexecuted environment PASS.

Finally prove the source did not move:

```bash
git status --short
test "$(git rev-parse HEAD)" = "$FINAL_SHA"
test "$(sha256sum "$ZIP" | awk '{print $1}')" = "$ZIP_SHA"
```

## 11. Create the ignored exact-release evidence bundle

All files in this section stay untracked under `dist/`. Do not put exact
SHA/checksum/status values into tracked Markdown.

### 11.1 Final execution state

Create `dist/final-execution-evidence.json`:

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

When a signal executed successfully on GitHub Actions for this **exact final
SHA**, `SUCCESS` plus its concrete HTTPS run URL may be used instead of
`LOCAL_PASS`.

### 11.2 Artifact / Plugin Check evidence

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

### 11.3 Exact clean-install evidence

Create `dist/clean-install-evidence.json`; `source_sha` and
`zip_sha256` are mandatory identity fields:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "zip_sha256": "<EXACT_64_HEX_ZIP_SHA256>",
  "generated_at": "<ISO_8601_TIMESTAMP>",
  "checks": {
    "activation": "PASS",
    "deactivation": "PASS",
    "no_fatal": "PASS",
    "no_warning_attributable": "PASS",
    "db_tables_present": "PASS",
    "capabilities_present": "PASS",
    "cron_hooks_present": "PASS",
    "admin_ui_loads": "PASS",
    "export_basic": "PASS",
    "reactivate_no_duplicates": "PASS",
    "uninstall_cleanup": "PASS"
  }
}
```

### 11.4 Runtime-export evidence

Create `dist/runtime-export-evidence.json`:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "zip_sha256": "<EXACT_64_HEX_ZIP_SHA256>",
  "generated_at": "<ISO_8601_TIMESTAMP>",
  "checks": {
    "activation": "PASS",
    "docx": "PASS",
    "pdf": "PASS",
    "html": "PASS",
    "markdown": "PASS",
    "all_formats": "PASS",
    "all_languages": "PASS",
    "arabic_rtl": "PASS",
    "retry_behavior": "PASS",
    "finalize": "PASS",
    "single_use_download": "PASS",
    "unauthorized_download_rejected": "PASS"
  }
}
```

### 11.5 Six-environment Phase 69 evidence

Create `dist/manual-runtime-evidence.json`. Every `evidence` value must point
to an actual observation recorded in the non-empty
`dist/evidence/manual-runtime.log`:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "zip_sha256": "<EXACT_64_HEX_ZIP_SHA256>",
  "generated_at": "<ISO_8601_TIMESTAMP>",
  "environments": {
    "standard_wordpress": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#standard-wordpress"},
    "wpml": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#wpml"},
    "redis_on": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#redis-on"},
    "redis_off": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#redis-off"},
    "openlitespeed": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#openlitespeed"},
    "cloudflare_proxy": {"status":"PASS","evidence":"dist/evidence/manual-runtime.log#cloudflare-proxy"}
  }
}
```

### 11.6 Public source/build-transparency evidence

After anonymous HTTP verification of the public source and build inputs, create
`dist/source-transparency-evidence.json`:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "public_url": "https://github.com/SimplixInnovations/sscribe-export-site-pages/tree/<FINAL_40_HEX_SHA>",
  "composer_json_url": "https://github.com/SimplixInnovations/sscribe-export-site-pages/blob/<FINAL_40_HEX_SHA>/composer.json",
  "build_script_url": "https://github.com/SimplixInnovations/sscribe-export-site-pages/blob/<FINAL_40_HEX_SHA>/scripts/build-release.php",
  "build_doc_url": "https://github.com/SimplixInnovations/sscribe-export-site-pages/blob/<FINAL_40_HEX_SHA>/docs/BUILD_TRANSFORMATIONS.md",
  "checks": {
    "public_url": "PASS",
    "composer_json": "PASS",
    "build_script": "PASS",
    "build_doc": "PASS"
  }
}
```

Do not create a PASS value from assumption. Each dynamic evidence file must
reflect an execution or external access check that actually happened.

## 12. Strict final certification

First prove the tracked tree is still clean:

```bash
git status --short
test "$(git rev-parse HEAD)" = "$FINAL_SHA"
```

Run every fail-closed exact-release gate:

```bash
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:manual-runtime-tests
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:agent-final-report
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:auditor-handoff
SSCRIBE_RELEASE_CERTIFICATION=1 composer release:audit
```

The release audit also requires the official Plugin Check WordPress testbench
(`SSCRIBE_WP_ROOT`, and `SSCRIBE_WP_BIN` when `wp` is not on PATH).

Inspect at minimum:

- `dist/manual-runtime-tests-manifest.json`
- `dist/release-blockers-manifest.json`
- `dist/final-ci-state-manifest.json`
- `dist/exact-artifact-evidence-manifest.json`
- `dist/agent-final-report-manifest.json`
- `dist/auditor-handoff-manifest.json`

Every strict manifest must have zero errors and report release readiness where
applicable. A failure means **do not tag**.

## 13. Branch closure and immutable tag rule

After strict certification passes, make no tracked changes.

Verify local and remote source identity and branch topology:

```bash
git fetch origin --prune --tags
git switch main
git reset --hard origin/main
git rev-parse HEAD
git rev-parse origin/main
git status --short --branch
git branch -a
git worktree list
```

Required result:

- local `HEAD` equals `origin/main` and the exact certified SHA;
- the working tree is clean;
- `main` is the only persistent long-lived branch;
- completed audit/release/feature/hotfix branches are deleted;
- no extra worktree carries an authoritative divergent ref.

Only then create the annotated immutable current-version tag through the guarded
helper:

```bash
composer release:tag
```

For this release that helper must resolve the version from
`SSCRIBE_VERSION` (currently 2.0.4), re-run the strict certification gates as a
fail-closed admission guard, create `v{VERSION}` on the exact certified
`origin/main` HEAD, and push it without force. The tag-triggered
`.github/workflows/release.yml` must then complete verify → audit → test →
certify → publish successfully. Submit to WordPress.org only the exact
certified ZIP from that release, with the same SHA-256 as the certification
evidence.

## Release rule

**Merge readiness and release readiness are different.** The source may be
merged once reviewed, but no release tag / WordPress.org upload may happen
until every item above is complete on one immutable final SHA and the generated
strict evidence binds to that exact SHA/artifact.
