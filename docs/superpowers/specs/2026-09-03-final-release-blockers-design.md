# SScribe 2.0.0 Final Release Blockers Design

**Baseline:** `bd0e3bf39fb99e9621c665e6a2e5c6b4a8fd4bcb`

**Goal:** Close the independently verified release blockers without broad refactoring, preserve the improvements already landed, and make the exact release candidate certifiable by runtime tests, real WordPress tests, E2E, Plugin Check, and exact-package gates.

## 1. Non-negotiable release invariants

1. One visible selection must have one server interpretation across Count, Preview, Preflight, Start, Session, Batch, Summary, and final export.
2. `__all__` is a transport sentinel only. It may remain visible in browser/count payloads, but query-bearing server operations must translate it to the internal empty-language representation before WPML validation/querying.
3. Preflight `status=error` or `can_proceed=false` is a hard stop. Only warning-level diagnostics with `can_proceed=true` may offer an explicit Continue action.
4. Normal PHPUnit runs keep `failOnWarning=true`. Coverage reporting is moved to a coverage-specific config so no-coverage jobs do not fail because a coverage reporter was requested without a driver.
5. E2E build preparation must install the dev-only Strauss toolchain before running `composer vendor:prefix`; the final ZIP must still exclude dev-only packages/files.
6. No release gate may report success after silently skipping a mandatory submission check.
7. WordPress/PHP metadata must describe a supported pair that is actually executed in CI. Candidate minimum: WordPress 6.1 + PHP 8.2.
8. Fatal operational logging may attribute external files to SScribe only when the current AJAX action is an SScribe action, not merely because WordPress is doing AJAX.
9. Existing source/build transparency evidence must not claim inaccessible public source. Until repository visibility is explicitly changed, the deployed plugin must include the practical Composer/build-source material WordPress reviewers need, or release remains blocked as an external governance prerequisite.
10. No existing test is deleted or weakened to make CI green.

## 2. Language request boundary

Create `SScribe_Language_Request` as a small request-boundary utility. It registers priority-1 callbacks only for query-bearing actions that currently reject `__all__`: `wp_ajax_sscribe_get_export_preview` and `wp_ajax_sscribe_start_export`.

For those actions only, a POSTed language equal to `__all__` is translated to `''` in both `$_POST` and `$_REQUEST` before the existing handlers execute. Real language codes and invalid values are left untouched so existing validation remains authoritative. Count endpoints are intentionally not normalized because their response contract uses `__all__` as a key.

This avoids duplicating sentinel special cases in multiple controllers while preserving existing controller behavior.

## 3. Admin client hardening module

Add `admin/js/sscribe-release-hardening.js`, loaded after `sscribe-admin` and `sscribe-debug-console` on the SScribe admin page.

It is deliberately narrow and uses the already-public `window.SScribe` / `window.SScribeDebugConsole` extension seams:

- `getLanguageLabel('__all__')` returns the localized `All Languages` label through `wp.i18n.__`; all other labels delegate to the original implementation.
- `runPreflightCheck` keeps the current request/retry/nonce logic but changes the success decision:
  - `ok` + `can_proceed !== false` -> proceed;
  - `warning` + `can_proceed !== false` -> render existing preflight banner and require explicit Continue;
  - `error` or `can_proceed === false` -> render the existing detailed banner, remove/disable the Continue action, clear preparing/busy state, and do not start an export.
- Before delegating Debug `saveSettings`, if `_previousDebugEnabled` is undefined, seed it from the current checkbox. This prevents a non-toggle save from being misclassified as a Debug state transition while preserving the existing OFF->ON comparison when the user actually toggled.

No existing 160 KB admin controller behavior is copied except the small preflight request decision body required to preserve retry semantics.

## 4. Fatal attribution

Change `SScribe_Fatal_Handler::is_in_scope()` so plugin-root files are always in scope, while external files are accepted only when `wp_doing_ajax()` is true **and** the current `action` begins with `sscribe_`. Empty/malformed/non-scalar actions are out of scope.

