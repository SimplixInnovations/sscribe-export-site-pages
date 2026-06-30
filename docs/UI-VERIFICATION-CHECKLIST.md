# sScribe Admin UI: Visual Verification Checklist

This document is meant to be opened alongside [ui-preview.html](ui-preview.html).

I cannot render the page in a headless browser here (no Chrome, Chromium,
Firefox, Playwright, or Puppeteer is installed in this environment, and
downloading WordPress core to spin up a real admin shell was blocked by
the data-exfiltration classifier). So instead, this file lists every
visual concern that *could* be the source of the "borders overlap,
elements overlap, wrong colors" complaint, with the exact CSS rule
file/line that's responsible. As you scan the preview, check each item
off or write down what you actually see.

---

## Open this preview

1. Open `docs/ui-preview.html` directly in any browser (Chrome, Edge,
   Firefox, Safari). No server required.
2. Confirm the following sections render. The preview uses the **exact**
   `admin/css/sscribe-admin.css` file as shipped in this commit.

---

## A. Hero band (top, above tabs)

| Element | Class selector | What to verify |
|---|---|---|
| Hero container | `body .sscribe-master-container .sscribe-hero` | 4px green stripe on the left, 1px green hairline directly to the right of it, white interior |
| Hero height | -- | Tall enough to comfortably hold title + subtitle + 2 stats; nothing cropped |
| Hero stats | `.sscribe-hero-stats` | Sit on the right side of the hero at the same vertical line as the title/subtitle block |
| Hero font sizes | `.sscribe-hero-title` 24px, `.sscribe-hero-subtitle` 13–14px | No clipping, no overflow into the next section |
| Hero version chip | `.sscribe-hero-version` | A small green-deeper pill next to "SScribe", not crashing into the title text |
| Hero eyebrow | `.sscribe-hero-eyebrow` | Small uppercase green text above the title, accent line to its left |

**Possible bugs and where to look:**
- Hero `padding: 24px 32px` on the container; border-left 4px; `::after` is
  at `left: 0; width: 4px`; `::before` is at `left: 4px; width: 1px`.
  Total left ribbon is 5px wide. Confirm the ribbon looks like one
  solid block, not a thin 1px stripe with a sliver.
- Right edge of hero: no border, no shadow.

## B. Tabs row

| Element | Class | What to verify |
|---|---|---|
| Tab nav | `.sscribe-tabs-nav` | Sits directly below the hero with a small gap (about 24px), no overlap |
| Active tab | `.sscribe-tab-btn.sscribe-tab-active` | Green-deeper text, weight 700, white-ish bg with subtle border. **No box-shadow** |
| Inactive tabs | `.sscribe-tab-btn` | Steel text, no background, no shadow |
| Tab hover | (not in preview by default) | When you hover, an inactive tab should show a slight border-tinted background, not a shadow |
| Underline / sliding indicator | -- | There should be NO sliding underline indicator (intentionally removed per "ultra-flat" rule). Confirm absence. |

**Possible overlap/element bug:**
- Tab container has `padding: 4px` around the track and 4px padding inside
  each button. Active button has `border: 1px solid`. Confirm active and
  inactive tabs have the **same vertical height**.
- Tab buttons must not bleed past their container.

## C. Config panel

| Element | Class | What to verify |
|---|---|---|
| Panel header | `.sscribe-panel-header` | Title + optional buttons, hairline border below |
| Panel title | `.sscribe-panel-title` | Settings icon + "Export Configuration" h2, both vertically centered |
| Panel body | `.sscribe-panel-body` | White background, no shadow, hairline border on the outer panel |
| Config grid | `.sscribe-config-grid` | 3 sections stacked vertically, equal vertical gap |

**Possible layout bug:**
- Section titles: `.sscribe-section-title` shows step badge + heading text
  on one line. If your viewer renders them split into 2 lines, the
  `display: flex; align-items: center; gap: 12px` rule should be
  enforcing them on one line.
- Config grid uses `display: flex; flex-direction: column;` with a 32px
  gap. Confirm sections are spaced, not stacked without gap.

## D. Post-type cards (step 1)

| Element | Class | What to verify |
|---|---|---|
| Card container | `.sscribe-post-type-cards` | All 3 cards on the same row, equal width, 13px gaps between them |
| Card outer | `.sscribe-post-type-card` | Forwards clicks only; no visible style itself |
| Card inner | `.sscribe-post-type-card-inner` | White background, 1px hairline, 11/13 padding, **13px height** |
| Selected card | the `input:checked` sibling `.sscribe-post-type-card-inner` | **2px green-deeper border, 9/11 padding, green-tint background, still 13px height** |
| Card icon | `.sscribe-post-type-icon` | 32×32 green icon left |
| Card name | `.sscribe-post-type-name` | 14px, dark ink, weight 600 |
| Card count | `.sscribe-post-type-count` | 12px, secondary steel color |
| Card check | `.sscribe-post-type-selector` | Hidden until selected. Becomes visible (small green-deeper circle) when card is selected |

