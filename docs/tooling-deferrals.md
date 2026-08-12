---
title: Tooling Deferrals
description: Tooling versions intentionally held back, with the reasons and the trigger conditions that would justify upgrading.
---

# Tooling Deferrals

This document records the QA / static-analysis tool versions that the
project deliberately does not track to the latest upstream, why each one
is held back, and what would have to change before it makes sense to
move forward. The decision is reviewed once per release cycle; the
table is the canonical source.

## Current vs. available versions

| Tool          | Project version | Latest upstream (2026-08) | Status     |
| ------------- | --------------- | ------------------------- | ---------- |
| PHP_CodeSniffer | ^3.13           | 4.x-dev (in development)  | Deferred   |
| PHPUnit         | ^11.0 (LTS)     | 13.x (current)            | Deferred   |

## PHPCS: held at 3.13, not tracking 4.x

**Current state.** The project ships `squizlabs/php_codesniffer:
^3.13` and `wp-coding-standards/wpcs` for the WordPress-Extra
standard. CI runs `composer cs` on every push and the build script
calls PHPCS as a gate before zipping.

**Why deferred.**

1. PHPCS 4.x is still in development as of 2026. The Composer
   constraint `^3.13` is the latest stable major; pulling in `^4.0`
   would currently resolve to a pre-release tag.
2. The WordPress coding standard (WPCS) has not been updated for
   PHPCS 4. Upgrading the sniffer without a matching WPCS release
   would either fail to install or silently drop every WPCS rule.
3. PHPCS 4 reorganises the internal tokenizer API. The handful of
   PHPCS-internal customisations the project relies on (the
   `phpcs:ignore` annotations preserved by the build strip pass) are
   written against the 3.x token names. A 4.x upgrade needs a sweep
   to confirm those still resolve.

**What would justify upgrading.**

- WPCS publishes a release that declares `phpcs: ^4.0` compatibility
  in its own `composer.json`.
- The project finishes the build-time strip-comments pass refactor so
  the strip logic no longer needs to identify PHPCS tokens by name.

**Risk of staying.** Negligible for the next two quarters. PHPCS 3.13
receives security fixes; no CVEs in the 3.x line have surfaced in the
last 12 months.

## PHPUnit: held at 11.x LTS, not tracking 12/13

**Current state.** The project ships `phpunit/phpunit: ^11.0`. The
test suite runs 776 tests with 2,511 assertions and zero failures. CI
runs the suite on every push.

**Why deferred.**

1. PHPUnit 11 is the current long-term-support line. LTS guarantees
   patch releases through 2027; that covers the WP.org submission and
   the first year of active maintenance.
2. PHPUnit 12 introduced several breaking changes that would touch the
   test suite:
   - removed the XML schema support for the `coverage` extension's
     old config block;
   - tightened deprecation handling so `setMethods()` and the
     `expects()`-without-`->method()` shorthand trigger hard failures
     rather than warnings;
   - dropped PHP 8.1 as a supported runtime; the project's CI matrix
     still includes 8.1 for the support matrix WP.org requires.
3. PHPUnit 13 is the bleeding edge; it drops PHP 8.2 as well and
   tightens the same deprecations further. No LTS guarantee, no
   backport channel.
4. The current suite already triggers 7 PHPUnit deprecation warnings
   and 5 PHPUnit-internal deprecations. Upgrading the major version
   would convert the warnings into hard failures and force a sweep
   to rewrite or remove the deprecated calls before the gate could
   turn green again.

**What would justify upgrading.**

- PHP 8.1 is officially dropped from the WP.org minimum-supported
  version and the CI matrix can drop the 8.1 job.
- The suite is brought to zero deprecation warnings under the current
  PHPUnit 11 (the `OK, but there were issues` line in the test
  summary is the canary).
- PHPUnit 11 reaches end-of-life and stops receiving security patches.

**Risk of staying.** Low. LTS backports land regularly. The known
deprecations do not affect test correctness; they are forward-compat
markers, not correctness bugs.

## Review cadence

These decisions are re-examined at every release-candidate cut. The
reviewer is expected to:

1. Re-read the current upstream release notes for PHPCS and PHPUnit.
2. Re-read this document.
3. Update the table at the top and adjust the per-tool rationale if
   any of the listed trigger conditions have been met.

If a reviewer wants to upgrade outside the cadence, the upgrade PR
must include a one-paragraph note in the PR description explaining
which trigger condition is now satisfied and what was done about it.