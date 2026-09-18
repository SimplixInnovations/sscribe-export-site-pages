# Tag Policy — v2.x (canonical contract)

## Why this exists

Phase 54 of the release-hardening spec mandates that the canonical
rules for cutting, pointing, and verifying release tags be declared
in a single document the release engineer, reviewer, and CI gate can
all cite. Without this contract:

- Tags drift from `SSCRIBE_VERSION` (a release at `v2.0.0` ships
  source still on `v1.9.x` — the historic incident).
- Tags land on the wrong ref (the tag points at `develop` HEAD
  instead of `main` HEAD, so the GH Release ZIP build never matches
  what was reviewed).
- Tags are absent or lightweight (no proof that the released commit
  is exactly the commit a downstream auditor sees).
- Tags are force-moved or re-cut (rewrites shipped history).

The companion verifier `scripts/verify-tag-policy.php` walks the
rules below; the companion PHPUnit test
`tests/Integration/SScribe_Tag_Policy_Test.php` pins the same
contract at the PHPUnit boundary.

## Canonical rules

Every release tag MUST obey every rule below. A violation at any
rule blocks the release.

| #  | Rule                                                                              | Enforced by                       |
|----|-----------------------------------------------------------------------------------|-----------------------------------|
| 1  | The tag name equals `SSCRIBE_VERSION` exactly (e.g. `v2.0.1`).                    | Phase 16 + Phase 54 verifier.     |
| 2  | New release tags from v2.0.3 onward are annotated tags (`git tag -a` or `git tag -s`), not lightweight tags. Historical v2.0.2-and-earlier tags are preserved exactly as originally published. | Phase 54 verifier. |
| 3  | The tag points exactly at `origin/main` HEAD at certification time.               | Phase 54 verifier + Phase 77.     |
| 4  | The tag points at the exact certified source SHA (the SHA recorded in the build evidence). | Phase 54 verifier + Phase 72. |
| 5  | The local `main` ref equals the remote `origin/main` at tag-cut time (no drift).  | Phase 77 + Phase 54.              |
| 6  | No tag re-cut: once `v{VERSION}` exists it cannot be overwritten (`--force-with-lease` / `--force` banned on mainline release tags). | Phase 54 verifier. |
| 7  | The release workflow consumes tags but does NOT create them — tag-cutting is a deliberate maintainer action outside CI. | Phase 54 verifier. |
| 8  | When publishing the GitHub Release, `gh release create` uses `--verify-tag` so publication aborts if the named remote tag does not already exist. | Phase 54 verifier. |

The verifier asserts all 8 rules are declared and enforces annotated tag objects prospectively when a v2.0.3-or-later tag exists.

## Rule 4 — exact certified source SHA

The tag must point at the exact source SHA that produced the certified
ZIP. Concretely, after `composer release` writes
`dist/release-certification-evidence.json` with `source_sha`, the tag
must be cut at that same SHA. The Phase 72 strict gate (exact-artifact
evidence) cross-checks this binding: if `git rev-parse v{VERSION}` does
not equal the `source_sha` in the build evidence, Phase 72 fails.

## Rule 8 — `gh release create --verify-tag` verifies remote tag existence

`--verify-tag` makes `gh release create` abort if the named tag does not already exist in the remote repository. It does not cryptographically sign the tag, create an attestation, or by itself prove that the tag object is annotated.

The release pipeline separately binds the release to the certified source SHA by checking the tag SHA against `origin/main` and `dist/release-certification-evidence.json`.

## Historical lightweight-tag exception

The published `v2.0.2` tag and any earlier historical tags are immutable and must not be moved, deleted, or recreated merely to change tag-object type. The annotated-tag requirement is enforced prospectively for `v2.0.3` and later.

## Cryptographic signing of tags — recommended, not required

WordPress.org does not require a GPG- or SSH-signed Git tag for plugin submission. Repository provenance is established by the maintainer-controlled source repository, immutable release-tag policy, certified source SHA, and exact-artifact evidence. `gh release create --verify-tag` only requires that the named remote tag already exist; it is not a cryptographic provenance mechanism.

