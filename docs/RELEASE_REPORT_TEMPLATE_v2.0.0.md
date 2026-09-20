# SScribe Final Release Report — tracked template

> **Governance template:** this tracked file is not release proof. The current
> exact-SHA report is generated as `dist/final-release-report.md` during strict
> release certification. The historical v2.0.0 closeout remains separately at
> `docs/RELEASE_REPORT_v2.0.0.md`.

Generated: PENDING_FINAL_CERTIFICATION

## Executive summary

Release version: PENDING_FINAL_CERTIFICATION  
Release tag: PENDING_FINAL_CERTIFICATION  
Source SHA: PENDING_FINAL_CERTIFICATION

This section is populated from strict release evidence only after all tracked
release changes are committed and the exact source SHA is fixed.

## Release evidence

- ZIP: PENDING_FINAL_CERTIFICATION
- ZIP SHA-256: PENDING_FINAL_CERTIFICATION
- ZIP byte size: PENDING_FINAL_CERTIFICATION
- ZIP file count: PENDING_FINAL_CERTIFICATION
- Builder evidence: PENDING_FINAL_CERTIFICATION
- Plugin Check evidence: PENDING_FINAL_CERTIFICATION

## Blocker status

- Phase 70 effective blocker state: PENDING_FINAL_CERTIFICATION
- Evidence: `dist/release-blockers-manifest.json`

## CI state

- Phase 71 exact-SHA execution state: PENDING_FINAL_CERTIFICATION
- Evidence: `dist/final-ci-state-manifest.json`

## Open items

- PENDING_FINAL_CERTIFICATION

A strict final report may state that there are no open release blockers only
when the strict Phase 70 manifest reports `release_ready: true` and zero
effective deferred blockers.

## Verification recipe

```bash
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:agent-final-report
sha256sum dist/sscribe-export-site-pages-{VERSION}.zip
git rev-parse HEAD
git status --short
```

The generated `dist/final-release-report.md` must contain concrete values and
must not contain PENDING/TBD placeholders.
