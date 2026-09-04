# SScribe v2.0.0 — Release Candidate Report

**Version:** 2.0.0  
**Target:** wordpress.org/plugins/sscribe-export-site-pages  
**Repository state:** source hardening complete; final executable certification pending on the exact merged SHA.

## Current verdict

The repository is **not declared release-ready by documentation alone**. The
source tree has undergone the v2.0.0 hardening program and the independent
September 4 audit, but the final release verdict is issued only after the exact
merged `main` SHA passes the strict local/CI certification gates.

This report intentionally contains no stale "SHIP-WORTHY" SHA, old WordPress
minimums, old Plugin Check result, or previous candidate checksum. Those values
must be regenerated for the exact final source.

## Repository-side findings closed in the independent audit

- Fixed debug JSON download `Content-Length` to use byte length, preventing
  incorrect HTTP length headers for Arabic and other multibyte log content.
- Added regression coverage for the byte-length rule.
- Separated CI policy from actual execution evidence so a table saying
  "Required status: SUCCESS" can no longer masquerade as a passing run.
- Rebuilt the exact-artifact verifier so strict certification recomputes
  SHA-256, byte size, ZIP file count, checksum sidecar, and source SHA.
- Made Phase 70 blockers fail closed in strict release mode.
- Reset stale exact-artifact evidence values after source changes.
- Documented a controlled `LOCAL_PASS` evidence path when GitHub Actions never
  starts a job because of runner/account availability; local evidence must
  still be tied to the exact final SHA.
- Corrected the WordPress.org source-transparency requirement: because build
  tooling is excluded from the deployed ZIP, the exact maintained source/build
  inputs must be publicly available before submission.
- Aligned E2E workflow action majors with the canonical pinned CI actions.

## Canonical final gates

Run these on the exact merged SHA after dependencies/build inputs are prepared:

```bash
composer install --no-interaction --no-progress
npm ci --ignore-scripts
composer vendor:prefix

composer version:check
composer test
composer test:wp
composer test:coverage
composer test:coverage:check
composer stan
composer cs
composer i18n:check
npm run test:audit-helper
npm run audit:js
npm run lint
npm run test:e2e:smoke
npm run test:e2e:full
composer audit --locked --format=plain --abandoned=fail

composer release
```

Run the official WordPress Plugin Check against the exact resulting
`dist/sscribe-export-site-pages-2.0.0.zip`, then perform the exact-package
clean-install/runtime checks in `docs/WP_ORG_CLEAN_INSTALL_SMOKE.md` and
`docs/MANUAL_RUNTIME_TESTS_v2.0.0.md`.

After the evidence documents are populated for that same SHA/artifact, the
strict final gates are:

```bash
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence
bash bin/release-audit.sh
```

All must exit 0.

## Current metadata target

The canonical source currently declares:

- Version: `2.0.0`
- Requires WordPress: `6.1`
- Tested up to: `7.1`
- Requires PHP: `8.2`
- License: `GPL-2.0-or-later`

These values must remain synchronized across the main file, `readme.txt`,
Composer constraints, and release tooling.

## WordPress.org source transparency

The production ZIP intentionally excludes development/build inputs including
`scripts/`, tests, and CI configuration. Before WordPress.org submission,
make the exact tagged source and corresponding build tooling publicly available
through the canonical repository or an equivalent maintained public mirror.
Private or reviewer-only repository access is not sufficient.

## Final release evidence to record

Do not fill these from an older candidate. Record them from the exact final
merged SHA only:

- final source SHA / short SHA
- exact ZIP filename
- SHA-256
- exact byte size
- exact archive entry count
- build timestamp
- builder identity
- official Plugin Check evidence
- clean-install/runtime evidence
- required execution signal statuses (GitHub SUCCESS or documented LOCAL_PASS)

The authoritative schemas live in:

- `docs/RELEASE_BLOCKERS_v2.0.0.md`
- `docs/FINAL_CI_STATE_v2.0.0.md`
- `docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md`
- `docs/DEFINITION_OF_DONE_v2.0.0.md`

## Historical evidence

Earlier release-hardening evidence is retained in
`docs/CI_EVIDENCE_v2.0.0.md` and related phase documents for traceability,
but it is **historical evidence only** and cannot certify the final merged
candidate.

## Launch rule

Do not create `v2.0.0` or upload the ZIP to WordPress.org until:

1. every Phase 70 release blocker is `RESOLVED`;
2. every Phase 71 execution signal is `SUCCESS` or a concrete, exact-SHA
   `LOCAL_PASS`;
3. Phase 72 strict artifact identity matches the exact final ZIP;
4. the exact ZIP passes official Plugin Check, clean install, runtime export,
   multilingual/RTL checks, and the local release audit;
5. the exact source/build inputs are publicly available for WordPress.org.

At that point the release can be declared ready, tagged, and submitted.
