import { test, expect } from '../fixtures/shared';

/**
 * Diag probe: after the bootExport path runs for test 2, dump the
 * DOM state of the export button + the JS state of SScribe.isProcessing.
 */
test('diag: dump button state after cancel-all + reset', async ({ adminPage }) => {
  test.skip(!process.env.SS_DIAG_DUMP, 'set SS_DIAG_DUMP=1');

  // Direct probe of the cancel-all endpoint URL shape.
  const probe = await adminPage.context().request.get('/', {
    headers: { Accept: 'application/json' },
  });
  console.log('diag: probe status', probe.status());
  console.log('diag: probe content-type', probe.headers()['content-type'] || 'none');
  const probeBody = await probe.text();
  console.log('diag: probe first 200 chars', probeBody.slice(0, 200));
  expect(probeBody).toContain('ssb_test_cancel_all');
  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
  await adminPage.waitForLoadState('domcontentloaded');
  // Wait a moment for AJAX to settle.
  await adminPage.waitForTimeout(3000);

  const buttonState = await adminPage.locator('#sscribe-export-btn').evaluate(
    (el: HTMLButtonElement) => ({
      disabled: el.disabled,
      title: el.title,
      classList: el.className,
    })
  );
  console.log('diag: button state', buttonState);

  const sscribeState = await adminPage.evaluate(() => {
    return {
      has_SScribe: typeof (window as any).SScribe !== 'undefined',
      isProcessing: (window as any).SScribe?.isProcessing ?? 'no-SScribe',
      sessionId: (window as any).SScribe?.sessionId ?? null,
      selectedPageCount: (window as any).SScribe?.selectedPageCount ?? '?',
      countsState: (window as any).SScribe?.countsState ?? '?',
      countsLoaded: (window as any).SScribe?.countsState?.loaded ?? '?',
    };
  });
  console.log('diag: SScribe state', sscribeState);

  // Last check: dump the most recent check_active_session response.
  const lastActive = await adminPage.evaluate(async () => {
    return await new Promise<string>((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', (window as any).ajaxurl || '/wp-admin/admin-ajax.php');
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = () => resolve(xhr.responseText);
      const formData = `action=sscribe_check_active_session&nonce=${(window as any).sscribe_data?.nonce ?? ''}`;
      xhr.send(formData);
    });
  });
  console.log('diag: live AJAX response', lastActive);
  expect(buttonState.disabled).toBe(false);
});
