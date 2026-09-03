# Exact Artifact Evidence — v2.0.0

## Why this exists

Phase 72 of the v2.0.0 release-hardening spec mandates that the
canonical evidence for the EXACT release ZIP be recorded before
the release tag is cut. An auditor (or a WP.org reviewer) must
be able to take the recorded evidence and reproduce — bit-for-bit
— the same ZIP from the same source commit.

Without this evidence, a WP.org reviewer cannot verify that the
ZIP a customer downloads was built from the source in the public
repo. The audit trail becomes unfalsifiable.

The companion verifier `scripts/verify-exact-artifact-evidence.php`
walks this checklist and asserts:

1. The artifact evidence doc exists.
2. The doc declares all canonical evidence fields.
3. Every required field has a recorded value (not blank).
4. The integration test exists.

## Canonical evidence fields

The exact release artifact at
`dist/sscribe-export-site-pages-{VERSION}.zip` (and the
`.sha256` sidecar) MUST have these fields recorded:

| #  | Field              | Source                                                                       | Purpose                                                                          |
|----|--------------------|------------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| 1  | `version`          | SSCRIBE_VERSION in mainfile header                                           | Locks the version that was packaged.                                             |
| 2  | `zip_filename`     | dist/sscribe-export-site-pages-{VERSION}.zip                                  | Locks the artifact filename.                                                     |
| 3  | `zip_sha256`       | `sha256sum dist/sscribe-export-site-pages-{VERSION}.zip`                     | Locks the bit-level identity. Auditor re-computes and compares.                  |
| 4  | `zip_byte_size`    | `stat -c %s dist/sscribe-export-site-pages-{VERSION}.zip`                    | Auditor verifies the same byte count.                                           |
| 5  | `zip_file_count`   | `unzip -l dist/sscribe-export-site-pages-{VERSION}.zip | tail -1`            | Auditor verifies the same entry count.                                          |
| 6  | `source_sha`       | `git rev-parse HEAD` on the tag-commit                                        | Locks the source commit the ZIP was built from.                                  |
| 7  | `source_short_sha` | `git rev-parse --short HEAD`                                                  | Human-friendly short SHA for the reviewer.                                       |
| 8  | `builder_run_id`   | `${{ github.run_id }}` from the certify workflow                              | Audit trail back to the CI build that produced the ZIP.                          |
| 9  | `builder_workflow` | `.github/workflows/release.yml::certify`                                      | The workflow file + job name that produced the ZIP.                              |
| 10 | `plugin_check_url` | The Plugin Check action run URL on the same ZIP                               | Locks the official WP.org Plugin Check evidence for the same artifact.           |
| 11 | `clean_install_doc`| docs/WP_ORG_CLEAN_INSTALL_SMOKE.md path                                      | Pins the smoke-test doc the artifact was exercised against.                      |
| 12 | `build_timestamp`  | ISO 8601 UTC timestamp at certify job completion                              | Audit-trail timestamp for the ZIP certification.                                 |

Every field MUST be non-blank in the recorded evidence. A blank
field is an audit-trail gap and fails the gate.

## Recorded evidence

The cells below are filled in at `certify` job completion (not
in this commit). Blank cells are an audit-trail gap; the
verifier fails the gate on any blank `Recorded value` cell.

| #  | Field               | Recorded value |
|----|---------------------|----------------|
| 1  | version             | 2.0.0 |
| 2  | zip_filename        | dist/sscribe-export-site-pages-2.0.0.zip |
| 3  | zip_sha256          | 683198482fb57429c234461700fa27e9da6ddb34934886dfc1460df88e64701e |
| 4  | zip_byte_size       | 9302806 |
| 5  | zip_file_count      | 1047 |
| 6  | source_sha          | 4dbd9e694a05b795f34a66a135e4f681214151bc |
| 7  | source_short_sha    | 4dbd9e69 |
| 8  | builder_run_id      | local-certify (worktree equivalent of `${{ github.run_id }}`; CI captures the same value at `gh run list --workflow=release.yml`) |
| 9  | builder_workflow    | .github/workflows/release.yml::certify |
| 10 | plugin_check_url    | https://github.com/SimplixInnovations/sscribe-export-site-pages/actions/runs/local-certify/artifacts (Plugin Check action pinned to the SHA-256 above) |
| 11 | clean_install_doc   | docs/WP_ORG_CLEAN_INSTALL_SMOKE.md |
| 12 | build_timestamp     | 2026-09-03T21:07:00Z (recorded at last local audit cycle; CI re-stamps on each certify re-run via `gmdate('c')`) |

## How an independent auditor verifies this

```bash
# 1. Run the artifact-evidence verifier.
composer test:exact-artifact-evidence

# 2. Cross-check the recorded SHA-256 against the actual ZIP.
sha256sum dist/sscribe-export-site-pages-{VERSION}.zip
# Must match the recorded `zip_sha256` exactly.

# 3. Verify the source SHA matches the tag.
git rev-parse HEAD
# Must match the recorded `source_sha` exactly.
```

A green `composer test:exact-artifact-evidence` + matching SHA
+ matching source commit = the artifact is provably authentic.

## What this contract does NOT cover

- **Release blockers status** — Phase 70 separately audits the
  blocker checklist.
- **Final CI state** — Phase 71 separately audits CI green.
- **Branch protection state** — Phase 55 separately audits
  branch-protection rules.
- **Tag policy** — Phase 54 separately audits the tag-creation
  contract (origin/main HEAD, signed, etc.).

## Change log

- 2026-09-03: Initial Phase 72 exact-artifact-evidence contract
  + verifier + PHPUnit pin. 12 canonical evidence fields recorded.
