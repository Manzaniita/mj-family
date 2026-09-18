/**
 * MJ ERP — page-reportes.js
 * MJ.pages.reportes: rentabilidad real de lo vendido.
 *
 * Precio de venta menos costo landed, por producto, por canal, por mes y por cliente, y lo que se
 * vendió por debajo del costo. Todo sale de lo que cada venta ya guarda (el costo queda congelado
 * al día de la venta): acá no se recalcula nada, se agrupa y se muestra.
 *
 * REST: GET /reports/summary, /reports/products, /reports/below-cost, /reports/monthly, /reports/customers.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PER_PAGE = 50;
	const SEARCH_DELAY = 350;

	function num(v) { const n = MJ.fmt.toNumber(v); return n === null ? 0 : n; }
	function dash() { return '<span class="mj-muted">—</span>'; }
	function reportsInfo() { return (MJ.state.bootstrap && MJ.state.bootstrap.reports) || {}; }
	function baseCur() { return String(MJ.setting('base_currency', 'USD')).toUpperCase(); }
	function moneyBase(v) {
		const n = num(v);
		return MJ.fmt.money(n, baseCur(), Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2);
	}
	// Los pesos al dólar de hoy, solo como referencia.
	function localToday(v) { const l = MJ.fmt.toLocal(num(v)); return l === null ? '' : MJ.fmt.moneyLocal(l, 0); }
	function moneyPairHtml(v) {
		const local = localToday(v);
		return '<span class="mj-money-pair"><span class="mj-money-pair__base">' + h(moneyBase(v)) + '</span>' + (local ? '<span class="mj-money-pair__local">' + h(local) + '</span>' : '') + '</span>';
	}
	function pctHtml(v, flagged) {
		const n = num(v);
		return '<span class="' + (flagged || n < 0 ? 'mj-danger-text mj-strong' : '') + '">' + h(MJ.fmt.pct(n, 1)) + '</span>';
	}
	function firstOfMonth() { return MJ.fmt.today().slice(0, 8) + '01'; }
	function monthLabel(ym) {
		const m = String(ym || '').match(/^(\d{4})-(\d{2})$/);
		if (!m) return String(ym || '');
		const names = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
		return names[parseInt(m[2], 10) - 1] + ' ' + m[1];
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	/* ------------------------------------------------------------------ */
	/* CSV                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Una celda de CSV para Excel en español: separador «;», comillas escapadas y las fórmulas
	 * neutralizadas (un nombre de producto que empiece con «=» no tiene que ejecutarse al abrir).
	 */
	function csvCell(v) {
		let s = v === null || v === undefined ? '' : String(v);
		if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
		if (/[";\n\r]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
		return s;
	}
	function csvNumber(v) { return String(num(v)).replace('.', ','); }
	function downloadCsv(name, rows) {
		const csv = '﻿' + rows.map(function (r) { return r.map(csvCell).join(';'); }).join('\r\n');
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = name;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function kpi(c) {
		return '<div class="mj-card mj-kpi' + (c.className ? ' ' + c.className : '') + '">' +
			'<div class="mj-kpi__label">' + MJ.icon(c.icon) + '<span>' + h(c.label) + '</span></div>' +
			'<div class="mj-kpi__value' + (c.money ? ' mj-money' : '') + '">' + (c.valueHtml !== undefined ? c.valueHtml : h(c.value)) + '</div>' +
			(c.local ? '<div class="mj-kpi__local mj-muted mj-money">' + h(c.local) + '</div>' : '') +
			'<div class="mj-kpi__sub mj-muted">' + h(c.sub || '') + '</div>' +
		'</div>';
	}

	function summaryHtml(s) {
		if (!s) return loadingHtml('Calculando…');
		const below = s.below_cost || {};
		const belowCount = num(below.lines_count);
		return '<div class="mj-grid mj-grid--4 mj-mb">' +
			kpi({ icon: 'cash', label: 'Vendido', money: true, className: 'mj-kpi--month', value: moneyBase(s.revenue_base), local: localToday(s.revenue_base), sub: MJ.fmt.int(s.sales_count) + (s.sales_count === 1 ? ' venta' : ' ventas') + ' · ' + MJ.fmt.qty(s.qty_total) + ' u.' }) +
			kpi({ icon: 'box', label: 'Costo de lo vendido', money: true, value: moneyBase(s.cost_base), local: localToday(s.cost_base), sub: s.no_cost_count ? MJ.fmt.int(s.no_cost_count) + (s.no_cost_count === 1 ? ' venta sin costo cargado' : ' ventas sin costo cargado') : 'Costo landed al día de cada venta' }) +
			kpi({ icon: 'chart', label: 'Ganancia', money: true, className: num(s.profit_base) < 0 ? 'mj-kpi--danger' : 'mj-kpi--received', value: moneyBase(s.profit_base), local: localToday(s.profit_base), sub: 'Margen ' + MJ.fmt.pct(s.margin_pct, 1) + ' · markup ' + MJ.fmt.pct(s.markup_pct, 1) }) +
			kpi({ icon: belowCount ? 'warning' : 'check-circle', label: 'Vendido bajo costo', className: belowCount ? 'mj-kpi--danger' : 'mj-kpi--received', value: MJ.fmt.int(belowCount), sub: belowCount ? 'Se perdieron ' + moneyBase(below.loss_base) + ' en esas líneas' : 'Nada por debajo del costo' }) +
		'</div>';
	}

	function toolbarHtml(uid, state) {
		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-filters novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:0 1 170px">' +
					'<label class="mj-field__label" for="' + uid + '-from">Desde</label>' +
					'<input class="mj-input" id="' + uid + '-from" type="date" name="date_from" value="' + h(state.from) + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 170px">' +
					'<label class="mj-field__label" for="' + uid + '-to">Hasta</label>' +
					'<input class="mj-input" id="' + uid + '-to" type="date" name="date_to" value="' + h(state.to) + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto;align-self:flex-end">' +
					'<div class="mj-btn-group">' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="month">Este mes</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="last">Mes pasado</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="30">30 días</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="year">Este año</button>' +
					'</div>' +
				'</div>' +
			'</form>' +
		'</div></div>';
	}

	function tabsHtml(active) {
		const tabs = [
			{ key: 'products', label: 'Por producto', icon: 'box' },
			{ key: 'below', label: 'Bajo costo', icon: 'warning' },
			{ key: 'channels', label: 'Por canal', icon: 'layers' },
			{ key: 'monthly', label: 'Por mes', icon: 'calendar' },
			{ key: 'customers', label: 'Por cliente', icon: 'user' },
		];
		return '<div class="mj-tabs" role="tablist">' + tabs.map(function (t) {
			return '<button type="button" class="mj-tabs__tab' + (t.key === active ? ' is-active' : '') + '" data-tab="' + t.key + '" role="tab" aria-selected="' + (t.key === active ? 'true' : 'false') + '">' + MJ.icon(t.icon) + '<span>' + h(t.label) + '</span></button>';
		}).join('') + '</div>';
	}

	function productsToolbarHtml(uid, state) {
		return '<div class="mj-card__body">' +
			'<form class="mj-form mj-inline-form mj-row mj-row--wrap" novalidate autocomplete="off" onsubmit="return false">' +
				'<div class="mj-field" style="flex:2 1 240px">' +
					'<label class="mj-field__label" for="' + uid + '-search">Buscar</label>' +
					'<div class="mj-input-group"><span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
					'<input class="mj-input" id="' + uid + '-search" type="search" name="search" placeholder="Nombre o SKU…" value="' + h(state.search) + '"></div>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 220px">' +
					'<label class="mj-field__label" for="' + uid + '-orderby">Ordenar por</label>' +
					'<select class="mj-select" id="' + uid + '-orderby" name="orderby">' +
						[['profit_base', 'Más ganancia'], ['margin_pct', 'Mejor margen'], ['revenue_base', 'Más vendidos ($)'], ['qty', 'Más vendidos (unidades)'], ['name', 'Nombre']].map(function (o) {
							return '<option value="' + o[0] + '"' + (state.orderby === o[0] ? ' selected' : '') + '>' + h(o[1]) + '</option>';
						}).join('') +
					'</select>' +
				'</div>' +
				'<div class="mj-field mj-field--check" style="flex:0 0 auto;align-self:flex-end">' +
					'<label class="mj-checkbox" for="' + uid + '-flagged"><input type="checkbox" id="' + uid + '-flagged" name="only_flagged"' + (state.onlyFlagged ? ' checked' : '') + '><span>Solo bajo costo o con poco margen</span></label>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto;align-self:flex-end;margin-left:auto">' +
					'<button type="button" class="mj-btn mj-btn--ghost mj-btn--sm" data-action="export-products">' + MJ.icon('download') + '<span>CSV</span></button>' +
				'</div>' +
			'</form>' +
		'</div>';
	}

	function productRowHtml(p) {
		const flagged = p.below_cost || p.low_margin;
		return '<tr' + (flagged ? ' class="is-flagged"' : '') + '>' +
			'<td><div class="mj-strong">' + h(p.name) + '</div>' +
				'<div class="mj-small mj-muted">' + (p.sku ? 'SKU ' + h(p.sku) + ' · ' : '') + h(MJ.fmt.int(p.sales_count)) + (p.sales_count === 1 ? ' venta' : ' ventas') + '</div>' +
				(p.below_cost ? '<div class="mj-small mj-danger-text">' + MJ.icon('warning') + ' Vendido por debajo del costo' + (p.below_cost_lines > 1 ? ' (' + h(MJ.fmt.int(p.below_cost_lines)) + ' líneas)' : '') + '</div>' : '') +
				(p.no_cost ? '<div class="mj-small mj-warning-text">Sin costo cargado: el margen no se puede medir</div>' : '') +
			'</td>' +
			'<td class="mj-text-right">' + h(MJ.fmt.qty(p.qty)) + '</td>' +
			'<td class="mj-text-right">' + moneyPairHtml(p.unit_price_base) + '</td>' +
			'<td class="mj-text-right">' + (p.no_cost ? dash() : moneyPairHtml(p.unit_cost_base)) + '</td>' +
			'<td class="mj-text-right">' + moneyPairHtml(p.revenue_base) + '</td>' +
			'<td class="mj-text-right"><span class="mj-money ' + (num(p.profit_base) < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + h(moneyBase(p.profit_base)) + '</span></td>' +
			'<td class="mj-text-right">' + (p.no_cost ? dash() : pctHtml(p.margin_pct, flagged)) + '</td>' +
		'</tr>';
	}

	function productsTableHtml(state) {
		const res = state.products;
		if (state.errorProducts) return '<div class="mj-card__body">' + MJ.ui.alert(MJ.api.message(state.errorProducts, 'No se pudo cargar el reporte.'), 'danger') + '</div>';
		if (!res) return loadingHtml('Calculando por producto…');
		if (!res.items.length) return MJ.ui.empty({ icon: 'box', title: 'Sin ventas en el período', text: state.onlyFlagged ? 'Ningún producto bajo costo ni con poco margen. Buena señal.' : 'No se vendió nada entre esas fechas.' });
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Producto</th><th class="mj-text-right">Unid.</th><th class="mj-text-right">Precio unit.</th><th class="mj-text-right">Costo unit.</th><th class="mj-text-right">Vendido</th><th class="mj-text-right">Ganancia</th><th class="mj-text-right">Margen</th>' +
		'</tr></thead><tbody>' + res.items.map(productRowHtml).join('') + '</tbody></table></div>';
		if (res.pages > 1) {
			html += '<div class="mj-card__footer mj-row mj-row--between">' +
				'<span class="mj-small mj-muted">Página ' + MJ.fmt.int(res.page) + ' de ' + MJ.fmt.int(res.pages) + ' · ' + MJ.fmt.int(res.total) + ' productos</span>' +
				'<span class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="prev"' + (res.page <= 1 ? ' disabled' : '') + '>' + MJ.icon('chevron-left') + '<span>Anterior</span></button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="next"' + (res.page >= res.pages ? ' disabled' : '') + '>' + MJ.icon('chevron-right') + '<span>Siguiente</span></button>' +
				'</span>' +
			'</div>';
		}
		return html;
	}

	function belowHtml(state) {
		const res = state.below;
		if (!res) return loadingHtml('Buscando ventas bajo costo…');
		if (!res.items.length) return MJ.ui.empty({ icon: 'check-circle', title: 'Nada vendido bajo costo', text: 'Ninguna línea del período se vendió por debajo de su costo landed.' });
		return '<div class="mj-card__body"><p class="mj-muted mj-small">Cada línea es un producto de una venta que salió más barato que su costo. Puede ser un precio mal cargado, un descuento de más o un costo desactualizado: conviene mirar la venta.</p></div>' +
			'<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Venta</th><th>Producto</th><th class="mj-text-right">Unid.</th><th class="mj-text-right">Precio unit.</th><th class="mj-text-right">Costo unit.</th><th class="mj-text-right">Pérdida</th>' +
		'</tr></thead><tbody>' + res.items.map(function (r) {
			return '<tr>' +
				'<td><a class="mj-link mj-mono" href="' + h(MJ.router.link('/ventas/' + r.sale_id)) + '">' + h(r.sale_code) + '</a><div class="mj-small mj-muted">' + h(MJ.fmt.date(r.sale_date)) + (r.customer_name ? ' · ' + h(r.customer_name) : '') + ' · ' + h(r.channel_label) + '</div></td>' +
				'<td><div class="mj-strong">' + h(r.name) + '</div>' + (r.sku ? '<div class="mj-small mj-muted">SKU ' + h(r.sku) + '</div>' : '') + '</td>' +
				'<td class="mj-text-right">' + h(MJ.fmt.qty(r.qty)) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.unit_price_base) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.unit_cost_base) + '</td>' +
				'<td class="mj-text-right"><span class="mj-money mj-danger-text mj-strong">' + h('−' + moneyBase(r.loss_base)) + '</span></td>' +
			'</tr>';
		}).join('') + '</tbody></table></div>';
	}

	function groupedTableHtml(rows, firstCol) {
		if (!rows.length) return MJ.ui.empty({ icon: 'layers', title: 'Sin ventas en el período', text: '' });
		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>' + h(firstCol) + '</th><th class="mj-text-right">Ventas</th><th class="mj-text-right">Vendido</th><th class="mj-text-right">Costo</th><th class="mj-text-right">Ganancia</th><th class="mj-text-right">Margen</th>' +
		'</tr></thead><tbody>' + rows.map(function (r) {
			return '<tr>' +
				'<td class="mj-strong">' + h(r.label) + '</td>' +
				'<td class="mj-text-right">' + h(MJ.fmt.int(r.sales_count)) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.revenue_base) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.cost_base) + '</td>' +
				'<td class="mj-text-right"><span class="mj-money ' + (num(r.profit_base) < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + h(moneyBase(r.profit_base)) + '</span></td>' +
				'<td class="mj-text-right">' + pctHtml(r.margin_pct) + '</td>' +
			'</tr>';
		}).join('') + '</tbody></table></div>';
	}

	function channelsHtml(state) {
		if (!state.summary) return loadingHtml('Calculando…');
		return '<div class="mj-card__body"><h3 class="mj-card__title">Por canal</h3></div>' + groupedTableHtml(state.summary.by_channel || [], 'Canal') +
			'<div class="mj-card__body mj-mt"><h3 class="mj-card__title">Por origen</h3></div>' + groupedTableHtml(state.summary.by_source || [], 'Origen');
	}

	function monthlyHtml(state) {
		const rows = state.monthly;
		if (!rows) return loadingHtml('Calculando por mes…');
		const max = rows.reduce(function (m, r) { return Math.max(m, num(r.revenue_base)); }, 0);
		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Mes</th><th class="mj-text-right">Ventas</th><th class="mj-text-right">Vendido</th><th class="mj-text-right">Ganancia</th><th class="mj-text-right">Margen</th><th style="width:30%"><span class="mj-hidden">Gráfico</span></th>' +
		'</tr></thead><tbody>' + rows.map(function (r) {
			const w = max > 0 ? Math.round(100 * num(r.revenue_base) / max) : 0;
			const pw = num(r.revenue_base) > 0 ? Math.max(0, Math.round(w * num(r.profit_base) / num(r.revenue_base))) : 0;
			return '<tr>' +
				'<td class="mj-strong">' + h(monthLabel(r.month)) + '</td>' +
				'<td class="mj-text-right">' + h(MJ.fmt.int(r.sales_count)) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.revenue_base) + '</td>' +
				'<td class="mj-text-right"><span class="mj-money ' + (num(r.profit_base) < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + h(moneyBase(r.profit_base)) + '</span></td>' +
				'<td class="mj-text-right">' + pctHtml(r.margin_pct) + '</td>' +
				'<td><div class="mj-report-bar" title="' + h('Vendido ' + moneyBase(r.revenue_base) + ' · ganancia ' + moneyBase(r.profit_base)) + '"><span class="mj-report-bar__total" style="width:' + w + '%"></span><span class="mj-report-bar__profit" style="width:' + pw + '%"></span></div></td>' +
			'</tr>';
		}).join('') + '</tbody></table></div>' +
		'<div class="mj-card__body mj-small mj-muted">La barra clara es lo vendido; la oscura, la ganancia. Últimos ' + rows.length + ' meses, sin contar las ventas anuladas.</div>';
	}

	function customersHtml(state) {
		const rows = state.customers;
		if (!rows) return loadingHtml('Calculando por cliente…');
		if (!rows.length) return MJ.ui.empty({ icon: 'user', title: 'Sin ventas en el período', text: '' });
		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Cliente</th><th class="mj-text-right">Ventas</th><th class="mj-text-right">Vendido</th><th class="mj-text-right">Ganancia</th><th class="mj-text-right">Margen</th>' +
		'</tr></thead><tbody>' + rows.map(function (r) {
			const name = r.customer_id ? '<a class="mj-link" href="' + h(MJ.router.link('/clientes/' + r.customer_id)) + '">' + h(r.customer_name) + '</a>' : h(r.customer_name);
			return '<tr>' +
				'<td class="mj-strong">' + name + '</td>' +
				'<td class="mj-text-right">' + h(MJ.fmt.int(r.sales_count)) + '</td>' +
				'<td class="mj-text-right">' + moneyPairHtml(r.revenue_base) + '</td>' +
				'<td class="mj-text-right"><span class="mj-money ' + (num(r.profit_base) < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + h(moneyBase(r.profit_base)) + '</span></td>' +
				'<td class="mj-text-right">' + pctHtml(r.margin_pct) + '</td>' +
			'</tr>';
		}).join('') + '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.reportes = {
		title() { return 'Reportes'; },

		render(container, params, query) {
			if (reportsInfo().enabled === false) {
				container.innerHTML = MJ.ui.alert('Los Reportes están apagados. Se prenden en Configuración → Caja y reportes.', 'warning');
				return function () {};
			}

			const uid = 'mj-rep-' + Math.random().toString(36).slice(2, 8);
			const state = {
				from: firstOfMonth(),
				to: MJ.fmt.today(),
				tab: (query && query.tab) || 'products',
				summary: null,
				products: null,
				below: null,
				monthly: null,
				customers: null,
				errorProducts: null,
				search: '',
				orderby: 'profit_base',
				onlyFlagged: !!(query && query.tab === 'below'),
				page: 1,
				seq: 0,
				pseq: 0,
				disposed: false,
			};
			if (state.tab === 'below') state.onlyFlagged = false;

			container.innerHTML =
				'<div class="mj-page-header">' +
					'<div>' +
						'<h2 class="mj-page-header__title">Reportes</h2>' +
						'<div class="mj-page-header__subtitle mj-muted">Rentabilidad real: precio de venta menos costo landed, con el costo congelado al día de cada venta. Las anuladas no cuentan.</div>' +
					'</div>' +
					'<div class="mj-page-header__actions"><button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button></div>' +
				'</div>' +
				'<div data-region="summary">' + loadingHtml('Calculando…') + '</div>' +
				toolbarHtml(uid, state) +
				'<div class="mj-card mj-card--flush">' +
					'<div class="mj-card__header">' + tabsHtml(state.tab) + '</div>' +
					'<div data-tab-panel="products" data-region="products"' + (state.tab === 'products' ? '' : ' class="mj-hidden"') + '>' + productsToolbarHtml(uid, state) + '<div data-region="products-table">' + loadingHtml('Calculando por producto…') + '</div></div>' +
					'<div data-tab-panel="below" data-region="below"' + (state.tab === 'below' ? '' : ' class="mj-hidden"') + '></div>' +
					'<div data-tab-panel="channels" data-region="channels"' + (state.tab === 'channels' ? '' : ' class="mj-hidden"') + '></div>' +
					'<div data-tab-panel="monthly" data-region="monthly"' + (state.tab === 'monthly' ? '' : ' class="mj-hidden"') + '></div>' +
					'<div data-tab-panel="customers" data-region="customers"' + (state.tab === 'customers' ? '' : ' class="mj-hidden"') + '></div>' +
				'</div>';

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };
			const tabs = MJ.ui.tabs(qs('.mj-tabs', container), {
				active: state.tab,
				panels: qs('.mj-card--flush', container),
				onChange(key) { state.tab = key; loadTab(); },
			});

			function rangeParams() {
				const p = {};
				if (state.from) p.date_from = state.from;
				if (state.to) p.date_to = state.to;
				return p;
			}

			function loadSummary() {
				const mine = state.seq;
				region('summary').innerHTML = loadingHtml('Calculando…');
				MJ.api.get('reports/summary', rangeParams()).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.summary = res;
					region('summary').innerHTML = summaryHtml(res);
					if (state.tab === 'channels') region('channels').innerHTML = channelsHtml(state);
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					region('summary').innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo calcular el resumen.'), 'danger');
				});
			}

			function loadProducts() {
				// Secuencia propia: el orden y la página cambian sin tocar el resumen.
				const mine = ++state.pseq;
				state.products = null;
				state.errorProducts = null;
				region('products-table').innerHTML = productsTableHtml(state);
				const p = Object.assign(rangeParams(), { page: state.page, per_page: PER_PAGE, orderby: state.orderby, order: state.orderby === 'name' ? 'ASC' : 'DESC' });
				if (state.search) p.search = state.search;
				if (state.onlyFlagged) p.only_flagged = 1;
				MJ.api.get('reports/products', p).then(function (res) {
					if (state.disposed || mine !== state.pseq) return;
					state.products = res;
					region('products-table').innerHTML = productsTableHtml(state);
				}).catch(function (err) {
					if (state.disposed || mine !== state.pseq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.errorProducts = err;
					region('products-table').innerHTML = productsTableHtml(state);
				});
			}

			function loadTab() {
				const mine = state.seq;
				if (state.tab === 'products') { if (!state.products) loadProducts(); return; }
				if (state.tab === 'channels') { region('channels').innerHTML = channelsHtml(state); return; }
				if (state.tab === 'below' && !state.below) {
					region('below').innerHTML = belowHtml(state);
					MJ.api.get('reports/below-cost', rangeParams()).then(function (res) {
						if (state.disposed || mine !== state.seq) return;
						state.below = res;
						region('below').innerHTML = belowHtml(state);
					}).catch(function (err) { if (!state.disposed && mine === state.seq) region('below').innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar.'), 'danger'); });
					return;
				}
				if (state.tab === 'monthly' && !state.monthly) {
					region('monthly').innerHTML = monthlyHtml(state);
					MJ.api.get('reports/monthly', { months: 12 }).then(function (res) {
						if (state.disposed || mine !== state.seq) return;
						state.monthly = res.items || [];
						region('monthly').innerHTML = monthlyHtml(state);
					}).catch(function (err) { if (!state.disposed && mine === state.seq) region('monthly').innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar.'), 'danger'); });
					return;
				}
				if (state.tab === 'customers' && !state.customers) {
					region('customers').innerHTML = customersHtml(state);
					MJ.api.get('reports/customers', Object.assign(rangeParams(), { limit: 50 })).then(function (res) {
						if (state.disposed || mine !== state.seq) return;
						state.customers = res.items || [];
						region('customers').innerHTML = customersHtml(state);
					}).catch(function (err) { if (!state.disposed && mine === state.seq) region('customers').innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar.'), 'danger'); });
				}
			}

			/* Cambió el período: todo lo que se calculó se vuelve a pedir. */
			function reloadAll() {
				state.seq++;
				state.summary = null; state.products = null; state.below = null; state.customers = null;
				state.page = 1;
				loadSummary();
				if (state.tab === 'products') loadProducts();
				else loadTab();
			}

			function setRange(kind) {
				const today = new Date();
				const ymd = MJ.fmt.ymd;
				if (kind === 'last') {
					const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
					const last = new Date(today.getFullYear(), today.getMonth(), 0);
					state.from = ymd(first); state.to = ymd(last);
				} else if (kind === '30') {
					const d = new Date(today.getTime() - 29 * 86400000);
					state.from = ymd(d); state.to = ymd(today);
				} else if (kind === 'year') {
					state.from = today.getFullYear() + '-01-01'; state.to = ymd(today);
				} else {
					state.from = firstOfMonth(); state.to = ymd(today);
				}
				const f = qs('[name="date_from"]', container); if (f) f.value = state.from;
				const t = qs('[name="date_to"]', container); if (t) t.value = state.to;
				reloadAll();
			}

			function exportProducts() {
				const p = Object.assign(rangeParams(), { page: 1, per_page: 500, orderby: state.orderby, order: state.orderby === 'name' ? 'ASC' : 'DESC' });
				if (state.search) p.search = state.search;
				if (state.onlyFlagged) p.only_flagged = 1;
				MJ.api.get('reports/products', p).then(function (res) {
					const cur = baseCur();
					const rows = [['Producto', 'SKU', 'Ventas', 'Unidades', 'Precio unitario (' + cur + ')', 'Costo unitario (' + cur + ')', 'Vendido (' + cur + ')', 'Costo (' + cur + ')', 'Ganancia (' + cur + ')', 'Margen %', 'Bajo costo']];
					(res.items || []).forEach(function (r) {
						rows.push([r.name, r.sku, r.sales_count, csvNumber(r.qty), csvNumber(r.unit_price_base), csvNumber(r.unit_cost_base), csvNumber(r.revenue_base), csvNumber(r.cost_base), csvNumber(r.profit_base), csvNumber(r.margin_pct), r.below_cost ? 'Sí' : 'No']);
					});
					downloadCsv('rentabilidad-' + state.from + '-' + state.to + '.csv', rows);
				}).catch(function (err) { MJ.ui.toastError(err, 'No se pudo exportar.'); });
			}

			const searchDebounced = MJ.ui.debounce(function () { state.page = 1; loadProducts(); }, SEARCH_DELAY);

			container.addEventListener('input', function (e) {
				if (e.target.name === 'search') { state.search = String(e.target.value || '').trim(); searchDebounced(); }
			});
			container.addEventListener('change', function (e) {
				const n = e.target.name;
				if (n === 'date_from') { state.from = e.target.value; reloadAll(); }
				else if (n === 'date_to') { state.to = e.target.value; reloadAll(); }
				else if (n === 'orderby') { state.orderby = e.target.value; state.page = 1; loadProducts(); }
				else if (n === 'only_flagged') { state.onlyFlagged = !!e.target.checked; state.page = 1; loadProducts(); }
			});
			container.addEventListener('click', function (e) {
				const rangeBtn = e.target.closest ? e.target.closest('[data-range]') : null;
				if (rangeBtn) { setRange(rangeBtn.getAttribute('data-range')); return; }
				const btn = e.target.closest ? e.target.closest('[data-action]') : null;
				if (!btn) return;
				const action = btn.getAttribute('data-action');
				if (action === 'reload') reloadAll();
				else if (action === 'prev') { if (state.page > 1) { state.page--; loadProducts(); } }
				else if (action === 'next') { if (state.products && state.page < state.products.pages) { state.page++; loadProducts(); } }
				else if (action === 'export-products') exportProducts();
			});

			loadSummary();
			loadTab();

			return function () { state.disposed = true; tabs.destroy(); };
		},
	};
})();
