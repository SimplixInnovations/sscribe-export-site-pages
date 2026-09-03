# SScribe 2.0.0 Final Release Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a new exact SScribe 2.0.0 release candidate in which every independently verified runtime, CI, E2E, compatibility, observability, and submission-package blocker is closed and proven by the exact SHA.

**Architecture:** Keep the existing export architecture intact. Add one small server request-boundary language normalizer and one narrowly scoped browser hardening module, directly fix the small fatal-handler/configuration/workflow files whose contracts are wrong, and add runtime/real-WordPress/E2E regressions that exercise production behavior rather than static comments. Release certification remains fail-closed: no mandatory gate may be skipped or weakened.

**Tech Stack:** WordPress/PHP 8.2+, PHPUnit 11, WordPress Core test suite, JavaScript/jQuery, Playwright, GitHub Actions, Composer/Strauss, npm, WordPress Plugin Check.

**Spec:** `docs/superpowers/specs/2026-09-03-final-release-blockers-design.md`

## Global Constraints

- Baseline is `bd0e3bf39fb99e9621c665e6a2e5c6b4a8fd4bcb`; all implementation happens on `fix/2.0.0-release-blockers-final`.
- Do not delete tests, add `continue-on-error`, lower PHPStan/coverage thresholds, disable warning failures, or bypass security/package audits.
- `__all__` remains the browser/count transport sentinel; server query operations translate it to the internal empty-language representation before ordinary validation.
- Preflight `error` or `can_proceed=false` is terminal; warning-level diagnostics alone may be explicitly overridden.
- WordPress minimum target is 6.1 with PHP 8.2 and must be exercised in a real WordPress testbench.
- Exact release ZIP, Plugin Check, real WordPress, E2E, and CI results must all belong to the final SHA.
- Do not make the private repository public without an explicit owner decision; satisfy source/build transparency through shipped reproducibility material when technically acceptable.

---

### Task 1: Lock the All-Languages server request contract

**Files:**
- Create: `tests/Unit/SScribe_Language_Request_Test.php`
- Create: `tests-wp/WordPress/SScribe_All_Languages_Request_Test.php`
- Create: `includes/class-sscribe-language-request.php`
- Modify: `sscribe-export-site-pages.php`

**Interfaces:**
- Produces: `SScribe_Language_Request::register(): void`
- Produces: `SScribe_Language_Request::normalize_current_request(): void`
- Contract: only `sscribe_get_export_preview` and `sscribe_start_export` translate `language=__all__` to `language=''`; count endpoints preserve `__all__`.

- [ ] **Step 1: Write unit regressions before production code**

The unit test must assert registration targets and direct normalization behavior, including preservation of `en`, invalid strings, and count actions.

- [ ] **Step 2: Add a real-WordPress regression that invokes the actual Preview/Start AJAX paths with `__all__`**

The test must prove Preview reaches a success response and Start gets past language validation into the normal session/start pipeline. It may use existing WP AJAX test helpers but must not merely assert source strings.

- [ ] **Step 3: Run the new tests on the draft PR and verify RED**

Expected: failure because `SScribe_Language_Request` does not exist and the real production endpoints reject `__all__`.

- [ ] **Step 4: Implement `SScribe_Language_Request` minimally**

Register priority-1 callbacks for the two affected AJAX actions. In the callback, mutate `$_POST['language']` and `$_REQUEST['language']` from `__all__` to `''`; do nothing for every other value/action.

- [ ] **Step 5: Register the normalizer before `SScribe()->run()`**

Call `SScribe_Language_Request::register()` inside the plugin bootstrap after the autoloader is available and before export handlers are registered.

- [ ] **Step 6: Verify unit + real-WP GREEN**

Run the targeted unit test and the real-WP test matrix. Existing count tests must remain green.

- [ ] **Step 7: Commit**

Commit message: `fix(export): normalize all-language requests centrally`

---

### Task 2: Correct preflight, All-Languages label, and Debug transition behavior

