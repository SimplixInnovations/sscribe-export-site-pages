# SScribe 2.0.0 Final Release Blockers Design

**Original audit baseline:** `bd0e3bf39fb99e9621c665e6a2e5c6b4a8fd4bcb`
**Implementation base:** `c8026092a2e97c0bb8a61d4e8c4af3ab7e38aa09`

**Goal:** Close the independently verified release blockers without broad refactoring, preserve the later Phase 77/78 improvements already landed on `develop`, and make the exact release candidate certifiable by runtime tests, real WordPress tests, E2E, Plugin Check, and exact-package gates.

## Non-negotiable invariants

1. One visible selection has one server interpretation across Count, Preview, Preflight, Start, Session, Batch, Summary, and final export.
2. `__all__` is a transport sentinel only. Query-bearing server operations translate it to the internal empty-language representation before WPML validation/querying; count responses may retain it as a key.
3. Preflight `status=error` or `can_proceed=false` is a hard stop. Only warning-level diagnostics with `can_proceed=true` may offer explicit Continue.
4. PHPUnit keeps `failOnWarning=true`; coverage reporting moves to a coverage-specific config.
5. E2E build preparation installs the dev-only Strauss toolchain before `composer vendor:prefix`; final package rules still exclude dev-only code.
6. No mandatory release gate may report success after being skipped.
7. WordPress/PHP metadata must describe a supported pair that is actually executed in CI. Candidate floor: WordPress 6.1 + PHP 8.2.
8. Fatal operational logging may attribute external files to SScribe only for an `sscribe_*` AJAX action, not merely because WordPress is doing AJAX.
9. Source/build transparency must be truthful. The private repository is not described as a public source location. Prefer self-contained build-source material in the submission package; otherwise retain an explicit external release blocker rather than silently publishing the repository.
10. Existing tests/gates are not deleted, bypassed, or weakened to make CI green.

## Server language boundary

Create `SScribe_Language_Request`, registering priority-1 callbacks for `wp_ajax_sscribe_get_export_preview` and `wp_ajax_sscribe_start_export`. For those actions only, `language=__all__` becomes `language=''` in both `$_POST` and `$_REQUEST` before existing handlers run. Real codes and invalid values are untouched. Count endpoints are intentionally not normalized.

## Admin client hardening

Add a small `admin/js/sscribe-release-hardening.js` using the existing public `window.SScribe` and optional `window.SScribeDebugConsole` seams:

- `getLanguageLabel('__all__')` renders localized `All Languages`.
- Preflight decision is fail-closed: OK proceeds, warning requires explicit Continue, error/non-proceedable cannot call Start.
- A Debug settings save with no Debug toggle seeds the previous-state comparator from the current checkbox so it cannot trigger a false transition reload; a real OFF->ON change still refreshes state.

## Fatal attribution

Plugin-root fatal files remain in scope. External fatal files are in scope only when WordPress is doing AJAX and the current request action is a scalar sanitized value beginning with `sscribe_`.

## PHPUnit / coverage

Default `phpunit.xml` remains strict but contains no coverage reporter. `phpunit-coverage.xml` carries the coverage reports, and `composer test:coverage` explicitly uses it.

## E2E and supply chain

The E2E workflow installs Composer dev dependencies, runs Composer locked audit and npm high-severity audit, then prefixes/builds and executes the full Playwright suite. High-severity npm advisories are remediated at the dependency chain rather than ignored.

## Minimum compatibility

Raise the declared/runtime WordPress minimum from 6.0 to 6.1 while retaining PHP 8.2. Add an exact real-WordPress CI job for WordPress 6.1 + PHP 8.2. If it fails for genuine compatibility reasons, raise the floor instead of weakening the test.

## Release artifact leakage scanner

Replace typography heuristics in `check-ai-artifacts.php` with real release-leakage checks: invisible zero-width characters, assistant-workspace/prompt/transcript artifacts, and explicit assistant boilerplate. Ordinary em/en dashes, smart quotes, and ellipsis are not evidence of generated code.

## Plugin Check / release audit

`bin/release-audit.sh` fails closed when official Plugin Check cannot run. It may not append `All gates green` after a missing mandatory testbench.

## Source/build transparency

Prefer a self-contained submission package containing the minimum Composer/build-source material needed to reproduce prefixed generated code, with exact package rules allowing only those named build inputs. If that cannot be done cleanly, keep the repo private and record public-source publication as an external owner action; never represent the private URL as publicly accessible evidence.

## Mandatory regressions

Automated tests must prove:

- Preview/Start normalize `__all__`, count endpoints do not.
- All Languages Preview and Start succeed under a real WordPress testbench.
- `__all__` never renders as `__ALL__`.
- hard preflight cannot start export; warning requires explicit Continue.
- Debug non-toggle save does not reload; real toggle does.
- unrelated AJAX fatal is not attributed to SScribe; SScribe AJAX fatal is.
- default PHPUnit does not request coverage; coverage config does.
- E2E installs Strauss before prefixing and runs Composer/npm audits.
- WordPress 6.1 + PHP 8.2 real test suite passes.
- release audit fails when Plugin Check is unavailable.
- exact final ZIP passes Plugin Check, clean install, activation and export smoke.

## Completion rule

A candidate is releaseable only when one new exact SHA has all mandatory workflows green, the exact ZIP is produced from that SHA, its checksum is recorded, official Plugin Check passes against that artifact, and active release evidence names that same SHA.