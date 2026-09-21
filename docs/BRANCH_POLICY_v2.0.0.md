# SScribe v2.x — Branch Topology Policy

This file defines the canonical branch topology for SScribe Export Site Pages.

## Why this exists

Earlier release work kept multiple long-lived integration/release branches alive at the same time. They drifted across release scripts, workflow files, documentation, and runtime code, making it difficult to prove which ref was actually shippable.

The corrected model is deliberately smaller: **main is the only canonical long-lived branch**. Review work may use temporary pull-request branches, but those branches are disposable and must be deleted after their work is merged or abandoned.

## Canonical long-lived branches

Exactly one long-lived branch is allowed:

| Branch | Role |
|---|---|
| `main` | Reviewed integration and release branch; every merged commit must be shippable. |

A feature, audit, hotfix, or release branch is transient work state, not part of the persistent topology.

## Forbidden patterns

The following states are non-canonical:

1. Any persistent long-lived branch other than `main`.
2. A completed pull-request, audit, feature, hotfix, support, or release branch left behind after merge/abandonment.
3. A local `main` that diverges from `origin/main` when cutting a release tag.
4. A release tag cut from any commit other than the certified `origin/main` HEAD.
5. Local-only release tags or recovery refs treated as authoritative repository state.

Transient review branches are allowed while their pull request is active. They must not survive the final release cleanup.

## Promotion rules

- **Day-to-day work:** create a transient branch, open a pull request to `main`, pass required checks/review, merge, then delete the transient branch.
- **Release preparation:** prepare release changes on a transient release branch and merge them to `main` through the same review path.
- **Release tag:** fetch `origin/main`, require local `main` to match it exactly, then cut the immutable annotated release tag from that SHA.
- **Hotfix:** use a transient hotfix branch, merge it to `main`, verify the merged SHA, then delete the branch.
- **History:** published `main` and release tags are never rewritten to repair drift.

## How an independent auditor verifies this

Run:

```bash
composer test:branch-policy
```

The verifier checks that:

1. this policy document exists with its canonical sections;
2. no non-main local long-lived branch is present in a normal local checkout;
3. `origin/main` exists;
4. local `main`, when present, matches `origin/main`;
5. local refs used as release evidence are mirrored by origin;
6. CI uses the authenticated `refs/remotes/origin/main` ref rather than relying on an unauthenticated late network lookup;
7. remote non-main branches are reported as transient review state in normal mode and become release-blocking in strict certification mode;
8. the remote default branch resolves to `main` in strict certification mode;
9. strict certification consumes `dist/repository-governance-evidence.json`, bound to the current source SHA, and requires the live repository snapshot to report `default_branch=main`, `remote_branches=[main]`, squash merge enabled, merge commits disabled, and rebase merge disabled.

A pull-request CI checkout may be detached and may have no local named branch. In that case the authenticated remote-tracking `origin/main` ref is authoritative. Final release certification must be run from a network-capable local checkout so the verifier can resolve the remote default branch and complete remote-branch cleanup checks.

The repository-settings evidence is intentionally ignored under `dist/` because GitHub settings are mutable server-side state, not source. Capture it only after the final branch cleanup/settings changes, and bind it to the exact current `git rev-parse HEAD`. The canonical JSON shape is:

```json
{
  "source_sha": "<FINAL_40_HEX_SHA>",
  "repository": "SimplixInnovations/sscribe-export-site-pages",
  "default_branch": "main",
  "remote_branches": ["main"],
  "allow_squash_merge": true,
  "allow_merge_commit": false,
  "allow_rebase_merge": false,
  "captured_at": "<ISO_8601_TIMESTAMP>"
}
```

A hand-edited snapshot is not sufficient evidence by itself; retain the underlying `gh api` output in `dist/evidence/` so an auditor can reconcile the JSON with GitHub.

## How to recover from a violation

If a transient branch still contains unique work, merge or cherry-pick that work through a reviewed pull request to `main`. After the work is reachable from `main`, delete the transient local and remote branch.

If local `main` differs from `origin/main`, stop release work and reconcile without rewriting published history. Do not force-push `main`.

## Living document

Any branch-topology change must update this document, `scripts/verify-branch-policy.php`, its PHPUnit integration test, release helpers, and workflow branch filters together.
