# sScribe UI Verification Checklist

Use this checklist before every WP.org release. The build (`php scripts/build-release.php`) ships the contents of `dist/sscribe-export-site-pages-<version>.zip`. Every check must pass before tagging a release.

## 1. Constraint Grep on Shipped ZIP

Extract the ZIP to a temp directory and run the following. All counts must be zero unless noted.

```bash
unzip -q dist/sscribe-export-site-pages-1.1.3.zip -d /tmp/sscribe-ship
cd /tmp/sscribe-ship
```

| Pattern | Expected | Why |
|---|---|---|
| `linear-gradient` | 0 | No decorative gradients |
| `radial-gradient` | 0 | No decorative gradients |
| `box-shadow:` (positive value) | 0 | No visible shadows. The one allowed `box-shadow: none !important` in `@media print` should not match this regex. |
| `box-shadow: none` | 1 | The print media override |
| `@keyframes` | 0 | No CSS animation |
| `animation:` | 0 | No CSS animation |
| `backdrop-filter` | 0 | No blur/filter effects |
| `filter: brightness` | 0 | No filter effects |
| `rgba(` decimal alpha | 0 | Use `rgb(R G B / N%)` or named tokens |
| `outline: none` | 0 | Use `:focus-visible` ring |
| `transition: all` | 0 | Specific property only |
| `-webkit-transform` / `-webkit-transition` / `-webkit-animation` | 0 | No vendor-prefixed standardized properties |
| `em-dash character (U+2014)` | 0 | AI artifact; banned |
| `en-dash character (U+2013)` | 0 | AI artifact; banned |
| `/*` inside shipped `.css` files | 0 | Build strips comments |
| `Let's` / `let's` contraction | 0 | AI artifact |
| `delve` / `tapestry` / `leverage` | 0 | AI artifacts |
| `Moreover` / `Furthermore` / `In conclusion` | 0 | AI artifacts |

Positive checks:

| Pattern | Expected | Why |
|---|---|---|
| `:focus-visible` | ≥ 14 | One ring covering 14 control families |
| `--ss-color-accent: #635bff` (light) | 1 | Brand color on light |
| `--ss-color-accent: #8e89ff` (dark, case-insensitive) | 1 | AA-tuned brand on dark |
| `--ss-text-muted: #71717a` (light) | 1 | AA-passing muted text |
| `--ss-text-muted: #a1a1aa` (dark) | 1 | AA-tuned muted on dark |

## 2. Class Coverage

Every class used by `admin/partials/*.php` must resolve to a selector in `admin/css/*.css`:

```bash
{ grep -hoE 'class\s*=\s*"[^"]*"' admin/partials/*.php | grep -oE 'sscribe-[a-zA-Z0-9_-]+' | sort -u; } > /tmp/used.txt
{ grep -hoE '\.sscribe-[a-zA-Z0-9_-]+' admin/css/sscribe-admin.css admin/css/sscribe-debug-console.css | sed 's/^\.//' | sort -u; } > /tmp/defined.txt
comm -23 /tmp/used.txt /tmp/defined.txt | wc -l
```

Expected output: `0`. If non-zero, the missing classes are real coverage gaps - add minimal CSS rules or remove the unused classes from the partials.

## 3. Version Sync

The plugin version appears in four places. They must match.

| File | Line | Pattern |
|---|---|---|
| `sscribe-export-site-pages.php` | 6 | `* Version:           <X.Y.Z>` |
| `sscribe-export-site-pages.php` | 26 | `define( 'SSCRIBE_VERSION', '<X.Y.Z>' );` |
| `admin/css/sscribe-admin.css` | 1 | `/* @version <X.Y.Z> */` |
| `readme.txt` | 6 | `Stable tag: <X.Y.Z>` |

Run `composer version:check` (or `php scripts/verify-version-sync.php`) for an automated check.

## 4. Quality Gates

Run before tagging. All must pass:

```bash
composer cs        # PHPCS - PHP_CodeSniffer
composer stan      # PHPStan static analysis
composer test      # PHPUnit
composer version:check
```

## 5. Build

```bash
php scripts/build-release.php
ls -la dist/sscribe-export-site-pages-1.1.3.zip
```

The build:
- Strips `/* */` comments from CSS
- Strips non-pragma `T_COMMENT` from PHP
- Excludes `vendor/`, `node_modules/`, `dist/`, `docs/`, `tests/`, `.git/`
- Generates `dist/sscribe-export-site-pages-1.1.3.zip`
- Prints a SHA-256 hash

## 6. Shipped ZIP Audit

After build, run the constraint grep on the extracted ZIP (section 1 above) plus:

| Check | Expected |
|---|---|
| ZIP contains `vendor-prefixed/autoload.php` | yes |
| ZIP contains `vendor-prefixed/sscribe-runtime-shim.php` | yes (if used) |
| ZIP contains no `vendor/` (un-prefixed) | yes |
| ZIP contains no `docs/` | yes |
| ZIP contains no `.git/` | yes |
| ZIP contains no `.phpunit.result.cache` | yes |
| ZIP contains no `composer.json` / `composer.lock` (un-prefixed) | yes |
| `readme.txt` present at ZIP root | yes |
| Main plugin file at ZIP root | yes |

## 7. Visual Verification via Playwright

Open `docs/ui-preview.html` in a real browser via the Playwright MCP. Verify:

- [ ] **Light mode, 1440px wide**: hero, config panel, format cards, progress, history, modal, support terminal, debug console render with no horizontal scroll, no overlapping controls.
- [ ] **Dark mode (forced)**: all sections render with AA contrast. Brand accent visible. No white-on-white.
- [ ] **Forced colors mode**: borders and outlines use system colors. Focus rings use Highlight at 3px.
- [ ] **360px viewport**: single-column collapse. Hero stats stack. Tabs become horizontal scroll. No content overflows horizontally.
- [ ] **Keyboard tab order**: every interactive element receives focus with a visible 2px accent outline. Order is logical (top to bottom, left to right).
- [ ] **No animations or transitions on**: hover, focus, click in any mode. Transitions nullified under `prefers-reduced-motion: reduce`.

## 8. Runtime Smoke Test

In WP Playground:

1. Activate the plugin.
2. Navigate to `SScribe Export`.
3. Verify the admin page renders with no JS errors in the console.
4. Click the `Help & Docs` tab. Verify the support sidebar and support terminal render.
5. Click the `Debug Console` tab. Verify the debug entries render.
6. Click `Export`. Verify the form accepts selections.
7. Click `Preview` (if available). Verify a modal opens.

## 9. Final Pre-Release Gate

- [ ] All section 1-6 checks pass.
- [ ] Section 7 visual verification recorded (screenshots in `docs/screenshots/`).
- [ ] Section 8 runtime smoke test recorded.
- [ ] CHANGELOG entry added in `readme.txt` covering the change.
- [ ] UPGRADE NOTICE added in `readme.txt` if the change is user-visible.
- [ ] Git commit on `develop` with author `Simplix Innovations <info@simplixi.com>` and NO `Co-Authored-By` trailer.

## 10. After Release

- Push the `develop` branch.
- Confirm the CI workflow `release-zip.yml` (if configured) builds the same ZIP and matches the SHA-256.
- Tag the release on the merged commit.
- Upload the ZIP to the WP.org plugin SVN repository.