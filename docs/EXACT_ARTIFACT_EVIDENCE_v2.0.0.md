# Exact Artifact Evidence — v2.0.0

## Why this exists

Phase 72 proves that the exact ZIP being submitted is the ZIP built from the
exact source commit being certified. A non-empty documentation field is not
proof: the recorded SHA-256, byte size, ZIP entry count, source SHA, and
checksum sidecar must match the files and checkout on disk.

The normal source-contract check validates the evidence schema. Strict release
certification is enabled with `SSCRIBE_RELEASE_CERTIFICATION=1`; strict mode
recomputes the artifact identity and rejects stale or placeholder evidence.

## Canonical evidence fields

| # | Field | Source | Purpose |
|---|---|---|---|
| 1 | `version` | `SSCRIBE_VERSION` in the main plugin file | Locks the packaged version. |
| 2 | `zip_filename` | `dist/sscribe-export-site-pages-{VERSION}.zip` | Locks the exact artifact path. |
| 3 | `zip_sha256` | SHA-256 of the exact ZIP | Locks bit identity. |
| 4 | `zip_byte_size` | Exact ZIP byte size | Detects any byte-level change. |
| 5 | `zip_file_count` | `ZipArchive::numFiles` for the exact ZIP | Locks archive entry count. |
| 6 | `source_sha` | `git rev-parse HEAD` | Locks the source checkout. |
| 7 | `source_short_sha` | first 8 hex characters of `source_sha` | Human-readable source identity. |
| 8 | `builder_run_id` | GitHub run ID or explicit local-certification identifier | Identifies the builder execution. |
| 9 | `builder_workflow` | Build workflow/job or explicit local build command | Identifies the build path. |
| 10 | `plugin_check_url` | GitHub Plugin Check run URL, or a concrete local evidence reference when GitHub never executed | Connects Plugin Check evidence to this artifact. |
| 11 | `clean_install_doc` | Clean-install evidence document | Points to exact-package smoke evidence. |
| 12 | `build_timestamp` | ISO 8601 UTC completion timestamp | Dates certification evidence. |

## Recorded evidence

These values are intentionally reset whenever source changes after a previous
candidate build. Replace every `PENDING_FINAL_CERTIFICATION` value only after
building and testing the exact final SHA.

| # | Field | Recorded value |
|---|---|---|
| 1 | version | 2.0.0 |
| 2 | zip_filename | dist/sscribe-export-site-pages-2.0.0.zip |
| 3 | zip_sha256 | PENDING_FINAL_CERTIFICATION |
| 4 | zip_byte_size | PENDING_FINAL_CERTIFICATION |
| 5 | zip_file_count | PENDING_FINAL_CERTIFICATION |
| 6 | source_sha | PENDING_FINAL_CERTIFICATION |
| 7 | source_short_sha | PENDING_FINAL_CERTIFICATION |
| 8 | builder_run_id | PENDING_FINAL_CERTIFICATION |
| 9 | builder_workflow | PENDING_FINAL_CERTIFICATION |
| 10 | plugin_check_url | PENDING_FINAL_CERTIFICATION |
| 11 | clean_install_doc | docs/WP_ORG_CLEAN_INSTALL_SMOKE.md |
| 12 | build_timestamp | PENDING_FINAL_CERTIFICATION |

## Strict certification rules

With `SSCRIBE_RELEASE_CERTIFICATION=1`, the verifier must confirm all of the
following rather than trusting documentation text:

1. The main plugin version equals the recorded `version`.
2. Exactly the expected current-version ZIP is selected for certification.
3. The recorded ZIP filename equals the actual relative path.
4. `zip_sha256` equals a fresh `hash_file('sha256', ...)` result.
5. The `.sha256` sidecar exists and contains that same digest.
6. `zip_byte_size` equals `filesize()`.
7. `zip_file_count` equals `ZipArchive::numFiles`.
8. `source_sha` equals `git rev-parse HEAD`.
9. `source_short_sha` equals the first eight characters of that SHA.
10. No required evidence value is blank or a `PENDING`/TBD placeholder.
11. The clean-install evidence document exists.
12. The build timestamp is valid ISO 8601 data.

Plugin Check success and clean-install/runtime acceptance remain independent
release blockers as well; a matching checksum alone does not prove behavior.

## How an independent auditor verifies this

```bash
# Schema/source-contract check.
composer test:exact-artifact-evidence

# Actual release certification.
SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence

# Independent spot checks.
sha256sum dist/sscribe-export-site-pages-2.0.0.zip
git rev-parse HEAD
```

The strict verifier must exit 0 before tagging.

## What this contract does NOT cover

- Phase 70 controls unresolved release blockers.
- Phase 71 controls execution evidence.
- Phase 74 controls auditor handoff.
- Phase 75 controls release invariants.

## Change log

- 2026-09-04: Replaced non-empty-only evidence checks with strict live
  artifact/source comparison and reset stale candidate evidence.
- 2026-09-03: Initial Phase 72 contract.
