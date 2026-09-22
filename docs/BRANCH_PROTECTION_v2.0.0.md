# SScribe v2.x — Branch Protection Policy & Live Status

This document defines repository governance for the single canonical long-lived branch. It is not proof of live GitHub settings; live protection must be verified through the repository API/settings.

## Target branches

```
Branches: main
Audited Date: 2026-09-21
```

## Observed live status

At the recorded repository audit GitHub reported:

```
default branch: main
rulesets: none
main: UNPROTECTED
squash merge: enabled
rebase merge: disabled
merge commits: disabled
automatic head-branch deletion: enabled
```

The default branch, persistent branch topology, and merge methods are now reconciled to the canonical `main` + squash-only policy. The remaining repository-administration gap is the absence of a server-side `main` protection rule/ruleset. Branch protection remains strongly recommended governance but is not itself a WordPress.org submission requirement; until enabled, the compensating controls below remain mandatory.

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

### `main` — reviewed integration and release branch

- Changes enter through pull requests; no ordinary direct pushes.
- At least 1 approving review is required for ordinary changes.
- Sensitive security/release paths should require 2 approving reviews through CODEOWNERS or an equivalent rule.
- Conversation resolution is required before merge.
- Required status checks must pass.
- Signed commits or DCO `--signoff` are required for release promotion.
- Squash is the project merge method for pull requests into `main`.
- Rebase-merge is disabled.
- Merge commits are disabled for normal pull-request integration.
- **No force-pushes**.
- **No branch deletion** of `main`.
- There is **no unrestricted admin bypass**. Emergency bypass, if the GitHub plan supports one, must be narrowly scoped, audited, and followed by the full release gates.

Transient feature, audit, hotfix, and release branches may exist only while active work is under review. They are deleted after merge or abandonment.

## Allowed merge/update methods

- Pull requests into `main`: squash.
- Rebase-merge: disabled.
- Merge commits: disabled for normal reviewed PR integration.
- Release tags: created only from the certified `origin/main` HEAD; tags are immutable.

## Compensating controls while live protection is absent

1. `main` is the only persistent long-lived branch.
2. Completed transient branches are removed after merge.
3. External GitHub Actions are pinned to immutable commit SHAs.
4. Exact ZIPs are built/certified and Plugin Check runs on the package.
5. `composer release:audit` fails closed when mandatory tooling is unavailable.
6. Release tags are immutable and allowed only from the certified `origin/main` SHA.
7. Release helper scripts refuse to tag from a dirty, divergent, or non-main checkout.

## How to verify live state

Before a release, inspect GitHub rulesets/branch APIs for `main` and `v*`. Policy text is never a substitute for live server-side enforcement.

## Companion evidence

- `docs/CI_EVIDENCE_v2.0.0.md`
- `docs/SECURITY_MATRIX_v2.0.0.md`
- `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md`
- `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md`

## Living document

This is a living document. Update it whenever branch topology, required checks, merge policy, GitHub capabilities, or observed live protection changes.
