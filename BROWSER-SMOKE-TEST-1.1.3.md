# Browser Smoke Test — sScribe Export Site Pages 1.1.3

Date: 2026-07-23
Surface: WP-Playground at `http://127.0.0.1:9412/wp-admin/admin.php?page=sscribe-export`
Plugin under test: develop @ `faa1277` (post `chore(release): exclude audit/screenshot artifacts from build ZIP`)

End-to-end export of a 1-page published site to HTML/DOCX/PDF/Markdown was
exercised through the real admin UI, including the modal preview, the
multi-stage generate pipeline, the success alert, and the downloaded ZIP.

## What passed

1. Export tab renders with Content Type (3 radios), Content Status (6 radios),
   Export Format (5 radios), HTML Options section, pre-export advisories, and
   "WILL EXPORT" summary chips.
2. History tab lists 3 prior exports (now 4 after the test run) with filename,
   timestamp, file size, language badge, and Download / Log / Delete actions.
3. Support tab renders the redacted environment snapshot and Copy / Refresh
   controls.
4. Debug tab renders the debug toggle, level dropdown, filter row, and the
   dark console panel with 164 entries.
5. Preview button opens the modal with Total Pages, Format, Language, Status,
   Estimated Time, Estimated File Size, sample content preview, and
   Start Export / Close actions.
6. Generate Package button fires the full `start_export → process_batch →
   finalize_export` chain.
7. The finalizer returns the new success payload fields (`pages`, `formats`,
   `file_size`, `size`) and the success alert renders PAGES, FORMATS, FILE
   SIZE, GENERATED chips with correct values.
8. Download ZIP URL works: HTTP 200, `Content-Type: application/zip`,
   `Content-Disposition: attachment; filename="..."` (both RFC 2616 and
   RFC 5987 UTF-8 forms present), 1554-byte ZIP delivered.
9. Tab navigation flows cleanly between all four tabs in both light and dark
   modes; no JS console errors during normal flows (heartbeat aborts are
   expected under navigate).
10. v3 design tokens render correctly in both color schemes.

## Real visual / a11y bugs to fix before 1.1.4

These were visible on screen and worth acting on. None block 1.1.3 — they
all degrade polish, not correctness.

### Bug 1 — Markdown format card breaks the word mid-character

The fifth format card ("Markdown" + "Portable markdown text") wraps the
word "Markdown" as `Markdow` + `n`. Same column width as the four cards to
its left, but "Markdown" is the only single-word title that overflows the
text container, so the title underline break lands in the middle of a word.

Likely cause: `.sscribe-format-option-card` title is given a fixed or
`min-width` that crowds the text and the only soft-break opportunity is
inside the word. Fix path: widen the text container by a few px, OR allow
the title to wrap on a hyphen / non-breaking space, OR use a slightly
smaller font-size only on the title for that width tier, OR introduce
`overflow-wrap: anywhere` as a graceful-degradation fallback.

Severity: WARN — visible on every load of the Export tab, no other card
exhibits it, but the panel still functions.

### Bug 2 — Format-card secondary descriptions are dim under dark mode

Under `prefers-color-scheme: dark`, the descriptions beneath DOCX / PDF /
HTML / Markdown ("Editable Word document", "Print-ready document",
"Single-page HTML file", "Portable markdown text") render in a grey that
falls under 4.5:1 against the card surface. The titles and the green
selected-tile accent stay AA, but the second line breaches it.

Likely cause: the `--ss-format-card-description` token family was set for
the light surface and didn't get a dark-mode override (or its override is
too close to the surface). Fix path: dark-mode override that targets
`.sscribe-format-option-description` with the already-defined
`--ss-text-secondary` dark token.

Severity: WARN — only affects dark-mode readers, but it is the dark-mode
AA failure we already audited for in commit `28bfab7` and we regressed
slightly.

### Bug 3 — History tab language badge ("AL") low contrast under dark mode

The "AL" badge in the language column renders as a dark square on a dark
background; the letter inside is barely legible.

Likely cause: language-badge background uses the same surface-token
elevation for both color schemes, no `--ss-badge-*-bg` override for dark.
Fix path: give the badge a slightly lighter surface fill in dark mode, or
use a coloured border + transparent fill.

Severity: WARN — visible on every History tab render in dark mode.

### Bug 4 — Debug tab form-control borders invisible under dark mode

The "LOG LEVEL" and "Filter" dropdowns, the "Session ID" and "Search Logs"
inputs render with borders that visually disappear against the card
background; the fields read as flat rectangles, not as interactive
controls.

Likely cause: input/select border was set with a single token that does
not get enough elevation in dark mode. Fix path: same pattern as Bug 2 —
dark-mode override for `--ss-input-border` pushing to a higher contrast
token (e.g. `rgba(255,255,255,0.18)` to `rgba(255,255,255,0.25)`).

Severity: WARN — accessibility-cost on the Debug tab only; functional
impact low because the controls still work.

## What was specifically NOT a bug

These came up during the smoke test and were verified clean:

- "HTML Options" parent under Markdown/HTML panels is a heading (h3) with
  an icon, not an empty checkbox. The two children "Inline CSS styles"
  and "Responsive image markup" are correctly checked.
- The bottom-right green checkmark observed in dark-mode screenshots is
  the WP_DEBUG advisory dismiss control, not a stray toast.
- Heartbeat + occasional fetch aborts in the network panel are normal
  under repeated navigations during browser automation, not plugin bugs.

## Recommended follow-ups for 1.1.4

1. Open four atomic CSS-PRs (one per bug above) — each is a single token
   override. Ship all four together as
   `fix(ui): dark-mode AA fixes for format-desc, language-badge, form-`
   `control-borders, and Markdown card word-break`.
2. After the CSS changes, re-take the same four screenshots (light Export,
   light History, dark Export, dark Debug) to verify AA passes on the
   affected text.
3. The FPDI CVE bump (`SECURITY-AUDIT-1.1.3.md` → Deferred) should land
   in 1.1.4 alongside the mPDF minor bump that pulls FPDI 2.6.7+.

## Build verification

- ZIP checksum unchanged (after the `faa1277` exclusion fix): the prior
  build was already clean of `tab-export-*`, `.export-*`, `.audit/`,
  `playground-*`, `fake-wp/`, etc.
- 690 PHPUnit tests still pass.
- PHPStan still clean.
- PHPCS still clean.
