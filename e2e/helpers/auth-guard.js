/**
 * E2E auth helpers for EinkaufCheck.
 */
async function tryProgrammaticLogin(page) {
	const user = process.env.E2E_USER;
	const pass = process.env.E2E_PASS || process.env.E2E_PASSWORD;
	if (!user || !pass) {
		return false;
	}

	const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
	if (!onLogin) {
		return true;
	}

	const accountField = page.getByRole('textbox', { name: /account name|email|kontoname|e-mail/i }).first();
	const passwordField = page.getByRole('textbox', { name: /password|passwort/i });
	await accountField.fill(user);
	await passwordField.fill(pass);
	await page.getByRole('button', { name: /^log in$|^anmelden$/i }).click();
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 }).catch(() => {});

	const stillLogin = await loginHeading.isVisible({ timeout: 2000 }).catch(() => false);
	return !stillLogin;
}

async function ensureAuthenticated(page) {
	const loggedIn = await tryProgrammaticLogin(page);
	if (!loggedIn) {
		const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
		const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
		if (onLogin) {
			const { test } = require('@playwright/test');
			test.skip(true, 'Not authenticated. Set E2E_USER + E2E_PASS in e2e/.env');
		}
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function dismissOpenAppNavigation(page) {
	const narrow = (page.viewportSize()?.width ?? 1280) <= 480;
	if (!narrow) {
		return;
	}
	const appContent = page.locator('#app-content.ekc-app').first();
	const present = await appContent.count().catch(() => 0);
	if (!present) {
		return;
	}
	const appBox = await appContent.boundingBox({ timeout: 3000 }).catch(() => null);
	if (appBox && appBox.width > 200) {
		return;
	}
	const toggle = page.locator('#app-navigation-toggle, .app-navigation-toggle').first();
	if (await toggle.count()) {
		await toggle.click({ force: true }).catch(() => {});
		await page.waitForTimeout(250);
	}
	await appContent
		.evaluate((el) => {
			if (el.getBoundingClientRect().width > 200) {
				return;
			}
			const nav = document.getElementById('app-navigation');
			if (nav) {
				nav.classList.add('hidden');
			}
			document.body.classList.remove('snapjs-left');
		})
		.catch(() => {});
}

/**
 * @param {import('@playwright/test').Page} page
 */
function attachPageErrorCollector(page) {
	/** @type {string[]} */
	const errors = [];
	page.on('pageerror', (err) => {
		errors.push(String(err && err.message ? err.message : err));
	});
	return errors;
}

async function gotoApp(page, url) {
	const errors = attachPageErrorCollector(page);
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	await ensureAuthenticated(page);
	await page.locator('#ekc-page-title').waitFor({ state: 'visible', timeout: 30_000 });
	await dismissOpenAppNavigation(page);
	return errors;
}

/**
 * Wait until offers finished loading (rows, empty, or visible error).
 * First live fetch can take ~55s.
 * @param {import('@playwright/test').Page} page
 */
async function waitForOffersSettled(page) {
	const handle = await page.waitForFunction(() => {
		const alerts = Array.from(document.querySelectorAll('[role="alert"]'))
			.map((el) => (el.textContent || '').trim())
			.filter(Boolean);
		const live = (document.getElementById('ekc-live-region')?.textContent || '').trim();
		const statusBits = [...alerts, live];
		if (statusBits.some((t) => /CSRF check failed|Could not verify this request/i.test(t))) {
			return 'csrf';
		}
		if (statusBits.some((t) => /Too many requests|rate limit|429/i.test(t))) {
			return 'rate_limited';
		}
		const err = document.getElementById('ekc-load-error');
		if (err && !err.hidden) {
			return 'error';
		}
		const empty = document.getElementById('ekc-empty');
		if (empty && !empty.hidden) {
			return 'empty';
		}
		const product = document.querySelector('#ekc-rows .ekc-product');
		if (product) {
			return 'rows';
		}
		const busy = document.getElementById('ekc-table-wrap');
		if (busy && busy.getAttribute('aria-busy') === 'false' && document.querySelector('#ekc-rows tr')) {
			const loading = document.querySelector('#ekc-rows .ekc-loading');
			if (!loading) {
				return 'idle';
			}
		}
		return false;
	}, null, { timeout: 120_000 });
	const state = await handle.jsonValue();
	if (state === 'csrf') {
		throw new Error('CSRF check failed while loading offers — mutating requests must send requesttoken');
	}
}

/**
 * Default layout hides the shopping list for wide compare — reveal before list UI tests.
 * @param {import('@playwright/test').Page} page
 */
async function ensureListPanelVisible(page) {
	const side = page.locator('#ekc-side');
	if (await side.isVisible()) {
		return;
	}
	await page.locator('#ekc-layout-split').click();
	await side.waitFor({ state: 'visible', timeout: 5_000 });
}

module.exports = {
	ensureAuthenticated,
	tryProgrammaticLogin,
	gotoApp,
	dismissOpenAppNavigation,
	attachPageErrorCollector,
	waitForOffersSettled,
	ensureListPanelVisible,
};
