/**
 * MJ ERP — page-sale.js
 * MJ.pages.sale: el detalle de una venta — qué salió del depósito, qué salió de la consignación de
 * un revendedor, qué se cobró y qué falta.
 *
 * REST: GET /sales/{id}, PUT /sales/{id}, POST /sales/{id}/void, POST /sales/{id}/order,
 *       POST /sales/{id}/payments, POST /sales/{id}/delivery, PUT|DELETE /sale-payments/{id},
 *       GET /sales/{id}/log. Editar productos: MJ.components.saleItemsModal (components-sale-edit.js).
 *       Devolución o cambio y anular una venta cobrada: components-returns.js.
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

	// Los pesos al dólar de hoy, para tener una idea: no son los que se cobraron ese día.
	function localToday(v) { const l = MJ.fmt.toLocal(v); return l === null ? '' : MJ.fmt.moneyLocal(l, 0); }
	function moneyBoth(v) { const l = localToday(v); return moneyBase(v) + (l ? ' (' + l + ')' : ''); }
	function moneyPairHtml(v) {
		const l = localToday(v);
		return '<span class="mj-money-pair">' +
			'<span class="mj-money-pair__base">' + h(moneyBase(v)) + '</span>' +
			(l ? '<span class="mj-money-pair__local">' + h(l) + '</span>' : '') +
		'</span>';
	}

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

	/**
	 * Aviso de la venta que todavía no compromete nada.
	 *
	 * Sin esto, una venta anotada y sin cobrar parece igual a una cerrada, y la diferencia —que el
	 * stock sigue disponible para quien lo compre primero— recién se descubre mirando el producto.
	 */
	function commitNoticeHtml(sale) {
		const helpers = MJ.components.saleHelpers;
		if (sale.status === 'anulada' || sale.order_id || sale.stock_applied) return '';
		if (helpers.saleIsCommitted(sale)) return '';

		const falta = helpers.commitPendingText();
		return MJ.ui.alert(
			'Esta venta todavía no bajó stock' + (sale.wants_order === false ? '' : ' ni emitió el pedido en WooCommerce') +
			'. El producto se sigue pudiendo vender hasta que alguien lo pague o se lo lleve' +
			(falta ? ': pasa ' + falta + '.' : '.'),
			'warning'
		);
	}

	function customerHtml(sale) {
		const rows = [];
		// Con ficha, el nombre lleva a su historial: es la pregunta que sigue a «¿quién compró esto?».
		if (sale.customer_name || sale.customer_id) {
			const label = h(sale.customer_name || ('Cliente #' + sale.customer_id));
			rows.push(['Cliente', sale.customer_id
				? '<a class="mj-link" href="' + h(MJ.router.link('/clientes/' + sale.customer_id)) + '">' + label + '</a>'
				: label]);
		}
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

	/**
	 * «Editar productos» y, si ya se cobró, «Devolución o cambio»: solo para quien corrige ventas.
	 * Editar corrige lo que se cargó mal; la devolución registra lo que el cliente trajo, con su plata.
	 * Si no se puede ninguna, se dice por qué en vez de esconder el botón sin explicación.
	 */
	function editItemsHtml(sale) {
		const edit = sale.edit || {};
		const ret = sale.return_state || {};
		if (!(edit.can_manage || ret.can_manage) || sale.status === 'anulada') return '';
		const buttons = [];
		if (edit.can_edit && MJ.components.saleItemsModal) {
			buttons.push('<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="edit-items">' + MJ.icon('edit') + '<span>Editar productos</span></button>');
		}
		if (ret.can_return && MJ.components.saleReturnModal) {
			buttons.push('<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="return">' + MJ.icon('undo') + '<span>Devolución o cambio</span></button>');
		}
		if (buttons.length) return '<div class="mj-btn-group">' + buttons.join('') + '</div>';
		const reason = (num(sale.paid_total) || 0) > 0.004 ? (ret.reason || edit.reason) : (edit.reason || ret.reason);
		return reason
			? '<span class="mj-small mj-muted" title="' + h(reason) + '">' + MJ.icon('info') + ' No se puede cambiar</span>'
			: '';
	}

	function itemsHtml(sale) {
		const items = sale.items || [];
		if (!items.length) {
			return '<div class="mj-card"><div class="mj-card__body mj-muted">Esta venta no tiene productos.</div></div>';
		}

		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Productos</h3>' + editItemsHtml(sale) + '</div>' +
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

	/** ¿La entrega va por partes? Hay algo entregado y algo que falta. */
	function deliverySplit(sale) {
		const items = sale.items || [];
		return MJ.components.saleHelpers.deliveryEnabled() && sale.status !== 'anulada' &&
			items.some(function (item) { return (num(item.delivered_qty) || 0) > 0.00005; }) &&
			items.some(function (item) { return (num(item.pending_qty) || 0) > 0.00005; });
	}

	/** Debajo del nombre, en una entrega por partes: qué de este producto ya se llevó. */
	function itemDeliveryHtml(item, sale) {
		if (!deliverySplit(sale)) return '';
		const pending = num(item.pending_qty) || 0;
		if (pending <= 0.00005) {
			return '<div class="mj-small">' + MJ.ui.badgeRaw('Entregado', 'success') + '</div>';
		}
		const delivered = num(item.delivered_qty) || 0;
		return '<div class="mj-small">' + MJ.ui.badgeRaw('Falta entregar ' + MJ.fmt.qty(pending), 'warning') +
			(delivered > 0.00005 ? ' <span class="mj-muted">se llevó ' + h(MJ.fmt.qty(delivered)) + '</span>' : '') +
		'</div>';
	}

	function itemRowHtml(item, sale) {
		const isConsignment = item.origin === 'consignacion';
		return '<tr>' +
			'<td>' +
				'<div class="mj-strong">' + h(item.name) + '</div>' +
				(item.sku ? '<div class="mj-small mj-muted">SKU ' + h(item.sku) + '</div>' : '') +
				itemDeliveryHtml(item, sale) +
			'</td>' +
			'<td>' +
				MJ.ui.badgeRaw(item.origin_label || '', isConsignment ? 'info' : 'neutral') +
				(isConsignment
					? '<div class="mj-small mj-muted">Le debe ' + h(moneyBoth(item.consignment_total_base)) + '</div>'
					: '') +
			'</td>' +
			'<td class="is-num">' + h(MJ.fmt.qty(item.qty)) + '</td>' +
			'<td class="is-num">' + h(money(item.unit_price, sale)) + '</td>' +
			'<td class="is-num mj-strong">' + h(money(item.line_total, sale)) + '</td>' +
			'<td class="is-num">' + ((num(item.cost_base) || 0) > 0 ? moneyPairHtml(item.cost_base) : dash()) + '</td>' +
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
				'<div class="mj-totals__row"><dt>Costo</dt><dd>' + moneyPairHtml(sale.cost_base) + '</dd></div>' +
				'<div class="mj-totals__row"><dt>Margen</dt><dd class="' + ((num(sale.profit_base) || 0) >= 0 ? 'mj-success-text' : 'mj-danger-text') + '">' + moneyPairHtml(sale.profit_base) + '</dd></div>' +
			'</dl>' +
			'</div></div>';
	}

	/**
	 * Corregir un cobro: solo quien corrige ventas, y solo si el servidor lo marca `editable` (qué
	 * cobros no se corrigen sueltos lo decide MJERP_Sale_Payments_Repo::edit_blocker()).
	 */
	function canEditPayment(p, sale) {
		return !!info().can_manage && sale.status !== 'anulada' && !!p.editable;
	}

	function paymentsHtml(sale) {
		const payments = sale.payments || [];
		const voided = sale.status === 'anulada';
		const overpaid = voided ? 0 : (num(sale.paid_total) || 0) - (num(sale.collectable) || 0);

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
						// La plata que salió por una devolución: se ve, pero no se borra suelta.
						const isReturn = p.source === 'devolucion';
						return '<tr data-payment="' + h(p.id) + '">' +
							'<td>' + h(MJ.fmt.date(p.payment_date)) + '</td>' +
							'<td>' + h(p.method_label || '') +
								(p.reference ? '<div class="mj-small mj-muted">' + h(p.reference) + '</div>' : '') +
								(isReturn && p.note ? '<div class="mj-small mj-muted">' + h(p.note) + '</div>' : '') +
							'</td>' +
							'<td class="is-num mj-strong' + (isReturn ? ' mj-danger-text' : '') + '">' + h(MJ.fmt.money(p.amount, p.currency)) + '</td>' +
							'<td>' + (isReturn
								? MJ.ui.badgeRaw('Devolución', 'info')
								: (p.is_declared ? MJ.ui.badgeRaw(p.status_label, 'warning') : MJ.ui.badgeRaw(p.status_label, 'success'))) + '</td>' +
							'<td class="is-actions"><div class="mj-btn-group">' +
								(canEditPayment(p, sale) ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit-payment" title="Corregir" aria-label="Corregir cobro">' + MJ.icon('edit') + '</button>' : '') +
								(p.is_declared ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="confirm-payment" title="Confirmar" aria-label="Confirmar cobro">' + MJ.icon('check') + '</button>' : '') +
								(isReturn ? '' : '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-payment" title="Borrar" aria-label="Borrar cobro">' + MJ.icon('trash') + '</button>') +
							'</div></td>' +
						'</tr>';
					}).join('') +
					'</tbody></table></div>'
				: '<div class="mj-card__body mj-muted">Todavía no registraste cobros de esta venta.</div>') +
			// Pasa al corregir una venta cobrada que quedó por menos: se ve, y se arregla corrigiendo el cobro.
			(overpaid > 0.004
				? '<div class="mj-card__body">' + MJ.ui.alert('Se cobró ' + money(overpaid, sale) + ' más que el total de la venta. Corregí o borrá el cobro que sobra.', 'warning') + '</div>'
				: '') +
		'</div>';
	}

	/**
	 * Devoluciones y cambios: qué volvió, a dónde fue, qué se llevó a cambio y qué pasó con la plata.
	 * Como el historial, lo ve solo quien puede corregir ventas.
	 */
	function returnsHtml(sale) {
		const list = sale.returns || [];
		if (!list.length || !(sale.return_state || {}).can_manage) return '';
		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Devoluciones y cambios</h3></div>' +
			'<ol class="mj-sale-log">' + list.slice().reverse().map(function (r) { return returnRowHtml(r, sale); }).join('') + '</ol>' +
		'</div>';
	}

	function returnRowHtml(r, sale) {
		const lines = (r.items || []).map(function (it) {
			const qty = MJ.fmt.qty(it.qty);
			if (it.type === 'nuevo') return 'Se llevó ' + qty + ' × ' + it.name + ' a ' + money(it.unit_value, sale);
			const where = it.destination === 'rma' ? 'fue a RMA' : 'volvió al stock';
			const problem = it.problem ? ' (' + it.problem + ')' : '';
			return it.replaced
				? 'Se le dio otro igual por ' + qty + ' × ' + it.name + ' por garantía; lo que trajo ' + where + problem
				: 'Devolvió ' + qty + ' × ' + it.name + ' (' + money(it.line_value, sale) + '); ' + where + problem;
		});

		const detail = r.settlement_detail || {};
		let moneyText = '';
		if (r.settlement === 'saldo') {
			const split = MJ.components.creditBalancesText ? MJ.components.creditBalancesText(detail.credit_split) : '';
			moneyText = 'Quedó a favor: ' + (split || money(r.excess, sale));
		} else if (r.settlement === 'reintegro') {
			moneyText = 'Se le devolvió ' + money(r.excess, sale) + (r.refund_method_label ? ' · ' + r.refund_method_label : '') + (r.refund_reference ? ' · ' + r.refund_reference : '');
		} else if ((num(r.due_after) || 0) > 0.004) {
			moneyText = 'Quedó por cobrar ' + money(r.due_after, sale);
		}

		return '<li class="mj-sale-log__row">' +
			'<div class="mj-small mj-muted">' + h(MJ.fmt.datetime(r.created_at)) + (r.created_by_name ? ' · ' + h(r.created_by_name) : '') + '</div>' +
			'<div class="mj-sale-log__text"><span class="mj-strong mj-mono">' + h(r.code) + '</span> ' + MJ.ui.badgeRaw(r.kind_label || r.kind, 'info') + '</div>' +
			(lines.length ? '<ul class="mj-sale-log__changes mj-small">' + lines.map(function (l) { return '<li>' + h(l) + '</li>'; }).join('') + '</ul>' : '') +
			(moneyText ? '<div class="mj-small">' + h(moneyText) + '</div>' : '') +
			(r.reason ? '<div class="mj-small mj-muted">Motivo: ' + h(r.reason) + '</div>' : '') +
		'</li>';
	}

	/** El globo de RMA del menú: una devolución con algo fallado lo sube. */
	function refreshRmaBadge() {
		const rma = info().rma;
		if (!rma || !rma.enabled || !info().can_manage) return;
		MJ.api.get('rma', { status: 'abierto', per_page: 1 }).then(function (res) {
			if (res && res.totals) rma.open_count = num(res.totals.open_count) || 0;
			if (MJ.app && MJ.app.refreshNavBadges) MJ.app.refreshNavBadges();
		}).catch(function () { /* el globo se pone al día en la próxima carga */ });
	}

	/**
	 * Entrega: cómo se entrega, dónde, y el botón que cierra el circuito.
	 *
	 * La acción de un toque («Marcar entregada») está también en la lista de ventas; acá se repite
	 * porque es donde se termina de mirar una venta antes de salir a llevarla.
	 */
	function deliveryHtml(sale) {
		const helpers = MJ.components.saleHelpers;
		if (!helpers.deliveryEnabled()) return '';

		const voided = sale.status === 'anulada';
		const rows = [];
		rows.push(['Estado', voided ? dash() : helpers.deliveryBadge(sale)]);
		rows.push(['Cómo', sale.delivery_method_label ? h(sale.delivery_method_label) : dash()]);
		if (sale.delivery_note) rows.push(['Dónde', h(sale.delivery_note)]);
		if (!voided && deliverySplit(sale)) {
			rows.push(['Falta', helpers.deliveryPending(sale).map(function (p) {
				return h(p.name) + ' ×' + h(MJ.fmt.qty(p.qty));
			}).join('<br>')]);
		}
		if (sale.delivered_at) {
			rows.push(['Entregada', h(MJ.fmt.datetime(sale.delivered_at)) + (sale.delivered_by_name ? ' · ' + h(sale.delivered_by_name) : '')]);
		}

		return '<div class="mj-card"><div class="mj-card__header">' +
				'<h3 class="mj-card__title">Entrega</h3>' +
				(voided
					? ''
					: (sale.delivered
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="delivery">' + MJ.icon('edit') + '<span>Cambiar</span></button>'
						: '<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="deliver">' + MJ.icon('truck') + '<span>' + (deliverySplit(sale) ? 'Entregar lo que falta' : 'Marcar entregada') + '</span></button>')) +
			'</div>' +
			'<div class="mj-card__body"><dl class="mj-deflist">' +
				rows.map(function (r) {
					return '<div class="mj-deflist__row"><dt>' + h(r[0]) + '</dt><dd>' + r[1] + '</dd></div>';
				}).join('') +
			'</dl>' +
			(voided || sale.delivered
				? ''
				: '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-mt" data-action="delivery">' + MJ.icon('edit') + '<span>' + (helpers.deliveryHasParts(sale) ? 'Entregar una parte o cambiar cómo' : 'Cambiar cómo o dónde') + '</span></button>') +
			'</div></div>';
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

	/**
	 * Historial: quién cargó, cobró, entregó, corrigió o anuló la venta, y cuándo.
	 *
	 * Lo ve solo quien puede corregir ventas (Configuración → Ventas → Corregir ventas); el servidor
	 * lo vuelve a controlar al pedirlo.
	 */
	function canSeeLog() {
		return !!info().can_manage && info().log_enabled !== false;
	}

	function historyHtml(state) {
		const rows = state.log;
		let body;
		if (rows === null) {
			body = '<div class="mj-card__body">' + loadingHtml('Cargando el historial…') + '</div>';
		} else if (!rows.length) {
			body = '<div class="mj-card__body mj-muted">Todavía no hay nada anotado. Lo que se haga con esta venta desde ahora queda acá.</div>';
		} else {
			body = '<ol class="mj-sale-log">' + rows.map(logRowHtml).join('') + '</ol>';
		}
		return '<div class="mj-card"><div class="mj-card__header"><h3 class="mj-card__title">Historial</h3></div>' + body + '</div>';
	}

	function logRowHtml(row) {
		const changes = row.detail && Array.isArray(row.detail.changes) ? row.detail.changes : [];
		return '<li class="mj-sale-log__row mj-sale-log__row--' + h(row.action || '') + '">' +
			'<div class="mj-small mj-muted">' + h(MJ.fmt.datetime(row.created_at)) + (row.actor_name ? ' · ' + h(row.actor_name) : '') + '</div>' +
			'<div class="mj-sale-log__text">' + h(row.summary || '') + '</div>' +
			(changes.length
				? '<ul class="mj-sale-log__changes mj-small">' + changes.map(function (c) { return '<li>' + h(c) + '</li>'; }).join('') + '</ul>'
				: '') +
			(row.reason ? '<div class="mj-small mj-muted">Motivo: ' + h(row.reason) + '</div>' : '') +
		'</li>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.sale = {
		title(params) { return 'Venta #' + (params && params.id ? params.id : ''); },

		render(container, params) {
			const offs = [];
			const state = { id: parseInt(params && params.id, 10) || 0, sale: null, log: null, disposed: false };

			container.innerHTML = loadingHtml('Cargando la venta…');

			function paint() {
				const sale = state.sale;
				if (!sale) return;

				container.innerHTML =
					headerHtml(sale) +
					voidedNoticeHtml(sale) +
					commitNoticeHtml(sale) +
					(MJ.components.consignmentNotice ? MJ.components.consignmentNotice(sale) : '') +
					'<div class="mj-grid mj-grid--detail">' +
						'<div class="mj-grid__main">' +
							itemsHtml(sale) +
							'<div class="mj-mt">' + paymentsHtml(sale) + '</div>' +
							(returnsHtml(sale) ? '<div class="mj-mt">' + returnsHtml(sale) + '</div>' : '') +
							(canSeeLog() ? '<div class="mj-mt" data-region="history">' + historyHtml(state) + '</div>' : '') +
						'</div>' +
						'<div class="mj-grid__side">' +
							totalsHtml(sale) +
							(deliveryHtml(sale) ? '<div class="mj-mt">' + deliveryHtml(sale) + '</div>' : '') +
							'<div class="mj-mt">' + customerHtml(sale) + '</div>' +
							'<div class="mj-mt">' + detailsHtml(sale) + '</div>' +
						'</div>' +
					'</div>';
				loadLog();
			}

			function paintHistory() {
				const region = qs('[data-region="history"]', container);
				if (region) region.innerHTML = historyHtml(state);
			}

			/*
			 * El historial se vuelve a pedir cada vez que se repinta la venta: cobrar, entregar o
			 * anular también dejan su anotación, y la tarjeta tiene que mostrarla sin recargar.
			 */
			function loadLog() {
				if (!canSeeLog()) return;
				MJ.api.get('sales/' + state.id + '/log').then(function (res) {
					if (state.disposed) return;
					state.log = (res && res.items) || [];
					paintHistory();
				}).catch(function (err) {
					if (state.disposed) return;
					state.log = state.log || [];
					paintHistory();
					if (err && err.status !== 403) toastError(err, 'No se pudo cargar el historial de la venta.');
				});
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

			/* Un toque = entregada, el mismo gesto que en la lista de ventas. */
			offs.push(MJ.ui.on(container, 'click', '[data-action="deliver"]', function (e, btn) {
				if (!state.sale) return;
				MJ.ui.setLoading(btn, true);
				MJ.api.post('sales/' + state.sale.id + '/delivery', { status: 'entregado' }).then(function (updated) {
					if (state.disposed) return;
					state.sale = updated;
					MJ.ui.toast('Entregada.', 'success');
					MJ.events.emit('sales:changed', {});
					paint();
				}).catch(function (err) {
					MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo registrar la entrega.');
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="delivery"]', function () {
				if (!state.sale) return;
				MJ.components.saleDeliveryModal({
					sale: state.sale,
					onSaved(updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
					},
				});
			}));

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

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit-items"]', function () {
				if (!state.sale) return;
				MJ.components.saleItemsModal({
					sale: state.sale,
					onSaved(updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
						MJ.events.emit('sales:changed', {});
					},
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="return"]', function () {
				if (!state.sale) return;
				MJ.components.saleReturnModal({
					sale: state.sale,
					onSaved(updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
						MJ.events.emit('sales:changed', {});
						refreshRmaBadge();
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

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit-payment"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				const payment = state.sale && (state.sale.payments || []).find(function (p) { return p.id === id; });
				if (!payment) return;
				MJ.components.salePaymentModal({
					sale: state.sale,
					payment: payment,
					onSaved(updated) {
						if (state.disposed) return;
						state.sale = updated;
						paint();
						MJ.events.emit('sales:changed', {});
					},
				});
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

				// Cobrada: además de anular, se decide qué pasa con la plata.
				const rs = sale.return_state || {};
				if (rs.can_manage && (num(rs.refundable_on_void) || 0) > 0.004 && MJ.components.saleVoidModal) {
					MJ.components.saleVoidModal({
						sale: sale,
						onSaved(updated) {
							if (state.disposed) return;
							state.sale = updated;
							paint();
							MJ.events.emit('sales:changed', {});
						},
					});
					return;
				}

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
