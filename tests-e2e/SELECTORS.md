# SELECTORS.md — Ground-truth reference for PR2/PR3 admin-UI selectors

**Generated:** 2026-08-31
**Task:** M3 sweep Task 6.5 (PR1)
**Method:** Every selector below was verified against the production sources:
`admin/partials/sscribe-admin-display.php`, `admin/partials/sscribe-admin-debug-tab.php`,
`admin/css/sscribe-admin.css`, `admin/css/sscribe-debug-console.css`,
`admin/js/sscribe-admin.js`, `admin/js/sscribe-debug-console.js`.

> **For implementer subagents (Tasks 7-34):** Every selector below has been
> verified to exist in production. If you need a selector that is not listed,
> you MUST surface that gap in `DONE_WITH_CONCERNS` and run
> `git log --all -p -- admin/` to see whether it was recently added/removed.

> **About comments:** `scripts/build-release.php` strips `//`, `#`, `/* */`
> comments before zipping (per memory `build-strip-comments-shipping.md`).
> Selectors inside comments are NOT shipped and therefore are NOT listed here.

> **Path note:** The brief says `admin/views/*.php` but the actual directory is
> `admin/partials/`. All PHP-template citations below use `admin/partials/`.

## Format

Each line follows the brief's format:
```
- `<selector>` → `<source-file>:<line>
```
Modifiers and notes appear inline after `|` separators.
Each row also calls out the spec task it supports, where relevant.

---

## 0. External selectors (WP-core, used by `tests-e2e/helpers/login.ts`)

The selectors and URL fragments below are **not** part of the plugin's admin
UI; they are WordPress-core login form fields and admin URLs that the
`loginAsAdmin()` test helper uses to authenticate before navigating to the
plugin page. They are cataloged here so a future PR2 spec that wraps the
login helper (e.g., for an auth-required a11y test) has a canonical
reference. Consumed by `tests-e2e/helpers/login.ts:8-12`.

- `#user_login` → tests-e2e/helpers/login.ts:8 | WP login form username field
- `#user_pass` → tests-e2e/helpers/login.ts:9 | WP login form password field
- `#wp-submit` → tests-e2e/helpers/login.ts:12 | WP login form submit button
- `/wp-admin/` → tests-e2e/helpers/login.ts:11 | Admin URL fragment (landing target after login; matches `page.waitForURL(/\/wp-admin\//)`)
- `?page=sscribe-export` → admin/class-sscribe-admin.php:78 | Admin page query string (plugin's main admin page slug; cross-referenced in §1 L35)

## 1. Admin navigation (Tasks 7-10)

- `?page=sscribe-export` → admin/class-sscribe-admin.php:78 | menu_slug from `add_menu_page()`
- `.sscribe-master-container` → admin/partials/sscribe-admin-display.php:123 | top-level wrapper
- `.sscribe-skip-link[href="#sscribe-main-content"]` → admin/partials/sscribe-admin-display.php:122 | first focusable element
- `#sscribe-main-content` → admin/partials/sscribe-admin-display.php:183 | `role="main"`
- `.sscribe-hero` → admin/partials/sscribe-admin-display.php:124 | top header band
- `#sscribe-stat-total-pages` → admin/partials/sscribe-admin-display.php:145 | hero stat (total pages)
- `#sscribe-stat-recent-exports` → admin/partials/sscribe-admin-display.php:152 | hero stat (recent exports)
- `.sscribe-hero-stat` → admin/partials/sscribe-admin-display.php:141 | stat tile (`role="listitem"`)
- `.sscribe-hero-stats[role="list"]` → admin/partials/sscribe-admin-display.php:140 | stat list container

## 2. Tab structure (Tasks 7, 14, 17)

- `nav.sscribe-tabs-nav[role="tablist"]` → admin/partials/sscribe-admin-display.php:185 | tab list
- `.sscribe-tab-btn[data-tab="export"]` → admin/partials/sscribe-admin-display.php:186 | Export tab button
- `.sscribe-tab-btn[data-tab="history"]` → admin/partials/sscribe-admin-display.php:192 | History tab button
- `.sscribe-tab-btn[data-tab="support"]` → admin/partials/sscribe-admin-display.php:199 | Support tab button (capability-gated)
- `.sscribe-tab-btn[data-tab="debug"]` → admin/partials/sscribe-admin-display.php:207 | Debug tab button (debug-mode-gated)
- `#sscribe-tab-btn-export` → admin/partials/sscribe-admin-display.php:186 | (alias of above)
- `#sscribe-tab-btn-history` → admin/partials/sscribe-admin-display.php:192
- `#sscribe-tab-btn-support` → admin/partials/sscribe-admin-display.php:199
- `#sscribe-tab-btn-debug` → admin/partials/sscribe-admin-display.php:207
- `#sscribe-tab-export[role="tabpanel"]` → admin/partials/sscribe-admin-display.php:216 | Export panel
- `#sscribe-tab-history[role="tabpanel"]` → admin/partials/sscribe-admin-display.php:834
- `#sscribe-tab-support[role="tabpanel"]` → admin/partials/sscribe-admin-display.php:1000
- `#sscribe-tab-debug[role="tabpanel"]` → admin/partials/sscribe-admin-display.php:1060
- `.sscribe-tab-active` → admin/partials/sscribe-admin-display.php:186 | active tab/panel modifier (JS-toggled)
- `.sscribe-tab-content` → admin/partials/sscribe-admin-display.php:216 | panel base class
- `#sscribe-tab-announce` → admin/partials/sscribe-admin-display.php:184 | `aria-live="polite"` announce region

> **Brief asked for `aria-pressed` — NOT USED.** Tabs use `aria-selected`.
> Spec authors must NOT assert `aria-pressed` on tabs.

## 3. Format cards (Tasks 8, 15, 18)

- `#sscribe-format-cards` → admin/partials/sscribe-admin-display.php:399 | Step 4 container
- `.sscribe-format-card-label` → admin/partials/sscribe-admin-display.php:400 | wrapper `<label>`
- `.sscribe-format-card-label.sscribe-format-all` → admin/partials/sscribe-admin-display.php:400 | "All Formats" modifier
- `.sscribe-format-card-inner` → admin/partials/sscribe-admin-display.php:402 | inner `<div>`
- `.sscribe-format-icon` → admin/partials/sscribe-admin-display.php:403 | icon span
- `.sscribe-format-name` → admin/partials/sscribe-admin-display.php:409 | format name (DOCX, PDF, …)
- `.sscribe-format-desc` → admin/partials/sscribe-admin-display.php:410 | format description (one-line)
- `.sscribe-format-selector` → admin/partials/sscribe-admin-display.php:412 | checkmark indicator (CSS pseudo-element)
- `input[name="sscribe_format"][value="all"]` → admin/partials/sscribe-admin-display.php:401 | radio, default checked
- `input[name="sscribe_format"][value="docx"]` → admin/partials/sscribe-admin-display.php:441
- `input[name="sscribe_format"][value="pdf"]` → admin/partials/sscribe-admin-display.php:441
- `input[name="sscribe_format"][value="html"]` → admin/partials/sscribe-admin-display.php:441
- `input[name="sscribe_format"][value="markdown"]` → admin/partials/sscribe-admin-display.php:441
- `#sscribe-format-options` → admin/partials/sscribe-admin-display.php:460 | format-options wrapper (hidden by default)
- `.sscribe-format-option-panel[data-format="docx"]` → admin/partials/sscribe-admin-display.php:489 | DOCX options
- `.sscribe-format-option-panel[data-format="pdf"]` → admin/partials/sscribe-admin-display.php:463 | PDF options
- `.sscribe-format-option-panel[data-format="html"]` → admin/partials/sscribe-admin-display.php:534 | HTML options
- `.sscribe-format-option-panel[data-format="markdown"]` → admin/partials/sscribe-admin-display.php:513 | Markdown options
- `.sscribe-format-option-title` → admin/css/sscribe-admin.css:910 | per-panel header text
- `.sscribe-format-option-field` → admin/partials/sscribe-admin-display.php:469 | option field wrapper
- `.sscribe-format-option-checkbox` → admin/partials/sscribe-admin-display.php:478 | checkbox field variant
- `#sscribe-pdf-page-size` → admin/partials/sscribe-admin-display.php:471 | `<select>` for PDF page size
- `#sscribe-pdf-include-images` → admin/partials/sscribe-admin-display.php:479 | checkbox
- `#sscribe-pdf-include-page-numbers` → admin/partials/sscribe-admin-display.php:483 | checkbox
- `#sscribe-docx-template` → admin/partials/sscribe-admin-display.php:497 | `<select>` for DOCX template
- `#sscribe-docx-include-images` → admin/partials/sscribe-admin-display.php:503 | checkbox
- `#sscribe-docx-include-toc` → admin/partials/sscribe-admin-display.php:507 | checkbox
- `#sscribe-md-include-frontmatter` → admin/partials/sscribe-admin-display.php:520 | checkbox
- `#sscribe-md-include-featured-image` → admin/partials/sscribe-admin-display.php:524 | checkbox
- `#sscribe-md-absolute-urls` → admin/partials/sscribe-admin-display.php:528 | checkbox
- `#sscribe-html-include-css` → admin/partials/sscribe-admin-display.php:541 | checkbox
- `#sscribe-html-responsive-images` → admin/partials/sscribe-admin-display.php:545 | checkbox

> **Phantom:** `.sscribe-format-card[data-format="docx"]` does NOT exist.
> Brief example is wrong. Use `input[name="sscribe_format"][value="docx"]`
> (the radio) or `.sscribe-format-option-panel[data-format="docx"]`
> (the option panel), not a `.sscribe-format-card[data-format="…"]` selector.

## 4. Post type / language / status cards (Tasks 14, 18)

- `#sscribe-post-type-cards` → admin/partials/sscribe-admin-display.php:234 | Step 1 container
- `.sscribe-post-type-card` → admin/partials/sscribe-admin-display.php:259 | wrapper `<label>`
- `.sscribe-post-type-card.sscribe-post-type-card-any` → admin/partials/sscribe-admin-display.php:259 | "Any" modifier
- `.sscribe-post-type-card-inner` → admin/partials/sscribe-admin-display.php:261 | inner `<div>`
- `.sscribe-post-type-name` → admin/partials/sscribe-admin-display.php:267 | label
- `.sscribe-post-type-count[data-sscribe-count-for]` → admin/partials/sscribe-admin-display.php:268 | count badge
- `.sscribe-post-type-icon` → admin/partials/sscribe-admin-display.php:262 | icon container
- `input[name="sscribe_post_type"]` → admin/partials/sscribe-admin-display.php:260 | radio input
- `#sscribe-language-cards` → admin/partials/sscribe-admin-display.php:288 | Step 2 container (WPML only)
- `.sscribe-language-cards-wrapper` → admin/partials/sscribe-admin-display.php:287 | outer wrapper
- `.sscribe-lang-card-label` → admin/partials/sscribe-admin-display.php:289 | wrapper `<label>`
- `.sscribe-lang-card-label.sscribe-lang-card-all` → admin/partials/sscribe-admin-display.php:289 | "All languages" modifier
- `.sscribe-lang-card-label.sscribe-lang-card-compact` → admin/partials/sscribe-admin-display.php:289 | compact variant
- `.sscribe-lang-card-inner` → admin/partials/sscribe-admin-display.php:291 | inner `<div>`
- `.sscribe-lang-flag` → admin/partials/sscribe-admin-display.php:312 | `<img>` flag
- `.sscribe-lang-flag-placeholder` → admin/partials/sscribe-admin-display.php:314 | text fallback
- `.sscribe-lang-flag-placeholder.sscribe-lang-flag-all` → admin/partials/sscribe-admin-display.php:293 | "All" placeholder
- `.sscribe-lang-name` → admin/partials/sscribe-admin-display.php:300 | language label
- `.sscribe-lang-count` → admin/partials/sscribe-admin-display.php:301 | count badge
- `input[name="sscribe_language"]` → admin/partials/sscribe-admin-display.php:290 | radio input
- `#sscribe-status-cards` → admin/partials/sscribe-admin-display.php:337 | Step 3 container
- `.sscribe-status-card-label` → admin/partials/sscribe-admin-display.php:367 | wrapper `<label>`
- `.sscribe-status-card-label.sscribe-status-disabled` → admin/partials/sscribe-admin-display.php:365 | disabled (zero-count) variant
- `.sscribe-status-card-inner` → admin/partials/sscribe-admin-display.php:374 | inner `<div>`
- `.sscribe-status-name` → admin/partials/sscribe-admin-display.php:381 | status label
- `.sscribe-status-count[data-status]` → admin/partials/sscribe-admin-display.php:382 | count badge
- `input[name="sscribe_post_status"]` → admin/partials/sscribe-admin-display.php:368 | radio input

> **Phantom:** `.sscribe-language-badge` does NOT exist.
> `regression-discipline.mjs` references it (regression: dark-mode
> color-contrast on language-badge) but the regex never matches.
> Closest selectors: `.sscribe-lang-card-label`, `.sscribe-lang-card-inner`.

## 5. Configuration summary chips (Task 18)

- `#sscribe-config-section-summary` → admin/partials/sscribe-admin-display.php:555 | summary section wrapper
- `#sscribe-config-summary` → admin/partials/sscribe-admin-display.php:560 | container, `aria-live="polite"`
- `.sscribe-summary-chip` → admin/partials/sscribe-admin-display.php:561 | base class
- `#sscribe-summary-post-type` → admin/partials/sscribe-admin-display.php:561
- `#sscribe-summary-status` → admin/partials/sscribe-admin-display.php:563
- `#sscribe-summary-language` → admin/partials/sscribe-admin-display.php:580 | WPML only
- `#sscribe-summary-format` → admin/partials/sscribe-admin-display.php:583
- `#sscribe-summary-pages` → admin/partials/sscribe-admin-display.php:604
- `#sscribe-summary-time` → admin/partials/sscribe-admin-display.php:606
- `.sscribe-summary-sep` → admin/partials/sscribe-admin-display.php:562 | separator dot
- `.sscribe-summary-divider` → admin/partials/sscribe-admin-display.php:603 | separator line
- `#sscribe-auto-download-toggle` → admin/partials/sscribe-admin-display.php:610 | auto-download checkbox

## 6. Export bar + preflight (Tasks 18, 22)

- `#sscribe-preview-btn` → admin/partials/sscribe-admin-display.php:645 | preview button (disabled until valid config)
- `#sscribe-export-btn` → admin/partials/sscribe-admin-display.php:649 | "Generate Package" primary CTA
- `#sscribe-export-btn-text` → admin/partials/sscribe-admin-display.php:650 | inner span
- `#sscribe-export-btn-hint` → admin/partials/sscribe-admin-display.php:652 | `aria-describedby` target
- `#sscribe-preview-btn-hint` → admin/partials/sscribe-admin-display.php:648 | `aria-describedby` target
- `#sscribe-export-disabled-reason` → admin/partials/sscribe-admin-display.php:655 | `aria-live="polite"` reason
- `.sscribe-preflight-warnings[role="status"]` → admin/partials/sscribe-admin-display.php:618 | wrapper
- `.sscribe-preflight-warning[data-warning-code]` → admin/partials/sscribe-admin-display.php:625 | warning row
- `.sscribe-preflight-warning-{info,warning,error}` → admin/partials/sscribe-admin-display.php:625 | severity modifier
- `.sscribe-preflight-warning-message` → admin/partials/sscribe-admin-display.php:630
- `.sscribe-preflight-warning-detail` → admin/partials/sscribe-admin-display.php:632
- `.sscribe-preflight-warning-dismiss[data-warning-code]` → admin/partials/sscribe-admin-display.php:635
- `.sscribe-noscript-notice` → admin/partials/sscribe-admin-display.php:657 | `<noscript>` fallback
- `.sscribe-export-bar` → admin/partials/sscribe-admin-display.php:643 | wrapper
- `.sscribe-export-bar-actions` → admin/partials/sscribe-admin-display.php:644 | action buttons column
- `.sscribe-export-bar-status` → admin/partials/sscribe-admin-display.php:654 | status column
- `#sscribe-onboarding-banner[role="region"]` → admin/partials/sscribe-admin-display.php:161 | first-run guide
- `#sscribe-onboarding-dismiss` → admin/partials/sscribe-admin-display.php:177 | dismiss button

> **Phantom:** `.sscribe-start-export-btn` does NOT exist. Use `#sscribe-export-btn`.
> **Phantom:** `.sscribe-cancel-export-btn` does NOT exist. Use `#sscribe-cancel-btn`.
> **Phantom:** `.sscribe-restart-btn` does NOT exist. Use `#sscribe-new-export-btn`.

## 7. Progress area (Tasks 18, 21)

- `#sscribe-progress-area` → admin/partials/sscribe-admin-display.php:692 | wrapper, `role="status" aria-live="polite"`
- `.sscribe-spinner` → admin/partials/sscribe-admin-display.php:693 | spinner container
- `.sscribe-spinner-img` → admin/partials/sscribe-admin-display.php:694 | loader `<img>`
- `.sscribe-phase-steps[role="list"]` → admin/partials/sscribe-admin-display.php:697 | phase list
- `.sscribe-phase-step[data-phase="fetching"]` → admin/partials/sscribe-admin-display.php:698 | phase 1
- `.sscribe-phase-step[data-phase="processing"]` → admin/partials/sscribe-admin-display.php:706 | phase 2
- `.sscribe-phase-step[data-phase="packaging"]` → admin/partials/sscribe-admin-display.php:714 | phase 3
- `.sscribe-phase-step.sscribe-phase-active` → admin/partials/sscribe-admin-display.php:698 | active modifier
- `.sscribe-phase-dot` → admin/partials/sscribe-admin-display.php:699 | per-step dot
- `.sscribe-phase-connector` → admin/partials/sscribe-admin-display.php:705 | connector line
- `.sscribe-phase-label` → admin/partials/sscribe-admin-display.php:700 | step label
- `#sscribe-status-text` → admin/partials/sscribe-admin-display.php:722 | headline `<h4>`
- `#sscribe-current-page` → admin/partials/sscribe-admin-display.php:725 | current page label
- `#sscribe-progress-bar[role="progressbar"]` → admin/partials/sscribe-admin-display.php:728 | progress bar
- `#sscribe-progress-text` → admin/partials/sscribe-admin-display.php:735 | percentage label
- `#sscribe-time-remaining` → admin/partials/sscribe-admin-display.php:738 | ETA label
- `#sscribe-cancel-btn` → admin/partials/sscribe-admin-display.php:741 | cancel button
- `#sscribe-cancel-hint` → admin/partials/sscribe-admin-display.php:745 | `aria-describedby` target
- `.sscribe-progress-tracker` → admin/partials/sscribe-admin-display.php:726 | progress tracker wrapper
- `.sscribe-progress-bar-container` → admin/partials/sscribe-admin-display.php:727 | bar container
- `.sscribe-progress-percentage` → admin/partials/sscribe-admin-display.php:735 | percentage text
- `.sscribe-progress-actions` → admin/partials/sscribe-admin-display.php:740 | actions wrapper

## 8. Download area (Tasks 18, 19)

- `#sscribe-download-area` → admin/partials/sscribe-admin-display.php:749 | wrapper, `role="alert" aria-live="assertive"`
- `.sscribe-status-alert.sscribe-status-success` → admin/partials/sscribe-admin-display.php:749 | success state
- `#sscribe-success-pages` → admin/partials/sscribe-admin-display.php:765 | `<dd>` page count
- `#sscribe-success-formats` → admin/partials/sscribe-admin-display.php:769 | `<dd>` format count
- `#sscribe-success-size` → admin/partials/sscribe-admin-display.php:773 | `<dd>` file size
- `#sscribe-success-time` → admin/partials/sscribe-admin-display.php:777 | `<dd>` generation timestamp
- `#sscribe-success-meta` → admin/partials/sscribe-admin-display.php:762 | `<dl>` meta wrapper
- `#sscribe-download-btn[download]` → admin/partials/sscribe-admin-display.php:781 | download `<a>`
- `#sscribe-download-hint` → admin/partials/sscribe-admin-display.php:784 | `aria-describedby` target
- `#sscribe-view-history-btn` → admin/partials/sscribe-admin-display.php:785 | view-history button
- `#sscribe-view-history-hint` → admin/partials/sscribe-admin-display.php:789 | `aria-describedby` target
- `#sscribe-new-export-btn` → admin/partials/sscribe-admin-display.php:790 | new-export button
- `#sscribe-new-export-hint` → admin/partials/sscribe-admin-display.php:793 | `aria-describedby` target
- `.sscribe-success-actions` → admin/partials/sscribe-admin-display.php:780 | success actions wrapper
- `.sscribe-success-meta-item` → admin/partials/sscribe-admin-display.php:763 | one `<dt>/<dd>` pair

## 9. Error area (Task 22)

- `#sscribe-error-area` → admin/partials/sscribe-admin-display.php:798 | wrapper, `role="alert" aria-live="assertive"`
- `.sscribe-status-alert.sscribe-status-error` → admin/partials/sscribe-admin-display.php:798 | error state
- `#sscribe-error-text` → admin/partials/sscribe-admin-display.php:808 | short failure message
- `#sscribe-error-guidance` → admin/partials/sscribe-admin-display.php:809 | guidance block (`.sscribe-hidden`)
- `#sscribe-error-guidance-text` → admin/partials/sscribe-admin-display.php:810 | guidance body
- `#sscribe-error-try-again` → admin/partials/sscribe-admin-display.php:813 | retry button
- `#sscribe-error-change-config` → admin/partials/sscribe-admin-display.php:817 | change-config button
- `#sscribe-error-toggle-details[aria-expanded]` → admin/partials/sscribe-admin-display.php:820 | details toggle
- `#sscribe-error-toggle-details-label` → admin/partials/sscribe-admin-display.php:822 | inner label span
- `#sscribe-error-technical-details` → admin/partials/sscribe-admin-display.php:826 | details container
- `.sscribe-debug-pre` → admin/partials/sscribe-admin-display.php:827 | `<pre>` for technical output
- `#sscribe-try-again-hint` → admin/partials/sscribe-admin-display.php:824 | `aria-describedby` target
- `.sscribe-error-actions` → admin/partials/sscribe-admin-display.php:812 | actions wrapper
- `.sscribe-button-toggle-details` → admin/partials/sscribe-admin-display.php:820 | details toggle button class

## 10. History tab (Tasks 11, 12, 13)

- `.sscribe-history-toolbar` → admin/partials/sscribe-admin-display.php:848 | toolbar wrapper
- `.sscribe-search-field` → admin/partials/sscribe-admin-display.php:849 | search label
- `#sscribe-history-search` → admin/partials/sscribe-admin-display.php:854 | `<input type="search">`
- `.sscribe-bulk-hint` → admin/partials/sscribe-admin-display.php:856 | bulk-action hint
- `#sscribe-bulk-hint` → admin/partials/sscribe-admin-display.php:856 | (alias ID)
- `#sscribe-bulk-bar[data-active]` → admin/partials/sscribe-admin-display.php:863 | bulk-action bar
- `#sscribe-bulk-select-all` → admin/partials/sscribe-admin-display.php:866 | master checkbox
- `#sscribe-bulk-count[aria-live]` → admin/partials/sscribe-admin-display.php:870 | selected-count label
- `#sscribe-bulk-download-btn` → admin/partials/sscribe-admin-display.php:873 | bulk-download
- `#sscribe-bulk-delete-btn` → admin/partials/sscribe-admin-display.php:876 | bulk-delete
- `#sscribe-history-skeleton` → admin/partials/sscribe-admin-display.php:882 | skeleton placeholder
- `.sscribe-skeleton` → admin/partials/sscribe-admin-display.php:883 | base skeleton class
- `.sscribe-skeleton-icon` → admin/partials/sscribe-admin-display.php:883 | icon placeholder
- `.sscribe-skeleton-title` → admin/partials/sscribe-admin-display.php:883 | title placeholder
- `.sscribe-skeleton-title-short` → admin/partials/sscribe-admin-display.php:884 | variant
- `.sscribe-skeleton-title-long` → admin/partials/sscribe-admin-display.php:885 | variant
- `#sscribe-history-table` → admin/partials/sscribe-admin-display.php:887 | wrapper around `<table>`
- `table.sscribe-history-table-element` → admin/partials/sscribe-admin-display.php:889 | the `<table>`
- `table.sscribe-history-table-element > caption.screen-reader-text` → admin/partials/sscribe-admin-display.php:890 | accessible name
- `th[scope="col"].sscribe-history-col-check` → admin/partials/sscribe-admin-display.php:893 | checkbox column header
- `th[scope="col"].sscribe-history-col-file` → admin/partials/sscribe-admin-display.php:894 | file column header
- `th[scope="col"].sscribe-history-col-actions` → admin/partials/sscribe-admin-display.php:895 | actions column header
- `tbody[aria-rowcount]` → admin/partials/sscribe-admin-display.php:898 | `<tbody>` with row count
- `tr.sscribe-history-row[data-filename]` → admin/partials/sscribe-admin-display.php:912 | one history row
- `tr.sscribe-history-row > td.sscribe-history-cell-check` → admin/partials/sscribe-admin-display.php:913 | checkbox cell
- `tr.sscribe-history-row > td.sscribe-history-cell-file` → admin/partials/sscribe-admin-display.php:919 | file cell
- `tr.sscribe-history-row > td.sscribe-history-cell-actions` → admin/partials/sscribe-admin-display.php:953 | actions cell
- `tr.sscribe-history-row[aria-rowindex]` → admin/partials/sscribe-admin-display.php:912 | row index
- `input.sscribe-history-check` → admin/partials/sscribe-admin-display.php:915 | row checkbox
- `.sscribe-history-check-label` → admin/partials/sscribe-admin-display.php:914 | row checkbox label
- `.sscribe-history-file` → admin/partials/sscribe-admin-display.php:920 | file-name + meta block
- `.sscribe-file-icon` → admin/partials/sscribe-admin-display.php:921 | icon container
- `.sscribe-file-icon-img` → admin/partials/sscribe-admin-display.php:923 | `<img>` flag image
- `.sscribe-file-icon-text` → admin/partials/sscribe-admin-display.php:925 | text fallback
- `.sscribe-file-meta` → admin/partials/sscribe-admin-display.php:936 | meta line
- `.sscribe-file-size` → admin/partials/sscribe-admin-display.php:939 | size text
- `.sscribe-file-retention` → admin/partials/sscribe-admin-display.php:942 | expiry text
- `.sscribe-meta-sep` → admin/partials/sscribe-admin-display.php:938 | meta separator dot
- `.sscribe-history-actions` → admin/partials/sscribe-admin-display.php:954 | actions container
- `a.sscribe-button.sscribe-button-outline[download]` → admin/partials/sscribe-admin-display.php:955 | per-row download `<a>`
- `button.sscribe-log-btn[data-filename]` → admin/partials/sscribe-admin-display.php:959 | "Log" button
- `button.sscribe-delete-btn[data-filename]` → admin/partials/sscribe-admin-display.php:962 | "Delete" button (two-click confirm)
- `#sscribe-history-empty` → admin/partials/sscribe-admin-display.php:982 | empty-state wrapper
- `#sscribe-empty-start-export-btn` → admin/partials/sscribe-admin-display.php:990 | empty-state CTA
- `.sscribe-empty-icon` → admin/partials/sscribe-admin-display.php:983 | empty-state icon
- `.sscribe-empty-title` → admin/partials/sscribe-admin-display.php:988 | empty-state heading
- `.sscribe-empty-copy` → admin/partials/sscribe-admin-display.php:989 | empty-state copy
- `tr.sscribe-history-row.sscribe-row-selected` → admin/css/sscribe-admin.css:4258 | selected-row modifier (CSS only; JS adds via class)
- `tr.sscribe-history-row.sscribe-history-row-hidden` → admin/css/sscribe-admin.css:4617 | hidden-row modifier (search-filter)

**JS-rendered history row** (constructed by `admin/js/sscribe-admin.js`):
- `tr.sscribe-history-row[data-filename]` → admin/js/sscribe-admin.js:1969 | AJAX re-render path
- `button.sscribe-log-btn[data-filename]` → admin/js/sscribe-admin.js:2033
- `button.sscribe-delete-btn[data-filename]` → admin/js/sscribe-admin.js:2043
- `.sscribe-history-row` → admin/css/sscribe-admin.css:2051 | CSS styles

## 11. Preview modal — wizard (Task 18)

- `#sscribe-preview-panel[role="dialog"]` → admin/partials/sscribe-admin-display.php:662 | modal root
- `#sscribe-preview-title` → admin/partials/sscribe-admin-display.php:665 | `<h3>` title
- `#sscribe-preview-close` → admin/partials/sscribe-admin-display.php:669 | close button
- `#sscribe-preview-content` → admin/partials/sscribe-admin-display.php:673 | body
- `.sscribe-preview-loading` → admin/partials/sscribe-admin-display.php:674 | initial loading state
- `.sscribe-loading-spinner` → admin/partials/sscribe-admin-display.php:675 | CSS spinner
- `.sscribe-preview-footer` → admin/partials/sscribe-admin-display.php:679 | footer wrapper
- `#sscribe-preview-dismiss-btn` → admin/partials/sscribe-admin-display.php:680 | "Close" footer button
- `#sscribe-preview-start-btn` → admin/partials/sscribe-admin-display.php:683 | "Start Export" footer button
- `#sscribe-preview-desc` → admin/partials/sscribe-admin-display.php:687 | `aria-describedby` target
- `.sscribe-modal-content-preview` → admin/partials/sscribe-admin-display.php:663 | modal content modifier

## 12. Log modal (Task 13)

- `#sscribe-log-modal[role="dialog"]` → admin/partials/sscribe-admin-display.php:1069 | modal root
- `#sscribe-log-modal-title` → admin/partials/sscribe-admin-display.php:1072 | `<h3>` title
- `#sscribe-modal-close` → admin/partials/sscribe-admin-display.php:1076 | top-right close button
- `#sscribe-log-content[aria-live]` → admin/partials/sscribe-admin-display.php:1080 | body
- `.sscribe-log-loading` → admin/partials/sscribe-admin-display.php:1081 | initial loading state
- `button[data-close-modal="sscribe-log-modal"].sscribe-modal-close-btn` → admin/partials/sscribe-admin-display.php:1087 | footer "Close" button
- `#sscribe-log-modal-desc` → admin/partials/sscribe-admin-display.php:1089 | `aria-describedby` target

## 13. Confirm modal — alertdialog (Task 11)

- `#sscribe-confirm-modal[role="alertdialog"]` → admin/partials/sscribe-admin-display.php:1093 | modal root
- `#sscribe-confirm-title` → admin/partials/sscribe-admin-display.php:1102 | heading
- `#sscribe-confirm-desc` → admin/partials/sscribe-admin-display.php:1103 | sub-headline
- `#sscribe-confirm-body` → admin/partials/sscribe-admin-display.php:1106 | body
- `.sscribe-confirm-list` → admin/js/sscribe-admin.js:2779 | `<ul>` of consequence bullets (JS-populated)
- `#sscribe-confirm-cancel` → admin/partials/sscribe-admin-display.php:1108 | "Cancel" footer button
- `#sscribe-confirm-proceed` → admin/partials/sscribe-admin-display.php:1111 | "Proceed" footer button
- `.sscribe-confirm-header` → admin/partials/sscribe-admin-display.php:1095 | header
- `.sscribe-confirm-icon` → admin/partials/sscribe-admin-display.php:1096 | warning icon
- `.sscribe-confirm-titles` → admin/partials/sscribe-admin-display.php:1101 | title container
- `.sscribe-confirm-body` → admin/partials/sscribe-admin-display.php:1106 | body wrapper
- `.sscribe-confirm-footer` → admin/partials/sscribe-admin-display.php:1107 | footer wrapper
- `.sscribe-modal-content-confirm` → admin/partials/sscribe-admin-display.php:1094 | modal content modifier

## 14. Two-click confirm button states (Task 11)

The two-click confirm pattern toggles classes on the same button. Do NOT merge
these classes when asserting both states (per memory
`two-click-confirm-pointer-events-landmine.md`).

- `.sscribe-btn-busy` → admin/js/sscribe-admin.js:1085 | generic in-flight state
- `.sscribe-btn-confirming` → admin/js/sscribe-admin.js:3381 | first-click armed state

## 15. Toast container (Task 10)

- `#sscribe-toast-container[aria-live]` → admin/partials/sscribe-admin-display.php:1067 | toast container
- `.sscribe-toast` → admin/js/sscribe-admin.js:2409 | generic toast
- `.sscribe-toast.sscribe-toast-success` → admin/js/sscribe-admin.js:2408 | success variant
- `.sscribe-toast.sscribe-toast-error` → admin/js/sscribe-admin.js:2408 | error variant
- `.sscribe-toast.sscribe-toast-info` → admin/js/sscribe-admin.js:2408 | info variant
- `.sscribe-toast-icon` → admin/js/sscribe-admin.js:2410 | icon span
- `.sscribe-toast-body` → admin/js/sscribe-admin.js:2411 | body span
- `.sscribe-toast-dismiss` → admin/css/sscribe-admin.css:2633 | close button
- `.sscribe-toast-visible` → admin/js/sscribe-admin.js:2416 | visible state modifier
- `.sscribe-toast-removing` → admin/js/sscribe-admin.js:2420 | removal state modifier

## 16. Support tab — capability-gated (Task 16)

- `.sscribe-support-master` → admin/partials/sscribe-admin-display.php:1001 | wrapper
- `.sscribe-support-sidebar` → admin/partials/sscribe-admin-display.php:1002 | left rail
- `.sscribe-support-section` → admin/partials/sscribe-admin-display.php:1004 | section wrapper
- `.sscribe-support-section-header` → admin/partials/sscribe-admin-display.php:1005 | section header
- `.sscribe-support-section-copy` → admin/partials/sscribe-admin-display.php:1009 | intro copy
- `.sscribe-support-actions-vertical` → admin/partials/sscribe-admin-display.php:1011 | action button column
- `#sscribe-support-copy-btn` → admin/partials/sscribe-admin-display.php:1012 | "Copy to Clipboard" button
- `#sscribe-support-refresh-btn` → admin/partials/sscribe-admin-display.php:1015 | "Refresh Data" button
- `#sscribe-support-feedback` → admin/partials/sscribe-admin-display.php:1020 | `aria-live="polite"` feedback line
- `.sscribe-support-main` → admin/partials/sscribe-admin-display.php:1024 | right pane
- `.sscribe-support-panel[data-support-card][aria-busy]` → admin/partials/sscribe-admin-display.php:1025 | right pane container
- `#sscribe-support-copy-text` → admin/partials/sscribe-admin-display.php:1032 | `<textarea>` populated by AJAX
- `#sscribe-support-grid[aria-busy]` → admin/partials/sscribe-admin-display.php:1034 | snapshot grid
- `.sscribe-support-grid-empty` → admin/partials/sscribe-admin-display.php:1034 | empty-state modifier
- `.sscribe-support-empty` → admin/partials/sscribe-admin-display.php:1035 | empty wrapper
- `.sscribe-support-empty-icon` → admin/partials/sscribe-admin-display.php:1036 | empty icon
- `.sscribe-support-empty-title` → admin/partials/sscribe-admin-display.php:1049 | empty heading
- `.sscribe-support-empty-copy` → admin/partials/sscribe-admin-display.php:1050 | empty copy
- `.sscribe-support-panel-header` → admin/partials/sscribe-admin-display.php:1026 | panel header
- `.sscribe-support-panel-meta` → admin/partials/sscribe-admin-display.php:1028 | meta label
- `.sscribe-support-copy-wrap` → admin/partials/sscribe-admin-display.php:1030 | copy textarea wrapper

## 17. Debug tab — debug-mode-gated (Task 17)

- `#sscribe-debug-root` → admin/partials/sscribe-admin-debug-tab.php:37 | outer wrapper
- `.sscribe-debug-master` → admin/partials/sscribe-admin-debug-tab.php:37 | panel wrapper (alias ID)
- `.sscribe-debug-wp-debug-notice` → admin/partials/sscribe-admin-debug-tab.php:29 | shown when `WP_DEBUG` is on
- `.sscribe-debug-header` → admin/partials/sscribe-admin-debug-tab.php:38 | debug header
- `.sscribe-debug-title-row` → admin/partials/sscribe-admin-debug-tab.php:39 | title row
- `#sscribe-debug-help-btn` → admin/partials/sscribe-admin-debug-tab.php:41 | help button toggle
- `#sscribe-debug-help-content[hidden]` → admin/partials/sscribe-admin-debug-tab.php:189 | help content (toggled)
- `#sscribe-debug-help-title` → admin/partials/sscribe-admin-debug-tab.php:190 | help heading
- `#sscribe-debug-enabled[role="switch"][aria-checked]` → admin/partials/sscribe-admin-debug-tab.php:55 | enable-debug toggle
- `.sscribe-debug-settings-card` → admin/partials/sscribe-admin-debug-tab.php:50 | settings card
- `.sscribe-debug-settings-grid` → admin/partials/sscribe-admin-debug-tab.php:51 | settings grid
- `.sscribe-debug-toggle-section` → admin/partials/sscribe-admin-debug-tab.php:52 | toggle section
- `.sscribe-debug-toggle-label` → admin/partials/sscribe-admin-debug-tab.php:53 | toggle label
- `.sscribe-toggle-switch` → admin/partials/sscribe-admin-debug-tab.php:54 | switch wrapper
- `.sscribe-toggle-slider` → admin/partials/sscribe-admin-debug-tab.php:56 | slider span
- `.sscribe-toggle-text` → admin/partials/sscribe-admin-debug-tab.php:58 | text wrapper
- `.sscribe-debug-level-section` → admin/partials/sscribe-admin-debug-tab.php:64 | level section
- `#sscribe-debug-level` → admin/partials/sscribe-admin-debug-tab.php:66 | log-level `<select>`
- `.sscribe-debug-save-section` → admin/partials/sscribe-admin-debug-tab.php:74 | save section
- `#sscribe-debug-save-settings` → admin/partials/sscribe-admin-debug-tab.php:75 | save button
- `#sscribe-debug-save-feedback[aria-live]` → admin/partials/sscribe-admin-debug-tab.php:78 | save feedback
- `.sscribe-debug-controls-card` → admin/partials/sscribe-admin-debug-tab.php:83 | controls card
- `.sscribe-debug-controls-row` → admin/partials/sscribe-admin-debug-tab.php:84 | controls row
- `.sscribe-debug-filter` → admin/partials/sscribe-admin-debug-tab.php:85 | level filter
- `#sscribe-debug-filter-level` → admin/partials/sscribe-admin-debug-tab.php:87 | console level filter
- `.sscribe-debug-session-filter` → admin/partials/sscribe-admin-debug-tab.php:100 | session filter
- `#sscribe-debug-session-id` → admin/partials/sscribe-admin-debug-tab.php:102 | session-id input
- `#sscribe-session-id-desc` → admin/partials/sscribe-admin-debug-tab.php:103 | `aria-describedby` target
- `.sscribe-debug-search` → admin/partials/sscribe-admin-debug-tab.php:105 | search wrapper
- `#sscribe-debug-search` → admin/partials/sscribe-admin-debug-tab.php:107 | search input
- `.sscribe-debug-refresh-row` → admin/partials/sscribe-admin-debug-tab.php:110 | refresh row
- `.sscribe-debug-refresh-mode` → admin/partials/sscribe-admin-debug-tab.php:111 | refresh-mode wrapper
- `#sscribe-debug-refresh-paused` → admin/partials/sscribe-admin-debug-tab.php:112 | paused indicator
- `.sscribe-radio-label` → admin/partials/sscribe-admin-debug-tab.php:113 | radio label
- `input[name="sscribe_refresh_mode"][value="auto"]` → admin/partials/sscribe-admin-debug-tab.php:114 | auto-refresh radio
- `input[name="sscribe_refresh_mode"][value="manual"]` → admin/partials/sscribe-admin-debug-tab.php:118 | manual radio
- `.sscribe-radio-text` → admin/partials/sscribe-admin-debug-tab.php:115 | radio text span
- `#sscribe-debug-refresh-btn` → admin/partials/sscribe-admin-debug-tab.php:122 | manual refresh button
- `#sscribe-debug-stale-banner[role="status"]` → admin/partials/sscribe-admin-debug-tab.php:131 | "Showing previous logs" banner
- `#sscribe-debug-stale-banner-message` → admin/partials/sscribe-admin-debug-tab.php:137 | banner message text
- `#sscribe-debug-enable-and-clear` → admin/partials/sscribe-admin-debug-tab.php:140 | banner CTA
- `.sscribe-debug-stale-banner-icon` → admin/partials/sscribe-admin-debug-tab.php:132 | banner icon
- `.sscribe-debug-stale-banner-text` → admin/partials/sscribe-admin-debug-tab.php:135 | banner text
- `.sscribe-debug-stale-banner-actions` → admin/partials/sscribe-admin-debug-tab.php:139 | banner actions
- `.sscribe-debug-console-card[role="log"]` → admin/partials/sscribe-admin-debug-tab.php:146 | console card
- `.sscribe-debug-console-header` → admin/partials/sscribe-admin-debug-tab.php:147 | console header
- `.sscribe-debug-console-title` → admin/partials/sscribe-admin-debug-tab.php:148 | console title
- `.sscribe-debug-console-count` → admin/partials/sscribe-admin-debug-tab.php:149 | count class
- `#sscribe-debug-entry-count` → admin/partials/sscribe-admin-debug-tab.php:149 | `aria-live` count
- `#sscribe-debug-console-body` → admin/partials/sscribe-admin-debug-tab.php:151 | body
- `#sscribe-debug-empty` → admin/partials/sscribe-admin-debug-tab.php:152 | empty state
- `#sscribe-debug-empty-enable` → admin/partials/sscribe-admin-debug-tab.php:158 | enable-debug button in empty state
- `#sscribe-debug-entries` → admin/partials/sscribe-admin-debug-tab.php:162 | entries container (JS-populated)
- `.sscribe-debug-actions` → admin/partials/sscribe-admin-debug-tab.php:167 | actions wrapper
- `#sscribe-debug-export-btn` → admin/partials/sscribe-admin-debug-tab.php:168 | export-JSON button
- `.sscribe-export-btn-scope` → admin/partials/sscribe-admin-debug-tab.php:172 | "(all entries)" / "(filtered)" span
- `#sscribe-debug-clear-btn` → admin/partials/sscribe-admin-debug-tab.php:174 | clear-logs button (two-click confirm)
- `#sscribe-debug-rotated-details` → admin/partials/sscribe-admin-debug-tab.php:179 | `<details>` rotated-log section
- `.sscribe-debug-rotated-title` → admin/partials/sscribe-admin-debug-tab.php:181 | rotated title
- `.sscribe-debug-rotated-hint` → admin/partials/sscribe-admin-debug-tab.php:182 | rotated hint
- `#sscribe-debug-rotated-body` → admin/partials/sscribe-admin-debug-tab.php:184 | `aria-live="polite" aria-atomic="false"`
- `.sscribe-debug-rotated-empty` → admin/partials/sscribe-admin-debug-tab.php:185 | empty message
- `.sscribe-debug-rotated-loading` → admin/js/sscribe-debug-console.js:1258 | loading state

> **Phantom:** `.sscribe-clear-logs-btn` does NOT exist. Use `#sscribe-debug-clear-btn`.
> **Phantom:** `.sscribe-copy-debug-btn` does NOT exist. Use
> `#sscribe-debug-export-btn` (export JSON) or `#sscribe-support-copy-btn`
> (copy snapshot).

## 18. Debug console entries — JS-rendered (Task 17)

Constructed by `admin/js/sscribe-debug-console.js`:

- `.sscribe-debug-entry` → admin/js/sscribe-debug-console.js:611 | one log entry (top-level)
- `.sscribe-debug-entry.has-context` → admin/js/sscribe-debug-console.js:1648 | `<details>` variant with context rows
- `.sscribe-debug-entry-level-info` → admin/js/sscribe-debug-console.js:1626 | level modifier
- `.sscribe-debug-entry-level-debug` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry-level-notice` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry-level-warning` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry-level-error` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry-level-critical` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry-level-audit` → admin/js/sscribe-debug-console.js:1626
- `.sscribe-debug-entry[data-level]` → admin/js/sscribe-debug-console.js:1628 | upper-case level data-attr
- `.sscribe-debug-entry-header` → admin/js/sscribe-debug-console.js:612 | header row
- `.sscribe-debug-entry-badge` → admin/js/sscribe-debug-console.js:613 | level chip
- `.sscribe-debug-entry-time` → admin/js/sscribe-debug-console.js:1637 | timestamp
- `.sscribe-debug-entry-message` → admin/js/sscribe-debug-console.js:614 | message text
- `.sscribe-debug-entry-toggle` → admin/js/sscribe-debug-console.js:1667 | disclosure caret
- `.sscribe-debug-entry-context` → admin/js/sscribe-debug-console.js:1614 | context wrapper
- `.sscribe-debug-context-row` → admin/js/sscribe-debug-console.js:1603 | one context key/value row
- `.sscribe-debug-context-key` → admin/js/sscribe-debug-console.js:1604 | key text
- `.sscribe-debug-context-val` → admin/js/sscribe-debug-console.js:1607 | value `<pre>`
- `.sscribe-debug-append-loading` → admin/js/sscribe-debug-console.js:998 | "Loading more entries…"
- `.sscribe-debug-append-error` → admin/js/sscribe-debug-console.js:888 | inline error message
- `.sscribe-debug-retry-append` → admin/js/sscribe-debug-console.js:889 | retry button
- `.sscribe-feedback` → admin/js/sscribe-debug-console.js:1051 | generic feedback span
- `.sscribe-feedback-success` → admin/js/sscribe-debug-console.js:1051 | success feedback
- `.sscribe-feedback-error` → admin/js/sscribe-debug-console.js:1062 | error feedback

## 19. Rotated log rows — JS-rendered (Task 17)

- `.sscribe-debug-rotated-banner` → admin/js/sscribe-debug-console.js:1377 | banner shown when viewing rotated log
- `#sscribe-back-to-current` → admin/js/sscribe-debug-console.js:1381 | back-to-current button
- `.sscribe-debug-rotated-file` → admin/js/sscribe-debug-console.js:1299 | wrapper for one rotated file
- `.sscribe-debug-rotated-file-info` → admin/js/sscribe-debug-console.js:1300 | file-name + meta
- `.sscribe-debug-rotated-file-name` → admin/js/sscribe-debug-console.js:1301 | file name span
- `.sscribe-debug-rotated-file-meta` → admin/js/sscribe-debug-console.js:1303 | size / date
- `.sscribe-debug-rotated-file-actions` → admin/js/sscribe-debug-console.js:1309 | action buttons wrapper
- `button.sscribe-rotated-view[data-file]` → admin/js/sscribe-debug-console.js:1311 | view button
- `button.sscribe-rotated-export[data-file]` → admin/js/sscribe-debug-console.js:1319 | export button
- `button.sscribe-rotated-delete[data-file]` → admin/js/sscribe-debug-console.js:1327 | delete button (two-click confirm)
- `.sscribe-rotated-error` → admin/js/sscribe-debug-console.js:1534 | inline error indicator

## 20. Live regions (Tasks 14, 15, 21)

- `#sscribe-live-region` → admin/partials/sscribe-admin-display.php:158 | `aria-live="polite" aria-atomic="true"`
- `#sscribe-alert-region` → admin/partials/sscribe-admin-display.php:159 | `aria-live="assertive"`
- `#sscribe-tab-announce` → admin/partials/sscribe-admin-display.php:184 | `aria-live="polite"` for tab changes
- `[role="progressbar"]` → admin/partials/sscribe-admin-display.php:729 | confirmed
- `[aria-live="polite"]` → admin/partials/sscribe-admin-display.php:158 | confirmed (multiple matches)
- `[aria-live="assertive"]` → admin/partials/sscribe-admin-display.php:159 | confirmed (multiple matches)

## 21. ARIA / role quick reference (Tasks 14, 15, 21, 22)

| Role / attr | Selector | Source | Line |
| --- | --- | --- | --- |
| `role="tablist"` | `nav.sscribe-tabs-nav` | admin/partials/sscribe-admin-display.php | 185 |
| `role="tab"` | `.sscribe-tab-btn` | admin/partials/sscribe-admin-display.php | 186, 192, 199, 207 |
| `role="tabpanel"` | `.sscribe-tab-content` | admin/partials/sscribe-admin-display.php | 216, 834, 1000, 1060 |
| `role="progressbar"` | `#sscribe-progress-bar` | admin/partials/sscribe-admin-display.php | 729 |
| `role="status"` | `#sscribe-progress-area`, `#sscribe-debug-stale-banner`, `.sscribe-preflight-warnings` | admin/partials/sscribe-admin-display.php | 692, 618; admin/partials/sscribe-admin-debug-tab.php | 131 |
| `role="alert"` | `#sscribe-download-area`, `#sscribe-error-area` | admin/partials/sscribe-admin-display.php | 749, 798 |
| `role="alertdialog"` | `#sscribe-confirm-modal` | admin/partials/sscribe-admin-display.php | 1093 |
| `role="dialog"` | `#sscribe-preview-panel`, `#sscribe-log-modal` | admin/partials/sscribe-admin-display.php | 662, 1069 |
| `role="region"` | `#sscribe-onboarding-banner` | admin/partials/sscribe-admin-display.php | 161 |
| `role="main"` | `#sscribe-main-content` | admin/partials/sscribe-admin-display.php | 183 |
| `role="list"` | `.sscribe-hero-stats`, `.sscribe-phase-steps` | admin/partials/sscribe-admin-display.php | 140, 697 |
| `role="listitem"` | `.sscribe-hero-stat`, `.sscribe-phase-step` | admin/partials/sscribe-admin-display.php | 141, 148, 698, 706, 714 |
| `role="log"` | `.sscribe-debug-console-card` | admin/partials/sscribe-admin-debug-tab.php | 146 |
| `role="switch"` | `#sscribe-debug-enabled` | admin/partials/sscribe-admin-debug-tab.php | 55 |
| `aria-selected` | `.sscribe-tab-btn` | admin/partials/sscribe-admin-display.php | 186 |
| `aria-checked` | `#sscribe-debug-enabled` | admin/partials/sscribe-admin-debug-tab.php | 55 |
| `aria-busy` | `.sscribe-support-panel`, `#sscribe-support-grid` | admin/partials/sscribe-admin-display.php | 1025, 1034 |
| `aria-hidden` | `.sscribe-modal`, `.sscribe-tab-content`, `.sscribe-debug-entry-context` | admin/partials/sscribe-admin-display.php | 662, 216, 834, 1000, 1060, 1093; admin/js/sscribe-debug-console.js | 1616 |
| `aria-expanded` | `#sscribe-error-toggle-details`, `#sscribe-debug-help-btn` | admin/partials/sscribe-admin-display.php | 820; admin/partials/sscribe-admin-debug-tab.php | 41 |
| `aria-controls` | `#sscribe-error-toggle-details`, `#sscribe-debug-help-btn`, tabs | admin/partials/sscribe-admin-display.php | 820, 186, 192, 199, 207 |
| `aria-labelledby` | tabs, `.sscribe-modal`, progress area | admin/partials/sscribe-admin-display.php | 216, 662, 692, 834, 1000, 1060, 1069, 1093 |
| `aria-describedby` | buttons that point at hint spans | admin/partials/sscribe-admin-display.php | 645, 649, 741, 781, 785, 790, 813 |
| `aria-rowcount` | `tbody[aria-rowcount]` | admin/partials/sscribe-admin-display.php | 898 |
| `aria-rowindex` | `tr.sscribe-history-row` | admin/partials/sscribe-admin-display.php | 912 |
| `aria-valuemin` / `aria-valuemax` / `aria-valuenow` / `aria-valuetext` | `#sscribe-progress-bar` | admin/partials/sscribe-admin-display.php | 730-733 |
| `aria-relevant` | `#sscribe-toast-container` | admin/partials/sscribe-admin-display.php | 1067 |
| `aria-modal` | `.sscribe-modal` | admin/partials/sscribe-admin-display.php | 662, 1069, 1093 |
| `aria-orientation` | `nav.sscribe-tabs-nav` | admin/partials/sscribe-admin-display.php | 185 |

> **Brief asked for `aria-pressed` — NOT USED anywhere in admin templates.**
> Tabs use `aria-selected`; toggles use `aria-checked`; switches use
> `role="switch" aria-checked`. Spec authors must not assert `aria-pressed`.

## 22. Phantom selectors from the brief (flag for PR2/PR3 specs)

These selectors were referenced in the brief or in `regression-discipline.mjs`
but do **not** exist in the production source. Each is flagged for the relevant
PR2/PR3 implementer.

| Phantom selector | Where referenced | Reality (use instead) |
| --- | --- | --- |
| `.sscribe-format-card[data-format="docx"]` | Brief example L142 | `input[name="sscribe_format"][value="docx"]` (the radio) or `.sscribe-format-card-label:has(input[value="docx"])` (label wrapper) or `.sscribe-format-option-panel[data-format="docx"]` (option panel) |
| `.sscribe-start-export-btn` | Brief L188 example | `#sscribe-export-btn` |
| `.sscribe-cancel-export-btn` | Brief grep pattern | `#sscribe-cancel-btn` |
| `.sscribe-redownload-btn` | Brief grep pattern | Does not exist. Closest: `#sscribe-download-btn` (success area) or per-row `a[download]` in history table. |
| `.sscribe-restart-btn` | Brief grep pattern | `#sscribe-new-export-btn` |
| `.sscribe-clear-logs-btn` | Brief grep pattern | `#sscribe-debug-clear-btn` |
| `.sscribe-copy-debug-btn` | Brief grep pattern | `#sscribe-debug-export-btn` (export JSON) or `#sscribe-support-copy-btn` (copy snapshot). |
| `data-dl-token-*` (any prefix) | Brief grep pattern | Does not exist. Download URLs are written into `#sscribe-download-btn[href]` by JS (`admin/js/sscribe-admin.js:1659`); the underlying download token is a URL query parameter, not a `data-*` attribute. Specs that need to assert download-token semantics must read the `href` attribute (or its query string), not a `data-dl-token-*` attribute. |
| `.sscribe-debug-panel` | Brief grep pattern | Does not exist as a class. Use `#sscribe-debug-root` (the tab root) or `.sscribe-debug-master` (the panel wrapper). |
| `.sscribe-log-entry` | Brief grep pattern | `.sscribe-debug-entry` (note: `debug-` prefix). |
| `.sscribe-log-filter` | Brief grep pattern | `#sscribe-debug-filter-level`, `#sscribe-debug-session-id`, `#sscribe-debug-search`. |
| `sscribe-history-row` (without leading `.`) | Brief grep pattern | `.sscribe-history-row` (CSS-class form, with leading dot). The plain word exists at `admin/css/sscribe-admin.css:2051` (selector with leading `.`). |
| `sscribe-history-table` (without leading `.`) | Brief grep pattern | `.sscribe-history-table` (CSS) and `#sscribe-history-table` (ID). |
| `<caption>` and `scope=` (generic) | Brief grep pattern | `caption.screen-reader-text` exists at `admin/partials/sscribe-admin-display.php:890`. `th[scope="col"]` exists at L893-895. |
| `.sscribe-btn-busy` + `.sscribe-btn-confirming` (merged) | Brief grep pattern | Per memory `two-click-confirm-pointer-events-landmine.md`, never merge these. Use `.sscribe-btn-confirming` (armed) and `.sscribe-btn-busy` (in-flight) as separate selectors / states. |
| `.sscribe-language-badge` | `regression-discipline.mjs` L33 (`dark-mode color-contrast on language-badge`) | Does not exist. Closest: `.sscribe-lang-card-label`, `.sscribe-lang-card-inner`. The regression-discipline script's `revertMatch` regex references it and will never match production CSS, so the script will exit-1 with "revert pattern did not match" for this regression. Pre-existing concern from Task 6. |
| `.sscribe-format-option-description` | `regression-discipline.mjs` L26 (`dark-mode color-contrast on format-desc`) | Does not exist as a class. Closest: `.sscribe-format-desc` (real selector at `admin/partials/sscribe-admin-display.php:410, 450`). The regression-discipline script's `revertMatch` regex references the phantom class and will never match production CSS. Pre-existing concern from Task 6. |
| `console.error` capture by plugin-error-listener | Cross-task context (Task 2 review) | The listener captures `error` and `unhandledrejection` only. Specs must NOT assert that `console.error` reaches the test thread. |
| `runAxe` returns `AxeResults` | Cross-task context (Task 2 review) | `runAxe` returns `Promise<void>`. Specs that need violation details must use a different mechanism. |

## 23. Per-PR-task selector map

This table maps each PR2/PR3 spec (referenced in
`tests-e2e/regression-discipline.mjs`) to the section of this document where
its selectors are cataloged.

| Spec | Section in this doc | Notes |
| --- | --- | --- |
| `tests-e2e/00-smoke.spec.ts` (already exists) | §1, §3 | `?page=sscribe-export`, `.sscribe-format-card-*` |
| `tests-e2e/a11y/admin-tabs.spec.ts` (dark-mode contrast on format-desc) | §3, §4 | Phantom selector `.sscribe-format-option-description` — must use `.sscribe-format-desc` instead |
| `tests-e2e/a11y/admin-tabs.spec.ts` (dark-mode contrast on language-badge) | §4 | Phantom selector `.sscribe-language-badge` — must use `.sscribe-lang-card-label` instead |
| `tests-e2e/a11y/format-cards.spec.ts` (markdown-format-card word break) | §3 | `.sscribe-format-option-title` is real |
| `tests-e2e/e2e/debug/toggle.spec.ts` (toggle-ui-assets-gating) | §17 | `#sscribe-debug-enabled[role="switch"][aria-checked]` |
| `tests-e2e/e2e/history/delete-two-click.spec.ts` (two-click-confirm pointer-events) | §10, §13, §14, §17, §19 | `.sscribe-delete-btn`, `#sscribe-confirm-modal`, `.sscribe-btn-confirming`, `#sscribe-debug-clear-btn`, `.sscribe-rotated-delete` |
| `tests-e2e/e2e/export/wizard-happy-path.spec.ts` (export wizard happy path) | §3, §5, §6, §11 | format cards → summary → preview modal → export button |
| `tests-e2e/e2e/export/download-token-auth.spec.ts` (download token expiry) | §8, §20 | `#sscribe-download-btn[href]`, NOT `data-dl-token-*` |
| `tests-e2e/e2e/export/batch-progress.spec.ts` (batch-progress aria-live) | §7, §20 | `#sscribe-progress-bar[role="progressbar"]`, `.sscribe-phase-step[data-phase="..."]` |

**End of SELECTORS.md**
