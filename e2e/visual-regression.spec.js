// @ts-check
/**
 * Visual regression across themes and breakpoints.
 * Masks volatile offer rows so snapshots stay stable while chrome/layout are proven.
 */
const { test, expect } = require('@playwright/test');
const { gotoApp, dismissOpenAppNavigation, waitForOffersSettled } = require('./helpers/auth-guard');
const { setUserTheme, resetUserTheme, USER_THEMES } = require('./helpers/theming');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const routes = [
	{
		id: 'offers',
		url: `${BASE}/index.php/apps/einkaufcheck/`,
		clipHeight: 720,
		waitOffers: true,
		mask: ['#ekc-stats', '#ekc-table-wrap', '#ekc-compares-wrap', '.ekc-product'],
	},
	{
		id: 'settings',
		url: `${BASE}/index.php/apps/einkaufcheck/settings/general`,
		clipHeight: 900,
		waitOffers: false,
		mask: [],
	},
];

const viewports = [
	{ width: 375, height: 812, label: 'mobile' },
	{ width: 1280, height: 800, label: 'desktop' },
];

const visualThemes = ['light', 'dark', 'dark-highcontrast'];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string[]} selectors
 */
function maskLocators(page, selectors) {
	return selectors.map((sel) => page.locator(sel));
}

test.describe('EinkaufCheck visual regression', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	for (const theme of visualThemes) {
		for (const route of routes) {
			for (const vp of viewports) {
				test(`${theme} ${route.id} @${vp.label}`, async ({ page }) => {
					test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');

					await page.setViewportSize({ width: vp.width, height: vp.height });
					await gotoApp(page, route.url);
					await setUserTheme(page, theme);
					await dismissOpenAppNavigation(page);
					if (route.waitOffers) {
						await waitForOffersSettled(page);
					}
					await expect(page.locator(`body[data-theme-${theme}]`)).toBeAttached();
					const app = page.locator('#app-content.ekc-app').first();
					await expect(app).toBeVisible();

					const clip = await app.evaluate((el, height) => {
						const rect = el.getBoundingClientRect();
						return {
							x: Math.max(0, rect.left),
							y: Math.max(0, rect.top),
							width: Math.min(rect.width, window.innerWidth),
							height: Math.min(height, rect.height),
						};
					}, route.clipHeight);

					await expect(page).toHaveScreenshot(
						`${theme}-${route.id}-${vp.label}.png`,
						{
							clip,
							maxDiffPixelRatio: 0.02,
							mask: maskLocators(page, route.mask),
							animations: 'disabled',
							caret: 'hide',
						},
					);
				});
			}
		}
	}

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage();
		try {
			if (process.env.E2E_USER) {
				await gotoApp(page, routes[0].url);
				await resetUserTheme(page).catch(() => {});
			}
		} finally {
			await page.close();
		}
	});
});

test.describe('Theme coverage completeness', () => {
	test('USER_THEMES includes all NC selectable themes', () => {
		expect(USER_THEMES).toEqual(
			expect.arrayContaining(['light', 'dark', 'light-highcontrast', 'dark-highcontrast']),
		);
	});
});
