# Local submission verification

Source integration is authorized while GitHub Actions is unavailable. This is
not submission approval. Certify the final `origin/main` source and its exact
ZIP before tagging or uploading. Never copy PASS values from an earlier SHA.

## Final review changes to verify first

- Download failures must retain HTTP 400/403/404/429/500 as appropriate.
  WordPress AJAX defaults wp_die to HTTP 200 unless response is explicit.
  Run the download-token-auth browser spec, including invalid and missing
  filenames, and confirm rejection bodies are not served as successful files.

- A short final private/draft query page that crosses the candidate scan limit
  must return an indeterminate count, not an exact zero that omits later
  readable content. A fully examined short page must retain its exact count.
- Language cards show the existing translated Unknown label for indeterminate
  counts. They must not claim 10,000+ readable posts merely because the bounded
  permission scan stopped. Exact zero and ordinary counts remain numeric.
- The local checklist uses combined core plus real-WordPress coverage and
  checks frontend formatting. Updating main uses a fast-forward merge, never
  a destructive reset.

The new PHP regression tests were not executed in the review workspace because
PHP and Composer were unavailable. Local JavaScript syntax, runtime-contract,
audit-helper tests, and direct English/Arabic/zero/exact count-expression checks
passed. These are partial checks, not browser or PHP certification.

## Instructions for the local agent

1. Preserve local changes. Fetch origin, switch to main, and fast-forward only.
   Stop and report a dirty or divergent checkout; do not reset it or discard files.
2. Confirm HEAD equals origin/main and record the SHA outside tracked files.
   Use PHP 8.2+ with the repository-required extensions, Composer 2, Node 24+,
   WP-CLI, a coverage driver, Chromium, and the required database services.
3. Install locked dependencies and prefix vendors as described in
   `LOCAL_RELEASE_CHECKLIST_v2.0.0.md`. Run this focused regression first:

   ```bash
   php vendor/bin/phpunit tests/Unit/SScribe_Page_Collector_Readability_Performance_Test.php
   ```

4. Follow the complete local release checklist. Run the full PHP tests, PHPStan,
   PHPCS, JavaScript/CSS lint and formatting, dependency audits, real WordPress
   minimum/current matrix, combined coverage, and deterministic-build check.
   Run `composer release:determinism` before final evidence is assembled because
   build operations can replace dist. Missing tools or environments are BLOCKED,
   not PASS. Do not lower thresholds or add broad suppressions.
5. Build the final ZIP once, retain its SHA-256, and test that exact ZIP with
   official Plugin Check and the full browser suite. Verify both count boundary
   fixtures above in a real WordPress installation with a delegated exporter.
6. Inspect the full interface in light/dark, English/Arabic RTL, keyboard-only,
   narrow desktop/mobile widths and 200% zoom. Cover initial/loading/empty/error/
   success states, every tab, preflight focus, dismissible notices, history,
   cancellation, retry, expiration and unauthorized downloads. Save screenshots
   and browser/PHP error logs. Open the exported DOCX/PDF/HTML/Markdown files and
   inspect Arabic shaping, page breaks, tables, images, links and metadata.
7. Execute every environment in `MANUAL_RUNTIME_TESTS_v2.0.0.md`, including real
   licensed WPML, Redis on/off, OpenLiteSpeed and Cloudflare/proxy. Fixtures do
   not substitute for these environments. Record unavailable environments.
8. Generate ignored evidence JSON and logs exactly as the checklist specifies,
   then run every strict certification gate with
   `SSCRIBE_RELEASE_CERTIFICATION=1`. Do not commit final SHA/checksum evidence.
9. Return: source SHA; clean-tree proof; runtime versions; each command and exit
   code; test totals; coverage; Plugin Check results; UI screenshots; environment
   results; ZIP path, byte count and SHA-256; all strict manifests and open
   blockers. Do not tag or submit with a failure, missing environment or missing
   evidence. If a tracked fix is needed, commit it through review and regenerate
   final certification on the resulting main SHA.

With Actions unavailable, strict local evidence is the release proof. A skipped
or unavailable workflow must never be described as a successful workflow.
