# M3 — Browser/E2E + Accessibility + Performance sweep

**Status:** Design complete, awaiting user review
**Author:** brainstorm session (synthesis of two Explore reports + 3 clarifying answers)
**Date:** 2026-08-31
**Branch:** develop
**Plugin version:** 2.0.0 (per `package.json` / `composer.json`)

---

## Context

M3 has three sub-milestones remaining after the just-shipped real-WordPress integration testbench (commit `14379d2`, 69 real-WP tests added on top of the existing 941 fake-wp tests):

1. **Browser/E2E/Playwright** — admin UI flows (export wizard, download auth, format cards, batch progress)
2. **Accessibility automation** — closes the v1.2.0 "deliberately deferred" item #1 (manual WCAG AA smoke)
3. **Performance validation** — closes the v1.2.0 "deliberately deferred" item #3 (no perf budgets)

The plugin's admin surface is well-mapped (15 AJAX endpoints, 4 admin tabs, 5 format cards, single menu page, 10-step export wizard, download via `<a download href>` with `dl_token`). Native perf hooks already exist (`SScribe_Export_Resource_Monitor`, `SScribe_Adaptive_Metrics`, action hooks `sscribe_before_export_page` / `sscribe_after_export_page`).

What's NOT on disk: zero browser/E2E config, zero a11y tooling, zero perf tooling, only `@playwright/test` declared as a devDep with nothing wired to it. CI has no real-WP, no e2e, no a11y, no perf job.

This spec lands all three sub-milestones under one cohesive Playwright-based testbed, sharing a single WP-Playground runtime.

---

## Decisions (locked by clarifying answers)

| Question | Decision |
|---|---|
| Scope ambition | **Full coverage** (~120 tests across 2-3 PRs, 4-6 days work) |
| Browser runtime | **WP-Playground 3.1.44** in-process PHP via `@php-wasm/node` |
| A11y floor | **WCAG 2.2 AA + best-practice** via `@axe-core/playwright` |

Approach: **three projects under one `@playwright/test` config** (rejected: one mega-suite loses project-level CI sharding; rejected: per-area runners triple setup cost with no coverage gain).

---

## 1. Architecture

### 1.1 Runtime

