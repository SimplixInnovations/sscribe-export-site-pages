# SScribe Export Site Pages — Next-Phase Roadmap

Status: living document. Recorded 2026-10-04 after the 2.0.0 deep audit and
"All gates green" release certification (26/26, source `def9daa`, ZIP SHA-256
`bb9fb7c0ec4c3459c70dd3c619484795f804f6517076360ec04d99399128e71c`).

Purpose: start the next phase immediately once 2.0.0 is approved, without
re-deriving the audit. Every item below was verified during the deep audit
(file:line evidence exists in the audit record); items marked [contract] are
deliberate design decisions to respect, not bugs.

---

## Phase A — Quality & contract hardening (do first; all verified gaps)

### A1. Error-payload internal-details leak (highest priority)
Error responses ship absolute paths (`file_path`, `temp_dir`) inside
`technical.context`, and raw exception messages reach `errors[]`
(`includes/class-sscribe-batch-processor.php` validation context,
`includes/traits/trait-sscribe-export-finalizer.php` ZIP-failure payloads,
`includes/traits/trait-sscribe-batch-step-handler.php` critical-error text).
This contradicts the plugin's own no-internal-details policy
(`scripts/verify-no-internal-details.php`).
Fix: redact `path|file|dir|temp` context keys before
`SScribe_Export_Error_Handler::build_diagnostics_payload()` (mirror
`SScribe_Diagnostics::format_support_value()`), and map exception messages to
translated summaries; keep raw detail server-side in the logs only.

### A2. Cancel protocol unification
Server emits non-standard HTTP 499 with `cancelled:true`
(`trait-sscribe-batch-step-handler.php`); the client's `error:` handler maps
499 to retry (`admin/js/sscribe-admin.js` `getAjaxFailureDecision`), so a
cancelled export ends in a red "session expired" error instead of the
cancelled toast. Mid-batch cancel on HTTP 200 is likewise ignored by the
success branch.
Fix: return `success:false, code:'cancelled'` (HTTP 200) and check
`data.cancelled` in both client branches before retry/schedule.

### A3. Test gaps (each confirmed zero-coverage during the audit)
1. Network-wide activation loop (`includes/class-sscribe-activator.php`
   `activate_network_wide()`, `switch_to_blog` pairing) — deactivation IS
   tested; activation is not.
2. Multisite uninstall loop (`uninstall.php` multisite branch).
3. Upgrader legacy migrations: `<1.1.1` metrics-key rename and `<2.0.0`
   `migrate_legacy_storage()` failure path (`includes/class-sscribe-upgrader.php`).
4. Disk-full abort contract at ZIP assembly
   (`includes/class-sscribe-zip-handler.php` `disk_free_space` pre-check) —
   publicly claimed in `docs/PERFORMANCE_BENCHMARKS_v2.0.0.md`, untested.
5. Batch loop-exit filters (`sscribe_timeout_buffer_seconds`,
   `sscribe_soft_deadline_ratio`, `sscribe_memory_threshold_mb`) — only the
   paused *response* is tested, not the decision logic.
6. Privacy eraser partial-failure branches (session/archive retained paths).

### A4. API honesty cleanup
- `force` parameter of `sscribe_clear_session` is accepted and ignored
  (`trait-sscribe-session-ajax.php`) — implement force semantics or remove
  the parameter end-to-end.
- `refreshNonceAnd()` in `admin/js/sscribe-admin.js` targets
  `refresh_nonce_url` which is never localized and has no registered action —
  wire a real endpoint or delete the dead branch (today invalid nonces force
  a full page reload mid-operation).
- Error payloads must always carry `code` (the JS guidance table keys off it;
  roughly half the endpoints omit it) — enforce in `SScribe_AJAX_Guard::error()`.

### A5. Concurrency and state
- Single-active-session guard disengages during `finalizing`/`completing`
  (a second `create()` is no longer blocked) — close the double-export window.
- Renew the per-session lock inside long batches (finalize renews; batches do
  not) and defer cancel's temp-dir deletion until the batch acknowledges.
- `delete_owned_if_unlocked` (privacy eraser) should take the session lock.

### A6. Remaining small defects from the audit
- Rate limiter: `wp_cache_incr() === false` resets the counter to 1 instead of
  incrementing (quota silently unenforced on some object-cache backends).
- Global 50-entry export index evicts other users' archives (FIFO without
  ownership check) — scope eviction per user.
- Session HMAC signs the session id, not the payload (legacy plaintext rows);
  sign `hash(payload)` on the next storage-bump migration.
- Signing-key bootstrap persists `AUTH_SALT` to `wp_options` and has a
  first-call race — prefer a generated random key, always return the stored
  value.
- Support/self-test payloads expose full `PHP_VERSION` (mask like other
  values); debug logs retain `request_uri`/`user_agent`/`username` outside the
  privacy eraser — hash in `sanitize_log_context` or document the 7-day
  retention as the erasure policy.
