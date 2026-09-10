/**
 * MJ ERP — portal.js
 * La pantalla del revendedor. Autocontenida a propósito: no comparte nada con el bundle del ERP,
 * así en el navegador del revendedor no hay ni código muerto de la parte de administración.
 *
 * Tres cosas: qué tiene, avisar que vendió, y cuánto debe.
 *
 * REST usado (todo bajo mjerp/v1/portal/, resuelto siempre desde la sesión):
 *   GET  /me          estado inicial completo
 *   POST /sales       avisar una venta
 *   DELETE /sales/{id} deshacer un aviso que todavía no se confirmó
 *   GET  /account     resumen de cuenta con el saldo corrido
 *   POST /returns     devolver lo que no se vendió (si está habilitado)
 *   POST /payments    avisar un pago (si está habilitado)
 *   DELETE /payments/{id} deshacer un pago que todavía no se confirmó
 *
 * Qué puede hacer cada revendedor sale de `CFG.can`, resuelto en el servidor a partir del ajuste
 * general, su rol y su ficha. Acá solo se dibuja lo que está habilitado; el servidor lo vuelve a
 * comprobar en cada llamada.
 */
(function () {
	'use strict';

	const CFG = window.MJPORTAL || {};

	/** Última novedad que el revendedor ya miró, por navegador. */
	const LOG_SEEN_KEY = 'mjerp-portal-log-seen';
	const ROOT_ID = 'mjerp-portal';

	/* ------------------------------------------------------------------ */
	/* Utilidades                                                          */
	/* ------------------------------------------------------------------ */

	function h(value) {
		if (value === null || value === undefined) return '';
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function qs(sel, root) { return (root || document).querySelector(sel); }
	function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function toNumber(value) {
		if (typeof value === 'number') return Number.isFinite(value) ? value : null;
		if (value === null || value === undefined || value === '') return null;
		const n = Number(String(value).replace(/\s/g, '').replace(/\.(?=\d{3}\b)/g, '').replace(',', '.'));
		return Number.isFinite(n) ? n : null;
	}

	function f(value) { const n = toNumber(value); return n === null ? 0 : n; }

	const locale = CFG.locale || 'es-AR';

	function number(value, decimals) {
		const n = toNumber(value);
		if (n === null) return '—';
		const d = decimals === undefined ? 2 : decimals;
		try {
			return new Intl.NumberFormat(locale, { minimumFractionDigits: d, maximumFractionDigits: d }).format(n);
		} catch (e) {
			return n.toFixed(d);
		}
	}

	function qty(value) {
		const n = toNumber(value);
		if (n === null) return '—';
		try {
			return new Intl.NumberFormat(locale, { minimumFractionDigits: 0, maximumFractionDigits: 4 }).format(n);
		} catch (e) {
			return String(n);
		}
	}

	function symbol(currency) {
		const cur = String(currency || CFG.baseCurrency || 'USD').toUpperCase();
		const symbols = CFG.symbols || {};
		return symbols[cur] ? String(symbols[cur]) : cur;
	}

	/** Importe en moneda base (la moneda en la que está la deuda). */
	function money(value, currency) {
		const n = toNumber(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return (n < 0 ? '-' : '') + symbol(currency) + ' ' + number(Math.abs(n), decimals);
	}

	function date(value) {
		if (!value) return '';
		const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
		if (!m) return String(value);
		return m[3] + '/' + m[2] + '/' + m[1];
	}

	function datetime(value) {
		if (!value) return '';
		const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
		if (!m) return date(value);
		return m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5];
	}

	/**
	 * El mismo importe en pesos, al dólar de hoy. '' si no hay TC.
	 *
	 * La cuenta se lleva en dólares; esto es para que el revendedor sepa cuánta plata es hoy sin
	 * tener que hacer la cuenta él.
	 */
	function localOf(value) {
		const n = toNumber(value);
		const rate = f(CFG.rate);
		if (n === null || rate <= 0) return '';
		return money(n * rate, CFG.localCurrency || 'ARS');
	}

	/** "US$ 14 · $ 21.996" */
	function moneyBoth(value) {
		const local = localOf(value);
		return money(value) + (local ? ' · ' + local : '');
	}

	function icon(name) {
		const paths = {
			box: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
			cart: '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
			cash: '<rect x="1" y="6" width="22" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
			check: '<polyline points="20 6 9 17 4 12"/>',
			clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
			info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
			warning: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
			logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
			undo: '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
			x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
			list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
			store: '<path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3"/><path d="M3 9h18l-1 11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/>',
		};
		const body = paths[name] || paths.info;
		return '<svg class="mj-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                 */
	/* ------------------------------------------------------------------ */

	function request(method, path, body) {
		const url = String(CFG.restUrl || '').replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
		const opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'X-WP-Nonce': CFG.nonce || '',
			},
		};
		if (body !== undefined && body !== null) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}

		return fetch(url, opts).then(function (res) {
			return res.text().then(function (text) {
				let data = null;
				try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
				if (!res.ok) {
					const err = new Error((data && data.message) ? data.message : 'No se pudo completar la operación.');
					err.status = res.status;
					err.code = data && data.code ? data.code : '';
					throw err;
				}
				return data;
			});
		});
	}

	const api = {
		get(path) { return request('GET', path); },
		post(path, body) { return request('POST', path, body || {}); },
		del(path) { return request('DELETE', path); },
	};

	/* ------------------------------------------------------------------ */
	/* Estado                                                              */
	/* ------------------------------------------------------------------ */

	const state = {
		tab: 'stock',
		data: null,
		statement: null,
		payments: null,
		error: null,
		busy: false,
		toast: null,
	};

	/* ------------------------------------------------------------------ */
	/* Comprobantes de pago                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * El widget de subida lo pone MJ Medios de Pago (window.MJPM.uploader). Acá
	 * solo se dibuja el contenedor y se lo monta: la subida, la validación y el
	 * estado los maneja ese plugin, igual que en la tienda.
	 */
	function uploaderAvailable() {
		return !!(window.MJPM && window.MJPM.uploader);
	}

	function uploaderHtml(paymentId, title) {
		return '<div class="mj-portal__uploader" data-mjpm-uploader' +
			' data-context="erp_payment" data-objeto="' + parseInt(paymentId, 10) + '"' +
			' data-token="" data-title="' + h(title || '') + '"></div>';
	}

	/** Monta los contenedores que acaban de entrar al DOM. */
	function mountUploaders(root) {
		if (!uploaderAvailable()) return;
		try {
			window.MJPM.uploader.mountAll(root || document);
		} catch (e) { /* El widget no debe romper el portal. */ }
	}

	/** Los pagos que todavía esperan un comprobante (o que fueron rechazados). */
	function paymentsAwaitingReceipt() {
		const rows = Array.isArray(state.payments) ? state.payments : [];
		return rows.filter(function (row) {
			const r = row && row.receipt;
			return r && r.state !== 'aprobado';
		});
	}

	function receiptsHtml() {
		const rows = paymentsAwaitingReceipt();
		if (!rows.length || !uploaderAvailable()) return '';

		const noun = (rows[0].receipt && rows[0].receipt.noun_title) || 'Comprobante';

		return '<div class="mj-portal__receipts">' +
			'<div class="mj-portal__receipts-title">' + h(noun) + '</div>' +
			rows.map(function (row) {
				const r = row.receipt || {};
				const label = money(row.amount_base) + ' · ' + h(row.method_label || '') +
					(row.payment_date ? ' · ' + h(date(row.payment_date)) : '');
				return '<div class="mj-portal__receipt">' +
					'<div class="mj-portal__receipt-head">' +
						'<span class="mj-portal__receipt-label">' + label + '</span>' +
						(r.state_label ? '<span class="mj-portal__receipt-state is-' + h(r.state) + '">' + h(r.state_label) + '</span>' : '') +
					'</div>' +
					(r.reason ? '<div class="mj-portal__receipt-reason">' + h(r.reason) + '</div>' : '') +
					uploaderHtml(row.id, '') +
				'</div>';
			}).join('') +
		'</div>';
	}

	/** Permisos resueltos por el servidor. */
	function can(what) {
		const abilities = (state.data && state.data.can) || CFG.can || {};
		return !!abilities[what];
	}

	function paymentMethods() {
		const list = (state.data && state.data.payment_methods) || CFG.paymentMethods || [];
		return Array.isArray(list) ? list : [];
	}

	function root() { return document.getElementById(ROOT_ID); }

	function toast(message, kind) {
		const el = qs('[data-toast]');
		if (!el) return;
		el.className = 'mj-portal__toast is-visible' + (kind ? ' is-' + kind : '');
		el.textContent = message;
		if (state.toast) window.clearTimeout(state.toast);
		state.toast = window.setTimeout(function () {
			el.className = 'mj-portal__toast';
		}, 4000);
	}

	/* ------------------------------------------------------------------ */
	/* Vistas                                                              */
	/* ------------------------------------------------------------------ */

	function shellHtml() {
		const consignee = (CFG.consignee || {});
		const avatar = consignee.avatar
			? '<img class="mj-avatar" src="' + h(consignee.avatar) + '" alt="" width="36" height="36">'
			: '';
		return '<div class="mj-portal">' +
			'<header class="mj-portal__head">' +
				'<div class="mj-row mj-row--center">' + avatar +
					'<div>' +
						'<div class="mj-portal__name">' + h(consignee.name || '') + '</div>' +
						'<div class="mj-portal__site mj-small mj-muted">' + h(CFG.siteName || '') + '</div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-row">' +
					(CFG.homeUrl ? '<a class="mj-btn mj-btn--sm mj-btn--ghost" href="' + h(CFG.homeUrl) + '">' + icon('store') + '<span>Tienda</span></a>' : '') +
					'<a class="mj-btn mj-btn--sm mj-btn--ghost" href="' + h(CFG.logoutUrl || '#') + '">' + icon('logout') + '<span>Salir</span></a>' +
				'</div>' +
			'</header>' +
			'<div data-region="body"><div class="mj-loading">Cargando…</div></div>' +
			'<div class="mj-portal__toast" data-toast role="status" aria-live="polite"></div>' +
		'</div>';
	}

	function summaryHtml(account) {
		const balance = f(account.balance_base);
		const owes = balance > 0.005;
		const credit = balance < -0.005;

		return '<div class="mj-portal__hero' + (owes ? ' is-owing' : '') + '">' +
			'<div class="mj-portal__hero-label">' + h(owes ? 'Tenés que pagar' : (credit ? 'Tenés a favor' : 'Estás al día')) + '</div>' +
			'<div class="mj-portal__hero-value">' + h(money(Math.abs(balance))) + '</div>' +
			(localOf(Math.abs(balance)) ? '<div class="mj-portal__hero-local">' + h(localOf(Math.abs(balance))) + '</div>' : '') +
			'<div class="mj-portal__hero-hint">' +
				h(owes
					? (account.unpaid_sales === 1 ? '1 venta sin pagar' : account.unpaid_sales + ' ventas sin pagar')
					: (credit ? 'Se descuenta de tu próxima venta' : 'No debés nada')) +
			'</div>' +
			(f(account.declared_base) >= 0.005
				? '<div class="mj-portal__hero-hint">' + h('Avisaste ' + money(account.declared_base) + ' que todavía no confirmaron.') + '</div>'
				: '') +
			(can('register_payment') && owes
				? '<button type="button" class="mj-btn mj-btn--primary mj-portal__hero-action" data-action="pay">' + icon('cash') + '<span>Avisar un pago</span></button>'
				: '') +
		'</div>' +
		'<div class="mj-portal__stats">' +
			'<div class="mj-portal__stat">' +
				'<div class="mj-portal__stat-value">' + h(qty(account.on_hand_units)) + '</div>' +
				'<div class="mj-portal__stat-label">Unidades que tenés</div>' +
			'</div>' +
			'<div class="mj-portal__stat">' +
				'<div class="mj-portal__stat-value">' + h(money(account.charges_base)) + '</div>' +
				'<div class="mj-portal__stat-label">Vendiste en total</div>' +
			'</div>' +
			'<div class="mj-portal__stat">' +
				'<div class="mj-portal__stat-value">' + h(money(account.credits_base)) + '</div>' +
				'<div class="mj-portal__stat-label">Pagaste en total</div>' +
			'</div>' +
		'</div>';
	}

	function tabsHtml(active, account) {
		const tabs = [
			{ key: 'stock', label: 'Lo que tengo', icon: 'box' },
			{ key: 'ventas', label: 'Mis ventas', icon: 'cart', badge: parseInt(account.pending_sales, 10) || 0 },
			{ key: 'cuenta', label: 'Mi cuenta', icon: 'list' },
			{ key: 'novedades', label: 'Novedades', icon: 'clock', badge: unreadLog() },
		];
		return '<nav class="mj-portal__tabs" role="tablist">' +
			tabs.map(function (t) {
				const on = t.key === active;
				return '<button type="button" class="mj-portal__tab' + (on ? ' is-active' : '') + '" role="tab" ' +
					'aria-selected="' + (on ? 'true' : 'false') + '" data-tab="' + h(t.key) + '">' +
					icon(t.icon) + '<span>' + h(t.label) + '</span>' +
					(t.badge ? '<span class="mj-portal__tab-badge">' + h(t.badge) + '</span>' : '') +
				'</button>';
			}).join('') +
		'</nav>';
	}

	/**
	 * Novedades que todavía no miró, contra la última visita guardada en el navegador.
	 *
	 * Es a propósito local: no vale la pena una columna en la base para saber si alguien miró
	 * una pantalla, y si cambia de teléfono lo peor que pasa es que las vea de nuevo.
	 */
	function unreadLog() {
		const rows = (state.data && state.data.log && state.data.log.items) || [];
		if (!rows.length) return 0;
		let seen = '';
		try { seen = window.localStorage.getItem(LOG_SEEN_KEY) || ''; } catch (e) { seen = ''; }
		if (!seen) return rows.length;
		return rows.filter(function (row) { return String(row.created_at || '') > seen; }).length;
	}

	function markLogSeen() {
		const rows = (state.data && state.data.log && state.data.log.items) || [];
		if (!rows.length) return;
		try { window.localStorage.setItem(LOG_SEEN_KEY, String(rows[0].created_at || '')); } catch (e) { /* sin storage, se ven siempre */ }
	}

	function stockHtml(stock, canSell, canReturn) {
		if (!stock.length) {
			return '<div class="mj-portal__empty">' + icon('box') +
				'<div class="mj-portal__empty-title">No tenés mercadería</div>' +
				'<div class="mj-portal__empty-text">Cuando te entreguemos productos van a aparecer acá.</div>' +
			'</div>';
		}

		return '<div class="mj-portal__list">' +
			stock.map(function (row) {
				return '<div class="mj-portal__card" data-item="' + h(row.id) + '">' +
					'<div class="mj-portal__card-main">' +
						'<div class="mj-portal__card-title">' + h(row.name || '') + '</div>' +
						(row.sku ? '<div class="mj-portal__card-sub">SKU ' + h(row.sku) + '</div>' : '') +
						'<div class="mj-portal__card-sub">' +
							'Te quedan <strong>' + h(qty(row.qty_on_hand)) + '</strong> de ' + h(qty(row.qty)) +
							' · ' + h(moneyBoth(row.unit_price_base)) + ' cada uno' +
						'</div>' +
					'</div>' +
					'<div class="mj-portal__card-actions">' +
						(canSell ? '<button type="button" class="mj-btn mj-btn--primary" data-action="sell">' + icon('cart') + '<span>Vendí</span></button>' : '') +
						(canReturn ? '<button type="button" class="mj-btn mj-btn--ghost mj-btn--sm" data-action="return">' + icon('undo') + '<span>Devolver</span></button>' : '') +
					'</div>' +
				'</div>';
			}).join('') +
		'</div>';
	}

	function salesHtml(sales, needsApproval) {
		const rows = (sales && sales.items) || [];
		if (!rows.length) {
			return '<div class="mj-portal__empty">' + icon('cart') +
				'<div class="mj-portal__empty-title">Todavía no cargaste ventas</div>' +
				'<div class="mj-portal__empty-text">Cuando vendas algo, avisanos desde «Lo que tengo».</div>' +
			'</div>';
		}

		/*
		 * Sin confirmación, una venta es simplemente una venta: decirle «Confirmada» sugiere que
		 * alguien tuvo que aprobarla y no es lo que pasa.
		 */
		const labels = {
			pendiente: ['Esperando confirmación', 'warning'],
			aprobada: [needsApproval ? 'Confirmada' : 'Registrada', 'success'],
			rechazada: ['Rechazada', 'neutral'],
		};

		return (needsApproval
			? '<div class="mj-portal__note">' + icon('info') + '<span>Lo que cargás queda esperando que lo confirmemos. Recién ahí se suma a tu cuenta.</span></div>'
			: '<div class="mj-portal__note">' + icon('info') + '<span>Cada venta que avisás se suma a tu cuenta en el momento. Lo que confirmamos nosotros son los pagos.</span></div>') +
		'<div class="mj-portal__list">' +
			rows.map(function (sale) {
				const info = labels[sale.status] || [sale.status, 'neutral'];
				const balance = f(sale.balance_base);
				// El servidor decide: es suya, no tiene pagos aplicados y sigue dentro de la ventana.
				const canUndo = sale.can_undo === true;

				let money_line = '';
				if (sale.status === 'aprobada') {
					money_line = balance <= 0.005
						? '<span class="mj-portal__paid">Pagada</span>'
						: 'Debés <strong>' + h(moneyBoth(balance)) + '</strong>';
				} else if (sale.status === 'pendiente') {
					money_line = 'Serían ' + h(money(sale.amount_base)) + ' cuando la confirmemos';
				}

				return '<div class="mj-portal__card" data-sale="' + h(sale.id) + '" data-status="' + h(sale.status) + '">' +
					'<div class="mj-portal__card-main">' +
						'<div class="mj-portal__card-title">' + h(sale.name || '') + '</div>' +
						'<div class="mj-portal__card-sub">' +
							h(date(sale.sale_date)) + ' · ' + h(qty(sale.qty)) + ' u. · ' + h(money(sale.amount_base)) +
						'</div>' +
						'<div class="mj-portal__card-sub">' +
							'<span class="mj-portal__chip is-' + h(info[1]) + '">' + h(info[0]) + '</span> ' + money_line +
						'</div>' +
						(sale.note ? '<div class="mj-portal__card-sub mj-muted">' + h(sale.note) + '</div>' : '') +
					'</div>' +
					(canUndo
						? '<div class="mj-portal__card-actions">' +
							'<button type="button" class="mj-btn mj-btn--ghost mj-btn--sm" data-action="undo-sale">' + icon('x') + '<span>Deshacer</span></button>' +
						'</div>'
						: '') +
				'</div>';
			}).join('') +
		'</div>';
	}

	/**
	 * Bitácora: qué se le tocó de la cuenta y cuándo.
	 *
	 * Existe porque desde el ERP se pueden corregir precios, ventas y pagos. Sin esto, el
	 * revendedor abre su panel un día y debe otra cosa sin que nadie le haya avisado.
	 */
	function logHtml(log) {
		const rows = (log && log.items) || [];
		if (!rows.length) {
			return '<div class="mj-portal__empty">' + icon('clock') +
				'<div class="mj-portal__empty-title">Sin novedades</div>' +
				'<div class="mj-portal__empty-text">Acá van a aparecer los cambios que hagamos sobre tu cuenta: precios, ventas o pagos.</div>' +
			'</div>';
		}

		const tone = {
			eliminado: 'is-danger',
			editado: 'is-warning',
			confirmado: 'is-success',
			rechazado: 'is-danger',
		};

		return '<div class="mj-portal__list">' +
			rows.map(function (row) {
				return '<div class="mj-portal__card mj-portal__card--log">' +
					'<div class="mj-portal__card-main">' +
						'<div class="mj-portal__card-sub mj-portal__log-when">' + h(datetime(row.created_at)) +
							(row.actor_name ? ' · ' + h(row.actor_name) : '') + '</div>' +
						'<div class="mj-portal__log-text ' + h(tone[row.action] || '') + '">' + h(row.summary || '') + '</div>' +
						(row.reason ? '<div class="mj-portal__card-sub mj-muted">Motivo: ' + h(row.reason) + '</div>' : '') +
					'</div>' +
				'</div>';
			}).join('') +
		'</div>';
	}

	function statementHtml(rows, account) {
		if (!rows) return '<div class="mj-loading">Cargando…</div>';
		if (!rows.length) {
			return '<div class="mj-portal__empty">' + icon('list') +
				'<div class="mj-portal__empty-title">Sin movimientos</div>' +
				'<div class="mj-portal__empty-text">Acá vas a ver cada venta y cada pago, con el saldo al día.</div>' +
			'</div>';
		}

		const credit = f(account && account.unallocated_base);

		return (credit >= 0.005
			? '<div class="mj-portal__note">' + icon('info') + '<span>Tenés ' + h(money(credit)) + ' a favor: se descuenta solo de tu próxima venta.</span></div>'
			: '') +
		'<div class="mj-portal__statement">' +
			rows.slice().reverse().map(function (row) {
				const charge = f(row.charge_base);
				const creditAmount = f(row.credit_base);
				const isCharge = charge > 0.005;
				return '<div class="mj-portal__movement">' +
					'<div class="mj-portal__movement-icon">' + icon(row.type === 'venta' ? 'cart' : 'cash') + '</div>' +
					'<div class="mj-portal__movement-main">' +
						'<div class="mj-portal__movement-text">' + h(row.description || '') + '</div>' +
						'<div class="mj-portal__movement-date mj-small mj-muted">' + h(date(row.date)) + '</div>' +
					'</div>' +
					'<div class="mj-portal__movement-amounts">' +
						'<div class="mj-portal__movement-amount' + (isCharge ? '' : ' is-credit') + '">' +
							h((isCharge ? '+' : '−') + ' ' + money(isCharge ? charge : creditAmount)) +
						'</div>' +
						'<div class="mj-portal__movement-balance mj-small mj-muted">Saldo ' + h(money(row.balance_base)) + '</div>' +
					'</div>' +
				'</div>';
			}).join('') +
		'</div>';
	}

	function bodyHtml() {
		if (state.error) {
			return '<div class="mj-portal__note is-danger">' + icon('warning') + '<span>' + h(state.error) + '</span></div>';
		}
		if (!state.data) return '<div class="mj-loading">Cargando…</div>';

		const account = state.data.account || {};
		let panel = '';
		if (state.tab === 'stock') {
			panel = stockHtml(state.data.stock || [], !!state.data.can_sell, !!state.data.can_return);
		} else if (state.tab === 'novedades') {
			panel = logHtml(state.data.log);
		} else if (state.tab === 'ventas') {
			panel = salesHtml(state.data.sales, !!CFG.needsApproval);
		} else if (can('see_account')) {
			panel = receiptsHtml() + statementHtml(state.statement, account);
		} else {
			panel = '<div class="mj-portal__empty">' + icon('lock') +
				'<div class="mj-portal__empty-title">Tu cuenta la lleva la tienda</div>' +
				'<div class="mj-portal__empty-text">Consultá con quien te entrega la mercadería.</div>' +
			'</div>';
		}

		return summaryHtml(account) + tabsHtml(state.tab, account) + '<div class="mj-portal__panel">' + panel + '</div>';
	}

	function paint() {
		const body = qs('[data-region="body"]');
		if (body) {
			body.innerHTML = bodyHtml();
			mountUploaders(body);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Diálogo de pago                                                     */
	/* ------------------------------------------------------------------ */

	/** Qué comprobante le pide la tienda a este revendedor: resuelto en el servidor. */
	function receiptRequirement() {
		if (!uploaderAvailable()) return 'no';
		const abilities = (state.data && state.data.can) || CFG.can || {};
		const mode = abilities.payment_receipt || 'no';
		/* 'heredar' lo resuelve el servidor al crear el pago; acá no se adivina. */
		return 'heredar' === mode ? '' : mode;
	}

	function receiptNoun() {
		const abilities = (state.data && state.data.can) || CFG.can || {};
		return abilities.receipt_noun || 'comprobante';
	}

	/**
	 * Diálogo con el widget de subida, apenas se avisó el pago.
	 *
	 * @param {number} paymentId Id del pago recién creado.
	 * @param {Object} receipt   Bloque `receipt` que devolvió el servidor.
	 */
	function openReceiptSheet(paymentId, receipt) {
		if (!uploaderAvailable()) return;

		const title = (receipt && receipt.noun_title) || 'Comprobante';
		const sheet = document.createElement('div');
		sheet.className = 'mj-portal__sheet';
		sheet.innerHTML =
			'<div class="mj-portal__sheet-panel" role="dialog" aria-modal="true" aria-label="' + h(title) + '">' +
				'<div class="mj-portal__sheet-title">' + h(title) + '</div>' +
				'<div class="mj-portal__sheet-sub">' +
					h(receipt && receipt.required
						? 'Adjuntalo para que podamos confirmar el pago.'
						: 'Si lo tenés a mano, adjuntalo: confirmamos más rápido.') +
				'</div>' +
				uploaderHtml(paymentId, '') +
				'<div class="mj-portal__sheet-actions">' +
					'<button type="button" class="mj-btn mj-btn--ghost" data-cancel>' +
						h(receipt && receipt.required ? 'Lo subo después' : 'Listo') +
					'</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(sheet);
		document.body.classList.add('mj-portal-locked');

		function close() {
			if (sheet.parentNode) sheet.parentNode.removeChild(sheet);
			document.body.classList.remove('mj-portal-locked');
			loadStatement();
		}

		qs('[data-cancel]', sheet).addEventListener('click', close);
		sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });

		mountUploaders(sheet);
	}

	function openPaymentSheet() {
		const account = (state.data && state.data.account) || {};
		const owed = f(account.balance_base);
		const methods = paymentMethods();
		const partial = can('partial_payments');
		const needsApproval = can('payment_needs_approval');
		const localCurrency = CFG.localCurrency || 'ARS';

		/*
		 * El comprobante se adjunta DESPUÉS de avisar el pago, contra el pago ya
		 * creado: el archivo necesita algo a lo que pegarse, y así se puede avisar
		 * ahora y mandar la foto cuando se la tenga a mano.
		 */
		const receiptMode = receiptRequirement();
		const receiptName = receiptNoun();
		const receiptNotice = 'obligatorio' === receiptMode
			? 'Después de avisar te vamos a pedir el ' + receiptName + '.'
			: ('opcional' === receiptMode ? 'Después de avisar vas a poder adjuntar el ' + receiptName + '.' : '');

		const sheet = document.createElement('div');
		sheet.className = 'mj-portal__sheet';
		sheet.innerHTML =
			'<div class="mj-portal__sheet-panel" role="dialog" aria-modal="true" aria-label="Avisar un pago">' +
				'<div class="mj-portal__sheet-title">Avisar un pago</div>' +
				'<div class="mj-portal__sheet-sub">' +
					h(owed > 0.005
						? 'Debés ' + money(owed) + '.' + (partial ? ' Podés pagar una parte.' : ' Tenés que cubrir el total.')
						: 'No tenés saldo pendiente.') +
				'</div>' +
				'<form class="mj-form" data-pay-form novalidate>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-amount">¿Cuánto pagaste? (' + h(localCurrency) + ')</label>' +
						'<input class="mj-input" id="mjp-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0">' +
					'</div>' +
					(methods.length
						? '<div class="mj-field">' +
							'<label class="mj-field__label" for="mjp-method">¿Cómo?</label>' +
							'<select class="mj-select" id="mjp-method" name="method">' +
								methods.map(function (m) {
									return '<option value="' + h(m.key) + '">' + h(m.label) + '</option>';
								}).join('') +
							'</select>' +
						'</div>'
						: '') +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-pay-date">¿Cuándo?</label>' +
						'<input class="mj-input" id="mjp-pay-date" name="payment_date" type="date" value="' + h(CFG.today || '') + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-ref">Referencia (opcional)</label>' +
						'<input class="mj-input" id="mjp-ref" name="reference" type="text" maxlength="190" placeholder="Nº de operación, últimos dígitos…">' +
					'</div>' +
					(needsApproval
						? '<div class="mj-portal__note">' + icon('info') + '<span>Tu saldo baja cuando la tienda confirme el pago.</span></div>'
						: '') +
					(receiptNotice
						? '<div class="mj-portal__note">' + icon('info') + '<span>' + h(receiptNotice) + '</span></div>'
						: '') +
					'<div class="mj-portal__sheet-error" data-error></div>' +
					'<div class="mj-portal__sheet-actions">' +
						'<button type="button" class="mj-btn mj-btn--ghost" data-cancel>Cancelar</button>' +
						'<button type="submit" class="mj-btn mj-btn--primary" data-submit>' + icon('check') + '<span>Avisar</span></button>' +
					'</div>' +
				'</form>' +
			'</div>';

		document.body.appendChild(sheet);
		document.body.classList.add('mj-portal-locked');

		const form = qs('[data-pay-form]', sheet);
		const amountInput = qs('[name="amount"]', form);
		const errorEl = qs('[data-error]', sheet);
		const submitBtn = qs('[data-submit]', sheet);
		let submitting = false;

		function close() {
			if (sheet.parentNode) sheet.parentNode.removeChild(sheet);
			document.body.classList.remove('mj-portal-locked');
		}

		qs('[data-cancel]', sheet).addEventListener('click', close);
		sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (submitting) return;

			const amount = toNumber(amountInput.value);
			if (amount === null || amount <= 0) {
				errorEl.textContent = 'Poné cuánto pagaste.';
				amountInput.focus();
				return;
			}

			errorEl.textContent = '';
			submitting = true;
			submitBtn.disabled = true;

			const methodEl = qs('[name="method"]', form);
			api.post('payments', {
				amount: amount,
				currency: localCurrency,
				method: methodEl ? methodEl.value : '',
				payment_date: qs('[name="payment_date"]', form).value,
				reference: qs('[name="reference"]', form).value,
			}).then(function (res) {
				close();
				toast(res && res.needs_approval ? 'Listo, avisamos tu pago.' : 'Pago registrado.', 'success');
				if (res && res.receipt && res.id) {
					openReceiptSheet(res.id, res.receipt);
				}
				load();
			}).catch(function (err) {
				submitting = false;
				submitBtn.disabled = false;
				errorEl.textContent = (err && err.message) || 'No se pudo registrar el pago.';
			});
		});

		window.setTimeout(function () { try { amountInput.focus(); } catch (e) { /* noop */ } }, 60);
	}

	/* ------------------------------------------------------------------ */
	/* Diálogo de venta                                                    */
	/* ------------------------------------------------------------------ */

	function openSaleSheet(item) {
		const max = f(item.qty_on_hand);
		const sheet = document.createElement('div');
		sheet.className = 'mj-portal__sheet';
		sheet.innerHTML =
			'<div class="mj-portal__sheet-panel" role="dialog" aria-modal="true" aria-label="Registrar venta">' +
				'<div class="mj-portal__sheet-title">' + h(item.name || '') + '</div>' +
				'<div class="mj-portal__sheet-sub">Te quedan ' + h(qty(max)) + ' unidades a ' + h(money(item.unit_price_base)) + ' cada una.</div>' +
				'<form class="mj-form" data-sale-form novalidate>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-qty">¿Cuántas vendiste?</label>' +
						'<div class="mj-portal__stepper">' +
							'<button type="button" class="mj-btn mj-btn--ghost" data-step="-1" aria-label="Menos">−</button>' +
							'<input class="mj-input" id="mjp-qty" name="qty" type="text" inputmode="decimal" value="1" autocomplete="off">' +
							'<button type="button" class="mj-btn mj-btn--ghost" data-step="1" aria-label="Más">+</button>' +
						'</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-date">¿Cuándo?</label>' +
						'<input class="mj-input" id="mjp-date" name="sale_date" type="date" value="' + h(CFG.today || '') + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mjp-note">Nota (opcional)</label>' +
						'<input class="mj-input" id="mjp-note" name="note" type="text" maxlength="255" placeholder="Ej: se lo llevó un cliente del barrio">' +
					'</div>' +
					'<div class="mj-portal__sheet-total" data-total></div>' +
					'<div class="mj-portal__sheet-error" data-error></div>' +
					'<div class="mj-portal__sheet-actions">' +
						'<button type="button" class="mj-btn mj-btn--ghost" data-cancel>Cancelar</button>' +
						'<button type="submit" class="mj-btn mj-btn--primary" data-submit>' + icon('check') + '<span>' + (CFG.needsApproval ? 'Avisar la venta' : 'Registrar la venta') + '</span></button>' +
					'</div>' +
				'</form>' +
			'</div>';

		document.body.appendChild(sheet);
		document.body.classList.add('mj-portal-locked');

		const form = qs('[data-sale-form]', sheet);
		const qtyInput = qs('[name="qty"]', form);
		const totalEl = qs('[data-total]', sheet);
		const errorEl = qs('[data-error]', sheet);
		const submitBtn = qs('[data-submit]', sheet);
		let submitting = false;

		function close() {
			document.body.classList.remove('mj-portal-locked');
			if (sheet.parentNode) sheet.parentNode.removeChild(sheet);
		}

		function refreshTotal() {
			const value = f(toNumber(qtyInput.value));
			const total = value * f(item.unit_price_base);
			totalEl.innerHTML = value > 0
				? (CFG.needsApproval
					? 'Vas a deber <strong>' + h(moneyBoth(total)) + '</strong> cuando la confirmemos'
					: 'Vas a deber <strong>' + h(moneyBoth(total)) + '</strong>')
				: '';
		}

		qsa('[data-step]', sheet).forEach(function (btn) {
			btn.addEventListener('click', function () {
				const step = parseInt(btn.getAttribute('data-step'), 10) || 0;
				let value = f(toNumber(qtyInput.value)) + step;
				if (value < 1) value = 1;
				if (value > max) value = max;
				qtyInput.value = String(value);
				refreshTotal();
			});
		});

		qtyInput.addEventListener('input', refreshTotal);
		refreshTotal();

		qs('[data-cancel]', sheet).addEventListener('click', close);
		sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (submitting) return;
			errorEl.textContent = '';

			const value = toNumber(qtyInput.value);
			if (value === null || value <= 0) {
				errorEl.textContent = 'Poné cuántas unidades vendiste.';
				return;
			}
			if (value > max + 0.0001) {
				errorEl.textContent = 'Solo te quedan ' + qty(max) + ' unidades.';
				return;
			}

			submitting = true;
			submitBtn.disabled = true;

			api.post('sales', {
				consignment_item_id: parseInt(item.id, 10),
				qty: value,
				sale_date: String(qs('[name="sale_date"]', form).value || ''),
				note: String(qs('[name="note"]', form).value || ''),
			}).then(function (res) {
				close();
				toast(CFG.needsApproval ? 'Listo, avisamos la venta. Queda esperando confirmación.' : 'Venta registrada y sumada a tu cuenta.', 'success');
				if (res && res.stock) state.data.stock = res.stock;
				if (res && res.account) state.data.account = res.account;
				return load();
			}).catch(function (err) {
				submitting = false;
				submitBtn.disabled = false;
				errorEl.textContent = err && err.message ? err.message : 'No se pudo registrar la venta.';
			});
		});

		window.setTimeout(function () { qtyInput.focus(); qtyInput.select(); }, 50);
	}

	function openReturnSheet(item) {
		const max = f(item.qty_on_hand);
		const value = window.prompt('¿Cuántas unidades de «' + (item.name || '') + '» estás devolviendo? (te quedan ' + qty(max) + ')', '1');
		if (value === null) return;
		const parsed = toNumber(value);
		if (parsed === null || parsed <= 0 || parsed > max + 0.0001) {
			toast('Cantidad inválida.', 'danger');
			return;
		}
		api.post('returns', { consignment_item_id: parseInt(item.id, 10), qty: parsed }).then(function () {
			toast('Devolución registrada.', 'success');
			return load();
		}).catch(function (err) {
			toast(err && err.message ? err.message : 'No se pudo registrar la devolución.', 'danger');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Carga                                                               */
	/* ------------------------------------------------------------------ */

	function load() {
		return api.get('me').then(function (res) {
			state.data = res;
			state.error = null;
			paint();
			if (state.tab === 'cuenta') loadStatement();
		}).catch(function (err) {
			state.error = err && err.message ? err.message : 'No se pudo cargar tu cuenta.';
			paint();
		});
	}

	function loadStatement() {
		return api.get('account').then(function (res) {
			state.statement = Array.isArray(res && res.statement) ? res.statement : [];
			/* Los pagos vienen con su comprobante resuelto por el servidor. */
			state.payments = (res && res.payments && Array.isArray(res.payments.items)) ? res.payments.items : [];
			if (res && res.account && state.data) state.data.account = res.account;
			if (state.tab === 'cuenta') paint();
		}).catch(function () {
			state.statement = [];
			state.payments = [];
			if (state.tab === 'cuenta') paint();
		});
	}

	function findItem(id) {
		const stock = (state.data && state.data.stock) || [];
		return stock.find(function (r) { return parseInt(r.id, 10) === id; }) || null;
	}

	/* ------------------------------------------------------------------ */
	/* Arranque                                                            */
	/* ------------------------------------------------------------------ */

	function boot() {
		const el = root();
		if (!el) return;
		el.innerHTML = shellHtml();

		el.addEventListener('click', function (e) {
			const tab = e.target.closest ? e.target.closest('[data-tab]') : null;
			if (tab) {
				state.tab = tab.getAttribute('data-tab');
				if (state.tab === 'novedades') markLogSeen();
				paint();
				if (state.tab === 'cuenta' && state.statement === null) loadStatement();
				return;
			}

			const pay = e.target.closest ? e.target.closest('[data-action="pay"]') : null;
			if (pay) {
				openPaymentSheet();
				return;
			}

			const sell = e.target.closest ? e.target.closest('[data-action="sell"]') : null;
			if (sell) {
				const card = sell.closest('[data-item]');
				const item = card ? findItem(parseInt(card.getAttribute('data-item'), 10)) : null;
				if (item) openSaleSheet(item);
				return;
			}

			const ret = e.target.closest ? e.target.closest('[data-action="return"]') : null;
			if (ret) {
				const card = ret.closest('[data-item]');
				const item = card ? findItem(parseInt(card.getAttribute('data-item'), 10)) : null;
				if (item) openReturnSheet(item);
				return;
			}

			const undo = e.target.closest ? e.target.closest('[data-action="undo-sale"]') : null;
			if (undo) {
				const card = undo.closest('[data-sale]');
				const saleId = card ? parseInt(card.getAttribute('data-sale'), 10) : 0;
				if (!saleId) return;
				// Deshacer una venta ya contada devuelve la unidad y borra la deuda: vale preguntar.
				const pendingUndo = card.getAttribute('data-status') === 'pendiente';
				const question = pendingUndo
					? '¿Deshacer este aviso de venta?'
					: '¿Deshacer esta venta? Vuelve a quedar como unidad tuya y se saca de tu cuenta.';
				if (!window.confirm(question)) return;
				undo.disabled = true;
				api.del('sales/' + saleId).then(function () {
					toast(pendingUndo ? 'Aviso deshecho.' : 'Venta deshecha.', 'success');
					return load();
				}).catch(function (err) {
					undo.disabled = false;
					toast(err && err.message ? err.message : 'No se pudo deshacer.', 'danger');
				});
			}
		});

		load();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
