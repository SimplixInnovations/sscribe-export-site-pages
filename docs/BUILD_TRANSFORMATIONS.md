# Build Transformations (Phase 24)

This document is the **single source of truth** for what
`scripts/build-release.php` does to the working tree before it
writes the release ZIP. The same enumeration is also printed at
the end of every `composer release` run, so the build log and this
doc never drift: when a transformation is added here, the build
script's end-of-run summary is updated in the same commit.

> **Why this exists.** Phase 24 of the 2.0.0 release-hardening
> directive. A reviewer of the submission ZIP must be able to see,
> at a glance, exactly what was removed, rewritten, or renamed
> relative to the git checkout. Without an explicit enumeration,
> the build script is an opaque producer and the WP.org reviewer is
> left to reverse-engineer the delta.

---

## 1. Excluded paths (not shipped)

These paths are present in the working tree but do not appear in
the release ZIP. The `is_release_path_excluded` matcher in `scripts/build-release.php`
supports `*` and `?` wildcards. Rules without a slash also match individual
path segments, so exact development-directory exclusions such as `tests/`
continue to work alongside wildcard rules such as `*.log`.

### Dev-only directories

`tests/`, `tests-wp/`, `tests-js/`, `tests-e2e/`, `scripts/`, `.github/`,
`docs/`, `examples/`, `samples/`, `.audit/`, `.agent/`,
`.claude/`, `.opencode/`, `.cursor/`, `.windsurf/`, `.continue/`,
`.codeium/`, `.mimosa/`, `.omo/`, `.aider*`, `.aider.chat.history`,
`.aider.input.history`, `.aider.model.settings.json`, `.codegraph`,
`.debug-journal.md`, `.git/`, `.gitattributes`, `.gitignore`,
`.distignore`, `.phpunit.cache/`, `.playground-cache/`, `.cache/`,
`.sisyphus/`, `.wp-env/`, `.playground-tools/`, `.stubs/`, `stubs/`,
`bin/`, `node_modules/`, `dist/`, `build/`, `coverage/`,
`sscribe-exports/`, `WPScan/`, `WordPress-Core/`, `wordpress/`,
`wordpress-tests-lib/`, `scratch/`, `tmp/`, `tmp_diffs/`,
`vendor/`, `playground-blueprint.json`, `infection.json5`,
`commit-message.txt`, `strauss.json`, `node_modules/`.

### Dev-only root files

`phpunit-wp.xml`, `playwright.config.ts`, `composer.lock`,
`CONTRIBUTING.md`, `CHANGELOG.md`, `phpstan.neon`,
`phpstan.neon.dist`, `phpstan-baseline.neon`, `phpstan-bootstrap.php`,
`phpunit.xml`, `phpunit.xml.dist`, `phpcs.xml`, `.editorconfig`,
`.prettierrc`, `.eslintrc.json`, `.stylelintrc.json`,
`.php-cs-fixer.php`, `.php-cs-fixer.dist.php`, `mkdocs.yml`,
`.travis.yml`, `.scrutinizer.yml`, `.github_changelog_generator`,
`ruleset.xml`, `CREDITS.txt`, `.wp-env.json`, `verify_*.php`,
`debug_*.php`, `*.py`, `*.log`, `*.tmp`, `*.bak`, `.DS_Store`,
`Thumbs.db`, `desktop.ini`, `opencode.json`, `.opencode/`,
`eslint.config.js`, `.stylelintrc.json`, `husky/`, `review-diff.patch`.

### Vendor-prefixed exclusions