- `strip_page_builder_attributes()` strips `class`, killing button detection
  and code-fence language hints — extract those signals first or strip only
  builder-specific attributes.
- PDF: content images past the 20-image cap and non-localizable images are
  silently removed — emit a `[image not included]` placeholder like DOCX;
  move `check_memory_pressure()` before image processing.
- SVG: inline SVG is dropped in all formats and the SVG branches in
  `url_to_local_path` are dead code — emit consistent placeholders and remove
  or implement the dead branches.
- Reading-time semantics: `fa`/`ur` use Arabic segmentation but Latin WPM;
  Markdown forces `reading_time: 1` minimum while other formats show
  "Under 1 minute".
- DOCX: share the exporter's hardened `validate_url()` (the renderer accepts
  credential URLs), honor `rowspan`, review the 8 KiB minimum-size floor.
- Markdown: `<p>` regex lacks the `/s` flag; blockquote internals are
  flattened to plain text; table cells are not escaped beyond `|`.
- History table is capped client-side at 50 rows and the filter only searches
  rendered rows — paginate/filter server-side.
- Cold-path query cost: full `LIKE 'sscribe_session\_%'` scans + per-row
  decrypt on export start — add a per-user session index option.

---

## Phase B — Architecture & structure

1. **Split the monoliths.** `admin/js/sscribe-admin.js` (4.8k lines) →
   wizard/history/support/debug modules with a wp-scripts build; debug
   console already models the target quality (wp.i18n, rollback handling).
   `class-sscribe-exporter.php` (1.8k), `class-sscribe-page-collector.php`
   (1.8k), `class-sscribe-session.php` (1.7k) → extract collaborators
   (collector query strategies; session repository vs state machine).
2. **One error contract.** Central error catalog (code → message → retry
   policy) consumed by PHP and JS; enforced at `SScribe_AJAX_Guard::error()`.
3. **Share primitives.** URL validation exists in two divergent copies;
   text-run policy is now shared (`SScribe_Helpers::is_machine_style_string`)
   — extend the pattern to URL/IP/formatting helpers.
4. **i18n parity for the admin wizard.** Route `sscribe-admin.js` strings
   through `wp.i18n` (debug console already does) and enqueue `wp-i18n`.
5. **Stable gate contracts.** Replace line-number-pinned verifiers (e.g.
   `verify-mkdir-containment.php` callsite pins) with content/annotation
   matching so harmless refactors cannot silently red the release gate.
6. **Debug endpoints through the loader.** `admin/class-sscribe-admin-debug.php`
   hand-rolls authorization; route through `SScribe_Loader::add_guarded_*`.
7. **Multisite test surface.** The WP test stub has no multisite model;
   adding `get_sites`/`switch_to_blog` stubs unlocks A3 items 1–2.

---

## Phase C — Product upgrades (world-class direction)

Priority order recommended:

1. **WP-CLI command** — `wp sscribe export --formats=docx,pdf --post-type=page`
   for server-side/batch exports (top power-user ask for export plugins).
2. **Scheduled exports** — cron profiles (weekly archive per post type or
   language, retained as ZIP or emailed), reusing the existing batch engine.
3. **Incremental exports** — since-date / since-ID deltas on top of the
   existing page-ID snapshot model.
4. **Export profiles/presets** — saved setting bundles (formats + content
   filters + language) selectable in the wizard.
5. **Per-language archives** — multilingual detection (WPML/Polylang/
   TranslatePress) already exists; emit one ZIP per language.
6. **HTML self-contained mode** — inline local images/CSS into the HTML export
   for a true offline artifact (opt-in).
7. **Listing assets** — readme `== Screenshots ==` section plus
   banner/icon/screenshot files in the WordPress.org SVN `/assets` directory
   (absent today; reviewers notice).
8. **Adaptive batch sizing** — `SScribe_Adaptive_Metrics` already exists;
   feed it into `sscribe_batch_size` per host.

---

## Verification contract for every phase

No phase ships without: `composer release` green (full suite + PHPStan 7 +
PHPCS), `composer release:audit` "All gates green", Plugin Check 0/0 on the
exact ZIP (`php scripts/capture-plugin-check.php` evidence sidecar), and the
Playwright suite (smoke + e2e + axe) green against the exact ZIP. The deep-audit
report of 2026-10-04 is the baseline; revisit `docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md`
if new warnings appear.

## Environment notes (this machine)

- Interrupted `wp-phpunit` clones poison `tests-wp/.cache/wp-phpunit-tree-7-1`
  ("clone has no includes/"); delete that directory and retry.
- Windows write-locks occasionally defeat test mutation-restore (readme.txt,
  composer.json, package.json were each left mutated once). If a gate fails
  with bizarre content errors, run `git status` and `git checkout -- <file>`
  before debugging further.
