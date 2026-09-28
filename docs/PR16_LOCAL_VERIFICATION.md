# PR 16 local verification after the 39b7c1c9 evidence

Release remains HOLD. The reviewer supplied the source corrections. This run
requests execution and evidence; return substantive failures for correction.

1. Preserve the worktree and all prior bundles. Fetch and fast-forward
   `cert/closeout-2.0.4` to the full SHA supplied by the reviewer. Do not reset
   local changes. These follow-up changes touch tests and build tooling;
   no JavaScript formatting or POT regeneration is needed.
2. First run the focused regressions:

   ```sh
   php -l scripts/lib/build-workspace.php
   php -l scripts/verify-build-determinism.php
   php -l scripts/run-strauss.php
   php -l scripts/build-release.php
   php vendor/bin/phpunit --filter "SScribe_Admin_Test|SScribe_Plugin_Check_Command_Test|SScribe_Build_Workspace_Test"
   ```

   Require zero errors, failures and cleanup warnings. This verifies translation
   registration, read-only Git fixture cleanup, preservation of evidence,
   isolated committed-source builds, and rejection of failed evidence writes.
   Stop on a substantive failure and return the full output.
3. On the clean source SHA, run the existing core/WordPress and source gates:
   `composer test`, `composer test:wp`, `composer stan`, `composer cs`,
   `composer i18n:check`, and `node --test tests-js/*.test.mjs`.
   Capture actual stdout/stderr, command, runtime versions, elapsed time and
   exit code. List skips separately. Do not replace raw logs with a summary.
   The previous archive omitted the full core, WordPress and release-build logs.
4. Run `composer vendor:prefix` and `composer release` on this SHA, retaining
   full logs. The release builder now replaces only the current stage, ZIP and
   checksum; it preserves evidence bundles and other versioned ZIPs.
5. Run `composer release:determinism` asynchronously and monitor the printed
   isolated directory. It builds committed HEAD in a disposable clone and
   leaves the original dependencies, ZIP and evidence in place. It retains
   `step-*.log`, `step-*.log.json`, `determinism-result.json`, and both pass ZIPs
   in that directory. Step logs are written live, including Strauss file names.

   Do not apply the previous 240-second cap to the entire two-build command.
   Use an outer budget of up to 45 minutes, poll without blocking communication,
   and retain Composer's own process timeout. If progress stalls, collect the
   current raw step log, metadata, last file name and CPU/memory samples for
   that process and its children before stopping the process tree. An
   interrupted RUNNING result is BLOCKED, never PASS. Do not guess the cause
   or patch dependencies. Preserve the isolated directory until reviewed.

   Compare the hashes of both pass ZIPs with the canonical validated ZIP in
   the original checkout. Any mismatch remains HOLD; return the maps/logs.
6. With a successful current ZIP, record source SHA, ZIP SHA-256, byte size and
   entry count. In the disposable WordPress installation run:

   ```sh
   php scripts/capture-plugin-check.php /absolute/path/current-release.zip /absolute/path/isolated-wordpress
   ```

   Then run full Playwright E2E and accessibility on the same ZIP. Exercise
   archived-log navigation, Arabic plural counts and translated paused/error
   states; capture screenshots using Playwright's normal screenshot API.
   Complete the existing lifecycle and Arabic/RTL export visual checks there.
7. Regenerate strict release manifests with this exact identity. Keep unavailable
   WPML, Redis, OpenLiteSpeed, Cloudflare, PHP/WP/database matrix legs and other
   unexecuted checks BLOCKED. Do not reuse prior candidate results or fabricate
   a pass to satisfy a gate.
8. Return an attached archive containing the current ZIP, identity, complete
   raw logs and exit codes, manifests, screenshots and certification report.
   Include the determinism logs/metadata and both ZIP hashes; include both
   ZIPs if they differ. Exclude credentials and unrelated site data.

Do not merge, tag, upload, start GitHub CI, or delete preserved evidence.
The reviewer handles source fixes and integration. Listing assets and contributor
identity remain separate preparation items.
