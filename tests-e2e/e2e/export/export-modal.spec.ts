import { test, expect } from '../../fixtures/shared';

/**
 * Contract for the export configuration modal:
 *
 *   1. While an export is running the modal is close-locked: the X button
 *      is inert (data-close-blocked="true"), Escape does nothing, and the
 *      backdrop does nothing. The ONLY sanctioned exit is Cancel Export.
 *   2. On completion the modal re-enables closing and renders the success
 *      flourish.
 *   3. Cancelling mid-run settles the modal back to its configuration
 *      state and releases it.
 */
test.describe('e2e / export / export-modal', () => {
  test('close is locked while running and released on completion', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-open-export-modal-btn').click();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();

    await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
    await adminPage.locator('#sscribe-export-btn').click();

    // Running: close affordance is inert and every close channel is blocked.
    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute(
      'data-close-blocked',
      'true',
      { timeout: 30_000 }
    );
    await adminPage.locator('#sscribe-export-modal-close').click({ force: true });
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();
    await adminPage.keyboard.press('Escape');
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();

    // Completion: flourish renders and closing reactivates.
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
    await expect(adminPage.locator('.sscribe-success-flourish')).toBeVisible();
    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute(
      'data-close-blocked',
      'false'
    );
    await adminPage.locator('#sscribe-export-modal-close').click();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeHidden();
  });

  test('cancel is the sanctioned exit while running', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-open-export-modal-btn').click();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();

    await adminPage.locator('input[name="sscribe_format"][value="all"]').check({ force: true });
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
    await adminPage.locator('#sscribe-export-btn').click();

    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute(
      'data-close-blocked',
      'true',
      { timeout: 30_000 }
    );
    await adminPage.locator('#sscribe-cancel-btn').click();
    await expect(adminPage.locator('#sscribe-confirm-modal')).toBeVisible({ timeout: 15_000 });
    await adminPage.locator('#sscribe-confirm-proceed').click();

    // Cancel settles the modal back to the configuration state and releases it.
    await expect(adminPage.locator('#sscribe-export-state-config')).toBeVisible({ timeout: 30_000 });
    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute(
      'data-close-blocked',
      'false'
    );
  });
});
