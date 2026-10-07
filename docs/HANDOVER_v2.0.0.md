# SScribe Export Site Pages 2.0.0 - Handover

Shipped artifact: `dist/sscribe-export-site-pages-2.0.0.zip`
SHA-256: `219ce35d474cfbff1541e081e924c7fded27d11ce023beea1fb7d096b7426f2c`
Source: `64ccc08` (main = origin/main). Version 2.0.0.

## What the plugin is

Export every WordPress page/post (plus custom post types) into one ZIP of
DOCX, PDF, HTML and Markdown documents, from a configuration dialog with
readiness preview, live phase progress and a completion summary.

## Requirements

- WordPress 6.1+, PHP 8.2 to 8.5.
- WordPress.org compliance: Plugin Check 0 errors / 0 warnings, GPL-2.0-or-later.
- WCAG 2.2 AA in light AND dark; dark mode must match the WordPress admin.
- Large sites: batched exports of 10,000+ pages, pause/resume per batch,
  resume after page reload, safe cancel while running.
- Multilingual (WPML, Polylang, TranslatePress) with RTL / Arabic / Persian.
- Security: single-use download tokens, private storage outside the web root
  where possible (hardened fallback under uploads), capability + nonce on
  every endpoint, path containment.
- GDPR: privacy export/erasure for audit trail, stats, sessions and the
  diagnostics log; redacted support diagnostics; 72-hour archive retention.

## Issues faced during development (all fixed)

1. Activation dead-end on managed hosting (ref `b174bc301c46`): private
   storage resolution failed and aborted activation. Fixed with a hardened
   uploads fallback and non-fatal activation with an admin warning.
2. WordPress.org rejection: dark-on-dark text/icons and a background that did
   not match the admin. Fixed with context-adaptive dark tokens (admin
   background is the source of truth), contiguous canvas, and a theme-contrast
   audit covering every text/icon pair in both themes.
3. Workspace files leaked into the release ZIP. Fixed with `.distignore`,
   build exclusions and a package content gate.
4. Phantom "Failed to write PDF file": the PDF engine sanitizes written
   filenames. Fixed by normalizing the artifact back to the intended name.
5. Download-after-delete failures: download links are single-use. Fixed by
   refreshing row links after every download and re-render.
6. History UX: stale bulk-select count, missing filter clear control,
   untranslated `%1$d %2$s` dialog labels, dead "View in History" button. Fixed.
7. Dialog UX: accidental backdrop dismissal, close button under the WP admin
   bar, unclickable preflight actions, "New Export" opening stale state. Fixed
   (dialogs portaled to body, close-locked while running, scoped styles).
8. Session lost on page reload (masked by a test-harness wipe). Fixed with
   cache hygiene and abort-safe writes; resume-after-reload proven.
9. Cancel deadlocked against running batches. Fixed with flag-based cancel
   acknowledged instantly, honored at the next page checkpoint.
10. Release hygiene: two empty stub files from a path mistake, caught by
   Plugin Check and the artifact tests before shipping.

## Verification contract (what "done" means)

- Release gates: 26/26 "All gates green" + triage evidence sidecar.
- Plugin Check 0/0 on the exact ZIP; package has zero non-plugin files.
- 2,656 unit/integration tests, 43 browser tests (flows, axe WCAG 2.2 AA,
  theme contrast in light and dark), PHPStan 7 + PHPCS + ESLint + Stylelint.
- Icons: embedded Phosphor Light subset (2.9 KB, MIT license shipped), drawn
  in currentColor so themes recolor them.

## Known environment notes (this machine)

- Windows write-locks can fail test mutation-restores mid-run; if a suite run
  shows odd failures, check `git status` and `git checkout -- <file>` first.
- An interrupted `wp-phpunit` clone poisons
  `tests-e2e/.cache/e2e-wordpress` / `tests-wp/.cache`; delete the cache dir
  and retry.
- Rebuilds require a clean tree (the build stamps the source SHA).

## Next steps

See `docs/NEXT_PHASE_ROADMAP.md`: Phase A leftovers (parser class-signal
extraction, HMAC payload signing), Phase B architecture splits, Phase C
features (WP-CLI export, scheduled and incremental exports, per-language
archives).