**Files:**
- Create: `admin/js/sscribe-release-hardening.js`
- Create: `tests/Unit/SScribe_Release_Hardening_JS_Test.php`
- Create: `tests-e2e/e2e/export/all-languages-preview-start.spec.ts`
- Create: `tests-e2e/e2e/export/preflight-blocker.spec.ts`
- Create or update: `tests-e2e/e2e/debug/toggle-state-transition.spec.ts`
- Modify: `sscribe-export-site-pages.php` or create a small autoloadable enqueue class if needed.

**Interfaces:**
- Patches `window.SScribe.getLanguageLabel`, `window.SScribe.runPreflightCheck`.
- Patches `window.SScribeDebugConsole.saveSettings` only when that object exists.
- No hardening module behavior may run outside `toplevel_page_sscribe-export`.

- [ ] **Step 1: Add static/runtime regression tests before the hardening module exists**

Tests must assert:
- `__all__` renders `All Languages` rather than `__ALL__`;
- preflight error/can_proceed=false cannot call `proceedWithExport`;
- warning requires explicit Continue;
- Debug Save without a Debug toggle does not reload;
- actual OFF->ON save does refresh the console/page state.

- [ ] **Step 2: Add Playwright All-Languages Preview->Start coverage**

Create its own fixture/state. It must select All Languages, open Preview, assert Preview succeeds/count is coherent, start export, and verify the start endpoint is not rejected as `invalid_language`.

- [ ] **Step 3: Add Playwright hard-preflight-block coverage**

Intercept/mock only the preflight response with `status:error, can_proceed:false`; assert no `sscribe_start_export` request occurs and no enabled Continue Anyway control exists.

- [ ] **Step 4: Verify RED in draft-PR workflows**

- [ ] **Step 5: Implement the small hardening module**

Use the existing public `window.SScribe` seam. Preserve current retry/nonce logic. Delegate existing banner rendering for warnings/errors, but remove the proceed control for hard errors and reset preparation state without starting.

- [ ] **Step 6: Enqueue the module safely**

Load only on the SScribe admin page after `sscribe-admin`; use `wp-i18n` for the `All Languages` label. Patch Debug only after `SScribeDebugConsole` becomes available.

- [ ] **Step 7: Verify unit + Playwright GREEN**

- [ ] **Step 8: Commit**

Commit message: `fix(admin): enforce release-critical UI contracts`

---

### Task 3: Narrow fatal-error attribution to SScribe AJAX operations

**Files:**
- Modify: `includes/class-sscribe-fatal-handler.php`
- Modify: `tests/Unit/SScribe_Fatal_Handler_Test.php`

**Interfaces:**
- `SScribe_Fatal_Handler::is_in_scope()` remains private; tests exercise it through the current test seam/reflection pattern.

- [ ] **Step 1: Add failing tests**

Cases:
- plugin-root fatal => in scope;
- external fatal + `action=sscribe_process_batch` + AJAX => in scope;
- external fatal + `action=other_plugin_action` + AJAX => out of scope;
- external fatal + missing/non-scalar action => out of scope.

- [ ] **Step 2: Verify RED**

Current implementation should incorrectly accept unrelated AJAX.

- [ ] **Step 3: Implement minimal scope check**

For non-plugin-root files, require `wp_doing_ajax()` and a sanitized current action starting with `sscribe_`.

- [ ] **Step 4: Verify targeted + full unit GREEN**

- [ ] **Step 5: Commit**

Commit message: `fix(logging): scope fatal attribution to SScribe AJAX`

---

### Task 4: Split ordinary PHPUnit from coverage reporting without weakening warnings

**Files:**
- Modify: `phpunit.xml`
- Create: `phpunit-coverage.xml`
- Modify: `composer.json`
- Create: `tests/Integration/SScribe_PHPUnit_Config_Test.php`

**Interfaces:**
- `vendor/bin/phpunit` uses strict normal config and does not request coverage.
- `composer test:coverage` uses `phpunit-coverage.xml` and writes `clover.xml`.

