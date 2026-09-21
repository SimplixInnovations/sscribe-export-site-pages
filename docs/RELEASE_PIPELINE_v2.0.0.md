# Release Pipeline Contract — v2.x

## Why this exists

This document defines the release path for SScribe Export Site Pages. The
critical invariant is that reviewed source, the certified ZIP, the release tag,
and the published GitHub artifact remain bound to one source SHA.

The release flow separates source integration, tag creation, artifact
certification, and publication. Publication consumes the exact artifact created
by certification; it never rebuilds the plugin.

## Canonical pipeline

Every public release follows this sequence.

### 0. Reviewed source reaches `main`

Release changes are prepared on a transient branch, reviewed through a pull
request, and squash-merged into `main`. The transient branch is deleted after
merge.

The release engineer then reconciles local state:

```bash
git switch main
git pull --ff-only origin main
```

`main` is the only canonical long-lived branch.

### 1. Certify the merged source before tagging

Run the release gates against the exact merged `main` checkout:

```bash
composer release:audit
```

Strict final-release evidence is produced only after the source tree is final
and clean. The Phase 70/71/72 contracts bind that evidence to the exact source
SHA and release artifact.

### 2. Create the immutable tag through the guarded helper

After the reviewed `main` SHA is certified, create the tag with:

```bash
composer release:tag
```

The helper refuses a dirty tree, a non-`main` checkout, divergence from
`origin/main`, or an already-existing remote tag. New release tags from
v2.0.3 onward are annotated. Cryptographic tag signing is recommended when the
maintainer has signing configured, but it is not a WordPress.org requirement.

Tag creation is deliberately outside GitHub Actions. The tag push starts
`.github/workflows/release.yml`.

### 3. GitHub release workflow: verify → audit → test → certify → publish

The tag-triggered workflow has five sequential jobs:

1. **verify** — checks version synchronization and proves the tag points at the
   current `origin/main` HEAD.
2. **audit** — runs the locked Composer security audit.
3. **test** — installs the locked toolchain, generates the prefixed vendor tree,
   and runs PHPUnit, PHPStan, and PHPCS.
4. **certify** — builds the exact WordPress.org ZIP, records source SHA and ZIP
   SHA-256 evidence, runs the official WordPress Plugin Check, and uploads the
   ZIP, sidecar, and build metadata as immutable workflow artifacts.
5. **publish** — downloads only those certified artifacts, independently
   verifies the ZIP SHA-256, publishes them to the existing tag, then downloads
   the published files back and re-verifies their identity and package
   structure.

The `publish` job intentionally has no source checkout and does not run
`composer install`, `composer vendor:prefix`, or
`scripts/build-release.php`. Its `gh release` commands receive
`GH_REPO: ${{ github.repository }}` explicitly because no local Git remote is
available in that job.

## Forbidden shapes

The following are release blockers:

- **Direct release commits to `main`** — prepared changes must enter through a
  reviewed pull request.
- **Tagging before merged-source certification** — a tag must be cut only from
  the certified `origin/main` HEAD.
- **Tag creation in CI** — GitHub Actions consumes an existing tag; it never
  creates or force-moves one.
- **Build before source tests** — a release artifact must not be treated as
  certified before the source gates pass.
- **Publishing without official Plugin Check** — the exact ZIP must pass the
  official WordPress Plugin Check in the certify job.
- **Certify/publish collapse** — publication must consume a separately uploaded
  certified artifact rather than bytes created in the publish job.
- **Rebuild during publish** — publication must not run Composer installation,
  vendor prefixing, or the release builder.
- **Checksum bypass** — publication stops if the downloaded artifact differs
  from the certified SHA-256 sidecar.
- **Tag bypass or re-cut** — the tag must match the certified
  `origin/main` SHA and existing release tags are immutable.

## Why build-after-test and certify-before-publish matter

The source test stage proves the reviewed source is acceptable. The certify
stage then builds and evaluates the exact bytes intended for distribution.
Separating publish from certify prevents a later dependency install or rebuild
from changing those bytes after approval.

The resulting identity chain is:

```text
reviewed origin/main SHA
        =
release tag target
        =
certify build source_sha
        ->
certified ZIP SHA-256
        =
published/downloaded-back ZIP SHA-256
```

Any broken equality or failed transition blocks release.

## How an independent auditor verifies this

Inspect and run the canonical implementation, not the thin compatibility
wrapper alone:

```bash
# Source-side release audit.
composer release:audit

# Pipeline architecture contract.
composer test:release-pipeline

# Tag contract.
composer test:tag-policy

# Current implementation of the tag-triggered publish flow.
git diff --check
grep -nE "^(    (verify|audit|test|certify|publish):|.*GH_REPO:|.*gh release|.*build-release\.php)" .github/workflows/release.yml

# Canonical audit implementation; bin/release-audit.sh is only a wrapper.
grep -nE "Release-Blockers|Final-CI-State|Exact-Artifact-Evidence|Plugin-Check" scripts/release-audit.php
```

Before tagging, also verify that local `main` equals `origin/main` and that
strict release evidence references that same SHA. After the tag-triggered
workflow finishes, inspect its certify and publish jobs and compare the
published ZIP SHA-256 with the certified sidecar.

A release is publishable only when the source gates, exact-artifact
certification, tag binding, and tag-triggered workflow are all green.

## What this contract does NOT cover

- **Versioning** — version synchronization is defined by the version contract
  and `SSCRIBE_VERSION`.
- **Branch topology** — `docs/BRANCH_POLICY_v2.0.0.md` defines `main` as the
  only persistent branch.
- **Branch protection** — `docs/BRANCH_PROTECTION_v2.0.0.md` defines desired
  server-side repository governance.
- **Tag details** — `docs/TAG_POLICY_v2.0.0.md` is authoritative for tag
  shape, immutability, SHA binding, and the advisory cryptographic-signing
  policy.
- **Final execution evidence** — Phase 71 records exact-SHA CI/local execution
  evidence outside tracked source.
- **Exact artifact evidence** — Phase 72 records the final ZIP SHA-256, size,
  and file count.

## Change log

- 2026-09-03: Initial Phase 53 certify/publish split.
- 2026-09-20: Reconciled the contract with the main-only branch model,
  maintainer-created guarded tags, the five-job tag-triggered workflow, the
  no-rebuild publish job, and explicit `GH_REPO` context.
