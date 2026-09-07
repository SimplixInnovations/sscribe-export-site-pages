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
 * This spec exercises BOTH directions of the toggle (flip via slider, then
 * save via the Save Settings button — that's the production AJAX path,
 * see admin/js/sscribe-debug-console.js:712-718: the AJAX action is
 * `sscribe_debug_save_settings` and is fired only on Save, not on toggle).
 * After saving, the assets MUST still be present on the page.
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

    // The toggle's <span class="sscribe-toggle-slider"> is a visual overlay
    // that sits on top of the hidden <input>. Real users click the slider;
    // Playwright must click the slider too, otherwise it sees the span
    // covering the input and refuses to click.
    const slider = page.locator(
      'label.sscribe-debug-toggle-label:has(#sscribe-debug-enabled) .sscribe-toggle-slider'
    );
    const saveButton = page.locator('#sscribe-debug-save-settings');

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

    // Step 2: flip the toggle. The production flow is:
    //   1) click slider → input flips + change handler marks Save button dirty
    //      (admin/js/sscribe-debug-console.js:207-216).
    //   2) click Save → AJAX POST action=sscribe_debug_save_settings writes
    //      sscribe_debug_enabled + log_level + auto_refresh
    //      (admin/js/sscribe-debug-console.js:712-718).
    // No AJAX fires on the toggle click itself. We wait for the AJAX that
    // fires on Save.
    const updatedToggleState = await toggle.getAttribute('aria-checked');
    const targetState = updatedToggleState === 'true' ? 'false' : 'true';

    await slider.click();
    // The save button should now be marked dirty.
    await expect(saveButton).toHaveClass(/sscribe-button-dirty/);

    // jQuery $.post sends the data as the request body, not in the URL.
    // The URL is just admin-ajax.php. We must read postData() to check
    // action=sscribe_debug_save_settings.
    const ajaxResponse = page.waitForResponse(
      async (r) => {
        if (!r.url().includes('/wp-admin/admin-ajax.php')) return false;
        try {
          const post = r.request().postData() || '';
          return post.includes('action=sscribe_debug_save_settings');
        } catch {
          return false;
        }
      }
    );
    await saveButton.click();
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
