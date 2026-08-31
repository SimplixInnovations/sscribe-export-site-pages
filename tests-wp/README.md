Real WordPress Integration Testbench
====================================

Tests in this directory exercise the plugin against a real WordPress core,
the real wpdb (via the SQLite Database Integration drop-in), real
filesystem permissions, and real cron / AJAX / activation code paths.
The fake-wp bootstrap in `../tests/` continues to cover the lower-level
unit / security / integration suites; this directory covers the highest-
risk subsystems that the fake-wp stubs can never reach (dbDelta, role
capability granting, admin-AJAX dispatch, cron firing, private-storage
migration).

Install
-------

    composer test:wp:install

This downloads WordPress core + the WP-PHPUnit test suite into
`tests-wp/_wordpress/` and `tests-wp/_wordpress-tests-lib/`, drops
the SQLite Database Integration plugin's `db.php` into
`tests-wp/_wordpress/wp-content/db.php`, and runs the testbench smoke
install.

Run
---

    composer test:wp

Runs only the WordPress testsuite (config: `phpunit-wp.xml`).

    composer test

Runs the legacy fake-wp bootstrap suites (Unit / Integration / Security).

    composer test:all

Runs everything (fake-wp + real-WordPress).

Reset
-----

    composer test:wp:uninstall

Removes the cached WordPress testbench (and the SQLite drop-in).

Coverage in this suite
----------------------

| File                                            | What it exercises                                         |
|-------------------------------------------------|-----------------------------------------------------------|
| `SScribe_Activation_Test.php`                   | dbDelta schema, role caps, cron schedules, idempotency     |
| `SScribe_AJAX_Endpoints_Test.php`               | All 15 `wp_ajax_sscribe_*` endpoints (cap + nonce + body) |
| `SScribe_Cron_Test.php`                         | Exports/sessions/audit cleanup handlers + cron lifecycle  |
| `SScribe_Private_Storage_Migration_Test.php`    | Migration + resolver guards (public root, symlink, sticky) |
| `SScribe_WP_TestCase.php`                       | Base case wiring (real WP, SQLite drop-in)                |
| `SScribe_WP_Ajax_TestCase.php`                  | AJAX dispatch helper                                      |

Platform notes
--------------

- **PHP extensions.** `composer test:wp` enables `sqlite3` and
  `pdo_sqlite` automatically via `-d` flags. Both extensions must be
  available on disk; if not, install them and rerun.
- **Windows skips.** The symlink and 01777 sticky-bit tests are skipped
  on Windows — neither operation reflects POSIX semantics on the
  Win32 platform. The corresponding production code paths are exercised
  by every other test (the `posix_geteuid()` short-circuit and the
  `is_link()` guard hit on every resolution).
- **Run order matters for SSCRIBE_PRIVATE_STORAGE_DIR.** PHP does not
  provide an API to undefine a constant; once one test sets
  `SSCRIBE_PRIVATE_STORAGE_DIR`, every following test must work with
  whatever path the previous test left. The storage tests handle this by
  intentionally relying on the locked-in value (test 2 verifies the
  resolver rejects a missing constant path).
