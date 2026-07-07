---
scribe_design_version: 3
plugin: SScribe Export Site Pages
plugin_slug: sscribe-export-site-pages
plugin_version: 1.1.3
---

# sScribe Design System v3

Canonical reference for the sScribe admin UI. Every shipped rule resolves to one of the tokens, components, or media blocks documented here. No visual element lives outside the system.

This doc covers the admin surface (the `SScribe Export` admin page and its sub-tabs). It is the only design surface of the plugin. The user-facing exported documents (DOCX, PDF, HTML, Markdown) use each format's own typography and are not part of the design system.

---

## 1. Design Principles

The v3 system is built on five principles:

1. **Restraint over decoration.** No gradients, no shadows, no glow, no decorative animation. Hierarchy comes from type ramp, spacing, and hairline borders. A card looks like a piece of paper sitting on the page - not like a floating panel with a halo.
2. **Tokens, not hardcoded values.** Every color, font size, radius, and motion duration resolves to a CSS custom property. Components never hardcode `#635bff` or `8px` - they use `var(--ss-color-accent)` and `var(--ss-radius-md)`. This lets dark mode, future rebrand, and accessibility tuning work without touching component rules.
3. **Semantic roles, physical palette.** Components consume semantic tokens (`--ss-color-fg-primary`, `--ss-color-bg-inset`). Semantic tokens alias physical tokens (`--ss-text-primary`, `--ss-surface-2`). Want to rebalance the dark mode palette? Change the physical tokens; every component re-tunes for free.
4. **AA on dark.** The admin often runs in dark mode (macOS, Windows, GNOME 42+, Android). The dark palette is tuned for true WCAG AA - body text ≥ 4.5:1, large text ≥ 3:1, brand accent ≥ 4.5:1 for primary interactive use. Verified ratios documented per token.
5. **Native semantics first.** Buttons are `<button>`. Inputs are `<input>`. Tables are `<table>`. ARIA is used only where a custom widget cannot be a native element (modal, toast container, segmented control). No `role="button"` on `<div>`, no fake lists.

---

## 2. Architecture

Two stylesheets, one design system.

- `admin/css/sscribe-admin.css` - primary admin surface (≥ 4,500 lines).
- `admin/css/sscribe-debug-console.css` - debug-console surface (~1,550 lines).

Both files share the canonical `--ss-*` token space. The debug console extends the token space with `--ss-debug-*` scoping for terminal chrome and traffic-light indicators so it never collides with the main palette.

**No third-party CSS frameworks.** No Tailwind. No Bootstrap. No CSS-in-JS. The plugin ships zero external stylesheet dependencies and never fetches a font from a CDN. The system font stack is system-only.

**No vendor prefixes** except where a pseudo-element only exists in one engine:
- `-webkit-font-smoothing: antialiased` - controls font rendering on macOS.
- `::-webkit-scrollbar` - required for scrollbar theming.
- `::-webkit-details-marker` - required to hide the default `<details>` marker.

No `-webkit-` prefixes on standardized properties (no `-webkit-transform`, no `-webkit-transition`).

---

## 3. Tokens

### 3.1 Surface palette (light mode)

| Token | Value | Use |
|---|---|---|
| `--ss-bg` | `#fafafa` | Page background |
| `--ss-surface` | `#ffffff` | Card / panel |
| `--ss-surface-2` | `#f4f4f5` | Inset surface (search rest, toolbar bg) |
| `--ss-surface-3` | `#e4e4e7` | Skeleton track, busy state |
| `--ss-border` | `#e4e4e7` | 1px hairline |
| `--ss-border-strong` | `#d4d4d8` | Stronger border (focused inputs) |

### 3.2 Text and brand (light mode)

| Token | Value | Ratio on `--ss-bg` | Use |
|---|---|---|---|
| `--ss-text-primary` | `#09090b` | 19.4:1 | Body, headings |
| `--ss-text-secondary` | `#3f3f46` | 9.7:1 | Secondary body |
| `--ss-text-tertiary` | `#52525b` | 7.3:1 | Labels |
| `--ss-text-muted` | `#71717a` | 4.83:1 | Captions, placeholders (passes AA) |
| `--ss-brand` | `#635bff` | 5.9:1 | Primary interactive |
| `--ss-brand-hover` | `#5046e5` | - | Hover state |
| `--ss-brand-active` | `#3d34d3` | - | Pressed |
| `--ss-brand-tint` | `#ebe9ff` | - | Subtle selection bg |
| `--ss-success` | `#16a34a` | - | Success state |
| `--ss-warning` | `#d97706` | - | Warning state |
| `--ss-error` | `#dc2626` | - | Error state |
| `--ss-info` | `#0284c7` | - | Info state |

