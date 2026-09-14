import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  globalSetup: './tests-e2e/globalSetup.ts',
  globalTeardown: './tests-e2e/globalTeardown.ts',
  testDir: './tests-e2e',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // WP-Playground's 6 WASM workers serialize PHP requests. Running too
  // many Playwright workers in parallel on top of that overloads the
  // workers and the PHP thread pool exhausts itself. Cap at 2 workers
  // (1 in interactive mode) so the test suite stays green rather than
  // hitting PHP hangs under contention.
  workers: process.env.CI ? 2 : 1,
  reporter: process.env.CI
    ? [['html', { open: 'never' }], ['junit', { outputFile: 'test-results/e2e.junit.xml' }]]
    : 'list',
  // WP-Playground's 6 WASM workers serialize PHP requests and the
  // mu-plugin cap-shim updates user_meta on every request. After 8+
  // tests, individual requests can take >60s — the test-level timeout
  // must therefore exceed the login helper's 300s ceiling. Bumped from
  // 60_000 → 360_000 to absorb the worst-case login wait before the test
  // fixture times out the worker.
  timeout: 360_000,
  expect: { timeout: 60_000 },
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