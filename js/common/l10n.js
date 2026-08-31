/**
 * EinkaufCheck JS l10n — NC translate() does not substitute %s from arrays; we do.
 */
(function () {
	'use strict';

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

	const originalT = typeof window.t === 'function' ? window.t : null;

	function getBaseTranslation(msg) {
		if (window.OC && window.OC.L10N && typeof window.OC.L10N.get === 'function') {
			const fromCatalog = window.OC.L10N.get(APP, msg);
			if (fromCatalog && fromCatalog !== msg) {
				return fromCatalog;
			}
		}
		if (originalT) {
			return originalT(APP, msg);
		}
		return String(msg);
	}

	function translate(app, msg, args) {
		if (!originalT && !window.OC?.L10N?.get) {
			return applyPlaceholders(String(msg), args);
		}
		if (app !== APP) {
			return originalT ? originalT.apply(window, arguments) : String(msg);
		}
		if (msg == null) {
			return '';
		}
		let base = getBaseTranslation(msg);
		if (!base) {
			base = String(msg);
		}
		if (args === undefined || args === null) {
			return base;
		}
		return applyPlaceholders(base, args);
	}

	window.t = translate;
	window.EinkaufCheckL10n = {
		applyPlaceholders: applyPlaceholders,
	};
})();
