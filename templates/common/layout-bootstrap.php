<?php

declare(strict_types=1);

/**
 * Inline layout bootstrap — runs before deferred app.js (cache-safe, CSP-nonce).
 *
 * @var array $_
 */
$cspNonce = (string)($_['cspNonce'] ?? '');
?>
<script nonce="<?php p($cspNonce); ?>">
(function () {
	'use strict';
	var root = document.getElementById('app-content');
	if (!root || !root.classList.contains('ekc-app') || root.dataset.ekcPage !== 'offers') {
		return;
	}
	function layoutKey() {
		var wid = parseInt(root.dataset.ekcWorkspaceId || '0', 10) || 0;
		return 'ekc:layout:' + (wid > 0 ? wid : '0');
	}
	function readMode() {
		try {
			var v = localStorage.getItem(layoutKey());
			if (v === 'split' || v === 'compare') {
				return v;
			}
		} catch (_) {
			/* private mode */
		}
		return 'compare';
	}
	function persistMode(mode) {
		try {
			localStorage.setItem(layoutKey(), mode);
		} catch (_) {
			/* private mode */
		}
	}
	function applyCompare(compare) {
		root.classList.toggle('ekc-app--compare-focus', compare);
		var grid = document.getElementById('ekc-page-grid');
		if (grid) {
			grid.classList.toggle('ekc-page-grid--compare-focus', compare);
		}
		var side = document.getElementById('ekc-side');
		if (side) {
			side.hidden = compare;
			if ('inert' in side) {
				side.inert = compare;
			}
		}
		var list = document.getElementById('ekc-list-card');
		if (list) {
			list.hidden = compare;
			if ('inert' in list) {
				list.inert = compare;
			}
		}
		var compareBtn = document.getElementById('ekc-layout-compare');
		var splitBtn = document.getElementById('ekc-layout-split');
		if (compareBtn) {
			compareBtn.setAttribute('aria-pressed', compare ? 'true' : 'false');
		}
		if (splitBtn) {
			splitBtn.setAttribute('aria-pressed', compare ? 'false' : 'true');
		}
	}
	function onLayoutClick(e) {
		var target = e.target;
		if (!target || typeof target.closest !== 'function') {
			return;
		}
		var hideBtn = target.closest('#ekc-layout-hide-from-side');
		var layoutBtn = target.closest('[data-ekc-layout]');
		if (!hideBtn && !layoutBtn) {
			return;
		}
		var compare = hideBtn ? true : layoutBtn.getAttribute('data-ekc-layout') === 'compare';
		applyCompare(compare);
		persistMode(compare ? 'compare' : 'split');
	}
	applyCompare(readMode() !== 'split');
	root.addEventListener('click', onLayoutClick);
	window.__ekcLayoutBootstrap = { applyCompare: applyCompare, readMode: readMode };
})();
</script>
