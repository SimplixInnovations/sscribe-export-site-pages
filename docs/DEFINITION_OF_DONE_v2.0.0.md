# Definition of Done — governance schema v2.0.0

> **Scope:** this filename is retained as the Phase 76 governance-schema identifier. The release under certification is always the version declared by `SSCRIBE_VERSION`, not necessarily plugin version 2.0.0.

## Why this exists

Phase 76 of the v2.0.0 release-hardening spec mandates that the
canonical "is the current release done?" state be declared in a single
document every release engineer + reviewer + CI gate can cite.
Without a canonical Definition of Done:

- "Done" is interpreted differently by different people.
- A partial green CI is mistaken for a release-ready build.
- A DEFERRED blocker is glossed over because nobody tracked it.

The companion verifier `scripts/verify-definition-of-done.php`
walks this checklist and asserts every Definition of Done
criterion is satisfied.

## Canonical Definition of Done

The current `SSCRIBE_VERSION` release is **SHIPPED** only when ALL of the following are true. Every
criterion references the Phase gate that enforces it.

| #  | Criterion                                                              | Enforced by                                | Status required |
|----|------------------------------------------------------------------------|--------------------------------------------|-----------------|
| 1  | All Phases 16-77 are complete + green on the final SHA.                | Phases 16-77 verifiers + PHPUnit suite.    | green           |
| 2  | SSCRIBE_VERSION equals mainfile `Version:` + readme.txt Stable tag + package.json + composer.json constraint. | Phase 16 verifier.            | green           |
| 3  | Every required execution signal is SUCCESS or documented LOCAL_PASS on the exact final SHA. | Phase 71 final execution state. | green |
| 4  | Every Phase 70 blocker is RESOLVED; no DEFERRED blocker remains.       | Phase 70 release blockers.                 | green           |
| 5  | dist/sscribe-export-site-pages-{VERSION}.zip exists with matching `.sha256` sidecar. | Phase 63 + Phase 72 verifier. | green           |
| 6  | Plugin Check on the exact ZIP is PASS.                                 | Phase 33 plugin-check job.                 | PASS            |
| 7  | Real-WordPress matrix is green on every PHP × WP leg.                  | Phase 27 + Phase 56 real-wp-tests matrix.  | green           |
| 8  | Coverage thresholds met (project ≥ 70%, security-critical ≥ 90%).      | Phase 41 coverage threshold gate.          | green           |
| 9  | Composer security audit is clean (no HIGH/CRITICAL CVE).               | Phase 32 audit job.                        | green           |
| 10 | NPM audit is clean (no HIGH/CRITICAL CVE).                             | Phase 32 frontend-quality job.             | green           |
| 11 | AI-artifact scan finds zero AI markers (em-dash, en-dash, LLM phrasings).| Phase 32 audit job.                       | green           |
| 12 | Branch protection rules documented in `docs/BRANCH_PROTECTION_v2.0.0.md` match GitHub UI.| Phase 55 branch protection.| green           |
| 13 | Tag policy contract holds: tag equals SSCRIBE_VERSION, points at origin/main HEAD, is annotated for v2.0.3+, and `gh release create --verify-tag` verifies the remote tag exists; cryptographic tag signing is recommended, not required. | Phase 54 tag policy. | green |
| 14 | Release pipeline contract holds: build-after-test, Plugin Check after build, certify-then-publish split.| Phase 53 release pipeline + Phase 62 build order.| green |
| 15 | Acceptance matrix ≥ 25 cells, every cell's `ci_command` + `ci_step` is wired. | Phase 58 acceptance matrix.        | green           |
| 16 | Debug log redaction contract holds (sensitive keys redacted, IP HMAC-hashed, JWT redacted, length-bounded, canonical shape preserved).| Phase 59 debug log.            | green           |
| 17 | No-internal-details contract holds (no raw paths / SQL / ABSPATH / backtraces leak to user surfaces).| Phase 60 no-internal-details.| green           |
| 18 | CI command docs contract holds (every `test:*` script + ci.yml step keyword is documented).| Phase 61 CI docs.                | green           |
| 19 | Build-after-test vs test-after-build order holds (tests → build → Plugin Check). | Phase 62 build order.               | green           |
| 20 | Exact-package clean install holds (ZIP installs + activates on fresh WP).| Phase 63 + Phase 72.                       | green           |
| 21 | Plugin Check warning triage covers every warning (fixed / acknowledged / in_progress / deferred). | Phase 64 plugin check triage. | green           |
| 22 | JS error-free contract holds (no console.error, no var, strict mode, .catch matched). | Phase 65 JS error-free.       | green           |
| 23 | AJAX network trace covers every `wp_ajax_sscribe_*` action.             | Phase 66 AJAX network trace.               | green           |
| 24 | UI refactor discipline holds (admin file count locked at v1.9.0 baseline, zero renames/additions under admin/).| Phase 67 UI refactor discipline. | green |
| 25 | Phase 68 test coverage holds (all 24 required test signatures present in tests/).| Phase 68 test coverage.               | green           |
| 26 | Manual runtime tests runbook covers all 6 canonical environments.       | Phase 69 manual runtime tests.             | green           |
| 27 | Release blockers checklist complete (every blocker RESOLVED).          | Phase 70 release blockers.                 | green           |
| 28 | Final execution state holds (every required signal SUCCESS or documented LOCAL_PASS on the final SHA). | Phase 71 final execution state. | green |
| 29 | Strict exact-artifact evidence matches the actual ZIP, sidecar, and source SHA (SHA-256, byte size, file count, source SHA, builder identity, Plugin Check evidence, build timestamp). | Phase 72 exact artifact evidence. | green |
| 30 | Agent final report produced in the canonical format (6 sections, 7 format rules).| Phase 73 agent final report.            | green           |
| 31 | Auditor handoff protocol holds (13 artifacts listed, every artifact present).| Phase 74 auditor handoff.               | green           |
| 32 | Release invariants declared + enforced (33 invariants, each with enforcing Phase gate).| Phase 75 release invariants.            | green           |
| 33 | Branch topology policy holds (`main` is the only persistent long-lived branch; local main matches origin/main; no authoritative local-only refs; CI-tolerant).| Phase 77 branch topology policy.        | green           |
| 34 | Tag is cut on `origin/main` HEAD as an immutable annotated tag for v2.0.3+; cryptographic tag signing is recommended, not required. | Manual (Phase 54 gate). | green |
| 35 | WP.org submission is made via the Plugin Check action's `release-zip` artifact, with the audit-trail attached.| Manual (after Phase 33).                | submitted       |
| 36 | Public maintained exact source/build inputs are available for WordPress.org because build tooling is omitted from the deployed ZIP. | Phase 70 blocker 18 / WordPress.org source guideline. | public |

