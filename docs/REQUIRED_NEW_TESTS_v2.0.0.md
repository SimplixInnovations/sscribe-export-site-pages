# Required New Tests — v2.0.0

## Why this exists

Phase 68 of the v2.0.0 release-hardening spec mandates regression
coverage for 24 specific scenarios that were identified during the
v1.2.0 → v2.0.0 hardening cycle. A regression that drops one of
these tests would re-open a release-blocking failure mode.

This document is the **canonical registry** of required Phase 68
tests. The companion verifier
`scripts/verify-phase-68-test-coverage.php` walks the test tree and
asserts each entry below has a matching test method in
`tests/Integration/` or `tests/Unit/`. The companion PHPUnit
integration test pins the verifier at the PHPUnit boundary.

## Canonical registry

Each row records the required topic, the canonical signature the
verifier searches for, and the file + method where the coverage
lives. The verifier matches on case-insensitive substring against
test method names + docblocks.

| #  | Required topic                          | Signature phrase                          | Coverage file / method                                                       |
|----|------------------------------------------|-------------------------------------------|------------------------------------------------------------------------------|
| 1  | Stale count response                    | `stale count response`                    | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_stale_count_response_is_discarded_by_session_consumer` |
| 2  | Aborted count XHR                       | `aborted count xhr`                       | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_aborted_count_xhr_does_not_set_session_state` |
| 3  | Delayed stale retry                     | `delayed stale retry`                     | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_delayed_stale_retry_is_ignored_by_latest_count_consumer` |
| 4  | `__all__` count                          | `__all__ count`                           | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_all_languages_sentinel_is_accepted_in_count_response` |
| 5  | `__all__` Preview                        | `__all__ preview`                          | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_all_languages_sentinel_is_accepted_in_preview_response` |
| 6  | `__all__` export start                   | `__all__ start`                            | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_all_languages_sentinel_is_accepted_in_start_export` |
| 7  | All Types Preview                       | `all types preview`                       | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_all_types_card_renders_preview_count` |
| 8  | Summary / Preview equality              | `summary preview equality`                | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_summary_card_count_matches_preview_card_count` |
| 9  | Preflight retry method                  | `preflight retry method`                  | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_preflight_retry_uses_post_method` |
| 10 | 429 preflight                           | `429 preflight`                            | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_preflight_handles_429_rate_limited` |
| 11 | 503 preflight                           | `503 preflight`                            | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_preflight_handles_503_limiter_contention` |
| 12 | Terminal 500 preflight                  | `terminal 500 preflight`                   | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_preflight_terminal_500_emits_request_reference` |
| 13 | Clear-session terminal 500              | `clear-session terminal 500`               | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_clear_session_terminal_500_emits_request_reference` |
| 14 | Exact Retry-After behavior              | `retry-after`                              | `tests/Unit/SScribe_Rate_Limit_Response_Test.php::test_source_emits_retry_after_header` |
| 15 | Finalize terminal 500                   | `finalize terminal 500`                    | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_finalize_terminal_500_emits_request_reference` |
| 16 | Debug OFF→ON refresh                    | `debug off→on refresh`                    | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_debug_off_to_on_refresh_refetches_logs` |
| 17 | Debug ERROR no-match                    | `debug error no-match`                    | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_debug_error_no_match_shows_friendly_message` |
| 18 | Operational logger after-init flush     | `operational logger after-init flush`      | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_operational_logger_flushes_after_init_completes` |
| 19 | Fatal logger flush                      | `fatal logger flush`                      | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_fatal_logger_flushes_on_shutdown` |
| 20 | Redis rate-limit counter                | `redis rate-limit counter`                | `tests/Unit/SScribe_Export_Rate_Limiter_Object_Cache_Test.php::test_persistent_cache_path_advances_counter_monotonically` |
| 21 | Sticky-bit ownership                    | `sticky-bit ownership`                    | `tests/Integration/SScribe_Sticky_Bit_Regression_Test.php::test_foreign_owned_01777_with_sticky_is_accepted` |
| 22 | Exact package vendor-prefixed bootstrap | `exact package vendor-prefixed bootstrap`  | `tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php::test_exact_package_vendor_prefixed_bootstrap_loads_runtime_classes` |
| 23 | E2E auto-download OFF/ON                | `auto-download`                            | `tests/Unit/SScribe_Auto_Download_Test.php::test_auto_download_defaults_to_false` |
| 24 | Language DOM sibling structure          | `language dom sibling`                    | `tests/Unit/SScribe_Language_DOM_Test.php::test_all_language_radios_share_the_same_name_attribute` |

## Why this is a contract

The v1.2.0 release notes called out the v1.2.0 deferred items. Each
row above addresses one of those items or one of the new failure
modes that surfaced during the v2.0.0 hardening cycle:

- Rows 1–3: count-XHR stale / aborted / retry-replay paths.
- Rows 4–7: `__all__` and All Types preview / export entry points.
- Row 8: summary / preview must agree (selection invariant).
- Rows 9–13: preflight HTTP error handling (429 / 503 / 500).
- Row 14: rate-limit Retry-After header propagation.
- Row 15: finalize 500 handling.
- Rows 16–17: Debug console UX contracts.
- Rows 18–19: operational + fatal logger durability.
- Row 20: persistent object cache (Redis) rate-limit counter.
- Row 21: private storage sticky-bit ownership.
- Row 22: shipped (vendor-prefixed) runtime bootstrap path.
- Row 23: E2E auto-download toggle (off by default).
- Row 24: language DOM sibling structure (radio name attribute).

## How an independent auditor verifies this

```bash
# 1. Run the coverage gate.
composer test:phase-68-test-coverage

# 2. Cross-check the test count.
jq '.coverage_summary' dist/phase-68-test-coverage-manifest.json

# 3. Run the coverage integration test.
vendor/bin/phpunit tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php
```

A green `composer test:phase-68-test-coverage` + 24/24 topics covered
= the Phase 68 regression contract is intact.

## What this contract does NOT cover

- **Tests not yet migrated to the v2.0.0 layout** — older
  Phase 1–33 tests live in `tests/Unit/` and `tests/Integration/`
  under their existing names. They are NOT in the registry above;
  they are covered by their own dedicated integration tests.
- **Coverage thresholds** — Phase 41 (coverage) is a separate gate
  (`composer test:coverage:check`); Phase 68 only asserts the
  presence of specific tests, not the line coverage those tests
  achieve.
- **Manual / runtime tests** — Phase 69 is a separate gate for
  manual runtime testing that is documented in
  `docs/MANUAL_RUNTIME_TESTS_v2.0.0.md`.

## Change log

- 2026-09-03: Initial Phase 68 registry + verifier + PHPUnit pin.
  All 24 topics mapped to existing or new tests.
