import type { Page } from '@playwright/test';

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.click('#wp-submit'),
  ]);
}