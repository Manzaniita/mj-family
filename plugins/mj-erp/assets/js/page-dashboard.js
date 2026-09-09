/**
 * MJ ERP — page-dashboard.js
 * MJ.pages.dashboard: KPIs, recent purchases, quick actions.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };

	function firstOfMonth() {
		const d = new Date();
		return d.getFullYear() + '-' + (d.getMonth() < 9 ? '0' : '') + (d.getMonth() + 1) + '-01';
	}

	function num(v) { const n = MJ.fmt.toNumber(v); return n === null ? 0 : n; }

	function kpi(opts) {
		return '<div class="mj-card mj-kpi' + (opts.className ? ' ' + opts.className : '') + '"' + (opts.href ? ' data-href="' + h(opts.href) + '" role="link" tabindex="0"' : '') + '>' +
			'<div class="mj-kpi__label">' + (opts.icon ? MJ.icon(opts.icon) : '') + '<span>' + h(opts.label) + '</span></div>' +
			'<div class="mj-kpi__value' + (opts.money ? ' mj-money' : '') + '"' + (opts.valueAttr ? ' ' + opts.valueAttr : '') + '>' + (opts.valueHtml !== undefined ? opts.valueHtml : h(opts.value)) + '</div>' +
			'<div class="mj-kpi__sub mj-muted"' + (opts.subAttr ? ' ' + opts.subAttr : '') + '>' + (opts.subHtml !== undefined ? opts.subHtml : h(opts.sub || '')) + '</div>' +
		'</div>';
	}

	/**
	 * Fila de consignaciones: qué hay en la calle, quién debe y qué está esperando confirmación.
	 * No se muestra hasta que haya al menos un revendedor cargado.
	 */
	function consignmentCardHtml(data) {
		const info = data && data.consignment;
		if (!info || !info.enabled || !parseInt(info.consignees, 10)) return '';

		const base = String(MJ.setting('base_currency', 'USD')).toUpperCase();
		const moneyBase = function (value) {
			const n = MJ.fmt.toNumber(value);
			if (n === null) return '—';
			const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
			return MJ.fmt.money(n, base, decimals);
		};

		const pending = parseInt(info.pending_sales, 10) || 0;
		const balance = num(info.balance_base);
		const people = parseInt(info.consignees, 10) || 0;

		return '<div class="mj-grid mj-grid--3 mj-mb">' +
			kpi({
				icon: 'layers', label: 'En la calle', className: 'mj-kpi--transit', href: MJ.router.link('/consignaciones'),
				value: MJ.fmt.qty(info.on_hand_units) + ' u.',
				sub: moneyBase(info.on_hand_value_base) + ' en ' + (people === 1 ? '1 revendedor' : people + ' revendedores'),
			}) +
			kpi({
				icon: 'cash', label: 'Me deben', money: true, className: balance > 0.005 ? 'mj-kpi--year' : 'mj-kpi--received',
				href: MJ.router.link('/consignaciones'),
				value: moneyBase(Math.abs(balance)),
				sub: balance > 0.005 ? 'Cuenta corriente de reventa' : (balance < -0.005 ? 'Saldo a favor de los revendedores' : 'Todos al día'),
			}) +
			kpi({
				icon: pending ? 'warning' : 'check-circle', label: 'Ventas a confirmar',
				className: pending ? 'mj-kpi--danger' : 'mj-kpi--received',
				href: MJ.router.link('/consignaciones'),
				value: MJ.fmt.int(pending),
				sub: pending ? 'Hasta confirmarlas no baja el stock' : 'Nada esperando',
			}) +
		'</div>';
	}

	/**
	 * Fila de ventas: lo que se vendió este mes, el margen y lo que falta cobrar.
	 *
	 * Va arriba de todo porque es la pregunta que se hace todos los días; las compras se miran
	 * cuando llega un contenedor, las ventas se miran siempre.
	 */
	function salesCardHtml(data) {
		const info = data && data.sales;
		if (!info || !info.enabled) return '';

		const stats = info.stats || {};
		const month = stats.month || {};
		const today = stats.today || {};
		const base = String(MJ.setting('base_currency', 'USD')).toUpperCase();
		const moneyBase = function (value) {
			const n = MJ.fmt.toNumber(value);
			if (n === null) return '—';
			const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
			return MJ.fmt.money(n, base, decimals);
		};

		const unpaid = parseInt(info.unpaid_count, 10) || 0;
		const declared = parseInt(info.declared_count, 10) || 0;
		const todayCount = parseInt(today.count, 10) || 0;

		return '<div class="mj-grid mj-grid--3 mj-mb">' +
			kpi({
				icon: 'cash', label: 'Vendido este mes', money: true, className: 'mj-kpi--month',
				href: MJ.router.link('/ventas'),
				value: moneyBase(month.total),
				sub: (num(month.count) === 1 ? '1 venta' : MJ.fmt.int(num(month.count)) + ' ventas') +
					(todayCount ? ' · ' + (todayCount === 1 ? '1 hoy' : todayCount + ' hoy') : ''),
			}) +
			kpi({
				icon: 'chart', label: 'Margen del mes', money: true, className: 'mj-kpi--received',
				href: MJ.router.link('/ventas'),
				value: moneyBase(month.profit),
				sub: 'Precio de venta menos costo landed',
			}) +
			kpi({
				icon: unpaid ? 'warning' : 'check-circle', label: 'Falta cobrar',
				className: unpaid ? 'mj-kpi--danger' : 'mj-kpi--received',
				href: MJ.router.link('/ventas', { cobro: 'impaga' }),
				value: MJ.fmt.int(unpaid),
				sub: declared
					? (declared === 1 ? '1 pago a confirmar' : declared + ' pagos a confirmar')
					: (unpaid ? 'Ventas con saldo pendiente' : 'Todo cobrado'),
			}) +
		'</div>';
	}

	function recentTable(rows) {
		if (!rows || !rows.length) {
			return MJ.ui.empty({
				icon: 'cart',
				title: 'Todavía no hay compras',
				text: 'Registrá tu primera importación para ver acá el resumen.',
				action: { label: 'Nueva compra', icon: 'plus', href: MJ.router.link('/compras', { new: 1 }) },
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Código</th><th>Fecha</th><th>Proveedor</th><th>Estado</th><th class="is-num">Ítems</th><th class="is-num">Total</th>' +
			'</tr></thead><tbody>';
		rows.forEach(function (p) {
			html += '<tr class="is-clickable" data-href="' + h(MJ.router.link('/compras/' + p.id)) + '" tabindex="0">' +
				'<td><span class="mj-strong mj-mono">' + h(p.code) + '</span>' + (p.destination ? '<div class="mj-table__sub mj-muted mj-small">' + h(p.destination) + '</div>' : '') + '</td>' +
				'<td>' + h(MJ.fmt.date(p.purchase_date)) + '</td>' +
				'<td>' + (p.supplier_name ? h(p.supplier_name) : '<span class="mj-muted">—</span>') + '</td>' +
				'<td>' + MJ.ui.badge(p.status, p.status_label) + '</td>' +
				'<td class="is-num">' + h(MJ.fmt.int(p.items_count)) + '</td>' +
				'<td class="is-num mj-money">' + h(MJ.fmt.money(p.total, p.base_currency)) + '</td>' +
			'</tr>';
		});
		html += '</tbody></table></div>';
		return html;
	}

	function statusCounts(counts) {
		const statuses = MJ.state.statuses || {};
		const keys = Object.keys(statuses);
		if (!keys.length) return '';
		let html = '<div class="mj-stat-list">';
		let total = 0;
		keys.forEach(function (k) {
			const n = num(counts && counts[k]);
			total += n;
			html += '<a class="mj-stat-list__row" href="' + h(MJ.router.link('/compras', { status: k })) + '">' +
				'<span>' + MJ.ui.badge(k) + '</span><span class="mj-num mj-strong">' + h(MJ.fmt.int(n)) + '</span></a>';
		});
		html += '<div class="mj-stat-list__row mj-stat-list__row--total"><span>Total de compras</span><span class="mj-num mj-strong">' + h(MJ.fmt.int(total)) + '</span></div>';
		html += '</div>';
		return html;
	}

	function build(data, extra) {
		const stats = (data && data.stats) || {};
		const counts = stats.counts || {};
		const month = stats.month || {};
		const year = stats.year || {};
		const base = MJ.setting('base_currency', 'USD');
		const user = MJ.state.user || {};
		const firstName = String(user.name || '').trim().split(/\s+/)[0] || '';
		const now = new Date();
		const monthName = (function () {
			try { return new Intl.DateTimeFormat(MJ.fmt.locale(), { month: 'long' }).format(now); } catch (e) { return ''; }
		})();
		const inTransitCount = num(counts.en_transito);
		const receivedMonth = extra && extra.receivedMonth !== undefined ? extra.receivedMonth : null;

		let html = '<div class="mj-page-header">' +
			'<div><h2 class="mj-page-header__title">' + h(firstName ? 'Hola, ' + firstName : 'Inicio') + '</h2>' +
			'<div class="mj-page-header__subtitle mj-muted">Resumen de tus importaciones · ' + h(MJ.fmt.date(MJ.fmt.today())) + '</div></div>' +
			'<div class="mj-page-header__actions">' +
				'<a class="mj-btn mj-btn--primary" href="' + h(MJ.router.link('/compras', { new: 1 })) + '">' + MJ.icon('plus') + '<span>Nueva compra</span></a>' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="refresh" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
			'</div>' +
		'</div>';

		html += '<div class="mj-grid mj-grid--4 mj-mb">' +
			kpi({
				icon: 'truck', label: 'En tránsito', money: true, className: 'mj-kpi--transit', href: MJ.router.link('/compras', { status: 'en_transito' }),
				value: MJ.fmt.money(stats.in_transit_total, base),
				sub: inTransitCount === 1 ? '1 compra en camino' : MJ.fmt.int(inTransitCount) + ' compras en camino',
			}) +
			kpi({
				icon: 'package', label: 'Recibidas este mes', className: 'mj-kpi--received', href: MJ.router.link('/compras', { status: 'recibida' }),
				valueHtml: receivedMonth === null ? '<span class="mj-muted" data-received-month>…</span>' : '<span data-received-month>' + h(MJ.fmt.int(receivedMonth)) + '</span>',
				sub: MJ.fmt.int(num(counts.recibida)) + ' recibidas en total',
			}) +
			kpi({
				icon: 'cart', label: 'Invertido este mes' + (monthName ? ' (' + monthName + ')' : ''), money: true, className: 'mj-kpi--month',
				value: MJ.fmt.money(month.total, base),
				sub: (num(month.count) === 1 ? '1 compra' : MJ.fmt.int(num(month.count)) + ' compras') + ' · sin canceladas',
			}) +
			kpi({
				icon: 'chart', label: 'Invertido en ' + now.getFullYear(), money: true, className: 'mj-kpi--year',
				value: MJ.fmt.money(year.total, base),
				sub: (num(year.count) === 1 ? '1 compra' : MJ.fmt.int(num(year.count)) + ' compras') + ' · FOB + gastos',
			}) +
		'</div>';

		html += salesCardHtml(data);
		html += consignmentCardHtml(data);

		html += '<div class="mj-grid mj-grid--sidebar">' +
			'<div class="mj-card mj-card--flush">' +
				'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('clock') + '<span>Últimas compras</span></h3>' +
					'<a class="mj-link mj-small" href="' + h(MJ.router.link('/compras')) + '">Ver todas ' + MJ.icon('chevron-right') + '</a></div>' +
				'<div class="mj-card__body" data-recent>' + recentTable(data && data.recent_purchases) + '</div>' +
			'</div>' +
			'<aside class="mj-stack">' +
				'<div class="mj-card">' +
					'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('zap') + '<span>Accesos rápidos</span></h3></div>' +
					'<div class="mj-card__body mj-stack">' +
						'<a class="mj-btn mj-btn--primary mj-btn--block" href="' + h(MJ.router.link('/ventas/nueva')) + '">' + MJ.icon('cash') + '<span>Nueva venta</span></a>' +
						'<a class="mj-btn mj-btn--block" href="' + h(MJ.router.link('/compras', { new: 1 })) + '">' + MJ.icon('plus') + '<span>Nueva compra</span></a>' +
						'<a class="mj-btn mj-btn--block" href="' + h(MJ.router.link('/ventas')) + '">' + MJ.icon('list') + '<span>Ver ventas</span></a>' +
						'<a class="mj-btn mj-btn--block" href="' + h(MJ.router.link('/proveedores')) + '">' + MJ.icon('users') + '<span>Proveedores</span></a>' +
						'<a class="mj-btn mj-btn--block" href="' + h(MJ.router.link('/productos')) + '">' + MJ.icon('box') + '<span>Productos y costos</span></a>' +
						'<a class="mj-btn mj-btn--block" href="' + h(MJ.router.link('/configuracion')) + '">' + MJ.icon('settings') + '<span>Configuración</span></a>' +
					'</div>' +
				'</div>' +
				'<div class="mj-card">' +
					'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('list') + '<span>Compras por estado</span></h3></div>' +
					'<div class="mj-card__body">' + (statusCounts(counts) || '<div class="mj-muted mj-small">Sin datos.</div>') + '</div>' +
				'</div>' +
				'<div class="mj-note mj-small mj-muted">Los montos están en ' + h(base) + ' (moneda base). El TC de venta configurado es ' + h(MJ.fmt.rate(MJ.setting('sale_rate', 1))) + '.</div>' +
			'</aside>' +
		'</div>';
		return html;
	}

	MJ.pages.dashboard = {
		title: 'Inicio',
		render(container) {
			let disposed = false;
			let receivedMonth = null;
			const offs = [];

			function paint(data) {
				if (disposed) return;
				container.innerHTML = build(data, { receivedMonth: receivedMonth });
			}

			function loadReceivedMonth() {
				MJ.api.get('purchases', { status: 'recibida', date_from: firstOfMonth(), date_to: MJ.fmt.today(), per_page: 1, page: 1 }).then(function (res) {
					if (disposed) return;
					receivedMonth = res && res.total !== undefined ? num(res.total) : 0;
					const node = container.querySelector('[data-received-month]');
					if (node) { node.textContent = MJ.fmt.int(receivedMonth); node.classList.remove('mj-muted'); }
				}).catch(function () {
					if (disposed) return;
					const node = container.querySelector('[data-received-month]');
					if (node) node.textContent = '—';
				});
			}

			function refresh(showToast) {
				return MJ.bootstrap(true).then(function (data) {
					if (disposed) return;
					paint(data);
					loadReceivedMonth();
					if (showToast) MJ.ui.toast('Datos actualizados.', 'success', 1800);
				}).catch(function (err) {
					if (disposed) return;
					if (!MJ.state.bootstrap) {
						container.innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar el resumen.'), 'danger') +
							'<div class="mj-mt"><button type="button" class="mj-btn" data-action="refresh">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>';
					} else {
						MJ.ui.toastError(err, 'No se pudo actualizar el resumen.');
					}
				});
			}

			if (MJ.state.bootstrap) {
				paint(MJ.state.bootstrap);
				loadReceivedMonth();
				refresh(false);
			} else {
				MJ.ui.loading(container, 'Cargando resumen…');
				refresh(false);
			}

			offs.push(MJ.ui.on(container, 'click', '[data-action="refresh"]', function (e, btn) {
				e.preventDefault();
				MJ.ui.setLoading(btn, true);
				refresh(true).then(function () { MJ.ui.setLoading(btn, false); });
			}));
			offs.push(MJ.ui.on(container, 'click', '[data-href]', function (e, row) {
				if (e.target.closest('a, button')) return;
				e.preventDefault();
				MJ.router.navigate(row.getAttribute('data-href'));
			}));
			offs.push(MJ.ui.on(container, 'keydown', '[data-href]', function (e, row) {
				if (e.key === 'Enter' && e.target === row) { e.preventDefault(); MJ.router.navigate(row.getAttribute('data-href')); }
			}));

			return function cleanup() {
				disposed = true;
				offs.forEach(function (off) { off(); });
			};
		},
	};
})();
