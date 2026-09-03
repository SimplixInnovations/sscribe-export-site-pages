# UI Refactor Discipline — v2.0.0

## Why this exists

Phase 67 of the v2.0.0 release-hardening spec is **not a feature**;
it is a discipline gate. The spec says verbatim:

> Current priority is stability. Do not split the huge admin JS/CSS/PHP
> files simply because they are large unless necessary to implement
> the fixes safely. Post-release modularization can be a separate
> milestone. Avoid changing UX unnecessarily.

A regression that quietly splits `sscribe-admin.js` into a dozen
`sscribe-admin-{tab}.js` files during the 2.0.0 cycle would:

1. Break the WP.org submission that has already been reviewed against
   the v1.9.0 admin surface.
2. Introduce merge conflicts with already-certified review feedback.
3. Force the independent auditor (Phase 74) to re-review the entire
   admin surface instead of the targeted bug-fix / a11y / perf
   changes.
4. Threaten the v2.0.0 stability promise.

This document records the **UI refactor freeze** that governs the
remainder of the v2.0.0 release cycle. The discipline is enforced by
`scripts/verify-ui-refactor-discipline.php` (Phase 67 contract gate)
and pinned at the PHPUnit boundary by
`tests/Integration/SScribe_UI_Refactor_Discipline_Test.php`.

## Baseline SHA

The UI refactor freeze takes effect from the v1.9.0 release tag:

```
BASELINE_SHA = 1aa940d7
```

Any commit at or after `1aa940d7` is bound by this discipline.

## File-count lock

The number of admin surface files MUST match the v1.9.0 baseline
exactly. Adding, removing, splitting, or renaming an admin surface
file is a release-blocking regression.

| Path              | v1.9.0 baseline | Required at HEAD |
|-------------------|-----------------|------------------|
| `admin/*.php`     | 2               | 2                |
| `admin/css/*.css` | 3               | 3                |
| `admin/js/*.js`   | 2               | 2                |
| `admin/partials/*.php` | 2          | 2                |
| **Total**         | **9**           | **9**            |

The `index.php` silence-is-golden stubs in each directory are
intentional and not counted.

## Rename / add / split ban

Between `BASELINE_SHA` and the current `HEAD`, the following
operations are forbidden on the admin surface:

- **R (rename):** splitting `sscribe-admin.js` into
  `sscribe-admin-export.js` + `sscribe-admin-debug.js`, etc.
- **A (add):** adding a new `admin/js/sscribe-admin-settings.js` or
  similar.
- **D (delete):** removing a file with the intent of "replacing it"
  via two commits.
- **M (modify) of the file *path* (the file path stays the same):**
  modifications to existing files are fine when scoped to bug fixes,
  accessibility, performance, or security — see below.

## Allowed modifications to existing admin files

Inside the existing nine-file admin surface, modifications are
allowed only when scoped to one of the following intents:

1. **Bug fix** for an issue called out in the v1.2.0 release notes
   that has been carried into v2.0.0.
2. **Accessibility (WCAG 2.2 AA)** closure per Phase 43.
3. **Performance budget** compliance per Phase 44 / Performance
   Benchmarks doc.
4. **Operational log acceptance** per Phase 59.
5. **Security hardening** per Phase 38 / 39 / 48 / 49.
6. **i18n** text-domain consistency per Phase 42.
7. **Phase 43–66 contract gate wiring** — adding AJAX guard
   instrumentation, network trace logging, etc. **inside the existing
   nine-file surface**.

Anything else is a refactor and is forbidden.

## Post-release modularization backlog

Items deliberately **deferred to post-2.0.0** so the release
remains stable:

| Item                                  | Owner       | Target version | Reason for deferral                                  |
|---------------------------------------|-------------|----------------|------------------------------------------------------|
| Split `sscribe-admin.js` per tab      | unassigned  | 2.0.1 or later | JS bundle is monolithic but battle-tested; no release-critical need |
| Extract `sscribe-admin.css` tokens into per-tab partials | unassigned | 2.0.1 or later | Token system already extracted to `sscribe-tokens.css`; further splits carry risk |
| Split `class-sscribe-admin.php` into per-tab classes | unassigned | 2.0.1 or later | Class is large but already cleanly sectioned via docblocks; no release-critical need |
| Convert `admin/partials/*.php` to a per-tab partial lookup | unassigned | 2.0.1 or later | Would force a wider admin template review |
| Adopt `@vue/react` for the admin export wizard | rejected for 2.0.0 | n/a | UX stability is the priority; a framework swap is not |

Each backlog item must be reviewed against the v2.0.0 contract gates
(Phases 35–76) before it ships in any subsequent release.

## How an independent auditor verifies this

```bash
# 1. Run the discipline gate.
composer test:ui-refactor-discipline

# 2. Cross-check the file count.
git ls-tree -r 1aa940d7 -- admin/css/ admin/js/ admin/partials/ admin/*.php | wc -l
git ls-tree -r HEAD -- admin/css/ admin/js/ admin/partials/ admin/*.php | wc -l

# 3. Cross-check that no admin file was renamed since the freeze.
git diff --name-status 1aa940d7 HEAD -- admin/

# 4. Read this doc.
cat docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md
```

A green `composer test:ui-refactor-discipline` + zero `R` / non-zero
`A` in step 3 = the UI refactor freeze is intact.

## What this contract does NOT cover

- **Source code style refactors inside existing files** — moving a
  helper from the bottom of a class to the top, extracting a private
  static method, etc. are not "UI refactors" in the sense of this
  doc. They are normal code hygiene and are permitted.
- **Library replacement** (e.g. swapping mpdf for a smaller PDF
  library) — that is a Phase 37 third-party-license review and Phase
  41 coverage / Phase 62 build-order question, not a UI refactor.
- **Documentation-only changes** to admin partials — comments and
  docblocks do not change shipped behavior.

## Change log

- 2026-09-03: Initial discipline doc + verifier + PHPUnit pin.
  Baseline `1aa940d7` (v1.9.0). File-count lock recorded.
