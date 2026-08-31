import type { Page } from '@playwright/test';

/**
 * Forwards window.onerror + unhandledrejection events from the page to a
 * test-collectable array. The fixture (Task 2.6) drains the array on
 * afterEach and fails the test if any event fired during the run.
 */
export async function installPluginErrorListener(page: Page): Promise<void> {
  await page.addInitScript(() => {
    (window as unknown as { __sscribeErrors: unknown[] }).__sscribeErrors = [];
    window.addEventListener('error', (e) => {
      (window as unknown as { __sscribeErrors: unknown[] }).__sscribeErrors.push({
        kind: 'error',
        message: e.message,
        filename: e.filename,
        lineno: e.lineno,
      });
    });
    window.addEventListener('unhandledrejection', (e) => {
      (window as unknown as { __sscribeErrors: unknown[] }).__sscribeErrors.push({
        kind: 'unhandledrejection',
        reason: String(e.reason),
      });
    });
  });
}

export async function drainPluginErrors(page: Page): Promise<unknown[]> {
  return page.evaluate(
    () => ((window as unknown as { __sscribeErrors: unknown[] }).__sscribeErrors ?? []).splice(0)
  );
}