import { test, expect } from '../../fixtures/shared';

test.describe('e2e / export / wizard-happy-path', () => {
  test('full export wizard happy path: select → start → batch → finalize → download', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    // Wait until the export button reports enabled.
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });

    // Select the docx format.
    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });

    // Click Start Export.
    await adminPage.locator('#sscribe-export-btn').click();

    // Wait for completion.
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
    await expect(adminPage.locator('#sscribe-download-btn')).toHaveAttribute(
      'href',
      /[?&]token=[a-f0-9]{32}/,
      { timeout: 30_000 }
    );

    // Download.
    const downloadPromise = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const download = await downloadPromise;
    const path = await download.path();
    expect(path).not.toBeNull();
    expect(download.suggestedFilename()).toMatch(/-\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9-]+-[a-z0-9]{6}\.zip$/i);
  });
});
