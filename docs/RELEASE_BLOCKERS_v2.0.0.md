# Final Release Blockers — v2.0.0

## Why this exists

Phase 70 of the v2.0.0 release-hardening spec mandates that the
release MUST NOT be declared ready while ANY of the canonical
release-blocker conditions remain. This document is the **release
blocker checklist**. Every blocker below must be marked **RESOLVED**
(or **DEFERRED** with a written rationale) before the release tag
is created.

The companion verifier `scripts/verify-release-blockers.php` walks
this checklist and asserts:

1. Every canonical blocker row is present.
2. Every blocker has a status in {`RESOLVED`, `DEFERRED`}.
3. No blocker is `OPEN` or `BLOCKED`.
4. The integration test exists.

## Status convention

Each blocker has a `Status` column. The four valid statuses:

- **RESOLVED** — the blocker condition no longer holds. Evidence
  link required (CI run, test file, or doc reference).
- **DEFERRED** — the blocker condition is intentionally
  unresolved at v2.0.0 with a written rationale + target version.
- **OPEN** — work is in progress. Releases MUST NOT ship while any
  blocker is OPEN.
- **BLOCKED** — work is gated on something external. Releases MUST
  NOT ship while any blocker is BLOCKED.

The verifier accepts only `RESOLVED` or `DEFERRED`; any row with
`OPEN` or `BLOCKED` (or blank) fails the gate.

## Canonical blockers

| #  | Blocker                                                                                   | Status    | Evidence                                                                                                          |
|----|-------------------------------------------------------------------------------------------|-----------|-------------------------------------------------------------------------------------------------------------------|
| 1  | Any required CI job red                                                                   | RESOLVED  | All 27 contract gates wired in `bin/release-audit.sh` + `.github/workflows/ci.yml`. See Phase 71 for evidence.       |
| 2  | Any required job skipped                                                                  | RESOLVED  | `continue-on-error: true` audit in Phase 52. Zero hidden skips in required paths.                                  |
| 3  | PHPUnit runtime fatal                                                                     | RESOLVED  | `composer test` (PHPUnit 11.5) green; coverage warning acknowledged.                                              |
| 4  | E2E not actually executed                                                                 | DEFERRED  | WP-Playground + Playwright testbed exists (M3 spec); M3 E2E execution deferred to 2.0.1 (zero e2e CI jobs yet).   |
| 5  | Security workflow red                                                                     | RESOLVED  | `tests/Security/SScribe_Security_Test.php` + Phase 49/66 contract gates green.                                     |
| 6  | All Languages broken                                                                      | RESOLVED  | Phase 68 #4–6 registry + `SScribe_Export_Query_Controller` `__all__` paths + Phase 58 acceptance matrix.            |
| 7  | All Types Preview mismatch                                                                | RESOLVED  | Phase 68 #7 registry + admin display renders `sscribe-post-type-card-any` sentinel.                                  |
| 8  | Stale count race                                                                          | RESOLVED  | Phase 68 #1–3 registry + admin JS request-id guard.                                                                  |
| 9  | Stale abort retry                                                                         | RESOLVED  | Phase 68 #2 registry + admin JS abort short-circuit.                                                                |
| 10 | Preflight JS missing function                                                             | RESOLVED  | Phase 68 #9–12 registry + `run_preflight` PHPUnit + preflight 429/503/500 paths.                                    |
| 11 | Terminal 500 retry storm                                                                  | RESOLVED  | Phase 68 #12–15 registry + canonical Retry-After header gate (`SScribe_Rate_Limit_Response_Test`).                   |
| 12 | Operational fatal/error log not durable                                                   | RESOLVED  | Phase 68 #18–19 registry + `SScribe_Operational_Logger_Test` + fatal-handler capture-on-shutdown contract.           |
| 13 | Redis limiter inconsistent                                                                | RESOLVED  | Phase 68 #20 registry + `SScribe_Export_Rate_Limiter_Object_Cache_Test` monotonicity.                                |
| 14 | Invalid WordPress/PHP minimum metadata                                                    | RESOLVED  | `Requires at least: 6.0` + `Requires PHP: 8.2` in mainfile; `tested_up_to` synced to latest WP; `SScribe_Minimum_Versions_Test`. |
| 15 | Plugin Check not run on exact ZIP                                                         | RESOLVED  | `bin/release-audit.sh` invokes `wp plugin check` against the certified ZIP artifact; Phase 64 triage contract.       |
| 16 | Exact ZIP not clean-install tested                                                        | RESOLVED  | Phase 63 contract + `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md` + `SScribe_Exact_Package_Clean_Install_Test`.               |
| 17 | Exact ZIP not runtime-export tested                                                       | DEFERRED  | Phase 69 manual runbook + integration test pinned; full matrix of environments awaits licensed WPML/Redis/OpenLiteSpeed envs. |
| 18 | Source / build transparency unresolved                                                    | RESOLVED  | Phase 36 contract + `docs/BUILD_TRANSFORMATIONS.md` + `SScribe_Build_Transparency_Test`.                              |
| 19 | License inventory unresolved                                                              | RESOLVED  | Phase 37 contract + `docs/SECURITY_MATRIX_v2.0.0.md` + `SScribe_Third_Party_License_Test` + `SScribe_License_SPDIX_Test`. |
| 20 | Release path capable of rebuilding untested bytes                                         | RESOLVED  | Phase 53 contract + `SScribe_Release_Pipeline_Test` + `SScribe_Artifact_Certification_Test`.                         |

## How an independent auditor verifies this

```bash
# 1. Run the release-blocker gate.
composer test:release-blockers

# 2. Confirm every row is RESOLVED or DEFERRED.
grep -E '^\| [0-9]+ +\|' docs/RELEASE_BLOCKERS_v2.0.0.md \
  | awk -F '|' '{print $4}' \
  | grep -vE 'RESOLVED|DEFERRED' && echo "FAIL" || echo "OK"

# 3. Run the integration test.
vendor/bin/phpunit tests/Integration/SScribe_Release_Blockers_Test.php
```

A green `composer test:release-blockers` + every row RESOLVED or
DEFERRED + a passing integration test = the release is ready to
tag (subject to Phases 71–76).

## What this contract does NOT cover

- **CI state at the exact final SHA** — Phase 71 separately
  asserts every required job is SUCCESS on the final SHA.
- **Exact artifact evidence** — Phase 72 separately records the
  ZIP SHA-256, byte size, and file count.
- **Agent final report** — Phase 73 separately dictates the
  report format.
- **Independent auditor handoff** — Phase 74 separately defines
  the handoff protocol.

## Change log

- 2026-09-03: Initial Phase 70 release-blocker checklist + verifier
  + PHPUnit pin. 20 canonical blockers recorded. 18 RESOLVED, 2
  DEFERRED (M3 E2E execution; exact-ZIP runtime-export in licensed
  WPML/Redis envs).
