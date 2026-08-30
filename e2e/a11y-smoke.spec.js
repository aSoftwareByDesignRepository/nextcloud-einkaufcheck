// @ts-check
/**
 * Shell chrome + axe WCAG 2.1 AA smoke for EinkaufCheck.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { gotoApp, dismissOpenAppNavigation, waitForOffersSettled } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const URLS = {
	offers: `${BASE}/index.php/apps/einkaufcheck/`,
	trends: `${BASE}/index.php/apps/einkaufcheck/trends`,
	settings: `${BASE}/index.php/apps/einkaufcheck/settings/general`,
};

test.describe('EinkaufCheck shell chrome a11y smoke', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	for (const [name, url] of Object.entries(URLS)) {
		test(`${name}: skip links, live regions, tokens, axe`, async ({ page }) => {
			const pageErrors = await gotoApp(page, url);
			const app = page.locator('#app-content.ekc-app').first();
			await expect(page.locator('#einkaufcheck-app')).toBeVisible();
			await expect(app).toBeVisible();
			const appBox = await app.boundingBox();
			expect(appBox?.height ?? 0, 'main column must not collapse under the sidebar').toBeGreaterThan(200);
			await expect(app.locator('a.ekc-skip-link[href="#ekc-main-content"]')).toBeAttached();
			await expect(page.locator('a.ekc-skip-link[href="#app-navigation"]')).toBeAttached();
			await expect(page.locator('#ekc-live-region')).toBeAttached();
			await expect(page.locator('#ekc-alert-region')).toBeAttached();
			await expect(page.locator('#ekc-main-content')).toBeAttached();
			await expect(page.locator('.ekc-page-stack')).toBeAttached();
			await expect(app.getByRole('heading', { level: 1 }).first()).toBeAttached();
			await expect(page.locator('#app-navigation.ekc-nav')).toBeAttached();

			if (name === 'offers') {
				await waitForOffersSettled(page);
			}

			const tokens = await page.evaluate(() => {
				const el = document.querySelector('#app-content.ekc-app');
				const cs = el ? getComputedStyle(el) : getComputedStyle(document.body);
				return {
					bgSoft: cs.getPropertyValue('--ekc-bg-soft').trim(),
					touch: cs.getPropertyValue('--ekc-touch').trim(),
					focus: cs.getPropertyValue('--ekc-focus').trim(),
				};
			});
			expect(tokens.bgSoft, 'soft background token').not.toEqual('');
			expect(tokens.touch).toBe('44px');
			expect(tokens.focus).toBe('3px');

			const results = await new AxeBuilder({ page })
				.include('#content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze();
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
			expect(pageErrors, 'uncaught page errors').toEqual([]);
		});
	}

	test('filter help sits below the postcode and search fields', async ({ page }) => {
		const pages = [
			{
				url: URLS.offers,
				pairs: [['#ekc-plz', '#ekc-plz-help']],
			},
			{
				url: URLS.trends,
				pairs: [
					['#ekc-plz', '#ekc-plz-help'],
					['#ekc-q', '#ekc-trends-q-help'],
				],
			},
		];
		for (const { url, pairs } of pages) {
			await gotoApp(page, url);
			for (const [inputSel, helpSel] of pairs) {
				const input = page.locator(inputSel);
				const help = page.locator(helpSel);
				await expect(input).toBeVisible();
				await expect(help).toBeVisible();
				const ib = await input.boundingBox();
				const hb = await help.boundingBox();
				expect(ib, `${url} ${inputSel}`).toBeTruthy();
				expect(hb, `${url} ${helpSel}`).toBeTruthy();
				if (!ib || !hb) {
					throw new Error(`missing box for ${inputSel} / ${helpSel} on ${url}`);
				}
				expect(hb.y, `${helpSel} must sit below ${inputSel} on ${url}`).toBeGreaterThan(ib.y + ib.height - 1);
			}
		}
	});

	test('bare /settings shows the postal-code page without a redirect loop', async ({ page }) => {
		await gotoApp(page, `${BASE}/apps/einkaufcheck/settings`);
		await expect(page.locator('#ekc-page-title')).toBeVisible();
		await expect(page.locator('#ekc-settings-plz')).toBeVisible();
		await expect(page).not.toHaveURL(/\/login/);
	});

	test('mobile 375: no horizontal document overflow on offers', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await gotoApp(page, URLS.offers);
		await dismissOpenAppNavigation(page);
		await waitForOffersSettled(page);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow).toBeLessThanOrEqual(2);
	});
});
