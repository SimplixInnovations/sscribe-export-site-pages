import { test, expect } from '../fixtures/shared';

/**
 * Diagnostic-only: prove whether the test-session reset takes effect
 * across an export page reload. Captures the post-reset AJAX
 * sscribe_check_active_session payload via the response listener.
 *
 * NOT part of the E2E suite. Build-side only. Gated by env so it
 * never runs in CI by accident.
 */
test('diag: reset endpoint clears active session across admin page reload', async ({ adminPage }) => {
  test.skip(!process.env.SS_DIAG, 'set SS_DIAG=1 to run');
  // Note: do NOT call the reset endpoint first; we want a fresh admin
  // user with no prior session, then drive a start_export, then
  // prove the reset+reload combo clears it.
  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
  await adminPage.waitForResponse(
    (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sscribe_check_active_session'),
    { timeout: 30_000 }
  );
  // Start export to leave a session behind.
  const startResp = adminPage.waitForResponse(
    (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sscribe_start_export'),
    { timeout: 60_000 }
  );
  await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 30_000 });
  await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });
  await adminPage.locator('#sscribe-export-btn').click();
  const start = JSON.parse(await (await startResp).text());
  const sid = (start as { data: { session_id: string } }).data.session_id;
  console.log('diag: started session', sid);

  // Cancel any in-flight sessions, then reset row state.
  const cancelResp = await adminPage.context().request.get('/?ssb_test_cancel_all=1');
  const before = await cancelResp.json().catch(() => null);
  console.log('diag: cancel-all response', before);

  const resetResp = await adminPage.context().request.get('/?ssb_test_reset=1');
  console.log('diag: reset response', await resetResp.text().catch(() => '?'));

  // Probe to see how many sessions are now in DB.
  const probe = await adminPage.context().request.get('/?ssb_session_count=1');
  console.log('diag: session-count probe', probe.status(), await probe.text().catch(() => '?'));

  // Reload — admin_init hook should now wipe state too.
  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

  // Capture the post-reload AJAX response.
  const afterAjax = adminPage.waitForResponse(
    (r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sscribe_check_active_session'),
    { timeout: 30_000 }
  );
  await adminPage.waitForLoadState('domcontentloaded');
  const afterText = await (await afterAjax).text();
  console.log('diag: post-reload AJAX payload', afterText);
  const after = JSON.parse(afterText);
  expect(after.success).toBe(true);
  expect(after.data.has_active, 'has_active must be false after reset + reload').toBe(false);
});
