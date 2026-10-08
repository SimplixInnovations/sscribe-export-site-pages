import { chromium } from '@playwright/test';

const BASE = 'http://127.0.0.1:9400';
const USER = 'audit';
const PASS = process.env.WP_PASS || 'wEQq9SJd5SOK';

const results = {
  login: false,
  adminPageLoad: null,
  firstPaint: null,
  contrast: { light: [], dark: [] },
  keyboard: [],
  responsive: [],
  icons: [],
  errors: [],
};

async function main() {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const page = await ctx.newPage();

  // Collect console errors
  page.on('console', msg => {
    if (msg.type() === 'error') results.errors.push(msg.text());
  });
  page.on('pageerror', err => results.errors.push(err.toString()));

  // 1. Login
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 15000 });
  results.login = true;
  console.log('LOGIN: OK');

  // 2. Load SScribe admin page + measure first paint
  const t0 = Date.now();
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'networkidle' });
  const t1 = Date.now();
  results.adminPageLoad = t1 - t0;
  console.log('ADMIN_PAGE_LOAD_MS: ' + results.adminPageLoad);

  // Check for SScribe content
  const hasContent = await page.locator('text=SScribe').first().isVisible().catch(() => false);
  console.log('SSCRIBE_VISIBLE: ' + hasContent);

  // 3. Contrast check (light scheme) - sample key elements
  async function sampleContrast(label) {
    const items = await page.evaluate(() => {
      function luminance(r, g, b) {
        const a = [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
      }
      function parseColor(s) {
        const m = s.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
        return m ? [parseInt(m[1]), parseInt(m[2]), parseInt(m[3])] : null;
      }
      function getBg(el) {
        while (el) {
          const bg = getComputedStyle(el).backgroundColor;
          const c = parseColor(bg);
          if (c && !bg.includes('rgba(0, 0, 0, 0)')) return c;
          el = el.parentElement;
        }
        return [255, 255, 255];
      }
      const out = [];
      const selectors = ['h1', 'h2', 'p', 'a', 'button', 'label', 'td', 'th', 'span.sscribe-badge', '.sscribe-btn-primary', '.sscribe-btn-secondary'];
      for (const sel of selectors) {
        const els = document.querySelectorAll(sel);
        for (const el of Array.from(els).slice(0, 3)) {
          const text = el.textContent?.trim();
          if (!text || text.length < 2) continue;
          const style = getComputedStyle(el);
          const fg = parseColor(style.color);
          const bg = getBg(el);
          if (!fg) continue;
          const l1 = luminance(...fg);
          const l2 = luminance(...bg);
          const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
          out.push({ selector: sel, text: text.substring(0, 40), ratio: Math.round(ratio * 100) / 100, fg: style.color, bg: `rgb(${bg.join(',')})`, pass: ratio >= 4.5 });
        }
      }
      return out;
    });
    results.contrast[label] = items.filter(i => !i.pass);
    console.log('CONTRAST_' + label.toUpperCase() + '_FAILS: ' + results.contrast[label].length);
    if (results.contrast[label].length > 0) {
      results.contrast[label].forEach(i => console.log('  FAIL: ' + i.selector + ' "' + i.text + '" ratio=' + i.ratio));
    }
  }

  await sampleContrast('light');

  // 4. Dark scheme
  await page.evaluate(() => { document.body.classList.add('wp-core-ui'); document.documentElement.setAttribute('data-color-scheme', 'dark'); });
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'networkidle' });
  // Set dark scheme via user meta
  await page.evaluate(() => {
    document.documentElement.classList.add('dark-scheme');
  });
  await sampleContrast('dark');

  // 5. Keyboard navigation
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'networkidle' });
  const tabOrder = [];
  for (let i = 0; i < 25; i++) {
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      return { tag: el?.tagName, text: el?.textContent?.trim()?.substring(0, 30), id: el?.id, ariaLabel: el?.getAttribute('aria-label') };
    });
    tabOrder.push(focused);
  }
  results.keyboard = tabOrder;
  console.log('KEYBOARD_TAB_COUNT: ' + tabOrder.filter(t => t.tag && t.tag !== 'BODY').length);
  const focusVisible = await page.evaluate(() => {
    const el = document.activeElement;
    const style = getComputedStyle(el);
    return { outline: style.outline, outlineWidth: style.outlineWidth, boxShadow: style.boxShadow };
  });
  console.log('FOCUS_STYLE: ' + JSON.stringify(focusVisible));

  // Escape key test on any dialog
  await page.keyboard.press('Escape');
  console.log('ESCAPE_KEY: dispatched');

  // 6. Responsive viewports
  const viewports = [360, 768, 1024, 1366, 1920];
  for (const w of viewports) {
    await page.setViewportSize({ width: w, height: 800 });
    await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'networkidle' });
    const metrics = await page.evaluate(() => {
      return {
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        hasHScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        overflowElements: Array.from(document.querySelectorAll('*')).filter(el => el.scrollWidth > el.clientWidth + 5).map(el => el.tagName + '.' + (el.className || '').toString().substring(0, 30)).slice(0, 5),
      };
    });
    results.responsive.push({ width: w, ...metrics });
    console.log('VIEWPORT_' + w + ': scrollW=' + metrics.scrollWidth + ' clientW=' + metrics.clientWidth + ' hScroll=' + metrics.hasHScroll + ' overflow=' + metrics.overflowElements.join(','));
  }

  // 7. Icon rendering - check for missing glyph boxes
  await page.setViewportSize({ width: 1366, height: 768 });
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'networkidle' });
  const iconIssues = await page.evaluate(() => {
    const issues = [];
    // Check for tofu/missing glyph characters
    const allText = document.body.innerText;
    const tofuChars = allText.match(/[\uFFFD\u25A1\u25A2\u25A3]/g);
    if (tofuChars) issues.push('tofu_chars: ' + tofuChars.length);
    // Check icon elements
    const iconEls = document.querySelectorAll('[class*="icon"], [class*="dashicon"], .sscribe-icon');
    issues.push('icon_elements: ' + iconEls.length);
    // Check for elements with zero width that should be visible
    let zeroSize = 0;
    iconEls.forEach(el => {
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) zeroSize++;
    });
    if (zeroSize > 0) issues.push('zero_size_icons: ' + zeroSize);
    return issues;
  });
  results.icons = iconIssues;
  console.log('ICON_ISSUES: ' + JSON.stringify(iconIssues));

  // 8. Timing - measure AJAX status counts call
  const t2 = Date.now();
  await page.evaluate(async () => {
    const nonce = window.sscribeAdmin?.nonce || window.sscribe_export_data?.nonce || '';
    if (nonce) {
      await fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=sscribe_get_status_counts&nonce=' + encodeURIComponent(nonce),
      });
    }
  });
  const t3 = Date.now();
  console.log('AJAX_STATUS_COUNTS_MS: ' + (t3 - t2));

  // 9. Check for PHP notices in page source
  const pageSource = await page.content();
  const notices = pageSource.match(/(Warning|Notice|Deprecated|Fatal error):/g);
  if (notices) {
    results.errors.push('PHP notices in page: ' + notices.join(', '));
    console.log('PHP_NOTICES: ' + notices.join(', '));
  } else {
    console.log('PHP_NOTICES: none');
  }

  console.log('ERRORS: ' + JSON.stringify(results.errors));
  await browser.close();
  console.log('AUDIT_COMPLETE');
}

main().catch(e => { console.error('FATAL: ' + e.message); process.exit(1); });
