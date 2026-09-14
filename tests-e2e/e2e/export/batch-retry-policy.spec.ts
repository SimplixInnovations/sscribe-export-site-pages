import { test, expect } from '../../fixtures/shared';

/**
 * Regression: processBatch() HTTP failures must route through the
 * centralized failure-decision helper (`getAjaxFailureDecision`) so that
 * the server's `retry_in` / `Retry-After` overrides the client's default
 * backoff. Without this routing:
 *   - 429 quota (retry_in=60s) hammers the server every ~1.5s.
 *   - 503 contention (retry_in=750ms) gets clamped to the 1500ms floor.
 *   - 409 batch-lock is mis-classified as quota.
 *   - 500 server error is retried forever instead of failing terminal.
 *
 * Background:
 *   - Source under test: admin/js/sscribe-admin.js `processBatch.error`
 *     callback (post-Phase 5 routing).
 *   - The decision helper is `getAjaxFailureDecision` (admin/js/sscribe-admin.js:4037).
 *   - `scheduleNextBatch(retryInMs, isRetry)` honors a positive `retryInMs`
 *     by using it verbatim (clamped to >=1500ms floor) and resetting
 *     `pollBackoff` to 0.
 *
 * Mock strategy:
 *   - page.route() intercepts every admin-ajax.php call.
 *   - We filter by request POST body for `action=sscribe_process_batch`.
 *   - Synthesized responses use the right HTTP status + retry_in so the
 *     helper's branches fire (429, 503, 409, 500).
 *
 * Timing strategy (event-driven, no wall-clock waits):
 *   - Each test races a `page.waitForRequest('action=sscribe_process_batch')`
 *     against `setTimeout(floor_ms)`. If the race resolves on the
 *     timeout, the contract holds (no second call before floor).
 *   - After the floor-race, a bounded `expect.poll()` waits for the
 *     second call to actually fire after the server delay.
 *   - Eliminates arbitrary sleep drift that varies with WASM speed.
 */

const SCALED_QUOTA_DELAY_MS = 3000;
const QUOTA_FLOOR_MS = SCALED_QUOTA_DELAY_MS - 500; // call must not fire before this
const SCALED_CONTENTION_DELAY_MS = 2000;
const CONTENTION_FLOOR_MS = SCALED_CONTENTION_DELAY_MS - 500;
const SCALED_CONFLICT_DELAY_MS = 1500;
const CONFLICT_FLOOR_MS = SCALED_CONFLICT_DELAY_MS - 500;

const isProcessBatch = (postData: string | null | undefined): boolean =>
  typeof postData === 'string' &&
  postData.includes('action=sscribe_process_batch');

type TableJson = {
  success?: boolean;
  data?: { has_active?: boolean; [k: string]: unknown };
  [k: string]: unknown;
};

const syntheticJson = (status: number, body: object): { status: number; contentType: string; body: string } => ({
  status,
  contentType: 'application/json; charset=UTF-8',
  body: JSON.stringify(body),
});

/**
 * Wait for the next process-batch request OR a timeout — whichever
 * fires first. Returns { fired: boolean, elapsedMs }.
 *
 * Implementation: race `page.waitForRequest` against `setTimeout`.
 * `waitForRequest` resolves with the Request object (the request has
 * been sent by the time it resolves). The setTimeout fires if no
 * second request arrives within `floorMs`. The race winner is
 * deterministic for the same network ordering.
 */
async function raceFloor(
  page: import('@playwright/test').Page,
  floorMs: number
): Promise<{ fired: boolean; elapsedMs: number }> {
  const t0 = Date.now();
  let fired = false;
  let resolvedAt = floorMs;
  await Promise.race([
    page
      .waitForRequest((req) => {
        if (!req.url().includes('admin-ajax.php')) return false;
        return isProcessBatch(req.postData());
      }, { timeout: floorMs })
      .then(() => {
        fired = true;
        resolvedAt = Date.now() - t0;
      })
      .catch(() => {
        // timeout from waitForRequest — leave fired=false, resolvedAt=floorMs
      }),
    new Promise<void>((resolve) => setTimeout(resolve, floorMs)),
  ]);
  return { fired, elapsedMs: resolvedAt };
}