- **WP-Playground 3.1.44** ([`@wp-playground/cli`](https://github.com/WordPress/wordpress-playground)) runs in-process PHP via `@php-wasm/node`. Spawned once by `globalSetup`, killed at teardown.
- **Chromium 1.62.x** via `@playwright/test` (already in `package.json`). Installed via `npx playwright install chromium` in CI.
- **fs-ext-extra-prebuilt no-op lock stub** (TS port of [wp-playground-fs-ext-stub](../../../../../memory/wp-playground-fs-ext-stub.md) memory) applied at the very start of `globalSetup` so the lock files don't trip on Windows + Node v26. Idempotent — safe to call multiple times.
- The plugin is mounted as `dist/sscribe-export-site-pages.zip` (the existing build artifact from `composer release:prepare`). **Playwright runs against the real ship artifact, not loose source.** CI produces the ZIP, then runs Playwright against it.

### 1.2 Process topology

```
┌──────── globalSetup (Playwright worker, runs once) ────────┐
│ 1. stub-fs-ext.ts        → require.cache patch            │
│ 2. spawn @wp-playground/cli serve --blueprint=…  --port=9400 │
│ 3. poll GET /wp-login.php   500ms backoff, 90s cap          │
│ 4. wp post create ×50 via Playground CLI (5 × 10 combos)    │
│ 5. resolve globalSetup  → { baseURL, teardown }            │
└─────────────────────────────────────────────────────────────┘

┌──────── fixture: shared.server (per-test) ──────────────────┐
│ returns globalSetup URL + a fresh browser context           │
│ auto-login as admin via /wp-login.php                       │
│                                                              │
│ ┌── e2e project ─────────────────────────────────────────┐ │
│ │ navigate to admin.php?page=sscribe-export              │ │
│ │ interact with wizard; AJAX captured via page.route     │ │
│ └────────────────────────────────────────────────────────┘ │
│ ┌── a11y project ─────────────────────────────────────────┐ │
│ │ colorScheme: { light, dark } via page.emulateMedia     │ │
│ │ runAxe(page, { tags: wcag22a, wcag22aa, best-practice })│ │
│ └────────────────────────────────────────────────────────┘ │
│ ┌── perf project ─────────────────────────────────────────┐ │
│ │ start export; mu-plugin writes JSONL to wp-content/.../│ │
│ │ poll fetch(); assert median wall-time + peak memory    │ │
│ └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 1.3 Project layout

```
playwright.config.ts                 # globalSetup, three projects, axeRules
tests-e2e/
├─ fixtures/
│  ├─ blueprint.json                 # WP-Playground blueprint (mounts dist ZIP)
│  └─ shared.ts                      # custom test + fixtures (auto-login)
├─ helpers/
│  ├─ stub-fs-ext.ts                 # Node 26 + Windows lock stub
│  ├─ perf-sink.ts                   # JSONL recordSample + getMetrics
│  ├─ axe-rules.ts                   # axe Tags + runAxe wrapper
│  ├─ login.ts                       # loginAsAdmin(page)
│  ├─ plugin-error-listener.ts       # forwards window.onerror to test
│  └─ assert-json-ok.ts              # assertJSONOK(response, expected)
├─ globalSetup.ts                    # the runtime boot
├─ 00-smoke.spec.ts                  # stack canary
├─ e2e/                              # ~50 flow tests
│  ├─ export/
│  │  ├─ wizard-happy-path.spec.ts
│  │  ├─ validation-errors.spec.ts
│  │  ├─ batch-progress.spec.ts
│  │  ├─ cancel-mid-flight.spec.ts
│  │  ├─ download-token-auth.spec.ts
│  │  └─ format-selection.spec.ts
│  ├─ history/
│  │  ├─ list-paginate.spec.ts
│  │  ├─ re-download.spec.ts
│  │  └─ delete-two-click.spec.ts
│  ├─ support/
│  │  ├─ copy-debug-info.spec.ts
│  │  ├─ system-info.spec.ts
│  │  └─ restart.spec.ts
│  └─ debug/
│     ├─ toggle.spec.ts              # locks in [toggle-ui-assets-gating]
│     ├─ view-logs.spec.ts
│     ├─ filter-logs.spec.ts
│     └─ clear-logs.spec.ts
├─ a11y/                             # ~20 axe scans
│  ├─ admin-tabs.spec.ts             # 4 tabs × {light, dark} state
│  ├─ format-cards.spec.ts           # 4 formats × {hover, focus, selected, disabled}
│  ├─ wizard-modal.spec.ts           # confirm dialog, two-click confirm
│  ├─ progress.spec.ts               # aria-live announcer + progressbar roles
│  └─ history-table.spec.ts          # <caption>, <thead scope>, <details>/<summary>
├─ perf/                             # ~10 perf budgets
│  ├─ baseline-format-docx.spec.ts
│  ├─ baseline-format-pdf.spec.ts
│  ├─ baseline-format-html.spec.ts
│  ├─ baseline-format-markdown.spec.ts
│  ├─ memory-ceiling.spec.ts
│  ├─ batch-size-regression.spec.ts
│  └─ cronos-advisory.spec.ts        # sscribe_max_execution_time honored
├─ baselines/
│  └─ format-baselines.json          # loaded by perf specs
├─ regression-discipline.mjs         # CI script that flips source for 8 targets
└─ README.md
```

### 1.4 Why three projects, not three test suites

`@playwright/test` sharding works at the project boundary. Each project gets its own CI annotation, its own HTML report subdir, and its own `--grep` filter so perf-only PRs can skip the a11y suite. One npm script (`test:e2e`), three sub-runs (`test:e2e:e2e`, `test:e2e:a11y`, `test:e2e:perf`).

### 1.5 Failure model

| Failure type | Surface | Artifact captured |
|---|---|---|
| Playwright assertion | test-trace.zip | trace + screenshot + DOM + console |
| Axe violation | a11y report HTML | rule id, target HTML, impact, help URL |
| Perf regression | perf-report.html | per-page histogram vs baseline |
| WP-Playground boot | globalSetup stderr | full boot log + DB dump |
| Plugin fatal mid-flow | per-test error capture | fatal class + message + last screenshot |
| Cross-process crash | Playwright connection-refused | last page URL + screenshot |

---

## 2. Components

### 2.1 Config & boot layer

| File | Responsibility |
|---|---|
| `playwright.config.ts` | Defines `globalSetup`, three `projects` (e2e / a11y / perf), `baseURL` stub, `ignoreHTTPSErrors`, timeouts (60s test, 30s expect, 90s globalSetup), HTML + JUnit reporters |
| `tests-e2e/globalSetup.ts` | One function: applies fs-ext stub → spawns `@wp-playground/cli` → polls `/wp-login.php` until 200 (max 90s) → seeds 50 posts via Playground CLI → returns `{ baseURL, teardown }` |
| `tests-e2e/fixtures/blueprint.json` | WP-Playground blueprint: mounts `dist/*.zip`, sets `WP_DEBUG=true`, disables file edits, seeds admin user, enables pretty permalinks, drops `mu-plugins/sscribe-perf-sink.php` |

### 2.1.1 Concrete `blueprint.json` shape

```json
{
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "landingPage": "/wp-admin/admin.php?page=sscribe-export",
  "phpExtensionBundles": ["kitchen-sink"],
  "steps": [
    { "step": "installPlugin", "pluginZipFile": "/tmp/sscribe-build/sscribe-export-site-pages.zip" },
    { "step": "mkdir", "path": "/wordpress/wp-content/mu-plugins" },
    { "step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/sscribe-perf-sink.php", "data": "<?php /* ... contents from §2.3 ... */ ?>" },
    { "step": "wp-cli", "command": "wp rewrite structure '/%postname%/' --hard" },
    { "step": "wp-cli", "command": "wp user meta update admin sscribe_debug_enabled 1" }
  ]
}
```

The build step writes the `dist/*.zip` to a path the blueprint can reference (`/tmp/sscribe-build/`). The blueprint is intentionally minimal — no post seeding here; that's done by `globalSetup` after boot for visibility.

### 2.2 Helpers

| File | Responsibility |
|---|---|
| `tests-e2e/helpers/stub-fs-ext.ts` | TS port of the [wp-playground-fs-ext-stub](../../../../../memory/wp-playground-fs-ext-stub.md) memory. Replaces `flockSync` / `fcntlSync` / `lockFileExSync` / `unlockFileExSync` with no-ops via `require.cache` shim, keeps `F_RDLCK` / `F_WRLCK` / `F_UNLCK` constants intact |
| `tests-e2e/helpers/perf-sink.ts` | Custom fixture: opens a JSONL file under `wp-content/uploads/sscribe-perf/{session}.jsonl`, exposes `recordSample()` + `getMetrics()`. Reads back via `page.evaluate(() => fetch(...))` so the test thread never blocks on PHP |
| `tests-e2e/helpers/axe-rules.ts` | Exports axe `Tags` constants (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22a`, `wcag22aa`, `best-practice`) + `runAxe(page, opts)` wrapper that fails on any violation with rule id, target HTML, help URL |
| `tests-e2e/helpers/login.ts` | `loginAsAdmin(page)` — single helper that every test reuses |
| `tests-e2e/helpers/plugin-error-listener.ts` | `page.addInitScript` that forwards `window.onerror` + `unhandledrejection` to the Playwright test thread |
| `tests-e2e/helpers/assert-json-ok.ts` | `assertJSONOK(response, expected)` — checks `{ ok: true, ...expected }` shape and fails with `error.code` + `error.message` |

### 2.3 Perf sink mu-plugin

`tests-e2e/fixtures/mu-plugins/sscribe-perf-sink.php` (~30 lines, loaded via `blueprint.json`'s `mu-plugins` step):

```php
<?php
/**
 * Plugin Name: SScribe Perf Sink (M3 only)
 * Description: Hooks sscribe_after_export_page → writes JSONL to wp-content/uploads/sscribe-perf/
 */
