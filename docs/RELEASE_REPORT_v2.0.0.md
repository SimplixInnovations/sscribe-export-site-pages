# SScribe v2.0.0 — Historical Closeout Report

**Status:** HISTORICAL. The `v2.0.0` tag was annotated but unsigned and
points at commit `408202b62a27c413701050ad5fb6a73cc3c8f1f9` (the
deterministic-build hardening commit). The release was preserved but
demoted from public ship. **The corrected public release target is
v2.0.1.**

**Why this report contains no live source SHA:** A tracked report
cannot authoritatively contain its own resulting Git SHA — that would
require another commit to update, in a loop. All authoritative final
identities for any release ship in ignored `dist/` evidence. See
`dist/final-execution-evidence.json` and
`dist/release-certification-evidence.json` for the live source SHA /
ZIP SHA-256 / build timestamp of any certified release.

## Historical summary

When the v2.0.0 closeout was performed:

- The deterministic release ZIP was built twice and produced an
  identical SHA-256 of
  `250948f3dc2482deb68c13c5f830c0c99d36f661f5c958e8e80a5f1daa058cee`
  (9507342 bytes, 1051 entries). That ZIP identity is **historical** —
  the 2.0.1 release will rebuild and ship a new ZIP from the same
  deterministic builder.
- The PHPUnit suite was green at 1457 / 6092 / 0 / 28 across multiple
  `--order-by=random` seeds.
- 20 canonical Phase 70 release blockers were tracked in
  `docs/RELEASE_BLOCKERS_v2.0.0.md`; 12 were RESOLVED by code/test
  fixes and 8 were DEFERRED pending local final-certification evidence
  (CI availability, coverage driver, WP-Playground runtime, official
  Plugin Check on the exact ZIP, clean-install of the exact ZIP,
  runtime-export of the exact ZIP, source/build transparency).

## Why v2.0.0 did not ship

Two structural blockers converged at the v2.0.0 closeout:

1. **Unsigned annotated tag.** The existing `v2.0.0` tag was
   annotated (`git cat-file -t v2.0.0` → `tag`) but unsigned
   (`git tag -v v2.0.0` → "no signature found"). Per the original
   tag-policy rule #4 this was a release-stop.
2. **Strict-certification gates not all green.** Phase 70 and Phase 71
   strict gates remained DEFERRED / UNAVAILABLE in the local sandbox
   due to missing CI signal source, missing coverage driver, and
   missing WP-Playground runtime.

## Resolution path actually taken

The repository's release architecture was then refactored so the
remaining blockers can close without re-committing tracked files
(no SHA circularity):

- Phase 70 dynamic blockers (#1, #2, #4, #15, #16, #17, #18) now
  resolve from ignored `dist/` evidence rather than requiring a
  commit to flip `DEFERRED → RESOLVED` after each certification run.
  See `docs/RELEASE_BLOCKERS_v2.0.0.md` "Closure source" column.
- Tag policy §5 (`docs/TAG_POLICY_v2.0.0.md`) corrects the previous
  conflation of `gh release create --verify-tag` with cryptographic
  signing. `--verify-tag` is SHA-binding via GitHub's signed-tag
  store; cryptographic `git tag -s` is **recommended** when the
  maintainer has signing configured, not required.
- The real public release target moved from v2.0.0 to **v2.0.1**:
  version constant in `sscribe-export-site-pages.php`, `readme.txt`
  Stable tag, and `package.json` were updated to `2.0.1`; the
  historical `v2.0.0` tag is preserved.

## What remains historical in this report

- All ZIP / SHA / size / entry-count numbers above describe the
  v2.0.0 build, not v2.0.1.
- The source SHAs in the "v2.0.0" historical closeout no longer
  represent current `main` HEAD. They are intentionally retained
  as evidence that v2.0.0 was built and tested at that time, then
  preserved rather than force-shipped.

## Where the live identity of v2.0.1 lives

The authoritative v2.0.1 identities (source SHA, ZIP SHA-256, ZIP byte
size, ZIP entry count, build timestamp, strict-gate results) are
recorded in **ignored, regenerated** files:

- `dist/final-execution-evidence.json`
- `dist/release-certification-evidence.json`
- `dist/runtime-export-evidence.json`
- `dist/source-transparency-evidence.json` (when authorized / live)
- `dist/clean-install-evidence.json`

Tracked documents never need a commit to align with these values
because they do not embed them.

## See also

- [`docs/RELEASE_BLOCKERS_v2.0.0.md`](RELEASE_BLOCKERS_v2.0.0.md) — the
  canonical release-blocker registry. Now architecture-versioned so a
  certification run can close dynamic blockers without re-committing
  this file.
- [`docs/TAG_POLICY_v2.0.0.md`](TAG_POLICY_v2.0.0.md) — corrected tag
  policy (rule #4 no longer conflates `--verify-tag` with crypto
  signing).
- [`docs/DEFINITION_OF_DONE_v2.0.0.md`](DEFINITION_OF_DONE_v2.0.0.md) —
  release Definition of Done fingerprint.
- [`docs/CI_COMMANDS.md`](CI_COMMANDS.md) — every local gate.
