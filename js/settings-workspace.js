/**
 * Settings: shopping-space privacy + people invite.
 */
(function () {
	'use strict';

	const APP = 'einkaufcheck';
	const root = document.getElementById('app-content');
	if (!root || !root.classList.contains('ekc-app')) {
		return;
	}
	const section = root.dataset.ekcSettingsSection || '';
	if (section !== 'workspace' && section !== 'members') {
		return;
	}

	const api = window.EinkaufCheckApi;
	const msg = window.EinkaufCheckMessaging;
	const urls = JSON.parse(root.dataset.ekcUrls || '{}');
	const workspaceId = parseInt(root.dataset.ekcWorkspaceId || '0', 10) || 0;

	function idUrl(base, id) {
		return String(base || '').replace(/\/0(\/?$)/, '/' + id + '$1');
	}

	function esc(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]));
	}

	function roleLabel(role) {
		if (role === 'manager') {
			return t(APP, 'Manager');
		}
		if (role === 'contributor') {
			return t(APP, 'Contributor');
		}
		return t(APP, 'Viewer');
	}

	/* ── Workspace form ─────────────────────────────────────── */
	const form = document.querySelector('[data-ekc-workspace-form]');
	if (form && section === 'workspace') {
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const fd = new FormData(form);
			const body = {
				name: String(fd.get('name') || '').trim(),
				privacyMode: String(fd.get('privacyMode') || 'private'),
			};
			const btn = form.querySelector('button[type="submit"]');
			if (btn) {
				btn.disabled = true;
			}
			try {
				await api.put(idUrl(urls.workspaceUpdateBase, workspaceId), body);
				if (msg && msg.success) {
					msg.success(t(APP, 'Shopping space saved.'));
				}
				window.location.reload();
			} catch (err) {
				if (msg && msg.error) {
					msg.error(err.message || t(APP, 'Could not save.'));
				}
				if (btn) {
					btn.disabled = false;
				}
			}
		});
	}

	document.querySelector('[data-ekc-action="workspace-delete"]')?.addEventListener('click', async () => {
		const ok = window.confirm(
			t(APP, 'Delete this shopping space and its list forever? This cannot be undone.'),
		);
		if (!ok) {
			return;
		}
		const btn = document.querySelector('[data-ekc-action="workspace-delete"]');
		if (btn) {
			btn.disabled = true;
		}
		try {
			await api.del(idUrl(urls.workspaceDeleteBase || urls.workspaceUpdateBase, workspaceId));
			if (msg && msg.success) {
				msg.success(t(APP, 'Shopping space deleted.'));
			}
			window.location.href = String(urls.appIndex || urls.settingsWorkspace || '/');
		} catch (err) {
			if (msg && msg.error) {
				msg.error(err.message || t(APP, 'Could not delete.'));
			}
			if (btn) {
				btn.disabled = false;
			}
		}
	});

	/* ── Members ────────────────────────────────────────────── */
	if (section !== 'members') {
		return;
	}

	const rowsEl = document.querySelector('[data-ekc-member-rows]');
	let selectedUser = null;
	let selectedGroup = null;
	let searchAbort = null;

	function setPicked(kind, item) {
		const wrap = document.querySelector(kind === 'user' ? '[data-ekc-member-selected-wrap]' : '[data-ekc-group-selected-wrap]');
		const label = document.querySelector(kind === 'user' ? '[data-ekc-member-selected]' : '[data-ekc-group-selected]');
		if (!wrap || !label) {
			return;
		}
		if (!item) {
			wrap.hidden = true;
			label.textContent = '';
			return;
		}
		wrap.hidden = false;
		label.textContent = item.displayName || item.id;
	}

	function bindPicker(kind) {
		const qInput = document.getElementById(kind === 'user' ? 'ekc-member-invite-q' : 'ekc-group-invite-q');
		const suggest = document.getElementById(kind === 'user' ? 'ekc-member-invite-suggest' : 'ekc-group-invite-suggest');
		const searchUrl = kind === 'user' ? urls.directoryUsers : urls.directoryGroups;
		if (!qInput || !suggest || !searchUrl) {
			return;
		}
		qInput.addEventListener('input', () => {
			const q = qInput.value.trim();
			suggest.innerHTML = '';
			suggest.hidden = true;
			if (q.length < 2) {
				return;
			}
			if (searchAbort) {
				searchAbort.abort();
			}
			searchAbort = new AbortController();
			window.setTimeout(async () => {
				if (qInput.value.trim() !== q) {
					return;
				}
				try {
					const data = await api.get(searchUrl + '?q=' + encodeURIComponent(q), { signal: searchAbort.signal });
					const items = (data && data.items) || [];
					suggest.innerHTML = '';
					items.slice(0, 8).forEach((item) => {
						const btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'ekc-entity-picker__option';
						const label = item.displayName || item.label || item.id || item.gid || '';
						btn.textContent = label;
						btn.addEventListener('click', () => {
							if (kind === 'user') {
								selectedUser = { id: item.id || item.uid, displayName: label };
								setPicked('user', selectedUser);
							} else {
								selectedGroup = { id: item.id || item.gid, displayName: label };
								setPicked('group', selectedGroup);
							}
							suggest.hidden = true;
							qInput.value = '';
						});
						suggest.appendChild(btn);
					});
					suggest.hidden = items.length === 0;
				} catch (e) {
					if (e && e.name === 'AbortError') {
						return;
					}
				}
			}, 200);
		});
	}

	bindPicker('user');
	bindPicker('group');

	document.querySelector('[data-ekc-action="member-invite-clear"]')?.addEventListener('click', () => {
		selectedUser = null;
		setPicked('user', null);
	});
	document.querySelector('[data-ekc-action="group-invite-clear"]')?.addEventListener('click', () => {
		selectedGroup = null;
		setPicked('group', null);
	});

	document.querySelector('[data-ekc-action="member-invite-submit"]')?.addEventListener('click', async () => {
		if (!selectedUser) {
			msg && msg.error && msg.error(t(APP, 'Pick a person first.'));
			return;
		}
		const role = document.querySelector('[data-ekc-member-invite-role]')?.value || 'contributor';
		try {
			await api.post(idUrl(urls.workspaceAddMemberBase, workspaceId), {
				userId: selectedUser.id,
				role,
			});
			selectedUser = null;
			setPicked('user', null);
			await loadMembers();
			msg && msg.success && msg.success(t(APP, 'Person added.'));
		} catch (e) {
			msg && msg.error && msg.error(e.message || t(APP, 'Could not add person.'));
		}
	});

	document.querySelector('[data-ekc-action="group-invite-submit"]')?.addEventListener('click', async () => {
		const panel = document.querySelector('[data-ekc-group-invite]');
		if (panel && panel.dataset.ekcPrivateLocked === '1') {
			return;
		}
		if (!selectedGroup) {
			msg && msg.error && msg.error(t(APP, 'Pick a group first.'));
			return;
		}
		const role = document.querySelector('[data-ekc-group-invite-role]')?.value || 'contributor';
		try {
			await api.post(idUrl(urls.workspaceAddGroupMemberBase, workspaceId), {
				groupId: selectedGroup.id,
				role,
			});
			selectedGroup = null;
			setPicked('group', null);
			await loadMembers();
			msg && msg.success && msg.success(t(APP, 'Group added.'));
		} catch (e) {
			msg && msg.error && msg.error(e.message || t(APP, 'Could not add group.'));
		}
	});

	async function loadMembers() {
		if (!rowsEl) {
			return;
		}
		rowsEl.innerHTML = '<tr><td colspan="4" class="ekc-loading">' + esc(t(APP, 'Loading…')) + '</td></tr>';
		try {
			const data = await api.get(idUrl(urls.workspaceMembersBase, workspaceId));
			const items = (data && data.items) || [];
			if (items.length === 0) {
				rowsEl.innerHTML = '<tr><td colspan="4">' + esc(t(APP, 'No members yet.')) + '</td></tr>';
				return;
			}
			rowsEl.innerHTML = '';
			items.forEach((row) => {
				const tr = document.createElement('tr');
				const isGroup = row.type === 'group';
				const name = esc(row.displayName || row.userId || row.groupId || '');
				const typeLabel = isGroup ? t(APP, 'Group') : t(APP, 'Person');
				const roleOpts = isGroup
					? ['viewer', 'contributor']
					: ['viewer', 'contributor', 'manager'];
				const options = roleOpts.map((r) => (
					'<option value="' + r + '"' + (r === row.role ? ' selected' : '') + '>' + esc(roleLabel(r)) + '</option>'
				)).join('');
				tr.innerHTML = (
					'<td>' + name + '</td>'
					+ '<td>' + esc(typeLabel) + '</td>'
					+ '<td><label class="ekc-sr-only" for="ekc-role-' + row.type + '-' + row.id + '">' + esc(t(APP, 'Role')) + '</label>'
					+ '<select class="ekc-input" id="ekc-role-' + row.type + '-' + row.id + '" data-ekc-member-role data-type="' + esc(row.type) + '" data-id="' + esc(String(row.id)) + '">' + options + '</select></td>'
					+ '<td><button type="button" class="button" data-ekc-member-remove data-type="' + esc(row.type) + '" data-id="' + esc(String(row.id)) + '">' + esc(t(APP, 'Remove')) + '</button></td>'
				);
				rowsEl.appendChild(tr);
			});
		} catch (e) {
			rowsEl.innerHTML = '<tr><td colspan="4">' + esc(e.message || t(APP, 'Could not load members.')) + '</td></tr>';
		}
	}

	rowsEl?.addEventListener('change', async (e) => {
		const el = e.target.closest('[data-ekc-member-role]');
		if (!el) {
			return;
		}
		const type = el.getAttribute('data-type');
		const id = parseInt(el.getAttribute('data-id') || '0', 10);
		const role = el.value;
		try {
			if (type === 'group') {
				await api.put(idUrl(urls.groupMemberUpdateBase, id), { role });
			} else {
				await api.put(idUrl(urls.memberUpdateBase, id), { role });
			}
			msg && msg.success && msg.success(t(APP, 'Role updated.'));
			await loadMembers();
		} catch (err) {
			msg && msg.error && msg.error(err.message || t(APP, 'Could not update role.'));
			await loadMembers();
		}
	});

	rowsEl?.addEventListener('click', async (e) => {
		const btn = e.target.closest('[data-ekc-member-remove]');
		if (!btn) {
			return;
		}
		const type = btn.getAttribute('data-type');
		const id = parseInt(btn.getAttribute('data-id') || '0', 10);
		if (!window.confirm(t(APP, 'Remove this member from the shopping space?'))) {
			return;
		}
		try {
			if (type === 'group') {
				await api.del(idUrl(urls.groupMemberDeleteBase, id));
			} else {
				await api.del(idUrl(urls.memberDeleteBase, id));
			}
			msg && msg.success && msg.success(t(APP, 'Member removed.'));
			await loadMembers();
		} catch (err) {
			msg && msg.error && msg.error(err.message || t(APP, 'Could not remove member.'));
		}
	});

	loadMembers();
})();