async function bootExport(
  adminPage: import('@playwright/test').Page
): Promise<string> {
  // The shared fixture (tests-e2e/fixtures/shared.ts) already runs
  // cancel-all + reset before yielding the adminPage to the test,
  // so bootExport doesn't repeat that work. It still installs a
  // persistent response listener BEFORE goto to capture
  // check_active_session and get_status_counts, in case the page's
  // init() fires both AJAX in a window that waitForResponse would
  // miss if registered AFTER goto.
  let lastActive: { hasActive: boolean; ts: number } | null = null;
  let countsSeenAt: number | null = null;
  const installedAt = Date.now();
  const responseHandler = (resp: import('@playwright/test').Response): void => {
    if (resp.request().url().includes('admin-ajax.php') && resp.status() < 500) {
      const trace = process.env.SS_DIAG_RETRY;
      void resp.text().then((text) => {
        try {
          const body = JSON.parse(text) as TableJson;
          const pd = resp.request().postData() || '';
          if (pd.includes('action=sscribe_check_active_session')) {
            if (body.success === true && typeof body.data?.has_active === 'boolean') {
              lastActive = {
                hasActive: body.data.has_active !== false,
                ts: Date.now(),
              };
              if (trace) console.log(`diag: handler check_active_session has_active=${lastActive.hasActive} ts=${lastActive.ts}`);
            } else if (trace) {
              console.log(`diag: handler check_active_session non-success status=${resp.status()} body=${JSON.stringify(body).slice(0, 200)}`);
            }
          } else if (pd.includes('action=sscribe_get_status_counts')) {
            if (body.success === true) {
              countsSeenAt = Date.now();
              if (trace) console.log(`diag: handler get_status_counts ts=${countsSeenAt}`);
            } else if (trace) {
              console.log(`diag: handler get_status_counts non-success status=${resp.status()}`);
            }
          }
        } catch {
          // ignore parse errors
        }
      }).catch(() => undefined);
    }
  };
  adminPage.on('response', responseHandler);

  await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

  // Wait for check_active_session to land with has_active=false.
  for (let attempt = 0; attempt < 30; attempt += 1) {
    let foundClean = false;
    for (let waited = 0; waited < 30 && !foundClean; waited += 1) {
      await adminPage.waitForTimeout(500);
      if (lastActive && lastActive.ts >= installedAt && !lastActive.hasActive) {
        foundClean = true;
      }
    }
    if (process.env.SS_DIAG_RETRY) {
      console.log(`diag: attempt=${attempt} foundClean=${foundClean} lastActive=${JSON.stringify(lastActive)} countsSeenAt=${countsSeenAt} installedAt=${installedAt}`);
    }
    if (foundClean) break;
    if (attempt === 29) {
      adminPage.off('response', responseHandler);
      throw new Error(
        `bootExport: has_active still true after ${attempt + 1} reset attempts ` +
        `(lastActive=${JSON.stringify(lastActive)})`
      );
    }
    // Re-run cleanup and reload to get a fresh check_active_session probe.
    await adminPage.context().request.get('/?ssb_test_cancel_all=1');
    await adminPage.context().request.get('/?ssb_test_reset=1');
    await adminPage.waitForTimeout(500);
    await adminPage.reload();
  }

  // Wait for counts AJAX so the export button enables.
  for (let waited = 0; waited < 60 && countsSeenAt === null; waited += 1) {
    await adminPage.waitForTimeout(1000);
  }
  if (process.env.SS_DIAG_RETRY) {
    console.log(`diag: countsSeenAt=${countsSeenAt} (after loop)`);
  }
  if (countsSeenAt === null) {
    adminPage.off('response', responseHandler);
    throw new Error('bootExport: sscribe_get_status_counts did not fire within 60s after goto');
  }
  adminPage.off('response', responseHandler);
  await expect(adminPage.locator('#sscribe-export-btn')).toBeEnabled({ timeout: 30_000 });

  await adminPage
    .locator('input[name="sscribe_format"][value="docx"]')
    .check({ force: true });
  await adminPage.locator('#sscribe-export-btn').click();
  // Wait for the progress area to appear — proves start_export succeeded
  // and the batch loop has started.
  await expect(adminPage.locator('#sscribe-progress-area')).toBeVisible({ timeout: 30_000 });
  return 'native-runtime';
}

