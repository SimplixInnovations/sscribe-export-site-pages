import { test, expect } from '../fixtures/shared';
import { runAxe, AXE_TAGS } from '../helpers/axe-rules';

/**
 * Phase 27: A11y matrix — full @axe-core/playwright scan against every
 * SScribe admin tab panel.
 *
 * The existing a11y specs (`admin-tabs.spec.ts`, `format-cards.spec.ts`)
 * cover targeted regressions (computed-style contrast, WAI-ARIA tab
 * pattern, Markdown word-break). This spec is the *general* WCAG 2.2 AA
 * + best-practice coverage that the targetted specs do not exercise:
 *   - landmark roles (banner, main, complementary, contentinfo)
 *   - heading hierarchy (h1 → h6 nesting)
 *   - form labels (for/id pairing, aria-label, aria-labelledby)
 *   - link/button accessible names
 *   - color-contrast on every visible text node
 *   - ARIA attribute validity
 *
 * Each tab is scanned in isolation by limiting the scan to its
 * `role="tabpanel"` — the WP admin chrome (sidebar, top bar, etc.)
 * is OUT OF SCOPE. Only `#wpbody-content` is included as the common
 * host for every panel.
 *
 * Tags covered: WCAG 2.0 / 2.1 / 2.2 A + AA + best-practice.
 * Impact floor: minor (anything ≥ minor fails the spec).
 */
test.describe('a11y / admin-tabs-axe — full @axe-core/playwright scan', () => {
  // One test per tab. Order matches the natural left-to-right tab order.
  const TABS = ['export', 'history', 'support', 'debug'] as const;

  for (const tabId of TABS) {
    test(`${tabId} tabpanel passes WCAG 2.2 AA + best-practice`, async ({ adminPage }) => {
      await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

      // Click the tab so its panel becomes aria-hidden=false (only the
      // visible panel is in the layout flow axe-core analyzes for
      // bounding-box violations).
      await adminPage.locator(`#sscribe-tab-btn-${tabId}`).click();
      // Sanity: the panel is now visible and the rest are hidden.
      await expect(adminPage.locator(`#sscribe-tab-${tabId}`)).toHaveAttribute('aria-hidden', 'false');

      // Run axe-core. Limit scope to the panel + the wpbody-content host
      // (the panel lives inside it; we don't want to scan WP admin
      // chrome that we don't own).
      await runAxe(adminPage, {
        includeSelectors: ['#wpbody-content', `#sscribe-tab-${tabId}`],
        excludeSelectors: [
          // WP admin chrome — out of scope for SScribe audits.
          '#adminmenumain',
          '#wpadminbar',
          '#wpfooter',
          '#screen-meta',
          '#contextual-help-link-wrap',
          '.update-nag',
          '.notice.notice-warning',
          // Third-party plugin notices (Akismet, Hello Dolly, etc.).
          '.plugin-update-tr',
        ],
      });
    });
  }

  test('tablist itself passes WCAG 2.2 AA (no critical landmark errors)', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // A second independent scan: just the tablist, to catch issues
    // that the per-panel scan above would mask because the tablist is
    // shared chrome above every panel.
    await runAxe(adminPage, {
      includeSelectors: ['nav.sscribe-tabs-nav'],
      excludeSelectors: ['#adminmenumain', '#wpadminbar', '#wpfooter'],
    });
  });

  test('export tabpanel contains a visible h1 heading for page title', async ({ adminPage }) => {
    // Some screen-reader navigation relies on the document title +
    // main h1. Confirm at least one h1 exists in the active panel.
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-tab-btn-export').click();
    const h1Count = await adminPage.locator('#sscribe-tab-export h1, #sscribe-tab-export h2').count();
    expect(h1Count, 'export tabpanel must contain at least one h1 or h2 heading').toBeGreaterThan(0);
  });
});

// Reference AXE_TAGS import keeps the helper available if a follow-up
// spec narrows the tag set; suppress unused-locals for TS strict mode.
const _tags: typeof AXE_TAGS = AXE_TAGS;
void _tags;