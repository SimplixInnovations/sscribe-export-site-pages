import { test as base, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginAsAdmin } from '../helpers/login';
import {
  installPluginErrorListener,
  drainPluginErrors,
} from '../helpers/plugin-error-listener';

export type SharedFixtures = {
  adminPage: Page;
};

/**
 * Custom test wrapper that pre-authenticates every page as the admin user
 * and forwards any window.onerror / unhandledrejection to the test thread.
 */
export const test = base.extend<SharedFixtures>({
  adminPage: async ({ page }, use) => {
    await installPluginErrorListener(page);
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