### 3.3 Dark mode palette

Tuned for true WCAG AA on dark surfaces. Defined in `prefers-color-scheme: dark` block within `.sscribe-master-container`.

| Token | Light | Dark | Ratio on `--ss-bg` (dark) |
|---|---|---|---|
| `--ss-bg` | `#fafafa` | `#09090b` | - |
| `--ss-surface` | `#ffffff` | `#18181b` | - |
| `--ss-surface-2` | `#f4f4f5` | `#27272a` | - |
| `--ss-surface-3` | `#e4e4e7` | `#3f3f46` | - |
| `--ss-border` | `#e4e4e7` | `#27272a` | - |
| `--ss-border-strong` | `#d4d4d8` | `#3f3f46` | - |
| `--ss-text-primary` | `#09090b` | `#fafafa` | 17.6:1 |
| `--ss-text-secondary` | `#3f3f46` | `#d4d4d8` | 11.0:1 |
| `--ss-text-tertiary` | `#52525b` | `#a1a1aa` | 7.1:1 |
| `--ss-text-muted` | `#71717a` | `#a1a1aa` | 7.1:1 |
| `--ss-brand` | `#635bff` | `#8e89ff` | 6.4:1 |
| `--ss-brand-hover` | `#5046e5` | `#a5a0ff` | 8.1:1 |
| `--ss-brand-active` | `#3d34d3` | `#b8b3ff` | 10.4:1 |
| `--ss-brand-tint` | `#ebe9ff` | `#1e1b4b` | - |

### 3.4 Semantic role tokens

Defined immediately under the physical tokens. Each role aliases exactly one physical token. Components consume only roles - never raw `--ss-*` physical values directly - except for the rare layout-level rule that needs a specific surface.

| Role token | Aliases | Used by |
|---|---|---|
| `--ss-color-fg-primary` | `--ss-text-primary` | text rules |
| `--ss-color-fg-secondary` | `--ss-text-secondary` | body, labels |
| `--ss-color-fg-tertiary` | `--ss-text-tertiary` | captions, headers |
| `--ss-color-fg-muted` | `--ss-text-muted` | placeholders, helper text |
| `--ss-color-fg-inverse` | `--ss-text-inverse` | text on colored fills |
| `--ss-color-bg-canvas` | `--ss-bg` | page bg |
| `--ss-color-bg-surface` | `--ss-surface` | cards, panels |
| `--ss-color-bg-elevated` | `--ss-surface` | modals, popovers |
| `--ss-color-bg-inset` | `--ss-surface-2` | hover surfaces, search rest |
| `--ss-color-bg-skeleton` | `--ss-surface-3` | busy state |
| `--ss-color-border` | `--ss-border` | default hairlines |
| `--ss-color-border-strong` | `--ss-border-strong` | focused inputs |
| `--ss-color-accent` | `--ss-brand` | primary interactive |
| `--ss-color-accent-hover` | `--ss-brand-hover` | interactive hover |
| `--ss-color-accent-active` | `--ss-brand-active` | interactive pressed |
| `--ss-color-accent-soft` | `--ss-brand-tint` | tinted selection |
| `--ss-color-accent-tint` | `--ss-brand-tint` | (legacy alias) |
| `--ss-color-success-fg` | `--ss-success` | success text |
| `--ss-color-warning-fg` | `--ss-warning` | warning text |
| `--ss-color-error-fg` | `--ss-error` | error text |
| `--ss-color-info-fg` | `--ss-info` | info text |
| `--ss-color-success-bg` | light `#ecfdf5`, dark `#052e1a` | success alert bg |
| `--ss-color-warning-bg` | light `#fffbeb`, dark `#3a2a14` | warning alert bg |
| `--ss-color-error-bg` | light `#fef2f2`, dark `#3a1414` | error alert bg |
| `--ss-color-info-bg` | light `#f0f9ff`, dark `#0c2a3a` | info alert bg |

