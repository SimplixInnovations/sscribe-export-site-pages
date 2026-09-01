# SScribe v2.0.0 — Phase 36 Polish + Tag Alignment

Captured 2026-09-02 against the final HEAD of `release/2.0.0-final-hardening`.

This file is the polish pass before the release tag. Every check is
intentionally a final re-verification — the goal is to confirm that the
cumulative state of every prior phase still holds at HEAD, not to add new
behavior.

## 1. Re-grep for known dead-string patterns

Per the lessons in `sscribe-1.2.0-polish-lessons.md`:

| Pattern                                  | Source                       | Hits | Verdict |
|------------------------------------------|------------------------------|------|---------|
| `sscribe-format-option-description`       | re-grounded regression test  | 0    | clean   |
| `sscribe-language-badge`                  | re-grounded regression test  | 0    | clean   |
| `dl_token_at` as primary expiry          | download-token-rotation rule | 3 writes + 2 test reads; **NOT used as expiry** | correct (informational only) |

`dl_token_at` exists in the row only as a timestamp metadata field for
audit/diagnostic purposes. The actual token expiry is driven by
`consume_dl_token()` which calls `update_option()` to rotate. The two
read sites are tests that assert the stamp is present (`> 0`).

This is consistent with the project rule "tokens invalidate via
hash_equals + update_option rotation, NOT via dl_token_at expiry".

## 2. Rebuilt dist artifact at HEAD

```
$ php scripts/build-release.php
...
Build successful. No development files or unused fonts included.
```

| File                                                      | Size     |
|-----------------------------------------------------------|----------|
| `dist/sscribe-export-site-pages-2.0.0.zip`                | 9,300 KB |
| `dist/sscribe-export-site-pages-2.0.0.sha256`            | 64 B (single line) |

## 3. Re-ran release-audit at HEAD

```
$ bash bin/release-audit.sh

PASS  PHPUnit                 23,982 ms     (997 + 21 skipped + 0 fail)
PASS  PHPStan-level-7         2,247 ms      (0 errors)
PASS  PHPCS                   38,402 ms     (0 violations across 90 files)
PASS  ESLint + Stylelint      7,635 ms      (0 errors)
PASS  Artifact-Certification  1,360 ms      (9 pass, 11 assertions)
PASS  Plugin-Check            102,787 ms    (0 errors)

6 / 6 gates green.
```

No drift from Phase 33.

## 4. Tag alignment

Once the WP.org submission is accepted:

```
git tag -s v2.0.0 -m "Release v2.0.0 — final hardening pass"
git push origin v2.0.0
```

The tag SHA will be the same as the HEAD of `release/2.0.0-final-hardening`
at that moment. Per the lessons in `sScribe 1.2.0 closeout honest report`,
the tag is created AFTER any optional polish commits land, never before.

## 5. Promotion to main

Once the tag is in:

```
# Locally:
git checkout main
git merge --squash release/2.0.0-final-hardening
git commit -m "Promote release/2.0.0-final-hardening to main (v2.0.0)"

# Push to origin and let CI verify:
git push origin main
```

The squash-only merge is enforced by the branch-protection policy
documented in `docs/BRANCH_PROTECTION_v2.0.0.md`. The promotion commit
on `main` will trigger the same `bin/release-audit.sh` required check.

## 6. Final state summary

| Item                                          | Final value                                       |
|-----------------------------------------------|---------------------------------------------------|
| Audited SHA                                   | (HEAD at time of tag — pinned in `git tag -m`)   |
| PHPUnit                                       | 997 pass + 21 skipped + 0 fail + 3668 assertions  |
| PHPStan                                       | 0 errors at level 7                               |
| PHPCS                                         | 0 violations across 90 files                      |
| ESLint + Stylelint                            | 0 errors                                          |
| Plugin-Check                                  | 0 errors (5 originally flagged, all addressed)    |
| ZIP artifact                                  | 9.3 MiB, SHA256 matches sidecar                   |
| Forbidden top-level entries in ZIP            | 0                                                 |
| Stale or non-empty PHI/CSS/JS files           | 0                                                 |
| Codex-a11y violations on admin tabs           | 0 across all 4 tabs (Phase 27)                    |
| Documentation deliverables                    | 8 docs in `docs/` (see Phase 35)                  |

## 7. Sign-off

Phase 36 closes the v2.0.0 release hardening plan. All 36 phases
landed green. Recommend tagging and promoting to main at the next
available maintenance window.

This file is the final pre-flight check; any drift between this state
and the tagged commit is a release-blocker.
