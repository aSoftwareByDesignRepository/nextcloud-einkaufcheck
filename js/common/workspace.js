/**
 * Shopping-space switcher + create (nav).
 */
(function () {
	'use strict';

	const APP = 'einkaufcheck';
	const api = window.EinkaufCheckApi;
	const msg = window.EinkaufCheckMessaging;
	const root = document.getElementById('app-content');
	if (!root || !api) {
		return;
	}

	const urls = JSON.parse(root.dataset.ekcUrls || '{}');
	const select = document.querySelector('[data-ekc-workspace-select]');
	const createBtn = document.querySelector('[data-ekc-workspace-create]');
	const hint = document.getElementById('ekc-workspace-switcher-hint');

	function idUrl(base, id) {
		return String(base || '').replace(/\/0(\/?$)/, '/' + id + '$1');
	}

	function withWorkspaceQuery(url, wid) {
		const join = String(url).indexOf('?') >= 0 ? '&' : '?';
		return url + join + 'workspaceId=' + encodeURIComponent(String(wid));
	}

	function currentPageUrl(wid) {
		const page = root.dataset.ekcPage || 'offers';
		const section = root.dataset.ekcSettingsSection || '';
		let base = (urls.pages && urls.pages.offers) || window.location.pathname;
		if (page === 'trends' && urls.pages && urls.pages.trends) {
			base = urls.pages.trends;
		} else if (page === 'settings') {
			const key = section === 'workspace' ? 'settingsWorkspace'
				: section === 'members' ? 'settingsMembers'
				: section === 'stores' ? 'settingsStores'
				: section === 'access' ? 'settingsAccess'
				: 'settings';
			base = (urls.pages && urls.pages[key]) || (urls.pages && urls.pages.settings) || base;
		}
		return withWorkspaceQuery(base, wid);
	}

	function renderHint(ws) {
		if (!hint || !ws) {
			return;
		}
		const privateMode = String(ws.privacyMode || '') === 'private';
		hint.innerHTML = '';
		const badge = document.createElement('span');
		badge.className = 'ekc-badge ' + (privateMode ? 'ekc-badge--private' : 'ekc-badge--neutral');
		badge.textContent = privateMode ? t(APP, 'Private') : t(APP, 'Shared');
		hint.appendChild(badge);
		hint.appendChild(document.createTextNode(' '));
		hint.appendChild(document.createTextNode(
			privateMode
				? t(APP, 'Only invited people can see this list.')
				: t(APP, 'Invited people and groups can see this list.'),
		));
	}

	function fillSelect(items, currentId) {
		if (!select) {
			return;
		}
		select.innerHTML = '';
		(items || []).forEach((ws) => {
			const opt = document.createElement('option');
			opt.value = String(ws.id);
			const privacy = String(ws.privacyMode || '') === 'private'
				? t(APP, 'Private')
				: t(APP, 'Shared');
			opt.textContent = (ws.name || t(APP, 'Shopping space')) + ' (' + privacy + ')';
			if (Number(ws.id) === Number(currentId)) {
				opt.selected = true;
			}
			select.appendChild(opt);
		});
	}

	async function boot() {
		const bootId = parseInt(root.dataset.ekcWorkspaceId || '0', 10) || 0;
		if (bootId > 0) {
			api.setWorkspaceId(bootId);
		}
		if (!urls.workspaces) {
			return;
		}
		try {
			const data = await api.get(urls.workspaces, { skipWorkspace: true });
			const items = (data && data.items) || [];
			const current = items.find((w) => Number(w.id) === bootId) || items[0] || null;
			if (current) {
				api.setWorkspaceId(current.id);
				root.dataset.ekcWorkspaceId = String(current.id);
				fillSelect(items, current.id);
				renderHint(current);
				createBtn && (createBtn.hidden = !(data.capabilities && data.capabilities.canCreatePrivateWorkspace));
			}
		} catch (e) {
			if (msg && typeof msg.error === 'function') {
				msg.error(e.message || t(APP, 'Could not load shopping spaces.'));
			}
		}
	}

	if (select) {
		select.addEventListener('change', () => {
			const wid = parseInt(select.value, 10) || 0;
			if (wid < 1) {
				return;
			}
			api.setWorkspaceId(wid);
			window.location.assign(currentPageUrl(wid));
		});
	}

	if (createBtn) {
		createBtn.addEventListener('click', async () => {
			const name = window.prompt(t(APP, 'Name for your new private list'), t(APP, 'My shopping list'));
			if (name === null) {
				return;
			}
			const trimmed = String(name).trim();
			if (trimmed === '') {
				return;
			}
			createBtn.disabled = true;
			try {
				const ws = await api.post(urls.workspacesCreate, {
					name: trimmed,
					privacyMode: 'private',
				}, { skipWorkspace: true });
				api.setWorkspaceId(ws.id);
				window.location.assign(currentPageUrl(ws.id));
			} catch (e) {
				if (msg && typeof msg.error === 'function') {
					msg.error(e.message || t(APP, 'Could not create shopping space.'));
				}
				createBtn.disabled = false;
			}
		});
	}

	boot();
})();
