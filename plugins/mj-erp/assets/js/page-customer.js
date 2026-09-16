/**
 * MJ ERP — page-customer.js
 * MJ.pages.customer: la ficha de un cliente — sus datos, su cuenta en la tienda, lo que compró y su
 * saldo a favor, con cada movimiento.
 *
 * REST: GET /customers/{id}, GET /customers/{id}/credit, GET /customers/{id}/sales,
 *       DELETE /customers/{id}/link.
 * Los movimientos a mano del saldo: MJ.components.creditModal y creditPayoutModal.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const C = function () { return MJ.components || {}; };

	const PATH = '/clientes';

	function num(v) { return MJ.fmt.toNumber(v); }
	function dash() { return '<span class="mj-muted">—</span>'; }
	function localCur() { return MJ.setting('local_currency', 'ARS'); }
	function creditOn() { return !!(C().creditEnabled && C().creditEnabled()); }

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml(customer) {
		const sub = [];
		if (customer.company) sub.push(customer.company);
		if (customer.doc_number) {
			const label = C().customerDocTypeLabel ? C().customerDocTypeLabel(customer.doc_type) : '';
			sub.push((label ? label + ' ' : '') + customer.doc_number);
		}

		return '<div class="mj-page-header">' +
			'<div>' +
				'<div class="mj-breadcrumb mj-small"><a class="mj-link" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + ' Clientes</a></div>' +
				'<h2 class="mj-page-header__title">' + h(customer.name) +
					(customer.is_linked ? ' ' + MJ.ui.badgeRaw('Con cuenta', 'info') : '') +
					(customer.is_active === false ? ' ' + MJ.ui.badgeRaw('Archivado', 'neutral') : '') +
				'</h2>' +
				(sub.length ? '<div class="mj-page-header__subtitle mj-muted">' + h(sub.join(' · ')) + '</div>' : '') +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost" data-action="edit">' + MJ.icon('edit') + '<span>Editar</span></button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="sale">' + MJ.icon('cash') + '<span>Nueva venta</span></button>' +
			'</div>' +
		'</div>';
	}

	function creditHtml(state) {
		if (!creditOn()) return '';
		const credit = state.credit;
		const label = C().creditLabel ? C().creditLabel() : 'Saldo a favor';

		if (!credit) {
			return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">' + h(label) + '</h3></div>' +
				'<div class="mj-card__body">' + loadingHtml('Cargando el saldo…') + '</div></div>';
		}

		const balances = C().creditBalancesList(credit.balances);
		const positive = balances.some(function (b) { return b.amount > 0.004; });
		const shop = credit.currency || localCur();

		const amounts = balances.length
			? '<div class="mj-credit-balances">' + balances.map(function (b) {
				const other = b.currency !== shop && b.equivalent !== null && b.equivalent !== undefined;
				return '<div class="mj-credit-balance">' +
					'<span class="mj-credit-balance__amount ' + (b.amount < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + h(MJ.fmt.money(b.amount, b.currency)) + '</span>' +
					(other ? '<span class="mj-small mj-muted">≈ ' + h(MJ.fmt.money(b.equivalent, shop)) + ' al dólar de hoy</span>' : '') +
				'</div>';
			}).join('') + '</div>'
			: '<div class="mj-muted">No tiene saldo a favor.</div>';

		return '<div class="mj-card">' +
			'<div class="mj-card__header">' +
				'<h3 class="mj-card__title">' + h(label) + '</h3>' +
				(credit.can_manage
					? '<div class="mj-btn-group">' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="credit-adjust">' + MJ.icon('edit') + '<span>Editar saldo</span></button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="credit-payout"' + (positive ? '' : ' disabled') + '>' + MJ.icon('cash') + '<span>Le di la plata</span></button>' +
					'</div>'
					: '') +
			'</div>' +
			'<div class="mj-card__body">' +
				amounts +
				'<div class="mj-small mj-muted mj-mt">Queda en la moneda en que pagó y no vence. Se usa como medio de pago al cobrar una venta' +
					(MJ.setting('credit_shop_enabled', true) !== false ? ' o en la tienda' : '') + '.</div>' +
			'</div>' +
			movementsHtml(credit) +
		'</div>';
	}

	function movementsHtml(credit) {
		const rows = credit.movements || [];
		if (!rows.length) {
			return '<div class="mj-card__body mj-muted mj-small">Todavía no tuvo movimientos. El saldo nace de una devolución o de un ajuste.</div>';
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Fecha</th><th>Detalle</th><th class="is-num">Importe</th><th class="is-num">Saldo</th>' +
		'</tr></thead><tbody>' +
		rows.map(function (row) {
			const amount = num(row.amount) || 0;
			const sale = row.sale_id
				? ' · <a class="mj-link" href="' + h(MJ.router.link('/ventas/' + row.sale_id)) + '">' + h(row.sale_code || ('Venta #' + row.sale_id)) + '</a>'
				: '';
			const meta = [];
			if (row.created_by_name) meta.push(row.created_by_name);
			if (row.reference) meta.push('Ref. ' + row.reference);
			if (row.note) meta.push(row.note);
			if (row.kind === 'reintegro' && row.reason) meta.push(row.reason);

			return '<tr>' +
				'<td>' + h(MJ.fmt.datetime(row.created_at)) + '</td>' +
				'<td>' +
					'<div>' + h(row.description || row.kind_label || '') + sale + '</div>' +
					(meta.length ? '<div class="mj-small mj-muted">' + h(meta.join(' · ')) + '</div>' : '') +
				'</td>' +
				'<td class="is-num"><span class="' + (amount < 0 ? 'mj-danger-text' : 'mj-success-text') + '">' + (amount > 0 ? '+ ' : '− ') + h(MJ.fmt.money(Math.abs(amount), row.currency)) + '</span></td>' +
				'<td class="is-num">' + h(MJ.fmt.money(row.balance, row.currency)) + '</td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	function dataHtml(customer) {
		const rows = [];
		if (customer.phone) rows.push(['Teléfono', h(customer.phone)]);
		if (customer.email) rows.push(['Email', '<a class="mj-link" href="' + h('mailto:' + customer.email) + '">' + h(customer.email) + '</a>']);
		const address = [customer.address, customer.city, customer.province, customer.postcode].filter(Boolean).join(', ');
		if (address) rows.push(['Dirección', h(address)]);
		if (customer.notes) rows.push(['Notas', h(customer.notes)]);
		if (!rows.length) rows.push(['Datos', dash()]);

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Datos</h3></div>' +
			'<div class="mj-card__body"><dl class="mj-deflist">' +
				rows.map(function (r) { return '<div class="mj-deflist__row"><dt>' + h(r[0]) + '</dt><dd>' + r[1] + '</dd></div>'; }).join('') +
			'</dl></div></div>';
	}

	function accountHtml(customer) {
		const account = customer.account;
		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Cuenta en la tienda</h3>' +
				(customer.is_linked
					? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="unlink">' + MJ.icon('unlink') + '<span>Desvincular</span></button>'
					: '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="link">' + MJ.icon('link') + '<span>Vincular</span></button>') +
			'</div>' +
			'<div class="mj-card__body">' +
				(customer.is_linked && account
					? '<div class="mj-strong">' + h(account.name || account.email) + '</div><div class="mj-small mj-muted">' + h(account.email || '') + '</div>'
					: '<div class="mj-muted mj-small">Sin cuenta. Si se crea una en la tienda con el mismo email o teléfono, aparece en «Posibles vinculaciones» de la lista de clientes.</div>') +
			'</div></div>';
	}

	function statsHtml(customer) {
		const stats = customer.stats || {};
		const due = num(stats.due) || 0;
		const rows = [
			['Ventas', MJ.fmt.int(stats.sales_count || 0)],
			['Total comprado', h(MJ.fmt.money(stats.total || 0, localCur()))],
			['Debe', due > 0 ? '<span class="mj-danger-text">' + h(MJ.fmt.money(due, localCur())) + '</span>' : dash()],
		];
		if (stats.last_sale_date) rows.push(['Última compra', h(MJ.fmt.date(stats.last_sale_date))]);

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Compras</h3></div>' +
			'<div class="mj-card__body"><dl class="mj-deflist">' +
				rows.map(function (r) { return '<div class="mj-deflist__row"><dt>' + h(r[0]) + '</dt><dd>' + r[1] + '</dd></div>'; }).join('') +
			'</dl></div></div>';
	}

	function salesHtml(state) {
		const res = state.sales;
		const header = '<div class="mj-card__header"><h3 class="mj-card__title">Ventas</h3></div>';
		if (!res) return '<div class="mj-card">' + header + '<div class="mj-card__body">' + loadingHtml('Cargando ventas…') + '</div></div>';

		const items = (res && res.items) || [];
		if (!items.length) return '<div class="mj-card">' + header + '<div class="mj-card__body mj-muted">Todavía no tiene ventas.</div></div>';

		return '<div class="mj-card">' + header +
			'<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
				'<th>Venta</th><th>Fecha</th><th class="is-num">Total</th><th>Cobro</th>' +
			'</tr></thead><tbody>' +
			items.map(function (sale) {
				return '<tr class="' + (sale.status === 'anulada' ? 'is-muted' : '') + '">' +
					'<td><a class="mj-link mj-strong" href="' + h(MJ.router.link('/ventas/' + sale.id)) + '">' + h(sale.code) + '</a>' +
						(sale.status === 'anulada' ? ' ' + MJ.ui.badgeRaw(sale.status_label || 'Anulada', 'danger') : '') + '</td>' +
					'<td>' + h(MJ.fmt.date(sale.sale_date)) + '</td>' +
					'<td class="is-num">' + h(MJ.fmt.money(sale.total, sale.currency)) + '</td>' +
					'<td>' + MJ.ui.badgeRaw(sale.payment_status_label || '', sale.payment_status_color || 'neutral') + '</td>' +
				'</tr>';
			}).join('') +
			'</tbody></table></div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.customer = {
		title() { return 'Cliente'; },

		render(container, params) {
			const offs = [];
			const state = {
				id: parseInt(params && params.id, 10) || 0,
				customer: null,
				credit: null,
				sales: null,
				disposed: false,
			};

			container.innerHTML = loadingHtml('Cargando el cliente…');

			function paint() {
				const customer = state.customer;
				if (!customer) return;
				container.innerHTML =
					headerHtml(customer) +
					'<div class="mj-grid mj-grid--detail">' +
						'<div class="mj-grid__main">' +
							(creditOn() ? creditHtml(state) + '<div class="mj-mt"></div>' : '') +
							salesHtml(state) +
						'</div>' +
						'<div class="mj-grid__side">' +
							statsHtml(customer) +
							'<div class="mj-mt">' + dataHtml(customer) + '</div>' +
							'<div class="mj-mt">' + accountHtml(customer) + '</div>' +
						'</div>' +
					'</div>';
			}

			function loadCustomer() {
				return MJ.api.get('customers/' + state.id).then(function (customer) {
					if (state.disposed) return;
					state.customer = customer;
					paint();
				});
			}

			function loadCredit() {
				if (!creditOn()) return Promise.resolve();
				return MJ.api.get('customers/' + state.id + '/credit').then(function (credit) {
					if (state.disposed) return;
					state.credit = credit;
					paint();
				}).catch(function (err) { toastError(err, 'No se pudo cargar el saldo a favor.'); });
			}

			function loadSales() {
				return MJ.api.get('customers/' + state.id + '/sales', { per_page: 30 }).then(function (res) {
					if (state.disposed) return;
					state.sales = (res && res.sales) || { items: [] };
					paint();
				}).catch(function (err) {
					if (state.disposed) return;
					state.sales = { items: [] };
					paint();
					toastError(err, 'No se pudieron cargar las ventas.');
				});
			}

			loadCustomer().then(function () {
				loadCredit();
				loadSales();
			}).catch(function (err) {
				if (state.disposed) return;
				if (err && (err.handled || err.status === 401)) return;
				container.innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar el cliente.'), 'danger') +
					'<div class="mj-row mj-mt"><a class="mj-btn" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + '<span>Volver a Clientes</span></a></div>';
			});

			offs.push(MJ.ui.on(container, 'click', '[data-action="sale"]', function () {
				MJ.router.navigate('/ventas/nueva?cliente=' + encodeURIComponent(state.id));
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit"]', function () {
				if (!state.customer) return;
				C().customerModal({
					customer: state.customer,
					onSaved() { if (!state.disposed) loadCustomer(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="link"]', function () {
				if (!state.customer) return;
				C().customerLinkModal({
					customer: state.customer,
					onLinked() { if (!state.disposed) { loadCustomer(); loadSales(); } },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="unlink"]', function () {
				const customer = state.customer;
				if (!customer) return;
				MJ.ui.confirm({
					title: 'Desvincular la cuenta',
					message: 'El cliente «' + customer.name + '» deja de estar asociado a su cuenta de la tienda. La ficha, sus ventas y su saldo quedan como están.',
					okLabel: 'Desvincular',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.del('customers/' + state.id + '/link').then(function (updated) {
						if (state.disposed) return;
						state.customer = updated;
						paint();
						MJ.ui.toast('Cliente desvinculado.', 'success');
					}).catch(function (err) { toastError(err, 'No se pudo desvincular el cliente.'); });
				});
			}));

			function onCredit(statement) {
				if (state.disposed) return;
				state.credit = statement;
				paint();
			}

			offs.push(MJ.ui.on(container, 'click', '[data-action="credit-adjust"]', function () {
				if (!state.customer) return;
				// Usar el saldo para pagar una venta mueve lo que debe y sus ventas: se recarga todo.
				C().creditModal({
					customer: state.customer,
					onSaved() {
						if (state.disposed) return;
						loadCredit();
						loadCustomer();
						loadSales();
					},
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="credit-payout"]', function () {
				if (!state.customer) return;
				C().creditPayoutModal({ customer: state.customer, balances: state.credit && state.credit.balances, onSaved: onCredit });
			}));

			return function cleanup() {
				state.disposed = true;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
