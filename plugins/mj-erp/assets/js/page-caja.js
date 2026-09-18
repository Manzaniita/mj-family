/**
 * MJ ERP — page-caja.js
 * MJ.pages.caja: saldo y movimientos de cada caja, movimientos a mano, transferencias y cierres.
 *
 * El libro de una caja se arma en el servidor con lo que ya está anotado en ventas, cobranzas,
 * compras y reintegros, más lo que se carga desde acá. Borrar un movimiento o deshacer un cierre
 * lo hace solo quien puede corregir ventas.
 *
 * REST: GET /cash, GET|POST /cash/{id}/movements, POST /cash/transfer, DELETE /cash-movements/{id},
 *       GET|POST /cash/{id}/closures, DELETE /cash-closures/{id}.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PER_PAGE = 50;

	function num(v) { const n = MJ.fmt.toNumber(v); return n === null ? 0 : n; }
	function dash() { return '<span class="mj-muted">—</span>'; }
	function cashInfo() { return (MJ.state.bootstrap && MJ.state.bootstrap.cash) || {}; }
	function money(v, cur) {
		const n = num(v);
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, cur, decimals);
	}
	function signedHtml(v, cur) {
		const n = num(v);
		const cls = n < -0.004 ? 'mj-danger-text' : (n > 0.004 ? 'mj-success-text' : '');
		return '<span class="mj-money ' + cls + '">' + h((n > 0.004 ? '+' : '') + money(n, cur)) + '</span>';
	}
	function firstOfMonth() { return MJ.fmt.today().slice(0, 8) + '01'; }

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

	function boxCardHtml(box, active) {
		const others = Object.keys(box.others || {});
		return '<div class="mj-card mj-kpi' + (active ? ' mj-kpi--month' : '') + '" data-box="' + h(box.id) + '" role="button" tabindex="0" title="Ver los movimientos de ' + h(box.name) + '">' +
			'<div class="mj-kpi__label">' + MJ.icon('dollar') + '<span>' + h(box.name) + '</span>' +
				(box.closed_today ? ' ' + MJ.ui.badgeRaw('Cerrada hoy', 'success') : '') + '</div>' +
			'<div class="mj-kpi__value mj-money' + (num(box.balance) < -0.004 ? ' mj-danger-text' : '') + '">' + h(money(box.balance, box.currency)) + '</div>' +
			(others.length
				? '<div class="mj-kpi__local mj-muted mj-money">' + others.map(function (c) { return h(money(box.others[c], c)); }).join(' · ') + '</div>'
				: '') +
			'<div class="mj-kpi__sub mj-muted">Hoy: ' + h('+' + money(box.today_in, box.currency)) + ' · ' + h('−' + money(box.today_out, box.currency)) + '</div>' +
		'</div>';
	}

	function boxesHtml(state) {
		if (!state.summary) return loadingHtml('Cargando cajas…');
		const boxes = state.summary.cashboxes || [];
		if (!boxes.length) {
			return MJ.ui.empty({
				icon: 'dollar',
				title: 'No hay cajas',
				text: 'Creá las cajas en Proveedores → Cajas (pesos, dólares, la cuenta del banco…) y asignale una a cada medio de pago en Configuración → Ventas.',
			});
		}
		const cols = Math.min(4, Math.max(2, boxes.length));
		return '<div class="mj-grid mj-grid--' + cols + ' mj-mb" data-region="boxes-grid">' +
			boxes.map(function (b) { return boxCardHtml(b, b.id === state.boxId); }).join('') +
		'</div>';
	}

	function toolbarHtml(uid, state) {
		const sources = (state.summary && state.summary.sources) || {};
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
				'<div class="mj-field" style="flex:1 1 220px">' +
					'<label class="mj-field__label" for="' + uid + '-source">Origen</label>' +
					'<select class="mj-select" id="' + uid + '-source" name="source">' +
						'<option value="">Todos</option>' +
						Object.keys(sources).map(function (k) { return '<option value="' + h(k) + '"' + (state.source === k ? ' selected' : '') + '>' + h(sources[k]) + '</option>'; }).join('') +
					'</select>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 0 auto;align-self:flex-end">' +
					'<div class="mj-btn-group">' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="month">Este mes</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="today">Hoy</button>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-range="all">Todo</button>' +
					'</div>' +
				'</div>' +
			'</form>' +
		'</div></div>';
	}

	function periodTotalsHtml(state) {
		const res = state.ledger;
		if (!res || !res.cashbox) return '';
		const cur = res.cashbox.currency;
		const t = (res.totals && res.totals.by_currency && res.totals.by_currency[cur]) || { income: 0, expense: 0, net: 0 };
		const balance = (res.balances && res.balances[cur]) || 0;
		return '<div class="mj-grid mj-grid--4 mj-mb">' +
			'<div class="mj-card mj-kpi mj-kpi--received"><div class="mj-kpi__label">' + MJ.icon('arrow-right') + '<span>Entró en el período</span></div><div class="mj-kpi__value mj-money">' + h(money(t.income, cur)) + '</div><div class="mj-kpi__sub mj-muted">' + h(res.cashbox.name) + '</div></div>' +
			'<div class="mj-card mj-kpi mj-kpi--danger"><div class="mj-kpi__label">' + MJ.icon('arrow-left') + '<span>Salió en el período</span></div><div class="mj-kpi__value mj-money">' + h(money(t.expense, cur)) + '</div><div class="mj-kpi__sub mj-muted">Gastos, reintegros, devoluciones, retiros</div></div>' +
			'<div class="mj-card mj-kpi"><div class="mj-kpi__label">' + MJ.icon('chart') + '<span>Neto del período</span></div><div class="mj-kpi__value">' + signedHtml(t.net, cur) + '</div><div class="mj-kpi__sub mj-muted">' + h(MJ.fmt.int(res.total)) + (res.total === 1 ? ' movimiento' : ' movimientos') + '</div></div>' +
			'<div class="mj-card mj-kpi mj-kpi--month"><div class="mj-kpi__label">' + MJ.icon('dollar') + '<span>Saldo actual</span></div><div class="mj-kpi__value mj-money' + (balance < -0.004 ? ' mj-danger-text' : '') + '">' + h(money(balance, cur)) + '</div><div class="mj-kpi__sub mj-muted">Todo el historial de la caja</div></div>' +
		'</div>';
	}

	function rowHtml(row, canManage) {
		const link = row.link ? '<a class="mj-link" href="' + h(MJ.router.link(row.link)) + '">' + h(row.label) + '</a>' : h(row.label);
		return '<tr data-uid="' + h(row.uid) + '">' +
			'<td><div class="mj-strong">' + h(MJ.fmt.date(row.date)) + '</div><div class="mj-small mj-muted">' + h(row.source_label) + '</div></td>' +
			'<td><div class="mj-strong">' + link + '</div>' +
				(row.detail && row.detail !== row.label ? '<div class="mj-small mj-muted">' + h(row.detail) + '</div>' : '') +
				(row.note && row.note !== row.detail ? '<div class="mj-small">' + h(row.note) + '</div>' : '') +
			'</td>' +
			'<td>' + (row.method_label ? h(row.method_label) : dash()) + (row.reference ? '<div class="mj-small mj-muted mj-mono">' + h(row.reference) + '</div>' : '') + '</td>' +
			'<td class="mj-text-right">' + signedHtml(row.amount, row.currency) + '</td>' +
			'<td class="mj-small mj-muted">' + (row.created_by_name ? h(row.created_by_name) : dash()) + '</td>' +
			'<td class="is-actions">' +
				(row.deletable && canManage
					? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-movement" data-id="' + h(row.ref_id) + '" title="' + h(row.transfer_id ? 'Borrar la transferencia (las dos cajas)' : 'Borrar') + '" aria-label="Borrar movimiento">' + MJ.icon('trash') + '</button>'
					: '') +
			'</td>' +
		'</tr>';
	}

	function tableHtml(state) {
		if (state.error) {
			return '<div class="mj-card__body">' +
				MJ.ui.alert(MJ.api.message(state.error, 'No se pudo cargar el libro de la caja.'), 'danger') +
				'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
			'</div>';
		}
		if (!state.boxId) {
			return '<div class="mj-card__body">' + MJ.ui.alert('Elegí una caja arriba para ver sus movimientos.', 'info') + '</div>';
		}
		if (!state.ledger) return loadingHtml('Cargando movimientos…');
		const res = state.ledger;
		if (!res.items.length) {
			return MJ.ui.empty({
				icon: 'inbox',
				title: 'Sin movimientos en el período',
				text: 'Los cobros, cobranzas, gastos de compras y reintegros con esta caja aparecen acá solos. Lo que no viene de otra pantalla se carga con «Ingreso» o «Egreso».',
			});
		}
		const canManage = !!(state.summary && state.summary.can_manage);
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Fecha</th><th>Movimiento</th><th>Medio</th><th class="mj-text-right">Importe</th><th>Quién</th><th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' + res.items.map(function (r) { return rowHtml(r, canManage); }).join('') + '</tbody></table></div>';
		if (res.pages > 1) {
			html += '<div class="mj-card__footer mj-row mj-row--between">' +
				'<span class="mj-small mj-muted">Página ' + MJ.fmt.int(res.page) + ' de ' + MJ.fmt.int(res.pages) + '</span>' +
				'<span class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="prev"' + (res.page <= 1 ? ' disabled' : '') + '>' + MJ.icon('chevron-left') + '<span>Anterior</span></button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="next"' + (res.page >= res.pages ? ' disabled' : '') + '>' + MJ.icon('chevron-right') + '<span>Siguiente</span></button>' +
				'</span>' +
			'</div>';
		}
		return html;
	}

	function closuresHtml(state) {
		if (!state.boxId || !state.summary || !state.summary.closure_enabled) return '';
		const list = state.closures;
		const cur = state.ledger && state.ledger.cashbox ? state.ledger.cashbox.currency : '';
		const canManage = !!state.summary.can_manage;
		let body;
		if (!list) body = loadingHtml('Cargando cierres…');
		else if (!list.length) body = '<div class="mj-card__body"><p class="mj-muted mj-small">Todavía no hay cierres de esta caja. «Cerrar caja» cuenta lo que hay y anota la diferencia contra lo esperado.</p></div>';
		else {
			body = '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
				'<th>Día</th><th class="mj-text-right">Inicial</th><th class="mj-text-right">Entró</th><th class="mj-text-right">Salió</th><th class="mj-text-right">Esperado</th><th class="mj-text-right">Contado</th><th class="mj-text-right">Diferencia</th><th>Quién</th><th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
			'</tr></thead><tbody>' + list.map(function (c) {
				const diff = num(c.difference);
				return '<tr data-closure="' + h(c.id) + '">' +
					'<td><div class="mj-strong">' + h(MJ.fmt.date(c.closure_date)) + '</div>' + (c.note ? '<div class="mj-small mj-muted">' + h(c.note) + '</div>' : '') + '</td>' +
					'<td class="mj-text-right mj-money">' + h(money(c.opening_balance, c.currency || cur)) + '</td>' +
					'<td class="mj-text-right mj-money">' + h(money(c.income_total, c.currency || cur)) + '</td>' +
					'<td class="mj-text-right mj-money">' + h(money(c.expense_total, c.currency || cur)) + '</td>' +
					'<td class="mj-text-right mj-money">' + h(money(c.expected_balance, c.currency || cur)) + '</td>' +
					'<td class="mj-text-right mj-money mj-strong">' + h(money(c.counted_balance, c.currency || cur)) + '</td>' +
					'<td class="mj-text-right">' + (Math.abs(diff) < 0.004 ? MJ.ui.badgeRaw('Cuadra', 'success') : signedHtml(diff, c.currency || cur)) + '</td>' +
					'<td class="mj-small mj-muted">' + h(c.closed_by_name || '') + '</td>' +
					'<td class="is-actions">' + (canManage ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete-closure" data-id="' + h(c.id) + '" title="Deshacer el cierre" aria-label="Deshacer el cierre">' + MJ.icon('undo') + '</button>' : '') + '</td>' +
				'</tr>';
			}).join('') + '</tbody></table></div>';
		}
		return '<div class="mj-card mj-card--flush mj-mt">' +
			'<div class="mj-card__header"><h3 class="mj-card__title">Cierres de caja</h3></div>' +
			body +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Modales                                                             */
	/* ------------------------------------------------------------------ */

	function kindOptions(summary) {
		return ((summary && summary.kinds) || []).map(function (k) { return '<option value="' + h(k.key) + '">' + h(k.label) + '</option>'; }).join('');
	}

	/** Ingreso o egreso a mano. */
	function movementModal(state, direction, onSaved) {
		const box = (state.summary.cashboxes || []).find(function (b) { return b.id === state.boxId; });
		if (!box) return;
		const isIn = direction === 'ingreso';
		const uid = 'mj-cash-mov-' + Math.random().toString(36).slice(2, 8);
		const requireReason = state.summary.require_reason !== false;
		const modal = MJ.ui.modal({
			title: (isIn ? 'Ingreso a ' : 'Egreso de ') + box.name,
			size: 'md',
			body:
				'<form class="mj-form" data-form novalidate autocomplete="off">' +
					'<div class="mj-form-row">' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-amount">Importe (' + h(box.currency) + ')</label>' +
							'<input class="mj-input" id="' + uid + '-amount" name="amount" type="text" inputmode="decimal" placeholder="0" required>' +
						'</div>' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-date">Fecha</label>' +
							'<input class="mj-input" id="' + uid + '-date" name="movement_date" type="date" value="' + h(MJ.fmt.today()) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-kind">Tipo</label>' +
						'<select class="mj-select" id="' + uid + '-kind" name="kind">' + kindOptions(state.summary) + '</select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-concept">Concepto' + (requireReason ? '' : ' <span class="mj-muted">(opcional)</span>') + '</label>' +
						'<input class="mj-input" id="' + uid + '-concept" name="concept" type="text" maxlength="255" placeholder="' + h(isIn ? 'Por ejemplo: aporte para cambio' : 'Por ejemplo: bolsas y cinta') + '"' + (requireReason ? ' required' : '') + '>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-ref">Referencia <span class="mj-muted">(opcional)</span></label>' +
						'<input class="mj-input" id="' + uid + '-ref" name="reference" type="text" maxlength="190" placeholder="Nº de comprobante, ticket…">' +
					'</div>' +
					'<div class="mj-form__errors mj-hidden" data-errors></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn ' + (isIn ? 'mj-btn--primary' : 'mj-btn--danger') + '" data-action="save">' + MJ.icon(isIn ? 'plus' : 'minus') + '<span>' + (isIn ? 'Anotar ingreso' : 'Anotar egreso') + '</span></button>',
		});
		const amountEl = qs('[name="amount"]', modal.el);
		if (amountEl) setTimeout(function () { amountEl.focus(); }, 50);

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.api.post('cash/' + box.id + '/movements', {
				direction: direction,
				kind: data.kind,
				amount: data.amount,
				movement_date: data.movement_date,
				concept: data.concept,
				reference: data.reference,
			}).then(function () {
				MJ.ui.toast(isIn ? 'Ingreso anotado.' : 'Egreso anotado.', 'success');
				modal.close();
				onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo anotar el movimiento.');
			});
		});
	}

	/** Pasar plata de una caja a otra. */
	function transferModal(state, onSaved) {
		const boxes = state.summary.cashboxes || [];
		if (boxes.length < 2) { MJ.ui.toast('Hacen falta al menos dos cajas para transferir.', 'warning'); return; }
		const uid = 'mj-cash-tr-' + Math.random().toString(36).slice(2, 8);
		const opts = function (selected) {
			return boxes.map(function (b) { return '<option value="' + h(b.id) + '"' + (b.id === selected ? ' selected' : '') + '>' + h(b.name + ' (' + b.currency + ')') + '</option>'; }).join('');
		};
		const other = boxes.find(function (b) { return b.id !== state.boxId; });
		const modal = MJ.ui.modal({
			title: 'Transferencia entre cajas',
			size: 'md',
			body:
				'<form class="mj-form" data-form novalidate autocomplete="off">' +
					'<div class="mj-form-row">' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-from">Sale de</label>' +
							'<select class="mj-select" id="' + uid + '-from" name="from_id">' + opts(state.boxId) + '</select>' +
						'</div>' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-to">Entra en</label>' +
							'<select class="mj-select" id="' + uid + '-to" name="to_id">' + opts(other ? other.id : 0) + '</select>' +
						'</div>' +
					'</div>' +
					'<div class="mj-form-row">' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-amount">Importe que sale</label>' +
							'<input class="mj-input" id="' + uid + '-amount" name="amount" type="text" inputmode="decimal" placeholder="0" required>' +
						'</div>' +
						'<div class="mj-field mj-hidden" data-amount-to>' +
							'<label class="mj-field__label" for="' + uid + '-amount-to">Importe que entra</label>' +
							'<input class="mj-input" id="' + uid + '-amount-to" name="amount_to" type="text" inputmode="decimal" placeholder="0">' +
							'<div class="mj-field__hint">Las cajas tienen monedas distintas: decí cuánto entra en la de destino.</div>' +
						'</div>' +
					'</div>' +
					'<div class="mj-form-row">' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-date">Fecha</label>' +
							'<input class="mj-input" id="' + uid + '-date" name="movement_date" type="date" value="' + h(MJ.fmt.today()) + '">' +
						'</div>' +
						'<div class="mj-field">' +
							'<label class="mj-field__label" for="' + uid + '-concept">Concepto <span class="mj-muted">(opcional)</span></label>' +
							'<input class="mj-input" id="' + uid + '-concept" name="concept" type="text" maxlength="255" placeholder="Depósito en el banco">' +
						'</div>' +
					'</div>' +
					'<div class="mj-form__errors mj-hidden" data-errors></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('arrow-right') + '<span>Transferir</span></button>',
		});

		function syncCurrencies() {
			const from = boxes.find(function (b) { return String(b.id) === qs('[name="from_id"]', modal.el).value; });
			const to = boxes.find(function (b) { return String(b.id) === qs('[name="to_id"]', modal.el).value; });
			const differ = from && to && from.currency !== to.currency;
			qs('[data-amount-to]', modal.el).classList.toggle('mj-hidden', !differ);
		}
		modal.el.addEventListener('change', function (e) { if (e.target.name === 'from_id' || e.target.name === 'to_id') syncCurrencies(); });
		syncCurrencies();

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.api.post('cash/transfer', data).then(function () {
				MJ.ui.toast('Transferencia anotada en las dos cajas.', 'success');
				modal.close();
				onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo anotar la transferencia.');
			});
		});
	}

	/** Cerrar el día: lo esperado sale del libro, lo contado lo escribe quien cierra. */
	function closureModal(state, onSaved) {
		const box = (state.summary.cashboxes || []).find(function (b) { return b.id === state.boxId; });
		if (!box) return;
		const uid = 'mj-cash-close-' + Math.random().toString(36).slice(2, 8);
		const modal = MJ.ui.modal({
			title: 'Cerrar caja · ' + box.name,
			size: 'md',
			body:
				'<form class="mj-form" data-form novalidate autocomplete="off">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-date">Día que se cierra</label>' +
						'<input class="mj-input" id="' + uid + '-date" name="closure_date" type="date" value="' + h(MJ.fmt.today()) + '" max="' + h(MJ.fmt.today()) + '">' +
					'</div>' +
					'<div data-preview>' + loadingHtml('Calculando lo esperado…') + '</div>' +
					'<div class="mj-field mj-mt">' +
						'<label class="mj-field__label" for="' + uid + '-counted">Lo que contaste (' + h(box.currency) + ')</label>' +
						'<input class="mj-input" id="' + uid + '-counted" name="counted_balance" type="text" inputmode="decimal" placeholder="0" required>' +
						'<div class="mj-field__hint">Lo que hay de verdad en la caja al terminar el día. La diferencia queda anotada, no se corrige sola.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-note">Nota <span class="mj-muted">(opcional)</span></label>' +
						'<input class="mj-input" id="' + uid + '-note" name="note" type="text" maxlength="255">' +
					'</div>' +
					'<div class="mj-form__errors mj-hidden" data-errors></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('lock') + '<span>Cerrar el día</span></button>',
		});

		let preview = null;
		function loadPreview() {
			const date = qs('[name="closure_date"]', modal.el).value;
			const region = qs('[data-preview]', modal.el);
			region.innerHTML = loadingHtml('Calculando lo esperado…');
			MJ.api.get('cash/' + box.id + '/closures', { date: date }).then(function (res) {
				preview = res.preview;
				if (!preview) { region.innerHTML = ''; return; }
				const cur = preview.currency;
				region.innerHTML =
					(preview.existing ? MJ.ui.alert('Ese día ya está cerrado (contado ' + money(preview.existing.counted_balance, cur) + '). Para rehacerlo, deshacé el cierre en la lista.', 'warning') : '') +
					'<div class="mj-stat-list">' +
						'<div class="mj-stat-list__row"><span>Saldo inicial</span><span class="mj-money">' + h(money(preview.opening_balance, cur)) + '</span></div>' +
						'<div class="mj-stat-list__row"><span>Entró</span><span class="mj-money mj-success-text">' + h('+' + money(preview.income_total, cur)) + '</span></div>' +
						'<div class="mj-stat-list__row"><span>Salió</span><span class="mj-money mj-danger-text">' + h('−' + money(preview.expense_total, cur)) + '</span></div>' +
						'<div class="mj-stat-list__row mj-stat-list__row--total"><span>Esperado en caja</span><span class="mj-money">' + h(money(preview.expected_balance, cur)) + '</span></div>' +
					'</div>' +
					'<p class="mj-small mj-muted">' + h(MJ.fmt.int(preview.movements_count)) + (preview.movements_count === 1 ? ' movimiento' : ' movimientos') + ' ese día.</p>';
				const counted = qs('[name="counted_balance"]', modal.el);
				if (counted && !counted.value) counted.placeholder = MJ.fmt.number(preview.expected_balance, 2);
			}).catch(function (err) {
				region.innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudo calcular lo esperado.'), 'danger');
			});
		}
		modal.el.addEventListener('change', function (e) { if (e.target.name === 'closure_date') loadPreview(); });
		loadPreview();

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.api.post('cash/' + box.id + '/closures', data).then(function (closure) {
				const diff = num(closure && closure.difference);
				MJ.ui.toast(Math.abs(diff) < 0.004 ? 'Caja cerrada: cuadra.' : 'Caja cerrada con una diferencia de ' + money(diff, closure.currency) + '.', Math.abs(diff) < 0.004 ? 'success' : 'warning');
				modal.close();
				onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo cerrar la caja.');
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.caja = {
		title() { return 'Caja'; },

		render(container, params, query) {
			if (cashInfo().enabled === false) {
				container.innerHTML = MJ.ui.alert('La Caja está apagada. Se prende en Configuración → Caja y reportes.', 'warning');
				return function () {};
			}

			const uid = 'mj-cash-' + Math.random().toString(36).slice(2, 8);
			const state = {
				summary: null,
				boxId: parseInt(query && query.caja, 10) || 0,
				from: firstOfMonth(),
				to: MJ.fmt.today(),
				source: '',
				page: 1,
				ledger: null,
				closures: null,
				error: null,
				seq: 0,
				disposed: false,
			};

			container.innerHTML =
				'<div class="mj-page-header">' +
					'<div>' +
						'<h2 class="mj-page-header__title">Caja</h2>' +
						'<div class="mj-page-header__subtitle mj-muted">Saldo y movimientos de cada caja. Los cobros, las cobranzas, los gastos de compras y los reintegros entran solos; lo demás se carga acá.</div>' +
					'</div>' +
					'<div class="mj-page-header__actions" data-region="actions"></div>' +
				'</div>' +
				'<div data-region="boxes"></div>' +
				'<div data-region="period"></div>' +
				'<div data-region="toolbar"></div>' +
				'<div class="mj-card mj-card--flush"><div data-region="table">' + loadingHtml('Cargando…') + '</div></div>' +
				'<div data-region="closures"></div>';

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			function paintActions() {
				const s = state.summary;
				if (!s || !state.boxId) { region('actions').innerHTML = '<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>'; return; }
				region('actions').innerHTML =
					'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
					'<button type="button" class="mj-btn" data-action="income">' + MJ.icon('plus') + '<span>Ingreso</span></button>' +
					'<button type="button" class="mj-btn" data-action="expense">' + MJ.icon('minus') + '<span>Egreso</span></button>' +
					((s.cashboxes || []).length > 1 ? '<button type="button" class="mj-btn mj-btn--ghost" data-action="transfer">' + MJ.icon('arrow-right') + '<span>Transferir</span></button>' : '') +
					(s.closure_enabled ? '<button type="button" class="mj-btn mj-btn--primary" data-action="close">' + MJ.icon('lock') + '<span>Cerrar caja</span></button>' : '');
			}

			function paintBoxes() {
				region('boxes').innerHTML = boxesHtml(state);
			}

			function paintLedger() {
				region('period').innerHTML = periodTotalsHtml(state);
				region('table').innerHTML = tableHtml(state);
				region('closures').innerHTML = closuresHtml(state);
			}

			function loadSummary() {
				return MJ.api.get('cash').then(function (res) {
					if (state.disposed) return;
					state.summary = res;
					const boxes = res.cashboxes || [];
					if (!state.boxId || !boxes.some(function (b) { return b.id === state.boxId; })) {
						state.boxId = boxes.length ? boxes[0].id : 0;
					}
					// El inicio muestra los mismos saldos: se ponen al día sin recargar.
					if (MJ.state.bootstrap && MJ.state.bootstrap.cash) MJ.state.bootstrap.cash.balances = boxes;
					paintBoxes();
					paintActions();
					if (!qs('[data-filters]', container)) region('toolbar').innerHTML = toolbarHtml(uid, state);
				});
			}

			function loadLedger() {
				if (state.disposed || !state.boxId) { paintLedger(); return; }
				const mine = ++state.seq;
				state.ledger = null;
				state.closures = null;
				paintLedger();
				const params = { page: state.page, per_page: PER_PAGE };
				if (state.from) params.date_from = state.from;
				if (state.to) params.date_to = state.to;
				if (state.source) params.source = state.source;
				const wantClosures = state.summary && state.summary.closure_enabled;
				Promise.all([
					MJ.api.get('cash/' + state.boxId + '/movements', params),
					wantClosures ? MJ.api.get('cash/' + state.boxId + '/closures') : Promise.resolve(null),
				]).then(function (results) {
					if (state.disposed || mine !== state.seq) return;
					state.error = null;
					state.ledger = results[0];
					state.closures = results[1] ? (results[1].items || []) : [];
					paintLedger();
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = err;
					paintLedger();
				});
			}

			function reloadAll() {
				loadSummary().then(loadLedger).catch(function (err) {
					if (state.disposed) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = err;
					region('boxes').innerHTML = '';
					paintLedger();
				});
			}

			container.addEventListener('change', function (e) {
				const n = e.target.name;
				if (n === 'date_from') { state.from = e.target.value; state.page = 1; loadLedger(); }
				else if (n === 'date_to') { state.to = e.target.value; state.page = 1; loadLedger(); }
				else if (n === 'source') { state.source = e.target.value; state.page = 1; loadLedger(); }
			});

			function setRange(kind) {
				if (kind === 'today') { state.from = MJ.fmt.today(); state.to = MJ.fmt.today(); }
				else if (kind === 'all') { state.from = ''; state.to = ''; }
				else { state.from = firstOfMonth(); state.to = MJ.fmt.today(); }
				const f = qs('[name="date_from"]', container); if (f) f.value = state.from;
				const t = qs('[name="date_to"]', container); if (t) t.value = state.to;
				state.page = 1;
				loadLedger();
			}

			container.addEventListener('keydown', function (e) {
				const card = e.target.closest ? e.target.closest('[data-box]') : null;
				if (card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); card.click(); }
			});

			container.addEventListener('click', function (e) {
				const card = e.target.closest ? e.target.closest('[data-box]') : null;
				if (card) {
					const id = parseInt(card.getAttribute('data-box'), 10) || 0;
					if (id && id !== state.boxId) { state.boxId = id; state.page = 1; paintBoxes(); paintActions(); loadLedger(); }
					return;
				}
				const rangeBtn = e.target.closest ? e.target.closest('[data-range]') : null;
				if (rangeBtn) { setRange(rangeBtn.getAttribute('data-range')); return; }

				const btn = e.target.closest ? e.target.closest('[data-action]') : null;
				if (!btn) return;
				const action = btn.getAttribute('data-action');
				if (action === 'reload') { reloadAll(); return; }
				if (action === 'prev') { if (state.page > 1) { state.page--; loadLedger(); } return; }
				if (action === 'next') { if (state.ledger && state.page < state.ledger.pages) { state.page++; loadLedger(); } return; }
				if (!state.summary) return;
				if (action === 'income') { movementModal(state, 'ingreso', reloadAll); return; }
				if (action === 'expense') { movementModal(state, 'egreso', reloadAll); return; }
				if (action === 'transfer') { transferModal(state, reloadAll); return; }
				if (action === 'close') { closureModal(state, reloadAll); return; }
				if (action === 'delete-movement') {
					const id = parseInt(btn.getAttribute('data-id'), 10) || 0;
					MJ.ui.confirm({
						title: 'Borrar movimiento',
						message: 'Se borra del libro de la caja. Si es una transferencia, se borran las dos patas. No se puede deshacer.',
						okLabel: 'Borrar',
						danger: true,
					}).then(function (ok) {
						if (!ok) return;
						MJ.api.del('cash-movements/' + id).then(function () { MJ.ui.toast('Movimiento borrado.', 'success'); reloadAll(); })
							.catch(function (err) { toastError(err, 'No se pudo borrar el movimiento.'); });
					});
					return;
				}
				if (action === 'delete-closure') {
					const id = parseInt(btn.getAttribute('data-id'), 10) || 0;
					MJ.ui.confirm({
						title: 'Deshacer el cierre',
						message: 'El día vuelve a quedar abierto y se puede cerrar de nuevo.',
						okLabel: 'Deshacer',
						danger: true,
					}).then(function (ok) {
						if (!ok) return;
						MJ.api.del('cash-closures/' + id).then(function () { MJ.ui.toast('Cierre deshecho.', 'success'); reloadAll(); })
							.catch(function (err) { toastError(err, 'No se pudo deshacer el cierre.'); });
					});
				}
			});

			reloadAll();

			return function () { state.disposed = true; };
		},
	};
})();
