import { test, expect } from '../../fixtures/shared';

/**
 * Regression: download tokens are single-use and rotated on first consume.
 *
 * Background (production behavior at
 * `includes/class-sscribe-batch-file-handler.php:147-155` and
 * `includes/class-sscribe-zip-handler.php:738-782`):
 *
 *   - The download URL embeds a 32-hex-char token via `?token=<hex>`.
 *   - `consume_dl_token()` reads the stored token from
 *     `sscribe_export_row_<md5(zip)>` under a per-export lock, runs a
 *     constant-time compare (`hash_equals`), and on success rotates the
 *     token to a fresh `random_bytes(16)` value.
 *   - The next request with the original token returns 403 ("already used
 *     or expired").
 *
 * The regression scenario flagged in `regression-discipline.mjs` is "if the
 * expiry/consume check is neutralized, replayed tokens work". The spec
 * therefore exercises the live UI: build a token via the wizard-happy-path
 * flow, attempt to use the same token a second time, and assert the second
 * request returns the 403 audit message.
 *
 * This is the realistic surface — we don't write raw `random_bytes` to PHP
 * globals from inside the browser test. The wizard-happy-path test in this
 * suite already proves the first consumption works; here we only need to
 * prove the SECOND consumption is rejected.
 */
test.describe('e2e / export / download-token-auth', () => {
  test('download token is single-use — replay returns 403', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Step 1: trigger a fresh export so we get a token-bearing URL.
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
    const startResp = adminPage.waitForResponse((r) =>
      r.url().includes('action=sscribe_start_export')
    );
    await adminPage.locator('#sscribe-export-btn').click();
    await startResp;

    // Wait for the download area to surface (UI signal that export is
    // complete and the download URL was written to #sscribe-download-btn).
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });

    // Capture the token-bearing URL the JS wrote into the link.
    const downloadHref = await adminPage
      .locator('#sscribe-download-btn')
      .getAttribute('href');
    expect(downloadHref, 'download button must expose a token-bearing URL').toBeTruthy();
    expect(downloadHref, 'URL must contain a ?token= query parameter').toMatch(/[?&]token=[a-f0-9]{32}/);

    const token = new URL(downloadHref!).searchParams.get('token');
    expect(token, 'extracted token must be 32 hex chars').toMatch(/^[a-f0-9]{32}$/);

    // Step 2: first download. We use request.fetch() instead of the link
    // click so we can inspect the response status without Playwright's
    // download handling swallowing the page event.
    const firstStatus = await adminPage.evaluate(async (href) => {
      const r = await fetch(href, { redirect: 'manual', credentials: 'same-origin' });
      return r.status;
    }, downloadHref);
    // The production handler streams a 200 with the ZIP body. We don't care
    // about the body — only that the request was accepted.
    expect(firstStatus, 'first download must succeed (HTTP 200)').toBe(200);

    // Step 3: replay the same token. Production rotates the stored value on
    // the first consume, so the second request must be rejected.
    const secondStatus = await adminPage.evaluate(async (href) => {
      const r = await fetch(href, { redirect: 'manual', credentials: 'same-origin' });
      // `wp_die` writes a 403 then echoes the message as text/html — the body
      // contains "already been used or has expired".
      const body = await r.text();
      return { status: r.status, body };
    }, downloadHref);

    expect(secondStatus.status, 'replayed download must be rejected (HTTP 403)').toBe(403);
    expect(
      secondStatus.body,
      'rejected download body must mention the audit message'
    ).toMatch(/already been used or has expired/i);
  });
});
