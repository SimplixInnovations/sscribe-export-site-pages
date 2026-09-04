# Final Release Blockers — v2.0.0

## Why this exists

Phase 70 of the v2.0.0 release-hardening spec mandates that the
release MUST NOT be declared ready while ANY of the canonical
release-blocker conditions remain. This document is the **release
blocker checklist**. The source branch may be merged while local
verification is pending, but the release tag / WordPress.org upload
requires **every row to be RESOLVED**.

The companion verifier `scripts/verify-release-blockers.php` walks
this checklist and asserts:

1. Every canonical blocker row is present.
2. Every blocker has a recognized status.
3. `DEFERRED`, `OPEN`, and `BLOCKED` are all release-stop states.
4. The release is shippable only when every canonical row is `RESOLVED`.
5. The integration test exists.

## Status convention

Each blocker has a `Status` column. The four valid statuses:

- **RESOLVED** — the blocker condition no longer holds. Evidence
  link required (CI run, test file, or doc reference).
- **DEFERRED** — work/evidence is intentionally pending (for this candidate,
  mostly local final verification). It is **not shippable** until changed to
  RESOLVED with real evidence.
- **OPEN** — work is in progress. Releases MUST NOT ship while any
  blocker is OPEN.
- **BLOCKED** — work is gated on something external. Releases MUST
  NOT ship while any blocker is BLOCKED.

The verifier recognizes `RESOLVED` and `DEFERRED`, but it fails the final
release gate while **any DEFERRED row remains**. `OPEN`, `BLOCKED`, or blank
also fail.

## Canonical blockers

| #  | Blocker                                                                                   | Status    | Evidence                                                                                                          |
|----|-------------------------------------------------------------------------------------------|-----------|-------------------------------------------------------------------------------------------------------------------|
| 1  | Any required CI job red                                                                   | DEFERRED  | GitHub Actions availability is not treated as release evidence for this merge. Final verification must run locally with `bash bin/release-audit.sh` before tagging/upload. |
| 2  | Any required job skipped                                                                  | DEFERRED  | Workflow structure forbids hidden pass-on-error paths, but final execution evidence is local for this release candidate. |
| 3  | PHPUnit runtime fatal                                                                     | DEFERRED  | Source/test architecture has been repaired; run `composer test` locally on the merged final SHA before release. |
| 4  | E2E not actually executed                                                                 | DEFERRED  | Exact-package WP Playground + Playwright suite exists and is release-required; run `npm run test:e2e:full` locally on the final ZIP before release. |
| 5  | Security workflow red                                                                     | RESOLVED  | `tests/Security/SScribe_Security_Test.php` + Phase 49/66 contract gates green.                                     |
| 6  | All Languages broken                                                                      | RESOLVED  | Phase 68 #4–6 registry + `SScribe_Export_Query_Controller` `__all__` paths + Phase 58 acceptance matrix.            |
| 7  | All Types Preview mismatch                                                                | RESOLVED  | Phase 68 #7 registry + admin display renders `sscribe-post-type-card-any` sentinel.                                  |
| 8  | Stale count race                                                                          | RESOLVED  | Phase 68 #1–3 registry + admin JS request-id guard.                                                                  |
| 9  | Stale abort retry                                                                         | RESOLVED  | Phase 68 #2 registry + admin JS abort short-circuit.                                                                |
| 10 | Preflight JS missing function                                                             | RESOLVED  | Phase 68 #9–12 registry + `run_preflight` PHPUnit + preflight 429/503/500 paths.                                    |
| 11 | Terminal 500 retry storm                                                                  | RESOLVED  | Phase 68 #12–15 registry + canonical Retry-After header gate (`SScribe_Rate_Limit_Response_Test`).                   |
| 12 | Operational fatal/error log not durable                                                   | RESOLVED  | Phase 68 #18–19 registry + `SScribe_Operational_Logger_Test` + fatal-handler capture-on-shutdown contract.           |
| 13 | Redis limiter inconsistent                                                                | RESOLVED  | Phase 68 #20 registry + `SScribe_Export_Rate_Limiter_Object_Cache_Test` monotonicity.                                |
| 14 | Invalid WordPress/PHP minimum metadata                                                    | RESOLVED  | `Requires at least: 6.1` + `Requires PHP: 8.2`; WordPress 6.1/PHP 8.2 is the declared minimum test pair; `Tested up to: 7.1`. |
| 15 | Plugin Check not run on exact ZIP                                                         | DEFERRED  | Repository gate is fail-closed and portable; run official Plugin Check locally against the exact final ZIP before WordPress.org upload. |
| 16 | Exact ZIP not clean-install tested                                                        | DEFERRED  | Clean-install contract exists; perform the final local clean WordPress install/activation smoke against the exact merged ZIP. |
| 17 | Exact ZIP not runtime-export tested                                                       | DEFERRED  | Run the final package through real exports (including All Languages, all formats, retry/finalize/download) in the local production-like environment. |
| 18 | Source / build transparency unresolved                                                    | DEFERRED  | Build/source documentation is complete, but the canonical repository is private and the deployed ZIP omits build tooling. Before WordPress.org submission, make the canonical repository or an equivalent maintained exact-source/build mirror public; private/reviewer-only access is insufficient. |
| 19 | License inventory unresolved                                                              | RESOLVED  | Phase 37 contract + `docs/SECURITY_MATRIX_v2.0.0.md` + `SScribe_Third_Party_License_Test` + `SScribe_License_SPDIX_Test`. |
| 20 | Release path capable of rebuilding untested bytes                                         | RESOLVED  | Phase 53 contract + `SScribe_Release_Pipeline_Test` + `SScribe_Artifact_Certification_Test`.                         |

## How an independent auditor verifies this

```bash
# 1. Run the strict release-blocker gate.
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers

# 2. Confirm every row is RESOLVED. DEFERRED is valid only while preparing the release, never for tag/upload.
grep -E '^\| [0-9]+ +\|' docs/RELEASE_BLOCKERS_v2.0.0.md \
  | awk -F '|' '{print $4}' \
  | grep -vE 'RESOLVED' && echo "FAIL" || echo "OK"

# 3. Run the integration test.
vendor/bin/phpunit tests/Integration/SScribe_Release_Blockers_Test.php
```

A green strict `composer test:release-blockers` means every canonical row is
RESOLVED; only then is the release ready to tag/upload (subject to the
remaining strict final-state and exact-artifact evidence).

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
  + PHPUnit pin. 20 canonical blockers recorded. 12 RESOLVED, 8 DEFERRED. Deferred rows are explicit local/external
  release-verification requirements and must be closed before the
  WordPress.org upload/tag; they are not hidden code-completion claims.
