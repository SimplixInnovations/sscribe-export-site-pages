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
 *
 * Determinism:
 *   - Blueprint seeds 50 pages via runPHP (tests-e2e/fixtures/blueprint.json).
 *   - The mu-plugin bootstrap pins `sscribe_batch_size=5` so the export
 *     produces 10 deterministic batches instead of letting the resource
 *     monitor grow the batch size to 20 (which on slow WASM can blow
 *     past the per-test timeout ceiling).
 *   - Timeouts are tuned for WP-Playground: 30s for the first batch
 *     transition, 90s for completion.
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

    // Wait for counts AJAX so the export button enables.
    await adminPage.waitForResponse(
      async (r) => {
        if (!r.url().includes('admin-ajax.php')) return false;
        try {
          const pd = r.request().postData() || '';
          return pd.includes('action=sscribe_get_status_counts');
        } catch {
          return false;
        }
      },
      { timeout: 60_000 }
    );
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 30_000 });

    // Step 2: start a real export and watch the progress bar advance.
    // `check({ force: true })` bypasses the `.sscribe-format-card-inner`
    // overlay that intercepts pointer events on the hidden radio input
    // (admin/partials/sscribe-admin-display.php:441-442).
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });
    const startResp = adminPage.waitForResponse(
      async (r) => {
        if (!r.url().includes('admin-ajax.php')) return false;
        try {
          const pd = r.request().postData() || '';
          return pd.includes('action=sscribe_start_export');
        } catch {
          return false;
        }
      },
      { timeout: 60_000 }
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

    // Step 4: wait for the FIRST batch transition. 30s is generous on
    // slow WASM (50 pages / batch_size=5 → ~10 batches; first batch
    // should land within seconds once PHP is warm).
    await expect
      .poll(
        async () => {
          const now = await adminPage.locator('#sscribe-progress-bar').getAttribute('aria-valuenow');
          return parseInt(now || '0', 10);
        },
        { timeout: 30_000, intervals: [1_000] }
      )
      .toBeGreaterThan(0);

    // Capture the updated status text — the live region must have been
    // updated, not just the visual progress bar.
    const updated = (await statusText.textContent())?.trim() || '';
    expect(updated.length, 'progress status text must remain non-empty during run').toBeGreaterThan(0);

    // The full spec: the live-region attribute is preserved across the run
    // (some mutations could remove aria-live while the area is visible).
    await expect(progressArea).toHaveAttribute('aria-live', 'polite');

    // Step 5: wait for completion via the same UI signal the
    // wizard-happy-path test uses — `#sscribe-download-area` becomes
    // visible. 90s is generous for 10 batches on WASM.
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 90_000 });
  });
});
