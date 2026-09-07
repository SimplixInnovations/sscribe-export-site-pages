import type { Page } from '@playwright/test';

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

// WP-Playground's 6 WASM workers serialize PHP requests, and the mu-plugin
// shim runs `add_action('plugins_loaded', ...)` on every request to grant
// sscribe_export + manage_options caps. After 10+ tests the workers back up
// badly and a fresh login can take >180s on Windows (PHP compile-and-reload
// + 6-way contention). The login helper must outlast the worst case — the
// alternative (suite-failure on login timeout) is worse.
const LOGIN_NAV_TIMEOUT_MS = 300_000;

export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });

  // JS-driven fill. Chrome's autofill heuristics can swallow values
  // routed through locator click+fill (we observed #user_pass ending up
  // empty while #user_login received the literal "password"). Setting
  // the value directly through the DOM and dispatching an `input` event
  // bypasses the autofill UI and triggers WordPress's jQuery `input`
  // listener (which is what fires the actual form submit handler).
  await page.evaluate(
    ({ user, pass }) => {
      const userEl = document.getElementById('user_login') as HTMLInputElement | null;
      const passEl = document.getElementById('user_pass') as HTMLInputElement | null;
      if (userEl) {
        userEl.focus();
        userEl.value = user;
        userEl.dispatchEvent(new Event('input', { bubbles: true }));
        userEl.dispatchEvent(new Event('change', { bubbles: true }));
        userEl.blur();
      }
      if (passEl) {
        passEl.focus();
        passEl.value = pass;
        passEl.dispatchEvent(new Event('input', { bubbles: true }));
        passEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
      return {
        userOk: userEl?.value === user,
        passOk: passEl?.value === pass,
      };
    },
    { user: ADMIN_USER, pass: ADMIN_PASS }
  );

  // Wait for jQuery's login handler to attach the click + Promise.all
  // the navigation. WP-Playground's 6 WASM workers serialize PHP
  // requests so authentication can take >180s after suite contention.
  await Promise.all([
    page.waitForURL(/\/wp-admin\//, { timeout: LOGIN_NAV_TIMEOUT_MS }),
    page.locator('#wp-submit').click(),
  ]);
}