add_action( 'sscribe_after_export_page', function ( $page_id, $formats, $success, $elapsed_ms = null, $peak_mem_bytes = null ) {
    if ( empty( $_REQUEST['sscribe_perf_session'] ) ) return;
    $sess = sanitize_key( $_REQUEST['sscribe_perf_session'] );
    $dir  = WP_CONTENT_DIR . '/uploads/sscribe-perf';
    wp_mkdir_p( $dir );
    $line = wp_json_encode( compact( 'page_id', 'formats', 'success', 'elapsed_ms', 'peak_mem_bytes' ) ) . "\n";
    file_put_contents( "$dir/$sess.jsonl", $line, FILE_APPEND | LOCK_EX );
}, 10, 5 );
```

**Production note:** the action's signature must match production. If production only passes 3 args today, the mu-plugin's signature must match (use `null` defaults + filter hooks instead of fake 4th/5th args). Verify against `includes/class-sscribe-batch-processor.php` before finalizing.

### 2.4 CI glue

`.github/workflows/e2e.yml` — single job on PHP 8.4 / Node 24:

```yaml
name: e2e
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '24', cache: 'npm' }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - run: npm ci --ignore-scripts
      - run: npx playwright install chromium --with-deps
      - run: composer install --no-dev --no-scripts
      - run: composer vendor:prefix
      - run: composer release:prepare
      - run: npm run test:e2e:smoke
      - run: npm run test:e2e:fast
        if: github.event_name == 'pull_request' || github.ref == 'refs/heads/main'
      - run: npm run test:e2e:full
        if: github.event_name == 'push' && github.ref == 'refs/heads/main'
      - run: node tests-e2e/regression-discipline.mjs
        if: github.event_name == 'pull_request'
      - uses: actions/upload-artifact@v4
        if: failure()
        with: { name: playwright-report, path: playwright-report/ }
      - uses: actions/upload-artifact@v4
        if: failure()
        with: { name: perf-samples, path: tests-e2e/.cache/perf/ }
