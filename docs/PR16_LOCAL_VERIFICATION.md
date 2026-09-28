# PR 16 local verification

The correction code is supplied in PR #16. This handoff requests local execution
and evidence; substantive code fixes stay with the reviewer. Release is HOLD.

1. Preserve the prior candidate bundles and worktree. Fetch the remote
   `cert/closeout-2.0.4` branch and fast-forward to the reviewer-provided SHA.
   Do not reset or overwrite local changes.
2. Run the installed project formatter for `admin/js/sscribe-debug-console.js`
   and `composer i18n:make-pot`. These tools were unavailable to the reviewer.
   If generated files change, inspect and commit only the formatter/POT output
   on this branch, push it, and record the resulting full SHA. Do not change
   behavior to make tests pass. Report failures with their raw logs.
3. On the clean resulting SHA, run:

   ```sh
   node --test tests-js/*.test.mjs
   composer test
   composer test:wp
   composer stan
   composer cs
   composer i18n:check
   npm run lint:js
   npm run lint:css
   npm run format:check
   ```

   The core run must include `SScribe_Plugin_Check_Report_Fixtures_Test`,
   `SScribe_Plugin_Check_Command_Test`, `SScribe_Plugin_Check_Triage_Test`, and
   `SScribe_Private_Storage_Branches_Test`. Retain test counts, skipped tests,
   versions and exit codes. A skipped test is not a pass.
4. Run the existing release build and `composer release:determinism`. Keep full
   Strauss output if it hangs; record the command, elapsed time and last output.
   Do not mark determinism passed from a single build. Record final ZIP hash,
   size, entry count and source SHA.
5. In the isolated WordPress testbed with Plugin Check installed, capture the
   exact ZIP using:

   ```sh
   php scripts/capture-plugin-check.php /absolute/path/release.zip /absolute/path/isolated-wordpress
   ```

   The script records the raw report and bound evidence and invokes the strict
   verifier. Keep `dist/evidence/plugin-check.log`,
   `dist/evidence/plugin-check-evidence.json` and the triage manifest, even on
   failure. Do not hand-edit reports or hashes to obtain a pass.
6. Run full Playwright E2E and accessibility against that same ZIP. Specifically
   exercise opening an archived log, returning to the current log, plural
   counts in an installed Arabic locale, and translated error/paused states.
   Capture screenshots through Playwright's normal screenshot API; a separate
   CLI wrapper is not required. Run the existing lifecycle and export visual
   checks in the disposable testbed.
7. Re-run strict release evidence gates with the new identity. Preserve real
   BLOCKED statuses for unavailable WPML, Redis, OpenLiteSpeed, Cloudflare,
   supported PHP/WP/database matrix legs, or any other unexecuted check. Do not
   carry old green results over to the new SHA/ZIP.
8. Return the final full SHA, ZIP/hash, raw command logs and exit codes,
   manifests, screenshots, and certification report in an attached evidence
   archive or accessible download. A path on the local PC is insufficient for
   independent inspection. Exclude credentials and unrelated WordPress data.

Do not merge, tag, upload, or delete the worktree/evidence in this local run.
The reviewer will handle PR integration after reviewing the results. Listing
assets and contributor identity remain separate listing-preparation items;
they do not substitute for runtime evidence.