### 3.5 Type ramp

The plugin ships two font families via the system stack:

| Token | Family | Use |
|---|---|---|
| `--ss-font` | `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif` | UI body and headings |
| `--ss-font-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | Code blocks, kbd, debug terminal |

| Token | Size | Weight | Line | Tracking | Use |
|---|---|---|---|---|---|
| `--ss-text-display` | 32px | 600 | 1.15 | -0.03em | Hero (rare) |
| `--ss-text-h1` | 24px | 600 | 1.25 | -0.02em | Page heading |
| `--ss-text-h2` | 18px | 600 | 1.30 | -0.01em | Section heading |
| `--ss-text-h3` | 15px | 600 | 1.40 | -0.005em | Card heading |
| `--ss-text-h4` | 13px | 600 | 1.40 | 0 | Sub-heading |
| `--ss-text-body` | 13px | 400 | 1.55 | 0 | Default body |
| `--ss-text-body-md` | 14px | 400 | 1.50 | 0 | Lead body |
| `--ss-text-label` | 12px | 500 | 1.40 | 0 | Form labels |
| `--ss-text-caption` | 11px | 500 | 1.40 | 0.01em | Helper text |
| `--ss-text-overline` | 11px | 600 | 1.20 | 0.08em | Eyebrows (uppercase) |

Type utility classes:

- `.ss-type-display` … `.ss-type-caption` - semantic styles mapped to the tokens above.
- `.ss-type-numeric` - `font-variant-numeric: tabular-nums lining-nums` for numeric data.

### 3.6 Spacing scale (4px base)

`--ss-space-1` (4px) through `--ss-space-20` (80px). Use 4px increments only; do not invent new spacing values.

### 3.7 Radius scale

| Token | Value | Use |
|---|---|---|
| `--ss-radius-sm` | 6px | chips, code, kbd |
| `--ss-radius-md` | 8px | buttons, inputs, small cards |
| `--ss-radius-lg` | 12px | cards, panels |
| `--ss-radius-xl` | 16px | modals |
| `--ss-radius-full` | 9999px | pill shapes |

### 3.8 Motion

| Token | Value | Use |
|---|---|---|
| `--ss-motion-fast` | 80ms | Hover, focus transitions |
| `--ss-motion-base` | 120ms | Default transitions |
| `--ss-motion-slow` | 200ms | Tooltip fade |
| `--ss-easing` | `cubic-bezier(0.2, 0, 0, 1)` | System easing |

`prefers-reduced-motion: reduce` nullifies all transitions.

### 3.9 Focus

Every interactive component exposes a `:focus-visible` ring using:
- 2px solid outline `var(--ss-color-accent)`
- 2px outline-offset

Forced-colors mode (`forced-colors: active`) automatically elevates to 3px solid `Highlight`.

---

## 4. Component Library

Components are scoped to the admin surface and use only tokens from section 3. New components should follow the same rules.

### 4.1 Buttons (`.sscribe-button`)

40px tall default, 13px / 600 font, radius 8px.

| Variant | Background | Text | Border | Hover |
|---|---|---|---|---|
| `primary` | `--ss-color-accent` | `#ffffff` | `--ss-color-accent` | `--ss-color-accent-hover` |
| `secondary` | `--ss-color-bg-surface` | `--ss-color-fg-primary` | `--ss-color-border-strong` | `--ss-color-bg-inset` |
| `outline` | `--ss-color-bg-surface` | `--ss-color-fg-primary` | `--ss-color-border-strong` | `--ss-color-bg-inset` |
| `ghost` | transparent | `--ss-color-fg-secondary` | transparent | `--ss-color-bg-inset` |
| `success` | `--ss-color-success-fg` | `#ffffff` | - | darker green |
| `danger` | `--ss-color-error-fg` | `#ffffff` | - | darker red |
| `cancel` | transparent | `--ss-color-error-fg` | `--ss-color-error-fg` | `--ss-color-error-bg` |

Sizes:
- default - 40px
- `.ss-btn-sm` - 32px
- `.ss-btn-lg` - 44px

Icon buttons (`.sscribe-button-icon`) - 40×40 square hit area, radius sm.

### 4.2 Inputs (`.sscribe-input` / `.sscribe-select` / `.sscribe-textarea`)

