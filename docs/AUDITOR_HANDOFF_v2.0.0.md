# Auditor Handoff Protocol — v2.0.0

## Why this exists

Phase 74 of the v2.0.0 release-hardening spec mandates that the
protocol for handing off release evidence to an independent
auditor (release engineer who did NOT cut the tag, WP.org
reviewer, security auditor) MUST be declared in a canonical
document. Without this protocol:

- The auditor doesn't know what evidence is expected.
- The auditor doesn't know what commands to run to verify.
- The release engineer doesn't know what they MUST produce.

The companion verifier `scripts/verify-auditor-handoff.php`
walks this checklist and asserts:

1. The handoff protocol doc exists.
2. The doc declares canonical sections (Why this exists,
   Canonical handoff artifacts, Verification recipe per
   artifact, How an independent auditor verifies this).
3. Every canonical handoff artifact is listed.
4. Every listed handoff artifact exists and is non-empty.
5. The exact release ZIP + matching SHA-256 sidecar pair
   resolves at the canonical `dist/{name}-{VERSION}.{ext}`
   naming convention (no `zip.sha256`, no `.sha`).
6. Integration test exists.

## Canonical handoff artifacts

Every release handoff MUST include, at minimum, these artifacts:

| #  | Artifact                                 | Path                                                                                | Phase reference                |
|----|------------------------------------------|-------------------------------------------------------------------------------------|--------------------------------|
| 1  | Release blockers checklist               | docs/RELEASE_BLOCKERS_v2.0.0.md                                                     | Phase 70                       |
| 2  | Final CI execution evidence              | docs/FINAL_CI_STATE_v2.0.0.md + dist/final-execution-evidence.json + dist/final-ci-state-manifest.json | Phase 71 |
| 3  | Exact artifact evidence                  | docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md + dist/release-certification-evidence.json + dist/exact-artifact-evidence-manifest.json | Phase 72 |
| 4  | Agent final report                       | docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md + dist/final-release-report.md + dist/agent-final-report-manifest.json | Phase 73 |
| 5  | Branch protection contract               | docs/BRANCH_PROTECTION_v2.0.0.md                                                    | Phase 55                       |
| 6  | Tag policy contract                      | docs/TAG_POLICY_v2.0.0.md                                                           | Phase 54                       |
| 7  | Release pipeline contract                | docs/RELEASE_PIPELINE_v2.0.0.md                                                     | Phase 53                       |
| 8  | Acceptance matrix                        | docs/ACCEPTANCE_MATRIX_v2.0.0.json + dist/acceptance-matrix-manifest.json            | Phase 58                       |
| 9  | Build transparency doc                   | docs/BUILD_TRANSFORMATIONS.md                                                       | Phase 36                       |
| 10 | Third-party license inventory            | docs/SECURITY_MATRIX_v2.0.0.md                                                      | Phase 37                       |
| 11 | Plugin Check triage                      | docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md                                                | Phase 64                       |
| 12 | Manual runtime tests runbook             | docs/MANUAL_RUNTIME_TESTS_v2.0.0.md + docs/CI_EVIDENCE_v2.0.0.md                    | Phase 69                       |
| 13 | Exact release ZIP + SHA-256 sidecar     | dist/sscribe-export-site-pages-{VERSION}.zip + dist/sscribe-export-site-pages-{VERSION}.sha256 | Phase 63 + 72     |
| 14 | Branch topology policy + manifest        | docs/BRANCH_POLICY_v2.0.0.md + dist/branch-policy-manifest.json                      | Phase 77                       |

The handoff MUST include every artifact above. A missing artifact
fails the gate.

## Verification recipe per artifact

For each handoff artifact, the auditor runs a specific command
to verify the claim. The canonical recipes:

1. **Release blockers** — `SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers`
   + assert every blocker row is RESOLVED.
2. **Final execution state** — `SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state`
   + assert every required signal is SUCCESS or LOCAL_PASS on the exact final SHA; inspect `dist/final-execution-evidence.json`, `dist/final-ci-state-manifest.json`, and every referenced proof.
3. **Exact artifact evidence** — `SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence`
   + inspect `dist/release-certification-evidence.json` and `dist/exact-artifact-evidence-manifest.json`
   + independently run `sha256sum dist/sscribe-export-site-pages-{VERSION}.zip`.
