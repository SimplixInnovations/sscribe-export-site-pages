import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  globalSetup: './tests-e2e/globalSetup.ts',
  testDir: './tests-e2e',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 4 : undefined,
  reporter: process.env.CI
    ? [['html', { open: 'never' }], ['junit', { outputFile: 'test-results/e2e.junit.xml' }]]
    : 'list',
  timeout: 60_000,
  expect: { timeout: 30_000 },
  use: {
    baseURL: process.env.PLAYGROUND_BASE_URL ?? 'http://127.0.0.1:9400',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'e2e',
      testMatch: /tests-e2e\/(e2e|00-smoke)\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'a11y',
      testMatch: /tests-e2e\/a11y\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'perf',
      testMatch: /tests-e2e\/perf\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});