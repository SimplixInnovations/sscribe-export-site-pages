# SScribe v2.x — Branch Protection Policy & Live Status

This document defines repository governance for the two canonical long-lived branches.
It is not proof of live GitHub settings; live protection must be verified through the
repository API/settings.

## Target branches

```
Branches: main, develop
Audited Date: 2026-09-18
```

## Observed live status

At the 2026-09-18 repository audit, GitHub reported:

```
main:    UNPROTECTED
develop: UNPROTECTED
```

This is a repository-administration gap, not a WordPress plugin defect and not a
WordPress.org submission requirement.

## Required status checks (desired server-side policy)

| Check | Job / workflow | What it proves |
|---|---|---|
| Version Sync | `version-check` | Metadata and release contracts are internally consistent. |
| PHPUnit | `test` | Runtime/unit/integration suite passes. |
| Real WordPress Integration Suite | `real-wp-tests` | Real WordPress compatibility matrix passes. |
| Submission Package Check | `plugin-check` | Official WordPress Plugin Check passes on the exact ZIP. |
| Exact-package browser runtime | `e2e` workflow | Built ZIP boots and completes browser flows. |
| Release audit | `release-audit` workflow | Cross-platform promotion audit passes. |

## Desired protection rules

### `develop` — reviewed integration branch

- Changes enter through pull requests; no ordinary direct pushes.
- At least 1 approving review for ordinary changes.
- Sensitive security/release paths should require 2 approving reviews through
  CODEOWNERS or an equivalent review rule.
- Conversation resolution is required before merge.
- Required status checks must pass.
- Signed commits or DCO `--signoff` are required for release promotion.
- Squash is the project merge method for pull requests into `develop`.

### `main` — exact-SHA alias of `develop`

The branch-topology contract requires `origin/main == origin/develop`. Therefore
`main` must **not** use a squash/rebase/merge-commit PR promotion path, because
that would create a different SHA.

- General updates to `main` are restricted.
- The only allowed update is a controlled fast-forward synchronization to the
  already-reviewed exact `origin/develop` SHA.
- That synchronization may use one narrowly scoped ruleset bypass actor
  (designated maintainer or branch-sync GitHub App/workflow) because GitHub PR
  merging cannot preserve the exact SHA under squash-only repository policy.
- There is **no unrestricted admin bypass**. The synchronization exception is
  limited to fast-forwarding `main` to the already-gated `develop` SHA.
- Required status checks remain required for the commit being synchronized.

### Rules shared by both branches

- **No force-pushes**.
- **No branch deletion**.
- Release tags are immutable and separately protected.
- Do not disable checks to make a promotion pass.

## Allowed merge/update methods

- Pull requests into `develop`: squash.
- Rebase-merge is disabled for reviewed PR integration.
- Merge commits are disabled for normal reviewed PR integration.
- `main` promotion: fast-forward synchronization only; do not use the GitHub
  merge UI to create a different commit.

## Compensating controls while live protection is absent

1. Only `main` and `develop` may persist as long-lived branches.
2. `main` and `develop` are synchronized to the exact same SHA.
3. External GitHub Actions are pinned to immutable commit SHAs.
4. Exact ZIPs are built/certified and Plugin Check runs on the package.
5. `composer release:audit` fails closed when mandatory tooling is unavailable.
6. Release tags are immutable and allowed only from the certified shared SHA.

## How to verify live state

Before a release, inspect GitHub rulesets/branch APIs for `main`, `develop`,
and `v*`. Policy text is never a substitute for live server-side enforcement.

## Companion evidence

- `docs/CI_EVIDENCE_v2.0.0.md`
- `docs/SECURITY_MATRIX_v2.0.0.md`
- `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md`
- `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md`

## Living document

This is a living document. Update it whenever branch topology, required checks,
merge/update policy, GitHub capabilities, or observed live protection changes.
