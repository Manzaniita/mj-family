/**
 * MJ ERP — page-sale.js
 * MJ.pages.sale: el detalle de una venta — qué salió del depósito, qué salió de la consignación de
 * un revendedor, qué se cobró y qué falta.
 *
 * REST: GET /sales/{id}, PUT /sales/{id}, POST /sales/{id}/void, POST /sales/{id}/order,
 *       POST /sales/{id}/payments, DELETE /sale-payments/{id}.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PATH = '/ventas';

	function info() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function baseCur() { return MJ.setting('base_currency', 'USD'); }
	function num(v) { return MJ.fmt.toNumber(v); }
	function dash() { return '<span class="mj-muted">—</span>'; }

	function money(v, sale) {
		return MJ.fmt.money(v, (sale && sale.currency) || info().currency || MJ.setting('local_currency', 'ARS'));
	}
	function moneyBase(v) { return MJ.fmt.money(v, baseCur()); }

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	const SOURCES = { erp: 'Cargada en el ERP', tienda: 'Comprada en la tienda', portal: 'Cargada por el revendedor' };

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml(sale) {
		const voided = sale.status === 'anulada';
		return '<div class="mj-page-header">' +
			'<div>' +
				'<div class="mj-breadcrumb mj-small"><a class="mj-link" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + ' Ventas</a></div>' +
				'<h2 class="mj-page-header__title">' + h(sale.code) +
					' ' + MJ.ui.badgeRaw(sale.status_label || '', sale.status_color || 'neutral') +
					' ' + MJ.ui.badgeRaw(sale.payment_status_label || '', sale.payment_status_color || 'neutral') +
				'</h2>' +
				'<div class="mj-page-header__subtitle mj-muted">' +
					h(MJ.fmt.date(sale.sale_date)) + ' · ' + h(SOURCES[sale.source] || sale.source) +
					(sale.channel_label ? ' · ' + h(sale.channel_label) : '') +
				'</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				(sale.order_edit_link
					? '<a class="mj-btn mj-btn--ghost" href="' + h(sale.order_edit_link) + '" target="_blank" rel="noopener">' + MJ.icon('external') + '<span>Pedido #' + h(sale.order_number || sale.order_id) + '</span></a>'
					: (voided ? '' : '<button type="button" class="mj-btn mj-btn--ghost" data-action="create-order">' + MJ.icon('store') + '<span>Emitir pedido</span></button>')) +
				((num(sale.due) || 0) > 0.004 && !voided
					? '<button type="button" class="mj-btn mj-btn--primary" data-action="charge">' + MJ.icon('cash') + '<span>Registrar cobro</span></button>'
					: '') +
				(voided ? '' : '<button type="button" class="mj-btn mj-btn--ghost mj-danger-text" data-action="void">' + MJ.icon('x-circle') + '<span>Anular</span></button>') +
			'</div>' +
		'</div>';
	}

	function voidedNoticeHtml(sale) {
		if (sale.status !== 'anulada') return '';
		return MJ.ui.alert(
			'Esta venta está anulada' + (sale.voided_at ? ' desde el ' + MJ.fmt.datetime(sale.voided_at) : '') +
			'. Se deshizo la deuda del revendedor y, si no tenía pedido en WooCommerce, se repuso el stock.',
			'danger'
		);
	}

	function customerHtml(sale) {
		const rows = [];
		if (sale.customer_name) rows.push(['Cliente', h(sale.customer_name)]);
		if (sale.customer_email) rows.push(['Email', h(sale.customer_email)]);
		if (sale.customer_phone) rows.push(['Teléfono', h(sale.customer_phone)]);
		if (sale.consignee_name) {
			rows.push(['Revendedor', '<a class="mj-link" href="' + h(MJ.router.link('/consignaciones/revendedor/' + sale.consignee_id)) + '">' + h(sale.consignee_name) + '</a>']);
		}
		if (sale.created_by_name) rows.push(['Cargada por', h(sale.created_by_name)]);

		if (!rows.length) rows.push(['Cliente', 'Consumidor final']);

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Cliente</h3></div>' +
			'<div class="mj-card__body"><dl class="mj-deflist">' +
				rows.map(function (r) {
					return '<div class="mj-deflist__row"><dt>' + h(r[0]) + '</dt><dd>' + r[1] + '</dd></div>';
				}).join('') +
			'</dl></div></div>';
	}

	function itemsHtml(sale) {
		const items = sale.items || [];
		if (!items.length) {
			return '<div class="mj-card"><div class="mj-card__body mj-muted">Esta venta no tiene productos.</div></div>';
		}

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Productos</h3></div>' +
			'<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
				'<th>Producto</th>' +
				'<th>Sale de</th>' +
				'<th class="is-num">Cantidad</th>' +
				'<th class="is-num">Precio</th>' +
				'<th class="is-num">Subtotal</th>' +
				'<th class="is-num">Costo</th>' +
			'</tr></thead><tbody>' +
			items.map(function (item) { return itemRowHtml(item, sale); }).join('') +
			'</tbody></table></div></div>';
	}

	function itemRowHtml(item, sale) {
		const isConsignment = item.origin === 'consignacion';
		return '<tr>' +
			'<td>' +
				'<div class="mj-strong">' + h(item.name) + '</div>' +
				(item.sku ? '<div class="mj-small mj-muted">SKU ' + h(item.sku) + '</div>' : '') +
			'</td>' +
			'<td>' +
				MJ.ui.badgeRaw(item.origin_label || '', isConsignment ? 'info' : 'neutral') +
				(isConsignment
					? '<div class="mj-small mj-muted">Le debe ' + h(moneyBase(item.consignment_total_base)) + '</div>'
					: '') +
			'</td>' +
			'<td class="is-num">' + h(MJ.fmt.qty(item.qty)) + '</td>' +
			'<td class="is-num">' + h(money(item.unit_price, sale)) + '</td>' +
			'<td class="is-num mj-strong">' + h(money(item.line_total, sale)) + '</td>' +
			'<td class="is-num">' + ((num(item.cost_base) || 0) > 0 ? h(moneyBase(item.cost_base)) : dash()) + '</td>' +
		'</tr>';
	}

	function totalsHtml(sale) {
		const consignment = num(sale.consignment_total) || 0;
		const rows = [
			['Subtotal', money(sale.subtotal, sale)],
		];
		if ((num(sale.discount_total) || 0) > 0) rows.push(['Descuento', '− ' + money(sale.discount_total, sale)]);
		if ((num(sale.shipping_total) || 0) > 0) rows.push(['Envío', money(sale.shipping_total, sale)]);

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Totales</h3></div>' +
			'<div class="mj-card__body"><dl class="mj-totals">' +
				rows.map(function (r) {
					return '<div class="mj-totals__row"><dt>' + h(r[0]) + '</dt><dd>' + h(r[1]) + '</dd></div>';
				}).join('') +
				'<div class="mj-totals__row mj-totals__row--total"><dt>Total</dt><dd>' + h(money(sale.total, sale)) + '</dd></div>' +
				(consignment > 0
					? '<div class="mj-totals__row"><dt>A la cuenta del revendedor</dt><dd>' + h(money(consignment, sale)) + '</dd></div>'
					: '') +
				'<div class="mj-totals__row"><dt>Cobrado</dt><dd>' + h(money(sale.paid_total, sale)) + '</dd></div>' +
				'<div class="mj-totals__row' + ((num(sale.due) || 0) > 0.004 ? ' mj-totals__row--danger' : '') + '"><dt>Falta cobrar</dt><dd>' + h(money(sale.due, sale)) + '</dd></div>' +
			'</dl>' +
			'<hr class="mj-hr">' +
			'<dl class="mj-totals mj-small">' +
				'<div class="mj-totals__row"><dt>Costo</dt><dd>' + h(moneyBase(sale.cost_base)) + '</dd></div>' +
				'<div class="mj-totals__row"><dt>Margen</dt><dd class="' + ((num(sale.profit_base) || 0) >= 0 ? 'mj-success-text' : 'mj-danger-text') + '">' + h(moneyBase(sale.profit_base)) + '</dd></div>' +
			'</dl>' +
			'</div></div>';
	}

	function paymentsHtml(sale) {
		const payments = sale.payments || [];
		const voided = sale.status === 'anulada';

		return '<div class="mj-card"><div class="mj-card__header">' +
				'<h3 class="mj-card__title">Cobros</h3>' +
				((num(sale.due) || 0) > 0.004 && !voided
					? '<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="charge">' + MJ.icon('plus') + '<span>Cobro</span></button>'
					: '') +
			'</div>' +
			(payments.length
				? '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
						'<th>Fecha</th><th>Medio</th><th class="is-num">Importe</th><th>Estado</th>' +
						'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
					'</tr></thead><tbody>' +
					payments.map(function (p) {
						return '<tr data-payment="' + h(p.id) + '">' +
							'<td>' + h(MJ.fmt.date(p.payment_date)) + '</td>' +
							'<td>' + h(p.method_label || '') + (p.reference ? '<div class="mj-small mj-muted">' + h(p.reference) + '</div>' : '') + '</td>' +
							'<td class="is-num mj-strong">' + h(MJ.fmt.money(p.amount, p.currency)) + '</td>' +
							'<td>' + (p.is_declared ? MJ.ui.badgeRaw(p.status_label, 'warning') : MJ.ui.badgeRaw(p.status_label, 'success')) + '</td>' +
							'<td class="is-actions"><div class="mj-btn-group">' +
								(p.is_declared ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="confirm-payment" title="Confirmar" aria-label="Confirmar cobro">' + MJ.icon('check') + '</button>' : '') +
								'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-payment" title="Borrar" aria-label="Borrar cobro">' + MJ.icon('trash') + '</button>' +
							'</div></td>' +
						'</tr>';
					}).join('') +
					'</tbody></table></div>'
				: '<div class="mj-card__body mj-muted">Todavía no registraste cobros de esta venta.</div>') +
		'</div>';
	}

	function detailsHtml(sale) {
		const channels = (info().channels || []).map(function (c) { return { value: c.key, label: c.label }; });
		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Datos</h3></div>' +
			'<div class="mj-card__body">' +
				'<div class="mj-grid mj-grid--2">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sale-edit-channel">Canal</label>' +
						'<select class="mj-select" id="mj-sale-edit-channel" data-edit="channel">' +
							channels.map(function (c) {
								return '<option value="' + h(c.value) + '"' + (sale.channel === c.value ? ' selected' : '') + '>' + h(c.label) + '</option>';
							}).join('') +
						'</select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sale-edit-date">Fecha</label>' +
						'<input class="mj-input" id="mj-sale-edit-date" type="date" data-edit="sale_date" value="' + h(sale.sale_date || '') + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-edit-note">Nota</label>' +
					'<textarea class="mj-textarea" id="mj-sale-edit-note" rows="3" data-edit="note">' + h(sale.note || '') + '</textarea>' +
				'</div>' +
				'<div class="mj-row mj-row--end mj-mt">' +
					'<button type="button" class="mj-btn mj-btn--ghost" data-action="save">' + MJ.icon('save') + '<span>Guardar</span></button>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.sale = {
		title(params) { return 'Venta #' + (params && params.id ? params.id : ''); },

		render(container, params) {
			const offs = [];
			const state = { id: parseInt(params && params.id, 10) || 0, sale: null, disposed: false };

			container.innerHTML = loadingHtml('Cargando la venta…');

			function paint() {
				const sale = state.sale;
				if (!sale) return;

				container.innerHTML =
					headerHtml(sale) +
					voidedNoticeHtml(sale) +
					(MJ.components.consignmentNotice ? MJ.components.consignmentNotice(sale) : '') +
					'<div class="mj-grid mj-grid--detail">' +
						'<div class="mj-grid__main">' +
							itemsHtml(sale) +
							'<div class="mj-mt">' + paymentsHtml(sale) + '</div>' +
						'</div>' +
						'<div class="mj-grid__side">' +
							totalsHtml(sale) +
							'<div class="mj-mt">' + customerHtml(sale) + '</div>' +
							'<div class="mj-mt">' + detailsHtml(sale) + '</div>' +
						'</div>' +
					'</div>';
			}

			function load() {
				return MJ.api.get('sales/' + state.id).then(function (sale) {
					if (state.disposed) return;
					state.sale = sale;
					paint();
				}).catch(function (err) {
					if (state.disposed) return;
					if (err && (err.handled || err.status === 401)) return;
					container.innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar la venta.'), 'danger') +
						'<div class="mj-row mj-mt"><a class="mj-btn" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + '<span>Volver a Ventas</span></a></div>';
				});
			}

			load();

			offs.push(MJ.ui.on(container, 'click', '[data-action="charge"]', function () {
				if (!state.sale) return;
				MJ.components.salePaymentModal({
					sale: state.sale,
					onSaved(updated) {
						state.sale = updated;
						paint();
						MJ.events.emit('sales:changed', {});
					},
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="confirm-payment"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				if (!id) return;
				MJ.api.post('sale-payments/' + id + '/confirm').then(function (updated) {
					if (state.disposed) return;
					state.sale = updated;
					paint();
					MJ.ui.toast('Cobro confirmado.', 'success');
					MJ.events.emit('sales:changed', {});
				}).catch(function (err) { toastError(err, 'No se pudo confirmar el cobro.'); });
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="delete-payment"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				if (!id) return;
				MJ.ui.confirm({
					title: 'Borrar cobro',
					message: 'Se borra el cobro y la venta vuelve a quedar con saldo.',
					okLabel: 'Borrar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.del('sale-payments/' + id).then(function (updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
						MJ.events.emit('sales:changed', {});
					}).catch(function (err) { toastError(err, 'No se pudo borrar el cobro.'); });
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="save"]', function (e, btn) {
				const data = {
					channel: qs('[data-edit="channel"]', container).value,
					sale_date: qs('[data-edit="sale_date"]', container).value,
					note: qs('[data-edit="note"]', container).value,
				};
				MJ.ui.setLoading(btn, true);
				MJ.api.put('sales/' + state.id, data).then(function (updated) {
					if (state.disposed) return;
					state.sale = updated;
					paint();
					MJ.ui.toast('Venta actualizada.', 'success');
				}).catch(function (err) {
					MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo guardar la venta.');
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="create-order"]', function (e, btn) {
				MJ.ui.setLoading(btn, true);
				MJ.api.post('sales/' + state.id + '/order').then(function (updated) {
					if (state.disposed) return;
					state.sale = updated;
					paint();
					MJ.ui.toast('Pedido emitido en WooCommerce.', 'success');
				}).catch(function (err) {
					MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo emitir el pedido.');
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="void"]', function () {
				const sale = state.sale;
				if (!sale) return;
				const hasConsignment = (num(sale.consignment_qty) || 0) > 0;
				MJ.ui.confirm({
					title: 'Anular venta',
					message: 'Se anula la venta' +
						(hasConsignment ? ', se le saca la deuda al revendedor y le vuelve el stock a su consignación' : '') +
						(sale.order_id ? ' y se cancela el pedido en WooCommerce.' : '.'),
					okLabel: 'Anular',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.post('sales/' + state.id + '/void').then(function (updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
						MJ.ui.toast('Venta anulada.', 'success');
						MJ.events.emit('sales:changed', {});
					}).catch(function (err) { toastError(err, 'No se pudo anular la venta.'); });
				});
			}));

			return function cleanup() {
				state.disposed = true;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