**Recommendation (not requirement):** When the maintainer has
GPG/SSH signing configured (`git config --get user.signingkey` returns
non-empty, or `~/.gitconfig` declares `gpg.format = ssh`), the
release tag should additionally be created with `git tag -s` so the
tag object carries an explicit cryptographic signature verifiable
via `git tag -v v{VERSION}`. The verifier records whether
cryptographic signing was detected and surfaces it as advisory
information; absence is not a release-stop.

If the maintainer organization deliberately chooses mandatory
cryptographic signing as a policy, then implement it properly:

```bash
git tag -s v2.0.1 -m "SScribe 2.0.1"
git tag -v v2.0.1    # must verify with no `gpg: BAD signature`
```

If cryptographic signing is not configured, do not turn that into a
release-stop blocker. WP.org submission does not require it.

## Why "origin/main HEAD" specifically

The release artifact (`dist/sscribe-export-site-pages-{VERSION}.zip`) is built from the certified shared `origin/main == origin/develop` SHA by `scripts/build-release.php`. `composer release:audit` verifies the already-built artifact; it does not build it. The tag and artifact MUST resolve to the same certified source SHA, otherwise the ZIP the reviewer audits differs from the source the WP.org reviewer clones. The branch topology
policy (Phase 77) guarantees `main` and `develop` are in lock-step,
so an `origin/main` HEAD tag is by construction the same source the
develop branch reviewed.

If `main` and `develop` were permitted to diverge, the tag-policy
would have to choose which one was the authoritative source, and the
auditors would have to verify both. The Phase 77 branch topology
invariant keeps it simple: tag = origin/main HEAD is sufficient and
unique.

## How an independent auditor verifies this

```bash
# 1. Resolve the canonical version (single source of truth).
VERSION=$(grep -E "^Version:" sscribe-export-site-pages.php | awk '{print $2}')

# 2. Confirm the tag exists and points at origin/main HEAD.
git tag -l --sort=-v:refname | head -1            # must be "v${VERSION}"
git rev-parse "v${VERSION}"                       # must equal origin/main HEAD
git rev-parse origin/main

# 3. Confirm the tag points at the certified source SHA recorded in dist/release-certification-evidence.json.
CERT_SHA=$(jq -r .source_sha dist/release-certification-evidence.json)
test "$(git rev-parse v${VERSION})" = "$CERT_SHA"

# 4. For v2.0.3 and later, confirm the tag is annotated.
git cat-file -t "v${VERSION}"                    # must be "tag" for v2.0.3+

# 5. Attempt cryptographic verification if the maintainer has signing configured.
#    A non-zero exit means signing is not configured; that is acceptable.
git tag -v "v${VERSION}" || echo "tag not cryptographically signed — advisory only"

# 6. Re-run the verifier.
composer test:tag-policy
```

A green `composer test:tag-policy` + matching `origin/main` SHA +
matching certified source SHA = the release tag obeys the canonical
policy.

## What this contract does NOT cover

- **Branch topology** — Phase 77 separately enforces only `main` +
  `develop` are long-lived.
- **Artifact evidence** — Phase 72 separately records the ZIP
  SHA-256, byte size, and file count.
- **CI state** — Phase 71 separately verifies every required job is
  SUCCESS or LOCAL_PASS on the final SHA.

## Change log

- 2026-09-03: Initial Phase 54 tag-policy contract + verifier +
  PHPUnit pin.
- 2026-09-04: Rule count grew from 6 to 8: split "tag on main" into rules #3 (origin/main HEAD) and #4 (certified source SHA); split "no tag re-cut" (#6) from "CI does not cut tags" (#7). The historical wording of rule #8 was corrected on 2026-09-18.
- 2026-09-05: Added a section clarifying cryptographic signing is recommended (not required).
- 2026-09-18: Corrected `--verify-tag` semantics, added prospective annotated-tag enforcement for v2.0.3+, and preserved v2.0.2-and-earlier historical tag objects unchanged.
