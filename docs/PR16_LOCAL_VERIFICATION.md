# PR 16 local verification after the 0e7294af evidence

Release and merge remain HOLD. Execute locally; return substantive failures
with raw output. Preserve the worktree and all prior evidence bundles.

## Findings addressed in source

- The checkout ZIP differed from the two clean-build manifests in exactly
  `vendor-prefixed/composer/installed.php`. Its root reference was still
  `39b7c1c92375260cc7646fa5e61caf1b87ca5aca`. Replacing that single reference
  with `0e7294af9a3d936102902ca71b1210268af21e95` reproduces the manifest's
  file SHA-256 exactly; the other 885 files match. Packaging now canonicalizes
  our root metadata to the release version and current source SHA in staging.
  Dependency records and the checkout's generated metadata stay intact.
- Plugin Check's duplicate-constant warning was caused by the early bootstrap
  fallback. The fallback now runs after WordPress loads, allowing the active
  plugin to define its own constant. Raw-warning reports still fail validation.
- Both isolated Strauss steps completed successfully in about 406/411 seconds.
  The earlier 240-second cap was insufficient. No dependency patch is needed.

## Required run, in order

1. Fetch and fast-forward `cert/closeout-2.0.4` to the full SHA supplied by the
   reviewer. Do not reset local changes. Record `git rev-parse HEAD` and require
   a clean tracked tree. No JavaScript formatting or POT regeneration is needed.
2. **Install, then prefix, then test.** This order is required even on a fresh
   checkout; missing prefixed dependencies cause PHPWord type conflicts.

   ```sh
   composer install --no-interaction --prefer-dist
   composer vendor:prefix
   php -l scripts/lib/release-metadata.php
   php -l scripts/build-release.php
   php -l scripts/plugin-check-cli-bootstrap.php
   php -l tests/Unit/SScribe_Release_Metadata_Test.php
   php -l tests/Unit/SScribe_Plugin_Check_Bootstrap_Test.php
   php vendor/bin/phpunit --filter "SScribe_Release_Metadata_Test|SScribe_Build_Workspace_Test|SScribe_Plugin_Check_Bootstrap_Test|SScribe_Plugin_Check_Report_Fixtures_Test|SScribe_Build_Transparency_Test|SScribe_Plugin_Check_Test"
   ```

   Require zero errors, failures and warnings. These exercise stale versus clean
   root metadata, dependency preservation, active/inactive bootstrap ordering
   and strict rejection of warning-contaminated reports. Stop and return full
   output on a substantive failure.
3. Run source gates: `composer test`, `composer test:wp`, `composer stan`,
   `composer cs`, `composer i18n:check`, and `node --test tests-js/*.test.mjs`.
   Record skips separately; capture full stdout/stderr, command, runtime versions,
   elapsed time and exit code. Historical green results do not certify this SHA.
4. Run `composer release`. Preserve the ZIP and its SHA-256 before determinism.
   The builder preserves evidence and prior version packages, replacing only
   the current stage/ZIP/checksum. Do not rebuild after certifying a ZIP.
5. Run `composer release:determinism` asynchronously, monitoring the printed
   isolated directory. Allow up to 45 minutes for both builds; retain Composer's
   own process timeout. Poll without blocking communication. Preserve raw
   `step-*.log`, step metadata, `determinism-result.json` and both `pass-*.zip`
   files. If stalled, collect the current log and process/CPU details before
   stopping its process tree. Interrupted execution is BLOCKED, never PASS.

   **Require all three ZIP hashes to match:** checkout release, isolated pass 1
   and isolated pass 2. A two-pass determinism PASS alone does not establish
   checkout identity. Keep HOLD on any difference and attach the differing ZIPs.
6. On the successful checkout ZIP, record full source SHA, ZIP hash, size and
   entry count. Run this against the disposable WordPress installation:

   ```sh
   php scripts/capture-plugin-check.php /absolute/path/current-release.zip /absolute/path/isolated-wordpress
   ```

   Preserve unedited report and sidecar. Require no duplicate-constant warning
   and a successful strict validator result. Never strip stderr or loosen the
   parser. Run full Playwright E2E+a11y against this exact ZIP; retain screenshots
   via Playwright's normal screenshot API. Complete the remaining destructive
   lifecycle and Arabic/RTL install/export visual checks in disposable sites.
7. Regenerate strict manifests for this exact SHA/ZIP. Keep unavailable WPML,
   Redis ON, OpenLiteSpeed, Cloudflare, PHP 8.3/8.4, WP 6.1, MySQL and other
   unexecuted checks BLOCKED. Listing assets, contributor identity and anonymous
   source-transparency proof remain open where not evidenced.
8. Attach an archive with the checkout ZIP, **at least one actual isolated-pass
   ZIP**, both pass hashes, complete logs/metadata, manifests, screenshots and
   certification report. The supplied 0e7294af archive contained the checkout
   ZIP and determinism manifests but no actual pass ZIPs, despite its summary.
   Exclude credentials and unrelated site data.

Do not merge, tag, upload, start GitHub CI or delete preserved evidence.
The reviewer handles source fixes and integration after the gates are resolved.