test.describe('e2e / export / batch-retry-policy', () => {
  test('429 quota: server retry_in=3000 delays next batch beyond the client default', async ({ adminPage }) => {
    let firstIntercepted = false;
    let count = 0;
    await adminPage.route('**/admin-ajax.php*', async (route) => {
      const req = route.request();
      if (!isProcessBatch(req.postData())) return route.continue();
      count += 1;
      // Intercept only the FIRST batch. After that, let real backend
      // take over so the timing assertion below can observe the second
      // call firing after the server delay.
      if (!firstIntercepted) {
        firstIntercepted = true;
        return route.fulfill(
          syntheticJson(429, {
            success: false,
            data: {
              message: 'Rate limit exceeded.',
              code: 'rate_limited',
              retry_in: SCALED_QUOTA_DELAY_MS,
              request_id: 'ssr_phase5_quota_' + count,
            },
          })
        );
      }
      return route.continue();
    });

    await bootExport(adminPage);

    // Race the next batch call against the floor. If it doesn't fire
    // before QUOTA_FLOOR_MS, the contract holds.
    const race = await raceFloor(adminPage, QUOTA_FLOOR_MS);
    expect(race.fired, 'no second batch must fire before server delay honored').toBe(false);
    expect(count, 'first failure was the initial intercept').toBe(1);

    // Continue: the second batch SHOULD eventually fire after server delay.
    await expect
      .poll(
        async () => count,
        { timeout: SCALED_QUOTA_DELAY_MS + 5_000, intervals: [500] }
      )
      .toBeGreaterThanOrEqual(2);
  });

  test('503 contention: server retry_in is honored (no client backoff override)', async ({ adminPage }) => {
    const callTimestamps: number[] = [];
    await adminPage.route('**/admin-ajax.php*', async (route) => {
      const req = route.request();
      if (!isProcessBatch(req.postData())) return route.continue();
      callTimestamps.push(Date.now());
      return route.fulfill(
        syntheticJson(503, {
          success: false,
          data: {
            message: 'Limiter contention.',
            code: 'rate_limiter_busy',
            retry_in: SCALED_CONTENTION_DELAY_MS,
            request_id: 'ssr_phase5_contention',
          },
        })
      );
    });

    await bootExport(adminPage);

    // Wait for the FIRST batch to land (event-driven, not wall-clock).
    await expect
      .poll(() => callTimestamps.length, { timeout: 30_000, intervals: [250] })
      .toBeGreaterThanOrEqual(1);
    const firstCallAt = callTimestamps[0];

    // Race: no second call before CONTENTION_FLOOR_MS.
    const race = await raceFloor(adminPage, CONTENTION_FLOOR_MS);
    expect(
      race.fired,
      'no second call must fire before server delay honored'
    ).toBe(false);
    expect(callTimestamps.length).toBe(1);
    // Sanity: the floor elapsed (the timeout arm won, so elapsedMs == floorMs).
    expect(race.elapsedMs).toBeGreaterThanOrEqual(CONTENTION_FLOOR_MS - 100);
    expect(Date.now() - firstCallAt).toBeGreaterThanOrEqual(CONTENTION_FLOOR_MS - 100);

    // Eventually the second call fires after server delay.
    await expect
      .poll(() => callTimestamps.length, { timeout: SCALED_CONTENTION_DELAY_MS + 5_000, intervals: [500] })
      .toBeGreaterThanOrEqual(2);
  });

  test('409 batch-lock conflict: uses conflict action, not quota retry', async ({ adminPage }) => {
    await adminPage.route('**/admin-ajax.php*', async (route) => {
      const req = route.request();
      if (!isProcessBatch(req.postData())) return route.continue();
      return route.fulfill(
        syntheticJson(409, {
          success: false,
          data: {
            message: 'Batch is currently locked.',
            code: 'batch_locked',
            retry_in: SCALED_CONFLICT_DELAY_MS,
            request_id: 'ssr_phase5_lock',
          },
        })
      );
    });

    await bootExport(adminPage);

    // Wait for the first failure to land (event-driven — the status
    // text is updated after the AJAX failure response is processed).
    const statusText = adminPage.locator('#sscribe-status-text');
    await expect
      .poll(
        async () => {
          const t = (await statusText.textContent())?.trim() || '';
          return t.toLowerCase();
        },
        { timeout: 30_000, intervals: [250] }
      )
      .toMatch(/batch.*lock|locked/i);

    // Conflict responses carry message "Batch is currently locked." (or
    // localized equivalent). Quota responses carry "Rate limit exceeded."
    // (or similar). The contract: the status text on a 409 must not be
    // the quota / rate-limit message.
    const t = ((await statusText.textContent()) || '').toLowerCase();
    expect(t).not.toContain('rate limit');
    expect(t).not.toContain('quota');
  });

  test('500 server error: terminal fail, no automatic retry', async ({ adminPage }) => {
    const callTimes: number[] = [];
    await adminPage.route('**/admin-ajax.php*', async (route) => {
      const req = route.request();
      if (!isProcessBatch(req.postData())) return route.continue();
      callTimes.push(Date.now());
      return route.fulfill(
        syntheticJson(500, {
          success: false,
          data: {
            message: 'Internal server error.',
            code: 'server_error',
            request_id: 'ssr_phase5_500',
          },
        })
      );
    });

    await bootExport(adminPage);

    // Wait for the FIRST batch attempt (event-driven).
    await expect
      .poll(() => callTimes.length, { timeout: 30_000, intervals: [250] })
      .toBeGreaterThanOrEqual(1);
    const firstCallCount = callTimes.length;

    // Error UI must surface. The directive's contract: 500 must NOT
    // auto-retry. We poll for the error element appearing (event-driven)
    // rather than sleeping 6 seconds. The error area is rendered at
    // `#sscribe-error-area` in admin/partials/sscribe-admin-display.php:798
    // with classes `sscribe-status-alert sscribe-status-error`.
    await expect(
      adminPage.locator('#sscribe-error-area, .sscribe-status-error').first()
    ).toBeVisible({ timeout: 15_000 });

    // Confirm no retry: monitor for additional calls during a bounded
    // short interval. If any retry fires, callTimes.length grows.
    const countAfterError = callTimes.length;
    await expect
      .poll(() => callTimes.length, { timeout: 5_000, intervals: [500] })
      .toBe(countAfterError);
    expect(callTimes.length, '500 must not auto-retry').toBe(firstCallCount);
  });
});
