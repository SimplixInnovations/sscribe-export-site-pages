# SScribe v2.0.0 — Branch Protection & Required Checks

This file is the certifier's pre-flight branch-protection policy. Every
rule below maps 1:1 to a GitHub Branch Settings rule; the file pins the
exact state for `release/2.0.0-final-hardening` at the audited SHA.

## Why this file exists

Branch protection is a server-side rule. This document is the local
mirror of what the GitHub repository settings should enforce. Any
maintainer pushing a change can read this file to know what will pass
and what will not.

## Branch under protection

```
Branch:                       release/2.0.0-final-hardening
Base SHA:                     a5c093c (a5c093ca5672171f932a5d458755acd3649b3594)
Audited Date:                 2026-09-02
Promotion target (post-2.0.0): main
```

## Required status checks (must ALL be green)

The following GitHub Actions jobs are configured in `.github/workflows/ci.yml`.
All are required for merge on `release/2.0.0-final-hardening`:

| Required check         | Job name             | Source                     | What it proves                          |
|------------------------|----------------------|----------------------------|----------------------------------------|
| Version sync           | `version-check`      | ci.yml                     | Plugin header version == readme == main file |
| PHPUnit full suite     | `test`               | ci.yml                     | 997 tests still pass                    |
| Frontend quality       | `frontend-quality`   | ci.yml                     | ESLint + Stylelint clean                |
| Audit                  | `audit`              | ci.yml                     | Build transparency invariants            |
| Real WordPress Integration Suite (matrix) | `real-wp-tests` | ci.yml              | M3 testbench (PHP 8.2/8.3/8.4 × WP latest/previous) clean |
| Submission Package Check | `plugin-check`    | ci.yml                     | WP.org official plugin-check passes     |

Additional required jobs (matrix):

| Required check         | Job name             | Source                     |
|------------------------|----------------------|----------------------------|
| Release audit gate     | `release-audit`      | release-audit.yml (Phase 33) |
| Coverage PHP 8.4       | `coverage`           | ci.yml (informational; coverage gate = 80%) |

## Required reviews

- **At least 1 approving review** before merge to `release/2.0.0-final-hardening`.
- **At least 2 approving reviews** for any change that touches:
  - `includes/class-sscribe-activator.php`
  - `includes/class-sscribe-private-storage.php`
  - `scripts/build-release.php`
  - `bin/release-audit.sh`
  - `phpcs.xml` / `phpstan.neon` / `.github/workflows/*.yml`
- **No direct push** — `release/2.0.0-final-hardening` must require PRs.

## Conversation resolution

- All review comments must be resolved before merge.
- PRs without conversation are blocked from squash-merge.

## Signed commits

- The branch requires `--signoff` for every commit (DCO).
- The branch rejects force-pushes.
- The branch rejects deletion (a non-admin must delete the branch from
  `main` after a clean release tag).

## Allowed merge methods

- **Squash** is the only allowed merge method (preserves a clean linear
  history on `release/2.0.0-final-hardening`).
- Rebase-merge is disabled (the branch's history is too valuable to flatten).
- Merge commits are disabled (would fork the linear history).

## Restrictions

- No force-pushes, regardless of role.
- No commits bypassing branch protection, regardless of role.
- No admin-bypass BETA — every push goes through the gate.

## Reset policy

When `release/2.0.0-final-hardening` is promoted into `main`, the branch
protection is reset on `main`. The promotion commit on `main` is:

```
Release v2.0.0
=============
Audited SHA: a5c093ca5672171f932a5d458755acd3649b3594

  - 997 PHPUnit pass + 21 skipped + 0 fail.
  - 0 PHPStan-level-7 errors.
  - 0 PHPCS violations across 90 files.
  - 0 ESLint + Stylelint errors.
  - 0 Plugin-Check errors (5 originally flagged, all addressed).
  - 0 npm audit vulnerabilities.
  - ZIP artifact SHA256 matches sidecar.
  - Performance envelope within WP.org shared-host tolerance.

See docs/CI_EVIDENCE_v2.0.0.md for the audit log at this SHA.
See docs/SECURITY_MATRIX_v2.0.0.md for the security matrix.
See docs/PERFORMANCE_BENCHMARKS_v2.0.0.md for the perf envelope.
See docs/WP_ORG_CLEAN_INSTALL_SMOKE.md for the install evidence.
```

## Failure modes for any contributor

A commit that lands on `release/2.0.0-final-hardening` and trips one
of the required checks MUST be reverted before another commit can land
on top. The gate is "all-green-or-revert", not "all-green-or-pause".

This is intentional: a paused gate holds up the release; a revert
quickly returns the branch to green and lets the next PR continue.

## How to verify locally

Before pushing, run:

```bash
bash bin/release-audit.sh
```

If the script exits 0, the push will pass all required checks. If not,
fix the reported failure before pushing — do not bypass with an
`--no-verify` (there is no such loophole; the gate is server-side).

## Living document

This file is part of the v2.0.0 release hardening audit. It is
intentionally pinned to commit `a5c093c`. Any change to the required
checks (e.g., adding coverage) must:

1. Update `docs/BRANCH_PROTECTION_v2.0.0.md`.
2. Update `.github/workflows/ci.yml` accordingly.
3. Update `bin/release-audit.sh` if a new local gate is added.
4. Document the change in a commit message and PR description.
