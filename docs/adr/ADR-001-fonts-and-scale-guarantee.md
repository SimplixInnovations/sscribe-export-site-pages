# ADR-001: Fixed export fonts, branded admin fonts, and a 50,000-page guarantee

**Status:** Proposed
**Date:** 2026-10-09
**Deciders:** Product owner (Simplix Innovations), engineering

## Context

The plan was to let users pick Google Fonts for exports. Validation on 2026-10-09 showed:

- **No single font file covers every language.** The bundled PDF font, DejaVu Sans, covers Latin,
  Greek, Cyrillic, Hebrew, Arabic and Persian. It has 0% coverage of Chinese, Japanese, Korean,
  Hindi and Thai.
- **Urdu is broken today.** DejaVu Sans lacks heh goal (U+06C1) and yeh barree (U+06D2) and
  their shaping forms, although the readme promises Urdu RTL output. Noto Sans Arabic covers all
  of them, and all Arabic and Persian letters.
- **The PDF engine shapes Arabic through Unicode presentation forms (U+FB50–U+FEFF).** A font is
  only safe if it carries those glyphs. Inter has none, Cairo shows gaps, and Noto Sans Arabic has
  all of them.
- **PDF font subsetting does not take effect.** Output size is identical with subsetting on and off,
  so every PDF embeds the whole font. Your own audit measured a short one-page PDF at about 1 MB.
- **Archives are assembled in one request.** `SScribe_Zip_Handler::create_zip()` adds every file and
  writes the archive in a single `close()` at finalize.
- **ZIP64 works.** The bundled libzip 1.22.8 wrote and read back a 70,000-entry archive, past the
  classic 65,535 limit. The write took 17 s even for tiny files.
- **Bundled fonts must be GPL-compatible.** WordPress.org accepts SIL OFL fonts. It rejects
  commercially licensed fonts, and has flagged Apache-licensed fonts in themes.
- **Google Fonts from Google's servers carry legal risk.** LG München (3 O 17493/20, 2022) awarded
  damages for loading them without consent. Guideline 8 permits font CDNs, but self-hosting is the
  safe default.

## Decision

1. **No user-selectable export fonts and no Google Fonts.** Exports use one fixed font family. The
   plugin picks the right file per script automatically, so users never choose.
2. **The export family is Noto,** commissioned by Google for exactly this purpose and licensed under
   the SIL OFL:
   - Noto Sans: Latin, Greek and Cyrillic
   - Noto Sans Arabic: Arabic, Persian and Urdu
   - Noto Sans Hebrew: Hebrew

   Each family ships in Regular and Bold static instances, never variable fonts. Noto replaces
   DejaVu Sans after a side-by-side test.
3. **The guaranteed language list is exactly what those files cover.** Before an export starts, a
   preflight check detects characters outside the bundled fonts, such as Chinese, Japanese, Korean,
   Hindi or Thai. It warns the user that those characters won't render in PDF, instead of
   silently producing empty boxes. Packs for those scripts are a later, separate decision, because
   CJK fonts alone are tens of megabytes.
4. **Admin branding fonts are separate.** They style only the plugin's own screens and never reach
   exported files. They must be SIL OFL or another GPL-compatible license, bundled as subset WOFF2
   files, and loaded only on SScribe screens. A commercially licensed agency typeface cannot ship on
   WordPress.org.
5. **The tested guarantee is 50,000 pages per export.** It holds on a defined reference
   environment and covers every format and every guaranteed language. Larger sites may work but are
   not guaranteed.

## Options considered

### A: User-selectable Google Fonts
| Dimension | Assessment |
|---|---|
| Complexity | High: per-script fallback, coverage checks, weight import, legal consent |
| Risk | High: empty boxes for fonts without presentation forms; GDPR exposure |
| User value | Moderate; most users never change fonts |

### B: Curated list of a few tested fonts
| Dimension | Assessment |
|---|---|
| Complexity | Medium: each font needs the full coverage and shaping test for every script |
| Risk | Medium: package size grows with every family and every script |
| User value | Moderate |

### C: One fixed family, script files chosen automatically (chosen)
| Dimension | Assessment |
|---|---|
| Complexity | Low to medium: one fallback map and one preflight check |
| Risk | Lowest: one tested set and one license |
| User value | High: consistent output that never shows boxes in guaranteed languages |

## Trade-off analysis

The user loses font choice in exports. In exchange, every guaranteed language renders correctly
every time, the package stays small, and there is no legal exposure. Brand identity still reaches
the admin experience through the branded admin fonts.

## Consequences

- **Easier:** testing (one family), support, and the WordPress.org review.
- **Harder:** CJK, Indic and Thai sites remain outside the guarantee until script packs exist.
- **Fixed as part of this decision:** Urdu PDF output.
- **Revisit:** script packs once there is demand; DOCX font embedding (possible by post-processing
  the package with `w:embedTrueTypeFonts`, since PhpWord cannot embed).

## Prerequisites for the 50,000-page guarantee

These must land before the guarantee is published:

1. **Working PDF font subsetting.** At about 1 MB per PDF, 50,000 pages would be about 50 GB.
2. **Resumable or split archive assembly.** About 200,000 files (four formats) cannot be added and
   closed safely inside one PHP request. Archives must either build incrementally across requests or
   split into numbered parts.
3. **A dedicated queue table** if measurement shows the options-table queue degrading at this size.
4. **A defined reference environment:** PHP version, memory limit, max execution time, database and
   disk, plus a published duration target.
5. **A repeatable generator** for a 50,000-page multilingual test site, run on every release.

## Action items

1. [ ] Fix PDF font subsetting and prove the size drop on the reference page.
2. [ ] Fix Urdu now by adding Noto Sans Arabic for Arabic-script runs.
3. [ ] Replace DejaVu with the Noto set after a side-by-side rendering test in every guaranteed
       language.
4. [ ] Add the preflight check for characters outside the bundled fonts.
5. [ ] Make archive assembly resumable or split into parts.
6. [ ] Build the 50,000-page generator and the reference environment, then run and publish the results.
7. [ ] Confirm the agency's typefaces are SIL OFL or GPL-compatible before design work starts.
8. [ ] Correct the readme's language claims to match the tested list.
