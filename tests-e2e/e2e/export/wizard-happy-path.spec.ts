import { test, expect } from '../../fixtures/shared';
import { assertJSONOK } from '../../helpers/assert-json-ok';

test.describe('e2e / export / wizard-happy-path', () => {
  test('full export wizard happy path: select → start → batch → finalize → download', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Select docx + html format radios. The phantom
    // `.sscribe-format-card[data-format="…"]` selector is corrected to the
    // radio inputs cataloged at SELECTORS.md §3.
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
    await adminPage.locator('input[name="sscribe_format"][value="html"]').check();

    // Click Start Export (primary CTA). Phantom `.sscribe-start-export-btn`
    // is corrected to `#sscribe-export-btn` per SELECTORS.md §22.
    const startResp = adminPage.waitForResponse((r) =>
      r.url().includes('/wp-admin/admin-ajax.php') && r.url().includes('action=sscribe_start_export')
    );
    await adminPage.locator('#sscribe-export-btn').click();
    const start = JSON.parse((await startResp).text());
    assertJSONOK(start as { success: boolean; [k: string]: unknown });
    const sessionId = (start as { data: { session_id: string } }).data.session_id;
    expect(sessionId).toMatch(/^[a-f0-9]{16}$/);

    // Wait for completion. The production JS auto-polls `sscribe_process_batch`
    // (admin/js/sscribe-admin.js:1416) and switches to `sscribe_finalize_export`
    // (admin/js/sscribe-admin.js:1763) once the batch returns
    // `data.status === 'finalizing'`. Neither endpoint ever returns
    // `data.status === 'complete'` from `process_batch` — that string only
    // appears in the response of `sscribe_finalize_export` after the ZIP is
    // built. Rather than mirror the JS's two-stage poll inside the test, we
    // wait for the UI signal `exportComplete()` raises: `#sscribe-download-area`
    // becomes visible once the ZIP is ready
    // (admin/js/sscribe-admin.js:1653-1659). This is the same signal the user
    // sees in production.
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });

    // Download (phantom `.sscribe-download-btn` corrected to
    // `#sscribe-download-btn` per SELECTORS.md §8).
    const downloadPromise = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const download = await downloadPromise;
    const path = await download.path();
    expect(path).not.toBeNull();
    // Filename pattern: sscribe-export-{gmdate('Y-m-d-His')}-{6-hex}.zip
    // Production builds this at includes/class-sscribe-zip-handler.php:135-136.
    expect(download.suggestedFilename()).toMatch(/^sscribe-export-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{6}\.zip$/);
  });
});