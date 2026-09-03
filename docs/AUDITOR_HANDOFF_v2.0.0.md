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
4. Integration test exists.

## Canonical handoff artifacts

Every release handoff MUST include, at minimum, these artifacts:

| #  | Artifact                                 | Path                                                                                | Phase reference                |
|----|------------------------------------------|-------------------------------------------------------------------------------------|--------------------------------|
| 1  | Release blockers checklist               | docs/RELEASE_BLOCKERS_v2.0.0.md                                                     | Phase 70                       |
| 2  | Final CI state checklist                 | docs/FINAL_CI_STATE_v2.0.0.md                                                       | Phase 71                       |
| 3  | Exact artifact evidence                  | docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md                                              | Phase 72                       |
| 4  | Agent final report                       | docs/RELEASE_REPORT_v2.0.0.md                                                       | Phase 73                       |
| 5  | Branch protection contract               | docs/BRANCH_PROTECTION_v2.0.0.md                                                    | Phase 55                       |
| 6  | Tag policy contract                      | docs/TAG_POLICY_v2.0.0.md                                                           | Phase 54                       |
| 7  | Release pipeline contract                | docs/RELEASE_PIPELINE_v2.0.0.md                                                     | Phase 53                       |
| 8  | Acceptance matrix                        | docs/ACCEPTANCE_MATRIX_v2.0.0.json + dist/acceptance-matrix-manifest.json            | Phase 58                       |
| 9  | Build transparency doc                   | docs/BUILD_TRANSFORMATIONS.md                                                       | Phase 36                       |
| 10 | Third-party license inventory            | docs/SECURITY_MATRIX_v2.0.0.md                                                      | Phase 37                       |
| 11 | Plugin Check triage                      | docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md                                                | Phase 64                       |
| 12 | Manual runtime tests runbook             | docs/MANUAL_RUNTIME_TESTS_v2.0.0.md + docs/CI_EVIDENCE_v2.0.0.md                    | Phase 69                       |
| 13 | Exact release ZIP + SHA-256 sidecar     | dist/sscribe-export-site-pages-{VERSION}.zip + dist/sscribe-export-site-pages-{VERSION}.sha256 | Phase 63 + 72     |

The handoff MUST include every artifact above. A missing artifact
fails the gate.

## Verification recipe per artifact

For each handoff artifact, the auditor runs a specific command
to verify the claim. The canonical recipes:

1. **Release blockers** — `composer test:release-blockers`
   + assert every blocker row is RESOLVED or DEFERRED.
2. **Final CI state** — `composer test:final-ci-state`
   + assert every required job is SUCCESS or SKIPPED.
3. **Exact artifact evidence** — `composer test:exact-artifact-evidence`
   + `sha256sum dist/sscribe-export-site-pages-{VERSION}.zip`.
4. **Agent final report** — read the report, verify SHA-256
   + commit SHA + version matches every other artifact.
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

## How an independent auditor verifies this

```bash
# 1. Run the auditor-handoff verifier.
composer test:auditor-handoff

# 2. Confirm every artifact above exists and is
#    non-empty.
for f in \
  docs/RELEASE_BLOCKERS_v2.0.0.md \
  docs/FINAL_CI_STATE_v2.0.0.md \
  docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md \
  docs/RELEASE_REPORT_v2.0.0.md \
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
  ; do
    test -s "$f" || echo "MISSING: $f"
done

# 3. Re-run every verification recipe above and assert
#    each returns 0.
```

A green `composer test:auditor-handoff` + every artifact present
+ every verification recipe green = the release is ready for
reviewer handoff.

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
