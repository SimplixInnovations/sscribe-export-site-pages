import { chromium } from '@playwright/test';

const BASE = 'http://127.0.0.1:9400';
const USER = 'audit';
const PASS = process.env.WP_PASS || 'wEQq9SJd5SOK';

async function main() {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
  page.on('pageerror', err => errors.push(err.toString()));

  // Login
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 15000 });
  console.log('LOGIN: OK');

  // Responsive viewports (use domcontentloaded instead of networkidle)
  for (const w of [360, 768, 1024, 1366, 1920]) {
    await page.setViewportSize({ width: w, height: 800 });
    await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2000);
    const m = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      hasHScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
      overflow: Array.from(document.querySelectorAll('.sscribe-form-card, .sscribe-card, .sscribe-btn, .sscribe-format-card, table')).filter(el => el.scrollWidth > el.clientWidth + 5).map(el => el.tagName + '.' + (el.className||'').toString().split(' ').slice(0,2).join('.')).slice(0, 5),
    }));
    console.log('VIEWPORT_' + w + ': scrollW=' + m.scrollWidth + ' clientW=' + m.clientWidth + ' hScroll=' + m.hasHScroll + ' overflow=[' + m.overflow.join('; ') + ']');
  }

  // Focus visibility test
  await page.setViewportSize({ width: 1366, height: 768 });
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2000);
  // Tab to a button and check focus ring
  for (let i = 0; i < 15; i++) await page.keyboard.press('Tab');
  const focus = await page.evaluate(() => {
    const el = document.activeElement;
    const s = getComputedStyle(el);
    return { tag: el.tagName, class: (el.className||'').toString().substring(0,40), outline: s.outline, outlineOffset: s.outlineOffset, boxShadow: s.boxShadow, border: s.border };
  });
  console.log('FOCUS_RING: ' + JSON.stringify(focus));

  // Icon rendering check
  const icons = await page.evaluate(() => {
    const els = document.querySelectorAll('[class*="icon"], [class*="dashicon"], .sscribe-icon, svg');
    let zero = 0, total = 0;
    els.forEach(el => { total++; const r = el.getBoundingClientRect(); if (r.width === 0 && r.height === 0) zero++; });
    const tofu = (document.body.innerText.match(/[\uFFFD\u25A1]/g) || []).length;
    return { total, zeroSize: zero, tofuChars: tofu };
  });
  console.log('ICONS: total=' + icons.total + ' zeroSize=' + icons.zeroSize + ' tofu=' + icons.tofuChars);

  // PHP notices check
  const src = await page.content();
  const notices = src.match(/(Warning|Notice|Deprecated|Fatal error):/g);
  console.log('PHP_NOTICES: ' + (notices ? notices.join(', ') : 'none'));

  // Timing: page load + AJAX
  const t0 = Date.now();
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'domcontentloaded', timeout: 30000 });
  const t1 = Date.now();
  console.log('PAGE_LOAD_MS: ' + (t1 - t0));

  // JS errors
  console.log('JS_ERRORS: ' + (errors.length ? errors.join(' | ') : 'none'));
  await browser.close();
  console.log('AUDIT2_COMPLETE');
}
main().catch(e => { console.error('FATAL: ' + e.message); process.exit(1); });
