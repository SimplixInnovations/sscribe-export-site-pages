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
  test('download token is single-use: replay returns 403', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Wait for counts AJAX to complete first — the export button is
    // disabled until `sscribe_get_status_counts` returns.
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

    // Step 1: trigger a fresh export so we get a token-bearing URL.
    // `check({ force: true })` bypasses the `.sscribe-format-card-inner`
    // overlay that intercepts pointer events on the hidden radio
    // (admin/partials/sscribe-admin-display.php:441-442) and dispatches
    // the click directly on the input — same result as a real user.
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });
    // jQuery $.ajax puts the action name in the POST body, not the URL —
    // match against postData() like the counts handler above.
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

    // Wait for the download area to surface AND for the production JS to
    // set the token-bearing href. The fadeIn callback in exportComplete()
    // (admin/js/sscribe-admin.js:2050) is what writes the href — so we
    // poll the href attribute itself rather than waiting only for visibility
    // (the area becomes visible at the START of the 400ms fadeIn, before
    // the callback runs).
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
    await expect(adminPage.locator('#sscribe-download-btn')).toHaveAttribute(
      'href',
      /[?&]token=[a-f0-9]{32}/,
      { timeout: 30_000 }
    );

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
    // `consume_dl_token()` rotates under the per-row lock and returns false;
    // the handler then issues `wp_die( $msg, '', [ 'response' => 403 ] )`,
    // which sends the audit message as text/html with HTTP 403.
    const secondStatus = await adminPage.evaluate(async (href) => {
      const r = await fetch(href, { redirect: 'manual', credentials: 'same-origin' });
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
