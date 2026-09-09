/**
 * MJ ERP — core.js
 * Global namespace: config, state, events, api, fmt, ui, icon, router, pages, bootstrap, money.
 * (MJ.calc lives in calc.js.)
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	const config = window.MJERP || {};

	MJ.version = config.version || '1.0.0';
	MJ.config = config;
	MJ.pages = MJ.pages || {};
	MJ.components = MJ.components || {};

	MJ.state = {
		bootstrap: null,
		settings: Object.assign({}, config.settings || {}),
		statuses: Object.assign({}, config.statuses || {}),
		suppliers: [],
		cashboxes: [],
		user: Object.assign({}, config.user || {}),
		route: { name: null, path: '/', params: {}, query: {} },
	};

	/**
	 * Read one setting with a fallback.
	 */
	MJ.setting = function (key, def) {
		const s = MJ.state.settings || {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	};

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
	/* ------------------------------------------------------------------ */

	const listeners = {};

	MJ.events = {
		on(evt, fn) {
			if (typeof fn !== 'function') return function () {};
			(listeners[evt] = listeners[evt] || []).push(fn);
			return function () { MJ.events.off(evt, fn); };
		},
		once(evt, fn) {
			const off = MJ.events.on(evt, function (data) { off(); fn(data); });
			return off;
		},
		off(evt, fn) {
			if (!listeners[evt]) return;
			if (!fn) { delete listeners[evt]; return; }
			listeners[evt] = listeners[evt].filter(function (f) { return f !== fn; });
		},
		emit(evt, data) {
			(listeners[evt] || []).slice().forEach(function (fn) {
				try { fn(data); } catch (e) { console.error('[MJ] events(' + evt + ')', e); }
			});
		},
	};

	/* ------------------------------------------------------------------ */
	/* ApiError                                                            */
	/* ------------------------------------------------------------------ */

	function ApiError(message, code, status, data) {
		this.name = 'ApiError';
		this.message = message || 'Error desconocido';
		this.code = code || 'mjerp_error';
		this.status = status || 0;
		this.data = data || null;
		this.handled = false;
		if (typeof Error.captureStackTrace === 'function') {
			Error.captureStackTrace(this, ApiError);
		} else {
			this.stack = (new Error(this.message)).stack;
		}
	}
	ApiError.prototype = Object.create(Error.prototype);
	ApiError.prototype.constructor = ApiError;
	ApiError.prototype.toString = function () { return this.message; };
	MJ.ApiError = ApiError;

	/* ------------------------------------------------------------------ */
	/* API                                                                 */
	/* ------------------------------------------------------------------ */

	let redirecting = false;

	function loginUrl() {
		if (config.loginUrl) return config.loginUrl;
		const home = String(config.homeUrl || '/').replace(/\/+$/, '');
		const back = config.appUrl || window.location.href;
		return home + '/wp-login.php?redirect_to=' + encodeURIComponent(back);
	}

	function handleUnauthorized() {
		if (redirecting) return;
		redirecting = true;
		MJ.ui.toast('Sesión expirada. Redirigiendo al inicio de sesión…', 'error', 4000);
		setTimeout(function () { window.location.href = loginUrl(); }, 1200);
	}

	function appendParams(pairs, key, value) {
		if (value === undefined || value === null || value === '') return;
		if (Array.isArray(value)) {
			value.forEach(function (v) { appendParams(pairs, key + '[]', v); });
			return;
		}
		if (typeof value === 'object') {
			Object.keys(value).forEach(function (k) { appendParams(pairs, key + '[' + k + ']', value[k]); });
			return;
		}
		if (typeof value === 'boolean') value = value ? 1 : 0;
		pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
	}

	function buildUrl(path, params) {
		let base = String(config.restUrl || '/wp-json/mjerp/v1/');
		path = String(path || '').replace(/^\/+/, '');
		let extra = '';
		const q = path.indexOf('?');
		if (q >= 0) { extra = path.slice(q + 1); path = path.slice(0, q); }
		const pairs = [];
		if (extra) pairs.push(extra);
		if (params) Object.keys(params).forEach(function (k) { appendParams(pairs, k, params[k]); });

		let url;
		if (base.indexOf('rest_route=') !== -1) {
			// Plain permalinks: https://site/?rest_route=/mjerp/v1/  → append the path to the rest_route value.
			if (!/\/$/.test(base)) base += '/';
			url = base + path;
			if (pairs.length) url += '&' + pairs.join('&');
		} else {
			if (!/\/$/.test(base)) base += '/';
			url = base + path;
			if (pairs.length) url += (url.indexOf('?') >= 0 ? '&' : '?') + pairs.join('&');
		}
		return url;
	}

	function request(method, path, options) {
		options = options || {};
		const url = buildUrl(path, options.params);
		const headers = { 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' };
		const init = { method: method, headers: headers, credentials: 'same-origin' };
		if (options.body !== undefined && options.body !== null && method !== 'GET') {
			headers['Content-Type'] = 'application/json; charset=utf-8';
			init.body = JSON.stringify(options.body);
		}
		if (method === 'GET') init.cache = 'no-store';

		return fetch(url, init).catch(function () {
			throw new ApiError('No se pudo conectar con el servidor. Revisá tu conexión e intentá de nuevo.', 'mjerp_network', 0);
		}).then(function (res) {
			return res.text().then(function (text) {
				let data = null;
				let parseFailed = false;
				if (text && text.trim() !== '') {
					try { data = JSON.parse(text); } catch (e) { parseFailed = true; }
				}
				if (res.ok) {
					if (parseFailed) throw new ApiError('El servidor devolvió una respuesta inválida.', 'mjerp_invalid_response', res.status, { raw: text.slice(0, 500) });
					return data;
				}
				let code = 'mjerp_http_' + res.status;
				let message = 'Error del servidor (' + res.status + ')';
				let status = res.status;
				let errData = null;
				if (data && typeof data === 'object') {
					if (data.code) code = String(data.code);
					if (data.message) message = String(data.message);
					errData = data.data || null;
					if (errData && errData.status) status = parseInt(errData.status, 10) || status;
				} else if (parseFailed) {
					message = 'El servidor devolvió una respuesta inválida (' + res.status + ').';
				}
				const err = new ApiError(message, code, status, errData);
				if (status === 401 || code === 'rest_cookie_invalid_nonce' || code === 'rest_not_logged_in') {
					err.handled = true;
					err.message = 'Tu sesión expiró. Volvé a iniciar sesión.';
					handleUnauthorized();
				}
				throw err;
			});
		});
	}

	MJ.api = {
		url: buildUrl,
		request: request,
		get(path, params) { return request('GET', path, { params: params }); },
		post(path, body) { return request('POST', path, { body: body || {} }); },
		put(path, body) { return request('PUT', path, { body: body || {} }); },
		del(path, body) { return request('DELETE', path, { body: body }); },
		loginUrl: loginUrl,
		/** Human readable message for any thrown value. */
		message(err, fallback) {
			if (!err) return fallback || 'Ocurrió un error inesperado.';
			if (typeof err === 'string') return err;
			if (err.message) return err.message;
			return fallback || 'Ocurrió un error inesperado.';
		},
	};

	/* ------------------------------------------------------------------ */
	/* Formatting                                                          */
	/* ------------------------------------------------------------------ */

	const nfCache = {};

	function locale() {
		return MJ.setting('locale', 'es-AR') || 'es-AR';
	}

	function defaultDecimals() {
		const d = parseInt(MJ.setting('decimals', 2), 10);
		return isNaN(d) || d < 0 ? 2 : Math.min(d, 6);
	}

	function numberFormatter(min, max) {
		const key = locale() + '|' + min + '|' + max;
		if (!nfCache[key]) {
			try {
				nfCache[key] = new Intl.NumberFormat(locale(), { minimumFractionDigits: min, maximumFractionDigits: max });
			} catch (e) {
				nfCache[key] = new Intl.NumberFormat('es-AR', { minimumFractionDigits: min, maximumFractionDigits: max });
			}
		}
		return nfCache[key];
	}

	function toNumber(v) {
		if (v === null || v === undefined || v === '') return null;
		if (typeof v === 'number') return isFinite(v) ? v : null;
		if (typeof v === 'boolean') return v ? 1 : 0;
		const n = parseNumber(v);
		return isNaN(n) ? null : n;
	}

	function parseNumber(str) {
		if (typeof str === 'number') return isFinite(str) ? str : NaN;
		if (str === null || str === undefined) return NaN;
		let s = String(str).trim();
		if (!s) return NaN;
		s = s.replace(/[^\d.,\-+]/g, '');
		if (!s || s === '-' || s === '+' || s === '.' || s === ',') return NaN;
		const lastDot = s.lastIndexOf('.');
		const lastComma = s.lastIndexOf(',');
		if (lastDot >= 0 && lastComma >= 0) {
			if (lastComma > lastDot) {
				s = s.replace(/\./g, '').replace(/,/g, '.'); // 1.234,56
			} else {
				s = s.replace(/,/g, ''); // 1,234.56
			}
		} else if (lastComma >= 0) {
			const commas = s.split(',').length - 1;
			s = commas > 1 ? s.replace(/,/g, '') : s.replace(',', '.');
		} else if (lastDot >= 0) {
			const dots = s.split('.').length - 1;
			if (dots > 1) s = s.replace(/\./g, '');
		}
		const n = parseFloat(s);
		return isFinite(n) ? n : NaN;
	}

	function pad2(n) { return (n < 10 ? '0' : '') + n; }

	function parseDateString(value) {
		if (!value) return null;
		if (value instanceof Date) return isNaN(value.getTime()) ? null : { y: value.getFullYear(), m: value.getMonth() + 1, d: value.getDate(), H: value.getHours(), M: value.getMinutes(), S: value.getSeconds() };
		const s = String(value).trim();
		const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
		if (!m) return null;
		return { y: parseInt(m[1], 10), m: parseInt(m[2], 10), d: parseInt(m[3], 10), H: m[4] ? parseInt(m[4], 10) : 0, M: m[5] ? parseInt(m[5], 10) : 0, S: m[6] ? parseInt(m[6], 10) : 0 };
	}

	const fmt = {
		locale: locale,
		decimals: defaultDecimals,
		resetCache() { Object.keys(nfCache).forEach(function (k) { delete nfCache[k]; }); },
		symbol(currency) {
			const cur = String(currency || MJ.setting('base_currency', 'USD')).toUpperCase();
			const symbols = MJ.setting('currency_symbols', {}) || {};
			return symbols[cur] ? String(symbols[cur]) : cur;
		},
		number(v, decimals) {
			const n = toNumber(v);
			if (n === null) return '—';
			const d = (decimals === undefined || decimals === null) ? 2 : Math.max(0, parseInt(decimals, 10) || 0);
			try { return numberFormatter(d, d).format(n); } catch (e) { return n.toFixed(d); }
		},
		int(v) {
			const n = toNumber(v);
			if (n === null) return '—';
			try { return numberFormatter(0, 0).format(Math.round(n)); } catch (e) { return String(Math.round(n)); }
		},
		/** Quantity: no trailing zeros, up to 4 decimals. */
		qty(v) {
			const n = toNumber(v);
			if (n === null) return '—';
			try { return numberFormatter(0, 4).format(n); } catch (e) { return String(n); }
		},
		money(value, currency, decimals) {
			const n = toNumber(value);
			if (n === null) return '—';
			const cur = currency || MJ.setting('base_currency', 'USD');
			const d = (decimals === undefined || decimals === null) ? defaultDecimals() : decimals;
			return (n < 0 ? '-' : '') + fmt.symbol(cur) + ' ' + fmt.number(Math.abs(n), d);
		},
		moneyLocal(value, decimals) {
			return fmt.money(value, MJ.setting('local_currency', 'ARS'), decimals);
		},
		pct(v, decimals) {
			const n = toNumber(v);
			if (n === null) return '—';
			return fmt.number(n, decimals === undefined ? 2 : decimals) + '%';
		},
		rate(v) {
			const n = toNumber(v);
			if (n === null) return '—';
			try { return numberFormatter(0, 2).format(n); } catch (e) { return String(n); }
		},
		date(value) {
			if (!value) return '';
			const p = parseDateString(value);
			if (!p) return String(value);
			return pad2(p.d) + '/' + pad2(p.m) + '/' + p.y;
		},
		datetime(value) {
			if (!value) return '';
			const p = parseDateString(value);
			if (!p) return String(value);
			return pad2(p.d) + '/' + pad2(p.m) + '/' + p.y + ' ' + pad2(p.H) + ':' + pad2(p.M);
		},
		/** Date → 'YYYY-MM-DD' (local time). */
		ymd(date) {
			const d = date instanceof Date ? date : new Date();
			return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
		},
		today() { return fmt.ymd(new Date()); },
		parse: parseNumber,
		toNumber: toNumber,
		/** Rounds to n decimals (avoids float artifacts). */
		round(v, decimals) {
			const n = toNumber(v);
			if (n === null) return 0;
			const f = Math.pow(10, decimals === undefined ? 2 : decimals);
			return Math.round((n + Number.EPSILON) * f) / f;
		},
	};
	MJ.fmt = fmt;

	MJ.money = function (v, currency) {
		return fmt.money(v, currency || MJ.setting('base_currency', 'USD'));
	};

	/* ------------------------------------------------------------------ */
	/* Icons                                                               */
	/* ------------------------------------------------------------------ */

	const ICONS = {
		'home': '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
		'cart': '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
		'truck': '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'box': '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
		'package': '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
		'users': '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'user': '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'cash': '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01"/><path d="M18 12h.01"/>',
		'dollar': '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
		'settings': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
		'plus': '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
		'minus': '<line x1="5" y1="12" x2="19" y2="12"/>',
		'trash': '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
		'edit': '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
		'search': '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		'check': '<polyline points="20 6 9 17 4 12"/>',
		'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
		'x': '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'x-circle': '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
		'refresh': '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
		'undo': '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
		'upload': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
		'download': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
		'link': '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'unlink': '<path d="M18.84 12.25l1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/><path d="M5.17 11.75l-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/><line x1="8" y1="2" x2="8" y2="5"/><line x1="2" y1="8" x2="5" y2="8"/><line x1="16" y1="19" x2="16" y2="22"/><line x1="19" y1="16" x2="22" y2="16"/>',
		'external': '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
		'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
		'chevron-up': '<polyline points="18 15 12 9 6 15"/>',
		'chevron-left': '<polyline points="15 18 9 12 15 6"/>',
		'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
		'arrow-left': '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
		'arrow-right': '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		'warning': '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
		'info': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
		'error': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
		'copy': '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'file': '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
		'clipboard': '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
		'tag': '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
		'list': '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
		'eye': '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
		'logout': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
		'menu': '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
		'more': '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
		'more-vertical': '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
		'calendar': '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
		'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'layers': '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
		'percent': '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
		'chart': '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
		'store': '<path d="M3 9l1-5h16l1 5"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 11v10h14V11"/><path d="M10 21v-6h4v6"/>',
		'save': '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
		'filter': '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
		'lock': '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		'inbox': '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
		'archive': '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
		'globe': '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		'mail': '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
		'phone': '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
		'image': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
		'zap': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
		'spinner': '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
	};

	MJ.icon = function (name, className) {
		const body = ICONS[name] || ICONS.info;
		const cls = 'mj-icon' + (name ? ' mj-icon--' + String(name).replace(/[^a-z0-9-]/gi, '') : '') + (className ? ' ' + className : '');
		return '<svg class="' + cls + '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + body + '</svg>';
	};
	MJ.icon.names = function () { return Object.keys(ICONS); };

	/* ------------------------------------------------------------------ */
	/* UI helpers                                                          */
	/* ------------------------------------------------------------------ */

	const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

	function h(v) {
		if (v === null || v === undefined) return '';
		return String(v).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
	}

	function qs(sel, root) { return (root || document).querySelector(sel); }
	function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function cssEscape(s) {
		if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(s);
		return String(s).replace(/["\\]/g, '\\$&');
	}

	/* Toasts */
	let toastContainer = null;
	function ensureToastContainer() {
		if (toastContainer && document.body.contains(toastContainer)) return toastContainer;
		toastContainer = document.createElement('div');
		toastContainer.className = 'mj-toast-container';
		toastContainer.setAttribute('aria-live', 'polite');
		document.body.appendChild(toastContainer);
		return toastContainer;
	}

	function toast(message, type, ms) {
		type = type || 'info';
		if (['info', 'success', 'error', 'warning'].indexOf(type) === -1) type = 'info';
		if (ms === undefined || ms === null) ms = type === 'error' ? 6000 : 3500;
		const container = ensureToastContainer();
		const el = document.createElement('div');
		el.className = 'mj-toast mj-toast--' + type;
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		const iconName = type === 'success' ? 'check-circle' : (type === 'error' ? 'x-circle' : (type === 'warning' ? 'warning' : 'info'));
		el.innerHTML = MJ.icon(iconName) + '<span class="mj-toast__message">' + h(message) + '</span>' +
			'<button type="button" class="mj-toast__close" aria-label="Cerrar">' + MJ.icon('x') + '</button>';
		let timer = null;
		const remove = function () {
			if (timer) clearTimeout(timer);
			el.classList.add('is-leaving');
			setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
		};
		el.addEventListener('click', remove);
		container.appendChild(el);
		requestAnimationFrame(function () { el.classList.add('is-visible'); });
		if (ms > 0) timer = setTimeout(remove, ms);
		while (container.children.length > 5) container.removeChild(container.firstChild);
		return { el: el, close: remove };
	}

	/* Modals */
	const modalStack = [];

	function focusables(root) {
		return qsa('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', root)
			.filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
	}

	function modal(opts) {
		opts = opts || {};
		const size = ['sm', 'md', 'lg', 'xl'].indexOf(opts.size) >= 0 ? opts.size : 'md';
		const closeOnBackdrop = opts.closeOnBackdrop !== false;
		const closeOnEsc = opts.closeOnEsc !== false;
		const id = 'mj-modal-' + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36);
		const previousFocus = document.activeElement;

		const backdrop = document.createElement('div');
		backdrop.className = 'mj-modal-backdrop';
		backdrop.style.zIndex = String(1000 + modalStack.length * 10);
		backdrop.innerHTML =
			'<div class="mj-modal mj-modal--' + size + '" role="dialog" aria-modal="true" aria-labelledby="' + id + '-title" tabindex="-1">' +
				'<div class="mj-modal__header">' +
					'<h3 class="mj-modal__title" id="' + id + '-title">' + (opts.titleHtml ? opts.titleHtml : h(opts.title || '')) + '</h3>' +
					(opts.hideClose ? '' : '<button type="button" class="mj-modal__close" data-modal-close aria-label="Cerrar">' + MJ.icon('x') + '</button>') +
				'</div>' +
				'<div class="mj-modal__body">' + (opts.body || '') + '</div>' +
				'<div class="mj-modal__footer' + (opts.footer ? '' : ' mj-hidden') + '">' + (opts.footer || '') + '</div>' +
			'</div>';

		const el = backdrop.firstElementChild;
		const bodyEl = qs('.mj-modal__body', el);
		const footerEl = qs('.mj-modal__footer', el);
		const titleEl = qs('.mj-modal__title', el);
		let open = true;

		const api = {
			id: id,
			el: el,
			backdrop: backdrop,
			body: bodyEl,
			footer: footerEl,
			closeOnEsc: closeOnEsc,
			isOpen() { return open; },
			setTitle(text) { titleEl.textContent = text === undefined || text === null ? '' : String(text); },
			setBody(html) { bodyEl.innerHTML = html || ''; },
			setFooter(html) { footerEl.innerHTML = html || ''; footerEl.classList.toggle('mj-hidden', !html); },
			focusFirst() {
				const auto = qs('[autofocus]', el);
				const target = auto || qsa('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])', bodyEl).filter(function (n) { return n.offsetParent !== null; })[0]
					|| focusables(footerEl)[0] || qs('.mj-modal__close', el) || el;
				try { target.focus({ preventScroll: true }); } catch (e) { /* noop */ }
				if (target && target.select && (target.type === 'text' || target.type === 'search') && opts.selectOnFocus) target.select();
			},
			close(result) {
				if (!open) return;
				open = false;
				const idx = modalStack.indexOf(api);
				if (idx >= 0) modalStack.splice(idx, 1);
				backdrop.classList.add('is-closing');
				if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
				if (!modalStack.length) document.body.classList.remove('mj-modal-open');
				if (typeof opts.onClose === 'function') {
					try { opts.onClose(result); } catch (e) { console.error('[MJ] modal onClose', e); }
				}
				const top = modalStack[modalStack.length - 1];
				if (top) {
					top.focusFirst();
				} else if (previousFocus && typeof previousFocus.focus === 'function' && document.body.contains(previousFocus)) {
					try { previousFocus.focus({ preventScroll: true }); } catch (e) { /* noop */ }
				}
			},
		};

		backdrop.addEventListener('mousedown', function (e) {
			backdrop._downOnBackdrop = (e.target === backdrop);
		});
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop && backdrop._downOnBackdrop && closeOnBackdrop) api.close();
			backdrop._downOnBackdrop = false;
		});
		el.addEventListener('click', function (e) {
			const btn = e.target.closest ? e.target.closest('[data-modal-close]') : null;
			if (btn && el.contains(btn)) { e.preventDefault(); api.close(btn.getAttribute('data-modal-close') || undefined); }
		});

		document.body.appendChild(backdrop);
		document.body.classList.add('mj-modal-open');
		modalStack.push(api);

		if (typeof opts.onMount === 'function') {
			try { opts.onMount(el, api); } catch (e) { console.error('[MJ] modal onMount', e); }
		}
		requestAnimationFrame(function () { backdrop.classList.add('is-visible'); if (open) api.focusFirst(); });
		return api;
	}

	function closeAllModals() {
		modalStack.slice().reverse().forEach(function (m) { m.close(); });
	}

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			const top = modalStack[modalStack.length - 1];
			if (top) {
				const t = e.target;
				const inAutocomplete = t && t.closest && t.closest('.mj-autocomplete') && qs('.mj-autocomplete__list:not(.mj-hidden)', t.closest('.mj-autocomplete'));
				if (inAutocomplete) return; // let the autocomplete close first
				if (top.closeOnEsc) { e.preventDefault(); top.close(); }
				return;
			}
			qsa('.mj-dropdown.is-open').forEach(function (d) { d.classList.remove('is-open'); });
		}
		if (e.key === 'Tab' && modalStack.length) {
			const top = modalStack[modalStack.length - 1];
			const nodes = focusables(top.el);
			if (!nodes.length) { e.preventDefault(); return; }
			const first = nodes[0];
			const last = nodes[nodes.length - 1];
			if (e.shiftKey && (document.activeElement === first || !top.el.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	});

	function confirm(opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			let done = false;
			const finish = function (v) { if (done) return; done = true; resolve(!!v); };
			const okCls = opts.danger ? 'mj-btn mj-btn--danger' : 'mj-btn mj-btn--primary';
			const m = modal({
				title: opts.title || 'Confirmar',
				size: opts.size || 'sm',
				body: opts.messageHtml ? opts.messageHtml : '<p>' + h(opts.message || '¿Estás seguro?') + '</p>',
				footer: '<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>' + h(opts.cancelLabel || 'Cancelar') + '</button>' +
					'<button type="button" class="' + okCls + '" data-confirm-ok>' + h(opts.okLabel || 'Aceptar') + '</button>',
				closeOnBackdrop: opts.closeOnBackdrop !== undefined ? opts.closeOnBackdrop : true,
				onClose() { finish(false); },
				onMount(el, api) {
					const ok = qs('[data-confirm-ok]', el);
					ok.addEventListener('click', function () { finish(true); api.close(true); });
					el.addEventListener('keydown', function (e) {
						if (e.key === 'Enter' && e.target && e.target.tagName !== 'TEXTAREA') { e.preventDefault(); finish(true); api.close(true); }
					});
				},
			});
			requestAnimationFrame(function () { const ok = qs('[data-confirm-ok]', m.el); if (ok) ok.focus(); });
		});
	}

	function prompt(opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			let done = false;
			const finish = function (v) { if (done) return; done = true; resolve(v); };
			const inputHtml = opts.multiline
				? '<textarea class="mj-textarea" name="value" rows="' + (opts.rows || 4) + '" placeholder="' + h(opts.placeholder || '') + '">' + h(opts.value || '') + '</textarea>'
				: '<input class="mj-input" type="' + h(opts.type || 'text') + '" name="value" value="' + h(opts.value || '') + '" placeholder="' + h(opts.placeholder || '') + '"' + (opts.inputmode ? ' inputmode="' + h(opts.inputmode) + '"' : '') + '>';
			modal({
				title: opts.title || 'Ingresar valor',
				size: opts.size || 'sm',
				body: '<form class="mj-form" data-prompt-form>' +
					'<div class="mj-field">' + (opts.label ? '<label class="mj-field__label">' + h(opts.label) + '</label>' : '') + inputHtml +
					(opts.hint ? '<div class="mj-field__hint">' + h(opts.hint) + '</div>' : '') + '</div>' +
					'</form>',
				footer: '<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>' + h(opts.cancelLabel || 'Cancelar') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--primary" data-prompt-ok>' + h(opts.okLabel || 'Aceptar') + '</button>',
				onClose() { finish(null); },
				onMount(el, api) {
					const form = qs('[data-prompt-form]', el);
					const field = qs('[name="value"]', form);
					const submit = function () {
						let v = field.value;
						if (opts.trim !== false) v = v.trim();
						if (opts.required && !v) { field.classList.add('is-invalid'); field.focus(); return; }
						finish(v);
						api.close(v);
					};
					form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
					qs('[data-prompt-ok]', el).addEventListener('click', submit);
					if (opts.multiline) {
						field.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); submit(); } });
					}
					requestAnimationFrame(function () { field.focus(); if (field.select && !opts.multiline) field.select(); });
				},
			});
		});
	}

	function spinner(size) {
		return '<span class="mj-spinner' + (size ? ' mj-spinner--' + h(size) : '') + '" aria-hidden="true"></span>';
	}

	function loading(container, text) {
		if (typeof container === 'string') container = qs(container);
		if (!container) return null;
		container.innerHTML = '<div class="mj-loading">' + spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
		return container;
	}

	function empty(opts) {
		opts = opts || {};
		let action = '';
		if (opts.action) {
			if (typeof opts.action === 'string') {
				action = opts.action;
			} else if (typeof opts.action === 'object') {
				const cls = 'mj-btn ' + (opts.action.primary === false ? 'mj-btn--ghost' : 'mj-btn--primary') + (opts.action.className ? ' ' + h(opts.action.className) : '');
				const inner = (opts.action.icon ? MJ.icon(opts.action.icon) : '') + '<span>' + h(opts.action.label || 'Acción') + '</span>';
				action = opts.action.href
					? '<a class="' + cls + '" href="' + h(opts.action.href) + '">' + inner + '</a>'
					: '<button type="button" class="' + cls + '"' + (opts.action.action ? ' data-action="' + h(opts.action.action) + '"' : '') + '>' + inner + '</button>';
			}
		}
		return '<div class="mj-empty">' +
			(opts.icon ? '<div class="mj-empty__icon">' + MJ.icon(opts.icon) + '</div>' : '') +
			(opts.title ? '<div class="mj-empty__title">' + h(opts.title) + '</div>' : '') +
			(opts.text ? '<div class="mj-empty__text">' + h(opts.text) + '</div>' : '') +
			(action ? '<div class="mj-empty__action">' + action + '</div>' : '') +
			'</div>';
	}

	function alertHtml(message, type, opts) {
		opts = opts || {};
		type = ['info', 'warning', 'danger', 'success'].indexOf(type) >= 0 ? type : 'info';
		const iconName = type === 'success' ? 'check-circle' : (type === 'danger' ? 'error' : (type === 'warning' ? 'warning' : 'info'));
		return '<div class="mj-alert mj-alert--' + type + (opts.className ? ' ' + h(opts.className) : '') + '" role="' + (type === 'danger' ? 'alert' : 'status') + '">' +
			MJ.icon(iconName) + '<div class="mj-alert__body">' + (opts.html ? message : h(message)) + '</div></div>';
	}

	function statusInfo(key) {
		const statuses = MJ.state.statuses || {};
		const s = statuses[key];
		if (s && typeof s === 'object') return { label: s.label || key, color: s.color || 'neutral' };
		return { label: key ? String(key) : '—', color: 'neutral' };
	}

	function badge(statusKey, labelOverride) {
		const info = statusInfo(statusKey);
		const color = ['neutral', 'warning', 'success', 'danger', 'info'].indexOf(info.color) >= 0 ? info.color : 'neutral';
		return '<span class="mj-badge mj-badge--' + color + ' mj-badge--status-' + h(String(statusKey || '').replace(/[^a-z0-9_-]/gi, '')) + '">' + h(labelOverride || info.label) + '</span>';
	}

	function badgeRaw(text, color) {
		color = ['neutral', 'warning', 'success', 'danger', 'info'].indexOf(color) >= 0 ? color : 'neutral';
		return '<span class="mj-badge mj-badge--' + color + '">' + h(text) + '</span>';
	}

	function statusLabel(key) { return statusInfo(key).label; }

	function debounce(fn, ms) {
		let timer = null;
		const wrapped = function () {
			const ctx = this;
			const args = arguments;
			if (timer) clearTimeout(timer);
			timer = setTimeout(function () { timer = null; fn.apply(ctx, args); }, ms || 250);
		};
		wrapped.cancel = function () { if (timer) clearTimeout(timer); timer = null; };
		wrapped.flush = function () { if (timer) { clearTimeout(timer); timer = null; fn.apply(this, arguments); } };
		return wrapped;
	}

	function on(root, event, selector, handler) {
		if (typeof root === 'string') root = qs(root);
		if (!root) return function () {};
		if (typeof selector === 'function') { handler = selector; selector = null; }
		const events = String(event).split(/\s+/).filter(Boolean);
		const fn = function (e) {
			let t = e.target;
			if (t && t.nodeType === 3) t = t.parentElement;
			if (!t || !t.closest) return;
			if (!selector) { handler.call(root, e, root); return; }
			const match = t.closest(selector);
			if (match && root.contains(match)) handler.call(match, e, match);
		};
		events.forEach(function (ev) { root.addEventListener(ev, fn, ev === 'focus' || ev === 'blur'); });
		return function () { events.forEach(function (ev) { root.removeEventListener(ev, fn, ev === 'focus' || ev === 'blur'); }); };
	}

	function setLoading(btn, isLoading) {
		if (typeof btn === 'string') btn = qs(btn);
		if (!btn) return;
		if (isLoading) {
			if (btn.dataset.mjLoading === '1') return;
			btn.dataset.mjLoading = '1';
			btn.dataset.mjWasDisabled = btn.disabled ? '1' : '0';
			btn.disabled = true;
			btn.classList.add('is-loading');
			btn.setAttribute('aria-busy', 'true');
			const sp = document.createElement('span');
			sp.className = 'mj-spinner mj-spinner--sm mj-btn__spinner';
			sp.setAttribute('aria-hidden', 'true');
			btn.insertBefore(sp, btn.firstChild);
		} else {
			if (btn.dataset.mjLoading !== '1') return;
			delete btn.dataset.mjLoading;
			btn.disabled = btn.dataset.mjWasDisabled === '1';
			delete btn.dataset.mjWasDisabled;
			btn.classList.remove('is-loading');
			btn.removeAttribute('aria-busy');
			const sp = qs('.mj-btn__spinner', btn);
			if (sp) sp.parentNode.removeChild(sp);
		}
	}

	function castFieldValue(el) {
		const type = el.dataset ? el.dataset.type : null;
		let v = el.value;
		if (type === 'number') {
			v = String(v === undefined || v === null ? '' : v).trim();
			if (v === '') return null;
			return parseNumber(v);
		}
		if (type === 'int') {
			v = String(v === undefined || v === null ? '' : v).trim();
			if (v === '') return null;
			const n = parseInt(parseNumber(v), 10);
			return isNaN(n) ? NaN : n;
		}
		if (type === 'bool') return v === '1' || v === 'true' || v === 'on' || v === 'yes';
		if (type === 'json') { try { return JSON.parse(v); } catch (e) { return null; } }
		return v;
	}

	function assignPath(out, name, value, push) {
		const m = String(name).match(/^([^[\]]+)((?:\[[^[\]]*\])*)$/);
		if (!m) { out[name] = value; return; }
		const root = m[1];
		const parts = [];
		const re = /\[([^[\]]*)\]/g;
		let mm;
		while ((mm = re.exec(m[2])) !== null) parts.push(mm[1]);
		if (!parts.length) {
			if (push) { if (!Array.isArray(out[root])) out[root] = []; out[root].push(value); }
			else out[root] = value;
			return;
		}
		let cur = out;
		let key = root;
		for (let i = 0; i < parts.length; i++) {
			const p = parts[i];
			if (p === '') {
				if (!Array.isArray(cur[key])) cur[key] = [];
				if (i === parts.length - 1) { cur[key].push(value); return; }
				const arr = cur[key];
				const obj = {};
				arr.push(obj);
				cur = arr;
				key = arr.length - 1;
				continue;
			}
			if (i === parts.length - 1) {
				if (typeof cur[key] !== 'object' || cur[key] === null || Array.isArray(cur[key]) && !(p in cur[key])) {
					if (typeof cur[key] !== 'object' || cur[key] === null) cur[key] = {};
				}
				cur[key][p] = value;
				return;
			}
			if (typeof cur[key] !== 'object' || cur[key] === null) cur[key] = {};
			cur = cur[key];
			key = p;
		}
	}

	function formData(form) {
		if (typeof form === 'string') form = qs(form);
		const out = {};
		if (!form) return out;
		const elements = form.elements ? Array.prototype.slice.call(form.elements) : qsa('input, select, textarea', form);
		const checkboxCounts = {};
		elements.forEach(function (el) {
			if (el.type === 'checkbox' && el.name) checkboxCounts[el.name] = (checkboxCounts[el.name] || 0) + 1;
		});
		elements.forEach(function (el) {
			const name = el.name;
			if (!name || el.disabled) return;
			const type = (el.type || '').toLowerCase();
			if (['submit', 'button', 'reset', 'fieldset', 'file', 'image'].indexOf(type) >= 0) return;
			if (type === 'checkbox') {
				const isArray = /\[\]$/.test(name) || checkboxCounts[name] > 1;
				if (isArray) {
					const key = name.replace(/\[\]$/, '');
					if (!Array.isArray(out[key])) out[key] = [];
					if (el.checked) out[key].push(castFieldValue(el));
				} else {
					assignPath(out, name, el.checked);
				}
				return;
			}
			if (type === 'radio') {
				if (el.checked) assignPath(out, name, castFieldValue(el));
				else if (out[name] === undefined && !/\[/.test(name)) out[name] = null;
				return;
			}
			if (el.tagName === 'SELECT' && el.multiple) {
				assignPath(out, name.replace(/\[\]$/, ''), Array.prototype.slice.call(el.selectedOptions).map(function (o) { return o.value; }));
				return;
			}
			if (/\[\]$/.test(name)) { assignPath(out, name, castFieldValue(el)); return; }
			assignPath(out, name, castFieldValue(el));
		});
		return out;
	}

	function clearErrors(form) {
		if (typeof form === 'string') form = qs(form);
		if (!form) return;
		qsa('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
		qsa('.mj-field__error[data-generated]', form).forEach(function (el) { el.parentNode.removeChild(el); });
		qsa('.mj-form__errors', form).forEach(function (el) { el.parentNode.removeChild(el); });
	}

	function showErrors(form, errors) {
		if (typeof form === 'string') form = qs(form);
		if (!form) return;
		clearErrors(form);
		let fields = {};
		const general = [];
		if (!errors) return;
		if (errors instanceof Error) {
			const params = errors.data && errors.data.params && typeof errors.data.params === 'object' ? errors.data.params : null;
			if (params && Object.keys(params).length) fields = params;
			else general.push(errors.message || 'Ocurrió un error.');
		} else if (typeof errors === 'string') {
			general.push(errors);
		} else if (Array.isArray(errors)) {
			errors.forEach(function (e) { if (e) general.push(typeof e === 'string' ? e : (e.message || String(e))); });
		} else if (typeof errors === 'object') {
			fields = errors;
		}
		let firstInvalid = null;
		Object.keys(fields).forEach(function (name) {
			let msg = fields[name];
			if (Array.isArray(msg)) msg = msg.join(' ');
			if (msg && typeof msg === 'object') msg = msg.message || JSON.stringify(msg);
			msg = String(msg || 'Valor inválido');
			const input = qs('[name="' + cssEscape(name) + '"], [name="' + cssEscape(name) + '[]"]', form);
			if (!input) { general.push(name === '_' || name === 'general' ? msg : msg); return; }
			input.classList.add('is-invalid');
			if (!firstInvalid) firstInvalid = input;
			const field = input.closest('.mj-field') || input.parentElement;
			if (field) {
				const err = document.createElement('div');
				err.className = 'mj-field__error';
				err.setAttribute('data-generated', '1');
				err.textContent = msg;
				field.appendChild(err);
			}
		});
		if (general.length) {
			const target = qs('[data-errors]', form) || form;
			const box = document.createElement('div');
			box.className = 'mj-alert mj-alert--danger mj-form__errors';
			box.setAttribute('role', 'alert');
			box.innerHTML = MJ.icon('error') + '<div class="mj-alert__body">' + (general.length === 1 ? h(general[0]) : '<ul>' + general.map(function (g) { return '<li>' + h(g) + '</li>'; }).join('') + '</ul>') + '</div>';
			target.insertBefore(box, target.firstChild);
		}
		if (firstInvalid) { try { firstInvalid.focus({ preventScroll: false }); } catch (e) { /* noop */ } }
	}

	function tabs(root, opts) {
		if (typeof root === 'string') root = qs(root);
		opts = opts || {};
		if (!root) return { activate() {}, destroy() {} };
		const panelsRoot = opts.panels ? (typeof opts.panels === 'string' ? qs(opts.panels) : opts.panels) : root.parentElement;
		const activate = function (key, silent) {
			qsa('.mj-tabs__tab', root).forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-tab') === key); t.setAttribute('aria-selected', t.getAttribute('data-tab') === key ? 'true' : 'false'); });
			if (panelsRoot) qsa('[data-tab-panel]', panelsRoot).forEach(function (p) { p.classList.toggle('mj-hidden', p.getAttribute('data-tab-panel') !== key); });
			if (!silent && typeof opts.onChange === 'function') opts.onChange(key);
		};
		const off = on(root, 'click', '.mj-tabs__tab[data-tab]', function (e, el) { e.preventDefault(); activate(el.getAttribute('data-tab')); });
		const first = qs('.mj-tabs__tab[data-tab]', root);
		activate(opts.active || (first ? first.getAttribute('data-tab') : ''), true);
		return { activate: activate, destroy: off };
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(String(text));
		return new Promise(function (resolve, reject) {
			const ta = document.createElement('textarea');
			ta.value = String(text);
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); resolve(); } catch (e) { reject(e); }
			document.body.removeChild(ta);
		});
	}

	MJ.ui = {
		h: h,
		attr: h,
		toast: toast,
		toastError(err, fallback) {
			if (err && err.handled) return null;
			return toast(MJ.api.message(err, fallback), 'error');
		},
		modal: modal,
		closeAllModals: closeAllModals,
		modalCount() { return modalStack.length; },
		confirm: confirm,
		prompt: prompt,
		spinner: spinner,
		loading: loading,
		empty: empty,
		alert: alertHtml,
		badge: badge,
		badgeRaw: badgeRaw,
		pill(text, className) { return '<span class="mj-pill' + (className ? ' ' + h(className) : '') + '">' + h(text) + '</span>'; },
		statusLabel: statusLabel,
		statusInfo: statusInfo,
		debounce: debounce,
		qs: qs,
		qsa: qsa,
		on: on,
		setLoading: setLoading,
		formData: formData,
		showErrors: showErrors,
		clearErrors: clearErrors,
		tabs: tabs,
		copyText: copyText,
		cssEscape: cssEscape,
		/** Fill a <select> with [{value,label,disabled?}] preserving/setting value. */
		fillSelect(select, options, value, placeholder) {
			if (!select) return;
			const current = value !== undefined ? value : select.value;
			select.innerHTML = (placeholder !== undefined && placeholder !== null ? '<option value="">' + h(placeholder) + '</option>' : '') +
				(options || []).map(function (o) {
					return '<option value="' + h(o.value) + '"' + (o.disabled ? ' disabled' : '') + (o.attrs ? ' ' + o.attrs : '') + '>' + h(o.label) + '</option>';
				}).join('');
			if (current !== undefined && current !== null && current !== '') select.value = String(current);
		},
		scrollTop() { try { window.scrollTo({ top: 0, behavior: 'auto' }); } catch (e) { window.scrollTo(0, 0); } },
	};

	/* Global dropdown handling: [data-dropdown-toggle] toggles closest .mj-dropdown; click outside closes. */
	document.addEventListener('click', function (e) {
		const t = e.target && e.target.closest ? e.target : null;
		const toggle = t ? t.closest('[data-dropdown-toggle]') : null;
		const dd = toggle ? toggle.closest('.mj-dropdown') : null;
		qsa('.mj-dropdown.is-open').forEach(function (d) { if (d !== dd) d.classList.remove('is-open'); });
		if (dd) {
			e.preventDefault();
			const willOpen = !dd.classList.contains('is-open');
			dd.classList.toggle('is-open', willOpen);
			toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
		}
	});

	/* ------------------------------------------------------------------ */
	/* Router                                                              */
	/* ------------------------------------------------------------------ */

	const routes = [];
	let started = false;

	function compilePattern(pattern) {
		const keys = [];
		let p = String(pattern || '/').trim();
		if (p.charAt(0) !== '/') p = '/' + p;
		p = p.replace(/\/+$/, '');
		const src = p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/:(\w+)/g, function (m, k) { keys.push(k); return '([^/]+)'; });
		return { regex: new RegExp('^' + src + '/?$', 'i'), keys: keys };
	}

	function parseQuery(str) {
		const out = {};
		if (!str) return out;
		let sp;
		try { sp = new URLSearchParams(str); } catch (e) { return out; }
		sp.forEach(function (v, k) {
			if (/\[\]$/.test(k)) { const key = k.slice(0, -2); (out[key] = out[key] || []).push(v); }
			else out[k] = v;
		});
		return out;
	}

	function buildQuery(obj) {
		const pairs = [];
		if (obj) Object.keys(obj).forEach(function (k) { appendParams(pairs, k, obj[k]); });
		return pairs.join('&');
	}

	function normalizePath(path) {
		let p = String(path === undefined || path === null ? '' : path).trim();
		if (p.charAt(0) === '#') p = p.slice(1);
		if (p.charAt(0) === '!') p = p.slice(1);
		if (!p) p = '/';
		if (p.charAt(0) !== '/') p = '/' + p;
		return p;
	}

	function parseHash(hash) {
		let raw = String(hash || '');
		if (raw.charAt(0) === '#') raw = raw.slice(1);
		const q = raw.indexOf('?');
		let path = q >= 0 ? raw.slice(0, q) : raw;
		const query = q >= 0 ? parseQuery(raw.slice(q + 1)) : {};
		path = normalizePath(path);
		try { path = decodeURI(path); } catch (e) { /* keep raw */ }
		if (path.length > 1) path = path.replace(/\/+$/, '');
		return { path: path, query: query };
	}

	function matchRoute(path) {
		for (let i = 0; i < routes.length; i++) {
			const r = routes[i];
			const m = r.regex.exec(path);
			if (!m) continue;
			const params = {};
			r.keys.forEach(function (k, idx) {
				let v = m[idx + 1];
				try { v = decodeURIComponent(v); } catch (e) { /* keep */ }
				params[k] = v;
			});
			return { route: r, params: params };
		}
		return null;
	}

	function currentRoute() {
		const parsed = parseHash(window.location.hash);
		const st = MJ.state.route || {};
		return { name: st.name, path: parsed.path, params: st.params || {}, query: parsed.query, pattern: st.pattern || null };
	}

	function dispatch() {
		const parsed = parseHash(window.location.hash);
		const found = matchRoute(parsed.path);
		if (!found) {
			if (parsed.path !== '/') { router.navigate('/', { replace: true }); return; }
			MJ.events.emit('route:notfound', parsed);
			return;
		}
		const info = { name: found.route.name, path: parsed.path, params: found.params, query: parsed.query, pattern: found.route.pattern };
		MJ.state.route = info;
		MJ.events.emit('route:change', info);
		try {
			found.route.handler(found.params, parsed.query, info);
		} catch (e) {
			console.error('[MJ] route handler error', e);
			MJ.events.emit('route:error', { error: e, route: info });
		}
	}

	const router = {
		register(name, pattern, handler) {
			if (typeof pattern === 'function') { handler = pattern; pattern = name; }
			const compiled = compilePattern(pattern);
			const existing = routes.findIndex(function (r) { return r.name === name; });
			const entry = { name: name, pattern: pattern, regex: compiled.regex, keys: compiled.keys, handler: handler };
			if (existing >= 0) routes[existing] = entry; else routes.push(entry);
			return router;
		},
		routes() { return routes.slice(); },
		navigate(path, opts) {
			opts = opts || {};
			let target = normalizePath(path);
			if (opts.query && Object.keys(opts.query).length) {
				const qsStr = buildQuery(opts.query);
				if (qsStr) target += (target.indexOf('?') >= 0 ? '&' : '?') + qsStr;
			}
			const newHash = '#' + target;
			if (opts.replace) {
				try { window.history.replaceState(null, '', newHash); } catch (e) { window.location.replace(newHash); return; }
				dispatch();
				return;
			}
			if (window.location.hash === newHash) { dispatch(); return; }
			window.location.hash = target;
		},
		/** Update only the query string of the current route (no dispatch unless opts.dispatch). */
		setQuery(query, opts) {
			opts = opts || {};
			const parsed = parseHash(window.location.hash);
			const merged = opts.merge === false ? (query || {}) : Object.assign({}, parsed.query, query || {});
			Object.keys(merged).forEach(function (k) { if (merged[k] === null || merged[k] === undefined || merged[k] === '') delete merged[k]; });
			const qsStr = buildQuery(merged);
			const newHash = '#' + parsed.path + (qsStr ? '?' + qsStr : '');
			if (newHash === window.location.hash) return;
			try {
				if (opts.push) window.history.pushState(null, '', newHash); else window.history.replaceState(null, '', newHash);
			} catch (e) { /* noop */ }
			if (MJ.state.route) MJ.state.route.query = merged;
			if (opts.dispatch) dispatch();
		},
		current: currentRoute,
		link(path, query) {
			let target = normalizePath(path);
			const qsStr = buildQuery(query);
			if (qsStr) target += (target.indexOf('?') >= 0 ? '&' : '?') + qsStr;
			return '#' + target;
		},
		parseHash: parseHash,
		parseQuery: parseQuery,
		buildQuery: buildQuery,
		reload() { dispatch(); },
		back() {
			if (window.history.length > 1) window.history.back(); else router.navigate('/');
		},
		start() {
			if (started) { dispatch(); return; }
			started = true;
			window.addEventListener('hashchange', dispatch);
			if (!window.location.hash || window.location.hash === '#') {
				try { window.history.replaceState(null, '', '#/'); } catch (e) { window.location.hash = '/'; return; }
			}
			dispatch();
		},
	};
	MJ.router = router;

	/* ------------------------------------------------------------------ */
	/* Bootstrap & state refreshers                                        */
	/* ------------------------------------------------------------------ */

	function asList(res, keys) {
		if (Array.isArray(res)) return res;
		if (res && typeof res === 'object') {
			for (let i = 0; i < keys.length; i++) if (Array.isArray(res[keys[i]])) return res[keys[i]];
		}
		return [];
	}

	function applyTheme() {
		const theme = MJ.setting('theme', 'dark') === 'light' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', theme);
	}

	function applyBootstrap(data) {
		data = data && typeof data === 'object' ? data : {};
		MJ.state.bootstrap = data;
		if (data.settings && typeof data.settings === 'object') MJ.state.settings = Object.assign({}, MJ.state.settings, data.settings);
		if (data.statuses && typeof data.statuses === 'object') MJ.state.statuses = Object.assign({}, data.statuses);
		if (data.user && typeof data.user === 'object') MJ.state.user = Object.assign({}, MJ.state.user, data.user);
		MJ.state.suppliers = asList(data.suppliers, ['items']);
		MJ.state.cashboxes = asList(data.cashboxes, ['items']);
		fmt.resetCache();
		applyTheme();
		MJ.events.emit('bootstrap:loaded', data);
		return data;
	}

	let bootPromise = null;

	MJ.bootstrap = function (force) {
		if (MJ.state.bootstrap && !force) return Promise.resolve(MJ.state.bootstrap);
		if (bootPromise) return bootPromise;
		bootPromise = MJ.api.get('bootstrap').then(function (data) {
			bootPromise = null;
			return applyBootstrap(data);
		}, function (err) {
			bootPromise = null;
			throw err;
		});
		return bootPromise;
	};

	MJ.refreshSuppliers = function (includeInactive) {
		return MJ.api.get('suppliers', includeInactive ? { include_inactive: 1 } : undefined).then(function (res) {
			const list = asList(res, ['items', 'suppliers']);
			MJ.state.suppliers = includeInactive ? list.filter(function (s) { return s.is_active === undefined || s.is_active === null || !!Number(s.is_active) || s.is_active === true; }) : list;
			if (MJ.state.bootstrap) MJ.state.bootstrap.suppliers = MJ.state.suppliers;
			MJ.events.emit('suppliers:changed', MJ.state.suppliers);
			return list;
		});
	};

	MJ.refreshCashboxes = function (includeInactive) {
		return MJ.api.get('cashboxes', includeInactive ? { include_inactive: 1 } : undefined).then(function (res) {
			const list = asList(res, ['items', 'cashboxes']);
			MJ.state.cashboxes = includeInactive ? list.filter(function (c) { return c.is_active === undefined || c.is_active === null || !!Number(c.is_active) || c.is_active === true; }) : list;
			if (MJ.state.bootstrap) MJ.state.bootstrap.cashboxes = MJ.state.cashboxes;
			MJ.events.emit('cashboxes:changed', MJ.state.cashboxes);
			return list;
		});
	};

	/** Apply settings returned by PUT /settings (or any partial) to state, theme, and emit 'settings:changed'. */
	MJ.applySettings = function (settings) {
		if (!settings || typeof settings !== 'object') return MJ.state.settings;
		MJ.state.settings = Object.assign({}, MJ.state.settings, settings);
		if (MJ.state.bootstrap) MJ.state.bootstrap.settings = MJ.state.settings;
		if (MJ.config) MJ.config.settings = MJ.state.settings;
		fmt.resetCache();
		applyTheme();
		MJ.events.emit('settings:changed', MJ.state.settings);
		return MJ.state.settings;
	};

	/** Helpers for lists in settings. */
	MJ.lists = {
		currencies() {
			const list = MJ.setting('currencies', ['USD', 'ARS']);
			const arr = Array.isArray(list) ? list.map(function (c) { return String(c).toUpperCase(); }) : ['USD', 'ARS'];
			const base = String(MJ.setting('base_currency', 'USD')).toUpperCase();
			const local = String(MJ.setting('local_currency', 'ARS')).toUpperCase();
			[base, local].forEach(function (c) { if (c && arr.indexOf(c) === -1) arr.push(c); });
			return arr;
		},
		expenseTypes() {
			const list = MJ.setting('expense_types', []);
			return Array.isArray(list) ? list.filter(function (t) { return t && t.key; }) : [];
		},
		priceLists() {
			const list = MJ.setting('price_lists', []);
			return Array.isArray(list) ? list.filter(function (t) { return t && t.key; }) : [];
		},
		destinations() {
			const list = MJ.setting('destinations', []);
			return Array.isArray(list) ? list.map(String) : [];
		},
		expenseTypeLabel(key) {
			const t = MJ.lists.expenseTypes().find(function (x) { return x.key === key; });
			return t ? t.label : (key || '');
		},
		priceList(key) {
			return MJ.lists.priceLists().find(function (x) { return x.key === key; }) || null;
		},
	};

	applyTheme();
})();
