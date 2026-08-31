# tests-e2e — Playwright + WP-Playground testbed

Real WordPress, real browser. Mounts `dist/*.zip` into WP-Playground 3.1.44
and drives Chromium via @playwright/test.

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
| `perf/` | Perf budgets (~10 specs against format-baselines.json) |
| `fixtures/blueprint.json` | WP-Playground blueprint (mounts dist/*.zip) |
| `fixtures/mu-plugins/` | Test-only PHP that crosses the WASM sandbox |
| `fixtures/shared.ts` | Custom test wrapper (auto-login + error listener) |
| `helpers/` | login + axe + JSON-OK + perf-sink + error-listener + fs-ext stub |
| `baselines/format-baselines.json` | Per-format per-page timing/memory baselines |
| `regression-discipline.mjs` | CI script: proves 8 regressions are caught |

## Adding a new spec

1. Pick the right project (`e2e/`, `a11y/`, or `perf/`)
2. Import `test` and `expect` from `../fixtures/shared` (NOT from `@playwright/test`)
3. Use `adminPage` fixture (auto-logs-in)
4. For a11y specs: use `runAxe(page)` from `../helpers/axe-rules`
5. For perf specs: import `perfTest` from `../helpers/perf-sink`, use `perfSession` + `pollPerfSink`

## Performance baselines

`baselines/format-baselines.json` mirrors `includes/class-sscribe-adaptive-metrics.php`'s
`BASELINE_SECONDS` / `BASELINE_MB`. Update only via deliberate PR with new
median measurement — never via silent CI re-baselining.

## Sandbox / firewall notes

The CI runner is ubuntu-latest so firewall issues are rare. On the local
sandbox, `@axe-core/playwright` may need the git-source fallback per
[composer-install-firewall-workaround](../memory/composer-install-firewall-workaround.md).