40px tall (textarea grows), 1.5px border, radius sm, 13px font, surface bg, tabular-nums for numeric input. Hover: border-strong. Focus-visible: accent border + outline.

### 4.3 Cards (`.sscribe-card`)

Surface bg, 1px border, radius lg (12px), pad 24px. Variants: header (border-bottom hairline), body, footer (border-top hairline, inset bg).

### 4.4 Tables (`.sscribe-table`)

Full width, border-collapse, hairline row separators. Header 11px / 600 / uppercase, pad 10px 16px, inset bg. Cell 13px, pad 12px 16px. Numeric column: tabular-nums, right-aligned. Row hover: inset bg.

### 4.5 Chips (`.sscribe-chip`) and Badges (`.sscribe-badge`)

Chip: 22px tall, radius full, inset bg / secondary fg, 12px / 500. Variants: solid (accent + white), success / warning / error / info.

Badge: 18px tall, 11px / 600 uppercase, semantically colored.

### 4.6 Code and kbd

- `.sscribe-code` - mono font, 12.5px, inset bg, radius sm, pad 1px 6px, fg-primary.
- `.sscribe-kbd` - `<kbd>` element styled: mono, 11px, surface bg, 1px border, radius sm, pad 0 5px.

### 4.7 Breadcrumb (`.sscribe-breadcrumb`)

Flex row, 8px gap, 13px font, fg-secondary. Separator `›`, fg-muted. Last item fg-primary / 500.

### 4.8 Empty state (`.sscribe-empty-state`)

Center column, 64px pad-y, 24px pad-x. Icon 32×32 fg-muted. Title 15px / 600 fg-primary. Body 13px fg-secondary, max-width 360px, centered.

### 4.9 Progress (`.sscribe-progress`)

6px tall track (skeleton bg). Fill (accent) width driven by `style="--p: N%"`. `aria-valuenow` semantics on parent. `.sscribe-progress.sscribe-progress-finalizing` swaps to success bg.

### 4.10 Switch, segmented, checkbox, radio

- `.sscribe-switch` - 36×20 track, 16px knob, accent when on.
- `.sscribe-segmented` - inline-flex, inset bg, 32px segments. Selected: surface bg + fg-primary + 1px hairline.
- `.sscribe-checkbox`, `.sscribe-radio` - 16px visual, wrapper label extends hit area to 40×40.

### 4.11 Toast (`.sscribe-toast`, `.sscribe-toast-warning`, etc.)

Flex row, 10px gap, 12px 16px pad, radius md, white text. Container fixed bottom-right, flex column, 10px gap, max-width 360px, z-index 100000.

### 4.12 Modal (`.sscribe-modal` with `.sscribe-modal-backdrop`)

Fixed inset 0, `rgb(9 9 11 / 50%)` overlay, flex center, pad 20px, z-index 100000. Content: surface bg, 1px border, radius xl, max-width 540px (680 for preview).

### 4.13 Tooltip (`[data-tooltip]`)

Pure CSS. `[data-tooltip]::after` shows `attr(data-tooltip)` on hover / focus-visible. Dark bg #18181b, white text, 11px, pad 4px 8px. Fade in 200ms. Reduced motion = instant.

### 4.14 Form layout

- `.sscribe-form-row` - flex column, 6px gap, full width. Label / helper / error classes.
- `.sscribe-form-grid` - CSS grid `repeat(auto-fit, minmax(240px, 1fr))`, gap 16px.
- `.sscribe-fieldset` - 1px border, radius md, pad 18px 20px.

---

## 5. Media Queries

### 5.1 `prefers-color-scheme: dark`

Toggles the entire palette inside `.sscribe-master-container`. No component change required.

### 5.2 `prefers-reduced-motion: reduce`

Nullifies all transitions and outlines for users with vestibular sensitivity. Hover and focus styles still apply.

### 5.3 `forced-colors: active`

Maps tokens to system colors (`Canvas`, `CanvasText`, `Highlight`, `LinkText`) and elevates focus rings to 3px solid `Highlight`. Affects all of `.sscribe-master-container` and `.sscribe-debug-master`.

### 5.4 `max-width` breakpoints

- 900px - tablet: workspace compresses; tabs become horizontal scroll.
- 640px - mobile: single column.
- 480px - small mobile: hero stat row stacks.

