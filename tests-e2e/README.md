# tests-e2e — Playwright + native WordPress testbed

Real WordPress, real browser. Boots a native PHP CLI server with
WordPress + SQLite and drives Chromium via @playwright/test.

## Quickstart

```bash
# 1. Build the plugin ZIP
composer release:prepare

# 2. Run smoke (fast — ~1 minute)
npm run test:e2e:smoke

# 3. Run all tests
npm run test:e2e:full
```

## Layout

| Path | Purpose |
|---|---|
| `e2e/` | Flow tests (~50 specs across export / history / support / debug) |
| `a11y/` | Axe scans (~20 specs at WCAG 2.2 AA + best-practice) |
| `fixtures/mu-plugins/` | Test-only PHP (bootstrap, reset endpoints, perf sink) |
| `fixtures/shared.ts` | Custom test wrapper (auto-login + error listener) |
| `helpers/` | login + axe + JSON-OK + perf-sink + error-listener |
| `runtime/` | Native WordPress HTTP runtime adapter |
| `regression-discipline.mjs` | CI script: proves 8 regressions are caught |

## Adding a new spec

1. Pick the right project (`e2e/` or `a11y/`)
2. Import `test` and `expect` from `../fixtures/shared` (NOT from `@playwright/test`)
3. Use `adminPage` fixture (auto-logs-in)
4. For a11y specs: use `runAxe(page)` from `../helpers/axe-rules`

## Runtime

The E2E suite boots a native WordPress HTTP runtime (real PHP + real WP + SQLite).
Controlled by `SSCREIBE_E2E_RUNTIME=native` (default). The PHP binary can be
overridden via `SSCRIBE_E2E_PHP_BINARY`.
