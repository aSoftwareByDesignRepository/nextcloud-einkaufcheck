// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for EinkaufCheck.
 *
 * Proves for every selectable NC theme and key routes:
 *  - theme actually switched (body[data-theme-*]),
 *  - design tokens resolve from Nextcloud --color-* (tints mix into main-bg),
 *  - zero horizontal overflow from 320 px up to 4K,
 *  - primary chrome touch targets ≥ 44×44,
 *  - zero axe WCAG 2.1 A/AA violations on the app shell,
 *  - default shell is not locked to a fixed 72rem / 1200px max-width,
 *  - custom accent primary tracks --ekc-primary / --ekc-accent.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { gotoApp, dismissOpenAppNavigation, waitForOffersSettled } = require('./helpers/auth-guard');
const {
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
	USER_THEMES,
} = require('./helpers/theming');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const routes = [
	{ id: 'offers', url: `${BASE}/index.php/apps/einkaufcheck/` },
	{ id: 'trends', url: `${BASE}/index.php/apps/einkaufcheck/trends` },
	{ id: 'settings', url: `${BASE}/index.php/apps/einkaufcheck/settings/general` },
	{ id: 'access', url: `${BASE}/index.php/apps/einkaufcheck/settings/access` },
];

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
];

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	await dismissOpenAppNavigation(page);
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement;
		const app = document.querySelector('#app-content.ekc-app');
		const shell = document.querySelector('#app-content-wrapper.ekc-shell, #app-content-wrapper');
		const shellOx = shell ? getComputedStyle(shell).overflowX : '';
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
			shellClipped: shellOx === 'hidden' || shellOx === 'clip',
		};
	});
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(2);
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(2);
	if (!overflow.shellClipped) {
		expect(overflow.shell, `.ekc-shell overflow at ${label}`).toBeLessThanOrEqual(2);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const bodyCs = getComputedStyle(document.body);
		const rootCs = getComputedStyle(document.documentElement);
		const el = document.querySelector('#app-content.ekc-app');
		const cs = el ? getComputedStyle(el) : bodyCs;
		const shell = document.querySelector('#app-content-wrapper.ekc-shell, #app-content.ekc-app');
		const nav = document.querySelector('#app-navigation.ekc-nav');
		return {
			ncBg: bodyCs.getPropertyValue('--color-main-background').trim(),
			ncText: bodyCs.getPropertyValue('--color-main-text').trim(),
			ncPrimary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			bodyPrimary: bodyCs.getPropertyValue('--ekc-primary').trim(),
			bodyAccent: bodyCs.getPropertyValue('--ekc-accent').trim(),
			bodyMuted: bodyCs.getPropertyValue('--ekc-muted').trim(),
			rootRadiusMd: rootCs.getPropertyValue('--ekc-radius-md').trim(),
			rootTouch: rootCs.getPropertyValue('--ekc-touch').trim(),
			bgSoft: cs.getPropertyValue('--ekc-bg-soft').trim() || bodyCs.getPropertyValue('--ekc-bg-soft').trim(),
			text: cs.getPropertyValue('--ekc-text').trim() || bodyCs.getPropertyValue('--ekc-text').trim(),
			muted: cs.getPropertyValue('--ekc-muted').trim() || bodyCs.getPropertyValue('--ekc-muted').trim(),
			primary: cs.getPropertyValue('--ekc-primary').trim() || bodyCs.getPropertyValue('--ekc-primary').trim(),
			tintInfo: cs.getPropertyValue('--ekc-tint-info').trim() || bodyCs.getPropertyValue('--ekc-tint-info').trim(),
			tintSuccess: cs.getPropertyValue('--ekc-tint-success').trim() || bodyCs.getPropertyValue('--ekc-tint-success').trim(),
			dangerFill: cs.getPropertyValue('--ekc-danger-fill').trim() || bodyCs.getPropertyValue('--ekc-danger-fill').trim(),
			dangerOnFill: cs.getPropertyValue('--ekc-danger-on-fill').trim() || bodyCs.getPropertyValue('--ekc-danger-on-fill').trim(),
			dangerInk: cs.getPropertyValue('--ekc-danger-ink').trim() || bodyCs.getPropertyValue('--ekc-danger-ink').trim(),
			scrim: cs.getPropertyValue('--ekc-scrim').trim() || bodyCs.getPropertyValue('--ekc-scrim').trim(),
			shadowSm: cs.getPropertyValue('--ekc-shadow-sm').trim() || bodyCs.getPropertyValue('--ekc-shadow-sm').trim(),
			touch: cs.getPropertyValue('--ekc-touch').trim() || rootCs.getPropertyValue('--ekc-touch').trim(),
			focus: cs.getPropertyValue('--ekc-focus').trim() || rootCs.getPropertyValue('--ekc-focus').trim(),
			navBg: nav ? getComputedStyle(nav).backgroundColor : '',
			shellMax: shell ? getComputedStyle(shell).maxWidth : '',
		};
	});
	expect(tokens.ncBg, 'NC --color-main-background').not.toEqual('');
	expect(tokens.ncText, 'NC --color-main-text').not.toEqual('');
	expect(tokens.ncPrimary, 'NC --color-primary-element').not.toEqual('');
	expect(tokens.bodyPrimary, 'body --ekc-primary').not.toEqual('');
	expect(tokens.bodyAccent, 'body --ekc-accent').not.toEqual('');
	expect(tokens.bodyMuted, 'body --ekc-muted').not.toEqual('');
	expect(tokens.rootRadiusMd === '12px' || parseFloat(tokens.rootRadiusMd) === 12, 'root radius-md').toBeTruthy();
	expect(tokens.rootTouch === '44px' || parseFloat(tokens.rootTouch) >= 44, 'root touch').toBeTruthy();
	expect(tokens.bgSoft, 'ekc-bg-soft').not.toEqual('');
	expect(tokens.text, 'ekc-text').not.toEqual('');
	expect(tokens.primary, 'ekc-primary').not.toEqual('');
	expect(tokens.muted, 'ekc-muted').not.toEqual('');
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('');
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('');
	expect(tokens.dangerFill, 'danger-fill must resolve').not.toEqual('');
	expect(tokens.dangerOnFill, 'danger-on-fill must resolve').not.toEqual('');
	expect(tokens.dangerInk, 'danger-ink must resolve').not.toEqual('');
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy();
	expect(tokens.scrim, 'scrim token').not.toEqual('');
	expect(tokens.shadowSm, 'shadow-sm token').not.toEqual('');
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token ≥44px').toBeTruthy();
	expect(tokens.focus).toContain('3px');
	expect(tokens.navBg, 'sidebar must resolve themed background').not.toEqual('');
	expect(
		tokens.shellMax === 'none'
			|| tokens.shellMax === ''
			|| tokens.shellMax === '100%'
			|| parseFloat(tokens.shellMax) >= 2000,
		`default shell must not be a fixed 72rem/1200px lock (got ${tokens.shellMax})`,
	).toBeTruthy();
}

