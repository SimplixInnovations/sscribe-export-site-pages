# Agent Final Report Format — v2.0.0

## Why this exists

Phase 73 of the v2.0.0 release-hardening spec mandates that the
canonical "is the release ready?" report produced by the release
agent MUST follow a fixed, auditable format. A reviewer (human or
CI) must be able to look at any final report and know:

1. **What** is being released (version + source SHA + tag).
2. **Evidence** the release is shippable (blocker status, CI
   state, artifact evidence).
3. **Open items** the reviewer should know about (DEFERRED
   blockers, accepted risks).
4. **How to verify** the claims in the report.

A free-form final report drifts over time and becomes un-auditable.
The companion verifier `scripts/verify-agent-final-report.php`
walks the doc and asserts the canonical sections exist.

## Canonical sections

Every final report produced for a v2.0.0 release MUST declare, in
order, these sections:

| #  | Section                  | What it contains                                                                       |
|----|--------------------------|----------------------------------------------------------------------------------------|
| 1  | `## Executive summary`   | One-paragraph "this release ships" + version + tag + source SHA.                        |
| 2  | `## Release evidence`    | Bulleted list: SHA-256, byte size, file count, source SHA, builder run ID, plugin check URL. |
| 3  | `## Blocker status`      | Bulleted list of every Phase 70 blocker with its current status + evidence pointer.    |
| 4  | `## CI state`            | Bulleted list of every Phase 71 required job + its current status on the final SHA.    |
| 5  | `## Open items`          | Bulleted list of DEFERRED blockers + accepted risks with rationale + target version.   |
| 6  | `## Verification recipe` | Exact commands an independent auditor can run to verify every claim above.             |

The verifier asserts all 6 sections exist. A report missing any
section fails the gate.

## Format rules

In addition to the canonical sections, the report MUST obey:

1. **Plain text only** — no HTML, no Markdown images, no embedded
   binary. The report is meant to be parsed by humans and CI.
2. **Single-h1 title** — the report title is the only `# `
   heading; everything else uses `## ` for sections.
3. **All bullets use `-`** — no `*` or `+` for bullet items.
4. **Every SHA-256 is 64 lowercase hex chars** — auditor verifies
   with `sha256sum`.
5. **Every commit SHA is 40 lowercase hex chars** — auditor
   verifies with `git rev-parse`.
6. **Version matches SSCRIBE_VERSION** — auditor verifies with
   the mainfile `Version:` header.
7. **The report is dated** — an ISO 8601 timestamp is at the top
   of the report.
8. **The report cites `docs/` paths only** — no external URLs
   for evidence (URLs for artifact hosting / Plugin Check
   action are the only exceptions).

The verifier asserts rules 1–7. Rule 8 is a human-review check
recorded in the audit trail.

## How an independent auditor verifies this

```bash
# 1. Run the agent-final-report verifier on the proposed
#    release report (typically
#    docs/RELEASE_REPORT_v2.0.0.md).
composer test:agent-final-report

# 2. Cross-check the SHA-256 against the actual ZIP.
sha256sum dist/sscribe-export-site-pages-{VERSION}.zip
# Must match the `## Release evidence` row exactly.

# 3. Cross-check the source SHA against the live tag.
git rev-parse HEAD
# Must match the `## Release evidence` row exactly.
```

A green `composer test:agent-final-report` + matching SHA +
matching source commit + every Phase 70 blocker accounted for
= the release is ready to tag (subject to Phases 74-76).

## What this contract does NOT cover

- **Reviewer approval workflow** — Phase 74 separately defines
  the auditor handoff.
- **Release invariants** — Phase 75 separately codifies the
  invariants that must NEVER drift.
- **Definition of done** — Phase 76 separately defines the
  canonical "shipped" state.

## Change log

- 2026-09-03: Initial Phase 73 agent-final-report contract +
  verifier + PHPUnit pin. 6 canonical sections (Executive
  summary, Release evidence, Blocker status, CI state, Open
  items, Verification recipe). 7 format rules. Report example
  at docs/RELEASE_REPORT_v2.0.0.md.
