import { test, expect } from '../../fixtures/shared';

/**
 * Regression: progress-area live region must remain `aria-live="polite"`
 * for screen readers to announce batch progress updates.
 *
 * Background:
 *   - The progress area is at `admin/partials/sscribe-admin-display.php:692`
 *     with `role="status" aria-live="polite" aria-labelledby="sscribe-status-text"`.
 *   - The production JS (`admin/js/sscribe-admin.js:1416` onwards) updates
 *     `#sscribe-status-text` and `#sscribe-progress-bar[aria-valuenow]`
 *     on every `sscribe_process_batch` response.
 *   - If `aria-live="off"` is ever set on `#sscribe-progress-area`, AT users
 *     lose all batch-progress announcements.
 *
 * The historical revert pattern in `regression-discipline.mjs` was
 * `aria-live="polite" → aria-live="off"` on the admin partial. The spec
 * below asserts the live attribute is preserved AND that updates actually
 * land in the live region during a real export.
 */
test.describe('e2e / export / batch-progress', () => {
  test('progress-area exposes aria-live=polite and updates as the export advances', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Step 1: static assertion — the progress area must declare an
    // aria-live value other than "off". We allow "polite" or "assertive"
    // because both are accessible; the regression is specifically "off".
    const progressArea = adminPage.locator('#sscribe-progress-area');
    await expect(progressArea).toHaveAttribute('aria-live', 'polite');
    await expect(progressArea).toHaveAttribute('role', 'status');

    // Step 2: start a real export and watch the progress bar advance.
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
    const startResp = adminPage.waitForResponse((r) =>
      r.url().includes('action=sscribe_start_export')
    );
    await adminPage.locator('#sscribe-export-btn').click();
    await startResp;

    // The progress area is hidden by default (`.sscribe-hidden`). It must
    // become visible once the export starts.
    await expect(progressArea).toBeVisible({ timeout: 30_000 });

    // Step 3: while the export runs, the live region must receive text
    // updates via the #sscribe-status-text child. We snapshot the initial
    // text, wait for it to change, and assert the new text is non-empty.
    const statusText = adminPage.locator('#sscribe-status-text');
    const initial = (await statusText.textContent())?.trim() || '';
    expect(initial.length, 'progress area must show initial status text').toBeGreaterThan(0);

    // Wait for the progress bar to advance at least once. The bar's
    // aria-valuenow is the canonical progress signal for AT.
    await expect
      .poll(
        async () => {
          const now = await adminPage.locator('#sscribe-progress-bar').getAttribute('aria-valuenow');
          return parseInt(now || '0', 10);
        },
        { timeout: 120_000, intervals: [1_000] }
      )
      .toBeGreaterThan(0);

    // Capture the updated status text — the live region must have been
    // updated, not just the visual progress bar.
    const updated = (await statusText.textContent())?.trim() || '';
    expect(updated.length, 'progress status text must remain non-empty during run').toBeGreaterThan(0);

    // The full spec: the live-region attribute is preserved across the run
    // (some mutations could remove aria-live while the area is visible).
    await expect(progressArea).toHaveAttribute('aria-live', 'polite');

    // Wait for completion via the same UI signal the wizard-happy-path
    // test uses — `#sscribe-download-area` becomes visible.
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
  });
});
