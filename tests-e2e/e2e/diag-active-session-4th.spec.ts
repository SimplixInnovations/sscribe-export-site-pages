import { test, expect } from '../fixtures/shared';

/**
 * Diag probe: after 3 retry-policy tests run, capture what the
 * 4th test sees for has_active on its first check_active_session
 * response. Prints the AJAX payload so we can see exactly why the
 * export button stays disabled.
 *
 * Gated by SS_DIAG_RUN_4 env so it never runs accidentally.
 */
test('diag: simulate 4-test retry sequence and dump 4th test state', async ({ adminPage, request }) => {
  test.skip(!process.env.SS_DIAG_RUN_4, 'set SS_DIAG_RUN_4=1');

  // Boot + start an export (no intercepts — let it run real).
  await request.get('/?ssb_test_cancel_all=1');
  await request.get('/?ssb_test_reset=1');

  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
  const activeResp = adminPage.waitForResponse(
    async (r) => {
      if (!r.url().includes('admin-ajax.php')) return false;
      try { return (r.request().postData() || '').includes('action=sscribe_check_active_session'); }
      catch { return false; }
    },
    { timeout: 30_000 }
  );
  const activeText = await (await activeResp).text();
  console.log('diag: FIRST-TEST check_active_session JSON', activeText);
  const countsResp = adminPage.waitForResponse(
    async (r) => {
      if (!r.url().includes('admin-ajax.php')) return false;
      try { return (r.request().postData() || '').includes('action=sscribe_get_status_counts'); }
      catch { return false; }
    },
    { timeout: 60_000 }
  );
  const countsText = await (await countsResp).text();
  console.log('diag: FIRST-TEST get_status_counts JSON', countsText);
  await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 30_000 });
  await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });

  const startResp = adminPage.waitForResponse(
    async (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sscribe_start_export'),
    { timeout: 60_000 }
  );
  await adminPage.locator('#sscribe-export-btn').click();
  const startText = await (await startResp).text();
  console.log('diag: start_export payload', startText);
  const sid = (JSON.parse(startText) as { data: { session_id: string } }).data.session_id;
  console.log('diag: sid', sid);

  // Wait for first batch.
  await adminPage.waitForResponse(
    async (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sscribe_process_batch'),
    { timeout: 60_000 }
  );

  // Now: cancel-all + reset (simulating test boundary).
  const cancelText = await (await request.get('/?ssb_test_cancel_all=1')).text();
  console.log('diag: cancel-all response', cancelText);
  const resetText = await (await request.get('/?ssb_test_reset=1')).text();
  console.log('diag: reset response', resetText);

  // What does check_active_session report NOW (in-process)?
  const probe1 = await (await request.get('/?ssb_session_count=1')).text();
  console.log('diag: session-count probe', probe1);

  // Now what about JS-side: navigate the page to export page fresh.
  // Capture the FIRST check_active_session AJAX response.
  const firstAjax = adminPage.waitForResponse(
    async (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=sscribe_check_active_session'),
    { timeout: 30_000 }
  );
  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
  const firstAjaxText = await (await firstAjax).text();
  console.log('diag: 4th-test first check_active_session JSON', firstAjaxText);

  // And dump button + SScribe state.
  await adminPage.waitForTimeout(2000);
  const btnState = await adminPage.locator('#sscribe-export-btn').evaluate((el: HTMLButtonElement) => ({
    disabled: el.disabled,
    classList: el.className,
    title: el.title,
  }));
  console.log('diag: 4th-test button state', btnState);
  const ssState = await adminPage.evaluate(() => ({
    isProcessing: (window as any).SScribe?.isProcessing ?? 'undef',
    isPreparing: (window as any).SScribe?.isPreparing ?? 'undef',
    sessionId: (window as any).SScribe?.sessionId ?? null,
  }));
  console.log('diag: 4th-test SScribe state', ssState);
});