- `vendor-prefixed/*/.github/`, `vendor-prefixed/*/.git/`,
  `vendor-prefixed/*/tests/`, `vendor-prefixed/*/docs/`,
  `vendor-prefixed/*/utils/`, `vendor-prefixed/*/tmp/`,
  `vendor-prefixed/*/.php-cs-fixer*`, `vendor-prefixed/*/.travis.yml`,
  `vendor-prefixed/*/.scrutinizer.yml`, `vendor-prefixed/*/mkdocs.yml`,
  `vendor-prefixed/*/.gitattributes`, `vendor-prefixed/*/.gitignore`,
  `vendor-prefixed/*/README.md`, `vendor-prefixed/*/CHANGELOG.md`,
  `vendor-prefixed/*/CONTRIBUTING.md`, `vendor-prefixed/*/CREDITS.txt`,
  `vendor-prefixed/*/composer.json`, `vendor-prefixed/*/composer.lock`,
  `vendor-prefixed/*/ruleset.xml`, `vendor-prefixed/*/phpstan.neon*`,
  `vendor-prefixed/*/phpunit.xml*`, `vendor-prefixed/*/.github_changelog_generator`,
  `vendor-prefixed/phpoffice/phpword/src/PhpWord/Shared/PCLZip/`,
  `vendor-prefixed/phpoffice/phpword/COPYING.LESSER`,
  `vendor-prefixed/phpoffice/phpword/phpword.ini.dist`,
  `vendor-prefixed/phpoffice/phpword/phpmd.xml.dist`.

### TCPDF runtime pruning

TCPDF ships a large general-purpose font catalog. SScribe's PDF exporter is
pinned to TCPDF's built-in DejaVu Sans family (regular, bold, italic, and
bold-italic) plus the initial Helvetica core metrics required during TCPDF
construction. The release builder removes every other TCPDF font artifact and
retains the DejaVu license files. This keeps the WordPress.org ZIP bounded
without introducing runtime-generated font files.

The release tree also removes upstream examples, tests, tools, package-manager
metadata, and other development-only vendor artifacts. TCPDF's `LICENSE.TXT`
remains alongside its source.

## 2. In-place file transformations

SScribe-owned PHP, CSS, JS, and known text assets are passed through
the documented first-party strip/sanitization functions before they are
written to `dist/`. Binary first-party assets such as images and compiled
translations are copied byte-for-byte.

The entire generated `vendor-prefixed/` tree is handled separately:
after Strauss performs the intentional namespace/class isolation, third-party
source and license files are copied byte-for-byte into the release staging
tree. SScribe's comment stripper and Unicode sanitizer do not rewrite vendor
content. Explicit vendor pruning and the PHPWord license-filename normalization
listed below occur after that copy.

### PHP — `strip_php_comments( $source )`

Tokenizes the source via `token_get_all()` and walks the token
stream:

- `T_DOC_COMMENT` (/** ... */) — **preserved** (carries @preserve /
  @var / @type pragmas that static analysis requires).
- `T_COMMENT` (//, #, /* */) — **stripped** unless the comment body
  matches `/phpcs:|phpcs-disable|phpcs-enable|phpcs:ignore|
  translators:|@preserve/i` (pragma-annotated comments survive).
- `T_OPEN_TAG`, `T_STRING`, `T_VARIABLE`, `T_CONSTANT_ENCAPSED_STRING`,
  `T_INLINE_HTML`, etc. — emitted verbatim.

### CSS — `strip_css_comments( $source )`

Removes all `/* ... */` block comments and collapses runs of blank
lines. No license-banner preservation.

### JS — `strip_js_comments( $source )`

- Block comments `/* ... */` that are **not** `/*!` (license) or
  `/**` (jsdoc) are stripped.
- Line `//` comments are stripped unless preceded by `:`, `"`, `'`,
  or `` ` `` (keeps URLs like `https://` and JSON literal colons
  intact).
- License banners and jsdoc survive.

### Text files — `sanitize_ai_artifacts( $source )`

Applied only to SScribe-owned files: after the strip pass for first-party
PHP/CSS/JS and to the builder's explicit first-party text-extension allowlist.
Third-party `vendor-prefixed/` content and binary files bypass this sanitizer.
It replaces AI-artifact Unicode characters with ASCII equivalents:

| Char | Code | Replacement | Reason |
|------|------|-------------|--------|
| `—` | U+2014 | ` - `       | em-dash |
| `–` | U+2013 | `-`         | en-dash |
| `…` | U+2026 | `...`       | ellipsis |
| `“` | U+201C | `"`         | left double curly |
| `”` | U+201D | `"`         | right double curly |
| `‘` | U+2018 | `'`         | left single curly |
| `’` | U+2019 | `'`         | right single curly |

