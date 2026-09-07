# Release Invariants — v2.0.0

## Why this exists

Phase 75 of the v2.0.0 release-hardening spec mandates that the
release pipeline MUST obey a canonical set of invariants that
NEVER drift. Each invariant is independently enforceable by an
automated verifier; drift in any invariant silently ships a
broken release (wrong version, stale ZIP, leaked secrets, etc.).

The companion verifier `scripts/verify-release-invariants.php`
walks this checklist and asserts every invariant is declared and
referenced by a Phase gate that enforces it.

## Canonical invariants

The release pipeline MUST obey every invariant below:

| #  | Invariant                                          | Enforced by                                          |
|----|----------------------------------------------------|------------------------------------------------------|
| 1  | SSCRIBE_VERSION is the single source of truth.     | Phase 16 verifier + PHPUnit regression.              |
| 2  | `readme.txt` Stable tag matches SSCRIBE_VERSION.   | Phase 16 verifier.                                   |
| 3  | `package.json` version matches SSCRIBE_VERSION.    | Phase 16 verifier.                                   |
| 4  | Mainfile `Version:` header matches SSCRIBE_VERSION.| Phase 16 verifier + Phase 63 ZIP mainfile check.     |
| 5  | ZIP SHA-256 is reproducible from source + tools.   | Phase 53 release-pipeline verifier.                  |
| 6  | ZIP contains zero comments in shipped PHP/CSS/JS.   | Phase 53 release-pipeline verifier.                  |
| 7  | ZIP excludes every dev-only path (tests/, scripts/, vendor/, etc.). | Phase 34 ZIP certification + Phase 35 content rules. |
| 8  | ZIP mainfile `Version:` matches SSCRIBE_VERSION.   | Phase 34 + Phase 63.                                 |
| 9  | Every shipped PHP file parses without syntax error.| Phase 32 lint job (PHP 8.2/8.3/8.4/8.5 matrix).     |
| 10 | Composer security audit is clean (no HIGH/CRITICAL).| Phase 32 audit job.                                  |
| 11 | NPM audit is clean (no HIGH/CRITICAL).            | Phase 32 frontend-quality job.                       |
| 12 | AI-artifact scan finds zero AI markers.           | Phase 32 audit job.                                  |
| 13 | Plugin Check on the exact ZIP is PASS.            | Phase 33 plugin-check job.                          |
| 14 | Real-WordPress testbench matrix is green (every leg).| Phase 27 + Phase 56 real-wp-tests matrix.           |
| 15 | Coverage thresholds met (project ≥ 70%, critical ≥ 90%).| Phase 41 coverage threshold gate.             |
| 16 | Zero `wp_ajax_nopriv_sscribe_*` actions registered.| Phase 49 AJAX security + Phase 66 AJAX network trace.|
| 17 | Zero `eval(`, `extract($_GET)`, `extract($_POST)` in shipped code.| Phase 32 security scanner.           |
| 18 | Composer autoload points at `vendor-prefixed/`.    | Phase 35 ZIP content rules.                          |
| 19 | Translation POT declares `X-Domain` + `Project-Id-Version` matching mainfile.| Phase 42 i18n contract.       |
| 20 | Every `composer test:*` script is documented in `docs/CI_COMMANDS.md`. | Phase 61 CI docs.                |
| 21 | Every `verify-*.php` script has a matching `SScribe_*_Test.php` PHPUnit regression.| Every Phase 16-74.                 |
| 22 | Every `.github/workflows/*.yml` uses SHA-pinned actions (no `@vN`).| Phase 52 workflow governance.      |
| 23 | `continue-on-error: true` is banned on required steps.| Phase 52 workflow governance.                       |
| 24 | All shipped PHP files use SSCRIBE prefix OR are in `vendor-prefixed/`.| Phase 32 + Phase 33 plugin-check. |
| 25 | No raw internal details leak to user-facing surfaces (admin UI, AJAX responses, REST, JS-visible PHP).| Phase 60 no-internal-details.|
| 26 | Every shipped JS file is runtime-clean (no console.error, no var, strict mode, .catch matched).| Phase 65 JS error-free.|
| 27 | UI refactor discipline holds (admin file count locked at v1.9.0 baseline).| Phase 67 UI refactor discipline.|
| 28 | Every required new test (24 signatures from Phase 68) is present in tests/Integration/ or tests/Unit/.| Phase 68 test coverage.|
| 29 | Manual runtime tests runbook covers all 6 canonical environments.| Phase 69 manual runtime tests.|
| 30 | Every Phase 70 blocker is RESOLVED; none is DEFERRED.| Phase 70 release blockers.                         |
| 31 | Every Phase 71 required CI job is SUCCESS or documented LOCAL_PASS on the final SHA — the underlying every Phase 71 required execution signal is SUCCESS or documented LOCAL_PASS check stays green.| Phase 71 final execution state. |
| 32 | Phase 72 evidence matches the actual ZIP, checksum sidecar, and current source SHA. Every Phase 72 artifact evidence field pins the matching ZIP.| Phase 72 exact artifact evidence. |
| 33 | Branch topology policy holds (exactly `main` and `develop` long-lived; same SHA; no local-only refs).| Phase 77 branch topology policy. |
| 34 | Public maintained exact source/build inputs are available for WordPress.org reviewers because build tooling is omitted from the deployed ZIP. | Phase 70 blocker 18 / WordPress.org source guideline. |

The verifier asserts all 34 invariants are declared and each
declares the enforcing Phase in its row.

## How an independent auditor verifies this

```bash
# 1. Run the release-invariants verifier.
composer test:release-invariants

# 2. Re-run every Phase gate that enforces each invariant
#    (a sample; full list is in the table above).
composer test
composer test:wp
composer test:version-sync
composer test:release-pipeline
composer test:zip-certification
composer test:zip-content-rules
composer test:security-scan
composer test:workflow-governance
composer test:ajax-security
composer test:no-internal-details
composer test:js-error-free
composer test:ui-refactor-discipline
composer test:phase-68-test-coverage
composer test:manual-runtime-tests
composer test:release-blockers
composer test:final-ci-state
composer test:exact-artifact-evidence
composer test:branch-policy
```

A green `composer test:release-invariants` + every enforcing
Phase green = the release pipeline obeys the canonical invariants.

## What this contract does NOT cover

- **Definition of done** — Phase 76 separately defines the
  canonical "shipped" state.
- **Auditor handoff** — Phase 74 separately defines the
  reviewer handoff artifacts.

## Change log

- 2026-09-03: Initial Phase 75 release-invariants contract +
  verifier + PHPUnit pin. 32 canonical invariants recorded
  (versions, ZIP, security, audit, Plugin Check, real-WP,
  coverage, AJAX, autoload, i18n, CI docs, workflow governance,
  no-internal-details, JS error-free, UI discipline, Phase 68
  coverage, manual runtime tests, Phase 70 blockers, Phase 71
  CI state, Phase 72 evidence).
- 2026-09-03: Added Phase 77 branch topology invariant (#33) so
  the pipeline cannot ship a release whose long-lived branches
  have diverged beyond the canonical contract.
