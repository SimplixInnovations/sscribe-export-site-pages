import { test, expect } from '../../fixtures/shared';

const syntheticJson = (body: object) => ({
	status: 200,
	contentType: 'application/json; charset=UTF-8',
	body: JSON.stringify(body),
});

const isPreflight = (postData: string | null | undefined): boolean =>
	typeof postData === 'string' && postData.includes('action=sscribe_preflight_check');

test.describe('e2e / export / preflight-contract', () => {
	test('hard preflight errors block export and never expose Continue Anyway', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			if (!isPreflight(route.request().postData())) return route.continue();
			return route.fulfill(
				syntheticJson({
					success: true,
					data: {
						status: 'error',
						can_proceed: false,
						checks: {
							storage: {
								status: 'error',
								name: 'Private storage',
								message: 'Private storage is not writable.',
							},
						},
					},
				})
			);
		});

		await adminPage.evaluate(() => {
			const w = window as any;
			w.__sscribeProceedCalls = 0;
			w.SScribe.proceedWithExport = () => {
				w.__sscribeProceedCalls += 1;
			};
			w.SScribe.runPreflightCheck('', 'publish', 'page', ['docx']);
		});

		const banner = adminPage.locator('.sscribe-preflight-banner');
		await expect(banner).toBeVisible();
		await expect(banner).toContainText('Private storage is not writable');
		await expect(banner.locator('.sscribe-preflight-proceed')).toHaveCount(0);
		expect(await adminPage.evaluate(() => (window as any).__sscribeProceedCalls)).toBe(0);
	});

	test('warning preflight requires explicit continuation before export starts', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.route('**/admin-ajax.php*', async (route) => {
			if (!isPreflight(route.request().postData())) return route.continue();
			return route.fulfill(
				syntheticJson({
					success: true,
					data: {
						status: 'warning',
						can_proceed: true,
						checks: {
							memory: {
								status: 'warning',
								name: 'Memory',
								message: 'Available memory is below the recommended level.',
							},
						},
					},
				})
			);
		});

		await adminPage.evaluate(() => {
			const w = window as any;
			w.__sscribeProceedCalls = 0;
			w.SScribe.proceedWithExport = () => {
				w.__sscribeProceedCalls += 1;
			};
			w.SScribe.runPreflightCheck('', 'publish', 'page', ['docx']);
		});

		const banner = adminPage.locator('.sscribe-preflight-banner');
		await expect(banner).toBeVisible();
		const proceed = banner.locator('.sscribe-preflight-proceed');
		await expect(proceed).toBeVisible();
		expect(await adminPage.evaluate(() => (window as any).__sscribeProceedCalls)).toBe(0);

		const returnTarget = adminPage.locator('#sscribe-tab-btn-export');
		await returnTarget.focus();
		await expect(returnTarget).toBeFocused();

		// Re-open after establishing a deterministic focus origin so the
		// production focus-restoration contract is exercised, not inferred.
		await adminPage.evaluate(() => {
			const w = window as any;
			w.SScribe.runPreflightCheck('', 'publish', 'page', ['docx']);
		});
		const focusedBanner = adminPage.locator('.sscribe-preflight-banner').last();
		const focusedProceed = focusedBanner.locator('.sscribe-preflight-proceed');
		await expect(focusedProceed).toBeVisible();
		await focusedProceed.click();
		await expect(returnTarget).toBeFocused();
		expect(await adminPage.evaluate(() => (window as any).__sscribeProceedCalls)).toBe(1);
	});

	test('all-languages sentinel has a user-facing localized label', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		const label = await adminPage.evaluate(() => (window as any).SScribe.getLanguageLabel('__all__'));
		expect(label).toBe('All Languages');
	});
});
