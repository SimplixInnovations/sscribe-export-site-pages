# SScribe v2.0.0 — Branch Protection Policy & Live Status

This document is the repository governance policy for the two canonical
long-lived branches. It is intentionally **not** treated as proof that GitHub
has applied the server-side settings: live protection must be checked in the
repository settings/API.

## Target branches

```
Branches: main, develop
Audited Date: 2026-09-04
```

## Observed live status

At the 2026-09-04 release-hardening audit, the GitHub API reported:

```
main:    UNPROTECTED
develop: UNPROTECTED
```

This is an external repository setting, not a WordPress plugin-code defect and
not a WordPress.org submission requirement. It should be enabled when the
GitHub plan/account permits it. Until then, the release process uses pull
requests, exact-artifact certification, immutable GitHub Action pins, and the
local release audit as compensating controls.

## Desired required status checks

The following release signals map to the repository workflows and should be
configured as required checks when branch protection/rulesets are available:

| Check | Job / workflow | What it proves |
|---|---|---|
| Version Sync | `version-check` | Metadata and release contracts are internally consistent. |
| PHPUnit | `test` | Runtime/unit/integration suite passes. |
| Submission Package Check | `plugin-check` | Official WordPress Plugin Check passes on the exact ZIP. |
| Exact-package browser runtime | `e2e` workflow | Built ZIP boots and completes browser export/download flows. |
| Release audit | `release-audit` workflow | Final promotion audit passes. |

## Desired pull-request rules

- **No direct push** to `main`; promotion should occur through a pull request.
- At least **1 approving review** for ordinary changes.
- At least **2 approving reviews** for sensitive release/security files:
  - `includes/class-sscribe-activator.php`
  - `includes/class-sscribe-private-storage.php`
  - `includes/class-sscribe-security.php`
  - `scripts/build-release.php`
  - `bin/release-audit.sh`
  - `phpcs.xml`, `phpstan.neon`, and `.github/workflows/*.yml`
- Conversation resolution is required before merge.
- Signed commits / DCO `--signoff` are preferred for release promotion.
- **No force-pushes** to protected long-lived branches.
- **No branch deletion** for `main` or `develop`.
- **No admin bypass** for required release checks once protection is enabled.

## Allowed merge methods

- **Squash** is the preferred merge method for feature/release PRs.
- Rebase-merge is disabled by policy for release promotion.
- Merge commits are avoided unless repository history requires them.

## Compensating controls while GitHub protection is unavailable

1. Feature work lands through PRs rather than direct edits to `main`.
2. External GitHub Actions are pinned to immutable 40-character commit SHAs.
3. The exact versioned ZIP is built once and certified before publication.
4. Plugin Check runs against the exact submission package.
5. `bin/release-audit.sh` fails closed when mandatory tooling is unavailable.
6. Release tags are allowed only from `origin/main` HEAD.

## How to verify live state

Before tagging a release, inspect GitHub repository settings or the branch API
for both `main` and `develop`. If protection is available, enable the policy
above. Do not infer server-side enforcement from this document.

## Companion evidence

- `docs/CI_EVIDENCE_v2.0.0.md`
- `docs/SECURITY_MATRIX_v2.0.0.md`
- `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md`
- `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md`

## Living document

This is a living document. Update it whenever branch topology, required checks,
merge policy, GitHub plan capabilities, or the observed live protection state
changes.
