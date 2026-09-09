import { test, expect } from '../../fixtures/shared';

/**
 * Regression: debug console's enable/disable toggle must
 *   (a) flip its aria-checked state and persist the option via AJAX,
 *   (b) on server-side rejection of the save, roll back to the captured
 *       pre-toggle state (Phase 10: rollbackDebugControls), and
 *   (c) when debug is OFF and the log is empty, render the
 *       'debug_disabled' empty-state copy — not the generic
 *       'no log entries found.' (Phase 11: empty-state taxonomy).
 *
 * Background:
 *   - Source under test: admin/js/sscribe-debug-console.js
 *     bindEvents (capture previous state on change),
 *     saveSettings (rollback on terminal failure),
 *     renderLogs (5-state taxonomy).
 *   - AJAX endpoint: sscribe_debug_save_settings
 *     (POSTs debug_enabled + log_level + auto_refresh).
 *
 * Mock strategy:
 *   page.route() intercepts sscribe_debug_save_settings and returns
 *   controlled success/failure responses. The toggle's
 *   rollbackDebugControls path is exercised by sending success=false
 *   after a toggle flip; the rollback state-transition path is
 *   exercised by sending a 500.
 */

const isDebugSaveSettings = (postData: string | null | undefined): boolean =>
	typeof postData === 'string' &&
	postData.includes('action=sscribe_debug_save_settings');

const syntheticJson = (status: number, body: object): { status: number; contentType: string; body: string } => ({
	status,
	contentType: 'application/json; charset=UTF-8',
	body: JSON.stringify(body),
});

