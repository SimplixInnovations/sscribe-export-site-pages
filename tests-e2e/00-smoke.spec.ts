import { test, expect } from './fixtures/shared';

test.describe('00-smoke — stack canary', () => {
  test('00-smoke: admin page renders + WP global present', async ({ adminPage, request }) => {
    // 1. Canary: did the native bootstrap write the canary file? The native
    // runtime's canary format is pages=N + active=[...] + sscribe_active=N +
    // bootstrap_time=ISO (see tests-e2e/runtime/native-wordpress.ts:240-245).
    // The legacy m=Y/p=Y keys were the WP-Playground canary format; the
    // sscribe_active=1 marker implies both mu-plugins loaded and plugins
    // loaded (the runtime's mu-plugin activates SScribe on bootstrap).
    const canary = await request.get('/wp-content/uploads/canary.txt');
    const canaryText = canary.status() === 200 ? await canary.text() : `HTTP ${canary.status()}`;
    console.log('CANARY:', canaryText);
    expect(canaryText).toContain('sscribe_active=1');
    expect(canaryText).toMatch(/^pages=\d+/m);

    // 2. Admin page goto (login flow happens via fixture)
    const response = await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    const status = response?.status() ?? 0;
    console.log('ADMIN_STATUS:', status);

    // 3. Diagnostic probes (non-fatal; PHP CLI server may produce
    //    parse errors on Connection:close after heavy AJAX traffic)
    try {
      const tr = await request.get('/wp-content/uploads/sscribe-bootstrap-trace.txt');
      console.log('TRACE:', tr.status() === 200 ? (await tr.text()).slice(0, 500) : `HTTP ${tr.status()}`);
    } catch (e) {
      console.log('TRACE_FETCH_ERROR:', (e as Error).message);
    }
    try {
      const hb = await request.get('/wp-content/uploads/sscribe-bootstrap-heartbeat.txt');
      console.log('HEARTBEAT:', hb.status() === 200 ? (await hb.text()).slice(0, 500) : `HTTP ${hb.status()}`);
    } catch (e) {
      console.log('HEARTBEAT_FETCH_ERROR:', (e as Error).message);
    }

    // 8. Page assertion: format card visible (real class is
    //    `.sscribe-format-card-inner`, the inner div inside the label
    //    wrapper).
    const bodyText = await adminPage.locator('body').innerText().catch(() => '');
    console.log('ADMIN_BODY:', bodyText.slice(0, 5000));
    const formatCard = adminPage.locator('.sscribe-format-card-inner').first();
    await expect(formatCard).toBeVisible();
    // 9. WP global injected.
    const wpLoaded = await adminPage.evaluate(() => typeof (window as unknown as { wp?: unknown }).wp !== 'undefined');
    expect(wpLoaded).toBe(true);
  });
});