## 5. PHPUnit and coverage configuration

The default `phpunit.xml` keeps strict warnings/risky behavior but no longer requests coverage reports. Create `phpunit-coverage.xml` with the coverage report block. Update `composer test:coverage` to run with `-c phpunit-coverage.xml`.

This makes targeted no-coverage integration commands deterministic without weakening warnings.

## 6. E2E build and supply-chain gate

Update `.github/workflows/e2e.yml` to:

- use `composer install --no-interaction --no-progress --no-scripts` (dev dependencies included so Strauss exists);
- run `composer audit --locked --format=plain --abandoned=fail`;
- run `npm audit --audit-level=high` after `npm ci`;
- build/prefix/release-prepare only after audits succeed;
- retain full Playwright on every PR/push.

If npm audit reports a high-severity advisory, update only the dependency chain needed to remove that advisory and commit the regenerated lockfile; do not blanket-ignore audit output.

## 7. Minimum supported WordPress contract

Change `Requires at least` and the runtime guard from 6.0 to 6.1 in the plugin main file and readme. Update the minimum-version verifier to assert 6.1 / PHP 8.2. Add an exact real-WordPress CI workflow entry that installs and runs the real WP suite on WordPress 6.1 + PHP 8.2 in addition to the existing modern matrix.

The floor is not considered closed until that job passes.

## 8. AI/workspace artifact gate

`check-ai-artifacts.php` must stop treating typography (em/en dashes, curly quotes, ellipsis) as proof of AI-generated code. Replace those false-positive rules with release-relevant leakage checks: assistant/workspace directories, prompt/transcript file patterns, and explicit assistant boilerplate such as `As an AI language model` in shipped source. Continue to fail on invisible zero-width characters where they create source-integrity risk.

The gate remains strict; its signal becomes meaningful.

## 9. Plugin Check / release audit

`bin/release-audit.sh` may not append a successful summary when official Plugin Check could not run. If the expected local Plugin Check testbench is unavailable, record a failed `Plugin-Check` gate and exit non-zero. CI remains the portable authoritative Plugin Check environment.

## 10. Source/build transparency

WordPress permits either public maintained source/build tooling or inclusion of source/build tooling in the deployed plugin. The repository is currently private, so a readme claim that it is the public development location is not valid evidence.

Implementation will prefer self-contained submission transparency: ship `composer.json`, `composer.lock`, the minimal Strauss/fixup build scripts required to reproduce vendor prefixing, and `docs/BUILD_TRANSFORMATIONS.md` if the current package rules permit that without introducing executable runtime risk. Package certification rules must explicitly allow only those named development-source files. If that proves incompatible with the existing package policy, keep the repository private and mark public-source availability as an external release blocker requiring an explicit owner decision; do not silently publish the repository.

## 11. Tests required before release

Automated regressions must cover:

- `__all__` is normalized for Preview and Start but not count endpoints;
- All Languages Preview succeeds and Start reaches session creation under a real WordPress testbench;
- `getLanguageLabel('__all__')` never renders `__ALL__`;
- preflight error cannot call `proceedWithExport`;
- preflight warning requires explicit Continue;
- Debug save without a Debug toggle does not trigger a reload; OFF->ON does;
- unrelated AJAX fatal is not attributed to SScribe; SScribe AJAX fatal is;
- default PHPUnit config does not request coverage; coverage config does;
- E2E workflow installs Strauss before prefixing and runs both Composer/npm audits;
- WordPress 6.1 + PHP 8.2 real testbench passes;
- release audit fails when Plugin Check is unavailable;
- exact submission ZIP passes Plugin Check and clean-install/export smoke.

## 12. Release completion rule

A candidate is releaseable only when a new exact SHA has all relevant workflows green, the exact ZIP is produced from that SHA, the ZIP checksum is recorded, official Plugin Check passes against that ZIP, and no release evidence document claims a different SHA is the current certified build.
