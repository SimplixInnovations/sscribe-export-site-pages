import { test, expect } from '../fixtures/shared';
import * as fs from 'fs';

const OUT = 'C:/Users/Ahmed/AppData/Local/Temp/kilo/ux';

test.describe('tmp spacing audit', () => {
  test.setTimeout(240_000);

  test('measure section titles and support snapshot spacing', async ({ adminPage }) => {
    fs.mkdirSync(OUT, { recursive: true });

    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
    await adminPage.waitForTimeout(800);

    const measures = await adminPage.evaluate(() => {
      const out: string[] = [];
      document
        .querySelectorAll<HTMLElement>('.sscribe-section-title, .sscribe-config-section-header')
        .forEach((title, i) => {
          const cs = getComputedStyle(title);
          const rect = title.getBoundingClientRect();
          const parent = title.nextElementSibling as HTMLElement | null;
          const nextRect = parent ? parent.getBoundingClientRect() : null;
          out.push(
            `title[${i}] "${title.textContent?.trim().slice(0, 24)}" marginBottom=${cs.marginBottom} gapToNext=${
              nextRect ? (nextRect.top - rect.bottom).toFixed(1) : 'N/A'
            }px`
          );
        });
      return out;
    });
    await adminPage.screenshot({ path: `${OUT}/v5-export-sections.png`, fullPage: true });

    await adminPage.locator('#sscribe-tab-btn-support').click();
    await adminPage.waitForTimeout(4000);
    const supportMeasure = await adminPage.evaluate(() => {
      const wrap = document.querySelector<HTMLElement>('.sscribe-support-copy-wrap');
      const text = document.querySelector<HTMLElement>('.sscribe-support-copy-text');
      const header = document.querySelector<HTMLElement>('.sscribe-support-panel-header');
      if (!wrap || !text || !header) return ['support nodes missing'];
      const cs = getComputedStyle(text);
      const w = wrap.getBoundingClientRect();
      const h = header.getBoundingClientRect();
      const t = text.getBoundingClientRect();
      return [
        `wrap padding-top=${getComputedStyle(wrap).paddingTop}`,
        `header->text gap=${(t.top - h.bottom).toFixed(1)}px`,
        `textarea radius=${cs.borderRadius} border=${cs.border}`,
      ];
    });
    await adminPage.screenshot({ path: `${OUT}/v5-support-spacing.png`, fullPage: true });

    fs.writeFileSync(`${OUT}/v5-measures.txt`, [...measures, ...supportMeasure].join('\n'), 'utf8');
  });
});
