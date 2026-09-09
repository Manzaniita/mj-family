/**
 * MJ ERP — page-purchase.js
 * MJ.pages.purchase: detalle de una compra (la pantalla principal del plugin).
 * Cabecera con estado y acciones, productos con edición inline, gastos de la importación,
 * costeo en vivo (landed cost + listas de precio), recepción con impacto en WooCommerce y notas.
 *
 * REST usado: GET|PUT|DELETE /purchases/{id}, POST /purchases/{id}/status, POST /purchases/{id}/duplicate,
 * POST /purchases/{id}/items, PUT|DELETE /purchases/{id}/items/{item_id}, POST /purchases/{id}/items/bulk,
 * POST /purchases/{id}/items/{item_id}/link, POST /purchases/{id}/expenses,
 * PUT|DELETE /purchases/{id}/expenses/{expense_id}, POST /purchases/{id}/receive, POST /purchases/{id}/unreceive.
 *
 * Toda mutación devuelve la compra completa: se vuelve a pintar desde la respuesta.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const CUSTOM_DESTINATION = '__custom__';
	const PRORATION_OPTIONS = [
		{ value: 'fob', label: 'Por FOB (proporcional al valor)' },
		{ value: 'qty', label: 'Por cantidad (proporcional a las unidades)' },
		{ value: 'none', label: 'Sin prorrateo' },
	];
	const PRORATION_SHORT = { fob: 'prorrateo por FOB', qty: 'prorrateo por cantidad', none: 'sin prorrateo' };
	const PRICE_TARGETS = [
		{ value: 'regular_price', label: 'Precio normal' },
		{ value: 'sale_price', label: 'Precio de oferta' },
	];
	const RECEIVE_STATUS = {
		ok: { label: 'OK', color: 'success' },
		unlinked: { label: 'Sin vincular', color: 'warning' },
		skipped_stock: { label: 'Sin stock', color: 'warning' },
		error: { label: 'Error', color: 'danger' },
	};

	/* ------------------------------------------------------------------ */
	/* Local helpers                                                       */
	/* ------------------------------------------------------------------ */

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function num(v) {
		if (typeof v === 'number') return isFinite(v) ? v : 0;
		if (v === null || v === undefined || v === '') return 0;
		const n = MJ.fmt.parse(v);
		return isNaN(n) ? 0 : n;
	}

	function isYmd(s) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(String(s || ''))) return false;
		return !isNaN(new Date(String(s) + 'T00:00:00').getTime());
	}

	/** Escapes a value used inside a quoted attribute selector ([data-id="…"]). */
	function attrSel(v) {
		return String(v === undefined || v === null ? '' : v).replace(/["\\]/g, '\\$&');
	}

	/** Decimal separator of the configured locale (inputs must never carry thousands separators). */
	function decimalSep() {
		try {
			const s = (1.5).toLocaleString(MJ.fmt.locale()).replace(/[0-9]/g, '');
			return s || '.';
		} catch (e) {
			return ',';
		}
	}

	/**
	 * Plain, re-parseable number for an <input> (no thousands separators).
	 * @param {*} v
	 * @param {number} [maxDec=4]
	 * @returns {string}
	 */
	function inputNum(v, maxDec) {
		const n = num(v);
		const d = (maxDec === undefined || maxDec === null) ? 4 : Math.max(0, maxDec);
		let s = n.toFixed(d);
		if (s.indexOf('.') >= 0) s = s.replace(/0+$/, '').replace(/\.$/, '');
		return s.replace('.', decimalSep());
	}

	function errMessage(err, fallback) {
		if (!err) return fallback || 'Ocurrió un error inesperado.';
		if (typeof err === 'string') return err;
		if (err.message) return String(err.message);
		return fallback || 'Ocurrió un error inesperado.';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toast(errMessage(err, fallback), 'error');
	}

	function alertBox(message, type, html) {
		type = ['info', 'warning', 'danger', 'success'].indexOf(type) >= 0 ? type : 'info';
		const icon = type === 'success' ? 'check' : (type === 'danger' ? 'warning' : (type === 'warning' ? 'warning' : 'info'));
		return '<div class="mj-alert mj-alert--' + type + '" role="' + (type === 'danger' ? 'alert' : 'status') + '">' +
			MJ.icon(icon) + '<div class="mj-alert__body">' + (html ? message : h(message)) + '</div></div>';
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function currencies() {
		try {
			const list = MJ.lists.currencies();
			return Array.isArray(list) && list.length ? list : ['USD', 'ARS'];
		} catch (e) {
			return ['USD', 'ARS'];
		}
	}

	function priceLists() {
		try {
			const list = MJ.lists.priceLists();
			return Array.isArray(list) ? list : [];
		} catch (e) {
			return [];
		}
	}

	function priceListOf(key) {
		return priceLists().find(function (l) { return String(l.key) === String(key); }) || null;
	}

	function defaultPriceListKey() {
		const lists = priceLists();
		if (!lists.length) return '';
		const configured = String(setting('price_list_for_price', ''));
		if (configured && lists.some(function (l) { return String(l.key) === configured; })) return configured;
		return String(lists[0].key);
	}

	/**
	 * True when the purchase already has items or expenses. Their exchange rates are expressed
	 * against the current base currency, so the server refuses to change it from then on.
	 */
	function hasLines(p) {
		if (!p) return false;
		const items = Array.isArray(p.items) ? p.items.length : 0;
		const expenses = Array.isArray(p.expenses) ? p.expenses.length : 0;
		return (items + expenses) > 0;
	}

	function optionsHtml(list, value) {
		return list.map(function (o) {
			return '<option value="' + h(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
		}).join('');
	}

	function clearFormErrors(form) {
		if (!form) return;
		qsa('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
		qsa('.mj-field__error', form).forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
		qsa('[data-form-alert]', form).forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
	}

	function showFormError(form, err) {
		if (!form) return;
		const params = err && err.data && err.data.params && typeof err.data.params === 'object' ? err.data.params : null;
		if (params && Object.keys(params).length) {
			MJ.ui.showErrors(form, params);
			return;
		}
		const box = document.createElement('div');
		box.className = 'mj-alert mj-alert--danger mj-mb';
		box.setAttribute('data-form-alert', '1');
		box.setAttribute('role', 'alert');
		box.innerHTML = MJ.icon('warning') + '<div class="mj-alert__body">' + h(errMessage(err)) + '</div>';
		form.insertBefore(box, form.firstChild);
	}

	function prorationShort(method) {
		return PRORATION_SHORT[String(method || 'fob')] || PRORATION_SHORT.fob;
	}

	function baseCur(p) {
		return String((p && p.base_currency) || setting('base_currency', 'USD')).toUpperCase();
	}

	function localCur(p) {
		return String((p && p.local_currency) || setting('local_currency', 'ARS')).toUpperCase();
	}

	function saleRate() {
		const r = num(setting('sale_rate', 1));
		return r > 0 ? r : 1;
	}

	function priceRounding() {
		return parseInt(setting('price_rounding', 0), 10) || 0;
	}

	/** Read-only MJ Dólar block injected into the settings by MJERP_Dolar. */
	function dolar() {
		const d = setting('dolar', null);
		return d && typeof d === 'object' ? d : { active: false, ready: false, rate: 0, publish_mode: 'local' };
	}

	/** True when the suggested price will be published in dollars through MJ Dólar. */
	function publishesUsd() {
		return String(dolar().publish_mode) === 'usd';
	}

	/** USD rounding context shared with the server (see MJERP_Costing::usd_config_from_settings). */
	function usdCfg() {
		try {
			return MJ.calc.usdConfigFromSettings(MJ.state.settings || {});
		} catch (e) {
			return null;
		}
	}

	/** "US$ 129" using the base currency symbol; no decimals when the amount is whole. */
	function moneyUsd(value, currency) {
		const n = MJ.fmt.toNumber(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, currency || setting('base_currency', 'USD'), decimals);
	}

	/**
	 * Where the suggested price ends up, spelled out in the reception dialog: publishing in dollars
	 * means MJ Dólar owns the peso price from then on, and the storefront (MJ Appearance and its
	 * child themes) shows the USD value next to it.
	 */
	function publishNote() {
		const d = dolar();
		if (publishesUsd()) {
			return 'El precio se guarda en dólares en MJ Dólar y la tienda lo muestra en pesos al TC ' +
				MJ.fmt.rate(num(d.rate)) + '. Cuando cambiés el TC, los precios se actualizan solos.';
		}
		if (d.active && !d.ready) {
			return 'MJ Dólar está activo pero no tiene TC cargado, así que el precio se escribe en pesos y queda fijo. ' +
				'Cargá el TC en MJ Dólar para publicar en dólares.';
		}
		if (!d.active) {
			return 'Sin MJ Dólar el precio se escribe en la moneda de la tienda y queda fijo hasta la próxima recepción.';
		}
		return 'El precio se escribe en la moneda de la tienda y queda fijo hasta la próxima recepción.';
	}

	/** One sentence explaining which exchange rate and rounding the suggested prices come from. */
	function priceSourceNote() {
		const d = dolar();
		if (publishesUsd()) {
			return 'Los precios sugeridos se publican en dólares y MJ Dólar los pasa a pesos con su TC ' +
				MJ.fmt.rate(num(d.rate)) + ' y su redondeo.';
		}
		if (d.active && !d.ready) {
			return 'MJ Dólar está activo pero sin TC cargado: los precios sugeridos usan el TC de venta ' +
				MJ.fmt.rate(saleRate()) + ' del ERP.';
		}
		return 'Los precios sugeridos usan el TC de venta ' + MJ.fmt.rate(saleRate()) + ' y el redondeo configurado.';
	}

	/**
	 * Suggested price of an item as a two-line block: the published price first (dollars when
	 * MJ Dólar is in play, pesos otherwise) and its counterpart underneath.
	 */
	function priceCellHtml(price, base) {
		if (!price) return '—';
		if (publishesUsd()) {
			return '<span class="mj-strong">' + h(moneyUsd(price.usd, base)) + '</span>' +
				'<div class="mj-small mj-muted">' + h(MJ.fmt.moneyLocal(price.usd_local)) + '</div>';
		}
		return '<span class="mj-strong">' + h(MJ.fmt.moneyLocal(price.local)) + '</span>' +
			'<div class="mj-small mj-muted">' + h(moneyUsd(price.base, base)) + '</div>';
	}

	function can(p, key) {
		return !!(p && p.can && p.can[key]);
	}

	function canEdit(p) {
		return can(p, 'edit');
	}

	function itemLinkedId(item) {
		const v = parseInt(item && item.variation_id, 10) || 0;
		const pid = parseInt(item && item.product_id, 10) || 0;
		return v > 0 ? v : pid;
	}

	function itemSubtitle(item) {
		const parts = [];
		if (item.sku) parts.push('SKU ' + item.sku);
		const prod = item.product && typeof item.product === 'object' ? item.product : null;
		if (prod && prod.name && String(prod.name) !== String(item.name)) parts.push(String(prod.name));
		if (prod && prod.manage_stock) parts.push('Stock ' + MJ.fmt.qty(prod.stock_quantity));
		return parts.join(' · ');
	}

	/**
	 * Totals + per item calc, taken from the server payload and completed locally when missing.
	 * @param {Object} p full purchase
	 * @returns {{ totals: Object, items: Object }} items keyed by item id (as string)
	 */
	function computeCalc(p) {
		const items = Array.isArray(p && p.items) ? p.items : [];
		const expenses = Array.isArray(p && p.expenses) ? p.expenses : [];
		let totals = null;
		if (p && p.calc && typeof p.calc === 'object') {
			totals = p.calc.totals && typeof p.calc.totals === 'object' ? p.calc.totals : p.calc;
		}
		let missing = !totals;
		items.forEach(function (it) { if (!it || !it.calc || typeof it.calc !== 'object') missing = true; });

		let local = null;
		if (missing && MJ.calc && typeof MJ.calc.calculate === 'function') {
			try {
				local = MJ.calc.calculate(p || {}, items, expenses, MJ.state.settings || {});
			} catch (e) {
				console.error('[MJ] calc.calculate', e);
				local = null;
			}
		}

		const byId = {};
		items.forEach(function (it, i) {
			const fromServer = it && it.calc && typeof it.calc === 'object' ? it.calc : null;
			const fromLocal = local && local.items && local.items[i] ? local.items[i] : null;
			byId[String(it.id)] = fromServer || fromLocal || {};
		});
		if (!totals) totals = (local && local.totals) || {};
		return { totals: totals, items: byId };
	}

	/** Suggested price for a landed unit cost using a price list key (no request). */
	function priceForList(landedUnitBase, listKey) {
		const list = priceListOf(listKey);
		if (!list) return null;
		try {
			return MJ.calc.priceFor(landedUnitBase, num(list.markup), saleRate(), priceRounding(), usdCfg());
		} catch (e) {
			return null;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Module state                                                        */
	/* ------------------------------------------------------------------ */

	const state = {
		id: 0,
		purchase: null,
		container: null,
		layoutReady: false,
		disposed: true,
		seq: 0,
		loading: false,
		priceList: '',
		formsSig: '',
		notesDirty: false,
		busy: {},
		edit: null,
		offs: [],
		comp: { itemSearch: null, expenseCashbox: null },
	};

	function region(name) {
		if (!state.container) return null;
		return qs('[data-region="' + name + '"]', state.container);
	}

	function apiPath(sub) {
		return 'purchases/' + encodeURIComponent(state.id) + (sub || '');
	}

	/* ------------------------------------------------------------------ */
	/* HTML — header                                                       */
	/* ------------------------------------------------------------------ */

	function headerHtml(p) {
		const parts = [];
		if (p.purchase_date) parts.push(MJ.fmt.date(p.purchase_date));
		parts.push(p.supplier_name ? String(p.supplier_name) : 'Sin proveedor');
		parts.push('destino ' + (p.destination ? String(p.destination) : 'sin definir'));
		parts.push(prorationShort(p.proration_method));

		const menu = [];
		if (p.status === 'en_transito') menu.push({ action: 'status-borrador', icon: 'refresh', label: 'Volver a borrador' });
		if (can(p, 'cancel')) menu.push({ action: 'status-cancelar', icon: 'x', label: 'Cancelar compra' });
		menu.push({ action: 'duplicate', icon: 'copy', label: 'Duplicar compra' });
		if (can(p, 'delete')) menu.push({ action: 'delete', icon: 'trash', label: 'Eliminar compra', danger: true });

		let primary = '';
		if (p.status === 'borrador') {
			primary = '<button type="button" class="mj-btn" data-action="status-transito">' + MJ.icon('truck') + '<span>Marcar en tránsito</span></button>';
		} else if (p.status === 'cancelada') {
			primary = '<button type="button" class="mj-btn" data-action="status-borrador">' + MJ.icon('refresh') + '<span>Volver a borrador</span></button>';
		}

		return '<div class="mj-page-header">' +
			'<div>' +
				'<a class="mj-link mj-small" href="' + h(MJ.router.link('/compras')) + '" data-action="back">' + MJ.icon('chevron-left') + '<span>Compras</span></a>' +
				'<h2 class="mj-page-header__title"><span class="mj-mono">' + h(p.code || ('#' + p.id)) + '</span> ' + MJ.ui.badge(p.status, p.status_label) + '</h2>' +
				'<div class="mj-page-header__subtitle mj-muted">' + h(parts.join(' · ')) + '</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
				(canEdit(p) ? '<button type="button" class="mj-btn mj-btn--ghost" data-action="edit">' + MJ.icon('edit') + '<span>Editar datos</span></button>' : '') +
				primary +
				'<div class="mj-dropdown">' +
					'<button type="button" class="mj-btn mj-btn--ghost" data-dropdown-toggle aria-expanded="false" aria-haspopup="true">' + MJ.icon('menu') + '<span>Más acciones</span>' + MJ.icon('chevron-down') + '</button>' +
					'<div class="mj-dropdown__menu" role="menu">' +
						menu.map(function (m) {
							return '<button type="button" class="mj-dropdown__item' + (m.danger ? ' mj-danger-text' : '') + '" role="menuitem" data-action="' + h(m.action) + '">' + MJ.icon(m.icon) + '<span>' + h(m.label) + '</span></button>';
						}).join('') +
					'</div>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	function lockHtml(p) {
		if (canEdit(p)) return '';
		let msg;
		if (p.status === 'recibida') {
			msg = 'Esta compra ya fue recibida: los productos y los gastos quedaron congelados. Si necesitás corregirla, deshacé la recepción.';
		} else if (p.status === 'cancelada') {
			msg = 'Esta compra está cancelada. Volvé a borrador para poder editar productos y gastos.';
		} else {
			msg = 'Esta compra no se puede editar en su estado actual. Podés seguir guardando notas.';
		}
		return alertBox(msg, p.status === 'recibida' ? 'success' : 'warning');
	}

	/* ------------------------------------------------------------------ */
	/* HTML — items card                                                   */
	/* ------------------------------------------------------------------ */

	function itemsHeadHtml(p, calc) {
		const base = baseCur(p);
		const count = (p.items || []).length;
		return '<div class="mj-row">' +
				'<h3 class="mj-card__title">' + MJ.icon('box') + '<span>Productos</span></h3>' +
				MJ.ui.pill(MJ.fmt.money(num(calc.totals.fob_total), base) + ' FOB') +
				'<span class="mj-small mj-muted">' + h(count === 1 ? '1 ítem' : MJ.fmt.int(count) + ' ítems') + '</span>' +
			'</div>' +
			(canEdit(p) ? '<div class="mj-row mj-row--end">' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="bulk-add" title="Elegí varios productos de una, copiales la misma cantidad, costo, moneda y TC, y agregalos todos juntos">' + MJ.icon('layers') + '<span>Agregar varios</span></button>' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="import">' + MJ.icon('upload') + '<span>Importar planilla</span></button>' +
			'</div>' : '');
	}

	function editableCell(p, kind, id, field, value, display, cls) {
		const classes = ['is-num'];
		if (cls) classes.push(cls);
		if (canEdit(p)) classes.push('mj-table__editable');
		let attrs = '';
		if (canEdit(p)) {
			attrs = ' data-edit="' + h(kind) + '" data-id="' + h(id) + '" data-field="' + h(field) + '"' +
				' data-value="' + h(inputNum(value, field === 'quantity' ? 4 : 6)) + '" tabindex="0" role="button"' +
				' title="Clic para editar"';
		}
		return '<td class="' + classes.join(' ') + '"' + attrs + '>' + display + '</td>';
	}

	function itemRowHtml(p, item, calc) {
		const base = baseCur(p);
		const c = calc.items[String(item.id)] || {};
		const linked = itemLinkedId(item) > 0;
		const sub = itemSubtitle(item);
		const editable = canEdit(p);

		let nameCell = '<div class="mj-strong">' + h(item.name || 'Sin nombre') + '</div>';
		if (sub) nameCell += '<div class="mj-table__sub mj-small mj-muted">' + h(sub) + '</div>';
		if (!linked) {
			nameCell += '<div class="mj-row mj-row--wrap mj-mt">' +
				'<span class="mj-tag mj-warning-text">' + MJ.icon('unlink') + '<span>Sin vincular</span></span>' +
				(editable ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="item-link" data-id="' + h(item.id) + '">' + MJ.icon('link') + '<span>Vincular</span></button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="item-create" data-id="' + h(item.id) + '">' + MJ.icon('plus') + '<span>Crear producto</span></button>' : '') +
			'</div>';
		} else if (item.product && item.product.edit_link) {
			nameCell += '<div class="mj-small"><a class="mj-link" href="' + h(item.product.edit_link) + '" target="_blank" rel="noopener">' + MJ.icon('external') + '<span>Ver en WooCommerce</span></a></div>';
		}

		return '<tr data-item="' + h(item.id) + '">' +
			'<td>' + nameCell + '</td>' +
			editableCell(p, 'item', item.id, 'quantity', item.quantity, h(MJ.fmt.qty(item.quantity))) +
			editableCell(p, 'item', item.id, 'unit_cost', item.unit_cost, h(MJ.fmt.money(item.unit_cost, item.currency))) +
			editableCell(p, 'item', item.id, 'rate', item.rate, h(MJ.fmt.rate(item.rate))) +
			'<td class="is-num mj-money mj-strong">' + h(MJ.fmt.money(num(c.fob_base), base)) + '</td>' +
			'<td class="is-actions">' +
				(editable ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="item-delete" data-id="' + h(item.id) + '" title="Quitar" aria-label="Quitar ' + h(item.name || '') + '">' + MJ.icon('trash') + '</button>' : '') +
			'</td>' +
		'</tr>';
	}

	function itemsBodyHtml(p, calc) {
		const items = Array.isArray(p.items) ? p.items : [];
		const base = baseCur(p);
		if (!items.length) {
			return MJ.ui.empty({
				icon: 'box',
				title: 'Sin productos',
				text: canEdit(p) ? 'Agregá productos con el buscador de abajo, elegí varios de una con «Agregar varios» o importá una planilla.' : 'Esta compra no tiene productos cargados.',
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
			'<th>Producto</th>' +
			'<th class="is-num">Cant.</th>' +
			'<th class="is-num">Costo</th>' +
			'<th class="is-num">TC</th>' +
			'<th class="is-num">FOB ' + h(base) + '</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>';
		items.forEach(function (item) { html += itemRowHtml(p, item, calc); });
		html += '</tbody><tfoot><tr>' +
			'<td colspan="4" class="mj-strong">Total FOB</td>' +
			'<td class="is-num mj-money mj-strong">' + h(MJ.fmt.money(num(calc.totals.fob_total), base)) + '</td>' +
			'<td></td>' +
		'</tr></tfoot></table></div>';
		return html;
	}

	function addItemFormHtml(p) {
		if (!canEdit(p)) return '';
		const fid = 'mj-ai-' + Math.random().toString(36).slice(2, 8);
		const base = baseCur(p);
		const curList = currencies().map(function (c) { return { value: c, label: c }; });
		return '<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-form="add-item" id="' + fid + '" novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:3 1 240px">' +
					'<label class="mj-field__label" for="' + fid + '-p">Producto</label>' +
					'<input class="mj-input" id="' + fid + '-p" name="name" type="text" maxlength="255" placeholder="Buscá por nombre o SKU…" data-item-search>' +
					'<input type="hidden" name="product_id" value="0">' +
					'<input type="hidden" name="variation_id" value="0">' +
					'<input type="hidden" name="sku" value="">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 90px">' +
					'<label class="mj-field__label" for="' + fid + '-q">Cant.</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-q" name="quantity" type="text" inputmode="decimal" data-type="number" value="1">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 130px">' +
					'<label class="mj-field__label" for="' + fid + '-c">Costo unit.</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-c" name="unit_cost" type="text" inputmode="decimal" data-type="number" placeholder="0,00">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 110px">' +
					'<label class="mj-field__label" for="' + fid + '-m">Moneda</label>' +
					'<select class="mj-select mj-select--sm" id="' + fid + '-m" name="currency">' + optionsHtml(curList, base) + '</select>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 130px">' +
					'<label class="mj-field__label" for="' + fid + '-r">TC de este ítem</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-r" name="rate" type="text" inputmode="decimal" data-type="number" value="' + h(inputNum(p.default_rate, 6)) + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto">' +
					'<label class="mj-field__label">&nbsp;</label>' +
					'<button type="submit" class="mj-btn mj-btn--primary" data-submit>' + MJ.icon('plus') + '<span>Agregar</span></button>' +
				'</div>' +
			'</form>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — expenses card                                                */
	/* ------------------------------------------------------------------ */

	function expensesHeadHtml(p, calc) {
		const base = baseCur(p);
		const count = (p.expenses || []).length;
		return '<div class="mj-row">' +
			'<h3 class="mj-card__title">' + MJ.icon('cash') + '<span>Gastos de la importación</span></h3>' +
			MJ.ui.pill(MJ.fmt.money(num(calc.totals.expenses_total), base)) +
			'<span class="mj-small mj-muted">' + h(count === 1 ? '1 gasto' : MJ.fmt.int(count) + ' gastos') + '</span>' +
		'</div>';
	}

	function expenseRowHtml(p, exp, calc) {
		const base = baseCur(p);
		const amountBase = exp.amount_base !== undefined && exp.amount_base !== null
			? num(exp.amount_base)
			: MJ.calc.toBase(exp.amount, exp.currency, exp.rate, base);
		const prorated = exp.is_prorated === undefined ? true : !!exp.is_prorated;
		const editable = canEdit(p);
		const label = exp.type_label || MJ.lists.expenseTypeLabel(exp.type) || exp.type || 'Gasto';

		let gasto = '<div class="mj-strong">' + h(label) + '</div>';
		if (exp.description) gasto += '<div class="mj-table__sub mj-small mj-muted">' + h(exp.description) + '</div>';
		if (exp.expense_date) gasto += '<div class="mj-table__sub mj-small mj-muted">' + h(MJ.fmt.date(exp.expense_date)) + '</div>';

		const badgeCls = 'mj-badge mj-badge--' + (prorated ? 'success' : 'neutral');
		const proratedCell = editable
			? '<button type="button" class="' + badgeCls + '" data-action="expense-prorated" data-id="' + h(exp.id) + '" title="Cambiar si este gasto se prorratea entre los productos">' + (prorated ? 'sí' : 'no') + '</button>'
			: '<span class="' + badgeCls + '">' + (prorated ? 'sí' : 'no') + '</span>';

		return '<tr data-expense="' + h(exp.id) + '">' +
			'<td>' + gasto + '</td>' +
			editableCell(p, 'expense', exp.id, 'amount', exp.amount, h(MJ.fmt.money(exp.amount, exp.currency))) +
			editableCell(p, 'expense', exp.id, 'rate', exp.rate, h(MJ.fmt.rate(exp.rate))) +
			'<td class="is-num mj-money">' + h(MJ.fmt.money(amountBase, base)) + '</td>' +
			'<td>' + proratedCell + '</td>' +
			'<td>' + (exp.cashbox_name ? h(exp.cashbox_name) : '<span class="mj-muted">—</span>') + '</td>' +
			'<td class="is-actions">' +
				(editable ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="expense-delete" data-id="' + h(exp.id) + '" title="Eliminar gasto" aria-label="Eliminar ' + h(label) + '">' + MJ.icon('trash') + '</button>' : '') +
			'</td>' +
		'</tr>';
	}

	function expensesBodyHtml(p, calc) {
		const expenses = Array.isArray(p.expenses) ? p.expenses : [];
		const base = baseCur(p);
		if (!expenses.length) {
			return MJ.ui.empty({
				icon: 'cash',
				title: 'Sin gastos',
				text: canEdit(p) ? 'Cargá el flete, el despachante, los impuestos y todo lo que encarezca la importación.' : 'Esta compra no tiene gastos cargados.',
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
			'<th>Gasto</th>' +
			'<th class="is-num">Monto</th>' +
			'<th class="is-num">TC</th>' +
			'<th class="is-num">' + h(base) + '</th>' +
			'<th>Prorratea</th>' +
			'<th>Sale de</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>';
		expenses.forEach(function (exp) { html += expenseRowHtml(p, exp, calc); });
		html += '</tbody><tfoot><tr>' +
			'<td colspan="3" class="mj-strong">Total gastos</td>' +
			'<td class="is-num mj-money mj-strong">' + h(MJ.fmt.money(num(calc.totals.expenses_total), base)) + '</td>' +
			'<td colspan="3"></td>' +
		'</tr></tfoot></table></div>';
		return html;
	}

	function addExpenseFormHtml(p) {
		if (!canEdit(p)) return '';
		const fid = 'mj-ae-' + Math.random().toString(36).slice(2, 8);
		const local = localCur(p);
		const curList = currencies().map(function (c) { return { value: c, label: c }; });
		return '<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-form="add-expense" id="' + fid + '" novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:0 1 150px">' +
					'<label class="mj-field__label" for="' + fid + '-t">Tipo</label>' +
					'<select class="mj-select mj-select--sm" id="' + fid + '-t" name="type"></select>' +
				'</div>' +
				'<div class="mj-field" style="flex:2 1 200px">' +
					'<label class="mj-field__label" for="' + fid + '-d">Descripción</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-d" name="description" type="text" maxlength="255" placeholder="Ej: Via Cargo guía 12345">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 130px">' +
					'<label class="mj-field__label" for="' + fid + '-a">Monto</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-a" name="amount" type="text" inputmode="decimal" data-type="number" placeholder="0,00">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 110px">' +
					'<label class="mj-field__label" for="' + fid + '-m">Moneda</label>' +
					'<select class="mj-select mj-select--sm" id="' + fid + '-m" name="currency">' + optionsHtml(curList, local) + '</select>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 120px">' +
					'<label class="mj-field__label" for="' + fid + '-r">TC propio</label>' +
					'<input class="mj-input mj-input--sm" id="' + fid + '-r" name="rate" type="text" inputmode="decimal" data-type="number" value="' + h(inputNum(p.default_rate, 6)) + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 160px">' +
					'<label class="mj-field__label" for="' + fid + '-c">Sale de</label>' +
					'<select class="mj-select mj-select--sm" id="' + fid + '-c" name="cashbox_id"></select>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto">' +
					'<label class="mj-field__label">&nbsp;</label>' +
					'<label class="mj-switch"><input type="checkbox" name="is_prorated" checked><span class="mj-switch__slider"></span><span class="mj-switch__label">Prorratear</span></label>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto">' +
					'<label class="mj-field__label">&nbsp;</label>' +
					'<button type="submit" class="mj-btn mj-btn--primary" data-submit>' + MJ.icon('plus') + '<span>Agregar</span></button>' +
				'</div>' +
			'</form>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — live costing card                                            */
	/* ------------------------------------------------------------------ */

	function costListHtml(p, calc, listKey) {
		const items = Array.isArray(p.items) ? p.items : [];
		const base = baseCur(p);
		const list = priceListOf(listKey);
		if (!items.length) {
			return '<div class="mj-note mj-small mj-muted">Agregá productos para ver el costo final por unidad.</div>';
		}
		return items.map(function (item) {
			const c = calc.items[String(item.id)] || {};
			const unitBase = num(c.unit_cost_base);
			const landed = num(c.landed_unit_base);
			const perUnitShare = landed - unitBase;
			const meta = MJ.fmt.money(unitBase, base) + ' + ' + MJ.fmt.money(perUnitShare, base) + ' (+' + MJ.fmt.pct(num(c.share_pct)) + ')';
			let priceHtml = '';
			if (list) {
				const price = priceForList(landed, listKey);
				if (price) {
					// The selected list is already named in the selector above, so only the numbers
					// go here: repeating the label squeezes the cost breakdown on the left.
					// The published price leads; publishing in USD is what MJ Dólar then converts.
					const pair = publishesUsd()
						? moneyUsd(price.usd, base) + ' · ' + MJ.fmt.moneyLocal(price.usd_local)
						: MJ.fmt.money(price.base, base) + ' · ' + MJ.fmt.moneyLocal(price.local);
					priceHtml = '<span class="mj-small mj-muted" title="' + h(list.label) + '">' + h(pair) + '</span>';
				}
			}
			return '<div class="mj-cost-list__item">' +
				'<div class="mj-cost-list__name">' + h(item.name || 'Sin nombre') +
					'<span class="mj-small mj-muted"> × ' + h(MJ.fmt.qty(item.quantity)) + '</span>' +
				'</div>' +
				'<div class="mj-cost-list__meta mj-small mj-muted">' + h(meta) + '</div>' +
				'<div class="mj-cost-list__value">' +
					'<span class="mj-money mj-strong">' + h(MJ.fmt.money(landed, base)) + '</span>' +
					priceHtml +
				'</div>' +
			'</div>';
		}).join('');
	}

	function costCardHtml(p, calc) {
		const base = baseCur(p);
		const t = calc.totals || {};
		const nonProrated = num(t.non_prorated_total);
		const adj = num(t.rounding_adjustment);
		const lists = priceLists();

		let rows = '<div class="mj-stat-list">' +
			'<div class="mj-stat-list__row"><span>Productos (FOB)</span><span class="mj-num">' + h(MJ.fmt.money(num(t.fob_total), base)) + '</span></div>' +
			'<div class="mj-stat-list__row"><span>Gastos prorrateables</span><span class="mj-num">' + h(MJ.fmt.money(num(t.prorated_total), base)) + '</span></div>';
		if (nonProrated > 0) {
			rows += '<div class="mj-stat-list__row"><span>Gastos no prorrateables</span><span class="mj-num">' + h(MJ.fmt.money(nonProrated, base)) + '</span></div>';
		}
		rows += '<div class="mj-stat-list__row"><span>Prorrateo sobre el FOB</span><span class="mj-num">' + h(MJ.fmt.pct(num(t.proration_pct))) + '</span></div>' +
			'<div class="mj-stat-list__row mj-stat-list__row--total"><span>Total</span><span class="mj-num mj-money mj-strong">' + h(MJ.fmt.money(num(t.total), base)) + '</span></div>' +
		'</div>';

		let note = '';
		if (adj !== 0) {
			note = '<div class="mj-note mj-small mj-muted">' + MJ.icon('info') +
				'<span>Ajuste de centavos aplicado al ítem de mayor FOB: ' + h(MJ.fmt.number(adj, 4) + ' ' + base) + '</span></div>';
		}

		const listField = lists.length
			? '<div class="mj-field mj-mt">' +
				'<label class="mj-field__label" for="mj-cost-list">Comparar contra la lista</label>' +
				'<select class="mj-select mj-select--sm" id="mj-cost-list" data-price-list></select>' +
			'</div>'
			: '<div class="mj-note mj-small mj-muted mj-mt">No hay listas de precio configuradas.</div>';

		return '<div class="mj-card__header">' +
				'<h3 class="mj-card__title">' + MJ.icon('percent') + '<span>Costeo en vivo</span></h3>' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="reload" title="Recalcular" aria-label="Recalcular">' + MJ.icon('refresh') + '</button>' +
			'</div>' +
			'<div class="mj-card__body">' +
				rows +
				note +
				listField +
				'<div class="mj-cost-list mj-mt" data-region="cost-list">' + costListHtml(p, calc, state.priceList) + '</div>' +
			'</div>' +
			'<div class="mj-card__footer">' +
				'<div class="mj-note mj-small mj-muted">El costo final por unidad (landed) es el FOB del ítem más la parte que le toca de los gastos (' +
					h(prorationShort(p.proration_method)) + '). ' + h(priceSourceNote()) + '</div>' +
			'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — reception card                                               */
	/* ------------------------------------------------------------------ */

	function receptionCardHtml(p) {
		const items = Array.isArray(p.items) ? p.items : [];
		const unlinked = items.filter(function (it) { return itemLinkedId(it) <= 0; }).length;
		let body = '';

		if (p.status === 'recibida') {
			const when = p.received_at ? MJ.fmt.datetime(p.received_at) : '';
			const who = p.received_by_name ? String(p.received_by_name) : '';
			let msg = 'Compra recibida';
			if (when) msg += ' el ' + when;
			if (who) msg += ' por ' + who;
			msg += '.';
			body += alertBox(msg, 'success');
		}

		if (can(p, 'receive')) {
			if (!items.length) {
				body += alertBox('Agregá al menos un producto para poder recibir la compra.', 'warning');
			} else {
				if (unlinked > 0) {
					body += alertBox(unlinked === 1
						? '1 ítem está sin vincular: no va a sumar stock ni actualizar costos.'
						: unlinked + ' ítems están sin vincular: no van a sumar stock ni actualizar costos.', 'warning');
				}
				body += '<button type="button" class="mj-btn mj-btn--primary mj-btn--block mj-btn--lg" data-action="receive">' + MJ.icon('check') + '<span>Marcar recibida</span></button>' +
					'<div class="mj-note mj-small mj-muted mj-mt">Vas a poder elegir las cantidades recibidas y si se suma stock, se actualizan los costos y los precios en WooCommerce.</div>';
			}
		} else if (can(p, 'unreceive')) {
			body += '<button type="button" class="mj-btn mj-btn--ghost mj-btn--block" data-action="unreceive">' + MJ.icon('refresh') + '<span>Deshacer recepción</span></button>' +
				'<div class="mj-note mj-small mj-muted mj-mt">Se descuenta el stock que se había sumado. Los costos y los precios NO se revierten.</div>';
		} else if (p.status === 'recibida') {
			body += '<div class="mj-note mj-small mj-muted">La opción de deshacer recepción está desactivada en la configuración.</div>';
		} else if (p.status === 'cancelada') {
			body += alertBox('La compra está cancelada. Volvé a borrador para poder recibirla.', 'warning');
		} else if (!items.length) {
			body += alertBox('Agregá al menos un producto para poder recibir la compra.', 'warning');
		}

		return '<div class="mj-card__header">' +
				'<h3 class="mj-card__title">' + MJ.icon('truck') + '<span>Recepción</span></h3>' +
			'</div>' +
			'<div class="mj-card__body">' + body + '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — notes card                                                   */
	/* ------------------------------------------------------------------ */

	function notesCardHtml(p) {
		return '<div class="mj-card__header">' +
				'<h3 class="mj-card__title">' + MJ.icon('file') + '<span>Notas</span></h3>' +
			'</div>' +
			'<div class="mj-card__body">' +
				'<textarea class="mj-textarea" data-notes rows="4" maxlength="5000" placeholder="Tracking, condiciones de pago, observaciones del proveedor…">' + h(p.notes || '') + '</textarea>' +
				'<div class="mj-row mj-row--end mj-mt">' +
					'<button type="button" class="mj-btn" data-action="save-notes">' + MJ.icon('save') + '<span>Guardar notas</span></button>' +
				'</div>' +
			'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Layout                                                              */
	/* ------------------------------------------------------------------ */

	function layoutHtml() {
		return '<div data-region="header"></div>' +
			'<div data-region="lock"></div>' +
			'<div class="mj-grid mj-grid--sidebar">' +
				'<div class="mj-stack">' +
					'<section class="mj-card mj-card--flush" data-card="items">' +
						'<div class="mj-card__header" data-region="items-head"></div>' +
						'<div data-region="items-body"></div>' +
						'<div class="mj-card__footer mj-hidden" data-region="items-form"></div>' +
					'</section>' +
					'<section class="mj-card mj-card--flush" data-card="expenses">' +
						'<div class="mj-card__header" data-region="exp-head"></div>' +
						'<div data-region="exp-body"></div>' +
						'<div class="mj-card__footer mj-hidden" data-region="exp-form"></div>' +
					'</section>' +
					'<section class="mj-card" data-region="notes"></section>' +
				'</div>' +
				'<div class="mj-stack">' +
					'<section class="mj-card" data-region="cost"></section>' +
					'<section class="mj-card" data-region="reception"></section>' +
				'</div>' +
			'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Painting                                                            */
	/* ------------------------------------------------------------------ */

	function setHtml(name, html) {
		const el = region(name);
		if (el) el.innerHTML = html;
	}

	function destroyForms() {
		if (state.comp.itemSearch) {
			try { state.comp.itemSearch.destroy(); } catch (e) { /* noop */ }
			state.comp.itemSearch = null;
		}
		if (state.comp.expenseCashbox) {
			try { state.comp.expenseCashbox.destroy(); } catch (e) { /* noop */ }
			state.comp.expenseCashbox = null;
		}
	}

	function initItemForm() {
		const form = qs('[data-form="add-item"]', state.container);
		if (!form) return;
		const input = qs('[data-item-search]', form);
		if (!input) return;
		try {
			state.comp.itemSearch = MJ.components.productSearch({
				input: input,
				onSelect(product) { applyProductToItemForm(form, product); },
				onCreate(term) {
					MJ.components.productCreateModal({
						initialName: term,
						onCreated(product) {
							applyProductToItemForm(form, product);
							// Register the new product as the search selection so the dropdown does not
							// reopen over the form when the input regains focus.
							if (state.comp.itemSearch && typeof state.comp.itemSearch.setSelected === 'function') {
								state.comp.itemSearch.setSelected(product);
							}
							input.value = product && product.name ? String(product.name) : term;
							MJ.ui.toast('Producto creado. Completá cantidad y costo para agregarlo.', 'success');
						},
					});
				},
			});
		} catch (e) {
			console.error('[MJ] productSearch', e);
		}
		input.addEventListener('input', function () {
			if (!input.value.trim()) resetProductFields(form);
		});
	}

	function resetProductFields(form) {
		const pid = qs('[name="product_id"]', form);
		const vid = qs('[name="variation_id"]', form);
		const sku = qs('[name="sku"]', form);
		if (pid) pid.value = '0';
		if (vid) vid.value = '0';
		if (sku) sku.value = '';
	}

	function applyProductToItemForm(form, product) {
		if (!form || !product) return;
		const pid = qs('[name="product_id"]', form);
		const vid = qs('[name="variation_id"]', form);
		const sku = qs('[name="sku"]', form);
		const cost = qs('[name="unit_cost"]', form);
		if (pid) pid.value = String(product.product_id || product.id || 0);
		if (vid) vid.value = String(product.variation_id || 0);
		if (sku) sku.value = product.sku ? String(product.sku) : '';
		if (cost && !cost.value.trim() && product.cost_base !== null && product.cost_base !== undefined && num(product.cost_base) > 0) {
			cost.value = inputNum(product.cost_base, 4);
		}
		const qty = qs('[name="quantity"]', form);
		if (qty && qty.focus) qty.focus();
	}

	function initExpenseForm() {
		const form = qs('[data-form="add-expense"]', state.container);
		if (!form) return;
		try {
			MJ.components.expenseTypeSelect(qs('[name="type"]', form), '', {});
		} catch (e) {
			console.error('[MJ] expenseTypeSelect', e);
		}
		try {
			state.comp.expenseCashbox = MJ.components.cashboxSelect({
				select: qs('[name="cashbox_id"]', form),
				value: '',
				allowCreate: true,
			});
		} catch (e) {
			console.error('[MJ] cashboxSelect', e);
		}
	}

	/** Rebuilds the two inline forms only when their shape changed (keeps what the user is typing). */
	function ensureForms(p) {
		const editable = canEdit(p);
		const sig = [editable ? '1' : '0', baseCur(p), localCur(p), String(p.default_rate), currencies().join(',')].join('|');
		if (state.formsSig === sig) return;
		state.formsSig = sig;
		destroyForms();

		const itemsForm = region('items-form');
		const expForm = region('exp-form');
		if (itemsForm) {
			itemsForm.innerHTML = addItemFormHtml(p);
			itemsForm.classList.toggle('mj-hidden', !editable);
		}
		if (expForm) {
			expForm.innerHTML = addExpenseFormHtml(p);
			expForm.classList.toggle('mj-hidden', !editable);
		}
		if (editable) {
			initItemForm();
			initExpenseForm();
		}
	}

	function paintCostList() {
		const el = region('cost-list');
		if (!el || !state.purchase) return;
		el.innerHTML = costListHtml(state.purchase, computeCalc(state.purchase), state.priceList);
	}

	function paintNotes(p) {
		const el = region('notes');
		if (!el) return;
		const ta = qs('[data-notes]', el);
		if (ta && (state.notesDirty || document.activeElement === ta)) return;
		el.innerHTML = notesCardHtml(p);
	}

	function paintAll() {
		const p = state.purchase;
		if (!p || !state.container) return;
		const calc = computeCalc(p);

		/* A cell being edited while another save lands must survive the repaint. */
		const pending = (state.edit && !state.edit.done && state.edit.input)
			? { kind: state.edit.kind, id: state.edit.id, field: state.edit.field, value: state.edit.input.value }
			: null;
		state.edit = null;

		setHtml('header', headerHtml(p));
		setHtml('lock', lockHtml(p));
		setHtml('items-head', itemsHeadHtml(p, calc));
		setHtml('items-body', itemsBodyHtml(p, calc));
		setHtml('exp-head', expensesHeadHtml(p, calc));
		setHtml('exp-body', expensesBodyHtml(p, calc));
		setHtml('cost', costCardHtml(p, calc));
		setHtml('reception', receptionCardHtml(p));
		paintNotes(p);
		ensureForms(p);

		const sel = qs('[data-price-list]', state.container);
		if (sel) {
			try {
				MJ.components.priceListSelect(sel, state.priceList, {});
				state.priceList = sel.value || state.priceList;
			} catch (e) {
				console.error('[MJ] priceListSelect', e);
			}
		}

		if (pending) restoreEdit(pending);
	}

	/** Re-opens an inline editor that a repaint destroyed, keeping what was typed. */
	function restoreEdit(pending) {
		if (!state.container || !state.purchase || !canEdit(state.purchase)) return;
		const sel = '[data-edit="' + attrSel(pending.kind) + '"]' +
			'[data-id="' + attrSel(pending.id) + '"]' +
			'[data-field="' + attrSel(pending.field) + '"]';
		const cell = qs(sel, state.container);
		if (!cell) return;
		beginEdit(cell);
		if (state.edit && state.edit.input) {
			state.edit.input.value = pending.value;
			if (state.edit.input.select) state.edit.input.select();
		}
	}

	function showBootLoading() {
		if (!state.container) return;
		state.container.innerHTML = '<div class="mj-section">' + loadingHtml('Cargando la compra…') + '</div>';
	}

	function showNotFound() {
		if (!state.container) return;
		state.layoutReady = false;
		state.container.innerHTML = '<div class="mj-section">' +
			MJ.ui.empty({
				icon: 'search',
				title: 'Compra no encontrada',
				text: 'La compra #' + state.id + ' no existe o fue eliminada.',
				action: '<a class="mj-btn mj-btn--primary" href="' + h(MJ.router.link('/compras')) + '">' + MJ.icon('cart') + '<span>Volver a compras</span></a>',
			}) +
		'</div>';
	}

	function showLoadError(err) {
		if (!state.container) return;
		state.layoutReady = false;
		state.container.innerHTML = '<div class="mj-section">' +
			alertBox(errMessage(err, 'No se pudo cargar la compra.'), 'danger') +
			'<div class="mj-row mj-mt">' +
				'<button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button>' +
				'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link('/compras')) + '">' + MJ.icon('chevron-left') + '<span>Volver a compras</span></a>' +
			'</div>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	function setPurchase(p) {
		if (!p || typeof p !== 'object' || !p.id) return;
		state.purchase = p;
		if (!state.priceList) state.priceList = defaultPriceListKey();
		if (!state.layoutReady) {
			state.container.innerHTML = layoutHtml();
			state.layoutReady = true;
			state.formsSig = '';
		}
		paintAll();
	}

	/** Mutations return the full purchase, sometimes wrapped as { purchase, report }. */
	function applyResponse(res) {
		if (res && typeof res === 'object' && res.purchase && typeof res.purchase === 'object') {
			setPurchase(res.purchase);
			return res.report || null;
		}
		setPurchase(res);
		return null;
	}

	function isLocked(err) {
		return !!(err && (err.code === 'mjerp_locked' || (err.status === 409 && String(err.code || '').indexOf('locked') >= 0)));
	}

	function handleErr(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		if (isLocked(err)) {
			MJ.ui.toast(errMessage(err, 'La compra cambió de estado y ya no se puede editar.'), 'error');
			load();
			return;
		}
		if (err.status === 404) {
			MJ.ui.toast(errMessage(err, 'El registro ya no existe.'), 'error');
			load();
			return;
		}
		toastError(err, fallback);
	}

	function load() {
		if (state.disposed || !state.id) return Promise.resolve();
		const mine = ++state.seq;
		state.loading = true;
		if (!state.layoutReady) showBootLoading();
		return MJ.api.get(apiPath()).then(function (p) {
			if (state.disposed || mine !== state.seq) return;
			state.loading = false;
			setPurchase(p);
		}).catch(function (err) {
			if (state.disposed || mine !== state.seq) return;
			state.loading = false;
			if (err && (err.handled || err.status === 401)) return;
			if (err && (err.status === 404 || err.code === 'mjerp_not_found')) { showNotFound(); return; }
			if (!state.layoutReady) showLoadError(err);
			else toastError(err, 'No se pudo actualizar la compra.');
		});
	}

	/**
	 * Runs a mutation guarding against double submits and repainting from the response.
	 * @returns {Promise<Object|boolean>} the report when the endpoint returns one, `true` on success, `false` on failure
	 */
	function mutate(key, run, btn, opts) {
		opts = opts || {};
		if (state.busy[key]) return Promise.resolve(false);
		state.busy[key] = true;
		if (btn) MJ.ui.setLoading(btn, true);
		const stopLoading = function () {
			if (btn && document.body.contains(btn)) MJ.ui.setLoading(btn, false);
		};
		return run().then(function (res) {
			delete state.busy[key];
			if (state.disposed) return false;
			stopLoading();
			const report = applyResponse(res);
			if (opts.success) MJ.ui.toast(opts.success, 'success');
			return report || true;
		}).catch(function (err) {
			delete state.busy[key];
			if (state.disposed) return false;
			stopLoading();
			handleErr(err, opts.fail);
			return false;
		});
	}

	/* ------------------------------------------------------------------ */
	/* Header actions                                                      */
	/* ------------------------------------------------------------------ */

	function changeStatus(status, btn) {
		const p = state.purchase;
		if (!p) return;
		const labels = { borrador: 'Borrador', en_transito: 'En tránsito', cancelada: 'Cancelada' };
		const run = function () {
			mutate('status', function () {
				return MJ.api.post(apiPath('/status'), { status: status });
			}, btn, {
				success: 'Estado actualizado a ' + (MJ.ui.statusLabel(status) || labels[status] || status) + '.',
				fail: 'No se pudo cambiar el estado.',
			});
		};
		if (status === 'cancelada') {
			MJ.ui.confirm({
				title: 'Cancelar compra',
				message: 'La compra ' + (p.code || '') + ' pasa a estado Cancelada y deja de poder editarse. Después podés volver a borrador.',
				okLabel: 'Cancelar compra',
				cancelLabel: 'Volver',
				danger: true,
			}).then(function (ok) { if (ok && !state.disposed) run(); });
			return;
		}
		run();
	}

	function duplicate(btn) {
		if (state.busy.duplicate) return;
		state.busy.duplicate = true;
		if (btn) MJ.ui.setLoading(btn, true);
		MJ.api.post(apiPath('/duplicate')).then(function (res) {
			delete state.busy.duplicate;
			if (state.disposed) return;
			if (btn && document.body.contains(btn)) MJ.ui.setLoading(btn, false);
			MJ.ui.toast('Compra duplicada como ' + (res && res.code ? res.code : 'nueva compra') + '.', 'success');
			if (res && res.id) MJ.router.navigate('/compras/' + res.id);
		}).catch(function (err) {
			delete state.busy.duplicate;
			if (state.disposed) return;
			if (btn && document.body.contains(btn)) MJ.ui.setLoading(btn, false);
			handleErr(err, 'No se pudo duplicar la compra.');
		});
	}

	function removePurchase(btn) {
		const p = state.purchase;
		if (!p) return;
		MJ.ui.confirm({
			title: 'Eliminar compra',
			message: 'Se elimina la compra ' + (p.code || '') + ' con todos sus productos y gastos. Esta acción no se puede deshacer.',
			okLabel: 'Eliminar',
			cancelLabel: 'Cancelar',
			danger: true,
		}).then(function (ok) {
			if (!ok || state.disposed || state.busy.remove) return;
			state.busy.remove = true;
			if (btn) MJ.ui.setLoading(btn, true);
			MJ.api.del(apiPath()).then(function () {
				delete state.busy.remove;
				if (state.disposed) return;
				MJ.ui.toast('Compra eliminada.', 'success');
				MJ.router.navigate('/compras');
			}).catch(function (err) {
				delete state.busy.remove;
				if (state.disposed) return;
				if (btn && document.body.contains(btn)) MJ.ui.setLoading(btn, false);
				handleErr(err, 'No se pudo eliminar la compra.');
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* "Editar datos" modal                                                */
	/* ------------------------------------------------------------------ */

	function destinationFieldHtml(fid, value) {
		const list = MJ.lists.destinations();
		value = value ? String(value) : '';
		const isCustom = !!value && list.indexOf(value) === -1;
		return '<div class="mj-field">' +
			'<label class="mj-field__label" for="' + fid + '-dest">Destino</label>' +
			'<select class="mj-select" id="' + fid + '-dest" name="destination_select">' +
				'<option value="">Sin destino</option>' +
				list.map(function (d) { return '<option value="' + h(d) + '"' + (d === value ? ' selected' : '') + '>' + h(d) + '</option>'; }).join('') +
				'<option value="' + CUSTOM_DESTINATION + '"' + (isCustom ? ' selected' : '') + '>Otro destino…</option>' +
			'</select>' +
			'<input class="mj-input mj-mt' + (isCustom ? '' : ' mj-hidden') + '" name="destination_custom" type="text" maxlength="100" placeholder="Escribí el destino" value="' + h(isCustom ? value : '') + '">' +
		'</div>';
	}

	function bindDestinationField(form) {
		const sel = qs('[name="destination_select"]', form);
		const custom = qs('[name="destination_custom"]', form);
		if (!sel || !custom) return;
		sel.addEventListener('change', function () {
			const isCustom = sel.value === CUSTOM_DESTINATION;
			custom.classList.toggle('mj-hidden', !isCustom);
			if (isCustom) custom.focus();
		});
	}

	function readDestination(data) {
		if (data.destination_select === CUSTOM_DESTINATION) return String(data.destination_custom || '').trim();
		return String(data.destination_select || '').trim();
	}

	function openEditModal() {
		const p = state.purchase;
		if (!p) return;
		const fid = 'mj-ep-' + Math.random().toString(36).slice(2, 8);
		const base = baseCur(p);
		const curList = currencies().map(function (c) { return { value: c, label: c }; });

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-code">Código *</label>' +
						'<input class="mj-input mj-mono" id="' + fid + '-code" name="code" type="text" maxlength="60" value="' + h(p.code || '') + '" autocomplete="off" required>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha *</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="purchase_date" type="date" value="' + h(p.purchase_date || '') + '" required>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-supplier">Proveedor</label>' +
					'<select class="mj-select" id="' + fid + '-supplier" name="supplier_id"></select>' +
				'</div>' +
				destinationFieldHtml(fid, p.destination) +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cur">Moneda base</label>' +
						'<select class="mj-select" id="' + fid + '-cur" name="base_currency"' + (hasLines(p) ? ' disabled' : '') + '>' + optionsHtml(curList, base) + '</select>' +
						'<div class="mj-field__hint">' + (hasLines(p)
							? 'No se puede cambiar con productos o gastos cargados: cada TC está expresado contra esta moneda.'
							: 'Moneda en la que se costea la compra.') + '</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-rate">TC por defecto *</label>' +
						'<input class="mj-input" id="' + fid + '-rate" name="default_rate" type="text" inputmode="decimal" data-type="number" value="' + h(inputNum(p.default_rate, 6)) + '">' +
						'<div class="mj-field__hint">' + h(localCur(p)) + ' por cada 1 ' + h(base) + '.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-method">Método de prorrateo</label>' +
						'<select class="mj-select" id="' + fid + '-method" name="proration_method">' + optionsHtml(PRORATION_OPTIONS, p.proration_method || 'fob') + '</select>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
					'<textarea class="mj-textarea" id="' + fid + '-notes" name="notes" rows="3" maxlength="5000">' + h(p.notes || '') + '</textarea>' +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('save') + '<span>Guardar cambios</span></button>';

		MJ.ui.modal({
			title: 'Editar datos de la compra',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				let supplierSel = null;
				let submitting = false;

				try {
					supplierSel = MJ.components.supplierSelect({
						select: qs('[name="supplier_id"]', form),
						value: p.supplier_id || '',
						allowCreate: true,
						supplier: p.supplier || null,
					});
				} catch (e) {
					console.error('[MJ] supplierSelect', e);
				}
				bindDestinationField(form);

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);
					const data = MJ.ui.formData(form);
					const errors = {};

					const code = String(data.code || '').trim();
					if (!code) errors.code = 'El código es obligatorio.';
					else if (code.length > 60) errors.code = 'El código no puede superar 60 caracteres.';

					const date = String(data.purchase_date || '').trim();
					if (!isYmd(date)) errors.purchase_date = 'Ingresá una fecha válida.';

					let rate = data.default_rate;
					if (typeof rate !== 'number') rate = MJ.fmt.parse(rate);
					if (rate === null || isNaN(rate) || rate <= 0) errors.default_rate = 'El TC debe ser mayor a 0.';

					const destination = readDestination(data);
					if (data.destination_select === CUSTOM_DESTINATION && !destination) errors.destination_custom = 'Escribí el destino.';

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const supplierId = parseInt(supplierSel ? supplierSel.getValue() : data.supplier_id, 10);
					const payload = {
						code: code,
						purchase_date: date,
						supplier_id: supplierId > 0 ? supplierId : 0,
						destination: destination,
						base_currency: String(data.base_currency || baseCur(p)).toUpperCase(),
						default_rate: rate,
						proration_method: ['fob', 'qty', 'none'].indexOf(data.proration_method) >= 0 ? data.proration_method : 'fob',
						notes: String(data.notes || ''),
					};

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);
					MJ.api.put(apiPath(), payload).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (state.disposed) { modal.close(); return; }
						state.notesDirty = false;
						applyResponse(res);
						MJ.ui.toast('Datos de la compra actualizados.', 'success');
						modal.close();
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						if (isLocked(err)) { modal.close(); handleErr(err); return; }
						showFormError(form, err);
					});
				});
			},
			onClose() {
				/* supplierSelect keeps a listener on the shared store; it is dropped with the modal DOM. */
			},
		});
	}

	/* ------------------------------------------------------------------ */
	/* Items — add, link, create, delete                                   */
	/* ------------------------------------------------------------------ */

	function submitAddItem(form) {
		const p = state.purchase;
		if (!p || !form) return;
		clearFormErrors(form);
		const data = MJ.ui.formData(form);
		const errors = {};

		const name = String(data.name || '').trim();
		const productId = parseInt(data.product_id, 10) || 0;
		const variationId = parseInt(data.variation_id, 10) || 0;
		if (!name && !productId) errors.name = 'Elegí un producto o escribí un nombre.';

		let quantity = data.quantity;
		if (typeof quantity !== 'number') quantity = MJ.fmt.parse(quantity);
		if (quantity === null || isNaN(quantity) || quantity <= 0) errors.quantity = 'La cantidad debe ser mayor a 0.';

		let unitCost = data.unit_cost;
		if (typeof unitCost !== 'number') unitCost = MJ.fmt.parse(unitCost);
		if (unitCost === null || isNaN(unitCost) || unitCost < 0) errors.unit_cost = 'El costo no puede ser negativo.';

		let rate = data.rate;
		if (typeof rate !== 'number') rate = MJ.fmt.parse(rate);
		if (rate === null || isNaN(rate) || rate <= 0) errors.rate = 'El TC debe ser mayor a 0.';

		if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

		const payload = {
			product_id: productId,
			variation_id: variationId,
			name: name,
			sku: String(data.sku || ''),
			quantity: quantity,
			unit_cost: unitCost,
			currency: String(data.currency || baseCur(p)).toUpperCase(),
			rate: rate,
		};
		const btn = qs('[data-submit]', form);

		mutate('add-item', function () {
			return MJ.api.post(apiPath('/items'), payload);
		}, btn, { success: 'Producto agregado.', fail: 'No se pudo agregar el producto.' }).then(function (ok) {
			if (state.disposed || !ok) return;
			resetAddItemForm();
		});
	}

	function resetAddItemForm() {
		const p = state.purchase;
		const form = qs('[data-form="add-item"]', state.container);
		if (!form || !p) return;
		clearFormErrors(form);
		const search = qs('[data-item-search]', form);
		if (state.comp.itemSearch && typeof state.comp.itemSearch.clear === 'function') {
			try { state.comp.itemSearch.clear(); } catch (e) { /* noop */ }
		} else if (search) {
			search.value = '';
		}
		resetProductFields(form);
		const qty = qs('[name="quantity"]', form);
		const cost = qs('[name="unit_cost"]', form);
		const cur = qs('[name="currency"]', form);
		const rate = qs('[name="rate"]', form);
		if (qty) qty.value = '1';
		if (cost) cost.value = '';
		if (cur) cur.value = baseCur(p);
		if (rate) rate.value = inputNum(p.default_rate, 6);
		if (search && search.focus) search.focus();
	}

	function findItem(id) {
		const items = Array.isArray(state.purchase && state.purchase.items) ? state.purchase.items : [];
		return items.find(function (it) { return String(it.id) === String(id); }) || null;
	}

	function findExpense(id) {
		const list = Array.isArray(state.purchase && state.purchase.expenses) ? state.purchase.expenses : [];
		return list.find(function (e) { return String(e.id) === String(id); }) || null;
	}

	function deleteItem(id, btn) {
		const item = findItem(id);
		if (!item) return;
		MJ.ui.confirm({
			title: 'Quitar producto',
			message: 'Se quita «' + (item.name || 'este ítem') + '» de la compra.',
			okLabel: 'Quitar',
			cancelLabel: 'Cancelar',
			danger: true,
		}).then(function (ok) {
			if (!ok || state.disposed) return;
			mutate('item-del-' + id, function () {
				return MJ.api.del(apiPath('/items/' + encodeURIComponent(id)));
			}, btn, { success: 'Producto quitado.', fail: 'No se pudo quitar el producto.' });
		});
	}

	function linkItem(id, product, btn) {
		if (!product) return Promise.resolve(false);
		const payload = {
			product_id: parseInt(product.product_id || product.id, 10) || 0,
			variation_id: parseInt(product.variation_id, 10) || 0,
		};
		return mutate('item-link-' + id, function () {
			return MJ.api.post(apiPath('/items/' + encodeURIComponent(id) + '/link'), payload);
		}, btn, { success: 'Ítem vinculado a «' + (product.name || 'producto') + '».', fail: 'No se pudo vincular el ítem.' });
	}

	function openLinkModal(id) {
		const item = findItem(id);
		if (!item) return;
		const fid = 'mj-lk-' + Math.random().toString(36).slice(2, 8);
		let chosen = null;
		let search = null;

		MJ.ui.modal({
			title: 'Vincular con un producto',
			size: 'md',
			body:
				'<form class="mj-form" id="' + fid + '" novalidate>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label">Ítem de la compra</label>' +
						'<div class="mj-strong">' + h(item.name || 'Sin nombre') + '</div>' +
						(item.sku ? '<div class="mj-small mj-muted">SKU ' + h(item.sku) + '</div>' : '') +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-s">Producto de WooCommerce</label>' +
						'<input class="mj-input" id="' + fid + '-s" type="text" placeholder="Buscá por nombre o SKU…" autocomplete="off">' +
						'<div class="mj-field__hint">Al vincular, el stock y el costo de este producto se actualizan al recibir la compra.</div>' +
					'</div>' +
					'<div data-chosen></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-link disabled>' + MJ.icon('link') + '<span>Vincular</span></button>',
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const linkBtn = qs('[data-link]', root);
				const chosenBox = qs('[data-chosen]', form);

				try {
					search = MJ.components.productSearch({
						input: qs('#' + fid + '-s', form),
						onSelect(product) {
							chosen = product;
							linkBtn.disabled = false;
							chosenBox.innerHTML = alertBox('Se vinculará con: ' + (product.name || '') + (product.sku ? ' (SKU ' + product.sku + ')' : ''), 'info');
						},
						onCreate(term) {
							modal.close();
							openCreateProductForItem(id, term);
						},
					});
				} catch (e) {
					console.error('[MJ] productSearch', e);
				}

				linkBtn.addEventListener('click', function () {
					if (!chosen) return;
					MJ.ui.setLoading(linkBtn, true);
					linkItem(id, chosen, null).then(function (ok) {
						MJ.ui.setLoading(linkBtn, false);
						if (ok) modal.close();
					});
				});
			},
			onClose() {
				if (search && typeof search.destroy === 'function') {
					try { search.destroy(); } catch (e) { /* noop */ }
				}
			},
		});
	}

	function openCreateProductForItem(id, nameOverride) {
		const p = state.purchase;
		const item = findItem(id);
		if (!p || !item) return;
		const calc = computeCalc(p);
		const c = calc.items[String(item.id)] || {};
		let costBase = num(c.landed_unit_base);
		if (costBase <= 0) {
			try { costBase = MJ.calc.toBase(item.unit_cost, item.currency, item.rate, baseCur(p)); } catch (e) { costBase = num(item.unit_cost); }
		}
		const price = priceForList(costBase, state.priceList);

		MJ.components.productCreateModal({
			initialName: nameOverride || item.name || '',
			initialSku: item.sku || '',
			initialCost: costBase > 0 ? costBase : '',
			initialPrice: price ? price.local : '',
			initialUsd: price ? price.usd : '',
			onCreated(product) {
				if (state.disposed || !product) return;
				linkItem(id, product, null);
			},
		});
	}

	/* ------------------------------------------------------------------ */
	/* Expenses — add, toggle, delete                                      */
	/* ------------------------------------------------------------------ */

	function submitAddExpense(form) {
		const p = state.purchase;
		if (!p || !form) return;
		clearFormErrors(form);
		const data = MJ.ui.formData(form);
		const errors = {};

		const type = String(data.type || '').trim();
		if (!type) errors.type = 'Elegí un tipo de gasto.';

		let amount = data.amount;
		if (typeof amount !== 'number') amount = MJ.fmt.parse(amount);
		if (amount === null || isNaN(amount) || amount < 0) errors.amount = 'Ingresá un monto válido.';
		else if (amount === 0) errors.amount = 'El monto debe ser mayor a 0.';

		let rate = data.rate;
		if (typeof rate !== 'number') rate = MJ.fmt.parse(rate);
		if (rate === null || isNaN(rate) || rate <= 0) errors.rate = 'El TC debe ser mayor a 0.';

		if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

		const cashboxId = parseInt(state.comp.expenseCashbox ? state.comp.expenseCashbox.getValue() : data.cashbox_id, 10);
		const payload = {
			type: type,
			description: String(data.description || '').trim(),
			amount: amount,
			currency: String(data.currency || localCur(p)).toUpperCase(),
			rate: rate,
			is_prorated: data.is_prorated !== false,
			cashbox_id: cashboxId > 0 ? cashboxId : 0,
		};
		const btn = qs('[data-submit]', form);

		mutate('add-expense', function () {
			return MJ.api.post(apiPath('/expenses'), payload);
		}, btn, { success: 'Gasto agregado.', fail: 'No se pudo agregar el gasto.' }).then(function (ok) {
			if (state.disposed || !ok) return;
			resetAddExpenseForm();
		});
	}

	function resetAddExpenseForm() {
		const p = state.purchase;
		const form = qs('[data-form="add-expense"]', state.container);
		if (!form || !p) return;
		clearFormErrors(form);
		const desc = qs('[name="description"]', form);
		const amount = qs('[name="amount"]', form);
		const rate = qs('[name="rate"]', form);
		const prorated = qs('[name="is_prorated"]', form);
		const cur = qs('[name="currency"]', form);
		if (desc) desc.value = '';
		if (amount) amount.value = '';
		if (rate) rate.value = inputNum(p.default_rate, 6);
		if (prorated) prorated.checked = true;
		if (cur) cur.value = localCur(p);
		if (desc && desc.focus) desc.focus();
	}

	function toggleProrated(id, btn) {
		const exp = findExpense(id);
		if (!exp) return;
		const next = !(exp.is_prorated === undefined ? true : !!exp.is_prorated);
		mutate('exp-pro-' + id, function () {
			return MJ.api.put(apiPath('/expenses/' + encodeURIComponent(id)), { is_prorated: next });
		}, btn, { fail: 'No se pudo cambiar el prorrateo.' });
	}

	function deleteExpense(id, btn) {
		const exp = findExpense(id);
		if (!exp) return;
		const label = exp.type_label || MJ.lists.expenseTypeLabel(exp.type) || 'este gasto';
		MJ.ui.confirm({
			title: 'Eliminar gasto',
			message: 'Se elimina «' + label + (exp.description ? ' — ' + exp.description : '') + '» de la compra.',
			okLabel: 'Eliminar',
			cancelLabel: 'Cancelar',
			danger: true,
		}).then(function (ok) {
			if (!ok || state.disposed) return;
			mutate('exp-del-' + id, function () {
				return MJ.api.del(apiPath('/expenses/' + encodeURIComponent(id)));
			}, btn, { success: 'Gasto eliminado.', fail: 'No se pudo eliminar el gasto.' });
		});
	}

	/* ------------------------------------------------------------------ */
	/* Inline cell editing                                                 */
	/* ------------------------------------------------------------------ */

	const FIELD_LABELS = { quantity: 'Cantidad', unit_cost: 'Costo unitario', amount: 'Monto', rate: 'Tipo de cambio' };

	function beginEdit(cell) {
		if (!state.purchase || !canEdit(state.purchase)) return;
		if (state.edit && state.edit.cell === cell) return;
		commitEdit();
		const kind = cell.getAttribute('data-edit');
		const id = cell.getAttribute('data-id');
		const field = cell.getAttribute('data-field');
		if (!kind || !id || !field) return;
		const value = cell.getAttribute('data-value') || '';
		const html = cell.innerHTML;
		cell.classList.add('is-editing');
		cell.innerHTML = '<input class="mj-cell-input" type="text" inputmode="decimal" value="' + h(value) + '" ' +
			'aria-label="' + h(FIELD_LABELS[field] || field) + '" autocomplete="off">';
		const input = qs('.mj-cell-input', cell);
		state.edit = { cell: cell, kind: kind, id: id, field: field, html: html, input: input, done: false };
		if (input) {
			input.focus();
			if (input.select) input.select();
		}
	}

	function restoreCell(edit) {
		if (!edit || !edit.cell) return;
		edit.cell.classList.remove('is-editing');
		edit.cell.innerHTML = edit.html;
	}

	function cancelEdit() {
		const edit = state.edit;
		if (!edit || edit.done) return;
		edit.done = true;
		state.edit = null;
		restoreCell(edit);
	}

	function commitEdit() {
		const edit = state.edit;
		if (!edit || edit.done || !edit.input) return;
		edit.done = true;
		state.edit = null;

		const raw = edit.input.value;
		const parsed = MJ.fmt.parse(raw);
		const previous = MJ.fmt.parse(edit.cell.getAttribute('data-value'));

		if (raw === null || String(raw).trim() === '' || isNaN(parsed)) {
			restoreCell(edit);
			MJ.ui.toast('Ingresá un número válido.', 'error');
			return;
		}
		if ((edit.field === 'quantity' || edit.field === 'rate') && parsed <= 0) {
			restoreCell(edit);
			MJ.ui.toast((edit.field === 'quantity' ? 'La cantidad' : 'El TC') + ' debe ser mayor a 0.', 'error');
			return;
		}
		if ((edit.field === 'unit_cost' || edit.field === 'amount') && parsed < 0) {
			restoreCell(edit);
			MJ.ui.toast('El importe no puede ser negativo.', 'error');
			return;
		}
		if (!isNaN(previous) && Math.abs(parsed - previous) < 0.0000005) {
			restoreCell(edit);
			return;
		}

		const payload = {};
		payload[edit.field] = parsed;
		const path = edit.kind === 'item'
			? apiPath('/items/' + encodeURIComponent(edit.id))
			: apiPath('/expenses/' + encodeURIComponent(edit.id));

		edit.cell.classList.add('is-loading');
		edit.input.disabled = true;

		MJ.api.put(path, payload).then(function (res) {
			if (state.disposed) return;
			applyResponse(res);
		}).catch(function (err) {
			if (state.disposed) return;
			edit.cell.classList.remove('is-loading');
			restoreCell(edit);
			handleErr(err, 'No se pudo guardar el cambio.');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Spreadsheet import                                                  */
	/* ------------------------------------------------------------------ */

	function openImportModal() {
		const p = state.purchase;
		if (!p) return;
		MJ.components.csvImportModal({
			title: 'Importar planilla de productos',
			defaultCurrency: baseCur(p),
			defaultRate: num(p.default_rate),
			onImport(rows, options) {
				return MJ.api.post(apiPath('/items/bulk'), {
					rows: rows,
					match_by: options.match_by,
					create_missing: !!options.create_missing,
					default_currency: options.default_currency,
					default_rate: options.default_rate,
				}).then(function (res) {
					if (state.disposed) return;
					const report = applyResponse(res);
					const added = report ? parseInt(report.added, 10) || 0 : rows.length;
					MJ.ui.toast(added === 1 ? '1 ítem importado.' : MJ.fmt.int(added) + ' ítems importados.', 'success');
					window.setTimeout(function () {
						if (!state.disposed) openImportReportModal(report, rows.length);
					}, 0);
				});
			},
		});
	}

	/**
	 * Result of a /items/bulk call. Shared by the spreadsheet import and the multi product picker.
	 * @param {Object} report  report devuelto por el endpoint
	 * @param {number} totalRows  filas enviadas
	 * @param {string} [title]  título del modal (por defecto, el de la importación)
	 */
	function openImportReportModal(report, totalRows, title) {
		report = report && typeof report === 'object' ? report : {};
		const errors = Array.isArray(report.errors) ? report.errors : [];
		const added = parseInt(report.added, 10) || 0;
		const linked = parseInt(report.linked, 10) || 0;
		const linkedById = parseInt(report.linked_by_id, 10) || 0;
		const created = parseInt(report.created, 10) || 0;
		const unlinked = parseInt(report.unlinked, 10) || 0;

		const stats = '<div class="mj-stat-list">' +
			'<div class="mj-stat-list__row"><span>Filas procesadas</span><span class="mj-num">' + h(MJ.fmt.int(totalRows)) + '</span></div>' +
			'<div class="mj-stat-list__row"><span>Ítems agregados</span><span class="mj-num mj-strong">' + h(MJ.fmt.int(added)) + '</span></div>' +
			'<div class="mj-stat-list__row"><span>Vinculados a productos</span><span class="mj-num">' + h(MJ.fmt.int(linked)) + '</span></div>' +
			(linkedById > 0 ? '<div class="mj-stat-list__row"><span>Vinculados por selección</span><span class="mj-num">' + h(MJ.fmt.int(linkedById)) + '</span></div>' : '') +
			'<div class="mj-stat-list__row"><span>Productos creados</span><span class="mj-num">' + h(MJ.fmt.int(created)) + '</span></div>' +
			'<div class="mj-stat-list__row"><span>Sin vincular</span><span class="mj-num' + (unlinked > 0 ? ' mj-warning-text' : '') + '">' + h(MJ.fmt.int(unlinked)) + '</span></div>' +
			'<div class="mj-stat-list__row mj-stat-list__row--total"><span>Errores</span><span class="mj-num' + (errors.length ? ' mj-danger-text' : '') + '">' + h(MJ.fmt.int(errors.length)) + '</span></div>' +
		'</div>';

		let errorsHtml = '';
		if (errors.length) {
			errorsHtml = '<div class="mj-mt"><div class="mj-strong mj-mb">Filas con problemas</div><div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
				'<th class="is-num">Fila</th><th>Detalle</th></tr></thead><tbody>' +
				errors.map(function (e) {
					const rowNum = e && e.row !== undefined && e.row !== null ? e.row : '—';
					const msg = e && e.message ? e.message : String(e);
					return '<tr><td class="is-num">' + h(rowNum) + '</td><td>' + h(msg) + '</td></tr>';
				}).join('') +
				'</tbody></table></div></div>';
		}

		let hint = '';
		if (unlinked > 0) {
			hint = alertBox('Los ítems sin vincular no van a sumar stock ni actualizar costos al recibir. Podés vincularlos desde la tabla de productos.', 'warning');
		}

		MJ.ui.modal({
			title: title || 'Resultado de la importación',
			size: 'md',
			body: stats + errorsHtml + (hint ? '<div class="mj-mt">' + hint + '</div>' : ''),
			footer: '<button type="button" class="mj-btn mj-btn--primary" data-modal-close>Listo</button>',
		});
	}

	/* ------------------------------------------------------------------ */
	/* Alta masiva — selector de varios productos                          */
	/* ------------------------------------------------------------------ */

	function openBulkAddModal() {
		const p = state.purchase;
		if (!p || !canEdit(p)) return;
		let modal = null;
		modal = MJ.components.bulkAddModal({
			purchase: p,
			title: 'Agregar varios productos',
			onSubmit(rows) {
				return submitBulkAdd(rows, function () {
					if (modal && typeof modal.close === 'function') modal.close();
				});
			},
		});
	}

	/**
	 * Sends the picked rows to /items/bulk. They already carry product_id/variation_id, so the
	 * server links them by id: no busca por SKU ni crea productos nuevos.
	 * @param {Array} rows  filas del selector
	 * @param {Function} closeModal  cierra el modal cuando el error lo deja sin sentido (compra bloqueada)
	 * @returns {Promise} rechaza para que el modal quede abierto con la selección intacta
	 */
	function submitBulkAdd(rows, closeModal) {
		const list = Array.isArray(rows) ? rows : [];
		if (!list.length) return Promise.resolve();
		if (state.busy['bulk-add']) {
			return Promise.reject(new Error('Ya se están agregando los productos seleccionados.'));
		}
		state.busy['bulk-add'] = true;
		return MJ.api.post(apiPath('/items/bulk'), {
			rows: list,
			match_by: 'sku',
			create_missing: false,
		}).then(function (res) {
			delete state.busy['bulk-add'];
			if (state.disposed) return;
			const report = applyResponse(res);
			const added = report ? parseInt(report.added, 10) || 0 : list.length;
			MJ.ui.toast(added === 1 ? '1 producto agregado a la compra.' : MJ.fmt.int(added) + ' productos agregados a la compra.', 'success');
			const errors = report && Array.isArray(report.errors) ? report.errors : [];
			if (!errors.length) return;
			/* Deferred: el modal del selector se cierra recién cuando esta promesa resuelve. */
			window.setTimeout(function () {
				if (!state.disposed) openImportReportModal(report, list.length, 'Resultado del alta masiva');
			}, 0);
		}).catch(function (err) {
			delete state.busy['bulk-add'];
			if (state.disposed) throw err;
			if (isLocked(err) || (err && err.status === 404)) {
				/* La compra dejó de ser editable: se avisa, se recarga y el selector ya no sirve. */
				handleErr(err, 'No se pudieron agregar los productos.');
				if (err && typeof err === 'object') err.handled = true;
				if (typeof closeModal === 'function') closeModal();
			}
			throw err;
		});
	}

	/* ------------------------------------------------------------------ */
	/* Receive / unreceive                                                 */
	/* ------------------------------------------------------------------ */

	function openReceiveModal() {
		const p = state.purchase;
		if (!p) return;
		const items = Array.isArray(p.items) ? p.items : [];
		if (!items.length) {
			MJ.ui.toast('Agregá al menos un producto antes de recibir la compra.', 'error');
			return;
		}
		const calc = computeCalc(p);
		const fid = 'mj-rc-' + Math.random().toString(36).slice(2, 8);
		const lists = priceLists();
		const defAddStock = String(setting('stock_on_receive', 'increase')) === 'increase';
		const defUpdateCost = setting('update_cost_on_receive', true) !== false;
		const defUpdatePrice = setting('update_price_on_receive', false) === true;
		const defTarget = String(setting('price_target', 'regular_price'));

		const base = baseCur(p);
		const usdMode = publishesUsd();
		const priceHead = usdMode ? 'Venta sugerida (USD)' : 'Precio nuevo';

		const rows = items.map(function (item) {
			const c = calc.items[String(item.id)] || {};
			const linked = itemLinkedId(item) > 0;
			const landed = num(c.landed_unit_base);
			const price = priceForList(landed, state.priceList);
			return '<tr data-rcv-item="' + h(item.id) + '">' +
				'<td>' +
					'<div class="mj-strong">' + h(item.name || 'Sin nombre') + '</div>' +
					(item.sku ? '<div class="mj-small mj-muted">SKU ' + h(item.sku) + '</div>' : '') +
				'</td>' +
				'<td class="is-num">' + h(MJ.fmt.qty(item.quantity)) + '</td>' +
				'<td class="is-num">' +
					'<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-type="number" name="qty_' + h(item.id) + '" value="' + h(inputNum(item.received_qty !== null && item.received_qty !== undefined && num(item.received_qty) > 0 ? item.received_qty : item.quantity, 4)) + '" aria-label="Cantidad recibida de ' + h(item.name || '') + '">' +
				'</td>' +
				'<td>' + (linked ? MJ.ui.badgeRaw('Vinculado', 'success') : MJ.ui.badgeRaw('Sin vincular', 'warning')) + '</td>' +
				'<td class="is-num">' + h(moneyUsd(landed, base)) + '</td>' +
				'<td class="is-num' + (defUpdatePrice ? '' : ' mj-hidden') + '" data-preview data-landed="' + h(String(landed)) + '">' +
					priceCellHtml(price, base) +
				'</td>' +
			'</tr>';
		}).join('');

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
					'<th>Producto</th>' +
					'<th class="is-num">Pedido</th>' +
					'<th class="is-num">Recibido</th>' +
					'<th>Vínculo</th>' +
					'<th class="is-num">Costo final</th>' +
					'<th class="is-num' + (defUpdatePrice ? '' : ' mj-hidden') + '" data-preview-head>' + h(priceHead) + '</th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table></div>' +
				'<div class="mj-divider"></div>' +
				'<div class="mj-stack">' +
					'<label class="mj-checkbox"><input type="checkbox" name="add_stock"' + (defAddStock ? ' checked' : '') + '> <span>Sumar stock en WooCommerce</span></label>' +
					'<label class="mj-checkbox"><input type="checkbox" name="update_cost"' + (defUpdateCost ? ' checked' : '') + '> <span>Actualizar costo de los productos</span></label>' +
					'<label class="mj-checkbox"><input type="checkbox" name="update_price" data-update-price' + (defUpdatePrice ? ' checked' : '') + '> <span>' +
						h(usdMode ? 'Publicar el precio de venta en dólares (MJ Dólar)' : 'Actualizar precio de WooCommerce') + '</span></label>' +
				'</div>' +
				'<div class="mj-note mj-small mj-muted mj-mt' + (defUpdatePrice ? '' : ' mj-hidden') + '" data-price-options>' +
					MJ.icon('info') + '<span>' + h(publishNote()) + '</span>' +
				'</div>' +
				'<div class="mj-form-row mj-mt' + (defUpdatePrice ? '' : ' mj-hidden') + '" data-price-options>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-pl">Lista de precio</label>' +
						'<select class="mj-select" id="' + fid + '-pl" name="price_list"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-pt">Campo a actualizar</label>' +
						'<select class="mj-select" id="' + fid + '-pt" name="price_target">' + optionsHtml(PRICE_TARGETS, defTarget) + '</select>' +
					'</div>' +
				'</div>' +
				(lists.length ? '' : '<div class="mj-note mj-small mj-muted mj-mt">No hay listas de precio configuradas: no se pueden actualizar precios.</div>') +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="' + fid + '-note">Nota de la recepción</label>' +
					'<input class="mj-input" id="' + fid + '-note" name="note" type="text" maxlength="255" placeholder="Ej: llegó completo, 2 cajas dañadas…">' +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('check') + '<span>Confirmar recepción</span></button>';

		MJ.ui.modal({
			title: 'Marcar compra como recibida',
			size: 'xl',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				const priceCheck = qs('[data-update-price]', form);
				const priceOptions = qsa('[data-price-options]', form);
				const listSel = qs('[name="price_list"]', form);
				let submitting = false;

				if (lists.length) {
					try {
						MJ.components.priceListSelect(listSel, state.priceList, {});
					} catch (e) {
						console.error('[MJ] priceListSelect', e);
					}
				} else if (priceCheck) {
					priceCheck.checked = false;
					priceCheck.disabled = true;
				}

				const refreshPreview = function () {
					const key = listSel ? listSel.value : '';
					qsa('[data-preview]', form).forEach(function (cell) {
						const landed = num(cell.getAttribute('data-landed'));
						cell.innerHTML = priceCellHtml(priceForList(landed, key), base);
					});
				};

				const togglePrice = function () {
					const on = !!(priceCheck && priceCheck.checked);
					priceOptions.forEach(function (box) { box.classList.toggle('mj-hidden', !on); });
					qsa('[data-preview], [data-preview-head]', form).forEach(function (cell) { cell.classList.toggle('mj-hidden', !on); });
					if (on) refreshPreview();
				};

				if (priceCheck) priceCheck.addEventListener('change', togglePrice);
				if (listSel) listSel.addEventListener('change', refreshPreview);
				togglePrice();

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);
					const data = MJ.ui.formData(form);
					const errors = {};
					const payloadItems = [];

					items.forEach(function (item) {
						const key = 'qty_' + item.id;
						let v = data[key];
						if (typeof v !== 'number') v = MJ.fmt.parse(v);
						if (v === null || isNaN(v) || v < 0) {
							errors[key] = 'Cantidad inválida.';
							return;
						}
						payloadItems.push({ item_id: parseInt(item.id, 10), received_qty: v });
					});

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const updatePrice = !!data.update_price && lists.length > 0;
					const payload = {
						items: payloadItems,
						add_stock: !!data.add_stock,
						update_cost: !!data.update_cost,
						update_price: updatePrice,
						note: String(data.note || '').trim(),
					};
					if (updatePrice) {
						payload.price_list = String(data.price_list || state.priceList || '');
						payload.price_target = PRICE_TARGETS.some(function (t) { return t.value === data.price_target; }) ? data.price_target : 'regular_price';
					}

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);
					MJ.api.post(apiPath('/receive'), payload).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (state.disposed) { modal.close(); return; }
						const report = applyResponse(res);
						MJ.ui.toast('Compra recibida.', 'success');
						modal.close();
						window.setTimeout(function () {
							if (!state.disposed) openReceiveReportModal(report);
						}, 0);
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						if (isLocked(err)) { modal.close(); handleErr(err); return; }
						showFormError(form, err);
					});
				});
			},
		});
	}

	function openReceiveReportModal(report) {
		const p = state.purchase;
		report = report && typeof report === 'object' ? report : {};
		const rows = Array.isArray(report.items) ? report.items : [];
		const errors = parseInt(report.errors, 10) || 0;
		const addedStock = parseInt(report.added_stock_items, 10) || 0;
		const base = baseCur(p);

		const publishedUsd = parseInt(report.usd_published_items, 10) || 0;

		let summary = alertBox(addedStock === 1
			? 'Se sumó stock en 1 producto.'
			: 'Se sumó stock en ' + MJ.fmt.int(addedStock) + ' productos.', errors ? 'warning' : 'success');
		if (publishedUsd) {
			summary += alertBox(publishedUsd === 1
				? 'Se publicó el precio en dólares de 1 producto: la tienda ya lo muestra al TC vigente.'
				: 'Se publicó el precio en dólares de ' + MJ.fmt.int(publishedUsd) + ' productos: la tienda ya los muestra al TC vigente.', 'success');
		}
		if (Array.isArray(report.warnings)) {
			report.warnings.forEach(function (w) { if (w) summary += alertBox(String(w), 'warning'); });
		}
		if (errors) summary += alertBox(errors === 1 ? '1 ítem tuvo problemas.' : MJ.fmt.int(errors) + ' ítems tuvieron problemas.', 'danger');

		let table;
		if (!rows.length) {
			table = '<div class="mj-note mj-small mj-muted mj-mt">El servidor no devolvió detalle por ítem.</div>';
		} else {
			table = '<div class="mj-table-wrap mj-mt"><table class="mj-table mj-table--compact"><thead><tr>' +
				'<th>Producto</th><th>Estado</th><th class="is-num">Stock</th><th class="is-num">Costo</th>' +
				'<th class="is-num">Venta publicada</th><th>Detalle</th>' +
			'</tr></thead><tbody>' +
			rows.map(function (r) {
				const info = RECEIVE_STATUS[r.status] || { label: r.status || '—', color: 'neutral' };
				const before = r.stock_before === null || r.stock_before === undefined ? null : num(r.stock_before);
				const after = r.stock_after === null || r.stock_after === undefined ? null : num(r.stock_after);
				const stock = (before === null && after === null)
					? '<span class="mj-muted">—</span>'
					: h(MJ.fmt.qty(before) + ' → ' + MJ.fmt.qty(after));

				const costCell = r.cost_updated
					? '<span class="mj-strong">' + h(moneyUsd(r.cost_usd, base)) + '</span>'
					: '<span class="mj-muted">—</span>';

				let priceCell = '<span class="mj-muted">—</span>';
				if (r.price_updated) {
					if (String(r.price_mode) === 'usd' && r.new_price_usd !== null && r.new_price_usd !== undefined) {
						priceCell = '<span class="mj-strong">' + h(moneyUsd(r.new_price_usd, base)) + '</span>' +
							(r.new_price_local !== null && r.new_price_local !== undefined
								? '<div class="mj-small mj-muted">' + h(MJ.fmt.moneyLocal(r.new_price_local)) + '</div>'
								: '');
					} else if (r.new_price !== null && r.new_price !== undefined) {
						priceCell = '<span class="mj-strong">' + h(
							r.new_price_usd !== null && r.new_price_usd !== undefined
								? moneyUsd(r.new_price_usd, base)
								: MJ.fmt.moneyLocal(r.new_price)
						) + '</span>';
					} else {
						priceCell = MJ.icon('check');
					}
				}

				return '<tr>' +
					'<td>' + h(r.name || ('Ítem #' + (r.item_id || ''))) + '</td>' +
					'<td>' + MJ.ui.badgeRaw(info.label, info.color) + '</td>' +
					'<td class="is-num">' + stock + '</td>' +
					'<td class="is-num">' + costCell + '</td>' +
					'<td class="is-num">' + priceCell + '</td>' +
					'<td class="mj-small mj-muted">' + h(r.message || '') + '</td>' +
				'</tr>';
			}).join('') +
			'</tbody></table></div>';
		}

		// The same modal reports an unreceive, where nothing was costed or published: there the
		// server already sends its own warning, so no footnote is needed.
		const isReceive = report.price_mode !== undefined && report.price_mode !== null;
		const note = isReceive
			? '<div class="mj-note mj-small mj-muted mj-mt">Los costos se guardaron en ' + h(base) + '. ' + h(publishNote()) + '</div>'
			: '';

		MJ.ui.modal({
			title: 'Recepción completada',
			size: 'lg',
			body: summary + table + note,
			footer: '<button type="button" class="mj-btn mj-btn--primary" data-modal-close>Listo</button>',
		});
	}

	function unreceive(btn) {
		const p = state.purchase;
		if (!p) return;
		MJ.ui.confirm({
			title: 'Deshacer recepción',
			message: 'Se descuenta el stock que se había sumado y la compra vuelve a "En tránsito". Los costos y los precios de los productos NO se revierten.',
			okLabel: 'Deshacer recepción',
			cancelLabel: 'Cancelar',
			danger: true,
		}).then(function (ok) {
			if (!ok || state.disposed) return;
			mutate('unreceive', function () {
				return MJ.api.post(apiPath('/unreceive'), {});
			}, btn, { success: 'Recepción deshecha.', fail: 'No se pudo deshacer la recepción.' }).then(function (report) {
				if (state.disposed || !report || typeof report !== 'object') return;
				window.setTimeout(function () {
					if (!state.disposed) openReceiveReportModal(report);
				}, 0);
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Notes                                                               */
	/* ------------------------------------------------------------------ */

	function saveNotes(btn) {
		const el = region('notes');
		const ta = el ? qs('[data-notes]', el) : null;
		if (!ta) return;
		const notes = String(ta.value || '');
		mutate('notes', function () {
			return MJ.api.put(apiPath(), { notes: notes });
		}, btn, { success: 'Notas guardadas.', fail: 'No se pudieron guardar las notas.' }).then(function (ok) {
			if (state.disposed || !ok) return;
			state.notesDirty = false;
			if (state.purchase) paintNotes(state.purchase);
		});
		state.notesDirty = false;
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
	/* ------------------------------------------------------------------ */

	function bind() {
		const c = state.container;

		state.offs.push(MJ.ui.on(c, 'click', '[data-action]', function (e, btn) {
			const action = btn.getAttribute('data-action');
			if (action === 'back') return; // plain link
			e.preventDefault();
			switch (action) {
				case 'reload':
					MJ.ui.setLoading(btn, true);
					load().then(function () { if (document.body.contains(btn)) MJ.ui.setLoading(btn, false); });
					break;
				case 'edit': openEditModal(); break;
				case 'status-transito': changeStatus('en_transito', btn); break;
				case 'status-borrador': changeStatus('borrador', btn); break;
				case 'status-cancelar': changeStatus('cancelada', btn); break;
				case 'duplicate': duplicate(btn); break;
				case 'delete': removePurchase(btn); break;
				case 'import': openImportModal(); break;
				case 'bulk-add': openBulkAddModal(); break;
				case 'item-link': openLinkModal(btn.getAttribute('data-id')); break;
				case 'item-create': openCreateProductForItem(btn.getAttribute('data-id')); break;
				case 'item-delete': deleteItem(btn.getAttribute('data-id'), btn); break;
				case 'expense-prorated': toggleProrated(btn.getAttribute('data-id'), btn); break;
				case 'expense-delete': deleteExpense(btn.getAttribute('data-id'), btn); break;
				case 'receive': openReceiveModal(); break;
				case 'unreceive': unreceive(btn); break;
				case 'save-notes': saveNotes(btn); break;
				default: break;
			}
		}));

		state.offs.push(MJ.ui.on(c, 'submit', 'form[data-form]', function (e, form) {
			e.preventDefault();
			const kind = form.getAttribute('data-form');
			if (kind === 'add-item') submitAddItem(form);
			else if (kind === 'add-expense') submitAddExpense(form);
		}));

		/* Inline editing ------------------------------------------------ */
		state.offs.push(MJ.ui.on(c, 'click', '.mj-table__editable', function (e, cell) {
			if (e.target && e.target.closest('.mj-cell-input')) return;
			beginEdit(cell);
		}));
		state.offs.push(MJ.ui.on(c, 'keydown', '.mj-table__editable', function (e, cell) {
			if (e.target && e.target.classList && e.target.classList.contains('mj-cell-input')) return;
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); beginEdit(cell); }
		}));
		state.offs.push(MJ.ui.on(c, 'keydown', '.mj-cell-input', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); commitEdit(); }
			else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelEdit(); }
		}));
		state.offs.push(MJ.ui.on(c, 'focusout', '.mj-cell-input', function (e, input) {
			const pending = state.edit;
			if (!pending || pending.input !== input) return;
			/* Deferred so a click on another cell can commit this one first without double firing. */
			window.setTimeout(function () {
				if (state.disposed || state.edit !== pending) return;
				commitEdit();
			}, 0);
		}));

		/* Price list of the live costing card ---------------------------- */
		state.offs.push(MJ.ui.on(c, 'change', '[data-price-list]', function (e, sel) {
			state.priceList = sel.value;
			paintCostList();
		}));

		/* Notes dirty tracking ------------------------------------------ */
		state.offs.push(MJ.ui.on(c, 'input', '[data-notes]', function () { state.notesDirty = true; }));

		/* Settings may change currencies / lists ------------------------- */
		state.offs.push(MJ.events.on('settings:changed', function () {
			if (state.disposed || !state.purchase) return;
			state.formsSig = '';
			if (!priceListOf(state.priceList)) state.priceList = defaultPriceListKey();
			paintAll();
		}));
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.purchase = {
		title(params) {
			const id = params && params.id;
			if (state.purchase && String(state.purchase.id) === String(id) && state.purchase.code) {
				return 'Compra ' + state.purchase.code;
			}
			return 'Compra';
		},

		render(container, params) {
			const id = parseInt(params && params.id, 10);

			state.id = id > 0 ? id : 0;
			state.purchase = null;
			state.container = container;
			state.layoutReady = false;
			state.disposed = false;
			state.seq = 0;
			state.loading = false;
			state.formsSig = '';
			state.notesDirty = false;
			state.busy = {};
			state.edit = null;
			state.offs = [];
			state.comp = { itemSearch: null, expenseCashbox: null };
			if (!priceListOf(state.priceList)) state.priceList = defaultPriceListKey();

			if (!state.id) {
				container.innerHTML = '<div class="mj-section">' +
					MJ.ui.empty({
						icon: 'warning',
						title: 'Compra inválida',
						text: 'El identificador de la compra no es válido.',
						action: '<a class="mj-btn mj-btn--primary" href="' + h(MJ.router.link('/compras')) + '">' + MJ.icon('cart') + '<span>Volver a compras</span></a>',
					}) +
				'</div>';
				return function cleanup() { state.disposed = true; };
			}

			bind();
			showBootLoading();
			load();

			return function cleanup() {
				state.disposed = true;
				state.seq++;
				state.offs.forEach(function (off) { if (typeof off === 'function') { try { off(); } catch (e) { /* noop */ } } });
				state.offs = [];
				destroyForms();
				state.edit = null;
				state.container = null;
				state.layoutReady = false;
			};
		},
	};
})();
