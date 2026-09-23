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

		await proceed.click();
		expect(await adminPage.evaluate(() => (window as any).__sscribeProceedCalls)).toBe(1);
	});


	test('preflight cancel returns keyboard focus to the invoking element', async ({ adminPage }) => {
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
							memory: { status: 'warning', name: 'Memory', message: 'Warning.' },
						},
					},
				})
			);
		});

		await adminPage.evaluate(() => {
			const origin = document.createElement('button');
			origin.id = 'sscribe-focus-origin';
			origin.textContent = 'Origin';
			document.body.appendChild(origin);
			origin.focus();
			(window as any).SScribe.runPreflightCheck('', 'publish', 'page', ['docx']);
		});

		const banner = adminPage.locator('.sscribe-preflight-banner');
		await expect(banner).toBeVisible();
		await expect(banner.locator('.sscribe-preflight-close')).toBeFocused();
		await banner.locator('.sscribe-preflight-cancel').click();
		await expect(banner).toHaveCount(0);
		expect(await adminPage.evaluate(() => document.activeElement?.id)).toBe('sscribe-focus-origin');
	});

	test('toast exposes a keyboard-operable dismiss button', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		await adminPage.evaluate(() => {
			(window as any).SScribe.showToast('Keyboard toast', 'info', 0);
		});
		const toast = adminPage.locator('.sscribe-toast').filter({ hasText: 'Keyboard toast' });
		await expect(toast).toBeVisible();
		const dismiss = toast.locator('button.sscribe-toast-dismiss');
		await expect(dismiss).toHaveAttribute('aria-label', /Dismiss/i);
		await dismiss.focus();
		await dismiss.press('Enter');
		await expect(toast).toHaveCount(0, { timeout: 2000 });
	});

	test('all-languages sentinel has a user-facing localized label', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
		const label = await adminPage.evaluate(() => (window as any).SScribe.getLanguageLabel('__all__'));
		expect(label).toBe('All Languages');
	});
});
