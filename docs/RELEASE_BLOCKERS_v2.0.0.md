# Release Blockers — v2.0.x (canonical contract)

## Why this exists

Phase 70 of the release-hardening spec mandates that the release MUST
NOT be declared ready while ANY of the canonical release-blocker
conditions remain. This document is the **release blocker registry**.

It distinguishes two kinds of blockers:

- **Static blockers** — closed by code, tests, or tracked docs. Their
  `Status` is `RESOLVED` in this file. Closure source: `static`.
- **Dynamic blockers** — closed at certification time by ignored,
  regenerated evidence files under `dist/`. Their tracked `Status` is
  `DEFERRED`; the strict cert gate resolves them at runtime by reading
  the evidence file referenced in `Closure source`. **No commit to
  this file is required to upgrade a dynamic blocker at certification
  time.**

That separation eliminates the SHA circularity the v2.0.0 closeout
identified: previously, closing a dynamic blocker required committing a
tracked edit to flip `DEFERRED → RESOLVED`, which changed the source
SHA, which invalidated the just-recorded evidence, in a loop.

## Status convention

| Status    | Meaning                                                                                   |
|-----------|-------------------------------------------------------------------------------------------|
| RESOLVED  | Blocker closed. Static rows: evidence is in this doc. Dynamic rows: closure source proves it at cert time. |
| DEFERRED  | Pending closure. Dynamic rows only — closure source determines when strict-mode upgrades.  |
| OPEN      | Work in progress. Releases MUST NOT ship while any blocker is OPEN.                       |
| BLOCKED   | Gated on something external. Releases MUST NOT ship while any blocker is BLOCKED.         |

The verifier recognises `RESOLVED` and `DEFERRED`. `OPEN` and `BLOCKED`
are vocabulary-reserved and will fail validation.

## Closure source vocabulary

| Token                       | Proven by                                                                                                          |
|-----------------------------|--------------------------------------------------------------------------------------------------------------------|
| `static`                    | Tracked code/test/docs — `Status` is `RESOLVED` in this file.                                                       |
| `phase71-evidence`          | `dist/final-execution-evidence.json` — all 9 required signals are `SUCCESS` or `LOCAL_PASS`.                       |
| `e2e-evidence`              | `dist/final-execution-evidence.json` — `signals.e2e.status` is `SUCCESS` or `LOCAL_PASS` and the log is non-empty. |
| `plugin-check-evidence`     | `dist/release-certification-evidence.json` — `plugin_check_url` points at a real log and the log records PASS.     |
| `clean-install-evidence`    | `dist/clean-install-evidence.json` — required checks all `PASS`.                                                    |
| `runtime-export-evidence`   | `dist/runtime-export-evidence.json` — required export checks all `PASS`.                                            |
| `manual-runtime-evidence`   | `dist/manual-runtime-evidence.json` + `dist/evidence/manual-runtime.log` — all six exact-ZIP environment runs are `PASS`. |
| `source-transparency-evidence` | `dist/source-transparency-evidence.json` — public source URL is reachable and contains the expected artifacts.   |

## Canonical blockers

