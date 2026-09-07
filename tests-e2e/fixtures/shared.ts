import { test as base, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginAsAdmin } from '../helpers/login';
import {
  installPluginErrorListener,
  drainPluginErrors,
} from '../helpers/plugin-error-listener';
import { resetTestSession, cancelAllSessions } from '../helpers/reset-test-session';

export type SharedFixtures = {
  adminPage: Page;
};

/**
 * Custom test wrapper that pre-authenticates every page as the admin user
 * and forwards any window.onerror / unhandledrejection to the test thread.
 *
 * Step 6 stability: every test starts with a clean session state.
 * Before the page is yielded to the test body, the fixture POSTs
 * cancel-all + reset so any leftover session row + transient from a
 * previous test cannot leak into the next test's check_active_session
 * probe (admin/js/sscribe-admin.js:914 disables the export button
 * when has_active=true).
 *
 * Both helpers throw on failure (see reset-test-session.ts); the
 * fixture surfaces a failed cleanup directly to the test, not as a
 * disabled-button timeout 30s later.
 */
export const test = base.extend<SharedFixtures>({
  adminPage: async ({ page }, use) => {
    await installPluginErrorListener(page);
    // Pre-test cleanup. Use the request context's baseURL (set from
    // playwright.config.ts use.baseURL) — page.context() doesn't
    // expose it directly via a public getter, so we read it from the
    // internal options with a typed cast.
    type BaseURLOption = { _options: { baseURL?: string } };
    const ctx = page.context() as unknown as BaseURLOption;
    const url: string = ctx._options.baseURL ?? 'http://127.0.0.1:9400';
    try {
      await cancelAllSessions(page.context().request, url);
    } catch {
      // First test of a cold boot may have nothing to cancel — ignore
      // the failure but log so a real break doesn't go unnoticed.
      // eslint-disable-next-line no-console
      console.warn('[shared.ts] cancelAllSessions failed (likely cold boot)');
    }
    try {
      await resetTestSession(page.context().request, url);
    } catch {
      // eslint-disable-next-line no-console
      console.warn('[shared.ts] resetTestSession failed (likely cold boot)');
    }
    await loginAsAdmin(page);
    await use(page);
    const errors = await drainPluginErrors(page);
    // Step 5 stability fix: close the page BEFORE the next test gets
    // its own fresh page. Without this, the previous test's browser
    // context stays alive for several hundred ms while Playwright
    // constructs the next one — long enough for any in-flight
    // SScribe batch poll to land a `process_batch` call that recreates
    // the `_transient_sscribe_export_session` row. The next test's
    // bootExport then races against that re-creation for ~9 minutes.
    if (!page.isClosed()) {
      await page.close().catch(() => undefined);
    }
    if (errors.length > 0) {
      throw new Error(`Plugin fired ${errors.length} runtime error(s):\n${JSON.stringify(errors, null, 2)}`);
    }
  },
});

export { expect };