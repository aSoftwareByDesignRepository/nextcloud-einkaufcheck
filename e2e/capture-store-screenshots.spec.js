// @ts-check
/**
 * App Store screenshot capture for EinkaufCheck (einkaufcheck).
 * 1920×1040 canvas (Check suite standard).
 *
 * Run:
 *   npx playwright test e2e/capture-store-screenshots.spec.js --project=chromium-store
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp, dismissOpenAppNavigation, waitForOffersSettled } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const outDir = path.resolve(__dirname, '../screenshots');

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
async function shot(page, name) {
	fs.mkdirSync(outDir, { recursive: true });
	await page.waitForTimeout(500);
	await page.screenshot({
		path: path.join(outDir, name),
		fullPage: false,
	});
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function waitChrome(page) {
	await expect(page.locator('#app-content.ekc-app').first()).toBeVisible({ timeout: 30_000 });
	await page.locator('#ekc-page-title, .ekc-page-header__title').first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
}

test.describe('App Store screenshots', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		test.skip(
			testInfo.project.name !== 'chromium-store',
			'App-store screenshot capture is only for chromium-store (1920×1040)',
		);
	});

	test('capture store screenshots', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/einkaufcheck/`);
		await dismissOpenAppNavigation(page);
		await waitForOffersSettled(page);
		await waitChrome(page);
		await shot(page, 'einkaufcheck-screenshot-01-offers.png');

		await gotoApp(page, `${BASE}/index.php/apps/einkaufcheck/trends`);
		await dismissOpenAppNavigation(page);
		await waitChrome(page);
		await shot(page, 'einkaufcheck-screenshot-02-trends.png');

		await gotoApp(page, `${BASE}/index.php/apps/einkaufcheck/settings/general`);
		await dismissOpenAppNavigation(page);
		await waitChrome(page);
		await shot(page, 'einkaufcheck-screenshot-03-settings.png');

		await gotoApp(page, `${BASE}/index.php/apps/einkaufcheck/settings/access`);
		await dismissOpenAppNavigation(page);
		await waitChrome(page);
		await shot(page, 'einkaufcheck-screenshot-04-access.png');

		await gotoApp(page, `${BASE}/index.php/apps/einkaufcheck/`);
		await dismissOpenAppNavigation(page);
		await waitForOffersSettled(page);
		const listCard = page.locator('#ekc-list-card');
		if (await listCard.count()) {
			await listCard.scrollIntoViewIfNeeded();
			await page.waitForTimeout(400);
		}
		await shot(page, 'einkaufcheck-screenshot-05-list.png');

		const files = [
			'einkaufcheck-screenshot-01-offers.png',
			'einkaufcheck-screenshot-02-trends.png',
			'einkaufcheck-screenshot-03-settings.png',
			'einkaufcheck-screenshot-04-access.png',
			'einkaufcheck-screenshot-05-list.png',
		];
		for (const name of files) {
			const full = path.join(outDir, name);
			expect(fs.existsSync(full), `missing ${name}`).toBeTruthy();
			expect(fs.statSync(full).size).toBeGreaterThan(20_000);
		}
	});
});
