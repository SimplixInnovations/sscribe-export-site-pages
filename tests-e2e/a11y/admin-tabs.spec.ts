import { test, expect } from '../fixtures/shared';

/**
 * Accessibility regressions referenced by `regression-discipline.mjs` for the
 * admin tabs surface. The historical entries in that file pointed at phantom
 * selectors (`sscribe-format-option-description`, `sscribe-language-badge`) that
 * do not exist in the production source (see SELECTORS.md §22). The selectors
 * that DO exist and were the actual cause of the regressions cataloged in
 * memory `dark-mode-aa-regressions.md` are:
 *   - `.sscribe-format-desc`   admin/css/sscribe-admin.css:606
 *   - `.sscribe-lang-name`     admin/css/sscribe-admin.css:579
 *
 * This spec therefore asserts the AA contrast contract using those real
 * selectors. The discipline script's revert patterns have been re-grounded
 * against these same selectors (see tests-e2e/regression-discipline.mjs).
 *
 * NOTE: The plugin ships a light theme only — there is no `prefers-color-scheme:
 * dark` rule or `[data-theme="dark"]` selector in admin/css/* (verified by
 * grep at write time). The "dark-mode" tag in the historical regression
 * records was the QA reviewer's local OS dark-mode emulation of WP-Admin
 * chrome bleeding through to `:root` `--ss-surface` and producing a
 * high-contrast inversion. This spec tests the COMPUTED styles of the shipped
 * tokens, which is what the user actually sees regardless of system theme.
 */
