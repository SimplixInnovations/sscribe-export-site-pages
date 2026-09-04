# Final CI State — v2.0.0

## Why this exists

Phase 71 of the v2.0.0 release-hardening spec mandates that the
release tag MUST NOT be created until the canonical CI pipeline
is green on the exact final SHA. "Green" means: every required
job is in the required status, no required job is skipped, and
the job-to-SHA mapping is recorded.

A tag cut without this evidence is an unauthorized release — a
WP.org reviewer can immediately spot the gap by checking the CI
badge.

The companion verifier `scripts/verify-final-ci-state.php` walks
this checklist and asserts:

1. Every canonical required job is listed.
2. Every required job has a status (SUCCESS / SKIPPED allowed).
3. No required job is FAILED / CANCELLED / MISSING.
4. The integration test exists.

## Status convention

Each job has a `Status` column. The four valid statuses on a
release-ready SHA:

- **SUCCESS** — the job ran to completion and all checks passed.
  This is the canonical "shippable" state.
- **SKIPPED** — the job intentionally did not run (matrix leg
  excluded, branch filter mismatch). Only allowed for jobs that
  the doc explicitly permits.
- **FAILED** — at least one check inside the job failed.
  Releases MUST NOT ship while any job is FAILED.
- **CANCELLED** — a previous job failed and the workflow was
  cancelled before reaching this one. Releases MUST NOT ship.
- **MISSING** — the job did not run (workflow file missing the
  job, or the workflow itself did not trigger). Releases MUST
  NOT ship.

The verifier accepts only `SUCCESS` or (where permitted) `SKIPPED`.
Any other status fails the gate.

## Canonical required jobs

The canonical CI workflow `.github/workflows/ci.yml` declares
exactly these jobs that MUST be SUCCESS on the final SHA:

| #  | Job                  | Required status | Notes                                                                 |
|----|----------------------|-----------------|-----------------------------------------------------------------------|
| 1  | `version-check`      | SUCCESS         | Phase 16-70 verifier suite + version sync.                            |
| 2  | `lint`               | SUCCESS         | PHP syntax across every shipped file; matrix 8.2/8.3/8.4/8.5.         |
| 3  | `test`               | SUCCESS         | PHPUnit 11.5 full suite; matrix 8.2/8.4/8.5.                          |
| 4  | `audit`              | SUCCESS         | Composer security audit + AI-artifact scan.                            |
| 5  | `frontend-quality`   | SUCCESS         | npm lint + format check + audit.                                      |
| 6  | `real-wp-tests`      | SUCCESS         | PHP 8.2/8.3/8.4 × WP latest/previous — every leg must be SUCCESS.      |
| 7  | `coverage`           | SUCCESS         | Threshold gate (project ≥ 70%, critical ≥ 90%).                       |
| 8  | `plugin-check`       | SUCCESS         | Official WordPress Plugin Check on the exact ZIP.                     |
| 9  | `e2e`                | SUCCESS         | Exact versioned ZIP boots in WP Playground; full Playwright export/download suite passes. |

## How an independent auditor verifies this

```bash
# 1. Run the final CI state verifier.
composer test:final-ci-state

# 2. Cross-check against the live GitHub Actions UI for the
#    final SHA:
gh run list --workflow=ci --commit <final-sha> --json status,conclusion,name
gh run list --workflow=e2e --commit <final-sha> --json status,conclusion,name

# 3. Every row in the canonical required-jobs table above
#    must show "conclusion: success".
```

A green `composer test:final-ci-state` + a passing integration
test + every required job SUCCESS on the final SHA = the release
is ready to tag (subject to Phases 72-76).

## What this contract does NOT cover

- **Branch protection state** — Phase 55 separately audits
  branch-protection rules.
- **Workflow file governance** — Phase 52 separately audits
  `continue-on-error: true`, action pinning, etc.
- **Artifact evidence** — Phase 72 separately records the
  exact ZIP SHA-256 + byte size + file count.
- **Agent final report** — Phase 73 separately dictates the
  report format.

## Change log

- 2026-09-03: Initial Phase 71 final CI state contract +
  verifier + PHPUnit pin. 9 canonical required release signals (version-check, lint, test,
  audit, frontend-quality, real-wp-tests, coverage, plugin-check,
  and exact-package e2e).