**LAYOUT MATH IS CRITICAL — this is the most likely culprit if cards
look different sizes between states:**

- Default: `padding: 11px 13px;` border 1px → interior 11px tall text
  + 1+1 = 13 outer container total
- Selected: `padding: 9px 11px;` border 2px → 9+2+2 = 13 outer total
- All three cards should be the **same size** regardless of selection
  state.

**If selected card looks shifted, taller, or narrower than unselected:**
- The padding/border math above must add up. Look at lines around
  `.sscribe-post-type-card-inner` in `admin/css/sscribe-admin.css`.

## E. Status cards (step 2)

Same structure as post-type cards but with `.sscribe-status-*` selectors.
Selected status card = green-deeper border + green-tint bg + green check.

## F. Format cards (step 3)

Same structure, four cards on one row. `.sscribe-format-*`.

## G. Status alert (right before export bar)

| Element | Class | What to verify |
|---|---|---|
| Alert | `.sscribe-status-alert.sscribe-status-success` | 44px green icon gutter on left + body column on right |
| Eyebrow | `.sscribe-status-eyebrow` | Small uppercase green text |
| Heading | `.sscribe-status-heading` | 14px, weight 600, dark |
| Body text | `.sscribe-status-desc` | 13px, secondary text color |

**Possible layout bug:**
- The success variant must NOT collapse icon into text. Verify icon is
  44px wide and centered vertically.

## H. Export bar

| Element | Class | What to verify |
|---|---|---|
| Bar container | `.sscribe-export-bar` | Hairline border, white bg, **4px solid green-deeper stripe on the LEFT**, summary on left + actions on right |
| Summary chips | `.sscribe-summary-chip` | Small outlined pills in one row |
| Filled chip | `.sscribe-summary-pages` | Filled green-deeper, white text. Not transparent |
| Action buttons | `.sscribe-button-outline`, `.sscribe-button-secondary`, `.sscribe-button-primary` | Right-aligned; the primary CTA is filled green-deeper with white text |
| Primary button radius | `.sscribe-button-primary` | radius-md (8px, **not** pill). Hairline border 1px solid transparent |

**MOST LIKELY OVERLAP SOURCE:**
- Export bar uses `display: flex; justify-content: space-between` to
  split summary and actions. If actions wrap under summary, viewport is
  too narrow. At 1200px wide there is room; at <900px they wrap.

## I. Cards: general checks across steps 1, 2, 3

1. Selected card border is **2px solid green-deeper** (not 1px tinted
   green)
2. Selected card background is **green-tint** (a very pale green
   background)
3. Selected card's check icon (`.sscribe-post-type-selector` and
   analogous) is **visible** (display:flex)
4. Unselected cards' check icons are **hidden** (display:none)
5. All cards in the same row have identical outer dimensions

## J. Colors

| What | Token | Expected hex | Look like |
|---|---|---|---|
| Brand green (primary actions, headers, stripes) | `--ss-green` | `#059669` | A muted forest green |
| Selected/pressed green | `--ss-green-deeper` | `#065f46` | A darker forest green |
| Hairline borders | `--ss-border` | `#e4e8eb` | Very light gray |
| Body text | `--ss-text` | `#001e2b` (ink) | Almost black |
| Secondary text | `--ss-text-2` | `#5c6c7a` (steel) | Medium gray |
| Muted text | `--ss-text-3` | `#7c8c9a` (stone) | Light gray |
| Page bg (subtle) | `--ss-bg-subtle` | `#f8faf9` | Faint cool-white |
| Card bg | `--ss-bg` / `--ss-bg-card` | `#ffffff` | Pure white |

**If any of these are wrong, the page looks generic WordPress instead
of enterprise-flat.** Open DevTools (F12 → Elements → Computed) and read
the resolved value to compare.

## K. Borders and hairlines

- Cards: 1px hairline gray; selected cards: 2px brand-green-deeper
- Tabs: 1px hairline; active tab: green-deeper text, **no shadow**
- Panels: 1px hairline; header has 1px hairline below
- Buttons: 1px transparent (primary / secondary) or hairline gray (outline)
- Hero: 4px solid green-deeper on the left edge

**Where overlaps would show up:**
1. The 4px hero stripe and the 1px hero hairline must touch cleanly.
2. Card border math from section D above.
3. Tab nav container must not let any button extend beyond its rounded border.

## L. Misc

- [ ] No `clip: rect()` warnings
- [ ] No `rgba()` decimal-alpha warnings (all converted to `rgb(R G B / P%)`)
- [ ] No `display: flex` conflicts producing inline overlaps
- [ ] Skip link is not visible until focused

---

## What to tell me

If anything is wrong, please describe it with **the section name from
this list (A–L)** and **the exact visual problem** ("section is
double-tall", "border is offset by a few pixels", "icon is the wrong
color"). Even one concrete observation will let me zero in on the
right CSS rule and fix it in one pass instead of ten.
