/**
 * EinkaufCheck page logic
 */
(function () {
	'use strict';

	const root = document.getElementById('app-content');
	if (!root || !root.classList.contains('ekc-app')) {
		return;
	}

	const APP = 'einkaufcheck';

	/**
	 * @param {string} text
	 * @param {string|number|boolean|Array<string|number|boolean|null|undefined>|Record<string, string|number|boolean|null|undefined>|null|undefined} args
	 * @returns {string}
	 */
	function applyPlaceholders(text, args) {
		if (args === undefined || args === null || typeof text !== 'string') {
			return text;
		}
		if (typeof args === 'object' && !Array.isArray(args)) {
			return text.replace(/\{([a-zA-Z0-9_]+)\}/g, function (match, name) {
				if (!Object.prototype.hasOwnProperty.call(args, name) || args[name] === undefined || args[name] === null) {
					return match;
				}
				return String(args[name]);
			});
		}
		const list = Array.isArray(args) ? args : [args];
		let i = 0;
		return text.replace(/%%|%\.\d+f|%s|%d/g, function (token) {
			if (token === '%%') {
				return '%';
			}
			if (i >= list.length) {
				return token;
			}
			const value = list[i++];
			if (value === undefined || value === null) {
				return '';
			}
			if (token === '%d') {
				return String(parseInt(String(value), 10) || 0);
			}
			return String(value);
		});
	}

	/** Translate with guaranteed %s / {name} substitution (NC core leaves %s literal). */
	function ekcTranslate(msg, args) {
		let text = typeof window.t === 'function' ? window.t(APP, msg) : String(msg);
		if (args != null) {
			text = applyPlaceholders(text, args);
		}
		return text;
	}

	const api = window.EinkaufCheckApi;
	const msg = window.EinkaufCheckMessaging;
	const urls = JSON.parse(root.dataset.ekcUrls || '{}');
	const page = root.dataset.ekcPage || 'offers';
	const isAppAdmin = root.dataset.ekcIsAppAdmin === '1';
	const canEditList = root.dataset.ekcCanEditList !== '0';
	const canManageSettings = root.dataset.ekcCanManageSettings === '1';
	const bootWorkspaceId = parseInt(root.dataset.ekcWorkspaceId || '0', 10) || 0;
	if (api && bootWorkspaceId > 0 && typeof api.setWorkspaceId === 'function') {
		api.setWorkspaceId(bootWorkspaceId);
	}

	function layoutModeStorageKeyEarly() {
		return 'ekc:layout:' + (bootWorkspaceId > 0 ? bootWorkspaceId : '0');
	}

	function readLayoutModeEarly() {
		try {
			const v = window.localStorage.getItem(layoutModeStorageKeyEarly());
			if (v === 'split' || v === 'compare') {
				return v;
			}
		} catch (_) {
			/* private mode */
		}
		return 'compare';
	}

	/** Apply compare-focus before paint when list should be hidden (prevents FOUC + broken grid). */
	function applyLayoutModeEarly() {
		if (page !== 'offers' || readLayoutModeEarly() !== 'compare') {
			return;
		}
		root.classList.add('ekc-app--compare-focus');
		const grid = document.getElementById('ekc-page-grid');
		if (grid) {
			grid.classList.add('ekc-page-grid--compare-focus');
		}
		const listCard = document.getElementById('ekc-list-card');
		if (listCard) {
			listCard.hidden = true;
			if ('inert' in listCard) {
				listCard.inert = true;
			}
		}
		const side = document.getElementById('ekc-side');
		if (side) {
			side.hidden = true;
			if ('inert' in side) {
				side.inert = true;
			}
		}
	}
	applyLayoutModeEarly();

	const state = {
		offers: [],
		list: [],
		listStore: 'all',
		watch: [],
		hits: [],
		gen: 0,
		busy: false,
		trendWeek: '',
		trendsPayload: null,
		weekCompare: null,
	};
	let offersAbort = null;

	const $ = (id) => document.getElementById(id);
	function assertCanEdit() {
		if (canEditList) {
			return true;
		}
		if (msg && typeof msg.error === 'function') {
			msg.error(t(APP, 'You can look at this list, but only contributors or managers can change it.'));
		} else if (msg && typeof msg.announce === 'function') {
			msg.announce(t(APP, 'You can look at this list, but only contributors or managers can change it.'), 'error');
		}
		return false;
	}
	function assertCanManageSettings() {
		if (canManageSettings) {
			return true;
		}
		if (msg && typeof msg.error === 'function') {
			msg.error(t(APP, 'Only managers can change shopping-space settings.'));
		} else if (msg && typeof msg.announce === 'function') {
			msg.announce(t(APP, 'Only managers can change shopping-space settings.'), 'error');
		}
		return false;
	}
	function applyViewerChrome() {
		if (!canEditList) {
			root.classList.add('ekc-app--readonly');
		}
		if (!canManageSettings) {
			root.classList.add('ekc-app--settings-locked');
			const pref = document.getElementById('ekc-pref-form');
			if (pref) {
				pref.querySelectorAll('input, select, button').forEach((el) => {
					el.disabled = true;
				});
			}
			// Postcode/week are space settings — contributors refresh the saved
			// PLZ only (server-enforced). Lock the fields so the UI matches.
			['ekc-plz', 'ekc-week', 'ekc-settings-plz', 'ekc-settings-week'].forEach((id) => {
				const el = $(id);
				if (el) {
					el.disabled = true;
					el.setAttribute('aria-readonly', 'true');
				}
			});
			const help = $('ekc-plz-help');
			if (help) {
				help.textContent = t(APP, 'Only managers can change the postcode. Refresh still updates this space’s saved offers.');
			}
			const intro = document.querySelector('.ekc-filter-panel__intro');
			if (intro && (page === 'offers' || page === 'trends')) {
				intro.textContent = t(APP, 'Search and filter this space’s offers. Only managers can change the postcode or week.');
			}
		}
	}
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]));
	const euro = (n) => (n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €');
	const idUrl = (base, id) => String(base || '').replace(/\/0(\/?$)/, '/' + id + '$1');

	function unitSuffix(kind) {
		if (kind === 'kg') {
			return '/kg';
		}
		if (kind === 'l') {
			return '/l';
		}
		if (kind === 'pc') {
			return '/' + t(APP, 'pc');
		}
		return '';
	}

	function formatUnitPrice(o) {
		if (o == null) {
			return '';
		}
		if (o.unit_price != null && o.unit_kind) {
			return euro(o.unit_price).replace(' €', '') + unitSuffix(o.unit_kind);
		}
		if (o.unit_label) {
			return String(o.unit_label);
		}
		if (o.per_kg != null) {
			return euro(o.per_kg).replace(' €', '') + '/kg';
		}
		if (o.per_l != null) {
			return euro(o.per_l).replace(' €', '') + '/l';
		}
		return '';
	}

	function unitSortKey(o) {
		if (o.unit_price != null && isFinite(Number(o.unit_price))) {
			return Number(o.unit_price);
		}
		if (o.per_kg != null && isFinite(Number(o.per_kg))) {
			return Number(o.per_kg);
		}
		return null;
	}

	function hasUnitPrice(o) {
		return formatUnitPrice(o) !== '';
	}

	/** True when next week’s metric is meaningfully cheaper than this week’s. */
	function isCheaperNextWeek(o, viewingWeek) {
		const tip = o && o.week_tip;
		if (!tip || (tip.verdict !== 'cheaper_later' && tip.verdict !== 'cheaper_now')) {
			return false;
		}
		const week = viewingWeek === 'next' ? 'next' : 'current';
		if (week === 'current') {
			return tip.verdict === 'cheaper_later' && tip.other_week === 'next';
		}
		return tip.verdict === 'cheaper_now';
	}

	function weekTipMeta(o, viewingWeek) {
		const tip = o && o.week_tip;
		if (!tip || (tip.verdict !== 'cheaper_later' && tip.verdict !== 'cheaper_now')) {
			return null;
		}
		const week = viewingWeek === 'next' ? 'next' : 'current';
		let label;
		let kind;
		if (tip.verdict === 'cheaper_later') {
			label = tip.other_week === 'next' ? t(APP, 'Cheaper next week') : t(APP, 'Cheaper this week');
			kind = tip.other_week === 'next' ? 'wait' : 'buy';
		} else {
			label = week === 'next' ? t(APP, 'Cheaper next week') : t(APP, 'Cheaper this week');
			kind = 'buy';
		}
		const saveBit = tip.saving != null
			? ' · ' + t(APP, 'saves about %s', [euro(tip.saving).replace(' €', '') + unitSuffix(tip.other_unit_kind || o.unit_kind || '')])
			: '';
		return { label, kind, aria: label + saveBit };
	}

	function syncOffersTitle() {
		const el = $('ekc-offers-title');
		if (!el) {
			return;
		}
		const week = $('ekc-week')?.value || 'current';
		el.textContent = week === 'next' ? t(APP, 'Next week’s offers') : t(APP, 'This week’s offers');
	}

	function updateWeekCompareHint() {
		const el = $('ekc-week-compare-hint');
		if (!el) {
			return;
		}
		const wc = state.weekCompare;
		if (!wc || wc.other_cache === 'hit') {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		const other = wc.other_week === 'next' ? t(APP, 'Next week') : t(APP, 'This week');
		el.hidden = false;
		el.textContent = t(APP, 'Load %s too (New prices) to see if waiting saves money. Lidl Plus is always this week’s list.', [other]);
	}

	function selectedCat() {
		const el = document.querySelector('input[name="ekc-cat"]:checked');
		return el ? el.value : 'food';
	}

	function categoryInFilter(offerCat, filter) {
		if (filter === 'all') {
			return true;
		}
		if (filter === 'food') {
			return offerCat === 'food' || offerCat === 'produce';
		}
		return offerCat === filter;
	}

	function foldSearch(value) {
		return String(value || '')
			.toLocaleLowerCase('de-DE')
			.replace(/ä/g, 'a')
			.replace(/ö/g, 'o')
			.replace(/ü/g, 'u')
			.replace(/ß/g, 'ss');
	}

	function offerMatchesQuery(offer, query) {
		const needle = foldSearch(query).trim();
		if (needle === '') {
			return true;
		}
		const hay = foldSearch([offer.brand, offer.name, offer.pack].filter(Boolean).join(' '));
		return hay.includes(needle);
	}

	function hitKeys() {
		const keys = new Set();
		for (const h of state.hits) {
			const o = h.offer || {};
			keys.add([o.store, o.brand, o.name, o.price].join('|'));
		}
		return keys;
	}

	function setOffersBusy(busy) {
		state.busy = busy;
		['ekc-reload', 'ekc-refresh', 'ekc-retry'].forEach((id) => {
			const el = $(id);
			if (el) {
				el.disabled = busy;
			}
		});
		const st = $('ekc-fetch-status');
		if (st) {
			st.hidden = !busy;
			st.textContent = busy
				? t(APP, 'Loading prices from the stores. This can take up to a minute.')
				: '';
		}
	}

	function validPlz(value) {
		return /^\d{5}$/.test(String(value || '').trim());
	}

	function markPlzValidity() {
		const input = $('ekc-plz') || $('ekc-settings-plz');
		if (!input) {
			return true;
		}
		const ok = validPlz(input.value);
		input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		return ok;
	}

	function picturesEnabled() {
		return !!($('ekc-show-images')?.checked || $('ekc-settings-show-images')?.checked);
	}

	function applyShowImagesPref(on) {
		['ekc-show-images', 'ekc-settings-show-images'].forEach((id) => {
			const el = $(id);
			if (el) {
				el.checked = !!on;
			}
		});
	}

	function safeImageSrc(url) {
		const u = String(url || '').trim();
		if (u === '' || u.length > 2000) {
			return '';
		}
		if (/[\x00-\x1f\x7f\\@]/.test(u)) {
			return '';
		}
		if (!/^https:\/\/[a-z0-9.-]+(?::443)?(?:[/?#].*)?$/i.test(u)) {
			return '';
		}
		return u;
	}

	function attachThumb(parent, url) {
		if (!parent || !picturesEnabled()) {
			return;
		}
		const src = safeImageSrc(url);
		if (!src) {
			return;
		}
		const img = document.createElement('img');
		img.className = 'ekc-thumb';
		img.alt = '';
		img.width = 48;
		img.height = 48;
		img.decoding = 'async';
		img.loading = 'lazy';
		img.referrerPolicy = 'no-referrer';
		img.addEventListener('error', () => {
			img.remove();
		});
		img.src = src;
		parent.insertBefore(img, parent.firstChild);
	}

	function updateImagesHint() {
		const hint = $('ekc-images-hint');
		if (!hint) {
			return;
		}
		if (!picturesEnabled()) {
			hint.hidden = true;
			return;
		}
		let rows = state.offers;
		if (page === 'trends' && state.trendsPayload) {
			rows = [].concat(
				state.trendsPayload.staples || [],
				state.trendsPayload.cheap_now || [],
				state.trendsPayload.search || [],
			);
		}
		const hasAny = rows.some((o) => safeImageSrc(o && o.image));
		hint.hidden = rows.length === 0 || hasAny;
	}

	let imagePrefSeq = 0;

	async function persistShowImages(on) {
		const seq = ++imagePrefSeq;
		const plz = (($('ekc-plz') || $('ekc-settings-plz'))?.value || '').trim();
		const week = ($('ekc-week') || $('ekc-settings-week'))?.value || 'current';
		if (!validPlz(plz)) {
			msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
			throw new Error('invalid_plz');
		}
		await api.put(urls.settingsSave, { plz, week, show_images: !!on });
		if (seq !== imagePrefSeq) {
			return;
		}
	}

	function bindShowImages() {
		const boxes = ['ekc-show-images', 'ekc-settings-show-images']
			.map((id) => $(id))
			.filter(Boolean);
		boxes.forEach((box) => {
			box.addEventListener('change', async () => {
				const on = !!box.checked;
				applyShowImagesPref(on);
				applyOffers();
				renderTrendsView(state.trendsPayload);
				updateImagesHint();
				try {
					await persistShowImages(on);
				} catch (err) {
					if (err && err.message === 'invalid_plz') {
						applyShowImagesPref(!on);
						applyOffers();
						renderTrendsView(state.trendsPayload);
						updateImagesHint();
						return;
					}
					msg.handleApiError(err);
					applyShowImagesPref(!on);
					applyOffers();
					renderTrendsView(state.trendsPayload);
					updateImagesHint();
				}
			});
		});
	}

	function applyOffers() {
		if (!$('ekc-rows')) {
			return;
		}
		const q = ($('ekc-q')?.value || '').trim();
		const store = $('ekc-store')?.value || 'all';
		const cat = selectedCat();
		const onlyUnit = $('ekc-only-kg')?.checked;
		const onlyWait = $('ekc-only-wait')?.checked;
		const onlyMatch = $('ekc-only-match')?.checked;
		const watched = hitKeys();
		const viewingWeek = $('ekc-week')?.value || 'current';
		let rows = state.offers.slice();
		if (store !== 'all') {
			rows = rows.filter((o) => o.store === store);
		}
		if (onlyUnit) {
			rows = rows.filter((o) => hasUnitPrice(o));
		}
		if (onlyWait) {
			rows = rows.filter((o) => isCheaperNextWeek(o, viewingWeek));
		}
		if (onlyMatch) {
			rows = rows.filter((o) => (o.match_stores || 1) > 1);
		}
		if (q) {
			rows = rows.filter((o) => offerMatchesQuery(o, q));
		}
		if (cat !== 'all') {
			const inCat = rows.filter((o) => categoryInFilter(o.category, cat));
			if (q && cat === 'food' && inCat.length === 0 && rows.length > 0) {
				const all = document.querySelector('input[name="ekc-cat"][value="all"]');
				if (all && !all.checked) {
					all.checked = true;
					msg.announce(t(APP, 'Search looks in every category.'));
				}
			} else {
				rows = inCat;
			}
		}
		rows.sort((a, b) => {
			const wa = watched.has([a.store, a.brand, a.name, a.price].join('|')) ? 0 : 1;
			const wb = watched.has([b.store, b.brand, b.name, b.price].join('|')) ? 0 : 1;
			if (wa !== wb) {
				return wa - wb;
			}
			const ma = (a.match_stores || 1) > 1 ? 0 : 1;
			const mb = (b.match_stores || 1) > 1 ? 0 : 1;
			if (ma !== mb) {
				return ma - mb;
			}
			const ua = unitSortKey(a);
			const ub = unitSortKey(b);
			if (ua == null && ub == null) {
				return a.name.localeCompare(b.name);
			}
			if (ua == null) {
				return 1;
			}
			if (ub == null) {
				return -1;
			}
			return ua - ub;
		});
		renderRows(rows, watched, viewingWeek);
	}

	function renderRows(rows, watched, viewingWeek) {
		const tb = $('ekc-rows');
		const empty = $('ekc-empty');
		tb.replaceChildren();
		empty.hidden = rows.length > 0;
		$('ekc-offers-table').hidden = rows.length === 0;
		if (!rows.length) {
			const countEl = $('ekc-offers-count');
			if (countEl) {
				countEl.textContent = t(APP, 'No offers match the filters.');
			}
			updateImagesHint();
			return;
		}
		const countEl = $('ekc-offers-count');
		if (countEl) {
			countEl.textContent = ekcTranslate('%s offers shown.', [String(rows.length)]);
		}
		const unitHeader = t(APP, 'Unit price');
		for (const o of rows) {
			const tr = document.createElement('tr');
			const isWatch = watched.has([o.store, o.brand, o.name, o.price].join('|'));
			if ((o.match_stores || 1) > 1) {
				tr.classList.add('overlap');
			}
			if (isWatch) {
				tr.classList.add('watch-hit');
			}
			const storeClass = o.store === 'ALDI Nord' ? 'aldi' : 'lidl';
			const matchBadge = (o.match_stores || 1) > 1
				? ` <span class="ekc-pill match">${esc(o.match_stores)} ${esc(t(APP, 'stores'))}</span>${o.is_cheapest ? ' <span class="ekc-pill win">' + esc(t(APP, 'cheapest')) + '</span>' : ''}`
				: '';
			const alertBadge = isWatch ? ' <span class="ekc-pill alert">' + esc(t(APP, 'Staple')) + '</span>' : '';
			const tipMeta = weekTipMeta(o, viewingWeek || $('ekc-week')?.value || 'current');
			const tipBadge = tipMeta
				? ` <span class="ekc-pill ${esc(tipMeta.kind === 'wait' ? 'wait' : 'buy')}" title="${esc(tipMeta.aria)}" aria-label="${esc(tipMeta.aria)}">${esc(tipMeta.label)}</span>`
				: '';
			const unitText = formatUnitPrice(o);
			const unitHtml = unitText
				? `<span class="ekc-pill kg">${esc(unitText)}</span>`
				: '—';
			tr.innerHTML = `
				<td class="ekc-row-actions" data-cell="${esc(t(APP, 'Actions'))}">
					<button type="button" class="ekc-btn ekc-btn--secondary add" aria-label="${esc(t(APP, 'Add to shopping list') + ': ' + (o.brand ? o.brand + ' ' : '') + o.name)}">+</button>
					<button type="button" class="ekc-btn ekc-btn--ghost watch" aria-label="${esc(t(APP, 'Watch this staple') + ': ' + o.name)}">${esc(t(APP, 'Watch'))}</button>
				</td>
				<td data-cell="${esc(t(APP, 'Store'))}"><span class="ekc-pill ${storeClass}">${esc(o.store)}</span></td>
				<td data-cell="${esc(t(APP, 'Brand'))}">${esc(o.brand)}</td>
				<td class="ekc-product" data-cell="${esc(t(APP, 'Product'))}"><div class="ekc-product__body"><span class="ekc-product__text">${esc(o.name)}${matchBadge}${alertBadge}${tipBadge}</span></div></td>
				<td data-cell="${esc(t(APP, 'Pack'))}">${esc(o.pack)}</td>
				<td class="num" data-cell="${esc(t(APP, 'Price'))}">${esc(euro(o.price))}</td>
				<td class="num" data-cell="${esc(unitHeader)}">${unitHtml}</td>`;
			tr.querySelector('.add').addEventListener('click', () => addToList(o));
			tr.querySelector('.watch').addEventListener('click', () => fillWatchFromOffer(o));
			attachThumb(tr.querySelector('.ekc-product__body'), o.image);
			tb.appendChild(tr);
		}
		updateImagesHint();
	}

	function renderAlerts() {
		const box = $('ekc-alerts');
		if (!box) {
			return;
		}
		if (!state.hits.length) {
			box.hidden = true;
			box.replaceChildren();
			return;
		}
		box.hidden = false;
		box.innerHTML = '<p class="ekc-callout__title">' + esc(t(APP, 'Staples on offer now')) + '</p>' + state.hits.map((h) => {
			const o = h.offer || {};
			return `<p><strong>${esc(h.query)}</strong> → ${esc(o.store)}: ${esc(o.brand ? o.brand + ' ' : '')}${esc(o.name)} · ${esc(euro(o.price))}${hasUnitPrice(o) ? ' · ' + esc(formatUnitPrice(o)) : ''}</p>`;
		}).join('');
	}

	const listBusy = new Set();
	const addBusy = new Set();
	let listClearBusy = false;
	let listJumpObserver = null;
	let layoutMode = 'compare';

	function currentWorkspaceId() {
		if (api && typeof api.getWorkspaceId === 'function') {
			return api.getWorkspaceId();
		}
		return bootWorkspaceId;
	}

	function layoutModeStorageKey() {
		const id = currentWorkspaceId();
		return 'ekc:layout:' + (id > 0 ? id : '0');
	}

	function readLayoutMode() {
		try {
			const v = window.localStorage.getItem(layoutModeStorageKey());
			if (v === 'split' || v === 'compare') {
				return v;
			}
		} catch (_) {
			/* private mode / disabled storage */
		}
		return 'compare';
	}

	function persistLayoutMode(mode) {
		try {
			window.localStorage.setItem(layoutModeStorageKey(), mode);
		} catch (_) {
			/* private mode */
		}
	}

	function syncLayoutToggleButtons() {
		const compare = layoutMode === 'compare';
		$('ekc-layout-compare')?.setAttribute('aria-pressed', compare ? 'true' : 'false');
		$('ekc-layout-split')?.setAttribute('aria-pressed', compare ? 'false' : 'true');
	}

	function openCompareSectionIfReady() {
		const wrap = $('ekc-compares-wrap');
		if (wrap && !wrap.hidden) {
			wrap.open = true;
		}
	}

	function applyLayoutMode(mode, options = {}) {
		const compare = mode === 'compare';
		layoutMode = compare ? 'compare' : 'split';
		root.classList.toggle('ekc-app--compare-focus', compare);
		const grid = $('ekc-page-grid');
		if (grid) {
			grid.classList.toggle('ekc-page-grid--compare-focus', compare);
		}
		const side = $('ekc-side');
		const listCard = $('ekc-list-card');
		const focusTarget = $('ekc-layout-compare');
		if (side) {
			if (compare) {
				const active = document.activeElement;
				if (active && side.contains(active)) {
					focusTarget?.focus();
				}
				side.hidden = true;
				if ('inert' in side) {
					side.inert = true;
				}
			} else {
				side.hidden = false;
				if ('inert' in side) {
					side.inert = false;
				}
			}
		}
		if (listCard) {
			listCard.hidden = compare;
			if ('inert' in listCard) {
				listCard.inert = compare;
			}
		}
		syncLayoutToggleButtons();
		if (compare) {
			openCompareSectionIfReady();
		}
		updateListJumpForLayout();
		if (!options.skipPersist) {
			persistLayoutMode(layoutMode);
		}
	}

	function updateListJumpForLayout() {
		const jump = $('ekc-list-jump');
		if (!jump) {
			return;
		}
		if (layoutMode === 'compare') {
			if (listJumpObserver) {
				listJumpObserver.disconnect();
				listJumpObserver = null;
			}
			jump.hidden = state.list.length === 0;
		} else {
			setupListJumpObserver();
		}
	}

	function bindLayoutToggle() {
		bindLayoutHideList();
		const toolbar = $('ekc-layout-toggle');
		if (!toolbar || toolbar.dataset.ekcLayoutWired === '1') {
			return;
		}
		toolbar.dataset.ekcLayoutWired = '1';
		$('ekc-layout-compare')?.addEventListener('click', () => {
			const wasSplit = layoutMode !== 'compare';
			applyLayoutMode('compare');
			if (wasSplit) {
				msg.announce(ekcTranslate('Shopping list and staples hidden. Tap Show lists or the button at the bottom to open them.'), 'polite');
			}
		});
		$('ekc-layout-split')?.addEventListener('click', () => {
			const wasCompare = layoutMode !== 'split';
			applyLayoutMode('split');
			if (wasCompare) {
				msg.announce(ekcTranslate('Shopping list and staples shown beside prices.'), 'polite');
			}
		});
	}

	function bindLayoutHideList() {
		const btn = $('ekc-layout-hide-from-side');
		if (!btn || btn.dataset.ekcLayoutHideWired === '1') {
			return;
		}
		btn.dataset.ekcLayoutHideWired = '1';
		btn.addEventListener('click', () => {
			const wasSplit = layoutMode !== 'compare';
			applyLayoutMode('compare');
			$('ekc-layout-compare')?.focus();
			if (wasSplit) {
				msg.announce(ekcTranslate('Shopping list and staples hidden. Tap Show lists or the button at the bottom to open them.'), 'polite');
			}
		});
	}

	function selectedListStore() {
		const el = document.querySelector('input[name="ekc-list-store"]:checked');
		const v = el ? el.value : (state.listStore || 'all');
		if (v === 'ALDI Nord' || v === 'Lidl') {
			state.listStore = v;
			return v;
		}
		state.listStore = 'all';
		return 'all';
	}

	function countForStore(store) {
		if (store === 'all') {
			return state.list.length;
		}
		return state.list.filter((item) => item.store === store).length;
	}

	function visibleListItems() {
		const store = selectedListStore();
		if (store === 'all') {
			return state.list.slice();
		}
		return state.list.filter((item) => item.store === store);
	}

	function withStoreQuery(base) {
		const store = selectedListStore();
		const url = String(base || '');
		if (!store || store === 'all' || url === '') {
			return url;
		}
		return url + (url.includes('?') ? '&' : '?') + 'store=' + encodeURIComponent(store);
	}

	function listExportUrl() {
		return withStoreQuery(urls.listExport);
	}

	function listClearUrl() {
		return withStoreQuery(urls.listClear);
	}

	function emptyListLabel() {
		const store = selectedListStore();
		if (store === 'ALDI Nord') {
			return t(APP, 'Empty ALDI list');
		}
		if (store === 'Lidl') {
			return t(APP, 'Empty Lidl list');
		}
		return t(APP, 'Empty list');
	}

	function emptyListConfirm() {
		const store = selectedListStore();
		if (store === 'ALDI Nord') {
			return t(APP, 'Empty only the ALDI items? Lidl stays on the list.');
		}
		if (store === 'Lidl') {
			return t(APP, 'Empty only the Lidl items? ALDI stays on the list.');
		}
		return t(APP, 'Empty the shopping list?');
	}

	function emptiedListMessage() {
		const store = selectedListStore();
		if (store === 'ALDI Nord') {
			return t(APP, 'ALDI list emptied.');
		}
		if (store === 'Lidl') {
			return t(APP, 'Lidl list emptied.');
		}
		return t(APP, 'Shopping list emptied.');
	}

	function updateListJump() {
		const jump = $('ekc-list-jump');
		if (!jump) {
			return;
		}
		const n = state.list.length;
		jump.textContent = n ? t(APP, 'Shopping list') + ' (' + n + ')' : t(APP, 'Shopping list');
		jump.setAttribute('aria-label', n
			? t(APP, 'Jump to shopping list, %s items', [String(n)])
			: t(APP, 'Jump to shopping list'));
		if (layoutMode === 'compare') {
			jump.hidden = n === 0;
		}
	}

	function bindListJumpClick() {
		const jump = $('ekc-list-jump');
		const card = $('ekc-list-card');
		if (!jump || !card || jump.dataset.ekcListJumpWired === '1') {
			return;
		}
		jump.dataset.ekcListJumpWired = '1';
		jump.addEventListener('click', (e) => {
			e.preventDefault();
			const revealList = () => {
				const cardEl = $('ekc-list-card');
				if (!cardEl) {
					return;
				}
				cardEl.focus({ preventScroll: true });
				cardEl.scrollIntoView({
					block: 'start',
					behavior: (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) ? 'auto' : 'smooth',
				});
			};
			if (layoutMode === 'compare') {
				applyLayoutMode('split');
				msg.announce(ekcTranslate('Shopping list and staples shown beside prices.'), 'polite');
				window.requestAnimationFrame(revealList);
				return;
			}
			revealList();
		});
	}

	function setupListJumpObserver() {
		const jump = $('ekc-list-jump');
		const card = $('ekc-list-card');
		if (!jump || !card || layoutMode === 'compare') {
			return;
		}
		if (typeof IntersectionObserver !== 'function') {
			return;
		}
		const scroller = document.getElementById('app-content');
		if (listJumpObserver) {
			listJumpObserver.disconnect();
		}
		listJumpObserver = new IntersectionObserver((entries) => {
			jump.hidden = entries.some((entry) => entry.isIntersecting);
		}, { root: scroller || null, threshold: 0.12 });
		listJumpObserver.observe(card);
	}

	function bindListJump() {
		bindListJumpClick();
		setupListJumpObserver();
	}

	function renderList() {
		const ul = $('ekc-list-items');
		if (!ul) {
			return;
		}
		ul.replaceChildren();
		const visible = visibleListItems();
		const total = state.list.length;
		const store = selectedListStore();
		const countEl = $('ekc-list-count');
		if (countEl) {
			if (store !== 'all' && total) {
				countEl.textContent = '(' + visible.length + ' · ' + store + ')';
			} else {
				countEl.textContent = '(' + visible.length + ')';
			}
		}
		document.querySelectorAll('.ekc-list-store-count').forEach((span) => {
			const key = span.getAttribute('data-ekc-store') || 'all';
			span.textContent = ' (' + countForStore(key) + ')';
		});
		const printStore = $('ekc-list-print-store');
		if (printStore) {
			printStore.textContent = store === 'all' ? t(APP, 'Both stores') : store;
		}
		const empty = $('ekc-list-empty');
		if (empty) {
			empty.hidden = visible.length > 0;
			empty.textContent = total === 0
				? t(APP, 'Your list is empty. Tap + on an offer.')
				: t(APP, 'Nothing for this store. Pick ALDI Nord, Lidl, or Both stores.');
		}
		['ekc-wa', 'ekc-copy', 'ekc-csv', 'ekc-print'].forEach((id) => {
			const btn = $(id);
			if (btn) {
				btn.disabled = visible.length === 0;
			}
		});
		const clearBtn = $('ekc-clear');
		if (clearBtn) {
			clearBtn.disabled = visible.length === 0;
			clearBtn.textContent = emptyListLabel();
		}
		updateListJump();
		for (const item of visible) {
			const li = document.createElement('li');
			if (item.checked) {
				li.classList.add('done');
			}
			const title = (item.qty + '× ' + (item.brand ? item.brand + ' ' : '') + item.name);
			li.innerHTML = `
				<input type="checkbox" ${item.checked ? 'checked' : ''} aria-label="${esc(t(APP, 'Bought') + ': ' + title)}" />
				<div class="ekc-item-main">
					<div class="ekc-item-title">${esc(item.qty)}× ${esc(item.brand ? item.brand + ' ' : '')}${esc(item.name)}</div>
					<div class="ekc-item-meta">${esc(item.store)}${item.pack ? ' · ' + esc(item.pack) : ''}${item.price != null ? ' · ' + esc(euro(item.price)) : ''}</div>
				</div>
				<div class="ekc-qty" role="group" aria-label="${esc(t(APP, 'Quantity'))}">
					<button type="button" class="ekc-btn ekc-btn--ghost ekc-qty__btn" data-delta="-1" aria-label="${esc(t(APP, 'One less'))}">−</button>
					<span class="ekc-qty__value" aria-hidden="true">${esc(item.qty)}</span>
					<button type="button" class="ekc-btn ekc-btn--ghost ekc-qty__btn" data-delta="1" aria-label="${esc(t(APP, 'One more'))}">+</button>
				</div>
				<button type="button" class="ekc-btn ekc-btn--ghost ekc-item-remove" aria-label="${esc(t(APP, 'Remove') + ': ' + title)}">×</button>`;
			li.querySelector('input').addEventListener('change', async (e) => {
				if (!assertCanEdit()) {
					e.target.checked = item.checked;
					return;
				}
				if (listBusy.has(item.id)) {
					e.target.checked = item.checked;
					return;
				}
				listBusy.add(item.id);
				try {
					await api.put(idUrl(urls.listUpdateBase, item.id), { checked: e.target.checked, qty: item.qty, note: item.note || '' });
					await loadList();
				} catch (err) {
					msg.handleApiError(err);
					await loadList();
				} finally {
					listBusy.delete(item.id);
				}
			});
			li.querySelectorAll('.ekc-qty__btn').forEach((btn) => {
				btn.addEventListener('click', async () => {
					if (!assertCanEdit()) {
						return;
					}
					if (listBusy.has(item.id)) {
						return;
					}
					const next = item.qty + Number(btn.getAttribute('data-delta'));
					if (next < 1 || next > 99) {
						msg.announce(t(APP, 'Quantity must be between 1 and 99.'), 'warning');
						return;
					}
					listBusy.add(item.id);
					try {
						await api.put(idUrl(urls.listUpdateBase, item.id), { qty: next, checked: item.checked, note: item.note || '' });
						await loadList();
					} catch (err) {
						msg.handleApiError(err);
					} finally {
						listBusy.delete(item.id);
					}
				});
			});
			li.querySelector('.ekc-item-remove').addEventListener('click', async () => {
				if (!assertCanEdit()) {
					return;
				}
				if (listBusy.has(item.id)) {
					return;
				}
				listBusy.add(item.id);
				try {
					await api.del(idUrl(urls.listDeleteBase, item.id));
					await loadList();
					msg.announce(t(APP, 'Removed from your shopping list.'), 'success');
				} catch (err) {
					msg.handleApiError(err);
				} finally {
					listBusy.delete(item.id);
				}
			});
			ul.appendChild(li);
		}
	}

	function renderWatch() {
		const ul = $('ekc-watch-items');
		if (!ul) {
			return;
		}
		ul.replaceChildren();
		const empty = $('ekc-watch-empty');
		if (empty) {
			empty.hidden = state.watch.length > 0;
		}
		for (const w of state.watch) {
			const caps = [];
			if (w.max_price != null) {
				caps.push('≤ ' + euro(w.max_price));
			}
			if (w.max_per_kg != null) {
				caps.push('≤ ' + euro(w.max_per_kg) + '/kg');
			}
			if (w.store) {
				caps.push(w.store);
			}
			const li = document.createElement('li');
			if (!w.enabled) {
				li.classList.add('is-paused');
			}
			li.innerHTML = `
				<input type="checkbox" ${w.enabled ? 'checked' : ''} aria-label="${esc(t(APP, 'Notify me') + ': ' + w.query)}" />
				<div class="ekc-item-main">
					<div class="ekc-item-title">${esc(w.query)}${w.enabled ? '' : ' (' + esc(t(APP, 'off')) + ')'}</div>
					<div class="ekc-item-meta">${caps.length ? esc(caps.join(' · ')) : esc(t(APP, 'No price cap'))}</div>
				</div>
				<button type="button" class="ekc-btn ekc-btn--ghost ekc-item-remove" aria-label="${esc(t(APP, 'Remove') + ': ' + w.query)}">×</button>`;
			li.querySelector('input').addEventListener('change', async (e) => {
				try {
					await api.put(idUrl(urls.watchUpdateBase, w.id), { enabled: e.target.checked });
					await loadWatch();
					await loadOffers(false, { silent: true });
					msg.announce(e.target.checked ? t(APP, 'We will notify you when this is on offer.') : t(APP, 'Notifications paused for this staple.'), 'success');
				} catch (err) {
					msg.handleApiError(err);
					await loadWatch();
				}
			});
			li.querySelector('.ekc-item-remove').addEventListener('click', async () => {
				if (!window.confirm(t(APP, 'Stop watching this staple?'))) {
					return;
				}
				try {
					await api.del(idUrl(urls.watchDeleteBase, w.id));
					await loadWatch();
					await loadOffers(false, { silent: true });
				} catch (err) {
					msg.handleApiError(err);
				}
			});
			ul.appendChild(li);
		}
	}

	async function addToList(o) {
		if (!assertCanEdit()) {
			return;
		}
		const key = [o.store, o.brand, o.name, o.pack, o.price].join('|');
		if (addBusy.has(key)) {
			return;
		}
		addBusy.add(key);
		try {
			await api.post(urls.listAdd, {
				store: o.store,
				brand: o.brand,
				name: o.name,
				pack: o.pack,
				price: o.price,
				per_kg: o.per_kg,
				qty: 1,
			});
			await loadList();
			msg.announce(t(APP, 'Added to your shopping list.'), 'success');
		} catch (err) {
			msg.handleApiError(err);
		} finally {
			addBusy.delete(key);
		}
	}

	function fillWatchFromOffer(o) {
		if (!assertCanEdit()) {
			return;
		}		const name = String(o.name || '').replace(/\d+(?:[.,]\d+)?\s*(?:kg|g|l|ml|%|er)\b/gi, ' ').replace(/\s+/g, ' ').trim();
		let q = name || o.name;
		if (String(q).trim().length < 3) {
			q = String(o.name || o.brand || '').trim();
		}
		$('ekc-watch-q').value = String(q).slice(0, 200);
		$('ekc-watch-max').value = '';
		$('ekc-watch-kg').value = '';
		$('ekc-watch-store').value = '';
		$('ekc-watch-q').focus();
		msg.announce(t(APP, 'Name filled in — press Watch this when it looks right.'), 'success');
	}

	async function loadList() {
		const data = await api.get(urls.list);
		state.list = data.items || [];
		renderList();
	}

	async function loadWatch() {
		const data = await api.get(urls.watch);
		state.watch = data.items || [];
		renderWatch();
	}

	async function loadOffers(refresh, opts) {
		const options = opts || {};
		const errBox = $('ekc-load-error');
		if (errBox) {
			errBox.hidden = true;
		}
		if (!markPlzValidity()) {
			msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
			$('ekc-plz')?.focus();
			return;
		}
		const wrap = $('ekc-table-wrap');
		wrap?.setAttribute('aria-busy', 'true');
		setOffersBusy(true);
		const gen = ++state.gen;
		if (offersAbort) {
			offersAbort.abort();
		}
		offersAbort = new AbortController();
		const plz = ($('ekc-plz')?.value || '').trim() || '24149';
		const week = $('ekc-week')?.value || 'current';
		const qs = '?plz=' + encodeURIComponent(plz) + '&week=' + encodeURIComponent(week);
		try {
			let data;
			if (refresh) {
				data = await api.post((urls.offersRefresh || urls.offers) + qs, undefined, { signal: offersAbort.signal });
			} else {
				try {
					data = await api.get(urls.offers + qs, { signal: offersAbort.signal });
				} catch (err) {
					if (err && err.code === 'offers_stale') {
						data = await api.post((urls.offersRefresh || urls.offers) + qs, undefined, { signal: offersAbort.signal });
					} else {
						throw err;
					}
				}
			}
			if (gen !== state.gen) {
				return;
			}
			state.offers = data.offers || [];
			state.hits = data.watch_hits || [];
			state.weekCompare = data.week_compare && typeof data.week_compare === 'object'
				? data.week_compare
				: null;
			if (data.plz && $('ekc-plz')) {
				$('ekc-plz').value = String(data.plz);
			}
			if (data.week && $('ekc-week')) {
				$('ekc-week').value = String(data.week);
			}
			syncOffersTitle();
			updateWeekCompareHint();
			$('ekc-stats').innerHTML = [
				['aldi', t(APP, 'ALDI')],
				['lidl', t(APP, 'Lidl')],
				['produce', t(APP, 'Fruit & vegetables')],
				['compare_groups', t(APP, 'In both stores')],
			].map(([k, label]) => `<article class="ekc-stat-tile"><span class="ekc-stat-tile__label">${esc(label)}</span><span class="ekc-stat-tile__value">${esc(data.counts?.[k] ?? 0)}</span></article>`).join('')
				+ `<article class="ekc-stat-tile"><span class="ekc-stat-tile__label">${esc(t(APP, 'Staple hits'))}</span><span class="ekc-stat-tile__value">${esc(state.hits.length)}</span></article>`;
			const lidl = data.lidl_store || {};
			const lidlEl = $('ekc-lidl-store');
			if (lidlEl) {
				const parts = [lidl.name, lidl.postal_code, lidl.city].filter(Boolean);
				if (parts.length) {
					lidlEl.hidden = false;
					lidlEl.textContent = t(APP, 'Lidl store for your postcode:') + ' ' + parts.join(', ');
				} else {
					lidlEl.hidden = true;
					lidlEl.textContent = '';
				}
			}
			if (data.partial) {
				msg.announce(t(APP, 'Some store lists could not be loaded. Showing what we have.'), 'warning');
			}
			applyOffers();
			renderCompares();
			renderAlerts();
			if (!options.silent) {
				msg.announce(t(APP, 'Offers updated.'), 'success');
			}
		} catch (err) {
			if (err && err.name === 'AbortError') {
				return;
			}
			if (gen !== state.gen) {
				return;
			}
			if (errBox) {
				errBox.hidden = false;
				$('ekc-load-error-text').textContent = err.message || t(APP, 'Request failed.');
			}
			msg.handleApiError(err);
		} finally {
			if (gen === state.gen) {
				wrap?.setAttribute('aria-busy', 'false');
				setOffersBusy(false);
			}
		}
	}

	function renderCompares() {
		const box = $('ekc-compares');
		const wrap = $('ekc-compares-wrap');
		if (!box || !wrap) {
			return;
		}
		const seen = new Set();
		const cards = [];
		for (const o of state.offers) {
			if (!o.match_id || seen.has(o.match_id)) {
				continue;
			}
			seen.add(o.match_id);
			const rows = (o.compare || []).map((c) => {
				const unit = c.per_kg != null ? euro(c.per_kg) + '/kg' : (c.per_l != null ? euro(c.per_l) + '/l' : '—');
				return `<tr class="${c.cheapest ? 'overlap' : ''}"><td data-cell="${esc(t(APP, 'Store'))}">${esc(c.store)}</td><td data-cell="${esc(t(APP, 'Brand'))}">${esc(c.brand)}</td><td data-cell="${esc(t(APP, 'Product'))}">${esc(c.name)}</td><td data-cell="${esc(t(APP, 'Pack'))}">${esc(c.pack)}</td><td class="num" data-cell="${esc(t(APP, 'Price'))}">${esc(euro(c.price))}</td><td class="num" data-cell="${esc(t(APP, 'Unit price'))}">${esc(unit)}</td><td data-cell="${esc(t(APP, 'Cheapest'))}">${c.cheapest ? '<span class="ekc-pill win">' + esc(t(APP, 'cheaper')) + '</span>' : ''}</td></tr>`;
			}).join('');
			cards.push(`<div class="ekc-compare-card"><h3>${esc(o.name)}</h3><div class="ekc-table-wrap ekc-compare-table-wrap" tabindex="0" role="region" aria-label="${esc(o.name)}"><table class="table"><thead><tr><th scope="col">${esc(t(APP, 'Store'))}</th><th scope="col">${esc(t(APP, 'Brand'))}</th><th scope="col">${esc(t(APP, 'Product'))}</th><th scope="col">${esc(t(APP, 'Pack'))}</th><th scope="col" class="num">${esc(t(APP, 'Price'))}</th><th scope="col" class="num">${esc(t(APP, 'Unit price'))}</th><th scope="col" class="ekc-sr-only">${esc(t(APP, 'Cheapest'))}</th></tr></thead><tbody>${rows}</tbody></table></div></div>`);
		}
		box.innerHTML = cards.length
			? '<p class="ekc-hint">' + esc(t(APP, 'Same item in more than one store — cheapest by €/kg, then €/l, then pack price.')) + '</p>' + cards.join('')
			: '';
		wrap.hidden = cards.length === 0;
		if (layoutMode === 'compare' && cards.length) {
			wrap.open = true;
		}
	}

	async function loadUserPrefs() {
		const s = await api.get(urls.settings);
		if (s.plz && $('ekc-plz')) {
			$('ekc-plz').value = s.plz;
		}
		if (s.week && $('ekc-week')) {
			$('ekc-week').value = s.week;
		}
		if ($('ekc-settings-plz') && s.plz) {
			$('ekc-settings-plz').value = s.plz;
		}
		if ($('ekc-settings-week') && s.week) {
			$('ekc-settings-week').value = s.week;
		}
		applyShowImagesPref(s.show_images === true);
	}

	function bindOffersPage() {
		applyLayoutMode(readLayoutMode(), { skipPersist: true });
		bindLayoutToggle();
		$('ekc-filter-form').addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!markPlzValidity()) {
				msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
				$('ekc-plz')?.focus();
				return;
			}
			if (canManageSettings) {
				try {
					await api.put(urls.settingsSave, {
						plz: ($('ekc-plz')?.value || '').trim(),
						week: $('ekc-week')?.value || 'current',
					});
				} catch (err) {
					msg.handleApiError(err);
					return;
				}
			}
			loadOffers(false);
		});
		$('ekc-refresh')?.addEventListener('click', () => loadOffers(true));
		$('ekc-clear-filters').addEventListener('click', () => {
			$('ekc-q').value = '';
			$('ekc-store').value = 'all';
			$('ekc-only-kg').checked = false;
			if ($('ekc-only-wait')) {
				$('ekc-only-wait').checked = false;
			}
			$('ekc-only-match').checked = false;
			const food = document.querySelector('input[name="ekc-cat"][value="food"]');
			if (food) {
				food.checked = true;
			}
			applyOffers();
		});
		$('ekc-empty-clear')?.addEventListener('click', () => $('ekc-clear-filters').click());
		$('ekc-retry')?.addEventListener('click', () => loadOffers(true));
		$('ekc-plz')?.addEventListener('input', markPlzValidity);
		['ekc-q', 'ekc-store', 'ekc-only-kg', 'ekc-only-wait', 'ekc-only-match'].forEach((id) => {
			$(id)?.addEventListener('input', applyOffers);
			$(id)?.addEventListener('change', applyOffers);
		});
		document.querySelectorAll('input[name="ekc-cat"]').forEach((el) => el.addEventListener('change', applyOffers));
		$('ekc-week')?.addEventListener('change', () => {
			syncOffersTitle();
		});

		$('ekc-watch-form').addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!assertCanEdit()) {
				return;
			}			const submit = $('ekc-watch-form').querySelector('button[type="submit"]');
			if (submit && submit.disabled) {
				return;
			}
			if (submit) {
				submit.disabled = true;
			}
			try {
				const query = $('ekc-watch-q').value.trim();
				if (query.length < 3 || query.length > 200) {
					$('ekc-watch-q').setAttribute('aria-invalid', 'true');
					$('ekc-watch-q').focus();
					msg.announce(t(APP, 'Query must be between 3 and 200 characters.'), 'error');
					return;
				}
				$('ekc-watch-q').setAttribute('aria-invalid', 'false');
				const max = $('ekc-watch-max').value;
				const kg = $('ekc-watch-kg').value;
				await api.post(urls.watchAdd, {
					query,
					max_price: max === '' ? null : Number(max),
					max_per_kg: kg === '' ? null : Number(kg),
					store: $('ekc-watch-store').value,
					enabled: true,
				});
				$('ekc-watch-form').reset();
				await loadWatch();
				await loadOffers(false, { silent: true });
				msg.announce(t(APP, 'We will notify you when this is on offer.'), 'success');
			} catch (err) {
				msg.handleApiError(err);
			} finally {
				if (submit) {
					submit.disabled = false;
				}
			}
		});

		$('ekc-wa').addEventListener('click', async () => {
			try {
				const exp = await api.get(listExportUrl());
				const url = String(exp.whatsapp_url || '');
				if (!/^https:\/\/wa\.me\/\?text=/.test(url)) {
					msg.announce(t(APP, 'Request failed.'), 'error');
					return;
				}
				if (url.length > 1800) {
					try {
						await navigator.clipboard.writeText(String(exp.text || ''));
						msg.announce(t(APP, 'List is too long for WhatsApp. Copied the text instead.'), 'warning');
					} catch (clipErr) {
						msg.handleApiError(clipErr);
					}
					return;
				}
				const win = window.open(url, '_blank', 'noopener');
				if (!win) {
					msg.announce(t(APP, 'Pop-up blocked. Allow pop-ups to open WhatsApp.'), 'warning');
				}
			} catch (err) {
				msg.handleApiError(err);
			}
		});
		$('ekc-copy').addEventListener('click', async () => {
			try {
				const exp = await api.get(listExportUrl());
				await navigator.clipboard.writeText(exp.text);
				msg.announce(t(APP, 'Shopping list copied.'), 'success');
			} catch (err) {
				msg.handleApiError(err);
			}
		});
		$('ekc-csv').addEventListener('click', async () => {
			try {
				const exp = await api.get(listExportUrl());
				const blob = new Blob([exp.csv], { type: 'text/csv;charset=utf-8' });
				const a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				const store = selectedListStore();
				a.download = store === 'ALDI Nord' ? 'einkaufszettel-aldi.csv' : (store === 'Lidl' ? 'einkaufszettel-lidl.csv' : 'einkaufszettel.csv');
				a.click();
				URL.revokeObjectURL(a.href);
			} catch (err) {
				msg.handleApiError(err);
			}
		});
		$('ekc-print')?.addEventListener('click', () => {
			const prev = document.title;
			const store = selectedListStore();
			document.title = store === 'all'
				? t(APP, 'Shopping list')
				: t(APP, 'Shopping list') + ' — ' + store;
			const restore = () => {
				document.title = prev;
				window.removeEventListener('afterprint', restore);
			};
			window.addEventListener('afterprint', restore);
			window.print();
			window.setTimeout(restore, 2000);
		});
		document.querySelectorAll('input[name="ekc-list-store"]').forEach((el) => {
			el.addEventListener('change', () => {
				selectedListStore();
				renderList();
				const store = selectedListStore();
				msg.announce(store === 'all'
					? t(APP, 'Showing both stores.')
					: t(APP, 'Showing %s.', [store]));
			});
		});
		$('ekc-clear').addEventListener('click', async () => {
			if (!assertCanEdit()) {
				return;
			}			if (listClearBusy || !window.confirm(emptyListConfirm())) {
				return;
			}
			listClearBusy = true;
			try {
				await api.del(listClearUrl());
				await loadList();
				msg.announce(emptiedListMessage(), 'success');
			} catch (err) {
				msg.handleApiError(err);
			} finally {
				listClearBusy = false;
			}
		});
		bindListJump();
	}

	function asChipItems(raw) {
		if (!Array.isArray(raw)) {
			return [];
		}
		return raw.map((item) => {
			if (item && typeof item === 'object') {
				return { id: String(item.id || ''), label: String(item.label || item.id || '') };
			}
			return { id: String(item), label: String(item) };
		}).filter((item) => item.id !== '');
	}

	function bindPicker(inputId, listId, chipsId, searchUrl) {
		const input = $(inputId);
		const list = $(listId);
		const chips = $(chipsId);
		const selected = [];
		let inflight = 0;
		let abort = null;
		let active = -1;

		function closeList() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			active = -1;
		}

		function renderChips() {
			chips.replaceChildren();
			if (!selected.length) {
				const li = document.createElement('li');
				li.className = 'ekc-item-meta';
				li.textContent = t(APP, 'No one selected yet — search below');
				chips.appendChild(li);
				return;
			}
			for (const item of selected) {
				const li = document.createElement('li');
				li.className = 'ekc-chip';
				li.innerHTML = `<span class="ekc-chip__text">${esc(item.label)}</span><button type="button" class="ekc-chip__remove" aria-label="${esc(t(APP, 'Remove') + ' ' + item.label)}">×</button>`;
				li.querySelector('button').addEventListener('click', () => {
					const i = selected.findIndex((s) => s.id === item.id);
					if (i >= 0) {
						selected.splice(i, 1);
					}
					renderChips();
				});
				chips.appendChild(li);
			}
		}

		function ids() {
			return selected.map((s) => s.id);
		}

		function add(item) {
			if (selected.some((s) => s.id === item.id)) {
				return;
			}
			selected.push(item);
			renderChips();
			input.value = '';
			closeList();
		}

		async function search() {
			const q = input.value.trim();
			if (q.length < 2) {
				closeList();
				return;
			}
			const token = ++inflight;
			if (abort) {
				abort.abort();
			}
			abort = new AbortController();
			try {
				const data = await api.get(searchUrl + '?q=' + encodeURIComponent(q), { signal: abort.signal });
				if (token !== inflight) {
					return;
				}
				list.replaceChildren();
				const items = data.items || [];
				if (!items.length) {
					const li = document.createElement('li');
					li.textContent = t(APP, 'No matches');
					list.appendChild(li);
				} else {
					items.forEach((item, i) => {
						const li = document.createElement('li');
						li.setAttribute('role', 'option');
						li.id = listId + '-opt-' + i;
						li.textContent = item.label;
						li.addEventListener('click', () => add(item));
						list.appendChild(li);
					});
				}
				list.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				active = -1;
			} catch (err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				msg.handleApiError(err);
			}
		}

		let timer = null;
		input.addEventListener('input', () => {
			window.clearTimeout(timer);
			timer = window.setTimeout(search, 260);
		});
		input.addEventListener('keydown', (e) => {
			const opts = [...list.querySelectorAll('[role="option"]')];
			if (e.key === 'ArrowDown' && opts.length) {
				e.preventDefault();
				active = Math.min(active + 1, opts.length - 1);
				opts.forEach((o, i) => o.setAttribute('aria-selected', i === active ? 'true' : 'false'));
				input.setAttribute('aria-activedescendant', opts[active].id);
			} else if (e.key === 'ArrowUp' && opts.length) {
				e.preventDefault();
				active = Math.max(active - 1, 0);
				opts.forEach((o, i) => o.setAttribute('aria-selected', i === active ? 'true' : 'false'));
				input.setAttribute('aria-activedescendant', opts[active].id);
			} else if (e.key === 'Enter' && active >= 0 && opts[active]) {
				e.preventDefault();
				opts[active].click();
			} else if (e.key === 'Escape') {
				closeList();
			}
		});
		input.addEventListener('blur', () => {
			window.setTimeout(closeList, 150);
		});
		renderChips();
		return {
			ids,
			set(items) {
				selected.splice(0, selected.length, ...asChipItems(items));
				renderChips();
			},
		};
	}

	async function bindSettings() {
		await loadUserPrefs();
		$('ekc-pref-form')?.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!assertCanManageSettings()) {
				return;
			}
			const plz = $('ekc-settings-plz').value.trim();
			if (!validPlz(plz)) {
				$('ekc-settings-plz').setAttribute('aria-invalid', 'true');
				msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
				return;
			}
			$('ekc-settings-plz').setAttribute('aria-invalid', 'false');
			try {
				await api.put(urls.settingsSave, {
					plz,
					week: $('ekc-settings-week').value,
					show_images: !!$('ekc-settings-show-images')?.checked,
				});
				msg.announce(t(APP, 'Saved.'), 'success');
			} catch (err) {
				msg.handleApiError(err);
			}
		});

		if (!isAppAdmin || !$('ekc-access-form')) {
			return;
		}
		const groups = bindPicker('ekc-group-search', 'ekc-group-listbox', 'ekc-group-chips', urls.directoryGroups);
		const users = bindPicker('ekc-user-search', 'ekc-user-listbox', 'ekc-user-chips', urls.directoryUsers);
		const admins = bindPicker('ekc-admin-search', 'ekc-admin-listbox', 'ekc-admin-chips', urls.directoryUsers);
		const data = await api.get(urls.accessGet);
		const mode = document.querySelector('input[name="access_mode"][value="' + (data.access_mode || 'open') + '"]');
		if (mode) {
			mode.checked = true;
		}
		groups.set(data.access_groups || []);
		users.set(data.access_users || []);
		admins.set(data.app_admins || []);

		let accessBusy = false;
		$('ekc-access-form').addEventListener('submit', async (e) => {
			e.preventDefault();
			if (accessBusy) {
				return;
			}
			accessBusy = true;
			$('ekc-access-save').disabled = true;
			try {
				const chosen = document.querySelector('input[name="access_mode"]:checked');
				await api.put(urls.accessSave, {
					access_mode: chosen ? chosen.value : 'open',
					access_groups: groups.ids(),
					access_users: users.ids(),
					app_admins: admins.ids(),
				});
				msg.announce(t(APP, 'Access saved.'), 'success');
			} catch (err) {
				msg.handleApiError(err);
			} finally {
				accessBusy = false;
				$('ekc-access-save').disabled = false;
			}
		});
	}

	function formatWeekLabel(iso) {
		const raw = String(iso || '');
		if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
			return raw;
		}
		const d = new Date(raw + 'T12:00:00');
		if (Number.isNaN(d.getTime())) {
			return raw;
		}
		return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
	}

	function trendVerdictText(card) {
		const v = String(card.verdict || 'unknown');
		const drop = card.drop_pct == null ? null : Math.abs(Number(card.drop_pct));
		if (v === 'cheap' && drop != null) {
			return t(APP, 'Cheaper than usual — about %s percent less.', [String(drop)]);
		}
		if (v === 'dear' && drop != null) {
			return t(APP, 'More expensive than usual — about %s percent more.', [String(drop)]);
		}
		if (v === 'usual') {
			return t(APP, 'About the usual price.');
		}
		if (v === 'new') {
			return t(APP, 'We only have this week so far. Come back next week to compare.');
		}
		return t(APP, 'No price to compare yet.');
	}

	function trendPointValue(card, pt) {
		if (card.unit === 'kg') {
			return pt && pt.per_kg != null ? pt.per_kg : null;
		}
		return pt && pt.price != null ? pt.price : null;
	}

	function renderSpark(card) {
		const series = Array.isArray(card.series) ? card.series : [];
		const values = series.map((pt) => trendPointValue(card, pt)).filter((n) => n != null && Number.isFinite(n));
		const wrap = document.createElement('div');
		wrap.className = 'ekc-spark';
		wrap.setAttribute('role', 'img');
		const lines = series.map((pt) => {
			const val = trendPointValue(card, pt);
			return t(APP, 'Week of %s: %s', [formatWeekLabel(pt.week_start), euro(val)]);
		});
		wrap.setAttribute('aria-label', lines.join('. '));
		const sr = document.createElement('p');
		sr.className = 'ekc-sr-only';
		sr.textContent = lines.join('. ');
		wrap.appendChild(sr);
		if (values.length === 0) {
			return wrap;
		}
		const min = Math.min(...values);
		const max = Math.max(...values);
		const span = max - min;
		const bars = document.createElement('div');
		bars.className = 'ekc-spark__bars';
		bars.setAttribute('aria-hidden', 'true');
		const currentWeek = String(state.trendWeek || '');
		series.forEach((pt) => {
			const val = trendPointValue(card, pt);
			const bar = document.createElement('span');
			bar.className = 'ekc-spark__bar';
			if (currentWeek !== '' && String(pt.week_start) === currentWeek) {
				bar.classList.add('is-current');
			}
			let pct = 50;
			if (val != null && Number.isFinite(val)) {
				pct = span < 1e-9 ? 50 : 18 + ((val - min) / span) * 82;
			} else {
				pct = 8;
			}
			bar.style.height = String(Math.round(pct)) + '%';
			bar.title = formatWeekLabel(pt.week_start) + ': ' + euro(val);
			bars.appendChild(bar);
		});
		wrap.appendChild(bars);
		return wrap;
	}

	function renderTrendCard(card) {
		const li = document.createElement('li');
		const verdict = String(card.verdict || 'unknown');
		li.className = 'ekc-trend' + (verdict === 'cheap' || verdict === 'dear' ? ' ekc-trend--' + verdict : '');
		const storeClass = card.store === 'ALDI Nord' ? 'aldi' : 'lidl';
		const unitNow = card.unit === 'kg' ? (card.current != null ? card.current : card.per_kg) : (card.current != null ? card.current : card.price);
		const unitHint = card.unit === 'kg' ? t(APP, '€/kg') : t(APP, 'Pack price');
		const last = Array.isArray(card.series) && card.series.length ? card.series[card.series.length - 1] : null;
		let extra = '';
		if (!card.on_offer_now && last) {
			extra = '<p class="ekc-trend__meta">' + esc(t(APP, 'Last seen %s. Not on this week’s list.', [formatWeekLabel(last.week_start)])) + '</p>';
		}
		const watchBit = card.from_watch && card.watch_query
			? ' <span class="ekc-pill alert">' + esc(t(APP, 'Staple')) + '</span>'
			: '';
		li.innerHTML = `
			<div class="ekc-trend__head">
				<div class="ekc-trend__product">
					<div class="ekc-trend__copy">
						<span class="ekc-pill ${storeClass}">${esc(card.store)}</span>
						<h3 class="ekc-trend__name">${esc(card.brand ? card.brand + ' ' : '')}${esc(card.name)}${watchBit}</h3>
					</div>
				</div>
			</div>
			<p class="ekc-trend__meta">${esc(card.pack || '')}</p>
			<p class="ekc-trend__price">${esc(euro(unitNow))} <span class="ekc-trend__meta">${esc(unitHint)}</span></p>
			<p class="ekc-trend__verdict">${esc(trendVerdictText(card))}</p>
			${extra}`;
		attachThumb(li.querySelector('.ekc-trend__product'), card.image);
		li.appendChild(renderSpark(card));
		const details = document.createElement('details');
		const summary = document.createElement('summary');
		summary.textContent = t(APP, 'Price by week');
		details.appendChild(summary);
		const table = document.createElement('table');
		table.className = 'ekc-trend-weeks';
		const caption = document.createElement('caption');
		caption.className = 'ekc-sr-only';
		caption.textContent = t(APP, 'Price by week');
		table.appendChild(caption);
		table.innerHTML += `<thead><tr><th scope="col">${esc(t(APP, 'Week'))}</th><th scope="col" class="num">${esc(unitHint)}</th></tr></thead>`;
		const tb = document.createElement('tbody');
		(card.series || []).forEach((pt) => {
			const tr = document.createElement('tr');
			tr.innerHTML = `<td>${esc(formatWeekLabel(pt.week_start))}</td><td class="num">${esc(euro(trendPointValue(card, pt)))}</td>`;
			tb.appendChild(tr);
		});
		table.appendChild(tb);
		details.appendChild(table);
		li.appendChild(details);
		if (card.on_offer_now) {
			const actions = document.createElement('div');
			actions.className = 'ekc-trend__actions';
			const add = document.createElement('button');
			add.type = 'button';
			add.className = 'ekc-btn ekc-btn--secondary';
			add.textContent = t(APP, 'Put on list');
			add.setAttribute('aria-label', t(APP, 'Add to shopping list') + ': ' + (card.brand ? card.brand + ' ' : '') + card.name);
			add.addEventListener('click', () => addToList(card));
			actions.appendChild(add);
			li.appendChild(actions);
		}
		return li;
	}

	function fillTrendList(listId, emptyId, rows) {
		const ul = $(listId);
		const empty = $(emptyId);
		if (!ul) {
			return;
		}
		ul.replaceChildren();
		const items = Array.isArray(rows) ? rows : [];
		if (empty) {
			empty.hidden = items.length > 0;
		}
		items.forEach((card) => ul.appendChild(renderTrendCard(card)));
	}

	let trendsAbort = null;

	function setTrendsBusy(busy) {
		['ekc-trends-show', 'ekc-trends-clear', 'ekc-trends-retry'].forEach((id) => {
			const el = $(id);
			if (el) {
				el.disabled = busy;
			}
		});
		const loading = $('ekc-trends-loading');
		if (loading) {
			loading.hidden = !busy;
		}
	}

	function renderTrendsView(data) {
		if (!data || page !== 'trends') {
			return;
		}
		const searching = ($('ekc-q')?.value || '').trim() !== '';
		const n = Number(data.weeks_tracked || 0);
		const stale = $('ekc-trends-stale');
		if (stale) {
			stale.hidden = data.cache !== 'empty';
		}
		const weeksEl = $('ekc-trends-weeks');
		if (weeksEl) {
			if (n < 2) {
				weeksEl.hidden = false;
				weeksEl.textContent = t(APP, 'We need a few weeks. Come back next week.');
			} else {
				weeksEl.hidden = false;
				weeksEl.textContent = t(APP, 'We have compared %s weeks of prices.', [String(n)]);
			}
		}
		const staplesWrap = $('ekc-trends-staples-wrap');
		const cheapWrap = $('ekc-trends-cheap-wrap');
		const searchWrap = $('ekc-trends-search-wrap');
		if (staplesWrap) {
			staplesWrap.hidden = searching;
		}
		if (cheapWrap) {
			cheapWrap.hidden = searching;
		}
		if (searchWrap) {
			searchWrap.hidden = !searching;
		}
		if (!searching) {
			fillTrendList('ekc-trends-staples', 'ekc-trends-staples-empty', data.staples);
			fillTrendList('ekc-trends-cheap', 'ekc-trends-cheap-empty', data.cheap_now);
			const cheapEmptyText = $('ekc-trends-cheap-empty')?.querySelector('.ekc-empty-state__text');
			if (cheapEmptyText) {
				cheapEmptyText.textContent = n < 2
					? t(APP, 'We need a few weeks. Come back next week.')
					: t(APP, 'None of this week’s products dropped enough to count as cheaper than usual.');
			}
		} else {
			fillTrendList('ekc-trends-search', 'ekc-trends-search-empty', data.search);
			const countEl = $('ekc-trends-search-count');
			if (countEl) {
				const found = Array.isArray(data.search) ? data.search.length : 0;
				countEl.textContent = found ? t(APP, '%s products', [String(found)]) : '';
			}
		}
		updateImagesHint();
	}

	async function loadTrends() {
		const errBox = $('ekc-trends-load-error');
		if (errBox) {
			errBox.hidden = true;
		}
		if (!markPlzValidity()) {
			msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
			$('ekc-plz')?.focus();
			return;
		}
		const q = ($('ekc-q')?.value || '').trim();
		if (q !== '' && (q.length < 3 || q.length > 200)) {
			$('ekc-q')?.setAttribute('aria-invalid', 'true');
			$('ekc-q')?.focus();
			msg.announce(t(APP, 'Query must be between 3 and 200 characters.'), 'error');
			return;
		}
		$('ekc-q')?.setAttribute('aria-invalid', 'false');
		setTrendsBusy(true);
		const gen = ++state.gen;
		if (trendsAbort) {
			trendsAbort.abort();
		}
		trendsAbort = new AbortController();
		const plz = ($('ekc-plz')?.value || '').trim() || '24149';
		const week = $('ekc-week')?.value || 'current';
		const store = $('ekc-store')?.value || 'all';
		const qs = '?plz=' + encodeURIComponent(plz)
			+ '&week=' + encodeURIComponent(week)
			+ '&store=' + encodeURIComponent(store)
			+ (q ? '&q=' + encodeURIComponent(q) : '');
		try {
			const data = await api.get((urls.trends || '') + qs, { signal: trendsAbort.signal });
			if (gen !== state.gen) {
				return;
			}
			state.trendWeek = String(data.current_week || '');
			state.trendsPayload = data;
			renderTrendsView(data);
		} catch (err) {
			if (err && err.name === 'AbortError') {
				return;
			}
			if (gen !== state.gen) {
				return;
			}
			if (errBox) {
				errBox.hidden = false;
				const text = $('ekc-trends-load-error-text');
				if (text) {
					text.textContent = err.message || t(APP, 'Request failed.');
				}
			}
			msg.handleApiError(err);
		} finally {
			if (gen === state.gen) {
				setTrendsBusy(false);
			}
		}
	}

	function bindTrendsPage() {
		const offersHref = urls.pages && urls.pages.offers ? urls.pages.offers : '';
		if (offersHref && $('ekc-trends-open-offers') && !$('ekc-trends-open-offers').getAttribute('href')) {
			$('ekc-trends-open-offers').setAttribute('href', offersHref);
		}
		$('ekc-trends-form')?.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!markPlzValidity()) {
				msg.announce(t(APP, 'Postal code must be exactly 5 digits.'), 'error');
				$('ekc-plz')?.focus();
				return;
			}
			if (canManageSettings) {
				try {
					await api.put(urls.settingsSave, {
						plz: ($('ekc-plz')?.value || '').trim(),
						week: $('ekc-week')?.value || 'current',
					});
				} catch (err) {
					msg.handleApiError(err);
					return;
				}
			}
			loadTrends();
		});
		$('ekc-trends-clear')?.addEventListener('click', () => {
			if ($('ekc-q')) {
				$('ekc-q').value = '';
			}
			if ($('ekc-store')) {
				$('ekc-store').value = 'all';
			}
			loadTrends();
		});
		$('ekc-trends-retry')?.addEventListener('click', () => loadTrends());
		$('ekc-plz')?.addEventListener('input', markPlzValidity);
	}

	applyViewerChrome();

	if (page === 'offers') {
		bindOffersPage();
		bindShowImages();
		(async () => {
			try {
				await loadUserPrefs();
				await Promise.all([loadList(), loadWatch(), loadOffers(false, { silent: true })]);
			} catch (e) {
				msg.handleApiError(e);
			}
		})();
	} else if (page === 'trends') {
		bindTrendsPage();
		bindShowImages();
		(async () => {
			try {
				await loadUserPrefs();
				await loadTrends();
			} catch (e) {
				msg.handleApiError(e);
			}
		})();
	} else if (page === 'settings') {
		bindShowImages();
		bindSettings().catch((e) => msg.handleApiError(e));
	}
})();
