import { test, expect } from './fixtures/shared';

test.describe('00-smoke — stack canary', () => {
  test('00-smoke: admin page renders + WP global present', async ({ adminPage, request }) => {
    // 1. Canary: did wp-cli step write the canary file?
    const canary = await request.get('/wp-content/uploads/canary.txt');
    const canaryText = canary.status() === 200 ? await canary.text() : `HTTP ${canary.status()}`;
    console.log('CANARY:', canaryText);
    expect(canaryText).toContain('m=Y');
    expect(canaryText).toContain('p=Y');

    // 2. Probe what /wp-admin/ requests return BEFORE login
    const adminPre = await request.get('/wp-admin/admin.php?page=sscribe-export');
    console.log('ADMIN_PRE_STATUS:', adminPre.status());
    const adminPreText = adminPre.status() === 200 ? await adminPre.text() : await adminPre.text();
    console.log('ADMIN_PRE_BODY:', adminPreText.slice(0, 300));

    // 3. Probe what /wp-login.php returns
    const login = await request.get('/wp-login.php');
    console.log('LOGIN_STATUS:', login.status());

    // 4. Read trace after these probe requests
    const tr = await request.get('/wp-content/uploads/sscribe-bootstrap-trace.txt');
    const trText = tr.status() === 200 ? await tr.text() : `HTTP ${tr.status()}`;
    console.log('TRACE_AFTER_PROBES:', trText);

    // 5. Now try adminPage.goto() (login flow happens via fixture)
    const response = await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    const status = response?.status() ?? 0;
    console.log('ADMIN_STATUS:', status);

    // 6. Trace after adminPage goto
    const tr2 = await request.get('/wp-content/uploads/sscribe-bootstrap-trace.txt');
    const tr2Text = tr2.status() === 200 ? await tr2.text() : `HTTP ${tr2.status()}`;
    console.log('TRACE_AFTER_ADMIN:', tr2Text);

    // 7. Read heartbeat to see what the mu-plugin thinks the state is
    const hb = await request.get('/wp-content/uploads/sscribe-bootstrap-heartbeat.txt');
    const hbText = hb.status() === 200 ? await hb.text() : `HTTP ${hb.status()}`;
    console.log('HEARTBEAT:', hbText.slice(0, 500));

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