| #  | Blocker                                                                                   | Status   | Closure source              | Evidence                                                                                                          |
|----|-------------------------------------------------------------------------------------------|----------|-----------------------------|-------------------------------------------------------------------------------------------------------------------|
| 1  | Any required CI job red                                                                   | DEFERRED | phase71-evidence            | Strict cert gate reads `dist/final-execution-evidence.json`; resolves to RESOLVED when every required signal is `SUCCESS` or `LOCAL_PASS`. |
| 2  | Any required job skipped                                                                  | DEFERRED | phase71-evidence            | Same evidence as #1 — `LOCAL_PASS` is a legitimate shippable status; skipped signals are detected by the absence of a signal key or an empty `evidence` path. |
| 3  | PHPUnit runtime fatal                                                                     | RESOLVED | static                      | 2451 tests, 7930 assertions, 0 failures, 0 deprecations, 28 skipped, deterministic across 3+ `--order-by=random` seeds. Fixes: WPML docblock→attributes; download-security / format-matrix / ajax-network-trace order independence via `setUpBeforeClass`; bootstrap self-heal regenerates missing manifest files; `tests/bootstrap.php` `get_user_by` stub honors field/value pairs so the `SScribe_Privacy` fast path is correctly testable. |
| 4  | E2E not actually executed                                                                 | DEFERRED | e2e-evidence                | `npm run test:e2e:smoke` + `npm run test:e2e:full` + `npm run test:e2e:a11y` against the exact final ZIP. Performance is separately enforced by `composer test:perf`. |
| 5  | Security workflow red                                                                     | RESOLVED | static                      | `tests/Security/SScribe_Security_Test.php` + Phase 49/66 contract gates green.                                     |
| 6  | All Languages broken                                                                      | RESOLVED | static                      | Phase 68 #4–6 registry + `SScribe_Export_Query_Controller` `__all__` paths + Phase 58 acceptance matrix.            |
| 7  | All Types Preview mismatch                                                                | RESOLVED | static                      | Phase 68 #7 registry + admin display renders `sscribe-post-type-card-any` sentinel.                                  |
| 8  | Stale count race                                                                          | RESOLVED | static                      | Phase 68 #1–3 registry + admin JS request-id guard.                                                                  |
| 9  | Stale abort retry                                                                         | RESOLVED | static                      | Phase 68 #2 registry + admin JS abort short-circuit.                                                                |
| 10 | Preflight JS missing function                                                             | RESOLVED | static                      | Phase 68 #9–12 registry + `run_preflight` PHPUnit + preflight 429/503/500 paths.                                    |
| 11 | Terminal 500 retry storm                                                                  | RESOLVED | static                      | Phase 68 #12–15 registry + canonical Retry-After header gate (`SScribe_Rate_Limit_Response_Test`).                   |
| 12 | Operational fatal/error log not durable                                                   | RESOLVED | static                      | Phase 68 #18–19 registry + `SScribe_Operational_Logger_Test` + fatal-handler capture-on-shutdown contract.           |
| 13 | Redis limiter inconsistent                                                                | RESOLVED | static                      | Phase 68 #20 registry + `SScribe_Export_Rate_Limiter_Object_Cache_Test` monotonicity.                                |
| 14 | Invalid WordPress/PHP minimum metadata                                                    | RESOLVED | static                      | `Requires at least: 6.1` + `Requires PHP: 8.2`; WordPress 6.1/PHP 8.2 is the declared minimum test pair; `Tested up to: 7.1`. |
| 15 | Plugin Check not run on exact ZIP                                                         | DEFERRED | plugin-check-evidence       | Official Plugin Check on the exact merged ZIP via `wp plugin check --require=...` or the GitHub Plugin Check action. Evidence captured to `dist/release-certification-evidence.json` → `plugin_check_url`. |
| 16 | Exact ZIP not clean-install tested                                                        | DEFERRED | clean-install-evidence      | Fresh WordPress instance + `wp plugin install dist/sscribe-export-site-pages-*.zip --force --activate` + activation/deactivation/uninstall round-trip; evidence captured to `dist/clean-install-evidence.json` + `dist/evidence/clean-install.md`. |
| 17 | Exact ZIP not runtime-export tested                                                       | DEFERRED | runtime-export-evidence     | Run the exact merged ZIP through DOCX / PDF / HTML / Markdown / All Formats / All Languages / Arabic / retry / finalize / download security against a production-like WordPress instance. Evidence captured to `dist/runtime-export-evidence.json` + `dist/evidence/runtime-exports.log`. |
| 18 | Source / build transparency unresolved                                                    | DEFERRED | source-transparency-evidence | Build/source documentation is complete; canonical repository or an equivalent maintained exact-source/build mirror must be public so a WordPress.org reviewer can reproduce the build. Evidence captured to `dist/source-transparency-evidence.json` after anonymous HTTP verification of source / `composer.json` / `scripts/build-release.php` / build docs. **Genuine owner-controlled external action; if not authorized, release to WP.org cannot ship.** |
| 19 | License inventory unresolved                                                              | RESOLVED | static                      | Phase 37 contract + `docs/SECURITY_MATRIX_v2.0.0.md` + `SScribe_Third_Party_License_Test` + `SScribe_License_SPDIX_Test`. |
| 20 | Release path capable of rebuilding untested bytes                                         | RESOLVED | static                      | Phase 53 contract + `SScribe_Release_Pipeline_Test` + `SScribe_Artifact_Certification_Test`.                         |
| 21 | Manual runtime environment matrix not executed on exact ZIP                                | DEFERRED | manual-runtime-evidence     | Phase 69 strict evidence binds Standard WordPress, WPML, Redis ON/OFF, OpenLiteSpeed, and Cloudflare/proxy PASS results to the exact current source SHA and ZIP SHA-256. |

## How an independent auditor verifies this

```bash
# 1. Run the strict release-blocker gate. It will:
#    - Validate doc schema / vocabulary / Closure source tokens.
#    - For each DEFERRED row, check the closure source evidence.
#    - Upgrade dynamic rows that prove RESOLVED from ignored evidence.
#    - Fail any row still effectively DEFERRED at strict time.
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers

# 2. Run the integration test pinning the contract.
vendor/bin/phpunit tests/Integration/SScribe_Release_Blockers_Test.php

# 3. Inspect the manifest emitted by the verifier.
cat dist/release-blockers-manifest.json
```

A green strict `composer test:release-blockers` means every canonical
row is RESOLVED — by tracked evidence (static) or by ignored evidence
(dynamic) — and the release is ready to tag/upload (subject to the
remaining strict final-state, exact-artifact evidence, and the
genuine external action on #18).

## What this contract does NOT cover

- **CI state at the exact final SHA** — Phase 71 separately asserts
  every required job is `SUCCESS` or `LOCAL_PASS` on the final SHA.
- **Exact artifact evidence** — Phase 72 separately records the
  ZIP SHA-256, byte size, and file count.
- **Agent final report** — Phase 73 separately dictates the report
  format.
- **Independent auditor handoff** — Phase 74 separately defines the
  handoff protocol.

## Change log

- 2026-09-03: Initial Phase 70 release-blocker checklist + verifier
  + PHPUnit pin. 20 canonical blockers recorded.
- 2026-09-04: Added `Closure source` column. Dynamic blockers now
  resolve at strict-cert time from ignored `dist/` evidence, breaking
  the SHA-loop the v2.0.0 closeout identified. `LOCAL_PASS` introduced
  as a legitimate shippable status (matches Phase 71 vocabulary).
- 2026-09-05: Removed the historical v2.0.0 hardcoded SHA from any
  tracked file. Source SHA / ZIP SHA / timestamp live in ignored
  `dist/` JSONs only.
- 2026-09-21: Added fail-closed Phase 69 manual-runtime execution as dynamic blocker #21, using ignored exact-SHA/exact-ZIP evidence.
- 2026-09-15: Phase 70 synchronized with native WordPress E2E.
  Obsolete zero-test Playwright perf requirement removed from blocker #4.
  Performance remains enforced by `composer test:perf`. Current PHPUnit
  certification evidence refreshed (2451 tests, 7930 assertions). Contract
  confirmed for v2.0.2. Registry heading updated to v2.0.x.