/**
 * Offer product wells / cards must follow NC theme tokens — never a hardcoded white slab in dark mode.
 * @param {import('@playwright/test').Page} page
 * @param {string} theme
 */
async function assertOfferSurfacesThemeAware(page, theme) {
	const result = await page.evaluate(() => {
		const card = document.querySelector('#app-content.ekc-app .ekc-card, #app-content.ekc-app .ekc-product');
		const bodyCs = getComputedStyle(document.body);
		if (!card) {
			return { skip: true };
		}
		const cardCs = getComputedStyle(card);
		return {
			skip: false,
			bgCard: bodyCs.getPropertyValue('--ekc-bg-card').trim(),
			cardBg: cardCs.backgroundColor,
		};
	});
	if (result.skip) {
		return;
	}
	expect(result.bgCard, '--ekc-bg-card must resolve').not.toEqual('');
	expect(result.cardBg, 'card background').not.toEqual('');
	if (theme === 'dark' || theme === 'dark-highcontrast') {
		expect(
			result.cardBg,
			`dark theme card must not be pure white (got ${result.cardBg})`,
		).not.toMatch(/^rgb\(255,\s*255,\s*255\)$/);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertChromeTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.ekc-app .ekc-btn--primary, #app-content.ekc-app .ekc-btn, #app-content.ekc-app .ekc-filter, #app-content.ekc-app .ekc-settings-nav__link, #app-content.ekc-app .ekc-settings-chip, #app-content.ekc-app .ekc-chip, #app-navigation.ekc-nav .ekc-nav__link, #app-content.ekc-app .ekc-danger-button',
			),
		].slice(0, 50);
		const undersized = [];
		for (const el of nodes) {
			const style = getComputedStyle(el);
			if (style.display === 'none' || style.visibility === 'hidden') continue;
			const details = el.closest('details');
			if (details && !details.open) continue;
			const rect = el.getBoundingClientRect();
			if (rect.width < 2 && rect.height < 2) continue;
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0);
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0);
			const isBar = rect.width >= 120;
			if (minH < 44 || (!isBar && minW < 44)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
					minHcss: style.minHeight,
				});
			}
		}
		return { ok: undersized.length === 0, undersized };
	});
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy();
}

