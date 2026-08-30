(function () {
	'use strict';

	const MUTATION = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
	const APP = 'einkaufcheck';

	function csrfToken() {
		if (typeof window.OC !== 'undefined' && typeof OC.requestToken === 'string' && OC.requestToken !== '') {
			return OC.requestToken;
		}
		const head = document.querySelector('head');
		const fromHead = head ? head.getAttribute('data-requesttoken') : '';
		if (fromHead) {
			return fromHead;
		}
		const meta = document.querySelector('meta[name="requesttoken"]');
		const fromMeta = meta ? meta.getAttribute('content') : '';
		if (fromMeta) {
			return fromMeta;
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input && input.value ? String(input.value) : '';
	}

	function applyCsrfToken(tok) {
		if (!tok) {
			return;
		}
		if (typeof window.OC !== 'undefined') {
			OC.requestToken = tok;
		}
		const head = document.querySelector('head');
		if (head) {
			head.setAttribute('data-requesttoken', tok);
		}
		const meta = document.querySelector('meta[name="requesttoken"]');
		if (meta) {
			meta.setAttribute('content', tok);
		}
		document.querySelectorAll('input[name="requesttoken"]').forEach((el) => {
			el.value = tok;
		});
	}

	async function refreshCsrfToken() {
		try {
			const url = (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function')
				? OC.generateUrl('/csrftoken')
				: '/index.php/csrftoken';
			const res = await fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
				},
			});
			if (!res.ok) {
				return '';
			}
			const data = await res.json().catch(() => null);
			const tok = data && typeof data.token === 'string' ? data.token : '';
			if (tok) {
				applyCsrfToken(tok);
			}
			return tok;
		} catch (e) {
			return '';
		}
	}

	function appendForm(params, key, value) {
		if (value === undefined) {
			return;
		}
		if (value === null) {
			params.append(key, '');
			return;
		}
		if (typeof value === 'boolean') {
			params.append(key, value ? '1' : '0');
			return;
		}
		if (Array.isArray(value) || (typeof value === 'object')) {
			params.append(key, JSON.stringify(value));
			return;
		}
		params.append(key, String(value));
	}

	/**
	 * Nextcloud CSRF reads requesttoken from GET, then POST, then the HTTP header.
	 * JSON bodies never populate $_POST, and some Apache setups drop the custom
	 * header — so mutating browser calls use form-urlencoded (SnackCheck pattern).
	 */
	function toFormBody(payload, token) {
		const params = new URLSearchParams();
		params.append('requesttoken', token);
		if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
			Object.keys(payload).forEach((key) => {
				if (key === 'requesttoken') {
					return;
				}
				appendForm(params, key, payload[key]);
			});
		}
		return params.toString();
	}

	function withCsrfQuery(url, token) {
		const join = String(url).indexOf('?') >= 0 ? '&' : '?';
		return url + join + 'requesttoken=' + encodeURIComponent(token);
	}

	function errorMessage(data, fallback) {
		if (data && typeof data === 'object') {
			if (data.error && data.error.message) {
				return String(data.error.message);
			}
			if (data.message) {
				return String(data.message);
			}
		}
		return fallback;
	}

	async function request(url, options) {
		const opts = options || {};
		const method = (opts.method || 'GET').toUpperCase();
		const mutating = MUTATION.has(method);
		const attempts = 2;
		let lastErr = null;
		for (let attempt = 0; attempt < attempts; attempt++) {
			const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
			let fetchUrl = url;
			let fetchBody;
			let token = csrfToken();
			if (!token && (mutating || attempt === 0)) {
				token = await refreshCsrfToken();
			}
			if (token) {
				headers.requesttoken = token;
			} else if (mutating) {
				throw Object.assign(new Error(t(APP, 'Missing CSRF request token.')), { status: 0 });
			}
			if (mutating) {
				if (method === 'POST') {
					const payload = (opts.body && typeof opts.body === 'object') ? opts.body : {};
					fetchBody = toFormBody(payload, token);
					headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
				} else {
					fetchUrl = withCsrfQuery(url, token);
					if (method !== 'DELETE') {
						const payload = (opts.body && typeof opts.body === 'object') ? opts.body : {};
						fetchBody = toFormBody(payload, token);
						headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
					}
				}
			} else if (token) {
				// AppFramework CSRF runs on GET too; some proxies drop the requesttoken header.
				fetchUrl = withCsrfQuery(url, token);
			}
			let response;
			try {
				response = await fetch(fetchUrl, {
					method,
					credentials: 'same-origin',
					headers,
					body: fetchBody,
					signal: opts.signal,
				});
			} catch (e) {
				if (e && e.name === 'AbortError') {
					throw e;
				}
				throw Object.assign(new Error(t(APP, 'Network error. Please retry.')), { status: 0, cause: e });
			}
			const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
			const data = isJson ? await response.json().catch(() => null) : await response.text();
			if (response.ok) {
				return data;
			}
			if (response.status === 412 && attempt + 1 < attempts) {
				const fresh = await refreshCsrfToken();
				if (fresh) {
					continue;
				}
			}
			const err = new Error(errorMessage(data, t(APP, 'Request failed.')));
			err.status = response.status;
			err.payload = data;
			err.code = (data && data.error && data.error.code) || (data && data.code) || null;
			lastErr = err;
			break;
		}
		throw lastErr || Object.assign(new Error(t(APP, 'Request failed.')), { status: 412 });
	}

	window.EinkaufCheckApi = {
		get: (url, options) => request(url, Object.assign({}, options || {}, { method: 'GET' })),
		post: (url, body, options) => request(url, Object.assign({}, options || {}, { method: 'POST', body })),
		put: (url, body, options) => request(url, Object.assign({}, options || {}, { method: 'PUT', body })),
		del: (url, options) => request(url, Object.assign({}, options || {}, { method: 'DELETE' })),
	};
})();
