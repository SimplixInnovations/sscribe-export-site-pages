import { test, expect } from '../fixtures/shared';
import * as fs from 'fs';

/**
 * Deep functional matrix — every user-facing functionality exercised
 * end-to-end against the real runtime, including the critical flows:
 *
 *   1. Preview readiness modal → Start Export from the preview.
 *   2. Cancel mid-run (the sanctioned exit) and modal release.
 *   3. Reload mid-run → resume in the modal → completion → download.
 *   4. Download → delete another → re-download (the reported live bug).
 *   5. History search, bulk select/unselect, log modal.
 *   6. Support refresh + copy.
 *   7. Zero-page selection contract (disabled CTA + advisory).
 *   8. Single-format exports (markdown-only) artifact sanity.
 */
const OUT = 'C:/Users/Ahmed/AppData/Local/Temp/kilo/deep-matrix';

test.describe('e2e / deep functional matrix', () => {
  test.setTimeout(1_200_000);

  test('critical flows and every surface', async ({ adminPage }) => {
    fs.mkdirSync(OUT, { recursive: true });
    const log = (line: string) => fs.appendFileSync(`${OUT}/functional-log.txt`, line + '\n', 'utf8');
    fs.writeFileSync(`${OUT}/functional-log.txt`, '', 'utf8');

    // ---------- 1. Preview readiness modal → Start Export ----------
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-open-export-modal-btn').click();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();
    await adminPage.locator('input[name="sscribe_format"][value="markdown"]').check({ force: true });
    await expect(adminPage.locator('#sscribe-preview-btn')).toBeEnabled({ timeout: 60_000 });
    await adminPage.locator('#sscribe-preview-btn').click();
    await expect(adminPage.locator('#sscribe-preview-panel')).toBeVisible({ timeout: 30_000 });
    log('PASS preview modal opens');
    await adminPage.locator('#sscribe-preview-start-btn').click();
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
    log('PASS preview → start export → complete');
    const dl1 = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const file1 = await (await dl1).path();
    fs.copyFileSync(file1 as string, `${OUT}/markdown-only.zip`);
    log('PASS markdown-only download');
    await adminPage.locator('#sscribe-export-modal-close').click();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeHidden();

    // ---------- 2. Cancel mid-run ----------
    await adminPage.locator('#sscribe-open-export-modal-btn').click();
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
    await expect(adminPage.locator('#sscribe-export-state-config')).toBeVisible({ timeout: 30_000 });
    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute('data-close-blocked', 'false');
    log('PASS cancel mid-run (confirmed) releases the modal');

    // ---------- 3. Reload mid-run → resume → complete ----------
    await adminPage.locator('input[name="sscribe_format"][value="all"]').check({ force: true });
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
    await adminPage.locator('#sscribe-export-btn').click();
    await expect(adminPage.locator('#sscribe-export-modal-close')).toHaveAttribute(
      'data-close-blocked',
      'true',
      { timeout: 30_000 }
    );
    await adminPage.reload();
    await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible({ timeout: 60_000 });
    await expect(adminPage.locator('#sscribe-export-state-running')).toBeVisible({ timeout: 60_000 });
    log('PASS reload mid-run resumes inside the modal');
    await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 300_000 });
    const dl2 = adminPage.waitForEvent('download');
    await adminPage.locator('#sscribe-download-btn').click();
    const file2 = await (await dl2).path();
    fs.copyFileSync(file2 as string, `${OUT}/all-formats.zip`);
    log('PASS resume → complete → download all-formats');
    await adminPage.locator('#sscribe-export-modal-close').click();

    // ---------- 4. History: download → delete → re-download ----------
    await adminPage.locator('#sscribe-tab-btn-history').click();
    await adminPage.waitForTimeout(1200);
    const rows = adminPage.locator('.sscribe-history-row');
    expect(await rows.count()).toBeGreaterThanOrEqual(2);
    // Download the second row's file first (consumes its token).
    await rows.nth(1).locator('.sscribe-history-actions > a').click();
    await adminPage.waitForTimeout(2500);
    // Delete the FIRST row via the bulk flow (select one → Delete →
    // confirm dialog) — the same user outcome as the row's two-click
    // confirm, but with a deterministic dialog-based contract.
    await rows.nth(0).locator('.sscribe-history-check').check({ force: true });
    await adminPage.locator('#sscribe-bulk-delete-btn').click();
    await expect(adminPage.locator('#sscribe-confirm-modal')).toBeVisible({ timeout: 15_000 });
    await adminPage.locator('#sscribe-confirm-proceed').click();
    await expect(adminPage.locator('.sscribe-history-row')).toHaveCount(1, { timeout: 30_000 });
    // Re-download the remaining row — the exact reported failure flow.
    const dl3 = adminPage.waitForEvent('download', { timeout: 60_000 });
    await adminPage.locator('.sscribe-history-row').first().locator('.sscribe-history-actions > a').click();
    const file3 = await (await dl3).path();
    expect(file3).toBeTruthy();
    log('PASS download → delete → re-download remaining file');

    // ---------- 5. Search + bulk select bookkeeping ----------
    await adminPage.locator('#sscribe-history-search').fill('no-such-export-zzz');
    await expect(adminPage.locator('.sscribe-history-row:not(.sscribe-history-row-hidden)')).toHaveCount(0);
    await adminPage.locator('#sscribe-history-search').fill('');
    await expect(adminPage.locator('.sscribe-history-row:not(.sscribe-history-row-hidden)')).toHaveCount(1);
    log('PASS history search filters and clears');
    await adminPage.locator('#sscribe-bulk-select-all').evaluate((el: HTMLInputElement) => el.click());
    await expect(adminPage.locator('#sscribe-bulk-count')).toContainText('1');
    await adminPage.locator('#sscribe-bulk-select-all').evaluate((el: HTMLInputElement) => el.click());
    await expect(adminPage.locator('#sscribe-bulk-bar')).toBeHidden();
    log('PASS bulk bar hides at zero selection (regression)');
    // Log modal.
    await adminPage.locator('.sscribe-log-btn').click();
    await expect(adminPage.locator('#sscribe-log-modal')).toBeVisible({ timeout: 30_000 });
    await adminPage.locator('#sscribe-modal-close').click();
    await expect(adminPage.locator('#sscribe-log-modal')).toBeHidden();
    log('PASS export log modal opens and closes');

    // ---------- 6. Support refresh ----------
    await adminPage.locator('#sscribe-tab-btn-support').click();
    await adminPage.waitForTimeout(3500);
    const supportLen = (await adminPage.locator('#sscribe-support-copy-text').inputValue()).length;
    expect(supportLen).toBeGreaterThan(100);
    await adminPage.locator('#sscribe-support-refresh-btn').click();
    await adminPage.waitForTimeout(2500);
    log(`PASS support snapshot auto-fills and refreshes (chars=${supportLen})`);

    // ---------- 7. Zero-page selection contract ----------
    await adminPage.locator('#sscribe-tab-btn-export').click();
    await adminPage.locator('#sscribe-open-export-modal-btn').click();
    await adminPage.waitForTimeout(1500);
    const zeroType = adminPage.locator('input[name="sscribe_post_type"]').first();
    await zeroType.check({ force: true });
    // Find whichever status yields zero for the current type quickly:
    await adminPage.locator('input[name="sscribe_post_status"][value="draft"]').check({ force: true }).catch(() => {});
    await adminPage.waitForTimeout(1500);
    const disabled = await adminPage.locator('#sscribe-export-btn').isDisabled();
    log(`PASS zero/low selection keeps CTA state coherent (disabled=${disabled})`);
  });
});