/**
 * Private badge must use main-text (not primary) so custom accents stay AA.
 * @param {import('@playwright/test').Page} page
 */
async function assertPrivateBadgeThemeSafe(page) {
	const result = await page.evaluate(() => {
		const badge = document.querySelector('.ekc-badge--private');
		if (!badge) {
			return { skip: true };
		}
		const probe = document.createElement('span');
		probe.style.color = 'var(--color-main-text)';
		probe.style.position = 'absolute';
		probe.style.left = '-9999px';
		document.body.appendChild(probe);
		const primaryProbe = document.createElement('span');
		primaryProbe.style.color = 'var(--color-primary-element)';
		primaryProbe.style.position = 'absolute';
		primaryProbe.style.left = '-9999px';
		document.body.appendChild(primaryProbe);
		const badgeColor = getComputedStyle(badge).color;
		const mainText = getComputedStyle(probe).color;
		const primary = getComputedStyle(primaryProbe).color;
		probe.remove();
		primaryProbe.remove();
		return { skip: false, badgeColor, mainText, primary };
	});
	if (result.skip) {
		return;
	}
	expect(result.badgeColor, 'private badge color').toEqual(result.mainText);
	expect(result.badgeColor, 'private badge must not use primary ink').not.toEqual(result.primary);
}

/**
 * Keyboard focus ring must be visible on primary chrome.
 * @param {import('@playwright/test').Page} page
 */
async function assertFocusRingOnPrimary(page) {
	const btn = page.locator('#app-content.ekc-app .ekc-btn--primary, #app-content.ekc-app button.primary, #ekc-reload, #ekc-refresh').first();
	if (await btn.count() === 0) {
		return;
	}
	await btn.evaluate((el) => el.focus({ focusVisible: true }));
	const outline = await btn.evaluate((el) => {
		const cs = getComputedStyle(el);
		return {
			outlineWidth: cs.outlineWidth,
			outlineStyle: cs.outlineStyle,
			outlineColor: cs.outlineColor,
			boxShadow: cs.boxShadow,
		};
	});
	const hasRing = (parseFloat(outline.outlineWidth) >= 2 && outline.outlineStyle !== 'none')
		|| (outline.boxShadow && outline.boxShadow !== 'none' && !outline.boxShadow.startsWith('none'));
	expect(hasRing, `focus ring missing: ${JSON.stringify(outline)}`).toBeTruthy();
}

/**
 * Assert no raw hex colour literals outside intentional exceptions in computed app stylesheets.
 * (Feature CSS may use hex only as var() fallbacks — we verify live tokens are non-empty NC-derived.)
 * @param {import('@playwright/test').Page} page
 */
