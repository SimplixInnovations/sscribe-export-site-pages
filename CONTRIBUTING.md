# Contributing to SScribe

## Branch policy (`main` / `develop` / tags)

- `main` and `develop` are the only long-lived branches and must always point to the **same SHA** between releases (alias model).
- Day-to-day work: commit on `develop`, push to origin, then advance `main` to match by fast-forward only. Never force-push `main` or `develop`, including during a release cut.
- No `release/*`, `feature/*`, `hotfix/*`, or `support/*` branch may persist between releases. Transient hotfix branches must be merged into `develop` and deleted before a tag cut.
- Tags are **immutable**. The release ZIP certified against a tag is immutable: never move, rebuild, or replace it.
- Enforcement: `composer test:branch-policy`, `composer test:tag-policy`, `composer test:branch-protection`. Full policy: `docs/BRANCH_POLICY_v2.0.0.md`.

## No direct release-tag mutation

Never commit onto a tag, delete/recreate a tag, or rebuild a certified artifact. All new work belongs to `develop` and the next release lineage.

## PHP coding standards

- PHP 8.2+ language level. WordPress Coding Standards via `phpcs.xml`; gate: `composer cs` (fix with `composer cs:fix` only for your own lines).
- Line endings: LF everywhere (`* text=auto eol=lf` in `.gitattributes`; `*.bat`/`*.cmd` are CRLF). `git diff --check` must pass.

## PHPStan

- Gate: `composer stan` (level per `phpstan.neon`). No new baseline entries without reviewer agreement; prefer fixing the code.

## PHPUnit

- Run: `composer test` (random order, `release-contract` group excluded).
- **Random-order tests**: every test must be order-independent. Establish your own fixtures (temp dirs via the production resolver, options via setUp/tearDown cleanup). Never rely on another test's side effects.
- Deterministic re-runs: `composer test -- --random-order-seed=<n>`.
- Real-WP suite: `composer test:wp:install` → `composer test:wp` → `composer test:wp:uninstall`.
- Coverage thresholds are contractual (Project ≥70%; Private Storage, Filesystem, Security, Audit Trail ≥90%). Do not lower them.

## Playwright

- Smoke: `npm run test:e2e:smoke`. Full incl. a11y: `npm run test:e2e:full`. Runtime contract: `npm run test:e2e:runtime-contract`.
- JS hygiene: `npm run lint`, `npm run format:check`, `npm run audit:js`.

## Security expectations

- All private-storage/filesystem/security paths need tests proving containment, ownership, and capability checks.
- Run `composer test:ajax-security`, `composer test:download-security`, `composer audit --locked`, `npm audit` before requesting review.
- Report suspected vulnerabilities per `SECURITY.md`, never as public issues first.

## Dependency update policy

- Deterministic installs only: `composer install` / `npm ci` from lock files. No `composer update` / `npm update` in feature work.
- Upgrades are separate, reviewed commits with full gate evidence (`dev.ps1 verify` + E2E).

## Commit expectations

- Small, separated commits: `test:`, `chore:`, `docs:`, `build:`, `tooling:` prefixes. Never mix normalization/behavior/docs in one commit.
- Cash the gates per commit where touched: unit tests + `stan` + `cs` minimum.

## Release artifact policy

- Built only via `composer release` (`scripts/build-release.php`); verified via `composer verify:artifact` or `dev.ps1 artifact`.
- Record SHA-256, bytes, and entry count for every certified ZIP. After certification the artifact is frozen.

## Windows development / Linux compatibility

- Windows entry point: `scripts/dev.ps1` (`doctor`, `setup`, `clean`, `test`, `verify`, `e2e`, `coverage`, `build`, `artifact`).
- Canonical logic must be cross-platform: PHP for orchestration (`scripts/*.php`), never Bash-only paths. `bin/*.sh` remain only as thin wrappers.
- Every external invocation in PowerShell must check `$LASTEXITCODE`; fail closed (`$ErrorActionPreference = "Stop"`, `Set-StrictMode -Version Latest`).
