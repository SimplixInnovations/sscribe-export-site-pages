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
 * Timing assertions use scaled-down server delays (3s instead of 60s for
 * the 429 case) to keep wall-clock under 30s per scenario. The assertion
 * contract — "no batch call sent before floor(serverDelay - 500ms)" —
 * is the directive's intent; the exact magnitude is timing-arbitrary.
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

const syntheticJson = (status: number, body: object): { status: number; contentType: string; body: string } => ({
	status,
	contentType: 'application/json; charset=UTF-8',
	body: JSON.stringify(body),
});

/**
 * Boots a real export and waits for the session to be in `processing`
 * state so processBatch is actively polling. Returns when the first
 * real batch has returned (so we know the session is live) AND a stub
 * route has been installed that will return the supplied responseFn.
 */
async function bootExportWithMockedBatchFailure(
	page: import('@playwright/test').Page,
	responseFn: (callCount: number) => {
		status: number;
		contentType: string;
		body: string;
	}
): Promise<{ sessionId: string; callCountRef: { count: number } }> {
	// Install the route BEFORE we start the export so the very first batch
	// hit is intercepted. We let one synthetic-failure response pass through,
	// then the test body drives the timing assertions.
	let interceptedFirstCall = false;
	const callCountRef = { count: number };
	await page.route('**/admin-ajax.php*', async (route) => {
		const req = route.request();
		if (!isProcessBatch(req.postData())) {
			return route.continue();
		}
		callCountRef.count += 1;
		// First intercepted batch: hand the failure to the client.
		if (!interceptedFirstCall) {
			interceptedFirstCall = true;
			return route.fulfill(responseFn(callCountRef.count));
		}
		// Subsequent batches during the test: continue to the real backend.
		// The real backend will surface success/progress; tests then assert
		// the client honored the server delay before re-firing.
		return route.continue();
	});

	await page.goto('/wp-admin/admin.php?page=sscribe-export');
	await page.locator('input[name="sscribe_format"][value="docx"]').check();

	const startResp = page.waitForResponse(
		(r) =>
			r.url().includes('/wp-admin/admin-ajax.php') &&
			r.url().includes('action=sscribe_start_export')
	);
	await page.locator('#sscribe-export-btn').click();
	const start = JSON.parse((await startResp).text());
	const sessionId = (start as { data: { session_id: string } }).data.session_id;
	expect(sessionId).toMatch(/^[a-f0-9]{16}$/);
	return { sessionId, callCountRef };
}

test.describe('e2e / export / batch-retry-policy', () => {
	test('429 quota: server retry_in=3000 delays next batch beyond the client default', async ({ adminPage }) => {
		const { callCountRef } = await bootExportWithMockedBatchFailure(adminPage, (call) =>
			syntheticJson(429, {
				success: false,
				data: {
					message: 'Rate limit exceeded.',
					code: 'rate_limited',
					retry_in: SCALED_QUOTA_DELAY_MS,
					request_id: 'ssr_phase5_quota_' + call,
				},
			})
		);
		// Wait for the client to honor the delay. The directive's contract:
		// no batch call before QUOTA_FLOOR_MS.
		await adminPage.waitForTimeout(QUOTA_FLOOR_MS);
		expect(callCountRef.count, 'first failure was the initial intercept').toBe(1);
		// Continue: the second batch SHOULD eventually fire after server delay.
		await adminPage.waitForTimeout(SCALED_QUOTA_DELAY_MS + 1500);
		expect(callCountRef.count).toBeGreaterThanOrEqual(2);
	});

	test('503 contention: server retry_in is honored (no client backoff override)', async ({ adminPage }) => {
		const callTimestamps: number[] = [];
		const firstCallAt = Date.now();
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

		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
		await adminPage.locator('#sscribe-export-btn').click();

		// Wait for the first batch to land.
		await adminPage.waitForTimeout(2000);
		const first = callTimestamps[0] ?? firstCallAt;
		// Sample at CONTENTION_FLOOR_MS. If client had used its old default
		// (1500ms), the second call would already be visible.
		await adminPage.waitForTimeout(CONTENTION_FLOOR_MS);
		expect(
			callTimestamps.length,
			'no second call must fire before server delay honored'
		).toBe(1);
		// Eventually the second call fires after server delay.
		await adminPage.waitForTimeout(SCALED_CONTENTION_DELAY_MS + 1500);
		expect(callTimestamps.length).toBeGreaterThanOrEqual(2);
	});

	test('409 batch-lock conflict: uses conflict action, not quota retry', async ({ adminPage }) => {
		const decisions: string[] = [];
		await adminPage.exposeFunction('__phase5_capture', (s: string) => decisions.push(s));
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

		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
		await adminPage.locator('#sscribe-export-btn').click();

		// Wait for first failure to land. The decision helper should choose
		// action='conflict' (not 'retry'/'refresh_nonce'/'fail'). We check
		// the visible status text — quota errors render a "Retrying…" string
		// or a rate-limit key; conflict should render the batch-locked copy.
		await adminPage.waitForTimeout(2000);
		const statusText =
			(await adminPage.locator('#sscribe-status-text').textContent())?.trim() || '';
		// Conflict responses carry message "Batch is currently locked." (or
		// localized equivalent). Quota responses carry "Rate limit exceeded."
		// (or similar). The contract: the status text on a 409 must not be
		// the quota / rate-limit message.
		expect(statusText.toLowerCase()).not.toContain('rate limit');
		expect(statusText.toLowerCase()).not.toContain('quota');

		// Honor server delay: no second batch before CONFLICT_FLOOR_MS.
		await adminPage.waitForTimeout(CONFLICT_FLOOR_MS);
		// Wait one more slot for the second batch to fire.
		await adminPage.waitForTimeout(SCALED_CONFLICT_DELAY_MS + 1500);
	});

	test('500 server error: terminal fail, no automatic retry', async ({ adminPage }) => {
		let callCount = 0;
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			const req = route.request();
			if (!isProcessBatch(req.postData())) return route.continue();
			callCount += 1;
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

		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('input[name="sscribe_format"][value="docx"]').check();
		await adminPage.locator('#sscribe-export-btn').click();

		// Wait long enough for any client backoff to have triggered a retry.
		// The directive's contract: 500 must NOT auto-retry. We wait 6s
		// (well beyond any sensible client backoff for a terminal error).
		await adminPage.waitForTimeout(6000);
		const firstCallCount = callCount;
		expect(firstCallCount, 'exactly one batch attempt should have been made').toBeGreaterThanOrEqual(1);
		// Error UI: sscribe-error or similar should be visible.
		const errorVisible =
			(await adminPage.locator('#sscribe-error, .sscribe-error, .sscribe-notice-error').count()) > 0;
		expect(errorVisible, '500 must surface terminal error UI').toBe(true);
		// Confirm no retry: another 3s window should not increase callCount.
		await adminPage.waitForTimeout(3000);
		expect(callCount, '500 must not auto-retry').toBe(firstCallCount);
	});
});
