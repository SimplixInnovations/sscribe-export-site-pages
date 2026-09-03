# SScribe v2.0.0 — Branch Topology Policy

This file pins the canonical long-lived branch set for the SScribe
Export Site Pages plugin. Its sole purpose is to prevent the
multi-branch divergence problem we hit during the v2.0.0 release
hardening cycle (see the "Why this exists" section).

## Why this exists

During the v2.0.0 work, two long-lived branches (`develop` and
`release/2.0.0-final-hardening`) accumulated ~2600 unique commits
between them on the same critical files (`composer.json`,
`bin/release-audit.sh`, `.github/workflows/ci.yml`,
`docs/CI_COMMANDS.md`, the verifier scripts). When we finally tried
to merge them, the diff was so large that a clean three-way merge was
not practical — every shared file became a conflict candidate, and
the auditor's "are these two branches shippable in isolation?" check
became unanswerable.

The fix: **only two long-lived branches exist at any time, and they
must always point to the same SHA**. New work always lands on the
single tip; promotion is `develop` → `main` by force-push during a
release tag cut, never by parallel development.

## Canonical long-lived branches

Exactly two branches are allowed to persist between releases:

| Branch  | Role                                   | Always at SHA |
|---------|----------------------------------------|---------------|
| `main`  | Release branch — every commit is shippable | identical to `develop` between releases |
| `develop` | Integration branch — work lands here first | identical to `main` between releases |

Between releases, the two branches are aliases of the same commit. At
a release tag cut, a single force-push moves `main` to the release
SHA; `develop` continues forward from there.

## Forbidden patterns

The following are forbidden by `scripts/verify-branch-policy.php` and
must fail CI if introduced:

1. A third long-lived branch (`release/*`, `feature/*`, `hotfix/*`,
   `support/*`) checked into the repo between releases.
2. Any local-only ref that is not a remote-tracking branch
   (`refs/original/*`, orphan tags, dangling refs).
3. `main` and `develop` pointing to different SHAs between releases.
4. `main` ahead of `develop` (a release was cut but `develop` was
   not advanced past the release SHA).
5. `develop` more than one commit ahead of `main` between releases
   (would mean work landed on `develop` without being promoted to
   `main`, which violates the "all commits are shippable" guarantee
   on `main`).

## Promotion rules

- **Day-to-day work**: commit on `develop`, push to origin.
- **Release cut**: when a release tag is applied:
  1. Both branches are force-pushed to the release SHA.
  2. The release tag is cut on `origin/main`.
  3. `develop` and `main` are again at the same SHA afterwards.
- **Hotfixes**: a hotfix branch may exist transiently, but it must
  be merged into `develop` and force-pushed to `main` before being
  deleted. No hotfix branch may survive a release tag cut.

## How an independent auditor verifies this

Run, from a clean clone at any commit on `main` or `develop`:

```bash
composer test:branch-policy
```

The verifier checks:

1. **Policy doc exists** — `docs/BRANCH_POLICY_v2.0.0.md` is present.
2. **Doc declares canonical sections** — "Why this exists",
   "Canonical long-lived branches", "Forbidden patterns",
   "Promotion rules", "How an independent auditor verifies this".
3. **Only `main` and `develop` are local branches** — no other
   long-lived branch name appears in `git branch`.
4. **Both branches exist on `origin`** — `git ls-remote origin
   refs/heads/*` lists exactly `main` and `develop`.
5. **`main` and `develop` point to the same SHA** — `git rev-parse`
   reports identical SHAs for both refs locally and remotely.
6. **No local-only refs** — every local ref is mirrored on origin
   (with the trivial exception of the current branch's working state).

If any check fails, the auditor knows the repo is in a non-canonical
state and must be corrected before further work continues.

## How to recover from a violation

If the verifier fails because a third long-lived branch exists:

```bash
# 1. Identify the branch
git branch -a

# 2. Confirm the work is reachable from develop
git log --oneline <offending-branch> --not develop | head -20

# 3. If yes — merge or cherry-pick into develop before deleting
git checkout develop
git merge --no-ff <offending-branch>
git branch -D <offending-branch>
git push origin --delete <offending-branch>

# 4. If no — the branch had unique work that is now lost; restore
#    from the reflog if the SHA is still known
git reflog | grep <offending-branch>
git update-ref refs/heads/develop <recovered-sha>
```

If the verifier fails because `main` and `develop` diverge:

```bash
# The branch with the LATEST work wins; force-update the other.
git checkout <losing-branch>
git reset --hard <winning-sha>
git push --force-with-lease origin <losing-branch>
```

## Living document

This file is part of the v2.0.0 release hardening audit. Any change
to the branch topology rules must:

1. Update `docs/BRANCH_POLICY_v2.0.0.md`.
2. Update `scripts/verify-branch-policy.php` to enforce the new rule.
3. Add a PHPUnit integration test in
   `tests/Integration/SScribe_Branch_Policy_Test.php`.
4. Document the change in a commit message.
