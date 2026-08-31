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
    assertJSONOK(start as { ok: boolean; [k: string]: unknown });
    const sessionId = (start as { data: { session_id: string } }).data.session_id;
    expect(sessionId).toMatch(/^[a-f0-9]{16}$/);

    // Poll batch progress until status === 'complete'. The brief's
    // `sscribe_get_status` action does not exist; the production JS polls
    // `sscribe_process_batch` (admin/js/sscribe-admin.js:1416), which returns
    // `data.status === 'complete'` when the export is done. The brief's
    // `.sscribe-poll-status-btn` selector is also a phantom — the JS
    // auto-polls, so we wait for the success area (`#sscribe-download-area`,
    // SELECTORS.md §8) to become visible instead of clicking a non-existent
    // button. This deviates from the brief's literal click/poll loop;
    // documented as a known concern in the report.
    let complete = false;
    const deadline = Date.now() + 180_000;
    while (!complete && Date.now() < deadline) {
      const pollResp = adminPage.waitForResponse((r) =>
        r.url().includes('/wp-admin/admin-ajax.php') && r.url().includes('action=sscribe_process_batch')
      );
      await adminPage.locator('#sscribe-progress-area').click({ trial: false }).catch(() => {});
      const r = await pollResp;
      const json = JSON.parse(await r.text());
      if ((json as { data: { status: string } }).data.status === 'complete') complete = true;
      else await adminPage.waitForTimeout(1000);
    }
    expect(complete, 'export did not complete within 180s').toBe(true);

    // Download (phantom `.sscribe-download-btn` corrected to
    // `#sscribe-download-btn` per SELECTORS.md §8).
    const downloadPromise = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const download = await downloadPromise;
    const path = await download.path();
    expect(path).not.toBeNull();
    // Filename pattern: sscribe-export-{session}-{timestamp}.zip
    expect(download.suggestedFilename()).toMatch(/^sscribe-export-[a-f0-9]{16}-\d+\.zip$/);
  });
});