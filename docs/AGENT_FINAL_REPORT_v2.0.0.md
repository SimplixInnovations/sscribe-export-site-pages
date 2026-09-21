# Agent Final Report Contract — v2.x

## Why this exists

The final release report must describe the **current exact release candidate**,
not an older closeout and not a tracked document containing stale SHA values.

The repository therefore separates three things:

- `docs/AGENT_FINAL_REPORT_v2.0.0.md` — this governance contract.
- `docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md` — tracked, non-authoritative
  structure/template with placeholders.
- `dist/final-release-report.md` — ignored, generated strict proof bound to the
  current git HEAD and the current exact ZIP.

`docs/RELEASE_REPORT_v2.0.0.md` is preserved only as a historical v2.0.0
closeout. It is not current release evidence and must not satisfy the auditor
handoff for a later release.

Normal source CI validates this contract and the tracked template. Strict
release certification is enabled with
`SSCRIBE_RELEASE_CERTIFICATION=1`; strict mode requires the Phase 70, 71, and
72 manifests to be release-ready for the same current source SHA, then generates
and validates `dist/final-release-report.md`.

## Canonical sections

Every generated final report must contain, in order:

| # | Section | Required content |
|---|---|---|
| 1 | `## Executive summary` | Current version, planned immutable tag, exact source SHA, and release-readiness statement. |
| 2 | `## Release evidence` | Exact ZIP path, SHA-256, byte size, file count, source SHA, builder evidence, and Plugin Check evidence. |
| 3 | `## Blocker status` | Strict Phase 70 release-ready state and effective blocker resolution count. |
| 4 | `## CI state` | Every Phase 71 required signal with concrete SUCCESS/LOCAL_PASS evidence. |
| 5 | `## Open items` | Any remaining release blocker, or an explicit statement that canonical strict gates have none. |
| 6 | `## Verification recipe` | Exact commands an independent auditor can run against the same checkout/artifact. |

The same six headings appear in the tracked template so format drift is caught
before strict certification.

## Format rules

1. The generated report has one H1 title and the six canonical H2 sections.
2. It contains the current semantic version resolved from strict Phase 72
   evidence.
3. It contains the exact 40-hex current source SHA.
4. It contains the exact 64-hex ZIP SHA-256.
5. It contains no `PENDING`, `TBD`, `UNAVAILABLE`, or `MISSING`
   placeholder state.
6. Release evidence is derived from generated strict manifests, not copied into
   tracked source.
7. The tracked template is never represented as final release proof.
8. The historical v2.0.0 report remains explicitly historical.
9. Repository-administration and post-certification publication actions are
   reported separately from canonical strict release blockers.

## Strict evidence inputs

`scripts/verify-agent-final-report.php` reads these generated manifests in
strict mode:

- `dist/release-blockers-manifest.json`
- `dist/final-ci-state-manifest.json`
- `dist/exact-artifact-evidence-manifest.json`

All three must report release-ready state for the same current `git HEAD`.
Only then does the verifier write `dist/final-release-report.md` and
`dist/agent-final-report-manifest.json`.

This avoids tracked-source SHA circularity: the final report is generated after
the source identity is fixed and is ignored by Git.

## How an independent auditor verifies this

```bash
# Source-contract validation only.
composer test:agent-final-report

# Strict exact-SHA prerequisites and report generation.
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:agent-final-report

# Identity spot checks.
git rev-parse HEAD
sha256sum dist/sscribe-export-site-pages-{VERSION}.zip
grep -nE "^(# |## |Generated:|- Source SHA:|- ZIP SHA-256:)" dist/final-release-report.md
```

Then inspect `dist/agent-final-report-manifest.json`. In strict mode it must
record `release_ready: true`, zero errors, and
`final_report_generated: true`.

A tracked historical closeout or template is never sufficient evidence for
tagging.

## What this contract does NOT cover

- Phase 70 defines the blocker registry and strict blocker closure.
- Phase 71 defines exact-SHA execution/CI evidence.
- Phase 72 defines exact artifact identity.
- Phase 74 defines the auditor handoff.
- Phase 75 defines invariants that must not drift.
- Phase 76 defines the overall Definition of Done.

## Change log

- 2026-09-03: Initial Phase 73 report-format contract.
- 2026-09-20: Separated the historical v2.0.0 closeout from the current report
  template, moved exact-SHA final proof to ignored
  `dist/final-release-report.md`, and made strict report generation depend on
  release-ready Phase 70/71/72 manifests.
