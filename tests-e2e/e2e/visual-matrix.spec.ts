import { test, expect } from '../fixtures/shared';
import type { Page } from '@playwright/test';
import * as fs from 'fs';

/**
 * Visual + responsive matrix: every screen in both themes at four
 * viewports, with a hard no-horizontal-overflow assertion at each size.
 */
const OUT = 'C:/Users/Ahmed/AppData/Local/Temp/kilo/visual-matrix';
const VIEWPORTS = [
  { name: '375', width: 375, height: 812 },
  { name: '768', width: 768, height: 1024 },
  { name: '1024', width: 1024, height: 768 },
  { name: '1440', width: 1440, height: 900 },
];

const DARK_ADMIN_FIXTURE = `
#wpadminbar, #adminmenumain, #adminmenumain .wp-submenu, #wpfooter { background: #1d2327; color: #f0f0f1; }
#adminmenumain .wp-menu-name, #adminmenumain a, #wpadminbar a { color: #f0f0f1; }
#wpbody-content, #wpcontent, .wrap { background: #1d2327; color: #f0f0f1; }
#wpbody-content input:not([type='checkbox']):not([type='radio']),
#wpbody-content select, #wpbody-content textarea {
  background: #2c3338; color: #f0f0f1; border-color: #50575e;
}
#wpbody-content .notice, #wpbody-content .postbox { background: #2c3338; color: #f0f0f1; }
`;

async function noHorizontalOverflow(page: Page, label: string, sink: string[]): Promise<void> {
  const overflow = await page.evaluate(() => {
    const doc = document.documentElement;
    return { scrollW: doc.scrollWidth, clientW: doc.clientWidth };
  });
  if (overflow.scrollW > overflow.clientW + 1) {
    sink.push(`${label}: horizontal overflow ${overflow.scrollW} > ${overflow.clientW}`);
  }
}

test.describe('e2e / visual matrix', () => {
  for (const ctx of ['light', 'dark'] as const) {
    for (const vp of VIEWPORTS) {
      test(`${ctx} admin @ ${vp.name}px — every screen`, async ({ adminPage }) => {
        fs.mkdirSync(OUT, { recursive: true });
        const issues: string[] = [];
        await adminPage.setViewportSize({ width: vp.width, height: vp.height });
        await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
        await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
        if (ctx === 'dark') {
          await adminPage.addStyleTag({ content: DARK_ADMIN_FIXTURE });
          await adminPage.evaluate(() => document.body.classList.add('admin-color-midnight'));
          await adminPage.waitForTimeout(300);
        }
        const isDark = await adminPage.evaluate(() => document.documentElement.classList.contains('sscribe-dark'));
        if (isDark !== (ctx === 'dark')) {
          issues.push(`theme-follow broken at ${vp.name}/${ctx}`);
        }

        const shot = async (name: string) => {
          await adminPage.screenshot({ path: `${OUT}/${ctx}-${vp.name}-${name}.png`, fullPage: false });
          await noHorizontalOverflow(adminPage, `${ctx}-${vp.name}-${name}`, issues);
        };

        await shot('export-tab');
        await adminPage.locator('#sscribe-open-export-modal-btn').click();
        await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();
        await adminPage.waitForTimeout(250);
        await shot('modal-config');
        await adminPage.locator('#sscribe-export-modal-close').click();

        await adminPage.locator('#sscribe-tab-btn-history').click();
        await adminPage.waitForTimeout(700);
        await shot('history');

        await adminPage.locator('#sscribe-tab-btn-support').click();
        await adminPage.waitForTimeout(3500);
        await shot('support');

        await adminPage.locator('#sscribe-tab-btn-debug').click();
        await adminPage.waitForTimeout(2200);
        await shot('debug');

        fs.appendFileSync(
          `${OUT}/visual-issues.txt`,
          `${ctx}-${vp.name}: ${issues.length ? issues.join(' | ') : 'OK'}\n`,
          'utf8'
        );
        expect(issues, `layout issues at ${ctx}/${vp.name}:\n${issues.join('\n')}`).toHaveLength(0);
      });
    }
  }
});
