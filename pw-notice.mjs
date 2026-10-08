import { chromium } from '@playwright/test';
const BASE = 'http://127.0.0.1:9400';
async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'audit');
  await page.fill('#user_pass', process.env.WP_PASS || 'wEQq9SJd5SOK');
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 15000 });
  await page.goto(BASE + '/wp-admin/admin.php?page=sscribe-export', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(3000);
  const src = await page.content();
  // Find all notices/warnings
  const matches = src.match(/(Notice|Warning|Deprecated|Fatal error|Parse error)[^<]{0,200}/g);
  if (matches) matches.forEach(m => console.log('FOUND: ' + m));
  else console.log('NO_PHP_NOTICES_IN_HTML');
  
  // Also check for elements with text containing Notice
  const noticeEls = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('*')).filter(el => {
      const t = el.textContent || '';
      return (t.includes('Notice:') || t.includes('Warning:') || t.includes('Deprecated:')) && el.children.length === 0;
    }).map(el => el.tagName + ': ' + el.textContent.substring(0, 200));
  });
  noticeEls.forEach(n => console.log('NOTICE_EL: ' + n));

  // Check zero-size icons
  const zeroIcons = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('[class*="icon"], [class*="dashicon"], .sscribe-icon, svg')).filter(el => {
      const r = el.getBoundingClientRect();
      return r.width === 0 && r.height === 0;
    }).map(el => ({ tag: el.tagName, class: (el.className?.baseVal || el.className || '').toString().substring(0, 60), parent: el.parentElement?.className?.toString().substring(0, 40) || '' })).slice(0, 15);
  });
  console.log('ZERO_ICONS_SAMPLE: ' + JSON.stringify(zeroIcons, null, 1));

  // Check plugin buttons focus style
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  const btnFocus = await page.evaluate(() => {
    const els = document.querySelectorAll('.sscribe-btn-primary, .sscribe-btn-secondary, button, .button');
    return Array.from(els).slice(0, 5).map(el => {
      const s = getComputedStyle(el);
      return { tag: el.tagName, class: (el.className||'').toString().substring(0, 40), outline: s.outline, boxShadow: s.boxShadow };
    });
  });
  console.log('BUTTON_STYLES: ' + JSON.stringify(btnFocus, null, 1));

  await browser.close();
}
main().catch(e => { console.error('FATAL: ' + e.message); process.exit(1); });
