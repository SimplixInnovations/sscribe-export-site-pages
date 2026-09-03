# Release Pipeline Contract — v2.0.0

## Why this exists

Phase 53 of the v2.0.0 release-hardening spec mandates that the
canonical order and shape of the release pipeline be declared in
a single document the release engineer, reviewer, and CI gate
can all cite. Without this contract:

- The Pipeline order drifts (Plugin Check runs BEFORE tests, so a
  failing test ships to WP.org review).
- "Certify" and "publish" are mixed into one step (a bad ZIP
  posts to WP.org before any human sees it).
- Build tooling is re-invented per release (each release adds a
  one-off `build.sh`, and nobody knows which one is canonical).

The companion verifier `scripts/verify-release-pipeline.php`
walks the pipeline structure; the companion PHPUnit test
`tests/Integration/SScribe_Release_Pipeline_Test.php` pins the
same contract at the PHPUnit boundary.

## Canonical pipeline

Every release MUST follow this exact shape, in this exact order.
Any reorder, skip, or merge fails the gate.

```
   ┌─────────────────────────────────────────────────────────┐
   │  1. tests           (composer test, composer test:wp)   │
   │      → green required (0 failures, 0 errors)             │
   │      → coverage thresholds met                           │
   └─────────────────────────────────────────────────────────┘
                            ↓
   ┌─────────────────────────────────────────────────────────┐
   │  2. build           (scripts/build-release.php)         │
   │      → emits dist/{slug}-{version}.zip                   │
   │      → strips comments (Phase 53 release-pipeline rule) │
   │      → records SHA-256 sidecar at dist/{slug}-{version}.sha256 │
   └─────────────────────────────────────────────────────────┘
                            ↓
   ┌─────────────────────────────────────────────────────────┐
   │  3. certify         (scripts/verify-tag-policy.php,      │
   │                      scripts/verify-phase-*             │
   │                      tests, WP.org plugin-check)         │
   │      → certify-then-publish split enforced               │
   └─────────────────────────────────────────────────────────┘
                            ↓
   ┌─────────────────────────────────────────────────────────┐
   │  4. publish         (git push origin main --follow-tags  │
   │                      then gh release create v{VERSION}   │
   │                      --verify-tag --title "..." --notes  │
   │                      from readme.txt changelog)          │
   └─────────────────────────────────────────────────────────┘
```

A green `composer release:audit` (the certify step) gates the
publish step. Manual `gh release create` is banned while
`composer release:audit` reports any non-zero rule.

## Forbidden shapes

- **Test-after-build** — running tests AFTER the ZIP is built,
  so a failing test ships a `dist/{slug}-{version}.zip` that
  the build pipeline "approved". Phase 62 build-order verifier
  forbids this.
- **Pre-publish Plugin Check** — running Plugin Check on the
  ZIP before any auditor review, so the WP.org reviewer sees a
  result the maintainer can't reproduce.
- **Merge certify + publish** — collapsing the certify step
  into the publish step so a failed certification never blocks
  publication. The split MUST be 2 distinct bash invocations.
- **Bypass tags** — pushing a release commit without a tag, or
  tagging a non-`origin/main`-HEAD commit.
- **Re-zip after certification** — re-running
  `scripts/build-release.php` after a successful certification
  produces a new ZIP whose SHA-256 doesn't match the recorded
  sidecar; the publish step MUST refuse to ship a ZIP whose
  SHA-256 differs from the certifier's recorded sidecar.

## Why "build-after-test" specifically

A build that runs BEFORE tests cannot be guaranteed to reflect
green source. A failing test discovered after build either
ships a broken ZIP (the historic incident pattern) or requires
a second build (which produces a different ZIP than the
artifacts the maintainers reviewed). The order "tests → build
→ certify → publish" guarantees the build witnesses the green
state and the certification witnesses the build; the
certification is the only allow gate between the build and the
publish.

## How an independent auditor verifies this

```bash
# 1. Confirm bin/release-audit.sh runs every phase in order.
grep -nE "Tests|Build|Certify|Publish" bin/release-audit.sh

# 2. Confirm ci.yml splits certify from publish.
grep -nE "test:|build:|release-audit" .github/workflows/ci.yml

# 3. Re-run the verifier.
composer test:release-pipeline

# 4. Re-run the whole certify chain end-to-end.
composer release:audit
```

A green `composer test:release-pipeline` + a green
`composer release:audit` + the pipeline order preserved in
`bin/release-audit.sh` and `.github/workflows/ci.yml` = the
release obeys the canonical pipeline contract.

## What this contract does NOT cover

- **Versioning** — Phase 16 separately codifies that
  SSCRIBE_VERSION is the single source of truth.
- **Branch topology** — Phase 77 separately enforces the
  canonical `main` + `develop` invariant.
- **Tag policy** — Phase 54 separately codifies the canonical
  tag-shape rules (annotated, signed, origin/main HEAD).
- **Artifact evidence** — Phase 72 separately records the
  ZIP SHA-256, byte size, file count.

## Change log

- 2026-09-03: Initial Phase 53 release-pipeline contract +
  verifier + PHPUnit pin. 4 canonical pipeline stages
  (tests, build, certify, publish) + 5 forbidden shapes
  (test-after-build, pre-publish Plugin Check, certify+publish
  merge, bypass tags, re-zip after cert).