**Why a sanitizer and not a comment-stripper?** The build preserves
jsdoc and T_DOC_COMMENT content because they carry @preserve /
@var / @type pragmas. Without the sanitizer, em-dashes inside
docblocks would leak into the shipped ZIP — and `tests/Unit/
SScribe_Shipped_Invariants_Test.php::test_zip_has_no_em_dashes`
would fail the build.

---

## 3. Vendor-specific handling

- All `vendor-prefixed/` files that survive exclusion/pruning are copied
  byte-for-byte from the Strauss-generated tree. No SScribe comment stripping
  or Unicode sanitization is applied to third-party source or notices.
- TCPDF's extensionless development metadata `Makefile` and `VERSION` are
  removed before release-content validation.
- `vendor-prefixed/phpoffice/phpword/COPYING.LESSER` is renamed to
  `COPYING.LESSER.txt` in the dist. WP.org plugin-check rejects
  the bare `.lesser` extension as an unexpected file type, but the
  LGPL attribution notice is required.
- `vendor-prefixed/phpoffice/phpword/src/PhpWord/Shared/PCLZip/`
  is removed. PHPWord bundles its own PCLZip; WordPress core also
  ships PCLZip. Shipping both causes class-name conflicts.
- `vendor-prefixed/phpoffice/phpword/phpword.ini.dist` and
  `vendor-prefixed/phpoffice/phpword/phpmd.xml.dist` are removed.
  These are configuration samples never read by SScribe code.
- `includes/sscribe-vendor-compat.php` is removed. This is a
  local-development compatibility shim that re-exposes the
  unprefixed `PhpWord` / `TCPDF` class names so non-prefixed
  examples work in the dev environment. The release code only
  uses prefixed vendors.

---

## 4. Distribution paths created under a different relative name

One release path differs from its tracked source path:

- `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt` is copied
  byte-for-byte from
  `vendor-prefixed/phpoffice/phpword/COPYING.LESSER`. The renamed
  `.txt` path preserves the required LGPL notice while avoiding the
  unexpected-extension warning emitted by WordPress Plugin Check.

Every other file in `dist/sscribe-export-site-pages/` retains the
same relative path as its tracked source.

---

## 5. First-party source files that are rewritten and shipped

Only SScribe-owned source is rewritten by the strip/sanitize stage.
`vendor-prefixed/` is excluded from these transformations.

Examples (illustrative — full list varies per release):

- `admin/js/sscribe-admin.js` — comments stripped (license + jsdoc
  preserved), then sanitized.
- `admin/css/sscribe-admin.css` — all `/* */` comments stripped.
- `includes/class-sscribe-export-query-controller.php` —
  `T_COMMENT` stripped, `T_DOC_COMMENT` preserved (then sanitized),
  token stream otherwise untouched.

---

## 6. Files in the ZIP that do not exist at the same tracked path

Only `vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt`; it is the
byte-for-byte renamed copy documented in Sections 3 and 4. No file
content is synthesized.

---

## 7. Files NOT in the ZIP that ARE in the working tree

The full enumeration above in Section 1. To audit a single file,
inspect the `base_excludes`, `font_excludes`, and `fpdi_excludes`
arrays at the top of `scripts/build-release.php`.

---

## How to verify a build

```bash
rm -rf dist/sscribe-export-site-pages dist/sscribe-export-site-pages-2.0.3.zip
composer release 2>&1 | tee build.log
diff <(unzip -l dist/sscribe-export-site-pages-2.0.3.zip | awk '{print $4}' | sort) \
     <(find dist/sscribe-export-site-pages -type f | sed 's|dist/sscribe-export-site-pages/||' | sort)
# Last command should produce no output (ZIP listing == dist listing).
```

For the file-content checks (sanitizer, comment-stripper):

```bash
vendor/bin/phpunit tests/Unit/SScribe_Shipped_Invariants_Test.php
```

For the license-text sanity check (Phase 23):

```bash
vendor/bin/phpunit tests/Integration/SScribe_License_SPDIX_Test.php
```

---

## When you add a transformation

1. Edit `scripts/build-release.php` to add the transformation.
2. Edit this document to add a row in the matching section.
3. Edit the end-of-build echo block in `scripts/build-release.php`
   so reviewers see the new transformation in `composer release`
   output.
4. All three changes ship in the same commit.