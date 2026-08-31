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
    if (errors.length > 0) {
      throw new Error(`Plugin fired ${errors.length} runtime error(s):\n${JSON.stringify(errors, null, 2)}`);
    }
  },
});

export { expect };