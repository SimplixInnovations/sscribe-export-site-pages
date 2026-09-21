import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  globalSetup: './tests-e2e/globalSetup.ts',
  globalTeardown: './tests-e2e/globalTeardown.ts',
  testDir: './tests-e2e',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Release E2E is intentionally serialized: one native PHP CLI server,
  // one SQLite database, one browser worker. This removes concurrency as a
  // source of nondeterminism and keeps the exact-package gate reproducible.
  workers: 1,
  reporter: process.env.CI
    ? [['html', { open: 'never' }], ['junit', { outputFile: 'test-results/e2e.junit.xml' }]]
    : 'list',
  // Native PHP CLI server can be slow under contention; keep a generous
  // timeout to absorb the worst-case login wait.
  timeout: 360_000,
  expect: { timeout: 60_000 },
  use: {
    baseURL: process.env.SSCRIBE_E2E_BASE_URL ?? 'http://127.0.0.1:9400',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'e2e',
      testMatch: /tests-e2e\/(e2e\/.*|00-smoke)\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'a11y',
      testMatch: /tests-e2e\/a11y\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});