The verifier asserts all 36 criteria are declared AND each
declares the enforcing Phase in its row.

## How an independent auditor verifies this

```bash
# 1. Run the definition-of-done verifier.
composer test:definition-of-done

# 2. Run the full release-audit gate (every Phase gate).
composer release:audit

# 3. Cross-check the latest git tag against SSCRIBE_VERSION.
git tag -l --sort=-v:refname | head -1
grep '^Version:' sscribe-export-site-pages.php
# Must match.

# 4. Cross-check the WP.org submission status.
gh release view "v{VERSION}" --repo SimplixInnovations/sscribe-export-site-pages
# Must show the certified ZIP + SHA-256 sidecar.
```

A green `composer test:definition-of-done` + a green
`composer release:audit` + matching tag + submitted to WP.org
= the current release is officially SHIPPED.

## What this contract does NOT cover

- **Future governance schemas** — this document's filename remains the Phase 76 schema identifier until the governance contract itself is versioned again; release identity is resolved dynamically from `SSCRIBE_VERSION`.
- **Backports** — security patches to older versions follow
  their own short-form Definition of Done.

## Change log

- 2026-09-03: Initial Phase 76 Definition of Done contract +
  verifier + PHPUnit pin. 34 canonical criteria recorded.
  Every criterion references its enforcing Phase gate.