```

Caches: `~/.cache/npm`, `~/.cache/wp-playground`, `vendor/`, `node_modules/`.
Artifacts on failure: `playwright-report/`, `test-results/`, `tests-e2e/.cache/perf/*.jsonl`.

---

## 3. Data flow

### 3.1 Boot

```
globalSetup() (Node worker, runs once)
├─ stub-fs-ext.ts # require.cache patch
├─ spawn @wp-playground/cli serve --blueprint=… --port=9400  (child process)
├─ poll GET http://127.0.0.1:9400/wp-login.php 500ms backoff, 90s cap
├─ wp post create ×50 via Playground CLI  (5 posts × 10 format/template combos)
└─ return { baseURL, teardown }
```

### 3.2 Per-test (e2e project)

```
test.beforeEach                  test body                   test.afterEach
├─ browser.newContext()          ├─ page.goto(admin)         └─ context.close()
├─ page.goto('/wp-login.php')    ├─ click format card          (fresh context =
├─ loginAsAdmin(page)            ├─ click "Start Export"        isolation)
└─ page.goto('admin.php?page=…') ├─ wait for response
                                   │   └─ JSON via page.route handler
                                   │       assert: { ok: true, batch: 1 }
                                   ├─ poll batch until complete
                                   └─ click "Download" → download.path()
                                       read ZIP, assert filename + CRC
```

### 3.3 Per-test (a11y project)

```
test body
├─ loginAsAdmin(page)
├─ page.goto('admin.php?page=…&tab=…')
├─ colorScheme?: page.emulateMedia({ colorScheme: 'dark' })
├─ runAxe(page, { tags: ['wcag22a','wcag22aa','best-practice'] })
└─ assert zero violations ≥ minor impact
```

### 3.4 Per-test (perf project) — cross-boundary trick

```
test body
├─ loginAsAdmin(page)
├─ start export via wizard (creates sscribe_perf_session ID)
├─ poll page.evaluate(() => fetch('/wp-content/uploads/sscribe-perf/{id}.jsonl'))
│   └─ mu-plugin wrote one JSONL line per sscribe_after_export_page fire:
│       { page_id, formats, success, elapsed_ms, peak_mem_bytes }
├─ when finalize_export → complete, stop polling
└─ parse JSONL, assert:
     per-page median wall-time ≤ BASELINE_SECONDS[format] × 1.5
     peak memory            ≤ BASELINE_MB[format] × 2  (regression threshold)
```

**Why `wp-content/uploads/sscribe-perf/`:** WP-Playground's tmp dir is inside the WASM sandbox — the test process can't `fs.readFileSync` it directly. `wp-content/uploads/` is HTTP-addressable (Playground auto-serves `wp-content`), so the test reads samples via `fetch()` over the same `baseURL` the e2e project already uses.

### 3.5 Cross-boundary summary

| Direction | Mechanism | Latency budget |
|---|---|---|
| Test → PHP (form submit) | `page.click` → native AJAX | <1s per call |
| PHP → Test (JSON) | `page.route` intercept | immediate |
| PHP → Test (perf sample) | `page.evaluate(() => fetch(...))` poll | 200ms between samples |
| Boot → Test | `globalSetup` return | <90s total |

### 3.6 Tear down

- `teardown()` sends `SIGTERM` to the Playground child
- `/tmp/wp-playground-*` scratch dir + `tests-e2e/.cache/perf/` + `playwright-report/` uploaded as CI artifacts on failure
- Three projects' JUnit XML merged into a single `test-results/e2e.junit.xml`

---

## 4. Error handling

### 4.1 Failure model — boot phase (globalSetup)

| Failure | Detection | Test impact |
|---|---|---|
| `stub-fs-ext.ts` patch fails | `require.cache` lookup returns null | globalSetup exits 1 with patch trace |
| `@wp-playground/cli` binary missing | `spawn` ENOENT | globalSetup exits 1, stderr "run `npm ci`" |
| Blueprint invalid | Playground exits within 5s | globalSetup tails last 50 lines of stderr, exits 1 |
| 90s boot timeout | `fetch('/wp-login.php')` times out | globalSetup exits 1, attaches last 20 boot log lines |
| Post seed fails | `wp post create` exits non-zero | globalSetup exits 1, attaches failing post body |

All boot failures upload the **entire WP-Playground scratch dir** (`/tmp/wp-playground-*`) as a CI artifact.

### 4.2 Failure model — per-test

| Failure | Helper that catches it | What test sees |
|---|---|---|
| WP AJAX returns `ok: false` | `assertJSONOK(response, expected)` | test fails with `error.code` + `error.message` |
| Plugin fatal mid-flow | `plugin-error-listener.ts` (page.addInitScript) | test fails with fatal class + message |
| JS console error | default Playwright capture | test fails with console error + stack |
| Download token expired/foreign | `download-token-auth.spec.ts` asserts 403 status | test fails with response body |
| Two-click confirm swallowed | `expect(button).toHaveCSS('pointer-events', 'auto')` after second click | test fails with computed pointer-events |
| Tab CSS/JS missing | `expect(page.locator('script[data-ss-tab-debug]')).toBeAttached()` even when debug flag false | test fails with empty locator |
| Dark-mode AA failure | axe scan finds `color-contrast` violation | a11y test fails with rule id + element HTML |
| Perf JSONL never finishes | 5-min poll timeout | last seen sample count + current URL + screenshot |
| Per-page wall-time regression | `elapsed_ms > baseline × 1.5` | full sample histogram HTML report |
| Per-page peak-memory regression | `peak_mem_bytes > baseline × 2` | full sample histogram HTML report |
| Perf sink mu-plugin not loaded | `fetch(...)` returns 404 at start | fixture exposes `getSinkPath()`; test asserts 200 before proceeding |

### 4.3 Tear-down noise

- WP-Playground child may log to STDERR on `SIGTERM` — captured into the boot log artifact
- The mu-plugin flushes its JSONL handle on every sample (no buffer). If the flush races with `SIGTERM`, the file is left intact (no throw) because the path is HTTP-addressable and `fclose()`'d on each sample.

### 4.4 Global rule

Every project specifies `test.use({ trace: 'on-first-retry' })`. Any failure gets the full Playwright trace zip — pages, snapshots, network, console. The CI artifact includes these per-failure traces. **No silent failures anywhere.**

---

## 5. Testing the M3 tests themselves

### 5.1 Self-smoke (stack canary)

`tests-e2e/00-smoke.spec.ts` runs first via `--grep "00-smoke"` in CI:

1. Loads the admin page (already-logged-in via fixture)
2. Asserts `await page.locator('.sscribe-format-card').first().isVisible()`
3. Asserts `await page.evaluate(() => typeof wp !== 'undefined')`
4. Tears down

If this test fails, the entire e2e suite is non-functional. Runs in **every CI run**, takes <30s.

### 5.2 Dry-run gate (CI)

```yaml
- name: Playwright list check
  run: npx playwright test --list --reporter=line
```

`--list` parses every spec file without executing. Catches:
- Missing imports (imports that resolve at runtime only)
- Malformed fixtures
- Broken `test.describe` nesting
- Type errors in spec files

Runs in <10s. If this exits non-zero, no `test:e2e` step is attempted.

### 5.3 Regression discipline — proving each test actually fails when it should

For every test that targets a specific regression (8 tests total in this category), the workflow is:

1. Write the test → run on current main → expect pass (baseline)
2. Revert the fix in source → run → MUST fail
3. Restore fix → run → MUST pass again

CI runs (2) on every PR that touches the relevant source file. Specifically:

| Test | Reverts | Asserts |
|---|---|---|
| `dark-mode-contrast-format-desc` | `color-contrast` violation reintroduced | axe finds violation in dark mode |
| `dark-mode-contrast-language-badge` | revert language-badge color | axe finds violation |
| `markdown-card-word-break` | wrap rule removed | visual regression + axe `text-alternative` |
| `toggle-ui-assets-gating` | remove the debug-tab JS load | locator empty |
| `two-click-confirm-pointer-events` | re-apply `pointer-events: none` | computed style assertion fails |
| `export-wizard-happy-path` | break one AJAX response | `assertJSONOK` fails |
| `download-token-auth` | remove token expiry check | 403 response becomes 200 |
| `batch-progress-aria-live` | remove `aria-live` attribute | role check fails |

Enforced by `tests-e2e/regression-discipline.mjs` (CI script that uses `paths-filter` to only run on touched paths). Adds ~20s per PR.

### 5.4 Ordering & isolation

- No test imports another. `test.describe.serial` is banned — would create ordering bugs.
- Fresh browser context per test (`test.use({ storageState: undefined })` in fixture).
- Single shared WP-Playground instance, but a fresh `wp-content/uploads/sscribe-perf/{hash}.jsonl` subdir per test (the fixture names the subdir after the test title hash).
- Perf sink JSONLs from prior tests are deleted in `test.afterEach`.

### 5.5 Slow vs fast split

| Run | Trigger | Specs |
|---|---|---|
| `test:e2e:smoke` | every PR | 00-smoke + toggle-gating + dark-mode AA + token auth |
| `test:e2e:fast` | every PR | all e2e wizard happy paths + a11y scans + 4 perf format baselines |
| `test:e2e:full` | nightly + main merge | everything in fast + memory ceiling + cron + history table + debug tab |

Exposed in `package.json` as:
```json
"scripts": {
  "test:e2e": "playwright test",
  "test:e2e:smoke": "playwright test --grep '00-smoke|smoke|wizard-happy-path|download-token-auth|dark-mode-contrast|toggle-ui-assets-gating'",
  "test:e2e:fast": "playwright test --grep-invert 'cronos-advisory|history-table-a11y|debug-tab-view-logs'",
  "test:e2e:full": "playwright test",
  "test:e2e:e2e": "playwright test --project=e2e",
  "test:e2e:a11y": "playwright test --project=a11y",
  "test:e2e:perf": "playwright test --project=perf"
}
```

The grep regexes anchor on stable test-name prefixes (kebab-case tokens from `test.describe` blocks) so future tests with unrelated words ("dark", "debug", etc.) cannot accidentally match the smoke/fast filter. The `:full` form runs everything and is what nightly + main-merge execute.

### 5.6 What does NOT live in this suite

- Unit-level PHP regressions — stay in the existing 941 fake-wp tests + 69 real-WP tests
- Static analysis — stays in `composer stan` + `composer cs`
- AI artifact scan — stays in `composer ci`
- WordPress.org plugin-check — stays in `composer release`

---

### 5.7 Pre-implementation setup (must happen before any M3 code lands)

Three repo-config changes are required before the first M3 commit, otherwise the spec itself can't commit and CI can't run:

1. **Remove the `docs/superpowers/` line from `.gitignore`** — the blanket ignore is over-broad for spec docs. Other entries (`PROGRESS.md`, `AGENTS.md`, `SScribe-Plugin-Audit-GLM5-Instructions.md`, etc.) stay — those are scratch planning docs, not specs. After removal:
   ```
   # Internal planning docs (keep out of repo) — REMOVED for spec docs
   docs/superpowers/specs/    # specs ARE committed; sub-dirs (analysis, plans) stay ignored
   ```
   Or simpler: change the line from `docs/superpowers/` to `docs/superpowers/analysis/` and `docs/superpowers/plans/` so only spec subdirs commit.

2. **Add `tests-e2e/.cache/`, `test-results/`, `playwright-report/` to `.gitignore`** — all generated, all already excluded by `.distignore` patterns.

3. **Verify `@axe-core/playwright` source availability** — try `npm install @axe-core/playwright --save-dev`; on failure switch to git source per [composer-install-firewall-workaround](../../../../../memory/composer-install-firewall-workaround.md); on second failure fall back to `axe-core` + custom Playwright assertion.

---

## 6. Files

### 6.1 New

| Path | Purpose |
|---|---|
| `playwright.config.ts` | Root Playwright config with three projects + globalSetup |
| `tests-e2e/globalSetup.ts` | Boot WP-Playground once, return baseURL |
| `tests-e2e/fixtures/blueprint.json` | WP-Playground blueprint (mounts dist/*.zip) |
| `tests-e2e/fixtures/mu-plugins/sscribe-perf-sink.php` | Hooks `sscribe_after_export_page` → JSONL |
| `tests-e2e/fixtures/shared.ts` | Custom `test` + fixtures (auto-login, perfSink) |
| `tests-e2e/helpers/stub-fs-ext.ts` | Node 26 + Windows lock stub |
| `tests-e2e/helpers/perf-sink.ts` | JSONL fixture helpers |
| `tests-e2e/helpers/axe-rules.ts` | axe Tags + runAxe wrapper |
| `tests-e2e/helpers/login.ts` | `loginAsAdmin(page)` |
| `tests-e2e/helpers/plugin-error-listener.ts` | page.addInitScript for window.onerror |
| `tests-e2e/helpers/assert-json-ok.ts` | `assertJSONOK(response, expected)` |
| `tests-e2e/00-smoke.spec.ts` | Stack canary |
| `tests-e2e/e2e/*.spec.ts` | ~50 flow tests |
| `tests-e2e/a11y/*.spec.ts` | ~20 axe scans |
| `tests-e2e/perf/*.spec.ts` | ~10 perf budgets |
| `tests-e2e/baselines/format-baselines.json` | Per-format per-page timing/memory baselines |
| `tests-e2e/regression-discipline.mjs` | CI script for the 8 targeted regressions |
| `tests-e2e/README.md` | Self-documents the suite |
| `.github/workflows/e2e.yml` | Single CI job |

### 6.2 Modified

| Path | Change |
|---|---|
| `package.json` | Add `test:e2e`, `test:e2e:smoke`, `test:e2e:fast`, `test:e2e:full`, `test:e2e:e2e`, `test:e2e:a11y`, `test:e2e:perf` scripts. Add `@axe-core/playwright` as devDep (git-source install per [composer-install-firewall-workaround](../../../../../memory/composer-install-firewall-workaround.md) — verify source availability before locking) |
| `.gitignore` | Add `tests-e2e/.cache/`, `test-results/`, `playwright-report/`. Replace the `docs/superpowers/` line with `docs/superpowers/analysis/` and `docs/superpowers/plans/` so only spec subdirs commit. See §5.7 |

### 6.3 NOT modified

- Plugin source code (the perf sink mu-plugin lives in `tests-e2e/`, not in the plugin)
- `composer.json` (no new PHP deps)
- `composer release:prepare` (the existing build already produces `dist/*.zip`)
- `.distignore` (everything M3 produces is already excluded by `tests/`, `playground-tools/`, `node_modules/`, etc.)
- `scripts/build-release.php` (no `base_excludes` changes — M3 artifacts land under gitignored paths)

---

## 7. Reuse points (existing code + memories)

| Source | How reused |
|---|---|
| [`includes/class-sscribe-export-resource-monitor.php`](../../../../includes/class-sscribe-export-resource-monitor.php) | `get_memory_usage_percent()`, `get_remaining_time()` — used by perf specs to assert against the same numbers the plugin sees |
| [`includes/class-sscribe-adaptive-metrics.php`](../../../../includes/class-sscribe-adaptive-metrics.php) | `BASELINE_SECONDS` / `BASELINE_MB` constants — perf spec baselines read from `format-baselines.json` which mirrors this shape |
| `sscribe_after_export_page($page_id, $formats, $success, $elapsed_ms = null, $peak_mem_bytes = null)` action | The mu-plugin hooks this; signature must match production — **verify before finalizing** |
| [wp-playground-fs-ext-stub.md](../../../../../memory/wp-playground-fs-ext-stub.md) | The TypeScript port of this memory is the `stub-fs-ext.ts` helper |
| [real-wp-testbench-learnings-m3.md](../../../../../memory/real-wp-testbench-learnings-m3.md) | Not directly reused (different runtime), but the same mental model: 1 global bootstrap, 1 teardown race suppressor, separate config per suite |
| [dark-mode-aa-regressions.md](../../../../../memory/dark-mode-aa-regressions.md) | The 4 dark-mode contrast regressions are the 4 `a11y/admin-tabs.spec.ts` color-scheme-dark tests |
| [toggle-ui-assets-gating.md](../../../../../memory/toggle-ui-assets-gating.md) | `e2e/debug/toggle.spec.ts` asserts the debug tab's CSS/JS load independent of the flag |
| [two-click-confirm-pointer-events-landmine.md](../../../../../memory/two-click-confirm-pointer-events-landmine.md) | `e2e/history/delete-two-click.spec.ts` asserts computed `pointer-events` after second click |
| [markdown-format-card-word-break.md](../../../../../memory/markdown-format-card-word-break.md) | `a11y/format-cards.spec.ts` includes the Markdown card word-break visual regression |
| [rotated-log-file-name-color-tokens.md](../../../../../memory/rotated-log-file-name-color-tokens.md) | `a11y/admin-tabs.spec.ts` (debug tab) asserts rotated-log filename is not invisible on light surface |
| [audit-screenshot-build-leak.md](../../../../../memory/audit-screenshot-build-leak.md) | M3 outputs land under `tests-e2e/.cache/`, `playwright-report/`, `test-results/` — all gitignored, all already covered by `.distignore` patterns |
| [composer-install-firewall-workaround.md](../../../../../memory/composer-install-firewall-workaround.md) | If `@axe-core/playwright` install fails, switch to git source per this workaround |
| [sandbox-firewall-composer-update-blocked.md](../../../../../memory/sandbox-firewall-composer-update-blocked.md) | Constraint: major `npm install` blocked; `@playwright/test` already cached, `@axe-core/playwright` needs source fallback |

---

## 8. Verification at the end of M3

The M3 PR closes when **all seven** of these hold:

1. `composer test:all` (941 + 69) exits 0 — **existing suites untouched**
2. `npm run test:e2e:smoke` exits 0
3. `npm run test:e2e:fast` exits 0
4. `npm run test:e2e:full` exits 0
5. `tests-e2e/regression-discipline.mjs` confirms all 8 targeted regressions are caught
6. `composer ci:full` exits 0 (existing gates unchanged)
7. The shipped `dist/*.zip` byte-for-byte matches what Playwright ran against (no drift between test target and production artifact — verified by checksum in CI)

---

## 9. Out of scope (deferred)

- **M4 — Release gates** (separate milestone)
- **M4 — Final reset to v2.0.0 with full audit report** (separate milestone)
- **WPML integration tests** — requires installing WPML in testbench; track for next milestone
- **SEO plugin matrix** (Yoast / Rank Math / AIOSEO / SEOPress / TSF) — high setup cost
- **Polylang integration** — plugin doesn't currently call Polylang APIs
- **Multisite `switch_to_blog` path** — requires multisite testbench variant
- **Visual diffing tools (Percy / Chromatic)** — axe + computed style assertions cover the highest-value cases; visual diff adds setup cost disproportionate to current risk
- **k6 / Artillery / JMeter** — external load drivers don't add value when the export flow is admin-driven (no public HTTP endpoint)
- **Lighthouse CI** — axe covers the accessibility floor; Lighthouse perf score for the admin is captured opportunistically via `lighthouse_audit` MCP in CI but not as a hard gate
- **Multilingual UI tests** (RTL languages, `lang` attributes) — out of scope unless regression surfaces

---

## 10. Memory entries to add at implementation time

When the implementation lands, write three new memory files:

1. **`m3-sweep-testbench.md`** — durable patterns for the WP-Playground + @playwright/test stack: fs-ext stub idempotency, perf-sink sandbox boundary, blueprint.json mu-plugin trick, three-project CI sharding
2. **`m3-a11y-floor.md`** — durable reference for the WCAG 2.2 AA + best-practice ruleset + which axe rules catch the known dark-mode regressions + the Markdown card word-break
3. **`m3-perf-baselines.md`** — durable reference for the `format-baselines.json` shape, the regression threshold multipliers (×1.5 wall-time, ×2 memory), and the JSONL wire format

These three memories replace the v1.2.0 "deliberately deferred" items #1 (manual AA smoke) and #3 (no perf budgets) — once M3 ships, those deferred-item memories should be **superseded** in `MEMORY.md` with pointers to the new M3 memories.

---

## 11. Risks + mitigations

| Risk | Mitigation |
|---|---|
| `@axe-core/playwright` fails to install (sandbox firewall) | Try tarball install first; on failure, switch to git source per [composer-install-firewall-workaround](../../../../../memory/composer-install-firewall-workaround.md); on second failure, fall back to plain `axe-core` + custom Playwright assertion |
| `sscribe_after_export_page` signature doesn't pass elapsed_ms / peak_mem_bytes | The mu-plugin accepts up to 5 args with null defaults; production's 3-arg signature still works. **Verify the actual production signature before finalizing** |
| WP-Playground scratch dir leaks memory across CI runs | `/tmp/wp-playground-*` uploaded on failure only; CI runner resets `/tmp` between jobs |
| Perf baselines drift as the exporter improves | `format-baselines.json` lives in the repo; updated by a deliberate PR with measurement of new median, not by silent CI re-baselining |
| Dark-mode regressions appear in 2.2 AA only (not 2.1 AA) | The `best-practice` ruleset covers `color-contrast-enhanced`; both modes tested |
| Three projects = three report dirs = harder triage | CI merges JUnit XML; HTML reports stay per-project so the human can drill in |
| `download.path()` returns /tmp path that vanishes mid-test | Downloaded ZIP is buffered in the test process; not relying on filesystem persistence |
| Existing CI doesn't have Node 24 + Playwright browser cache | New CI job starts cold (~3 min first run); warm cache ~30s |
| The plugin's `SScribe_Logger` destructor fires inside WP-Playground and corrupts the JSONL | Not present here (we're not in PHPUnit); the mu-plugin's JSONL handle flushes per-sample, no buffered destructor |

---

## 12. Open questions (none blocking)

None. All clarifying answers locked. Implementation can begin once user approves this spec.