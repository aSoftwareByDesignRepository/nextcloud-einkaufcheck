#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

function loadDotEnv(filePath) {
	if (!fs.existsSync(filePath)) {
		return;
	}
	for (const line of fs.readFileSync(filePath, 'utf8').split('\n')) {
		const trimmed = line.trim();
		if (trimmed === '' || trimmed.startsWith('#')) {
			continue;
		}
		const eq = trimmed.indexOf('=');
		if (eq === -1) {
			continue;
		}
		const key = trimmed.slice(0, eq).trim();
		let value = trimmed.slice(eq + 1).trim();
		if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
			value = value.slice(1, -1);
		}
		if (process.env[key] === undefined) {
			process.env[key] = value;
		}
	}
}

loadDotEnv(path.join(__dirname, '.env'));

module.exports = async function globalSetup() {
	const user = process.env.E2E_USER;
	const pass = process.env.E2E_PASS || process.env.E2E_PASSWORD;
	if (!user || !pass) {
		console.warn('[einkaufcheck:e2e] Skipping auth setup: set E2E_USER and E2E_PASS in e2e/.env');
		return;
	}

	const base = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
	const loginUrl = process.env.E2E_LOGIN_URL || `${base}/index.php/login`;
	const outputPath = process.env.E2E_STORAGE_STATE
		? path.resolve(process.env.E2E_STORAGE_STATE)
		: path.resolve(__dirname, '..', '.auth', 'storage-state.json');

	fs.mkdirSync(path.dirname(outputPath), { recursive: true });

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });

	const accountField = page.locator('input#user, input[name="user"]').first();
	const passwordField = page.locator('input#password, input[name="password"]').first();
	try {
		await accountField.waitFor({ state: 'visible', timeout: 30_000 });
	} catch {
		await browser.close();
		console.warn('[einkaufcheck:e2e] Login form not ready — tests may skip.');
		return;
	}
	await accountField.fill(user);
	await passwordField.fill(pass);
	await page.locator('button[type="submit"], input[type="submit"]').first().click();
	try {
		await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 });
	} catch {
		await browser.close();
		console.warn('[einkaufcheck:e2e] Login failed — check credentials and bruteforce delay.');
		return;
	}

	await context.storageState({ path: outputPath });
	await browser.close();
	process.env.E2E_STORAGE_STATE = outputPath;
	console.log('[einkaufcheck:e2e] storage state written:', outputPath);
};
