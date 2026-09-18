# SScribe Export Site Pages

Export WordPress pages to DOCX, PDF, HTML, or Markdown with multilingual and RTL support.

> Maintainer/developer README. The WordPress.org listing copy lives in `readme.txt`; this file documents how to develop, test, and release the plugin.

## Shortest path (Windows)

```powershell
.\scripts\dev.ps1 doctor
.\scripts\dev.ps1 setup
.\scripts\dev.ps1 verify
.\scripts\dev.ps1 build
```

`scripts/dev.ps1` is the canonical Windows developer interface. See [Windows setup](#windows-setup) and [Fresh-machine setup](#fresh-machine-setup).

## Status

Active development happens on `develop`. See [Branch policy](#branch-policy).

## Supported WordPress / PHP

| Requirement | Version |
|-------------|---------|
| WordPress   | 6.1+ (tested up to 7.1) |
| PHP         | 8.2+ |
| PHP memory  | 256 MB recommended for large PDF exports |
| Node        | 24+ (E2E tooling only) |

## Architecture overview

- `includes/` — plugin runtime: exporters (DOCX/PDF/HTML/Markdown), batch processor, session handling, ZIP handler, image processor, diagnostics.
- Security boundary (90%+ coverage enforced): private-storage root (`class-sscribe-private-storage.php`), filesystem containment (`class-sscribe-filesystem.php`), path-scope validation (`class-sscribe-security.php`), audit-trail writer (`class-sscribe-audit-trail.php`).
- `admin/` — WP Admin UI (settings, history, debug console).
- Private archives and logs live outside public web directories, guarded by capability, ownership, nonce, and single-use download-token checks.
- Third-party libraries are vendor-prefixed via Strauss (`vendor-prefixed/`) so the shipped ZIP never collides with other plugins. See `docs/BUILD_TRANSFORMATIONS.md`.

## Repository layout

```text
admin/            WP Admin UI (PHP, CSS, JS)
assets/           Fonts, icons, images
bin/              Thin POSIX wrappers (canonical logic lives in scripts/*.php)
docs/             Release evidence, policies, command reference
includes/         Plugin runtime
languages/        POT + translations
scripts/          Canonical PHP/JS tooling (verifiers, builders) + dev.ps1
stubs/            Static-analysis stubs
tests/            PHPUnit suites: Unit, Integration, Security
tests-e2e/        Playwright specs (e2e, a11y) + native WP testbed
tests-js/         JS unit tests
tests-wp/         Real-WordPress (SQLite) testbench
```

Generated, never committed: `vendor/`, `node_modules/`, `dist/`, `coverage/`.

## Fresh-machine setup

```powershell
git clone https://github.com/SimplixInnovations/sscribe-export-site-pages.git
cd sscribe-export-site-pages
git checkout develop
.\scripts\dev.ps1 doctor
.\scripts\dev.ps1 setup
.\scripts\dev.ps1 verify
.\scripts\dev.ps1 build
```

No undocumented manual step should be required. If one is, file it as a bug against `scripts/dev.ps1` and the docs.

## Windows setup

Prerequisites: Windows 11, PowerShell 5.1+, Git, PHP 8.2+, Composer, Node 24+ with npm. Details and checks: `.\scripts\dev.ps1 doctor`.

```powershell
.\scripts\dev.ps1 setup
```

`setup` is deterministic from lock files only: `composer install --no-interaction --prefer-dist`, `composer vendor:prefix`, `npm ci`, `npx playwright install chromium`, then `composer validate --strict`, `composer audit --locked`, `npm audit`. No `composer update` / `npm update`.

## Dependency installation

Same as setup, explicitly:

```powershell
composer install --no-interaction --prefer-dist
composer vendor:prefix   # generate gitignored Strauss tree (SScribeVendor\*) the suite loads
npm ci
npx playwright install chromium
```

## Testing

```powershell
composer test            # PHPUnit, random order, excludes release-contract group
.\scripts\dev.ps1 test   # same via the canonical entry point
```

- PHPUnit suites: `Unit`, `Integration`, `Security` (`phpunit.xml`).
- Tests run in **random order**; every test must be order-independent (no shared temp state, no leaked options/constants).
- Targeted seeds: `composer test -- --random-order-seed=<n>`.

## Real WordPress testing

```powershell
composer test:wp:install   # provision SQLite-backed WP testbench (cross-platform PHP port)
composer test:wp           # run Real-WP suite (phpunit-wp.xml)
composer test:wp:uninstall # remove the testbench
```

Requires no global WordPress install and leaves no residue outside the project-owned cache (see `.\scripts\dev.ps1 clean -Testbench`).

## Browser / E2E testing

```powershell
npm run test:e2e:smoke   # fast smoke (~1 min)
npm run test:e2e:full    # full suite incl. accessibility scans
.\scripts\dev.ps1 e2e    # canonical wrapper (verifies Node, Playwright, Chromium; cleans up after)
```

The E2E bed boots a native PHP CLI server with WordPress + SQLite and drives Chromium via `@playwright/test`. Details: `tests-e2e/README.md`.

## Coverage

```powershell
.\scripts\dev.ps1 coverage   # requires Xdebug; runs composer test:coverage:full
```

Enforced thresholds (unchanged per platform):

```text
Project          >= 70%
Private Storage  >= 90%
Filesystem       >= 90%
Security         >= 90%
Audit Trail      >= 90%
```

Never lower thresholds to make a platform green; fix the test/coverage architecture instead.

## Security audits

```powershell
composer audit --locked
npm audit
npm run audit:js
composer test:ajax-security
composer test:download-security
```

Plus static analysis on every change: `composer stan` (PHPStan), `composer cs` (PHPCS, `phpcs.xml`).

## Building

```powershell
.\scripts\dev.ps1 build
composer release   # canonical builder (scripts/build-release.php)
```

`build` requires a clean tree (or reports dirty state), runs vendor prefixing + `composer release`, then prints `VERSION`, `SOURCE SHA`, `ZIP`, `SHA-256`, `BYTES`, `ENTRIES`. Verify any artifact without Linux tools:

```powershell
.\scripts\dev.ps1 artifact -Zip ".\dist\sscribe-export-site-pages-<ver>.zip"
```

## Release process

1. `develop` and `main` are kept at the same SHA (alias model, enforced by `composer test:branch-policy`).
2. Day-to-day work lands on `develop`, then `main` is advanced by fast-forward only to match.
3. `composer release:prepare` can finalize the current development version or deliberately bump it; `composer release:commit` commits and fast-forwards both canonical branches.
4. Build/certify the exact ZIP and run `SSCRIBE_RELEASE_CERTIFICATION=1 composer release:audit` with the final Phase 70/71/72 evidence.
5. Only after certification, create the immutable annotated tag with `composer release:tag`; the tag push triggers the release workflow.

Tags and certified ZIPs are immutable after certification — never move, rebuild, or replace them.

## WordPress.org submission

The submission artifact is the certified versioned ZIP (`dist/sscribe-export-site-pages-<ver>.zip`) with recorded SHA-256, byte size, and entry count. `readme.txt` carries the directory listing. Pre-submission: `composer release:audit`, `npm run test:e2e:full`, `composer test:wp`.

## Branch policy

- Long-lived branches: exactly `main` and `develop`, always at the same SHA between releases.
- No `release/*`, `feature/*`, `hotfix/*`, or `support/*` branches persist between releases (transient hotfix branches must be merged and deleted before a tag cut).
- Tags are immutable. Full policy: `docs/BRANCH_POLICY_v2.0.0.md` (enforced by `composer test:branch-policy`).

## Versioning

`readme.txt` Stable tag, plugin header, and release tooling versions are kept in sync (`composer version:check`). The certified v2.0.2 release is immutable and is never rebuilt.

## Source / build transparency

`composer verify:source` and `composer verify:artifact` prove the shipped ZIP maps to the tagged source ( Strauss prefixing documented in `docs/BUILD_TRANSFORMATIONS.md`; per-release evidence in `docs/EXACT_ARTIFACT_EVIDENCE_*.md`).

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `composer test` order-dependent failure | Run the failing `--random-order-seed=N` in isolation; the test must establish its own fixtures (see `tests/Unit/SScribe_Filesystem_Mkdir_Coverage_Test.php` pattern) |
| `dev.ps1 coverage` refuses to run | Install/enable Xdebug; it prints the exact remediation |
| Real-WP suite missing | `composer test:wp:install`, then `composer test:wp` |
| Chromium missing | `npx playwright install chromium` (or `dev.ps1 setup`) |
| `git diff --check` flags CRLF | The repo enforces LF via `.gitattributes`; let Git renormalize, do not commit CRLF |
| symlink-guard tests skip on Windows | Enable Developer Mode (Settings > System > For developers) so `mklink` can create test symlinks; `dev.ps1 doctor` reports the state |
| `test:branch-policy` fails | `main` and `develop` diverged; reconcile `develop`, then fast-forward `main` to the exact same SHA; never force either branch |

## Contributing

See `CONTRIBUTING.md`.

## Security reporting

See `SECURITY.md`. Do not open public issues for suspected vulnerabilities before coordination.

## License

GPL-2.0-or-later. See `license.txt`.
