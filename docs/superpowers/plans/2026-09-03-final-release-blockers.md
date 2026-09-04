# SScribe 2.0.0 Final Release Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development. Every production fix follows RED -> GREEN -> refactor/verify.

**Goal:** Close the independent audit blockers on top of `c8026092a2e97c0bb8a61d4e8c4af3ab7e38aa09` and certify one exact release SHA.

**Architecture:** Surgical request-boundary and client hardening, small direct fixes to logging/config/workflows, and production-facing unit/real-WP/Playwright regressions. No broad controller rewrite and no gate weakening.

**Spec:** `docs/superpowers/specs/2026-09-03-final-release-blockers-design.md`

## Global constraints

- Never delete/skip tests to go green.
- Never add `continue-on-error` to a release gate.
- Keep `failOnWarning=true`, PHPStan level, coverage thresholds, Plugin Check strictness, and security audits intact.
- Keep the repository private unless the owner explicitly decides otherwise.
- Final evidence must all name the same exact SHA and exact ZIP.

### Task 1 - All Languages server contract

- [ ] Add failing unit tests for `SScribe_Language_Request`: Preview/Start `__all__` -> `''`; count actions preserve `__all__`; real language unchanged.
- [ ] Add failing real-WordPress tests invoking actual Preview and Start AJAX with `__all__` and a self-created published page fixture.
- [ ] Observe RED in PR CI.
- [ ] Add `includes/class-sscribe-language-request.php` and register it before production AJAX handlers.
- [ ] Re-run targeted unit + real-WP tests to GREEN.
- [ ] Commit `fix(export): normalize all-language requests centrally`.

### Task 2 - Client preflight / labels / Debug state

- [ ] Add failing JS contract tests and Playwright specs for All Languages Preview->Start, hard preflight blocking, warning confirmation, and Debug save transitions.
- [ ] Observe RED.
- [ ] Add `admin/js/sscribe-release-hardening.js`, loaded only on the SScribe admin page after `sscribe-admin`.
- [ ] `__all__` label -> localized `All Languages`.
- [ ] OK preflight proceeds; warning requires explicit Continue; error or `can_proceed=false` cannot start.
- [ ] Debug save without a Debug toggle cannot be mistaken for a state transition; genuine OFF->ON still refreshes.
- [ ] Re-run unit + Playwright to GREEN.
- [ ] Commit `fix(admin): enforce release-critical UI contracts`.

### Task 3 - Fatal attribution

- [ ] Add failing tests for external non-SScribe AJAX fatal rejection and SScribe AJAX acceptance.
- [ ] Restrict `SScribe_Fatal_Handler` external-file scope to scalar `action` values beginning `sscribe_`.
- [ ] Re-run targeted/full unit tests.
- [ ] Commit `fix(logging): scope fatal attribution to SScribe AJAX`.

### Task 4 - PHPUnit / coverage separation

- [ ] Add failing config contract test.
- [ ] Remove coverage reporting from default `phpunit.xml` without changing strict warning/risky settings.
- [ ] Create `phpunit-coverage.xml` and point `composer test:coverage` to it.
- [ ] Re-run Version Sync targeted PHPUnit under `coverage:none` and coverage job under Xdebug.
- [ ] Commit `fix(ci): separate strict tests from coverage reporting`.

### Task 5 - E2E dependency ordering and audits

- [ ] Extend E2E workflow contract test first: dev Composer dependencies present before Strauss; Composer locked audit and npm high audit mandatory.
- [ ] Observe RED against current `--no-dev` workflow.
- [ ] Fix workflow ordering; do not weaken artifact dev-file exclusions.
- [ ] Inspect/remediate exact npm high advisory if present.
- [ ] Require Playwright to actually start and run full suite.
- [ ] Commit `fix(e2e): make browser certification executable`.

### Task 6 - WordPress 6.1 / PHP 8.2 minimum

- [ ] Change minimum-version tests to expect WordPress 6.1 first and observe RED.
- [ ] Update plugin header, readme, runtime guard and verifier from 6.0 to 6.1.
- [ ] Add exact WP 6.1 + PHP 8.2 real-WP workflow.
- [ ] If real runtime fails, raise the floor instead of weakening tests.
- [ ] Commit `fix(compat): test and declare WordPress 6.1 minimum`.

### Task 7 - Release leakage scanner

- [ ] Add failing test proving normal typography is accepted and zero-width/assistant-workspace/explicit assistant boilerplate is rejected.
- [ ] Replace typography heuristics in `scripts/check-ai-artifacts.php` with release-leakage checks.
- [ ] Re-run scanner and Security Audit.
- [ ] Commit `fix(audit): detect release leakage without typography false positives`.

### Task 8 - Plugin Check fail-closed local audit

- [ ] Add failing contract test that a missing local Plugin Check testbench increments release-audit failure.
- [ ] Change `bin/release-audit.sh` missing-testbench path from SKIP to FAIL.
- [ ] Re-run contract tests.
- [ ] Commit `fix(release): make Plugin Check mandatory`.

### Task 9 - Source/build transparency

- [ ] Inspect `.distignore`, build script, readme, transparency verifier and ZIP content rules.
- [ ] Add failing tests for truthful/private-source wording and chosen self-contained reproducibility package.
- [ ] Ship only the minimum build-source inputs needed by reviewers if compatible with package policy; otherwise preserve a clearly documented external owner blocker.
- [ ] Build/certify exact ZIP and update readme truthfully.
- [ ] Commit `fix(release): make build source reproducible for reviewers`.

### Task 10 - Exact release verification

- [ ] All CI jobs green on one exact SHA; investigate every skipped/cancelled mandatory job.
- [ ] Real WP minimum/current matrices green.
- [ ] Full Playwright green including new regressions.
- [ ] Composer/npm/security audits green.
- [ ] PHPStan, PHPCS, PHPUnit, coverage, i18n, accessibility green.
- [ ] Exact ZIP checksum, clean install/activation/export smoke and official Plugin Check green.
- [ ] Refresh active evidence to the exact final SHA; mark stale historical evidence as such.
- [ ] Re-audit final branch diff against `develop` and baseline.
- [ ] Run verification-before-completion and finishing-a-development-branch before any release-ready claim.