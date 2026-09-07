import { test, expect } from '../fixtures/shared';

/**
 * Step 2 probe: prove the testbed endpoint returns JSON.
 *
 * Uses Playwright's already-booted WP-Playground (port 9400) and the
 * request context the test fixture provides. Hits the URL directly
 * and asserts Content-Type + JSON shape + success.
 *
 * Gated by env so it never runs accidentally:
 *   PLAYGROUND_E2E_PROBE=1 to enable.
 */
test('diag: reset endpoint returns application/json', async ({ request }) => {
  test.skip(!process.env.PLAYGROUND_E2E_PROBE, 'set PLAYGROUND_E2E_PROBE=1');

  const resetResp = await request.get('/?ssb_test_reset=1', {
    headers: { Accept: 'application/json' },
  });
  console.log('probe reset status', resetResp.status());
  console.log('probe reset content-type', resetResp.headers()['content-type'] || 'none');
  const resetText = await resetResp.text();
  console.log('probe reset body (first 400 chars):', resetText.slice(0, 400));

  expect(resetResp.status()).toBe(200);
  expect(resetResp.headers()['content-type'] || '').toMatch(/application\/json/i);
  const resetJson = JSON.parse(resetText);
  expect(resetJson.success).toBe(true);
});

test('diag: cancel-all endpoint returns application/json', async ({ request }) => {
  test.skip(!process.env.PLAYGROUND_E2E_PROBE, 'set PLAYGROUND_E2E_PROBE=1');

  const cancelResp = await request.get('/?ssb_test_cancel_all=1', {
    headers: { Accept: 'application/json' },
  });
  console.log('probe cancel-all status', cancelResp.status());
  console.log('probe cancel-all content-type', cancelResp.headers()['content-type'] || 'none');
  const cancelText = await cancelResp.text();
  console.log('probe cancel-all body (first 400 chars):', cancelText.slice(0, 400));

  expect(cancelResp.status()).toBe(200);
  expect(cancelResp.headers()['content-type'] || '').toMatch(/application\/json/i);
  const cancelJson = JSON.parse(cancelText);
  expect(cancelJson.success).toBe(true);
});
