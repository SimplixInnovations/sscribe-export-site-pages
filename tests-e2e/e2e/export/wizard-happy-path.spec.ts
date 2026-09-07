import { test, expect } from '../../fixtures/shared';
import { assertJSONOK } from '../../helpers/assert-json-ok';

test.describe('e2e / export / wizard-happy-path', () => {
  test('full export wizard happy path: select → start → batch → finalize → download', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Wait for counts AJAX to complete — the export button is disabled
    // until `sscribe_get_status_counts` returns. jQuery $.post puts the
    // data in the request body, so check postData() for the action.
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
    // Wait until the export button reports enabled. The button enables
    // only after the counts success handler runs (admin/js/sscribe-admin.js:
    // 679-682), which depends on the language-roundtrip shim succeeding.
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });

    // Select the docx format. Use `force: true` to bypass the
    // `.sscribe-format-card-inner` overlay that intercepts pointer events
    // on the hidden radio input (admin/partials/sscribe-admin-display.php:
    // 441-442). `check({ force: true })` dispatches the click directly on
    // the input — same behavior as a real user with keyboard / AT, and
    // same result (radio becomes :checked, change event fires).
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });

    // Click Start Export (primary CTA). Phantom `.sscribe-start-export-btn`
    // is corrected to `#sscribe-export-btn` per SELECTORS.md §22. The
    // AJAX action name lives in the POST body (jQuery $.ajax pattern),
    // not the URL — match against postData() like the counts handler.
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
    const start = JSON.parse(await (await startResp).text());
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

    // The fadeIn callback (admin/js/sscribe-admin.js:2050-2053) is what
    // writes the download URL into `#sscribe-download-btn`; it runs AFTER
    // the 400ms fadeIn completes. Visibility flips at the START of the
    // fadeIn, so we additionally wait for the href to be the live ZIP URL
    // (token-bearing) before clicking. Otherwise the click fires with
    // `href="#"` and Playwright captures the wrong navigation as a
    // download with a non-ZIP suggestedFilename.
    await expect(adminPage.locator('#sscribe-download-btn')).toHaveAttribute(
      'href',
      /[?&]token=[a-f0-9]{32}/,
      { timeout: 30_000 }
    );

    // Download (phantom `.sscribe-download-btn` corrected to
    // `#sscribe-download-btn` per SELECTORS.md §8).
    const downloadPromise = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const download = await downloadPromise;
    const path = await download.path();
    expect(path).not.toBeNull();
    // Filename pattern (production): {site-name}-{YYYY-MM-DD}-{HHMMSS}-
    // {lang-tag}-{format}-{6-hex}.zip — the zip-handler derives the base
    // from the live site name (includes/class-sscribe-zip-handler.php),
    // NOT the literal string "sscribe-export". We anchor the post-name
    // part of the filename because that's the contract the ZIP archive
    // guarantees; the leading site-name segment is host-specific.
    expect(download.suggestedFilename()).toMatch(/-\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9-]+-[a-z0-9]{6}\.zip$/i);
  });
});