4. **Agent final report** — `SSCRIBE_RELEASE_CERTIFICATION=1 composer test:agent-final-report`
   + inspect `dist/final-release-report.md` and `dist/agent-final-report-manifest.json`
   + verify version, source SHA, ZIP SHA-256, blocker state, and CI evidence match Phases 70–72.
5. **Branch protection** — `composer test:branch-protection`.
6. **Tag policy** — `composer test:tag-policy` + `git tag -v v{VERSION}`.
7. **Release pipeline** — `composer test:release-pipeline`.
8. **Acceptance matrix** — `composer test:acceptance-matrix`.
9. **Build transparency** — `composer test:build-transparency`
   (Phase 36 verifier).
10. **Third-party licenses** — `composer test:third-party-license`
    (Phase 37 verifier).
11. **Plugin Check triage** — `composer test:plugin-check-triage`.
12. **Manual runtime tests** — `composer test:manual-runtime-tests`
    + reviewer evidence recorded in docs/CI_EVIDENCE_v2.0.0.md.
13. **Exact ZIP** — install on a fresh WP instance + activate
    + run a 1-page export to confirm the plugin loads.
14. **Branch topology policy** — `composer test:branch-policy`
    + assert `dist/branch-policy-manifest.json` exists, has 0
    failures, and confirms **main is the only canonical long-lived branch**.
    An active pull-request branch may exist only as transient review state
    and must be deleted after merge or abandonment.

## How an independent auditor verifies this

```bash
# 1. Run the auditor-handoff verifier.
composer test:auditor-handoff

# 2. Confirm every artifact above exists and is
#    non-empty.
for f in \
  docs/RELEASE_BLOCKERS_v2.0.0.md \
  docs/FINAL_CI_STATE_v2.0.0.md \
  dist/final-execution-evidence.json \
  dist/final-ci-state-manifest.json \
  docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md \
  dist/release-certification-evidence.json \
  dist/exact-artifact-evidence-manifest.json \
  docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md \
  dist/final-release-report.md \
  dist/agent-final-report-manifest.json \
  docs/BRANCH_PROTECTION_v2.0.0.md \
  docs/TAG_POLICY_v2.0.0.md \
  docs/RELEASE_PIPELINE_v2.0.0.md \
  docs/ACCEPTANCE_MATRIX_v2.0.0.json \
  dist/acceptance-matrix-manifest.json \
  docs/BUILD_TRANSFORMATIONS.md \
  docs/SECURITY_MATRIX_v2.0.0.md \
  docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md \
  docs/MANUAL_RUNTIME_TESTS_v2.0.0.md \
  docs/CI_EVIDENCE_v2.0.0.md \
  docs/BRANCH_POLICY_v2.0.0.md \
  dist/branch-policy-manifest.json \
  dist/sscribe-export-site-pages-{VERSION}.zip \
  dist/sscribe-export-site-pages-{VERSION}.sha256 \
  ; do
    test -s "$f" || echo "MISSING: $f"
done

# 3. Re-run every verification recipe above and assert
#    each returns 0.
```

A green strict `composer test:auditor-handoff` + every generated and tracked artifact present
+ every verification recipe green = the exact current release candidate is ready for
reviewer handoff. The historical `docs/RELEASE_REPORT_v2.0.0.md` is provenance only and is not a current handoff artifact.

## What this contract does NOT cover

- **Reviewer approval** — the auditor signs off; this doc
  only defines the handoff artifacts.
- **Release invariants** — Phase 75 separately codifies the
  invariants that must NEVER drift.
- **Definition of done** — Phase 76 separately defines the
  canonical "shipped" state.

## Change log

- 2026-09-03: Initial Phase 74 auditor-handoff protocol +
  verifier + PHPUnit pin. 13 canonical handoff artifacts
  recorded. 13 verification recipes.
- 2026-09-03: Added Phase 77 branch topology policy as
  artifact #14 (docs + dist manifest) and a 14th
  verification recipe so the auditor can independently prove
  the long-lived branches obey the canonical contract.
- 2026-09-20: Replaced the historical v2.0.0 closeout as the Phase 73 handoff source with the tracked final-report template plus strict exact-SHA generated report/manifest; aligned the verification loop with all generated evidence named in the handoff table.
