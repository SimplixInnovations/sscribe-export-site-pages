import { test, expect } from './fixtures/shared';

test.describe('00-smoke — stack canary', () => {
  test('00-smoke: admin page renders + WP global present', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    // At least one format card visible.
    await expect(adminPage.locator('.sscribe-format-card').first()).toBeVisible();
    // wp global injected.
    const wpLoaded = await adminPage.evaluate(() => typeof (window as unknown as { wp?: unknown }).wp !== 'undefined');
    expect(wpLoaded).toBe(true);
  });
});
