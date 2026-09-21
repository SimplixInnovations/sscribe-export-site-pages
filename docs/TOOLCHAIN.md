# SScribe Tested Toolchain

Lock files (`composer.lock`, `package-lock.json`) are authoritative for
package versions. This document pins the tool layer around them: the
versions the workflow is validated against. Do not upgrade blindly;
upgrades are isolated, reviewed commits with full gate evidence.

## Validated versions

| Tool | Validated | Expectation |
|------|-----------|-------------|
| PHP | 8.2 – 8.5 (CI matrix), 8.5.10 local | `>= 8.2` per composer.json |
| Release-validation PHP | 8.5 (local gate runs) | Any of 8.2 – 8.5 |
| Composer | 2.10.3 | 2.x |
| Node | 24 (CI), 26.7.0 (local dev) | `>= 24.0.0` per engines |
| npm | bundled with Node (12.0.2 local) | bundled with Node |
| @playwright/test | 1.62.1 (package-lock.json) | `^1.62.1` |
| Git | 2.55 (Windows) | any recent 2.x |
| PowerShell | 7.4 / 5.1-compatible syntax | 5.1+ |
| WordPress (plugin) | 6.1 – 7.1 (`readme.txt`) | 6.1+ |
| WordPress (testbench) | `latest` / `previous` via wordpress.org API plus explicit 6.1 floor | rolling compatibility harness; resolved at install time |
| wp-phpunit test suite | version-matched `wp-phpunit/wp-phpunit` `tree-{major.minor}` branch | rolling compatibility harness; resolved from the selected WordPress version |
| SQLite integration (testbench + E2E) | 3.0.2 from WordPress.org | pinned external harness input; same version in PHP testbench and native E2E runtime |

## Deliberately not pinned

- WordPress core `latest` / `previous` remain rolling compatibility probes. The SQLite integration is deliberately pinned to a reviewed WordPress.org release so CI does not depend on an unauthenticated GitHub `releases/latest` request or silently change database semantics between candidate SHAs.
- No `.node-version` and no `packageManager` field: CI pins Node 24
  via `setup-node`; adding a second pin source would conflict rather
  than help. `engines: >= 24.0.0` plus this document are the contract.

## Platform notes

- Windows development requires Developer Mode for symlink-guard tests
  (`mklink`); `dev.ps1 doctor` reports the state as `Symlinks`.
- `ext-posix` does not exist on Windows: POSIX-only ownership branches
  stay Linux-covered by design (see coverage gate); thresholds are
  identical on every platform.
- Required PHP extensions are derived from `composer
  check-platform-reqs` (currently `ext-zip`, `ext-zlib`) plus the
  testbench/E2E set the doctor checks (`curl`, `dom`, `fileinfo`,
  `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3`, `phar`).
  Xdebug is required only for `dev.ps1 coverage`.