- [ ] **Step 1: Add failing config contract test**

Assert default config keeps `failOnWarning=true` and lacks `<coverage>`, while coverage config includes the same test suites/source envelope plus Clover reporting.

- [ ] **Step 2: Verify RED**

Current default config contains coverage and coverage config does not exist.

- [ ] **Step 3: Create coverage config and remove reporting from default**

Do not change `failOnWarning` or `failOnRisky`.

- [ ] **Step 4: Point Composer coverage command at the coverage config**

- [ ] **Step 5: Verify targeted integration test and Version Sync PHPUnit command GREEN without a coverage driver**

- [ ] **Step 6: Commit**

Commit message: `fix(ci): separate strict tests from coverage reporting`

---

### Task 5: Repair E2E dependency ordering and audit the JavaScript toolchain

**Files:**
- Modify: `.github/workflows/e2e.yml`
- Modify if required by advisory: `package.json`, `package-lock.json`
- Modify: `tests/Integration/SScribe_E2E_Deps_Test.php`
- Modify if needed: `scripts/verify-e2e-deps.php`

**Interfaces:**
- Strauss is installed before `composer vendor:prefix`.
- Composer locked audit and npm high-severity audit are release gates.
- Full Playwright remains required on PR and push.

- [ ] **Step 1: Extend the E2E workflow contract test first**

Assert Composer install does not use `--no-dev`, Composer audit exists before build, npm audit exists after npm install, and prefix/build occurs only after dependency installation.

- [ ] **Step 2: Verify RED against current workflow**

- [ ] **Step 3: Correct Composer install/audit ordering**

Use locked dev dependencies in the build workspace; final package rules continue to exclude dev-only code.

- [ ] **Step 4: Inspect the exact npm advisory from CI**

If high severity remains, update only the affected dev dependency chain to a patched version supported by the project.

- [ ] **Step 5: Verify E2E workflow reaches Playwright and full suite runs**

- [ ] **Step 6: Commit**

Commit message: `fix(e2e): make browser certification executable`

---

### Task 6: Establish WordPress 6.1 + PHP 8.2 as the tested minimum

**Files:**
- Modify: `sscribe-export-site-pages.php`
- Modify: `readme.txt`
- Modify: `scripts/verify-min-versions.php`
- Modify: `tests/Integration/SScribe_Minimum_Versions_Test.php`
- Create: `.github/workflows/minimum-compat.yml`

**Interfaces:**
- Header/readme/runtime guard all declare WordPress 6.1 and PHP 8.2.
- A real WordPress testbench executes the suite on exactly WP 6.1 + PHP 8.2.

- [ ] **Step 1: Update the minimum-version tests first to require 6.1**

- [ ] **Step 2: Verify RED against 6.0 metadata**

- [ ] **Step 3: Update header/readme/runtime guard/verifier to 6.1**

- [ ] **Step 4: Add the exact minimum real-WP workflow**

Install WordPress 6.1 with PHP 8.2 and run `composer test:wp`/the real-WP suite.

- [ ] **Step 5: Verify green exact-minimum workflow**

If runtime incompatibility appears, raise the floor rather than weakening the test.

- [ ] **Step 6: Commit**

Commit message: `fix(compat): test and declare WordPress 6.1 minimum`

---

### Task 7: Replace the false-positive AI scanner with a release-artifact leakage scanner

**Files:**
- Modify: `scripts/check-ai-artifacts.php`
- Create: `tests/Integration/SScribe_AI_Artifact_Scanner_Test.php`

**Interfaces:**
- Legitimate punctuation does not fail.
- Zero-width source anomalies and explicit assistant/workspace leakage do fail.

- [ ] **Step 1: Add failing fixtures/test cases**

Prove em/en dash, curly quotes, ellipsis are accepted. Prove zero-width chars, `As an AI language model`, and named assistant workspace/prompt artifacts are rejected.

- [ ] **Step 2: Verify RED**

Current scanner rejects legitimate punctuation.

