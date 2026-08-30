// @ts-check
/**
 * Product pictures are opt-in. Off = no <img> in the DOM (no third-party request).
 * On = thumbs next to the product name from allowlisted HTTPS CDNs.
 */
const { test, expect } = require('@playwright/test');
const { gotoApp, waitForOffersSettled } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const OFFERS = `${BASE}/index.php/apps/einkaufcheck/`;
const SETTINGS = `${BASE}/index.php/apps/einkaufcheck/settings/general`;
const TRENDS = `${BASE}/index.php/apps/einkaufcheck/trends`;

const ALLOWED_HOST = /(^|\.)(scene7\.com|lidl\.de|lidlplus\.com|aldi-nord\.de)$/i;

test.describe('EinkaufCheck product pictures', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test('off by default: no product thumbs in the DOM', async ({ page }) => {
		await gotoApp(page, SETTINGS);
		const settingsBox = page.locator('#ekc-settings-show-images');
		await expect(settingsBox).toBeVisible();
		if (await settingsBox.isChecked()) {
			await settingsBox.uncheck();
			await expect(settingsBox).not.toBeChecked();
		}
		await gotoApp(page, OFFERS);
		const box = page.locator('#ekc-show-images');
		await expect(box).toBeVisible();
		await expect(box).not.toBeChecked();
		await waitForOffersSettled(page);
		await expect(page.locator('#ekc-rows img.ekc-thumb')).toHaveCount(0);
	});

	test('turning pictures on loads allowlisted thumbs; off removes them', async ({ page }) => {
		const failedImages = [];
		page.on('requestfailed', (req) => {
			if (req.resourceType() === 'image') {
				failedImages.push(req.url() + ' ' + (req.failure()?.errorText || ''));
			}
		});

		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);

		const toggle = page.locator('#ekc-show-images');
		await toggle.check();
		await expect(toggle).toBeChecked();

		const hint = page.locator('#ekc-images-hint');
		const hintVisible = await hint.isVisible().catch(() => false);
		if (hintVisible) {
			await page.locator('#ekc-refresh').click();
			await waitForOffersSettled(page);
		}

		const thumbs = page.locator('#ekc-rows img.ekc-thumb');
		const n = await thumbs.count();
		if (n === 0) {
			const err = page.locator('#ekc-load-error');
			const errVisible = await err.isVisible().catch(() => false);
			expect(errVisible, 'offers failed to load so pictures cannot be proven').toBeFalsy();
			test.info().annotations.push({
				type: 'note',
				description: 'Cache has no image URLs after refresh — fail closed, no thumbs (allowed).',
			});
		} else {
			await expect(thumbs.first()).toBeVisible();
			const srcs = await thumbs.evaluateAll((els) => els.map((el) => el.getAttribute('src') || ''));
			for (const src of srcs) {
				expect(src, 'thumb src must be https').toMatch(/^https:\/\//);
				const host = new URL(src).hostname;
				expect(host, src).toMatch(ALLOWED_HOST);
			}
			const alts = await thumbs.evaluateAll((els) => els.map((el) => el.getAttribute('alt')));
			for (const alt of alts) {
				expect(alt, 'decorative thumbs next to the product name').toBe('');
			}
		}

		const cspBlocked = failedImages.filter((u) => /csp|blocked/i.test(u));
		expect(cspBlocked, cspBlocked.join('\n')).toEqual([]);

		await toggle.uncheck();
		await expect(toggle).not.toBeChecked();
		await expect(page.locator('#ekc-rows img.ekc-thumb')).toHaveCount(0);
	});

	test('settings toggle matches offers and survives reload', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await page.locator('#ekc-show-images').check();
		await expect.poll(async () => page.locator('#ekc-show-images').isChecked()).toBeTruthy();

		await gotoApp(page, SETTINGS);
		const settingsBox = page.locator('#ekc-settings-show-images');
		await expect(settingsBox).toBeVisible();
		await expect(settingsBox).toBeChecked();
		await expect(page.getByText('Product pictures', { exact: true })).toBeVisible();

		await page.reload({ waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#ekc-settings-show-images');
		await expect(page.locator('#ekc-settings-show-images')).toBeChecked();

		await settingsBox.uncheck();
		await gotoApp(page, OFFERS);
		await expect(page.locator('#ekc-show-images')).not.toBeChecked();
		await waitForOffersSettled(page);
		await expect(page.locator('#ekc-rows img.ekc-thumb')).toHaveCount(0);
	});

	test('trends page has the same pictures checkbox', async ({ page }) => {
		await gotoApp(page, TRENDS);
		await expect(page.locator('#ekc-show-images')).toBeVisible();
		await expect(page.locator('#ekc-page-title')).toHaveText(/when is it cheaper|wann ist es günstiger/i);
	});
});