---

## 6. Focus Ring Table

One `:focus-visible` rule covers the 14 control families. The rule is:

```css
outline: 2px solid var(--ss-color-accent);
outline-offset: 2px;
```

Applies to: button, tab-btn, modal-close, button-icon, toast-dismiss, preflight-close, support-copy-text, format-option-field select, help-link, onboarding-dismiss, history-row checkbox label, history bulk-select-all label, format-option-checkbox wrapper, segmented control items.

Forced colors elevates to 3px solid Highlight.

---

## 7. Hit-Target Table

| Control | Target |
|---|---|
| `.sscribe-button` | 40px |
| `.sscribe-button-icon` | 40×40 |
| `.sscribe-button.ss-btn-sm` | 32px |
| `.sscribe-button.ss-btn-lg` | 44px |
| `.sscribe-tab-btn` | 44px |
| `.sscribe-modal-close` | 40×40 |
| `.sscribe-onboarding-close` | 32×32 (intentional small variant on dismiss) |
| `.sscribe-input` / `-select` / `-textarea` | 40px tall |
| Checkbox / radio wrapper label | 40×40 |
| Switch wrapper | extends to 36px |

---

## 8. Anti-Patterns Banned by This System

These are policy violations when found in the codebase:

- `linear-gradient`, `radial-gradient`, `conic-gradient` - no decorative gradients.
- `box-shadow` for elevation - use 1px borders instead. The only `box-shadow` declaration in the entire codebase is `box-shadow: none !important` inside `@media print` to neutralize inherited shadows.
- `backdrop-filter` - no blur or filter effects.
- `filter: brightness` (or any visual `filter`) - no filter effects.
- `rgba(R, G, B, 0.X)` - use `rgb(R G B / X%)` (modern syntax) or named tokens.
- `outline: none` without replacement focus ring - illegal.
- `transition: all` - use the specific property or properties being animated.
- `-webkit-` vendor prefix on standardized properties - only allowed on pseudo-elements that only exist in WebKit (`::-webkit-scrollbar`, `::-webkit-details-marker`) and on `-webkit-font-smoothing`.
- em-dashes in shipped CSS / JS / PHP / readme - comments are stripped at build, but source should still prefer hyphens for consistency.
- `role="button"` on `<div>` - use a real `<button>` element.
- `transition` on `width` / `height` / `top` / `left` - use `transform` for layout animations.

---

## 9. Debug Console Scoping

`admin/css/sscribe-debug-console.css` is dark-mode-only. It extends the main token space with a `--ss-debug-*` layer for terminal chrome. Component-level rules for `.sscribe-empty-state`, `.sscribe-progress`, `.sscribe-chip-*` inside the `.sscribe-debug-master` scope reuse the same v3 component rules with debug-appropriate ink.

No light mode is provided for the debug terminal: the debug console is intended for system inspection under low-light conditions.

---

## 10. Migration from v2

v2 polish shipped the dark mode, reduced motion, and forced-colors media blocks. v3 adds:

- 27 semantic role tokens (no palette changes; pure aliasing).
- Full component library (input, select, textarea, table, breadcrumb, chip, code, kbd, segmented, switch, progress, empty-state, modal-backdrop, tooltip, form-row/grid/fieldset).
- Token-driven dark-mode re-tune: brand `#7c75ff` → `#8e89ff`, muted text `#8b8b96` → `#a1a1aa`, others normalized to the 11-step palette.
- Hit-target normalization to 40px default + intentional `.ss-btn-sm` and `.ss-btn-lg` modifiers.
- Unified `:focus-visible` ring across 14 control families.

All existing class names are preserved. New utility classes use the `.ss-type-*` prefix (reserved namespace).

---

## 11. References

- LongCipher design system - `designmd.ai` (restraint, tabular-nums, anti-patterns).
- DocuForge design system - `designmd.ai` (blue-purple-gray palette, button hierarchy, status badge conventions).
- Genesis design system - `designmd.ai` (indigo interactive, 4px spacing, pill chips).
- WCAG AA - `w3.org/WAI/WCAG21/Understanding/contrast-minimum.html`.
- `forced-colors` spec - `drafts.csswg.org/csswg-drafts/mediaqueries-5/#forced-colors`.
