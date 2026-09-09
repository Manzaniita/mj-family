/**
 * MJ ERP — page-sales.js
 * MJ.pages.sales: todas las ventas, vengan del mostrador o de la tienda, con lo que falta cobrar
 * y qué parte salió de la consignación de un revendedor.
 *
 * REST: GET /sales.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PATH = '/ventas';
	const SEARCH_DELAY = 350;

	function info() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function currency() { return info().currency || MJ.config.wcCurrency || MJ.setting('local_currency', 'ARS'); }
	function baseCur() { return MJ.setting('base_currency', 'USD'); }
	function num(v) { return MJ.fmt.toNumber(v); }
	function money(v) { return MJ.fmt.money(v, currency()); }
	function moneyBase(v) { return MJ.fmt.money(v, baseCur()); }
	function dash() { return '<span class="mj-muted">—</span>'; }

	function saleLink(id) { return MJ.router.link(PATH + '/' + id); }

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	const SOURCES = {
		erp: 'ERP',
		tienda: 'Tienda',
		portal: 'Portal',
	};

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml(stats) {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Ventas</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-region="subtitle">' + h(subtitleText(stats)) + '</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<a class="mj-btn mj-btn--primary" href="' + h(MJ.router.link(PATH + '/nueva')) + '">' + MJ.icon('plus') + '<span>Nueva venta</span></a>' +
			'</div>' +
		'</div>';
	}

	function subtitleText(stats) {
		if (!stats || !stats.month) return 'Lo que vendiste por todos los canales, con lo que falta cobrar';
		const parts = [];
		const count = parseInt(stats.month.count, 10) || 0;
		parts.push(count === 1 ? '1 venta este mes' : MJ.fmt.int(count) + ' ventas este mes');
		if ((num(stats.month.total) || 0) > 0) parts.push(moneyBase(stats.month.total));
		if ((num(stats.month.profit) || 0) !== 0) parts.push('margen ' + moneyBase(stats.month.profit));
		const unpaid = parseInt(stats.unpaid_count, 10) || 0;
		if (unpaid) parts.push(unpaid === 1 ? '1 con saldo' : MJ.fmt.int(unpaid) + ' con saldo');
		return parts.join(' · ');
	}

	function filtersHtml(state) {
		const statuses = info().statuses || {};
		const payStatuses = info().payment_statuses || {};
		const channels = info().channels || [];

		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<form class="mj-form mj-inline-form mj-row mj-row--wrap" novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:2 1 240px">' +
					'<label class="mj-field__label" for="mj-sales-search">Buscar</label>' +
					'<div class="mj-input-group">' +
						'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
						'<input class="mj-input" id="mj-sales-search" type="search" placeholder="Código, cliente o pedido…" value="' + h(state.search) + '" data-filter="search">' +
					'</div>' +
				'</div>' +
				selectField('mj-sales-source', 'Origen', 'source', state.source, Object.keys(SOURCES).map(function (k) {
					return { value: k, label: SOURCES[k] };
				}), 'Todos') +
				selectField('mj-sales-channel', 'Canal', 'channel', state.channel, channels.map(function (c) {
					return { value: c.key, label: c.label };
				}), 'Todos') +
				selectField('mj-sales-pay', 'Cobro', 'payment_status', state.paymentStatus, Object.keys(payStatuses).map(function (k) {
					return { value: k, label: payStatuses[k].label };
				}), 'Todos') +
				selectField('mj-sales-status', 'Estado', 'status', state.status, Object.keys(statuses).map(function (k) {
					return { value: k, label: statuses[k].label };
				}), 'Todos') +
				'<div class="mj-field" style="flex:1 1 150px">' +
					'<label class="mj-field__label" for="mj-sales-from">Desde</label>' +
					'<input class="mj-input" id="mj-sales-from" type="date" value="' + h(state.dateFrom) + '" data-filter="date_from">' +
				'</div>' +
				'<div class="mj-field" style="flex:1 1 150px">' +
					'<label class="mj-field__label" for="mj-sales-to">Hasta</label>' +
					'<input class="mj-input" id="mj-sales-to" type="date" value="' + h(state.dateTo) + '" data-filter="date_to">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 auto">' +
					'<label class="mj-field__label">&nbsp;</label>' +
					'<label class="mj-checkbox"><input type="checkbox" data-filter="only_consignment"' + (state.onlyConsignment ? ' checked' : '') + '> <span>Solo consignación</span></label>' +
				'</div>' +
			'</form>' +
		'</div></div>';
	}

	function selectField(id, label, filter, value, options, placeholder) {
		return '<div class="mj-field" style="flex:1 1 150px">' +
			'<label class="mj-field__label" for="' + id + '">' + h(label) + '</label>' +
			'<select class="mj-select" id="' + id + '" data-filter="' + h(filter) + '">' +
				'<option value="">' + h(placeholder) + '</option>' +
				options.map(function (o) {
					return '<option value="' + h(o.value) + '"' + (String(value) === String(o.value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
				}).join('') +
			'</select>' +
		'</div>';
	}

	function totalsHtml(totals) {
		if (!totals) return '';
		return '<div class="mj-stats mj-mb">' +
			statCard('Facturado', moneyBase(totals.total_base), 'cash') +
			statCard('Margen', moneyBase(totals.profit_base), 'chart') +
			statCard('A revendedores', moneyBase(totals.consignment_base), 'users') +
			statCard('Cobrado', moneyBase(totals.paid_base), 'check-circle') +
		'</div>';
	}

	function statCard(label, value, icon) {
		return '<div class="mj-stat">' +
			'<div class="mj-stat__icon">' + MJ.icon(icon) + '</div>' +
			'<div><div class="mj-stat__label mj-small mj-muted">' + h(label) + '</div>' +
			'<div class="mj-stat__value mj-strong">' + h(value) + '</div></div>' +
		'</div>';
	}

	function tableHtml(result) {
		const rows = (result && result.items) || [];
		if (!rows.length) {
			return MJ.ui.empty({
				icon: 'cash',
				title: 'Todavía no hay ventas',
				text: 'Acá aparecen las que cargás desde el ERP y las que entran por la tienda. Si un revendedor compra algo que tenía en consignación, se le imputa a su cuenta.',
				action: { label: 'Cargar una venta', icon: 'plus', href: MJ.router.link(PATH + '/nueva') },
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Venta</th>' +
			'<th>Fecha</th>' +
			'<th>Cliente</th>' +
			'<th>Origen</th>' +
			'<th class="is-num">Total</th>' +
			'<th class="is-num">Falta cobrar</th>' +
			'<th>Cobro</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		rows.map(rowHtml).join('') +
		'</tbody></table></div>';
	}

	function rowHtml(sale) {
		const voided = sale.status === 'anulada';
		const consignment = num(sale.consignment_qty) || 0;
		const due = num(sale.due) || 0;

		return '<tr data-id="' + h(sale.id) + '"' + (voided ? ' class="is-muted"' : '') + '>' +
			'<td>' +
				'<a class="mj-link mj-strong" href="' + h(saleLink(sale.id)) + '">' + h(sale.code) + '</a>' +
				(voided ? ' ' + MJ.ui.badgeRaw('Anulada', 'danger') : '') +
				(sale.order_number ? '<div class="mj-small mj-muted">Pedido #' + h(sale.order_number) + '</div>' : '') +
			'</td>' +
			'<td>' + h(MJ.fmt.date(sale.sale_date)) + '</td>' +
			'<td>' +
				(sale.customer_name ? h(sale.customer_name) : dash()) +
				(sale.consignee_name ? '<div class="mj-small mj-muted">' + MJ.icon('users') + ' ' + h(sale.consignee_name) + '</div>' : '') +
			'</td>' +
			'<td>' +
				'<div>' + h(SOURCES[sale.source] || sale.source) + '</div>' +
				(sale.channel_label ? '<div class="mj-small mj-muted">' + h(sale.channel_label) + '</div>' : '') +
			'</td>' +
			'<td class="is-num">' +
				'<span class="mj-strong">' + h(money(sale.total)) + '</span>' +
				(consignment > 0 ? '<div class="mj-table__sub mj-small mj-muted">' + h(MJ.fmt.qty(consignment)) + ' de consignación</div>' : '') +
			'</td>' +
			'<td class="is-num">' + (due > 0.004 ? '<span class="mj-danger-text mj-strong">' + h(money(due)) + '</span>' : dash()) + '</td>' +
			'<td>' + MJ.ui.badgeRaw(sale.payment_status_label || '', sale.payment_status_color || 'neutral') + '</td>' +
			'<td class="is-actions">' +
				'<div class="mj-btn-group">' +
					'<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(saleLink(sale.id)) + '" title="Abrir" aria-label="Abrir la venta ' + h(sale.code) + '">' + MJ.icon('eye') + '</a>' +
					(due > 0.004 && !voided
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="charge" title="Registrar cobro" aria-label="Registrar cobro de ' + h(sale.code) + '">' + MJ.icon('cash') + '</button>'
						: '') +
					(sale.order_edit_link
						? '<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(sale.order_edit_link) + '" target="_blank" rel="noopener" title="Ver el pedido en WooCommerce" aria-label="Ver el pedido en WooCommerce">' + MJ.icon('external') + '</a>'
						: '') +
				'</div>' +
			'</td>' +
		'</tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.sales = {
		title() { return 'Ventas'; },

		render(container, params, query) {
			const offs = [];
			const state = {
				search: query && query.q ? String(query.q) : '',
				source: '',
				channel: '',
				status: '',
				paymentStatus: query && query.cobro ? String(query.cobro) : '',
				dateFrom: '',
				dateTo: '',
				onlyConsignment: !!(query && query.consignacion),
				page: 1,
				result: null,
				error: null,
				seq: 0,
				disposed: false,
			};

			container.innerHTML =
				headerHtml(info().stats) +
				'<div data-region="totals"></div>' +
				'<div data-region="filters"></div>' +
				'<div data-region="list">' + loadingHtml() + '</div>' +
				'<div data-region="pagination"></div>';

			function region(name) { return qs('[data-region="' + name + '"]', container); }

			function paintFilters() {
				const el = region('filters');
				if (el) el.innerHTML = filtersHtml(state);
			}

			function paintList() {
				const el = region('list');
				if (!el) return;
				if (state.error) {
					el.innerHTML = MJ.ui.alert(state.error, 'danger');
					return;
				}
				el.innerHTML = state.result === null ? loadingHtml() : tableHtml(state.result);

				const totals = region('totals');
				if (totals) totals.innerHTML = state.result ? totalsHtml(state.result.totals) : '';

				const pagination = region('pagination');
				if (pagination) {
					pagination.innerHTML = state.result
						? MJ.components.pagination({ page: state.result.page, pages: state.result.pages, total: state.result.total })
						: '';
				}
			}

			function load() {
				const seq = ++state.seq;
				state.error = null;
				paintList();

				MJ.api.get('sales', {
					search: state.search || undefined,
					source: state.source || undefined,
					channel: state.channel || undefined,
					status: state.status || undefined,
					payment_status: state.paymentStatus || undefined,
					date_from: state.dateFrom || undefined,
					date_to: state.dateTo || undefined,
					only_consignment: state.onlyConsignment ? 1 : undefined,
					page: state.page,
					per_page: 20,
				}).then(function (res) {
					if (state.disposed || seq !== state.seq) return;
					state.result = res && typeof res === 'object' ? res : { items: [] };
					paintList();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = MJ.api.message(err, 'No se pudieron cargar las ventas.');
					paintList();
				});
			}

			paintFilters();
			load();

			const debouncedLoad = MJ.ui.debounce(function () {
				state.page = 1;
				load();
			}, SEARCH_DELAY);

			offs.push(MJ.ui.on(container, 'input', '[data-filter="search"]', function (e, el) {
				state.search = String(el.value || '').trim();
				debouncedLoad();
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-filter]', function (e, el) {
				const key = el.getAttribute('data-filter');
				if (key === 'search') return;
				if (key === 'only_consignment') state.onlyConsignment = !!el.checked;
				else if (key === 'source') state.source = el.value;
				else if (key === 'channel') state.channel = el.value;
				else if (key === 'status') state.status = el.value;
				else if (key === 'payment_status') state.paymentStatus = el.value;
				else if (key === 'date_from') state.dateFrom = el.value;
				else if (key === 'date_to') state.dateTo = el.value;
				state.page = 1;
				load();
			}));

			offs.push(MJ.components.bindPagination(container, function (page) {
				state.page = page;
				load();
				MJ.ui.scrollTop();
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="charge"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-id'), 10) : 0;
				const sale = ((state.result && state.result.items) || []).find(function (s) { return parseInt(s.id, 10) === id; });
				if (!sale) return;
				MJ.components.salePaymentModal({
					sale: sale,
					onSaved() {
						MJ.events.emit('sales:changed', {});
						load();
					},
				});
			}));

			offs.push(MJ.events.on('sales:changed', function () {
				if (!state.disposed) load();
			}));

			return function cleanup() {
				state.disposed = true;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
