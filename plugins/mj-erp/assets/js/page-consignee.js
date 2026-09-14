/**
 * MJ ERP — page-consignee.js
 * MJ.pages.consignee: la cuenta de un revendedor. Cuatro solapas — qué tiene en su poder, qué
 * vendió, qué pagó y el resumen de cuenta con el saldo corrido.
 *
 * REST usado: GET /consignees/{id} (trae todo de una), GET /consignees/{id}/statement,
 *             POST|DELETE /consignment-sales, POST /consignment-sales/{id}/approve|reject,
 *             POST|DELETE /consignment-payments, POST /consignment-returns.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const PATH = '/consignaciones';
	const TABS = [
		{ key: 'stock', label: 'En su poder', icon: 'package' },
		{ key: 'ventas', label: 'Ventas', icon: 'cart' },
		{ key: 'pagos', label: 'Pagos', icon: 'cash' },
		{ key: 'cuenta', label: 'Cuenta', icon: 'list' },
	];

	function num(v) { return MJ.fmt.toNumber(v); }
	function f(v) { return num(v) === null ? 0 : num(v); }

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCur() { return String(setting('base_currency', 'USD')).toUpperCase(); }

	/** Medios de cobro configurados, con el comodín como último recurso. */
	function paymentMethods() {
		const boot = MJ.state.bootstrap && MJ.state.bootstrap.consignment;
		const list = boot && Array.isArray(boot.payment_methods) ? boot.payment_methods : setting('consignment_payment_methods', []);
		return Array.isArray(list) && list.length ? list : [{ key: 'otro', label: 'Otro' }];
	}

	function moneyBase(value) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, baseCur(), decimals);
	}

	/**
	 * El mismo importe en pesos, al TC de hoy. '' cuando no hay TC cargado.
	 *
	 * La deuda se lleva en dólares: esto es la lectura del día, no un segundo número guardado.
	 * Por eso se muestra siempre debajo y más chico que el importe real.
	 */
	function moneyLocal(value) {
		const local = MJ.fmt.toLocal(value);
		return local === null ? '' : MJ.fmt.moneyLocal(local, 0);
	}

	/** Importe en USD con su equivalente en pesos debajo. */
	function moneyPairHtml(value, extraClass) {
		const local = moneyLocal(value);
		return '<span class="mj-money-pair' + (extraClass ? ' ' + extraClass : '') + '">' +
			'<span class="mj-money-pair__base">' + h(moneyBase(value)) + '</span>' +
			(local ? '<span class="mj-money-pair__local">' + h(local) + '</span>' : '') +
		'</span>';
	}

	/** Idem, en una sola línea: "US$ 14 · $ 21.996". */
	function moneyPairInline(value) {
		const local = moneyLocal(value);
		return moneyBase(value) + (local ? ' · ' + local : '');
	}

	function dash() { return '<span class="mj-muted">—</span>'; }

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function tabFromQuery(query) {
		const raw = query && query.tab ? String(query.tab).toLowerCase() : '';
		return TABS.some(function (t) { return t.key === raw; }) ? raw : 'stock';
	}

	const SALE_STATUS = {
		pendiente: { label: 'A confirmar', color: 'warning' },
		aprobada: { label: 'Confirmada', color: 'success' },
		rechazada: { label: 'Rechazada', color: 'neutral' },
	};

	/* ------------------------------------------------------------------ */
	/* HTML — cabecera y resumen                                           */
	/* ------------------------------------------------------------------ */

	function headerHtml(consignee, account) {
		const active = consignee.is_active !== false;
		const avatar = consignee.avatar
			? '<img class="mj-avatar" src="' + h(consignee.avatar) + '" alt="" width="40" height="40" loading="lazy">'
			: '';
		const list = consignee.price_list && consignee.price_list.label ? consignee.price_list.label : '';

		const meta = [];
		if (consignee.email) meta.push(h(consignee.email));
		if (consignee.phone) meta.push(h(consignee.phone));
		if (list) meta.push('Lista ' + h(list));

		return '<div class="mj-page-header">' +
			'<div class="mj-row mj-row--center">' +
				'<a class="mj-btn mj-btn--ghost mj-btn--icon" href="' + h(MJ.router.link(PATH)) + '" title="Volver" aria-label="Volver a consignaciones">' + MJ.icon('arrow-left') + '</a>' +
				avatar +
				'<div>' +
					'<h2 class="mj-page-header__title">' + h(consignee.name || ('Revendedor #' + consignee.id)) +
						(active ? '' : ' ' + MJ.ui.badgeRaw('Desactivado', 'neutral')) + '</h2>' +
					'<div class="mj-page-header__subtitle mj-muted">' + (meta.length ? meta.join(' · ') : 'Revendedor') + '</div>' +
				'</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost" data-action="new-consignment">' + MJ.icon('truck') + '<span>Entregar mercadería</span></button>' +
				'<button type="button" class="mj-btn mj-btn--ghost" data-action="new-sale">' + MJ.icon('cart') + '<span>Registrar venta</span></button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="new-payment">' + MJ.icon('cash') + '<span>Registrar pago</span></button>' +
			'</div>' +
		'</div>';
	}

	function summaryHtml(account) {
		const balance = f(account.balance_base);
		const owes = balance > 0.005;
		const credit = balance < -0.005;

		const cards = [
			{
				icon: 'cash',
				label: owes ? 'Te debe' : (credit ? 'Tiene a favor' : 'Saldo'),
				value: moneyBase(Math.abs(balance)),
				local: moneyLocal(Math.abs(balance)),
				className: owes ? (account.over_limit ? 'mj-kpi--danger' : 'mj-kpi--transit') : (credit ? 'mj-kpi--received' : ''),
				sub: account.credit_limit_base > 0
					? 'Límite ' + moneyBase(account.credit_limit_base) + (account.over_limit ? ' · pasado' : '')
					: (account.unpaid_sales ? account.unpaid_sales + ' venta' + (account.unpaid_sales === 1 ? '' : 's') + ' sin cobrar' : 'Al día'),
			},
			{
				icon: 'package',
				label: 'En su poder',
				value: MJ.fmt.qty(account.on_hand_units) + ' u.',
				sub: moneyPairInline(account.on_hand_value_base) + ' en ' + account.on_hand_lines + ' producto' + (account.on_hand_lines === 1 ? '' : 's'),
			},
			{
				icon: 'cart',
				label: 'Vendió',
				value: moneyBase(account.charges_base),
				local: moneyLocal(account.charges_base),
				sub: account.sales_count + ' venta' + (account.sales_count === 1 ? '' : 's') + ' confirmada' + (account.sales_count === 1 ? '' : 's'),
			},
			{
				icon: 'check-circle',
				label: 'Me pagó',
				value: moneyBase(account.credits_base),
				local: moneyLocal(account.credits_base),
				sub: account.last_payment_date ? 'Último ' + MJ.fmt.date(account.last_payment_date) : 'Sin pagos',
			},
		];

		return '<div class="mj-grid mj-grid--4 mj-mb">' + cards.map(kpiHtml).join('') + '</div>';
	}

	/** Tarjeta KPI, con el mismo marcado que usa el tablero. */
	function kpiHtml(c) {
		return '<div class="mj-card mj-kpi' + (c.className ? ' ' + c.className : '') + '">' +
			'<div class="mj-kpi__label">' + (c.icon ? MJ.icon(c.icon) : '') + '<span>' + h(c.label) + '</span></div>' +
			'<div class="mj-kpi__value mj-money">' + h(c.value) + '</div>' +
			// El equivalente en pesos va pegado al importe, no en el subtítulo: es el mismo dato
			// leído de otra manera, no información aparte.
			(c.local ? '<div class="mj-kpi__local mj-muted mj-money">' + h(c.local) + '</div>' : '') +
			'<div class="mj-kpi__sub mj-muted">' + h(c.sub || '') + '</div>' +
		'</div>';
	}

	function alertsHtml(account) {
		let out = '';
		const pending = parseInt(account.pending_sales, 10) || 0;
		if (pending) {
			out += MJ.ui.alert(
				(pending === 1 ? 'Cargó 1 venta que ' : 'Cargó ' + pending + ' ventas que ') +
				(pending === 1 ? 'está esperando' : 'están esperando') + ' tu confirmación por ' + moneyBase(account.pending_base) +
				'. Hasta que la confirmes no baja el stock ni se genera la deuda.',
				'warning'
			);
		}
		const credit = f(account.unallocated_base);
		if (credit >= 0.005) {
			out += MJ.ui.alert('Tiene ' + moneyBase(credit) + ' pagados que todavía no están imputados a ninguna venta. Se aplican solos a la próxima venta que confirmes.', 'info');
		}
		if (account.over_limit) {
			out += MJ.ui.alert('Pasó su límite de crédito de ' + moneyBase(account.credit_limit_base) + '.', 'danger');
		}
		return out;
	}

	function tabsHtml(uid, active, account) {
		return '<div class="mj-tabs" role="tablist">' +
			TABS.map(function (t) {
				const on = t.key === active;
				let badge = '';
				if (t.key === 'ventas' && account && account.pending_sales) {
					badge = ' <span class="mj-badge mj-badge--warning mj-badge--sm">' + h(account.pending_sales) + '</span>';
				}
				return '<a class="mj-tabs__tab' + (on ? ' is-active' : '') + '" role="tab" id="' + uid + '-tab-' + t.key + '" ' +
					'aria-selected="' + (on ? 'true' : 'false') + '" ' +
					'href="' + h(MJ.router.link(PATH + '/revendedor/' + account.consignee_id + (t.key === 'stock' ? '' : '?tab=' + t.key))) + '">' +
					MJ.icon(t.icon) + '<span>' + h(t.label) + '</span>' + badge + '</a>';
			}).join('') +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — solapas                                                      */
	/* ------------------------------------------------------------------ */

	function stockHtml(stock) {
		if (!stock.length) {
			return MJ.ui.empty({
				icon: 'package',
				title: 'No tiene mercadería',
				text: 'Cuando le entregues productos y marques la entrega como entregada, van a aparecer acá con lo que le queda por vender.',
				action: { label: 'Entregar mercadería', icon: 'truck', action: 'new-consignment' },
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Producto</th>' +
			'<th>Entrega</th>' +
			'<th class="is-num">Entregado</th>' +
			'<th class="is-num">Vendido</th>' +
			'<th class="is-num">Devuelto</th>' +
			'<th class="is-num">Le queda</th>' +
			'<th class="is-num">Precio acordado</th>' +
			'<th class="is-num">Valor</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		stock.map(function (row) {
			return '<tr data-item="' + h(row.id) + '">' +
				'<td>' +
					'<div class="mj-strong">' + h(row.name || '') + '</div>' +
					(row.sku ? '<div class="mj-small mj-muted">SKU ' + h(row.sku) + '</div>' : '') +
				'</td>' +
				'<td>' +
					'<span class="mj-mono mj-small">' + h(row.consignment_code || '') + '</span>' +
					(row.delivered_at ? '<div class="mj-small mj-muted">' + h(MJ.fmt.date(row.delivered_at)) + '</div>' : '') +
				'</td>' +
				'<td class="is-num">' + h(MJ.fmt.qty(row.qty)) + '</td>' +
				'<td class="is-num">' + h(MJ.fmt.qty(row.qty_sold)) + '</td>' +
				'<td class="is-num">' + (f(row.qty_returned) > 0 ? h(MJ.fmt.qty(row.qty_returned)) : dash()) + '</td>' +
				'<td class="is-num"><span class="mj-strong">' + h(MJ.fmt.qty(row.qty_on_hand)) + '</span></td>' +
				'<td class="is-num">' + moneyPairHtml(row.unit_price_base) + '</td>' +
				'<td class="is-num">' + moneyPairHtml(row.value_on_hand_base, 'mj-money') + '</td>' +
				'<td class="is-actions">' +
					'<div class="mj-btn-group">' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="sell-item" title="Registrar venta de este producto">' + MJ.icon('cart') + '<span>Vendió</span></button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit-price" title="Cambiar el precio acordado" aria-label="Cambiar el precio acordado">' + MJ.icon('tag') + '</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="return-item" title="Registrar devolución" aria-label="Registrar devolución">' + MJ.icon('undo') + '</button>' +
					'</div>' +
				'</td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	function salesHtml(sales) {
		const rows = (sales && sales.items) || [];
		if (!rows.length) {
			return MJ.ui.empty({
				icon: 'cart',
				title: 'Todavía no registró ventas',
				text: 'Cuando el revendedor venda, se registra acá: baja su stock y se genera la deuda.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Fecha</th>' +
			'<th>Producto</th>' +
			'<th class="is-num">Cant.</th>' +
			'<th class="is-num">Precio</th>' +
			'<th class="is-num">Total</th>' +
			'<th class="is-num">Debe</th>' +
			'<th>Estado</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		rows.map(function (sale) {
			const info = SALE_STATUS[sale.status] || { label: sale.status, color: 'neutral' };
			const pending = sale.status === 'pendiente';
			const balance = f(sale.balance_base);

			let balanceCell = dash();
			if (sale.status === 'aprobada') {
				balanceCell = balance <= 0.005
					? '<span class="mj-success-text">Cobrada</span>'
					: moneyPairHtml(balance, 'mj-strong');
			}

			return '<tr data-sale="' + h(sale.id) + '">' +
				'<td>' + h(MJ.fmt.date(sale.sale_date)) +
					(sale.source === 'portal' ? '<div class="mj-small mj-muted">La cargó él</div>' : '') + '</td>' +
				'<td>' +
					'<div class="mj-strong">' + h(sale.name || '') + '</div>' +
					(sale.sku ? '<div class="mj-small mj-muted">SKU ' + h(sale.sku) + '</div>' : '') +
				'</td>' +
				'<td class="is-num">' + h(MJ.fmt.qty(sale.qty)) + '</td>' +
				'<td class="is-num">' + moneyPairHtml(sale.unit_price_base) + '</td>' +
				'<td class="is-num">' + moneyPairHtml(sale.amount_base, 'mj-money') + '</td>' +
				'<td class="is-num">' + balanceCell + '</td>' +
				'<td>' + MJ.ui.badgeRaw(info.label, info.color) + '</td>' +
				'<td class="is-actions">' +
					'<div class="mj-btn-group">' +
						(pending
							? '<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="approve-sale">' + MJ.icon('check') + '<span>Confirmar</span></button>' +
							  '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="reject-sale" title="Rechazar" aria-label="Rechazar venta">' + MJ.icon('x') + '</button>'
							: '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="sale-detail" title="Ver detalle" aria-label="Ver detalle de la venta">' + MJ.icon('eye') + '</button>') +
						(sale.status === 'aprobada' && balance > 0.005
							? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="charge-sale" title="Registrar el pago de esta venta">' + MJ.icon('cash') + '<span>Cobrar</span></button>'
							: '') +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit-sale" title="Editar la venta" aria-label="Editar la venta">' + MJ.icon('edit') + '</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-sale" title="Eliminar la venta" aria-label="Eliminar la venta">' + MJ.icon('trash') + '</button>' +
					'</div>' +
				'</td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	/**
	 * Estado del comprobante de un pago, con link a la bandeja de MJ Medios de
	 * Pago, que es donde se aprueba o se rechaza. Devuelve '' cuando ese pago no
	 * pide comprobante o el plugin no está instalado.
	 *
	 * @param {Object} payment Pago.
	 * @returns {string} HTML.
	 */
	function receiptHint(payment) {
		const r = payment && payment.receipt;
		if (!r) return '';

		const tone = r.state === 'aprobado' ? 'success' : (r.state === 'rechazado' ? 'danger' : 'warning');
		const label = r.count > 0
			? (r.noun_title || 'Comprobante') + ': ' + (r.state_label || '')
			: 'Sin ' + (r.noun || 'comprobante');
		const badge = MJ.ui.badgeRaw(label, r.count > 0 ? tone : 'neutral');

		return ' ' + (r.review_url
			? '<a class="mj-receipt-link" href="' + h(r.review_url) + '" target="_blank" rel="noopener" title="Revisar el comprobante">' + badge + '</a>'
			: badge);
	}

	function paymentsHtml(payments) {
		const rows = (payments && payments.items) || [];
		if (!rows.length) {
			return MJ.ui.empty({
				icon: 'cash',
				title: 'Todavía no registró pagos',
				text: 'Cada pago se imputa a las ventas que está cobrando, así siempre se sabe qué pagó.',
				action: { label: 'Registrar pago', icon: 'cash', action: 'new-payment' },
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Fecha</th>' +
			'<th>Tipo</th>' +
			'<th class="is-num">Importe</th>' +
			'<th class="is-num">En ' + h(baseCur()) + '</th>' +
			'<th>Se imputó a</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		rows.map(function (payment) {
			const isAdjust = payment.kind === 'ajuste';
			const allocations = Array.isArray(payment.allocations) ? payment.allocations : [];
			const unallocated = f(payment.unallocated_base);

			let applied;
			if (payment.is_declared) {
				// Un pago que avisó el revendedor todavía no bajó nada: hasta confirmarlo es una
				// declaración suya, y por eso no aparece imputado a ninguna venta.
				applied = '<span class="mj-small mj-warning-text">Lo avisó el revendedor · confirmalo para que baje su saldo</span>';
			} else if (isAdjust) {
				applied = '<span class="mj-small">' + h(payment.reason || 'Ajuste manual') + '</span>';
			} else if (!allocations.length) {
				applied = '<span class="mj-small mj-warning-text">Sin imputar · queda a favor</span>';
			} else {
				applied = '<div class="mj-small">' + allocations.map(function (a) {
					return '<div>' + h(moneyPairInline(a.amount_base)) + ' → ' +
						h(a.sale_name || ('venta #' + a.sale_id)) +
						(a.sale_date ? ' <span class="mj-muted">' + h(MJ.fmt.date(a.sale_date)) + '</span>' : '') +
					'</div>';
				}).join('') +
				(unallocated >= 0.005 ? '<div class="mj-muted">' + h(moneyPairInline(unallocated)) + ' quedaron a favor</div>' : '') +
				'</div>';
			}

			const amount = f(payment.amount);
			const sameCurrency = String(payment.currency).toUpperCase() === baseCur();

			return '<tr data-payment="' + h(payment.id) + '"' + (payment.is_declared ? ' class="is-pending"' : '') + '>' +
				'<td>' + h(MJ.fmt.date(payment.payment_date)) + '</td>' +
				'<td>' +
					(isAdjust
						? MJ.ui.badgeRaw('Ajuste', 'info')
						: '<div>' + h(payment.method_label || '') + '</div>' +
						  (payment.reference ? '<div class="mj-small mj-muted mj-truncate">' + h(payment.reference) + '</div>' : '')) +
					(payment.is_declared ? ' ' + MJ.ui.badgeRaw('A confirmar', 'warning') : '') +
					receiptHint(payment) +
				'</td>' +
				'<td class="is-num">' + h(MJ.fmt.money(amount, payment.currency)) +
					(sameCurrency ? '' : '<div class="mj-small mj-muted">TC ' + h(MJ.fmt.rate(payment.rate)) + '</div>') + '</td>' +
				'<td class="is-num">' + moneyPairHtml(payment.amount_base, 'mj-money mj-strong') + '</td>' +
				'<td>' + applied + '</td>' +
				'<td class="is-actions"><div class="mj-btn-group">' +
					(payment.is_declared
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="confirm-payment" title="Confirmar el pago">' + MJ.icon('check') + '<span>Confirmar</span></button>'
						: '') +
					(payment.receipt && payment.receipt.count
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="view-receipt" title="Ver el comprobante" aria-label="Ver el comprobante">' + MJ.icon('file') + '</button>'
						: '') +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit-payment" title="Editar el pago" aria-label="Editar el pago">' + MJ.icon('edit') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-payment" title="Eliminar" aria-label="Eliminar pago">' + MJ.icon('trash') + '</button>' +
				'</div></td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	function statementHtml(rows) {
		if (!rows || !rows.length) {
			return MJ.ui.empty({
				icon: 'list',
				title: 'Sin movimientos',
				text: 'Acá se ve la cuenta corriente completa: cada venta que suma y cada pago que resta, con el saldo corrido.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Fecha</th>' +
			'<th>Detalle</th>' +
			'<th class="is-num">Debe</th>' +
			'<th class="is-num">Haber</th>' +
			'<th class="is-num">Saldo</th>' +
		'</tr></thead><tbody>' +
		rows.map(function (row) {
			const charge = f(row.charge_base);
			const credit = f(row.credit_base);
			const balance = f(row.balance_base);
			const icon = row.type === 'venta' ? 'cart' : (row.type === 'ajuste' ? 'edit' : 'cash');
			return '<tr>' +
				'<td>' + h(MJ.fmt.date(row.date)) + '</td>' +
				'<td><span class="mj-row">' + MJ.icon(icon) + '<span>' + h(row.description || '') + '</span></span></td>' +
				'<td class="is-num">' + (charge > 0.005 ? h(moneyBase(charge)) : dash()) + '</td>' +
				'<td class="is-num">' + (credit > 0.005 ? '<span class="mj-success-text">' + h(moneyBase(credit)) + '</span>' : dash()) + '</td>' +
				'<td class="is-num"><span class="' + (balance > 0.005 ? 'mj-strong' : 'mj-muted') + '">' + h(moneyBase(balance)) + '</span></td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.consignee = {
		title(params) { return 'Revendedor'; },

		render(container, params, query) {
			const uid = 'mj-cd-' + Math.random().toString(36).slice(2, 8);
			const id = parseInt(params && params.id, 10) || 0;
			const state = {
				id: id,
				tab: tabFromQuery(query),
				data: null,
				statement: null,
				error: null,
				disposed: false,
				seq: 0,
			};
			const offs = [];
			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML = loadingHtml('Cargando la cuenta…');

			function outstanding() {
				const sales = state.data && state.data.sales ? (state.data.sales.items || []) : [];
				return sales
					.filter(function (s) { return s.status === 'aprobada' && f(s.balance_base) > 0.0001; })
					.sort(function (a, b) {
						const d = String(a.sale_date || '').localeCompare(String(b.sale_date || ''));
						return d !== 0 ? d : (a.id - b.id);
					});
			}

			function stockRows() {
				return state.data && Array.isArray(state.data.stock) ? state.data.stock : [];
			}

			function paint() {
				if (state.error) {
					container.innerHTML = MJ.ui.alert(state.error, 'danger') +
						'<a class="mj-btn mj-btn--ghost mj-mt" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + '<span>Volver</span></a>';
					return;
				}
				if (!state.data) {
					container.innerHTML = loadingHtml('Cargando la cuenta…');
					return;
				}

				const consignee = state.data.consignee;
				const account = state.data.account;

				container.innerHTML =
					headerHtml(consignee, account) +
					summaryHtml(account) +
					'<div data-region="alerts">' + alertsHtml(account) + '</div>' +
					tabsHtml(uid, state.tab, account) +
					'<div data-region="panel">' + panelHtml() + '</div>';
			}

			function panelHtml() {
				if (state.tab === 'stock') return stockHtml(stockRows());
				if (state.tab === 'ventas') return salesHtml(state.data.sales);
				if (state.tab === 'pagos') return paymentsHtml(state.data.payments);
				return state.statement === null ? loadingHtml('Cargando la cuenta corriente…') : statementHtml(state.statement);
			}

			function paintPanel() {
				const el = region('panel');
				if (el) el.innerHTML = panelHtml();
			}

			function load() {
				const seq = ++state.seq;
				return MJ.api.get('consignees/' + state.id).then(function (res) {
					if (state.disposed || seq !== state.seq) return;
					state.data = res;
					state.error = null;
					paint();
					// El contador del menú tiene que seguir a lo que se acaba de confirmar o rechazar.
					MJ.events.emit('consignment:changed', null);
					if (state.tab === 'cuenta') loadStatement();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = MJ.api.message(err, 'No se pudo cargar la cuenta del revendedor.');
					paint();
				});
			}

			function loadStatement() {
				return MJ.api.get('consignees/' + state.id + '/statement', { limit: 300 }).then(function (res) {
					if (state.disposed) return;
					state.statement = Array.isArray(res && res.items) ? res.items : [];
					if (state.tab === 'cuenta') paintPanel();
				}).catch(function (err) {
					if (state.disposed) return;
					toastError(err, 'No se pudo cargar la cuenta corriente.');
				});
			}

			load();

			/* ---------------- acciones ---------------- */

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-consignment"]', function () {
				MJ.components.consignmentCreateModal({
					consigneeId: state.id,
					onCreated(consignment) {
						if (consignment && consignment.id) MJ.router.navigate(PATH + '/entrega/' + consignment.id);
					},
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-sale"]', function () {
				MJ.components.saleModal({
					consigneeId: state.id,
					stock: stockRows(),
					onSaved() { load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="sell-item"]', function (e, el) {
				const tr = el.closest('tr');
				const itemId = tr ? parseInt(tr.getAttribute('data-item'), 10) : 0;
				const item = stockRows().find(function (r) { return parseInt(r.id, 10) === itemId; });
				MJ.components.saleModal({
					consigneeId: state.id,
					stock: stockRows(),
					item: item,
					onSaved() { load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="return-item"]', function (e, el) {
				const tr = el.closest('tr');
				const itemId = tr ? parseInt(tr.getAttribute('data-item'), 10) : 0;
				const item = stockRows().find(function (r) { return parseInt(r.id, 10) === itemId; });
				MJ.components.returnModal({
					stock: stockRows(),
					item: item,
					onSaved() { load(); },
				});
			}));

			/**
			 * Diálogo de pago. Con una venta, llega con lo que debe esa venta ya puesto e imputado
			 * a ella: solo falta elegir cómo pagó.
			 *
			 * @param {number} [saleId]
			 */
			function openPayment(saleId) {
				MJ.components.paymentModal({
					consigneeId: state.id,
					consigneeName: state.data && state.data.consignee ? state.data.consignee.name : '',
					outstanding: outstanding(),
					account: state.data ? state.data.account : null,
					saleId: saleId || 0,
					onSaved() {
						state.statement = null;
						load();
					},
				});
			}

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-payment"]', function () {
				openPayment(0);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="charge-sale"]', function (e, el) {
				const tr = el.closest('tr');
				const saleId = tr ? parseInt(tr.getAttribute('data-sale'), 10) : 0;
				if (saleId) openPayment(saleId);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="approve-sale"]', function (e, el) {
				const tr = el.closest('tr');
				const saleId = tr ? parseInt(tr.getAttribute('data-sale'), 10) : 0;
				if (!saleId) return;
				MJ.ui.setLoading(el, true);
				MJ.api.post('consignment-sales/' + saleId + '/approve', {}).then(function () {
					if (state.disposed) return;
					MJ.ui.toast('Venta confirmada: bajó el stock y se generó la deuda.', 'success');
					state.statement = null;
					load();
				}).catch(function (err) {
					MJ.ui.setLoading(el, false);
					toastError(err, 'No se pudo confirmar la venta.');
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="reject-sale"]', function (e, el) {
				const tr = el.closest('tr');
				const saleId = tr ? parseInt(tr.getAttribute('data-sale'), 10) : 0;
				if (!saleId) return;
				MJ.ui.prompt({
					title: 'Rechazar venta',
					label: 'Motivo (opcional)',
					hint: 'La venta queda rechazada y no genera deuda. Si ya había bajado stock, se devuelve.',
					okLabel: 'Rechazar',
					required: false,
				}).then(function (note) {
					if (note === null || state.disposed) return;
					MJ.api.post('consignment-sales/' + saleId + '/reject', { note: String(note || '') }).then(function () {
						if (state.disposed) return;
						MJ.ui.toast('Venta rechazada.', 'success');
						load();
					}).catch(function (err) {
						toastError(err, 'No se pudo rechazar la venta.');
					});
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="sale-detail"]', function (e, el) {
				const tr = el.closest('tr');
				const saleId = tr ? parseInt(tr.getAttribute('data-sale'), 10) : 0;
				if (!saleId) return;
				openSaleDetail(saleId);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="confirm-payment"]', function (e, el) {
				const tr = el.closest('tr');
				const paymentId = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				if (!paymentId) return;

				MJ.ui.setLoading(el, true);
				MJ.api.post('consignment-payments/' + paymentId + '/confirm').then(function () {
					if (state.disposed) return;
					MJ.ui.toast('Pago confirmado: ya bajó su saldo.', 'success');
					state.statement = null;
					load();
					MJ.events.emit('consignment:changed', {});
				}).catch(function (err) {
					MJ.ui.setLoading(el, false);
					toastError(err, 'No se pudo confirmar el pago.');
				});
			}));

			/* ---------------- correcciones ---------------- */

			/** La fila de stock que corresponde a un botón. */
			function itemFromRow(el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-item'), 10) : 0;
				return (state.data.stock || []).find(function (r) { return parseInt(r.id, 10) === id; }) || null;
			}

			function saleFromRow(el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-sale'), 10) : 0;
				const rows = (state.data.sales && state.data.sales.items) || [];
				return rows.find(function (r) { return parseInt(r.id, 10) === id; }) || null;
			}

			function paymentFromRow(el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				const rows = (state.data.payments && state.data.payments.items) || [];
				return rows.find(function (r) { return parseInt(r.id, 10) === id; }) || null;
			}

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit-price"]', function (e, el) {
				const item = itemFromRow(el);
				if (!item) return;
				MJ.components.consignmentPriceModal({
					item: item,
					onSaved() { state.statement = null; load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit-sale"]', function (e, el) {
				const sale = saleFromRow(el);
				if (!sale) return;
				MJ.components.saleEditModal({
					sale: sale,
					onSaved() { state.statement = null; load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="delete-sale"]', function (e, el) {
				const sale = saleFromRow(el);
				if (!sale) return;
				const paid = f(sale.allocated_base) > 0.0001;
				MJ.ui.confirm({
					title: 'Eliminar la venta',
					message: paid
						? 'Esta venta tiene pagos aplicados. Quitá o reasigná el pago antes de borrarla.'
						: 'Se borra la venta, las unidades vuelven a quedar en poder del revendedor y la deuda desaparece. Queda anotado en su historial.',
					okLabel: paid ? 'Entendido' : 'Eliminar',
					cancelLabel: 'Cancelar',
					danger: !paid,
				}).then(function (ok) {
					if (!ok || paid || state.disposed) return;
					MJ.api.del('consignment-sales/' + sale.id).then(function () {
						if (state.disposed) return;
						MJ.ui.toast('Venta eliminada.', 'success');
						state.statement = null;
						load();
					}).catch(function (err) {
						toastError(err, 'No se pudo eliminar la venta.');
					});
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit-payment"]', function (e, el) {
				const payment = paymentFromRow(el);
				if (!payment) return;
				MJ.components.paymentEditModal({
					payment: payment,
					methods: paymentMethods(),
					onSaved() { state.statement = null; load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="view-receipt"]', function (e, el) {
				const payment = paymentFromRow(el);
				if (!payment) return;
				MJ.components.receiptModal({
					payment: payment,
					onChanged() { state.statement = null; load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="delete-payment"]', function (e, el) {
				const tr = el.closest('tr');
				const paymentId = tr ? parseInt(tr.getAttribute('data-payment'), 10) : 0;
				if (!paymentId) return;
				MJ.ui.confirm({
					title: 'Eliminar pago',
					message: 'Se elimina el pago y las ventas que estaba pagando vuelven a quedar impagas.',
					okLabel: 'Eliminar',
					cancelLabel: 'Cancelar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.del('consignment-payments/' + paymentId).then(function () {
						if (state.disposed) return;
						MJ.ui.toast('Pago eliminado.', 'success');
						state.statement = null;
						load();
					}).catch(function (err) {
						toastError(err, 'No se pudo eliminar el pago.');
					});
				});
			}));

			function openSaleDetail(saleId) {
				MJ.api.get('consignment-sales/' + saleId).then(function (sale) {
					if (state.disposed || !sale) return;
					const allocations = Array.isArray(sale.allocations) ? sale.allocations : [];
					const balance = f(sale.balance_base);
					const canCharge = outstanding().some(function (s) { return parseInt(s.id, 10) === parseInt(sale.id, 10); });

					/*
					 * Los pesos de lo cobrado son los que entraron de verdad: un pago en pesos lleva su
					 * propio TC. Solo un pago en dólares se lee con el dólar de hoy.
					 */
					const paidLocal = function (a) {
						const cur = String(a.currency || '').toUpperCase();
						if (cur && cur !== baseCur() && f(a.rate) > 0) return MJ.fmt.moneyLocal(f(a.amount_base) * f(a.rate), 0);
						return moneyLocal(a.amount_base);
					};

					const paidRows = allocations.length
						? '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
							'<th>Fecha</th><th>Medio</th><th class="is-num">Importe</th>' +
						'</tr></thead><tbody>' +
						allocations.map(function (a) {
							const local = paidLocal(a);
							return '<tr>' +
								'<td>' + h(MJ.fmt.date(a.payment_date)) + '</td>' +
								'<td>' + h(a.kind === 'ajuste' ? (a.reason || 'Ajuste manual') : a.method_label) +
									(a.reference ? '<div class="mj-small mj-muted">' + h(a.reference) + '</div>' : '') + '</td>' +
								'<td class="is-num">' + h(moneyBase(a.amount_base)) +
									(local ? '<div class="mj-small mj-muted">' + h(local) + '</div>' : '') + '</td>' +
							'</tr>';
						}).join('') + '</tbody></table></div>'
						: '<div class="mj-note mj-small mj-muted">Todavía no se cobró nada de esta venta.</div>';

					const modal = MJ.ui.modal({
						title: 'Venta #' + sale.id,
						size: 'md',
						body:
							'<div class="mj-stat-list">' +
								'<div class="mj-stat-list__row"><span>Producto</span><span class="mj-strong">' + h(sale.name || '') + '</span></div>' +
								'<div class="mj-stat-list__row"><span>Fecha</span><span>' + h(MJ.fmt.date(sale.sale_date)) + '</span></div>' +
								'<div class="mj-stat-list__row"><span>Cantidad</span><span class="mj-num">' + h(MJ.fmt.qty(sale.qty)) + '</span></div>' +
								'<div class="mj-stat-list__row"><span>Precio acordado</span><span class="mj-num">' + h(moneyPairInline(sale.unit_price_base)) + '</span></div>' +
								(sale.retail_amount !== null && sale.retail_amount !== undefined
									? '<div class="mj-stat-list__row"><span>Lo vendió a</span><span class="mj-num">' + h(MJ.fmt.money(sale.retail_amount, sale.retail_currency)) + '</span></div>'
									: '') +
								'<div class="mj-stat-list__row mj-stat-list__row--total"><span>Total</span>' +
									'<span class="mj-num mj-money mj-strong">' + h(moneyPairInline(sale.amount_base)) + '</span></div>' +
								'<div class="mj-stat-list__row"><span>' + (balance <= 0.005 ? 'Estado' : 'Falta cobrar') + '</span>' +
									'<span class="mj-num ' + (balance <= 0.005 ? 'mj-success-text' : 'mj-strong') + '">' +
									h(balance <= 0.005 ? 'Cobrada' : moneyPairInline(balance)) + '</span></div>' +
							'</div>' +
							(sale.note ? '<div class="mj-note mj-small mj-muted mj-mt">' + h(sale.note) + '</div>' : '') +
							'<div class="mj-divider"></div>' +
							'<div class="mj-field__label">Pagos imputados a esta venta</div>' +
							paidRows,
						footer: canCharge
							? '<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cerrar</button>' +
							  '<button type="button" class="mj-btn mj-btn--primary" data-charge>' + MJ.icon('cash') + '<span>Cobrar ' + h(moneyBase(balance)) + '</span></button>'
							: '<button type="button" class="mj-btn mj-btn--primary" data-modal-close>Cerrar</button>',
					});

					if (canCharge) {
						MJ.ui.on(modal.el, 'click', '[data-charge]', function () {
							modal.close();
							openPayment(sale.id);
						});
					}
				}).catch(function (err) {
					toastError(err, 'No se pudo cargar la venta.');
				});
			}

			return function cleanup() {
				state.disposed = true;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
