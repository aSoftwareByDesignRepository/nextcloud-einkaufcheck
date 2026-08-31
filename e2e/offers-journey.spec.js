// @ts-check
/**
 * Offers happy path: load list, add one item, watch a staple. Granny can find + and Watch.
 */
const { test, expect } = require('@playwright/test');
const { gotoApp, waitForOffersSettled, ensureListPanelVisible } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const OFFERS = `${BASE}/index.php/apps/einkaufcheck/`;

/** Shared ekctest user — leftover list rows from earlier runs must not fail exact counts. */
async function emptyShoppingList(page) {
	await ensureListPanelVisible(page);
	await expect(page.locator('#ekc-list-count')).toHaveText(/^\(/);
	if ((await page.locator('#ekc-list-items li').count()) > 0) {
		page.once('dialog', (d) => d.accept());
		await page.locator('#ekc-clear').click();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(0);
	}
}

test.describe('EinkaufCheck offers journey', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test('show offers, add to list, watch staple', async ({ page }) => {
		/** @type {number[]} */
		const refreshStatuses = [];
		page.on('response', (res) => {
			if (res.url().includes('/offers/refresh')) {
				refreshStatuses.push(res.status());
			}
		});
		await gotoApp(page, OFFERS);
		await expect(page.locator('#ekc-page-title')).toBeVisible();
		await expect(page.locator('#ekc-plz')).toHaveValue(/^\d{5}$/);
		const navBox = await page.locator('#app-navigation.ekc-nav').boundingBox();
		const appBox = await page.locator('#app-content.ekc-app').boundingBox();
		expect(appBox?.height ?? 0).toBeGreaterThan(200);
		expect(navBox?.x ?? 0).toBeLessThan(appBox?.x ?? 0);
		await waitForOffersSettled(page);
		expect(refreshStatuses.filter((s) => s === 412), 'live refresh must send a valid CSRF token').toEqual([]);

		const err = page.locator('#ekc-load-error');
		await expect(err).toBeHidden();

		const addBtn = page.locator('#ekc-rows button.add').first();
		await expect(addBtn).toBeVisible({ timeout: 10_000 });
		const box = await addBtn.boundingBox();
		expect(box?.height ?? 0).toBeGreaterThanOrEqual(40);

		await emptyShoppingList(page);
		await addBtn.click();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(1, { timeout: 15_000 });

		const watchesBefore = await page.locator('#ekc-watch-items li').count();
		await page.locator('#ekc-rows button.watch').first().click();
		await expect(page.locator('#ekc-watch-q')).not.toHaveValue('');
		await page.locator('#ekc-watch-form button[type="submit"]').click();
		await expect(page.locator('#ekc-watch-items li')).toHaveCount(watchesBefore + 1, { timeout: 15_000 });
	});

	test('clear filters does not turn pictures off', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await page.locator('#ekc-show-images').check();
		await page.locator('#ekc-clear-filters').click();
		await expect(page.locator('#ekc-show-images')).toBeChecked();
		await expect(page.locator('#ekc-only-kg')).not.toBeChecked();
		await expect(page.locator('#ekc-only-wait')).not.toBeChecked();
		await page.locator('#ekc-show-images').uncheck();
		await expect(page.locator('#ekc-show-images')).not.toBeChecked();
	});

	test('search Bananen finds produce while Food is selected', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await expect(page.locator('#ekc-load-error')).toBeHidden();
		await expect(page.locator('#ekc-cat-food')).toBeChecked();
		await page.locator('#ekc-cat-all').check();
		const banana = page.locator('#ekc-rows .ekc-product__text', { hasText: /Bananen/i });
		if ((await banana.count()) === 0) {
			test.skip(true, 'No banana offers in this week’s list');
		}
		await page.locator('#ekc-cat-food').check();
		await expect(page.locator('#ekc-rows .ekc-product__text', { hasText: /Bananen/i })).toBeVisible();
		await page.locator('#ekc-q').fill('Bananen');
		await expect(page.locator('#ekc-rows .ekc-product__text', { hasText: /Bananen/i })).toBeVisible();
		await expect(page.locator('#ekc-cat-food')).toBeChecked();
		await page.locator('#ekc-cat-nonfood').check();
		await expect(page.locator('#ekc-rows .ekc-product__text', { hasText: /Bananen/i })).toHaveCount(0);
	});

	test('shopping list can be filtered to one store', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await ensureListPanelVisible(page);
		await expect(page.locator('#ekc-list-store-all')).toBeVisible();
		await expect(page.locator('#ekc-print')).toBeVisible();
		await emptyShoppingList(page);
		await page.locator('#ekc-cat-all').check();
		const aldiAdd = page.locator('#ekc-rows tr').filter({ hasText: 'ALDI Nord' }).locator('button.add').first();
		const lidlAdd = page.locator('#ekc-rows tr').filter({ hasText: 'Lidl' }).locator('button.add').first();
		if ((await aldiAdd.count()) === 0 || (await lidlAdd.count()) === 0) {
			test.skip(true, 'Need an ALDI and a Lidl offer this week');
		}
		await aldiAdd.click();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(1, { timeout: 15_000 });
		await lidlAdd.click();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(2, { timeout: 15_000 });
		await page.locator('#ekc-list-store-aldi').check();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(1);
		await expect(page.locator('#ekc-list-items li')).toContainText('ALDI Nord');
		await page.locator('#ekc-list-store-lidl').check();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(1);
		await expect(page.locator('#ekc-list-items li')).toContainText('Lidl');
		await page.locator('#ekc-list-store-aldi').check();
		page.once('dialog', (d) => d.accept());
		await page.locator('#ekc-clear').click();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(0);
		await page.locator('#ekc-list-store-all').check();
		await expect(page.locator('#ekc-list-items li')).toHaveCount(1);
		await expect(page.locator('#ekc-list-items li')).toContainText('Lidl');
	});

	test('offers table can scroll to the unit price column', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		const wrap = page.locator('#ekc-table-wrap');
		await expect(wrap).toBeVisible();
		const overflowX = await wrap.evaluate((el) => getComputedStyle(el).overflowX);
		expect(overflowX).toMatch(/auto|scroll/);
		await wrap.evaluate((el) => {
			el.scrollLeft = el.scrollWidth;
		});
		const unit = page.locator('#ekc-unit-col');
		await expect(unit).toBeVisible();
		const inView = await unit.evaluate((el) => {
			const wrapEl = document.getElementById('ekc-table-wrap');
			if (!wrapEl) {
				return false;
			}
			const wr = wrapEl.getBoundingClientRect();
			const cr = el.getBoundingClientRect();
			return cr.right <= wr.right + 2 && cr.left >= wr.left - 2;
		});
		expect(inView, 'Unit price header must sit inside the scrollport after scrolling').toBe(true);
	});

	test('unit price shows by default when calculable', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await page.locator('#ekc-only-kg').check();
		await expect(page.locator('#ekc-rows tr').first()).toBeVisible();
		const cell = page.locator('#ekc-rows tr').first().locator('td.num').last();
		await expect(cell).not.toHaveText('—');
		await expect(cell).toContainText(/\/(kg|l|St\.)/);
	});

	test('cheaper-next-week filter is available', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		const wait = page.locator('#ekc-only-wait');
		await expect(wait).toBeVisible();
		await wait.check();
		await expect(wait).toBeChecked();
		await page.locator('#ekc-clear-filters').click();
		await expect(wait).not.toBeChecked();
	});

	test('Everything keeps the category chips on screen', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await expect(page.locator('#ekc-load-error')).toBeHidden();
		await page.locator('#ekc-cat-all').click();
		const firstProduct = page.locator('#ekc-rows .ekc-product').first();
		await expect(firstProduct).toBeVisible();
		await firstProduct.scrollIntoViewIfNeeded();
		await expect(page.locator('#ekc-cat-food')).toBeVisible();
		await expect(page.locator('#ekc-cat-produce')).toBeVisible();
		await expect(page.locator('#ekc-cat-nonfood')).toBeVisible();
		await expect(page.locator('#ekc-cat-all')).toBeVisible();
		await expect(page.locator('#ekc-cat-all')).toBeChecked();
	});

	test('hide list button widens offers table', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await ensureListPanelVisible(page);
		const wrapBefore = page.locator('#ekc-table-wrap');
		const widthBefore = await wrapBefore.evaluate((el) => el.clientWidth);
		expect(widthBefore).toBeGreaterThan(200);
		await page.locator('#ekc-layout-hide-from-side').click();
		await expect(page.locator('#ekc-list-card')).toBeHidden();
		await expect(page.locator('#app-content.ekc-app')).toHaveClass(/ekc-app--compare-focus/);
		await expect(page.locator('#ekc-offers-count')).not.toContainText('%s');
		const widthAfter = await wrapBefore.evaluate((el) => el.clientWidth);
		expect(widthAfter).toBeGreaterThan(widthBefore * 1.15);
	});

	test('compare layout hides both side panels for full-width table', async ({ page }) => {
		await gotoApp(page, OFFERS);
		await waitForOffersSettled(page);
		await expect(page.locator('#ekc-layout-compare')).toHaveAttribute('aria-pressed', 'true');
		await expect(page.locator('#ekc-side')).toBeHidden();
		await expect(page.locator('#ekc-list-card')).toBeHidden();
		await expect(page.locator('#ekc-watch-card')).toBeHidden();
		const tableBox = await page.locator('#ekc-offers-table').boundingBox();
		expect(tableBox?.width ?? 0).toBeGreaterThan(400);
		await page.locator('#ekc-layout-split').click();
		await expect(page.locator('#ekc-layout-split')).toHaveAttribute('aria-pressed', 'true');
		await expect(page.locator('#ekc-side')).toBeVisible();
		await expect(page.locator('#ekc-list-card')).toBeVisible();
		await expect(page.locator('#ekc-watch-card')).toBeVisible();
		await page.locator('#ekc-layout-compare').click();
		await expect(page.locator('#ekc-side')).toBeHidden();
		const jump = page.locator('#ekc-list-jump');
		if (await jump.isVisible()) {
			await jump.click();
			await expect(page.locator('#ekc-side')).toBeVisible();
		}
	});
});
