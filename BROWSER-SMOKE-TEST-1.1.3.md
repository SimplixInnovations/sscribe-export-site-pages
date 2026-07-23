# Browser Smoke Test — sScribe Export Site Pages 1.1.3

Date: 2026-07-23
Surface: WP-Playground at `http://127.0.0.1:9412/wp-admin/admin.php?page=sscribe-export`
Plugin under test: develop @ `b74ab76` (post `fix(ui): grid layout for format cards + visible modal close + debug toggle styling + clear-logs confirm fix + readable rotated log filenames`)

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

## Real visual / a11y bugs found and fixed before 1.1.3 ship

These were visible on screen and shipped in the same release as the
audit. Commit `b74ab76` (CSS-only, no PHP changes).

### Bug 1 — Markdown format card breaks the word mid-character ✅ FIXED

The fifth format card ("Markdown" + "Portable markdown text") wrapped
the word "Markdown" as `Markdow` + `n`. Same column width as the four
cards to its left, but "Markdown" is the only single-word title that
overflowed the text container, so the title underline break landed in
the middle of a word.

Root cause: `.sscribe-format-meta` was set with `min-width: 0` (the
default flex shrink behaviour) and `.sscribe-format-name` had
`overflow-wrap: anywhere;` — together these force a mid-character break
the moment the title is even 1px wider than the text container.

Fix in `admin/css/sscribe-admin.css`:
- `.sscribe-format-card-inner` `min-width: 130px → 152px`
- `.sscribe-format-meta` `min-width: 0 → max-content` (text container
  sizes to its longest word instead of being squeezed by the row flex)
- `.sscribe-format-name` `overflow-wrap: anywhere → normal`,
  `hyphens: auto → manual` (no more forced mid-word breaks)

Verified: all 5 cards now report `offsetHeight: 17px, lineCount: 1`
after reload with `?ignoreCache`.

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
- **History tab bulk delete** (`#sscribe-bulk-delete-btn`) — verified
  working: opens `showConfirm()` modal with title "Delete selected
  exports?" and a per-row filename list. Earlier test attempt
  incorrectly selected non-`.sscribe-history-check` checkboxes, which
  is why the count was 0 and no modal appeared.

## Additional UI/UX bugs found and fixed in `b74ab76`

Seven follow-on issues surfaced during a focused second-pass review of
the v3 polish surfaces. All addressed in the same CSS-only commit.

### Bug 5 — Export Format cards overflow the radio circle

**Symptom:** The radio circles on the right edge of the Export Format
cards visually overflowed the card border in some widths, making it
look like the circle was outside the card.

**Root cause:** The card-inner used `display: flex` with no fixed
gutter for the radio column. The text column (`minmax(0, 1fr)`)
shrank the radio column below its intrinsic 18px width when text was
long.

**Fix:** Converted `.sscribe-post-type-card-inner,
.sscribe-status-card-inner, .sscribe-format-card-inner,
.sscribe-lang-card-inner` from flex to grid with
`grid-template-columns: 32px minmax(0, 1fr) 18px` so the radio column
has a predictable 18px reservation regardless of text length.

### Bug 6 — History tab log modal close button invisible

**Symptom:** Pressing any row "Log" button opened the modal but the
close X button was a 40px transparent button that visually disappeared
into the modal surface — looked unclickable.

**Root cause:** `.sscribe-modal-close` had `background: transparent;
border: none` and sat at the same z-index as the modal title row.

**Fix:** Reshaped to 36px rounded circle with `--ss-surface-2`
background + `--ss-border`, added `:hover` color/border from
`--ss-error` palette, set `z-index: 2` + `position: relative` so it
sits above the modal title row. Also widened
`#sscribe-log-modal .sscribe-modal-content` to 720px so the Total
Pages / Format / Language / Status / Estimated Time cards don't wrap.

### Bug 7 — Debug Console "Enable Debug Logging" toggle has no visual active state

**Symptom:** When checked, the toggle looked identical to its
unchecked state — just a small slider color change inside an empty
container.

**Fix:** Wrapped the toggle in a `.sscribe-debug-toggle-section` card
with `--ss-surface-2` background + `--ss-border`, switched to a green
tint via `:has(input:checked) { background: --ss-success-bg,
border-color: --ss-success-border }` so the active state reads as a
confirmation, not a checkbox sitting in space.

### Bug 8 — CRITICAL: Clear Logs button silently fails after first click

**Symptom:** Click "Clear Logs" — button changes to "Click to confirm"
— click again — nothing happens. After 3 seconds, button reverts to
"Clear Logs" with no AJAX call made.

**Root cause:** `.sscribe-btn-confirming` was merged into the same
selector as `.sscribe-btn-busy`, which set `pointer-events: none`.
After the first click set the confirming state, the second click
literally could not register. The user saw "I click and nothing
happens" — the button reverts silently after the 3-second timeout,
exactly because the second click never fired.

This was a CLASS bug: the same broken pattern was inherited by every
two-click confirm button in the plugin — Clear Logs, Rotated Logs
Delete, etc. The user reported all three as "not working" because they
were all blocked by the same selector merge.

**Fix:** Split into separate rules:
- `.sscribe-btn-busy` keeps `pointer-events: none` (real in-flight AJAX
  should not accept clicks).
- `.sscribe-btn-confirming` keeps `pointer-events: auto` +
  `cursor: pointer` and signals active state via a 1.2s
  `sscribe-confirm-pulse` keyframes pulse animation with
  `prefers-reduced-motion: reduce` fallback.

### Bug 9 — Rotated log file name invisible on light surface

**Symptom:** `.sscribe-debug-rotated-file-name` rendered the rotated
log filename in `#fafafa` (near-white) on the debug surface, making
it literally invisible. Two related colors had the same problem:
`.sscribe-debug-rotated-file` border-top `#3f3f46` and
`.sscribe-debug-rotated-file-meta` color `#a1a1aa` were clearly
copy-pasted from the dark console panel styling.

**Why v3 polish missed it:** The rotated-logs list had zero entries in
the smoke-test data, so the filename/metadata selectors were never
painted during the dark-mode audit. The CSS only renders when
rotation has actually happened (file > MAX_LOG_FILE_SIZE).

**Fix:** Replaced all three hex values with tokens:
- filename: `var(--ss-text-primary)` (rgb(9,9,11) near-black)
- meta: `var(--ss-text-secondary)` (rgb(63,63,70))
- border-top: `var(--ss-border)`

## Recommended follow-ups for 1.1.4

1. Open one atomic CSS-PR covering Bugs 2, 3, and 4 (the dark-mode AA
   regressions):
   `fix(ui): dark-mode AA fixes for format-desc, language-badge,
   form-control-borders`.
2. After the dark-mode CSS changes, re-take the same four screenshots
   (light Export, light History, dark Export, dark Debug) to verify AA
   passes on the affected text.
3. The FPDI CVE bump (`SECURITY-AUDIT-1.1.3.md` → Deferred) should land
   in 1.1.4 alongside the mPDF minor bump that pulls FPDI 2.6.7+.
4. Audit any other `.sscribe-button` state classes that share
   selectors (e.g. `disabled`, `loading`, `error`) — the Bug 8 fix
   only split `btn-busy` from `btn-confirming`. A future audit pass
   should confirm no other destructive state class is sharing the
   `pointer-events: none` rule.

## Build verification

- ZIP checksum unchanged (after the `faa1277` exclusion fix): the prior
  build was already clean of `tab-export-*`, `.export-*`, `.audit/`,
  `playground-*`, `fake-wp/`, etc.
- 690 PHPUnit tests still pass.
- PHPStan still clean.
- PHPCS still clean.
