import { test, expect } from '../fixtures/shared';
import type { Page } from '@playwright/test';
import { runAxe } from '../helpers/axe-rules';
import * as fs from 'fs';

/**
 * Theme audit — the WordPress.org review contract:
 *
 *   1. The plugin MATCHES the WordPress admin: when the admin goes dark
 *      (any color scheme / dark-mode plugin / OS flip the admin honors)
 *      the plugin follows via the measured admin background.
 *   2. ZERO dark-on-dark (or light-on-light) text: every visible text
 *      node and icon must clear WCAG AA against its effective surface.
 *   3. The plugin canvas is CONTIGUOUS with the admin background — it
 *      paints no slab of its own.
 *
 * Every screen and interactive state is walked in BOTH themes and the
 * results are written to dist/evidence/theme-audit.json as the review
 * evidence record.
 */

const OUT = 'C:/Users/Ahmed/AppData/Local/Temp/kilo/theme-audit';

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

interface ContrastViolation {
  screen: string;
  tag: string;
  cls: string;
  text: string;
  size: string;
  weight: string;
  fg: string;
  bg: string;
  ratio: string;
  threshold: number;
}

async function applyDarkAdmin(page: Page): Promise<void> {
  await page.addStyleTag({ content: DARK_ADMIN_FIXTURE });
  await page.evaluate(() => {
    document.body.classList.add('admin-color-midnight');
  });
  await page.waitForTimeout(400);
}

/**
 * Walk every visible element in the plugin's UI trees and report any
 * text or icon whose contrast against its effective background is below
 * the WCAG AA threshold for its size.
 */
async function walkContrast(page: Page, screen: string): Promise<ContrastViolation[]> {
  return page.evaluate((screenName: string) => {
    const parse = (value: string): [number, number, number, number] | null => {
      const v = String(value || '').trim();
      const m = v.match(/rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+%?))?\s*\)/i);
      if (m) {
        let a = 1;
        if (typeof m[4] === 'string') {
          a = m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
        }
        return [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]), isNaN(a) ? 1 : a];
      }
      const h = v.match(/^#([0-9a-f]{3,8})$/i);
      if (h) {
        let s = h[1];
        if (s.length === 3 || s.length === 4) {
          s = s.split('').map((c) => c + c).join('');
        }
        return [
          parseInt(s.slice(0, 2), 16),
          parseInt(s.slice(2, 4), 16),
          parseInt(s.slice(4, 6), 16),
          s.length >= 8 ? parseInt(s.slice(6, 8), 16) / 255 : 1,
        ];
      }
      return null;
    };
    const lum = (rgb: number[]): number => {
      const lin = rgb.slice(0, 3).map((c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
    };
    const ratio = (a: number[], b: number[]): number => {
      const la = lum(a);
      const lb = lum(b);
      return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };
    const blendOver = (fg: number[], bg: number[]): number[] => {
      const a = fg[3] >= 0 ? fg[3] : 1;
      return [0, 1, 2].map((i) => fg[i] * a + bg[i] * (1 - a));
    };
    const effectiveBg = (el: Element): number[] => {
      // Collect every non-transparent background up the tree, then
      // composite bottom-up over white — the only correct model for
      // translucent ancestors (overlay scrims, tinted surfaces).
      const stack: number[][] = [];
      let node: Element | null = el;
      while (node && node.nodeType === 1) {
        const bg = parse(getComputedStyle(node).backgroundColor);
        if (bg && bg[3] > 0) {
          stack.push(bg);
          if (bg[3] >= 1) {
            break;
          }
        }
        node = node.parentElement;
      }
      let acc: number[] = [255, 255, 255, 1];
      for (let i = stack.length - 1; i >= 0; i--) {
        acc = blendOver(stack[i], acc).concat([1]);
      }
      return acc.slice(0, 3);
    };

    const violations: any[] = [];
    const roots = Array.from(
      document.querySelectorAll('.sscribe-master-container, .sscribe-modal-content, .sscribe-toast, #sscribe-export-modal')
    );
    const seen = new Set<Element>();
    const visit = (el: Element) => {
      if (seen.has(el)) return;
      seen.add(el);
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return;
      if (el.closest('[aria-hidden="true"]')) return; // decorative — axe-exempt too
      if (el.closest('.screen-reader-text')) return; // AT-only, visually hidden by design
      const rect = el.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) return;

      const directText = Array.from(el.childNodes)
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => (n.textContent || '').trim())
        .join(' ')
        .trim();

      const bg = effectiveBg(el);
      const isSvg = el.tagName.toLowerCase() === 'svg';
      const hasText = directText.length > 0;

      if (hasText || isSvg) {
        const fg = parse(cs.color) || [0, 0, 0, 1];
        const fgBlend = fg[3] < 1 ? blendOver(fg, bg.concat([1])) : fg.slice(0, 3);
        const size = parseFloat(cs.fontSize);
        const weight = parseInt(cs.fontWeight, 10) || 400;
        const large = size >= 24 || (size >= 18.66 && weight >= 700);
        const threshold = isSvg ? 3.0 : large ? 3.0 : 4.5;
        const r = ratio(fgBlend, bg);
        if (r < threshold) {
          violations.push({
            screen: screenName,
            tag: el.tagName.toLowerCase(),
            cls: (el.getAttribute('class') || '').slice(0, 60),
            text: (directText || (isSvg ? '[svg]' : '')).slice(0, 40),
            size: cs.fontSize,
            weight: cs.fontWeight,
            fg: cs.color,
            bg: `rgb(${bg.map((c) => Math.round(c)).join(', ')})`,
            ratio: r.toFixed(2),
            threshold,
          });
        }
      }
      Array.from(el.children).forEach(visit);
    };
    roots.forEach(visit);
    return violations as ContrastViolation[];
  }, screen);
}

