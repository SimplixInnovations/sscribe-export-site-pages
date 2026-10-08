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

  // Get full text around "Notice:"
  const noticeContext = await page.evaluate(() => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const results = [];
    while (walker.nextNode()) {
      const text = walker.currentNode.textContent;
      if (text && (text.includes('Notice:') || text.includes('Warning:') || text.includes('Deprecated:'))) {
        let el = walker.currentNode.parentElement;
        results.push({ text: text.substring(0, 300), parentHtml: el?.outerHTML?.substring(0, 300) || '', parentClass: el?.className?.toString().substring(0, 60) || '' });
      }
    }
    return results;
  });
  noticeContext.forEach(n => console.log('NOTICE_CONTEXT: ' + JSON.stringify(n)));

  // Check focus-visible on plugin buttons
  await page.focus('.sscribe-btn-primary, .sscribe-tab-btn, button.sscribe-button');
  const fv = await page.evaluate(() => {
    const el = document.activeElement;
    const s = getComputedStyle(el);
    return { tag: el.tagName, class: (el.className||'').toString().substring(0,50), outline: s.outline, boxShadow: s.boxShadow, borderColor: s.borderColor };
  });
  console.log('FOCUS_VISIBLE: ' + JSON.stringify(fv));

  await browser.close();
}
main().catch(e => { console.error('FATAL: ' + e.message); process.exit(1); });
