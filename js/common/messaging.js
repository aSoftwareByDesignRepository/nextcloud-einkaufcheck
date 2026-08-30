(function () {
	'use strict';

	const APP = 'einkaufcheck';

	function announce(message, kind) {
		const k = kind === 'error' ? 'error' : (kind === 'warning' ? 'warning' : 'success');
		const polite = document.getElementById('ekc-live-region');
		const assertive = document.getElementById('ekc-alert-region');
		const target = k === 'error' ? assertive : polite;
		if (target) {
			target.textContent = '';
			window.setTimeout(() => { target.textContent = String(message); }, 10);
		}
		const region = document.getElementById('ekc-toast-region');
		if (!region) {
			return;
		}
		const toast = document.createElement('div');
		toast.className = 'ekc-toast ekc-toast--' + k;
		toast.setAttribute('role', k === 'error' ? 'alert' : 'status');
		const text = document.createElement('p');
		text.textContent = String(message);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'ekc-toast__close';
		close.setAttribute('aria-label', t(APP, 'Dismiss'));
		close.textContent = '×';
		close.addEventListener('click', () => toast.remove());
		toast.appendChild(text);
		if (k === 'error') {
			const report = document.createElement('a');
			report.href = 'mailto:dev@software-by-design.de?subject=' + encodeURIComponent('EinkaufCheck: Problem report');
			report.textContent = t(APP, 'Report this problem');
			toast.appendChild(report);
		}
		toast.appendChild(close);
		region.appendChild(toast);
		window.setTimeout(() => {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, k === 'error' ? 8000 : 4000);
	}

	function handleApiError(err) {
		const status = Number((err && err.status) || 0);
		const code = err && err.code ? String(err.code) : '';
		if (status === 401) {
			announce(t(APP, 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		if (status === 412) {
			announce(t(APP, 'Could not verify this request. Please reload the page.'), 'error');
			return;
		}
		if (status === 403 || code === 'app_access_denied') {
			announce(t(APP, 'You are not allowed to do that.'), 'error');
			return;
		}
		if (status === 429 || code === 'rate_limited') {
			announce(t(APP, 'Too many requests. Please wait a moment and try again.'), 'warning');
			return;
		}
		announce(String((err && err.message) || t(APP, 'Request failed.')), 'error');
	}

	window.EinkaufCheckMessaging = { announce, handleApiError };
})();