test.describe('e2e / debug / toggle-state-transition', () => {
	test('toggle flip persists and survives server-side rejection (rollback to captured previous state)', async ({
		adminPage,
	}) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('#sscribe-tab-btn-debug').click();
		const toggle = adminPage.locator('#sscribe-debug-enabled[role="switch"]');
		await expect(toggle).toBeAttached();

		const initialAria = await toggle.getAttribute('aria-checked');
		expect(initialAria === 'true' || initialAria === 'false').toBe(true);
		const initialState = initialAria === 'true';

		// Install a route that always returns success=false to simulate
		// a server-side rejection (e.g. log rotation in progress).
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			const req = route.request();
			if (!isDebugSaveSettings(req.postData())) return route.continue();
			return route.fulfill(
				syntheticJson(200, {
					success: false,
					data: { message: 'Save rejected: log rotation in progress.' },
				})
			);
		});

		// Flip the toggle (visually). The handler fires saveSettings →
		// server returns success=false → rollbackDebugControls snaps the
		// checkbox back to the captured pre-toggle state. Use `force: true`
		// to bypass the `.sscribe-toggle-slider` span that overlays the
		// hidden checkbox input and intercepts pointer events.
		await toggle.click({ force: true });

		// Wait for the rollback to take effect. The toggle's aria-checked
		// must equal the initial state (rollback target).
		await expect(toggle).toHaveAttribute('aria-checked', String(initialState));

		// And the save feedback should display the server's message.
		const feedback = adminPage.locator('#sscribe-debug-save-feedback');
		await expect(feedback).toContainText(/log rotation in progress/i);
	});

	test('toggle flip rolls back on 500 (hard HTTP failure)', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('#sscribe-tab-btn-debug').click();
		const toggle = adminPage.locator('#sscribe-debug-enabled[role="switch"]');
		await expect(toggle).toBeAttached();

		const initialAria = await toggle.getAttribute('aria-checked');
		expect(initialAria === 'true' || initialAria === 'false').toBe(true);
		const initialState = initialAria === 'true';

		// Simulate hard HTTP failure (network timeout or 5xx).
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			const req = route.request();
			if (!isDebugSaveSettings(req.postData())) return route.continue();
			return route.fulfill(
				syntheticJson(500, {
					success: false,
					data: { message: 'Internal server error.' },
				})
			);
		});

		await toggle.click({ force: true });

		// Rollback path: checkbox back to initial state, error feedback
		// visible with HTTP 500 code.
		await expect(toggle).toHaveAttribute('aria-checked', String(initialState));
		const feedback = adminPage.locator('#sscribe-debug-save-feedback');
		await expect(feedback).toContainText(/error/i);
	});

	test('empty-state taxonomy renders debug_disabled copy when debug is OFF', async ({ adminPage }) => {
		// Deterministic pre-condition: the mu-plugin bootstrap
		// (`tests-e2e/fixtures/mu-plugins/00-sscribe-test-bootstrap.php`)
		// seeds `sscribe_debug_enabled=false` on the first request, so
		// the toggle reads aria-checked="false" without any prior flip.
		// No conditional skip — the test is now authoritative.
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

		// Install the fetch_logs mock BEFORE clicking the debug tab so
		// the tab-init fetchLogs() call gets our synthetic response.
		//
		// Why mock (not call the real AJAX): the WP-Playground worker
		// accumulates rate-limiter micro-locks across the 5 sequential
		// tests in this spec. When the rate limiter returns 429 for
		// fetch_logs, the JS fail handler renders the error via
		// showConsoleError() which HIDES #sscribe-debug-empty
		// (admin/js/sscribe-debug-console.js:637-649). The test then
		// sees `#sscribe-debug-empty p` as hidden and fails — even though
		// the empty-state taxonomy itself is correct. Mocking the AJAX
		// removes the rate-limiter dependency and exercises the
		// taxonomy path directly. This mirrors the strategy used by the
		// rollback tests (tests 1, 2, 4) for sscribe_debug_save_settings.
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			const req = route.request();
			try {
				const pd = req.postData() || '';
				if (pd.includes('action=sscribe_debug_fetch_logs')) {
					return route.fulfill(
						syntheticJson(200, {
							success: true,
							data: {
								entries: [],
								count: 0,
								offset: 0,
								limit: 200,
								has_more: false,
								window_cap: 1000,
								status: 'ok',
								debug_enabled: false,
								nonce: 'mock-nonce',
							},
						})
					);
				}
			} catch {
				// fall through to continue
			}
			return route.continue();
		});

		await adminPage.locator('#sscribe-tab-btn-debug').click();

		// Drive the renderLogs() taxonomy path directly via the public
		// SScribeDebugConsole.fetchLogs() method. This is the same
		// code path the auto-refresh timer (startAutoRefresh at
		// sscribe-debug-console.js:537) would call 10s later — but we
		// don't want to race the rate-limited server for those 10s.
		// Manually invoking it guarantees fetchLogs() fires after the
		// route is installed, so the mock intercepts and renderLogs()
		// runs the empty-state taxonomy synchronously.
		const fetchLogsTrigger = adminPage.waitForResponse(
			async (r) => {
				if (!r.url().includes('admin-ajax.php')) return false;
				try {
					const pd = r.request().postData() || '';
					return pd.includes('action=sscribe_debug_fetch_logs');
				} catch {
					return false;
				}
			},
			{ timeout: 30_000 }
		);
		await adminPage.evaluate(() => {
			const dc = (window as any).SScribeDebugConsole;
			// The page-init fetchLogs() call fires during
			// jQuery(document).ready (admin/js/sscribe-debug-console.js:1794-1797
			// → loadInitialState → fetchLogs) and is typically still in
			// flight by the time we install the route and click the tab.
			// fetchLogs() checks `if (this.isRefreshing) return;` at line 840,
			// so a manual call would no-op while the in-flight request holds
			// the flag. abort() clears it via the .fail() handler's
			// `if (xhr.statusText === 'abort')` branch (line 929).
			if (dc.currentRequest) {
				dc.currentRequest.abort();
			}
			dc.isRefreshing = false;
			dc.fetchLogs();
		});
		await fetchLogsTrigger;

		const toggle = adminPage.locator('#sscribe-debug-enabled[role="switch"]');
		await expect(toggle).toHaveAttribute('aria-checked', 'false', { timeout: 10_000 });

		// When debug is OFF and the log is empty, renderLogs must show
		// the debug_disabled copy (state 1 of 5 in the taxonomy), NOT
		// the default 'No log entries found.' copy.
		const empty = adminPage.locator('#sscribe-debug-empty p');
		await expect(empty).toBeVisible({ timeout: 10_000 });
		const text = (await empty.textContent())?.trim() || '';
		expect(text.toLowerCase()).toContain('disabled');
		expect(text.toLowerCase()).not.toContain('no log entries found');
	});
	test('saving unchanged debug state does not trigger a page reload', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('#sscribe-tab-btn-debug').click();
		const toggle = adminPage.locator('#sscribe-debug-enabled[role="switch"]');
		await expect(toggle).toBeAttached();
		const initialState = (await toggle.getAttribute('aria-checked')) === 'true';

		await adminPage.route('**/admin-ajax.php*', async (route) => {
			const req = route.request();
			if (!isDebugSaveSettings(req.postData())) return route.continue();
			return route.fulfill(
				syntheticJson(200, {
					success: true,
					data: {
						debug_enabled: initialState,
						log_level: 'INFO',
						auto_refresh: false,
						nonce: 'replacement-nonce',
					},
				})
			);
		});

		await adminPage.evaluate(() => {
			const w = window as any;
			w.__sscribeNoReloadMarker = 'alive';
			w.SScribeDebugConsole._previousDebugEnabled = undefined;
			w.SScribeDebugConsole.saveSettings(w.SScribeDebugConsole.isAutoRefresh);
		});

		await adminPage.waitForTimeout(1800);
		expect(await adminPage.evaluate(() => (window as any).__sscribeNoReloadMarker || null)).toBe('alive');
	});

	test('enabled console with active filters hides the Enable Debug Logging CTA', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.locator('#sscribe-tab-btn-debug').click();

		await adminPage.evaluate(() => {
			const w = window as any;
			w.SScribeDebugConsole.currentFilter = 'ERROR';
			w.SScribeDebugConsole.searchQuery = '';
			w.SScribeDebugConsole.sessionFilter = '';
			w.SScribeDebugConsole.renderLogs([], false, {
				status: 'ok',
				count: 0,
				debug_enabled: true,
			}, true);
		});

		await expect(adminPage.locator('#sscribe-debug-empty p')).toContainText(/no entries match the current filters/i);
		await expect(adminPage.locator('#sscribe-debug-empty-enable')).toBeHidden();
	});

});