async function checkContiguity(page: Page): Promise<{ canvasBg: string; adminBg: string; contiguous: boolean }> {
  return page.evaluate(() => {
    const parseA = (v: string) => {
      const m = String(v).match(/rgba?\([^)]*\)/);
      return m ? m[0] : v;
    };
    const master = document.querySelector('.sscribe-master-container, #sscribe-tab-export');
    const admin = document.getElementById('wpbody-content');
    const canvasBg = master ? getComputedStyle(master).backgroundColor : 'none';
    const adminBg = admin ? getComputedStyle(admin).backgroundColor : 'none';
    const transparent = /rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)|transparent/.test(canvasBg);
    return {
      canvasBg: parseA(canvasBg),
      adminBg: parseA(adminBg),
      contiguous: transparent || canvasBg === adminBg,
    };
  });
}

async function walkScreen(page: Page, screen: string): Promise<ContrastViolation[]> {
  const v = await walkContrast(page, screen);
  await page.screenshot({ path: `${OUT}/${screen}.png`, fullPage: false });
  return v;
}

/**
 * Run axe without aborting the sweep: every violation is recorded so a
 * single run produces the complete defect inventory for both themes.
 */
async function safeAxe(
  page: Page,
  screen: string,
  includeSelector: string,
  sink: ContrastViolation[]
): Promise<void> {
  try {
    await runAxe(page, { includeSelectors: [includeSelector] });
  } catch (err) {
    sink.push({
      screen,
      tag: 'axe',
      cls: includeSelector,
      text: String(err instanceof Error ? err.message : err).slice(0, 2000),
      size: '-',
      weight: '-',
      fg: '-',
      bg: '-',
      ratio: '0.00',
      threshold: 4.5,
    });
  }
}

test.describe('a11y / theme contrast audit', () => {
  for (const ctx of ['light', 'dark'] as const) {
    test(`every plugin surface passes AA and matches the ${ctx} admin`, async ({ adminPage }) => {
      fs.mkdirSync(OUT, { recursive: true });
      const violations: ContrastViolation[] = [];
      const contiguity: any[] = [];

      await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
      await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });

      if (ctx === 'dark') {
        await applyDarkAdmin(adminPage);
      }
      await adminPage.waitForTimeout(400);

      // Theme-follow contract: the plugin must switch exactly with the admin.
      const isDarkClassed = await adminPage.evaluate(() =>
        document.documentElement.classList.contains('sscribe-dark')
      );
      expect(isDarkClassed).toBe(ctx === 'dark');

      // 1. Export tab (hero + stats).
      violations.push(...(await walkScreen(adminPage, `${ctx}-01-export-tab`)));
      contiguity.push({ screen: `${ctx}-01-export-tab`, ...(await checkContiguity(adminPage)) });
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-tab-export', violations);

      // 2. Modal config state.
      await adminPage.locator('#sscribe-open-export-modal-btn').click();
      await expect(adminPage.locator('#sscribe-export-modal')).toBeVisible();
      await adminPage.waitForTimeout(300);
      violations.push(...(await walkScreen(adminPage, `${ctx}-02-modal-config`)));
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-export-modal', violations);

      // 3. Real export → running + complete states (covers flourishes/buttons).
      await adminPage.locator('input[name="sscribe_format"][value="docx"]').check({ force: true });
      await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 60_000 });
      await adminPage.locator('#sscribe-export-btn').click();
      await adminPage.waitForTimeout(600);
      violations.push(...(await walkScreen(adminPage, `${ctx}-03-modal-running`)));

      await expect(adminPage.locator('#sscribe-download-area')).toBeVisible({ timeout: 240_000 });
      await adminPage.waitForTimeout(600);
      violations.push(...(await walkScreen(adminPage, `${ctx}-04-modal-complete`)));
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-export-modal', violations);
      await adminPage.locator('#sscribe-export-modal-close').click();

      // 4. History.
      await adminPage.locator('#sscribe-tab-btn-history').click();
      await adminPage.waitForTimeout(800);
      violations.push(...(await walkScreen(adminPage, `${ctx}-05-history`)));
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-tab-history', violations);

      // 5. Support (settled).
      await adminPage.locator('#sscribe-tab-btn-support').click();
      await adminPage.waitForTimeout(4500);
      violations.push(...(await walkScreen(adminPage, `${ctx}-06-support`)));
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-tab-support', violations);

      // 6. Debug console (settled).
      await adminPage.locator('#sscribe-tab-btn-debug').click();
      await adminPage.waitForTimeout(2500);
      violations.push(...(await walkScreen(adminPage, `${ctx}-07-debug`)));
      await safeAxe(adminPage, `${ctx}-axe`, '#sscribe-tab-debug', violations);

      // Record the evidence, then enforce.
      fs.writeFileSync(
        `${OUT}/report-${ctx}.json`,
        JSON.stringify({ context: ctx, violations, contiguity }, null, 2),
        'utf8'
      );
      const printable = violations
        .map((v) => `${v.screen} | ${v.tag}.${v.cls} | "${v.text}" | ${v.ratio} < ${v.threshold} | fg:${v.fg} bg:${v.bg}`)
        .join('\n');
      expect(violations, `Contrast violations in ${ctx} admin:\n${printable}`).toHaveLength(0);
      expect(
        contiguity.filter((c) => !c.contiguous),
        `Plugin canvas must be contiguous with the admin background: ${JSON.stringify(contiguity)}`
      ).toHaveLength(0);
    });
  }
});
