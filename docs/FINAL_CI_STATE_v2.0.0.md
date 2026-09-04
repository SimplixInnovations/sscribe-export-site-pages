# Final CI State — v2.0.0

## Why this exists

Phase 71 is the final execution-evidence gate for SScribe v2.0.0. It must
never infer a passing release from a policy table that merely says what
*should* pass. The exact final source SHA must have recorded execution
evidence before a release tag or WordPress.org upload.

GitHub-hosted execution and local execution are treated separately:

- **SUCCESS** means the named GitHub job actually ran on the recorded final SHA
  and completed successfully.
- **LOCAL_PASS** is an explicit substitution for this release only when GitHub
  Actions did not start the job at all (for example, runner/account quota or
  availability). The equivalent local command must have been executed against
  the exact recorded final SHA and its evidence must be named in the table.
- **UNAVAILABLE**, **FAILED**, **CANCELLED**, **SKIPPED**, and **MISSING** are
  not release-ready statuses for a required job.

The normal source/CI verifier checks the contract structure. Strict release
certification is enabled with `SSCRIBE_RELEASE_CERTIFICATION=1`; in strict
mode every recorded row must be `SUCCESS` or `LOCAL_PASS`, and the recorded
source SHA must equal the checkout being certified.

## Status convention

| Status | Meaning | Release-ready? |
|---|---|---|
| SUCCESS | GitHub job actually executed on the final SHA and passed. | Yes |
| LOCAL_PASS | Equivalent local gate executed on the exact final SHA because GitHub did not start the job; concrete evidence is recorded. | Yes |
| UNAVAILABLE | GitHub did not start the job and no equivalent local proof is recorded yet. | No |
| FAILED | A required command or assertion failed. | No |
| CANCELLED | Execution was cancelled. | No |
| SKIPPED | Required execution did not occur. | No |
| MISSING | Required job/evidence is absent. | No |

## Canonical required jobs

This table is the **policy**, not proof of execution.

| # | Job | Required status | Local equivalent when GitHub never starts the job |
|---|---|---|---|
| 1 | `version-check` | SUCCESS | `composer version:check` plus the source-contract verifiers invoked by CI |
| 2 | `lint` | SUCCESS | PHP syntax matrix / supported-PHP lint validation |
| 3 | `test` | SUCCESS | `composer test` on the supported PHP matrix, at minimum the local release PHP plus explicit compatibility evidence |
| 4 | `audit` | SUCCESS | `composer audit --locked --format=plain --abandoned=fail` plus the repository security scans |
| 5 | `frontend-quality` | SUCCESS | `npm ci --ignore-scripts`, `npm run test:audit-helper`, `npm run audit:js`, and `npm run lint` |
| 6 | `real-wp-tests` | SUCCESS | Real WordPress integration matrix, including supported minimum/current WordPress coverage |
| 7 | `coverage` | SUCCESS | `composer test:coverage` then `composer test:coverage:check` |
| 8 | `plugin-check` | SUCCESS | Official WordPress Plugin Check against the exact final ZIP |
| 9 | `e2e` | SUCCESS | `npm run test:e2e:smoke` and `npm run test:e2e:full` against the exact final ZIP |

## Recorded final state

Final source SHA: `PENDING_FINAL_SHA`

The rows below are deliberately non-green until final certification is
performed. Never copy the required status from the policy table into this
table without execution evidence.

| # | Job | Observed status | Evidence |
|---|---|---|---|
| 1 | `version-check` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 2 | `lint` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 3 | `test` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 4 | `audit` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 5 | `frontend-quality` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 6 | `real-wp-tests` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 7 | `coverage` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 8 | `plugin-check` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |
| 9 | `e2e` | UNAVAILABLE | PENDING_FINAL_CERTIFICATION |

## How an independent auditor verifies this

For a normal source-contract check:

```bash
composer test:final-ci-state
```

For the actual release gate:

```bash
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
```

Then independently compare `Final source SHA` with `git rev-parse HEAD` and
inspect every evidence reference. If GitHub Actions is available, cross-check
the corresponding runs. If a row uses `LOCAL_PASS`, inspect the local report
and exact command output for that same SHA.

A strict green result plus nine shippable recorded rows is required before
tagging, subject to the remaining release blockers.

## What this contract does NOT cover

- Exact artifact identity is verified by Phase 72.
- Source/build public availability is tracked by Phase 70 blocker 18.
- Tag policy, branch policy, and WordPress.org submission remain separate gates.

## Change log

- 2026-09-04: Separated required-job policy from actual recorded execution
  evidence; added fail-closed strict certification and an explicit LOCAL_PASS
  path for jobs GitHub never started.
- 2026-09-03: Initial Phase 71 contract.