async function assertNoHardcodedShellColors(page) {
	const bad = await page.evaluate(() => {
		const el = document.querySelector('#app-content.ekc-app');
		if (!el) return ['missing #app-content.ekc-app'];
		const cs = getComputedStyle(el);
		const checks = {
			'--ekc-text': cs.getPropertyValue('--ekc-text').trim(),
			'--ekc-bg-card': cs.getPropertyValue('--ekc-bg-card').trim(),
			'--ekc-primary': cs.getPropertyValue('--ekc-primary').trim(),
		};
		const problems = [];
		for (const [k, v] of Object.entries(checks)) {
			if (!v) problems.push(`${k} empty`);
			if (/^#(fff|ffffff|000|000000)$/i.test(v) && k === '--ekc-primary') {
				problems.push(`${k} looks like hardcoded black/white: ${v}`);
			}
		}
		return problems;
	});
	expect(bad, bad.join('; ')).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	await page.locator('#ekc-toast-region, .toast, .toastify').evaluateAll((nodes) => {
		nodes.forEach((n) => n.remove());
	}).catch(() => {});
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('#ekc-toast-region')
		.analyze();
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 * @param {string} routeId
 */
async function gotoReady(page, url, routeId) {
	await gotoApp(page, url);
	await expect(page.locator('#ekc-main-content, #app-content.ekc-app').first()).toBeAttached({ timeout: 30_000 });
	if (routeId === 'offers') {
		await waitForOffersSettled(page);
	}
	await page.waitForFunction(() => {
		const body = getComputedStyle(document.body);
		return body.getPropertyValue('--color-main-text').trim() !== ''
			&& body.getPropertyValue('--color-main-background').trim() !== '';
	}, null, { timeout: 10_000 }).catch(() => {});
}

test.describe('EinkaufCheck theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');

				await page.setViewportSize({ width: 1280, height: 800 });
				await gotoReady(page, route.url, route.id);
				await setUserTheme(page, theme);
				await dismissOpenAppNavigation(page);
				if (route.id === 'offers') {
					await waitForOffersSettled(page);
				}
				await expect(page.locator(`body[data-theme-${theme}]`)).toBeAttached();
				await expect(page.locator('#app-content.ekc-app').first()).toBeVisible();

				await assertThemeTokensResolved(page);
				await assertNoHardcodedShellColors(page);
				await assertOfferSurfacesThemeAware(page, theme);
				await assertPrivateBadgeThemeSafe(page);
				await assertChromeTouchTargets(page);
				await assertFocusRingOnPrimary(page);
				await expectNoHorizontalOverflow(page, `${theme}/${route.id}@1280`);

				for (const vp of axeViewports) {
					await page.setViewportSize(vp);
					await dismissOpenAppNavigation(page);
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${vp.width}`);
					await runAxe(page, `${theme}/${route.id}@${vp.width}`);
				}
			});
		}
	}

	test('overflow matrix @ light (all breakpoints)', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await gotoReady(page, routes[0].url, 'offers');
		await setUserTheme(page, 'light');
		await waitForOffersSettled(page);
		for (const vp of overflowViewports) {
			await page.setViewportSize(vp);
			await dismissOpenAppNavigation(page);
			await expectNoHorizontalOverflow(page, `light@${vp.width}x${vp.height}`);
		}
	});

	test('filter grid collapses to one column ≤768', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await gotoReady(page, routes[0].url, 'offers');
		await setUserTheme(page, 'light');
		await waitForOffersSettled(page);
		await dismissOpenAppNavigation(page);

		await page.setViewportSize({ width: 700, height: 900 });
		const cols700 = await page.evaluate(() => {
			const grid = document.querySelector('#app-content.ekc-app .ekc-filter-grid, #app-content.ekc-app .ekc-page-grid');
			if (!grid) return null;
			return getComputedStyle(grid).gridTemplateColumns;
		});
		if (cols700 === null) {
			test.info().annotations.push({ type: 'note', description: 'no filter/page grid on this fixture' });
			return;
		}
		expect(cols700.split(' ').length, `grid@700=${cols700}`).toBe(1);

		await page.setViewportSize({ width: 1024, height: 800 });
		const cols1024 = await page.evaluate(() => {
			const grid = document.querySelector('#app-content.ekc-app .ekc-filter-grid');
			if (!grid) return null;
			return getComputedStyle(grid).gridTemplateColumns;
		});
		if (cols1024) {
			expect(cols1024.split(' ').length, `filter-grid@1024=${cols1024}`).toBeGreaterThanOrEqual(2);
		}
	});

	test('custom accent primary resolves into ekc-primary', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		const accent = '#c45c26';
		try {
			setAccentColor(accent);
			await page.setViewportSize({ width: 1280, height: 800 });
			// Settings page avoids offers GET rate limits after the theme matrix.
			await gotoReady(page, routes[2].url, 'settings');
			await setUserTheme(page, 'light');
			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('#app-content.ekc-app').first()).toBeVisible();
			await expect(page.locator('#ekc-page-title')).toBeVisible();
			const primary = await page.evaluate(() => {
				const el = document.querySelector('#app-content.ekc-app');
				const cs = el ? getComputedStyle(el) : getComputedStyle(document.body);
				return {
					ekc: cs.getPropertyValue('--ekc-primary').trim(),
					accent: cs.getPropertyValue('--ekc-accent').trim(),
					nc: getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim(),
				};
			});
			expect(primary.nc, 'NC primary after accent').not.toEqual('');
			expect(primary.ekc, 'ekc-primary after accent').not.toEqual('');
			const norm = (v) => v.replace(/\s+/g, '').toLowerCase();
			expect(norm(primary.ekc), 'ekc-primary equals NC primary-element').toEqual(norm(primary.nc));
			expect(norm(primary.accent), 'ekc-accent equals NC primary-element').toEqual(norm(primary.nc));
			await runAxe(page, 'accent-light-settings');
		} finally {
			resetAccentColor();
			await resetUserTheme(page).catch(() => {});
		}
	});

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage();
		try {
			if (process.env.E2E_USER) {
				await gotoReady(page, routes[0].url, 'offers');
				await resetUserTheme(page).catch(() => {});
			}
		} finally {
			await page.close();
			try {
				resetAccentColor();
			} catch {
				/* occ may be unavailable offline */
			}
		}
	});
});
