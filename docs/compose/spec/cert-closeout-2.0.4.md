---
feature: cert-closeout-2.0.4
status: designed
updated: 2026-09-26
branch: cert/closeout-2.0.4
commits: 
---

# Certification Closeout 2.0.4

## Report

## [S1] Problem
Local certification of SScribe 2.0.4 is incomplete. Plugin Check still emits DirectQuery/NoCaching warnings; Plugin Check report validation is not wired to the live report; debug-console JS has untranslated strings; storage-boundary behavior around synthetic TCPDF DOCUMENT_ROOT needs an explicit regression; compatibility matrix and strict evidence manifests are missing. Tracked source must be fixed and frozen before any final ZIP/SHA is certified. Final reports and evidence must stay untracked.

## [S2] Design
1. **Storage boundary** — `SScribe_Private_Storage::get_usable_document_root()` must ignore filesystem-root and bare-drive `DOCUMENT_ROOT` values (TCPDF autoconfig mutates it). Pin with unit tests that set `DOCUMENT_ROOT` to `/` and assert private storage still resolves. Keep `is_outside_public_roots` and `get_base_candidates` using the helper only.
2. **Plugin Check validation** — `verify-plugin-check.php` / triage gate must parse a real Plugin Check report file (TSV/JSON from `wp plugin check`) and fail on **new** error/warning codes not in `docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md`. No PASS from a stub or missing report.
3. **Warning remediation** — eliminate or formally acknowledge the four `WordPress.DB.DirectDatabaseQuery.*` warnings on `table_exists()` probes. Prefer a `$wpdb->prepare` form that Plugin Check accepts (or `%i` when floor allows); otherwise add triage rows with justification + integration pin so regressions fail.
4. **Debug-console i18n** — route user-visible strings in `admin/js/sscribe-debug-console.js` through `sscribe_data.strings` sourced from PHP `__()` so the POT includes them. Align `escHtml` with `escapeHtml` (escape quotes).
5. **Compatibility matrix** — run declared PHP/WP matrix where runtimes exist; document every skip as BLOCKED with reason. Run `composer release:determinism` before final evidence.
6. **Exact-artifact certification** — after all tracked fixes: freeze SHA, build ZIP once, run full E2E/a11y, visual/export inspection (Playwright), lifecycle, six Phase 69 environments (or BLOCKED), generate strict manifests from actual results, run `SSCRIBE_RELEASE_CERTIFICATION=1` gates.
7. **Artifacts** — ZIP, raw logs, manifests, screenshots, audit reports, final SHA/hash. Reports/evidence under `dist/` and untracked paths only.

## [S3] Out of Scope
- New export formats or features
- Changing minimum WP/PHP floors
- WordPress.org SVN upload / tagging
- Rewriting third-party vendor code except via supported filters/config

## Tasks
- [ ] T1: Storage-boundary regression tests for synthetic DOCUMENT_ROOT — acceptance: tests fail without helper, pass with it (covers: S2.1)
- [ ] T2: Plugin Check report parser + triage gate uses live report — acceptance: missing/unparsed report is FAIL; known warnings PASS; new codes FAIL (covers: S2.2; depends: T1)
- [ ] T3: Remediate DirectQuery table_exists warnings — acceptance: Plugin Check shows 0 untriaged warnings (covers: S2.3; depends: T2)
- [ ] T4: Debug-console i18n + escHtml quote parity — acceptance: no hardcoded UI strings outside strings map; escHtml escapes quotes; POT regenerated (covers: S2.4)
- [ ] T5: Compatibility matrix + release:determinism — acceptance: each leg PASS or documented BLOCKED; determinism exit 0 (covers: S2.5; depends: T3, T4)
- [ ] T6: Freeze SHA, build ZIP, full E2E/a11y/lifecycle/visual — acceptance: evidence logs non-empty and bound to ZIP SHA (covers: S2.6; depends: T5)
- [ ] T7: Six environments + strict manifests + certification gates — acceptance: manifests reflect actual results; unavailable envs BLOCKED; strict gates run (covers: S2.6; depends: T6)
- [ ] T8: Untracked final report bundle (ZIP, logs, manifests, screenshots, audits, SHA) — acceptance: package complete for reviewer (covers: S2.7; depends: T7)