test.describe('a11y / admin-tabs', () => {
  test('format-desc text contrast meets WCAG AA on light surface', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Real selector from SELECTORS.md §3 / production CSS line 606.
    const formatDesc = adminPage.locator('.sscribe-format-desc').first();
    await expect(formatDesc).toBeVisible();

    // Helpers must live inside the page-evaluate closure because Playwright
    // serialises args across the bridge and function-via-object does not
    // survive the round trip.
    const ratio = await formatDesc.evaluate((el) => {
      const cs = window.getComputedStyle(el);
      const fg = parseRGB(cs.color);
      let node: Element | null = el as Element;
      let bg: { r: number; g: number; b: number } = { r: 255, g: 255, b: 255 };
      while (node) {
        const ncs = window.getComputedStyle(node);
        const parsed = parseRGB(ncs.backgroundColor);
        if (parsed.a > 0) {
          bg = parsed;
          break;
        }
        node = node.parentElement;
      }
      return contrastRatio(fg, bg);

      type RGB = { r: number; g: number; b: number; a: number };
      function parseRGB(value: string): RGB {
        const m = value.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([0-9.]+))?\s*\)/);
        if (!m) return { r: 0, g: 0, b: 0, a: 1 };
        return {
          r: parseInt(m[1], 10),
          g: parseInt(m[2], 10),
          b: parseInt(m[3], 10),
          a: m[4] !== undefined ? parseFloat(m[4]) : 1,
        };
      }
      function relativeLuminance(c: { r: number; g: number; b: number }): number {
        const channel = (v: number) => {
          const s = v / 255;
          return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
      }
      function contrastRatio(
        fg: { r: number; g: number; b: number },
        bg: { r: number; g: number; b: number }
      ): number {
        const L1 = relativeLuminance(fg);
        const L2 = relativeLuminance(bg);
        const lighter = Math.max(L1, L2);
        const darker = Math.min(L1, L2);
        return (lighter + 0.05) / (darker + 0.05);
      }
    });

    // WCAG AA normal text requires ≥ 4.5:1. The shipped token
    // --ss-text-secondary (#3f3f46) on --ss-surface (#fff) clears AA easily
    // (~10.4:1); a regression that flips to --ss-text-muted (#5a5f66) drops
    // the ratio to ~6.5:1 — still passing but documented. A regression to a
    // light-gray value like #999999 would fail AA.
    expect(ratio, `format-desc contrast ${ratio.toFixed(2)}:1 must clear WCAG AA (4.5:1)`).toBeGreaterThanOrEqual(4.5);
  });

  test('lang-name text contrast meets WCAG AA on light surface', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Real selector from SELECTORS.md §4 / production CSS line 579.
    // The plugin only renders the language card when WPML is active, so we
    // fall back to the post-type name (same selector group, same token
    // resolution) if WPML is not present.
    let langName = adminPage.locator('.sscribe-lang-name').first();
    if ((await langName.count()) === 0) {
      langName = adminPage.locator('.sscribe-post-type-name').first();
    }
    await expect(langName).toBeVisible();

    const ratio = await langName.evaluate((el) => {
      const cs = window.getComputedStyle(el);
      const fg = parseRGB(cs.color);
      let node: Element | null = el as Element;
      let bg: { r: number; g: number; b: number } = { r: 255, g: 255, b: 255 };
      while (node) {
        const ncs = window.getComputedStyle(node);
        const parsed = parseRGB(ncs.backgroundColor);
        if (parsed.a > 0) {
          bg = parsed;
          break;
        }
        node = node.parentElement;
      }
      return contrastRatio(fg, bg);

      type RGB = { r: number; g: number; b: number; a: number };
      function parseRGB(value: string): RGB {
        const m = value.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([0-9.]+))?\s*\)/);
        if (!m) return { r: 0, g: 0, b: 0, a: 1 };
        return {
          r: parseInt(m[1], 10),
          g: parseInt(m[2], 10),
          b: parseInt(m[3], 10),
          a: m[4] !== undefined ? parseFloat(m[4]) : 1,
        };
      }
      function relativeLuminance(c: { r: number; g: number; b: number }): number {
        const channel = (v: number) => {
          const s = v / 255;
          return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
      }
      function contrastRatio(
        fg: { r: number; g: number; b: number },
        bg: { r: number; g: number; b: number }
      ): number {
        const L1 = relativeLuminance(fg);
        const L2 = relativeLuminance(bg);
        const lighter = Math.max(L1, L2);
        const darker = Math.min(L1, L2);
        return (lighter + 0.05) / (darker + 0.05);
      }
    });

    expect(ratio, `lang-name contrast ${ratio.toFixed(2)}:1 must clear WCAG AA (4.5:1)`).toBeGreaterThanOrEqual(4.5);
  });

  test('toast exposes a keyboard-operable dismiss button', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    await adminPage.evaluate(() => {
      const api = (window as typeof window & { SScribe?: { showToast?: (message: string, type: string, duration: number) => void } }).SScribe;
      if (!api || typeof api.showToast !== 'function') {
        throw new Error('SScribe.showToast is unavailable');
      }
      api.showToast('Keyboard toast', 'info', 0);
    });

    const toast = adminPage.locator('.sscribe-toast').filter({ hasText: 'Keyboard toast' });
    await expect(toast).toBeVisible();

    const dismiss = toast.locator('button.sscribe-toast-dismiss');
    await expect(dismiss).toBeVisible();
    await dismiss.focus();
    await expect(dismiss).toBeFocused();
    await dismiss.press('Enter');
    await expect(toast).toHaveCount(0);
  });

  test('preflight dismissal restores focus to the invoking control', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    const trigger = adminPage.locator('#sscribe-export-btn');
    await trigger.focus();
    await expect(trigger).toBeFocused();

    await adminPage.evaluate(() => {
      const api = (window as typeof window & {
        SScribe?: {
          showPreflightWarnings?: (
            diagnostics: { checks: Record<string, { status: string; name: string; message: string }> },
            onProceed: () => void,
            allowProceed: boolean
          ) => void;
        };
      }).SScribe;
      if (!api || typeof api.showPreflightWarnings !== 'function') {
        throw new Error('SScribe.showPreflightWarnings is unavailable');
      }
      api.showPreflightWarnings(
        { checks: { warning: { status: 'warning', name: 'Test warning', message: 'Focus regression test' } } },
        () => undefined,
        true
      );
    });

    const close = adminPage.locator('.sscribe-preflight-close');
    await expect(close).toBeFocused();
    await close.click();
    await expect(adminPage.locator('.sscribe-preflight-banner')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('tab buttons expose the WAI-ARIA tabs pattern', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    const tablist = adminPage.locator('nav.sscribe-tabs-nav[role="tablist"]');
    await expect(tablist).toBeVisible();

    const tabs = adminPage.locator('.sscribe-tab-btn[role="tab"]');
    const count = await tabs.count();
    expect(count, 'tablist must have at least the export + history tabs').toBeGreaterThanOrEqual(2);

    // Exactly one tab is aria-selected=true at any time (WAI-ARIA tabs pattern).
    const selectedCount = await tabs.evaluateAll((nodes) =>
      nodes.filter((n) => n.getAttribute('aria-selected') === 'true').length
    );
    expect(selectedCount, 'exactly one tab must be aria-selected=true').toBe(1);

    // Every tab must have aria-controls pointing at a real tabpanel id.
    const controlsIds = await tabs.evaluateAll((nodes) =>
      nodes.map((n) => n.getAttribute('aria-controls') || '').filter(Boolean)
    );
    for (const id of controlsIds) {
      const target = adminPage.locator(`#${id}`);
      await expect(target, `aria-controls target #${id} must exist`).toHaveCount(1);
      const role = await target.first().getAttribute('role');
      expect(role, `#${id} must have role="tabpanel"`).toBe('tabpanel');
    }

    // The active tab's panel must NOT be aria-hidden; inactive panels MUST be.
    const activeId = await tabs
      .evaluateAll((nodes) => {
        const n = nodes.find((x) => x.getAttribute('aria-selected') === 'true');
        return n ? n.getAttribute('aria-controls') : null;
      });
    expect(activeId).not.toBeNull();
    const activePanel = adminPage.locator(`#${activeId}`);
    expect(await activePanel.getAttribute('aria-hidden')).toBe('false');

    // aria-selected toggles when a different tab is clicked.
    const historyTab = adminPage.locator('#sscribe-tab-btn-history');
    await historyTab.click();
    await expect(historyTab).toHaveAttribute('aria-selected', 'true');
    await expect(adminPage.locator('#sscribe-tab-btn-export')).toHaveAttribute('aria-selected', 'false');
  });
});