- [ ] **Step 3: Narrow scanner patterns**

Retain release-integrity checks, remove typography heuristics, and update scanner output/docs to describe what it really checks.

- [ ] **Step 4: Verify scanner + Security Audit GREEN**

- [ ] **Step 5: Commit**

Commit message: `fix(audit): detect release leakage without typography false positives`

---

### Task 8: Make local release audit fail when mandatory Plugin Check cannot run

**Files:**
- Modify: `bin/release-audit.sh`
- Create or modify: `tests/Integration/SScribe_Plugin_Check_Triage_Test.php`

**Interfaces:**
- Missing local Plugin Check testbench yields FAIL/non-zero, never `All gates green`.

- [ ] **Step 1: Add failing contract test**

Test the script text/controlled environment so a missing testbench must increment failure rather than append SKIP.

- [ ] **Step 2: Verify RED**

- [ ] **Step 3: Change missing-testbench branch to fail closed**

- [ ] **Step 4: Verify GREEN**

- [ ] **Step 5: Commit**

Commit message: `fix(release): make Plugin Check mandatory`

---

### Task 9: Make submission source/build transparency truthful and reproducible

**Files:**
- Inspect/modify: `.distignore`
- Inspect/modify: `scripts/build-release.php`
- Inspect/modify: `scripts/verify-build-transparency.php`
- Inspect/modify: `scripts/verify-zip-content-rules.php`
- Inspect/modify: `tests/Integration/SScribe_Build_Transparency_Test.php`
- Inspect/modify: `tests/Integration/SScribe_ZIP_Content_Rules_Test.php`
- Modify: `readme.txt`
- Package inputs as justified: `composer.json`, `composer.lock`, minimal build scripts, `docs/BUILD_TRANSFORMATIONS.md`

**Interfaces:**
- Readme must not call the private GitHub URL a public source repository.
- The submitted package or a genuinely public URL must contain enough source/build material to reproduce generated prefixed code.

- [ ] **Step 1: Inspect current dist/build allowlist before changing package policy**

- [ ] **Step 2: Write failing transparency/package tests for the chosen self-contained source strategy**

- [ ] **Step 3: Include only the minimal reproducibility files in the package and update exact content rules**

Do not ship tests, CI, node_modules, `vendor/`, credentials, or workspace artifacts.

- [ ] **Step 4: Update Development/readme wording truthfully**

If self-contained packaging cannot meet the WordPress source rule cleanly, leave an explicit external release blocker requiring the owner to publish the source location. Do not make the repository public automatically.

- [ ] **Step 5: Build exact ZIP and verify content/transparency tests GREEN**

- [ ] **Step 6: Commit**

Commit message: `fix(release): make build source reproducible for reviewers`

---

### Task 10: Refresh evidence, run exact certification, and prepare reviewable PR

**Files:**
- Update only current evidence docs whose claims are regenerated from the final SHA.
- Mark stale historical reports clearly or remove them from the active evidence index.

**Interfaces:**
- Every "certified" claim includes the final exact SHA.
- Exact final ZIP checksum and Plugin Check result correspond to the same SHA.

- [ ] **Step 1: Run full PR CI and inspect every failed/cancelled/skipped job**

No skipped mandatory job is accepted as green.

- [ ] **Step 2: Run exact real-WP minimum + current matrices**

- [ ] **Step 3: Run full Playwright including the new All-Languages and preflight regressions**

- [ ] **Step 4: Run exact ZIP build, SHA-256 certification, Plugin Check, clean install, activation, and export smoke**

- [ ] **Step 5: Re-run security, Composer/npm audits, PHPStan, PHPCS, coverage, i18n, accessibility, and package rules**

- [ ] **Step 6: Refresh release evidence to the final SHA only**

- [ ] **Step 7: Re-audit the final branch diff against baseline**

- [ ] **Step 8: Open/update the PR with exact evidence and residual external prerequisites**

- [ ] **Step 9: Invoke verification-before-completion and finishing-a-development-branch before any release-ready claim**
