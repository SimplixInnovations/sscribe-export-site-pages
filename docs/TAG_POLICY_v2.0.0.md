# Tag Policy — v2.0.0

## Why this exists

Phase 54 of the v2.0.0 release-hardening spec mandates that the
canonical rules for cutting, signing, and verifying release tags
be declared in a single document the release engineer, reviewer,
and CI gate can all cite. Without this contract:

- Tags drift from `SSCRIBE_VERSION` (a release at `v2.0.0` ships
  source still on `v1.9.x` — the historic incident).
- Tags land on the wrong ref (the tag points at `develop` HEAD
  instead of `main` HEAD, so the GH Release ZIP build never
  matches what was reviewed).
- Tags are unsigned (no `git tag -v v2.0.0` proof, so the WP.org
  reviewer cannot verify the GitHub Release integrity).

The companion verifier `scripts/verify-tag-policy.php` walks
the rules below; the companion PHPUnit test
`tests/Integration/SScribe_Tag_Policy_Test.php` pins the same
contract at the PHPUnit boundary.

## Canonical rules

Every release tag MUST obey every rule below. A violation at any
rule blocks the release.

| #  | Rule                                                                            | Enforced by                                  |
|----|---------------------------------------------------------------------------------|----------------------------------------------|
| 1  | The tag name equals `SSCRIBE_VERSION` exactly (e.g. `v2.0.0`).                 | Phase 16 + Phase 54 tag-policy verifier.      |
| 2  | The tag points at `origin/main` HEAD (not `develop`, not a feature branch).      | Phase 54 tag-policy verifier.                |
| 3  | The tag is an annotated tag (created via `git tag -a`, not lightweight `-d`-able).| Phase 54 tag-policy verifier.                |
| 4  | The tag is signed via `gh release create v{VERSION} --verify-tag` (or `git tag -s`). | Phase 54 tag-policy verifier.             |
| 5  | The local main ref equals the remote `origin/main` at tag-cut time (no drift).  | Phase 77 branch topology + Phase 54.         |
| 6  | No tag re-cut: once `v{VERSION}` exists it cannot be overwritten (`force-with-lease` banned on mainline tags). | Phase 54 tag-policy verifier.    |

The verifier asserts all 6 rules are declared and each declares
the enforcing Phase gate in its row.

## Why "origin/main HEAD" specifically

The release artifact (`dist/sscribe-export-site-pages-{VERSION}.zip`)
is built from `origin/main` HEAD by `bin/release-audit.sh` →
`scripts/build-release.php`. The tag and the artifact MUST point
at the same source SHA, otherwise the ZIP the reviewer audits
differs from the source the WP.org reviewer clones. The branch
topology policy (Phase 77) guarantees `main` and `develop` are
in lock-step, so an `origin/main` HEAD tag is by construction
the same source the develop branch reviewed.

If `main` and `develop` were permitted to diverge, the tag-policy
would have to choose which one was the authoritative source,
and the auditors would have to verify both. The Phase 77 branch
topology invariant keeps it simple: tag = origin/main HEAD is
sufficient and unique.

## How an independent auditor verifies this

```bash
# 1. Resolve the canonical version (single source of truth).
VERSION=$(grep -E "^Version:" sscribe-export-site-pages.php | awk '{print $2}')

# 2. Confirm the tag exists and points at origin/main HEAD.
git tag -l --sort=-v:refname | head -1            # must be "v${VERSION}"
git rev-parse "v${VERSION}"                       # must equal origin/main HEAD
git rev-parse origin/main

# 3. Confirm the tag is annotated + signed.
git cat-file -t "v${VERSION}"                    # must be "tag" (annotated)
git tag -v "v${VERSION}"                         # must verify

# 4. Re-run the verifier.
composer test:tag-policy
```

A green `composer test:tag-policy` + a verified `git tag -v`
+ matching `origin/main` SHA = the release tag obeys the
canonical policy.

## What this contract does NOT cover

- **Branch topology** — Phase 77 separately enforces only
  `main` + `develop` are long-lived.
- **Artifact evidence** — Phase 72 separately records the
  ZIP SHA-256, byte size, and file count.
- **CI state** — Phase 71 separately verifies every required
  job is SUCCESS on the final SHA.

## Change log

- 2026-09-03: Initial Phase 54 tag-policy contract + verifier
  + PHPUnit pin. 6 canonical rules recorded (tag-name, main-HEAD,
  annotated, signed, no-drift, no-re-cut).
