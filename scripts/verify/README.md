# scripts/verify/

Runtime verification harnesses for the shipped plugin artifact
(`dist/sscribe-export-site-pages-<version>/`). These are run after
`php scripts/build-release.php` to confirm the ZIP would activate and
export without the crashes the in-tree static analysis (PHPCS, PHPStan,
PHPUnit) cannot detect.

The harnesses boot the plugin in an isolated child process with WP stubs
(so no real WordPress install is needed), then exercise a real
production code path. Failures here represent bugs that would hit a user
on activation or first export — not code-quality nits.

## The two harnesses

### `verify_runtime.php` — activation-time crash detector

```
php scripts/verify_runtime.php dist/sscribe-export-site-pages/
```

17 checks. Boots the plugin and exercises each surface a user touches
on activation or first export:

- Plugin header loads cleanly under PHP 8.2 (no self-deactivate).
- Container singleton reachable, every exporter class autoloads.
- mPDF / PHPWord vendor-prefixed namespaces resolve (catches the
  `class FPDF not found` / `class Mpdf\Mpdf not found` landmines
  the build script's asset pruning can introduce).
- PDF exporter config pins `backupSubsFont=freeserif`,
  `backupSIPFont=null` (regression for the 2026-06-24
  `Cannot find TTF DejaVuSansCondensed.ttf` crash).
- Diagnostics preflight thresholds agree with what
  `scripts/build-release.php` actually ships (catches the
  "diagnostic warns about a font that IS in the ZIP" class of
  false positive).
- Preflight status check returns `ok` (catches the "PHPWord / mPDF
  vendor-prefixed namespace probes the live wp-admin expects, but
  the dist no longer ships the auto-probed file" regression).

**When it fails** — the bug is on activation or first export. Look at
the check name; it points at the surface.

**Run it** after every change to `scripts/build-release.php`,
`includes/sscribe-prefixed-runtime-shim.php`, or any file that
re-pins mPDF / PHPWord behavior.

### `verify_e2e.php` — full PDF render proof

```
php scripts/verify_e2e.php dist/sscribe-export-site-pages/
```

Renders a real PDF via the actual `SScribe_PDF_Exporter` constructor +
the private `build_mpdf_config()` method (called via reflection). The
harness imports the production config verbatim — there is no
hand-reconstructed copy in this harness, so any future change to the
production code's `fonttrans` / `fontdata` / `backupSubsFont` /
`backupSIPFont` is automatically exercised.

Output: a real `.pdf` file with `%PDF-` magic header. Failure modes:

- `E2E_FATAL MpdfException` → a CSS keyword, named family, or
  character class hit a pruned TTF. Re-check
  `scripts/build-release.php` `font_excludes` against the
  `$config['fontdata']` keys the harness prints.
- `E2E_FAIL not_a_pdf` → mPDF wrote something but it's not a valid
  PDF (memory pressure, fatal during Output()). Inspect the harness
  output for the line preceding the failure.

The harness exercises:

- All common CSS keywords (`serif`, `sans-serif`, `monospace`,
  `times`, `times new roman`, `georgia`, `palatino`, `cambria`,
  `garamond`, `bookman`, `arial`, `helvetica`, `verdana`,
  `tahoma`, `trebuchet`, `lucida`, `courier`, `courier new`,
  `monaco`, `consolas`).
- All common character classes: Latin (em-dash, copyright, snowman),
  CJK, Cyrillic, Greek, Arabic.

**When it fails** — the bug is in PDF export. The fontdata keys
printed by the harness are the source of truth for which TTFs the
plugin remaps.

## Why two harnesses

| Harness | What it catches | What it doesn't catch |
|---|---|---|
| `verify_runtime.php` | Class autoload failures, vendor-prefix namespace breaks, preflight regressions, container resolution | Actual rendering bugs (PDF content quality, font glyph coverage) |
| `verify_e2e.php` | Font crash paths, fontdata/backup-chain regressions, PDF format validity | DOCX/HTML/Markdown output, end-to-end user flow |

Together they cover activation → first export → first PDF render.
What they DON'T cover: AJAX roundtrips (the PHPUnit AJAX tests do
that), DOCX/HTML/Markdown output quality, and WordPress.org
submission policy compliance (covered by `scripts/check-wp-org.php`).

## CI integration

Both harnesses are designed to run in CI without a WordPress install.
Total runtime under 30 seconds on commodity CI hardware.

The expected output prefix for each passing harness:

- `verify_runtime.php` → `=== Result: 17 passed, 0 failed ===`
- `verify_e2e.php` → `=== E2E PDF render: ... ===` ... `E2E_PASS`

If you add a check to either harness, prefer adding the assertion to
the harness that already exercises the relevant surface — don't add
a third harness.

## See also

- `docs/extension-points.md` — public API the harnesses exercise.
- `scripts/check-wp-org.php` — WP.org policy compliance scanner.
- `scripts/build-release.php` — the build script these harnesses
  validate.
