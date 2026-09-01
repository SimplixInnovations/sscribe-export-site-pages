import { test, expect } from '../../fixtures/shared';
import { loginAsAdmin } from '../../helpers/login';

/**
 * Regression: debug-tab CSS/JS must NEVER be gated behind the
 * `sscribe_debug_enabled` option (or any flag the tab's own toggle sets).
 *
 * Background (memory `toggle-ui-assets-gating.md`):
 * If the debug console's CSS/JS were gated on `sscribe_debug_enabled`, an
 * admin who arrived with logging OFF would see a toggle they could not
 * switch ON (the JS handler would never load). The contract is:
 *   - `sscribe-debug-console.css` is enqueued unconditionally for users with
 *     `manage_options` capability (admin/class-sscribe-admin.php:204-209).
 *   - `sscribe-debug-console.js` is enqueued unconditionally for the same
 *     cohort (admin/class-sscribe-admin.php:211-217).
 *   - The toggle (`#sscribe-debug-enabled`) reads/writes the option via AJAX;
 *     it never re-evaluates whether to inject assets.
 *
 * This spec exercises BOTH directions of the toggle to prove the contract
 * holds regardless of the underlying option state. After flipping the toggle
 * OFF (and confirming via AJAX), the assets MUST still be present on the page.
 */
test.describe('e2e / debug / toggle-ui-assets-gating', () => {
  test('debug console assets are enqueued for manage_options users regardless of sscribe_debug_enabled', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsAdmin(page);

    await page.goto('/wp-admin/admin.php?page=sscribe-export');

    // Step 1: assert assets are present in the CURRENT state (whatever it is).
    const debugTab = page.locator('#sscribe-tab-btn-debug');
    await debugTab.click();
    const toggle = page.locator('#sscribe-debug-enabled[role="switch"]');
    await expect(toggle).toBeAttached();

    const findAssets = () =>
      page.evaluate(() => {
        const css = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
          .map((el) => el.getAttribute('href') || '')
          .filter(Boolean);
        const js = Array.from(document.querySelectorAll('script[src]'))
          .map((el) => el.getAttribute('src') || '')
          .filter(Boolean);
        return {
          cssLoaded: css.some((h) => h.includes('sscribe-debug-console.css')),
          jsLoaded: js.some((s) => s.includes('sscribe-debug-console.js')),
        };
      });

    const before = await findAssets();
    expect(before.cssLoaded, 'assets must be enqueued in initial state').toBe(true);
    expect(before.jsLoaded, 'assets must be enqueued in initial state').toBe(true);

    // Step 2: flip the toggle via the AJAX path (the same one production
    // uses). The handler POSTs to sscribe_save_debug_settings, which writes
    // sscribe_debug_enabled. We wait for the response to confirm the write
    // happened, then verify the assets are STILL present on the page (the
    // page is not reloaded; only the option was flipped).
    const updatedToggleState = await toggle.getAttribute('aria-checked');
    const targetState = updatedToggleState === 'true' ? 'false' : 'true';

    const ajaxResponse = page.waitForResponse(
      (r) =>
        r.url().includes('/wp-admin/admin-ajax.php') &&
        (r.url().includes('sscribe_save_debug_settings') ||
          r.url().includes('sscribe_toggle_debug'))
    );
    await toggle.click();
    await ajaxResponse;

    // Step 3: the toggle's aria-checked must reflect the new state and the
    // assets must STILL be present. This is the contract.
    await expect(toggle).toHaveAttribute('aria-checked', targetState);

    const after = await findAssets();
    expect(
      after.cssLoaded,
      `assets must remain enqueued after toggle flip to aria-checked=${targetState}`
    ).toBe(true);
    expect(
      after.jsLoaded,
      `assets must remain enqueued after toggle flip to aria-checked=${targetState}`
    ).toBe(true);

    await ctx.close();
  });
});
