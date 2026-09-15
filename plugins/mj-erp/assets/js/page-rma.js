/**
 * MJ ERP — page-rma.js
 * MJ.pages.rma: lo que volvió fallado de una venta y espera el reclamo al proveedor.
 *
 * Cada RMA termina de una de dos formas: **volvió al stock** (el proveedor lo cambió o se arregló) o
 * **se dio de baja**. Recién ahí su costo pasa a ser pérdida por garantías, que se cuenta aparte del
 * margen de las ventas. Lo maneja solo quien puede corregir ventas.
 *
 * REST: GET /rma, PUT /rma/{id}, POST /rma/{id}/resolve.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const SEARCH_DELAY = 350;
	const PER_PAGE = 30;
	const STATUS_OPEN = 'abierto';
	const STATUS_RESTOCKED = 'en_stock';
	const STATUS_WRITTEN_OFF = 'baja';

	function num(v) { return MJ.fmt.toNumber(v); }
	function dash() { return '<span class="mj-muted">—</span>'; }
	function salesInfo() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function baseCur() { return MJ.setting('base_currency', 'USD'); }
	function moneyBase(v) { return MJ.fmt.money(num(v) || 0, baseCur()); }
	// Los pesos al dólar de hoy, solo como referencia.
	function localToday(v) { const l = MJ.fmt.toLocal(num(v) || 0); return l === null ? '' : MJ.fmt.moneyLocal(l, 0); }

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

	function kpiHtml(c) {
		return '<div class="mj-card mj-kpi' + (c.className ? ' ' + c.className : '') + '">' +
			'<div class="mj-kpi__label">' + MJ.icon(c.icon) + '<span>' + h(c.label) + '</span></div>' +
			'<div class="mj-kpi__value mj-money">' + h(c.value) + '</div>' +
			(c.local ? '<div class="mj-kpi__local mj-muted mj-money">' + h(c.local) + '</div>' : '') +
			'<div class="mj-kpi__sub mj-muted">' + h(c.sub || '') + '</div>' +
		'</div>';
	}

	function totalsHtml(t) {
		if (!t) return '';
		const open = num(t.open_count) || 0;
		const lossMonth = num(t.loss_month_base) || 0;
		return '<div class="mj-grid mj-grid--4 mj-mb">' + [
			{ icon: 'archive', label: 'Abiertos', value: MJ.fmt.int(open), sub: MJ.fmt.qty(t.open_qty || 0) + ' unidades esperando el reclamo', className: open > 0 ? 'mj-kpi--transit' : '' },
			{ icon: 'box', label: 'Costo en RMA', value: moneyBase(t.open_cost_base), local: localToday(t.open_cost_base), sub: 'Todavía no es pérdida' },
			{ icon: 'x-circle', label: 'Pérdida por garantías · mes', value: moneyBase(lossMonth), local: localToday(lossMonth), sub: 'Lo dado de baja este mes', className: lossMonth > 0 ? 'mj-kpi--danger' : '' },
			{ icon: 'chart', label: 'Pérdida por garantías · año', value: moneyBase(t.loss_year_base), local: localToday(t.loss_year_base), sub: 'Desde siempre: ' + moneyBase(t.loss_total_base) },
		].map(kpiHtml).join('') + '</div>';
	}

	function toolbarHtml(uid, state) {
		return '<div class="mj-card mj-mb">' +
			'<div class="mj-card__body">' +
				'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-filters novalidate autocomplete="off">' +
					'<div class="mj-field" style="flex:2 1 260px">' +
						'<label class="mj-field__label" for="' + uid + '-search">Buscar</label>' +
						'<div class="mj-input-group">' +
							'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
							'<input class="mj-input" id="' + uid + '-search" type="search" name="search" placeholder="Código, producto, SKU, proveedor o venta…" value="' + h(state.search) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 190px">' +
						'<label class="mj-field__label" for="' + uid + '-status">Estado</label>' +
						'<select class="mj-select" id="' + uid + '-status" name="status"></select>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 220px">' +
						'<label class="mj-field__label" for="' + uid + '-supplier">Proveedor</label>' +
						'<select class="mj-select" id="' + uid + '-supplier" name="supplier_id"></select>' +
					'</div>' +
				'</form>' +
			'</div>' +
		'</div>';
	}

	function rowHtml(row) {
		const open = row.status === STATUS_OPEN;
		const cost = num(row.cost_base) || 0;
		const local = localToday(cost);
		return '<tr data-id="' + h(row.id) + '">' +
			'<td>' +
				'<div class="mj-strong mj-mono">' + h(row.code) + '</div>' +
				'<div class="mj-small mj-muted">' + h(MJ.fmt.date(row.created_at)) + '</div>' +
			'</td>' +
			'<td>' +
				'<div class="mj-strong">' + h(row.name) + '</div>' +
				(row.sku ? '<div class="mj-small mj-muted">SKU ' + h(row.sku) + '</div>' : '') +
				(row.problem ? '<div class="mj-small">' + h(row.problem) + '</div>' : '') +
			'</td>' +
			'<td class="mj-text-right">' + h(MJ.fmt.qty(row.qty)) + '</td>' +
			'<td class="mj-text-right">' +
				(cost > 0
					? '<span class="mj-money-pair"><span class="mj-money-pair__base">' + h(moneyBase(cost)) + '</span>' + (local ? '<span class="mj-money-pair__local">' + h(local) + '</span>' : '') + '</span>'
					: dash()) +
			'</td>' +
			'<td>' + (row.supplier_name ? h(row.supplier_name) : dash()) + '</td>' +
			'<td>' + (row.sale_id ? '<a class="mj-link" href="' + h(MJ.router.link('/ventas/' + row.sale_id)) + '">' + h(row.sale_code || ('#' + row.sale_id)) + '</a>' : dash()) + '</td>' +
			'<td>' +
				MJ.ui.badgeRaw(row.status_label || row.status, row.status_color || 'neutral') +
				(!open && row.resolved_at
					? '<div class="mj-small mj-muted">' + h(MJ.fmt.date(row.resolved_at)) + (row.resolved_by_name ? ' · ' + h(row.resolved_by_name) : '') + '</div>'
					: '') +
				(row.resolution_note ? '<div class="mj-small mj-muted">' + h(row.resolution_note) + '</div>' : '') +
			'</td>' +
			'<td class="is-actions">' +
				'<div class="mj-btn-group">' +
					(open
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="restock" title="Volvió al stock">' + MJ.icon('undo') + '<span>Al stock</span></button>' +
							'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-danger-text" data-action="write-off" title="Dar de baja">' + MJ.icon('x-circle') + '<span>Baja</span></button>'
						: '') +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit" title="Editar" aria-label="' + h('Editar ' + row.code) + '">' + MJ.icon('edit') + '</button>' +
				'</div>' +
			'</td>' +
		'</tr>';
	}

	function tableHtml(state) {
		if (state.error) {
			return '<div class="mj-card__body">' +
				MJ.ui.alert(MJ.api.message(state.error, 'No se pudo cargar la lista de RMA.'), 'danger') +
				'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
			'</div>';
		}
		if (!state.rows) return loadingHtml('Cargando…');
		if (!state.rows.length) {
			return MJ.ui.empty({
				icon: 'archive',
				title: state.status === STATUS_OPEN ? 'Nada esperando reclamo' : 'Sin resultados',
				text: state.status === STATUS_OPEN
					? 'Lo que vuelva fallado en una devolución aparece acá hasta que vuelva al stock o se dé de baja.'
					: 'Ningún RMA coincide con los filtros.',
			});
		}

		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>RMA</th><th>Producto</th><th class="mj-text-right">Cant.</th><th class="mj-text-right">Costo</th>' +
			'<th>Proveedor</th><th>Venta</th><th>Estado</th><th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' + state.rows.map(rowHtml).join('') + '</tbody></table></div>';

		if (state.pages > 1) {
			html += '<div class="mj-card__footer mj-row mj-row--between">' +
				'<span class="mj-small mj-muted">Página ' + MJ.fmt.int(state.page) + ' de ' + MJ.fmt.int(state.pages) + '</span>' +
				'<span class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="prev"' + (state.page <= 1 ? ' disabled' : '') + '>' + MJ.icon('chevron-left') + '<span>Anterior</span></button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="next"' + (state.page >= state.pages ? ' disabled' : '') + '>' + MJ.icon('chevron-right') + '<span>Siguiente</span></button>' +
				'</span>' +
			'</div>';
		}
		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Modales                                                             */
	/* ------------------------------------------------------------------ */

	/** Proveedor al que se reclama, el problema y una nota. */
	function editModal(row, onSaved) {
		const uid = 'mj-rma-edit-' + row.id;
		const modal = MJ.ui.modal({
			title: 'RMA ' + row.code,
			size: 'md',
			body:
				'<form class="mj-form" data-form novalidate autocomplete="off">' +
					'<p class="mj-muted mj-small">' + h(MJ.fmt.qty(row.qty) + ' × ' + row.name) + '</p>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-supplier">Proveedor al que se reclama</label>' +
						'<select class="mj-select" id="' + uid + '-supplier" name="supplier_id"></select>' +
						'<div class="mj-field__hint">Viene del último que te lo vendió; cambialo si corresponde a otro.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-problem">Problema</label>' +
						'<input class="mj-input" id="' + uid + '-problem" name="problem" type="text" maxlength="255" value="' + h(row.problem) + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + uid + '-note">Nota</label>' +
						'<textarea class="mj-textarea" id="' + uid + '-note" name="note" rows="3" placeholder="Nº de reclamo, con quién hablaste…">' + h(row.note) + '</textarea>' +
					'</div>' +
					'<div class="mj-form__errors mj-hidden" data-errors></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('save') + '<span>Guardar</span></button>',
		});

		let supplier = null;
		try {
			supplier = MJ.components.supplierSelect({
				select: qs('[name="supplier_id"]', modal.el),
				value: row.supplier_id || '',
				supplier: row.supplier_id ? { id: row.supplier_id, name: row.supplier_name } : null,
				emptyLabel: 'Sin proveedor',
			});
		} catch (e) {
			console.error('[MJ] supplierSelect', e);
		}

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.api.put('rma/' + row.id, {
				supplier_id: supplier ? supplier.getValue() : data.supplier_id,
				problem: data.problem,
				note: data.note,
			}).then(function () {
				if (supplier) supplier.destroy();
				MJ.ui.toast('RMA guardado.', 'success');
				modal.close();
				onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo guardar el RMA.');
			});
		});
	}

	/** Cerrar un RMA: volvió al stock o se da de baja. */
	function resolveModal(row, status, onSaved) {
		const restock = status === STATUS_RESTOCKED;
		const uid = 'mj-rma-resolve-' + row.id;
		const modal = MJ.ui.modal({
			title: (restock ? 'Volvió al stock · ' : 'Dar de baja · ') + row.code,
			size: 'md',
			body:
				'<form class="mj-form" data-form novalidate autocomplete="off">' +
					(restock
						? MJ.ui.alert('Entran ' + MJ.fmt.qty(row.qty) + ' unidades de ' + row.name + ' al stock. No es pérdida.', 'info')
						: MJ.ui.alert('Se da de baja: su costo (' + moneyBoth(row.cost_base) + ') pasa a ser pérdida por garantías.', 'warning')) +
					'<div class="mj-field mj-mt">' +
						'<label class="mj-field__label" for="' + uid + '-note">Nota <span class="mj-muted">(opcional)</span></label>' +
						'<input class="mj-input" id="' + uid + '-note" name="note" type="text" maxlength="255" placeholder="' + h(restock ? 'Por ejemplo: el proveedor lo cambió' : 'Por ejemplo: el proveedor no lo reconoce') + '">' +
					'</div>' +
					'<div class="mj-form__errors mj-hidden" data-errors></div>' +
				'</form>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn ' + (restock ? 'mj-btn--primary' : 'mj-btn--danger') + '" data-action="save">' +
					MJ.icon(restock ? 'undo' : 'x-circle') + '<span>' + (restock ? 'Volvió al stock' : 'Dar de baja') + '</span></button>',
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			MJ.ui.setLoading(btn, true);
			MJ.api.post('rma/' + row.id + '/resolve', { status: status, note: MJ.ui.formData(form).note }).then(function () {
				MJ.ui.toast(restock ? 'Volvió al stock.' : 'Dado de baja.', 'success');
				modal.close();
				onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo cerrar el RMA.');
			});
		});
	}

	function moneyBoth(v) { const l = localToday(v); return moneyBase(v) + (l ? ' (' + l + ')' : ''); }

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.rma = {
		title() { return 'RMA'; },

		render(container) {
			if (!salesInfo().can_manage) {
				container.innerHTML = MJ.ui.alert('La lista de RMA la maneja solo quien puede corregir ventas (Configuración → Ventas → Corregir ventas).', 'warning');
				return function () {};
			}

			const uid = 'mj-rma-' + Math.random().toString(36).slice(2, 8);
			const state = {
				search: '',
				status: STATUS_OPEN,
				supplierId: '',
				rows: null,
				totals: null,
				statuses: null,
				page: 1,
				pages: 1,
				error: null,
				seq: 0,
				disposed: false,
			};

			container.innerHTML =
				'<div class="mj-page-header">' +
					'<div>' +
						'<h2 class="mj-page-header__title">RMA</h2>' +
						'<div class="mj-page-header__subtitle mj-muted">Lo que volvió fallado y espera el reclamo al proveedor. La pérdida por garantías se cuenta recién al darlo de baja.</div>' +
					'</div>' +
					'<div class="mj-page-header__actions">' +
						'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
					'</div>' +
				'</div>' +
				'<div data-region="totals"></div>' +
				toolbarHtml(uid, state) +
				'<div class="mj-card mj-card--flush"><div data-region="table">' + loadingHtml('Cargando…') + '</div></div>';

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };
			const statusSelect = qs('#' + uid + '-status', container);
			MJ.ui.fillSelect(statusSelect, [{ value: STATUS_OPEN, label: 'Abiertos' }], state.status, 'Todos');

			let supplier = null;
			try {
				supplier = MJ.components.supplierSelect({ select: qs('#' + uid + '-supplier', container), value: '', emptyLabel: 'Todos los proveedores' });
			} catch (e) {
				console.error('[MJ] supplierSelect', e);
			}

			function paintStatuses() {
				if (!state.statuses) return;
				MJ.ui.fillSelect(statusSelect, Object.keys(state.statuses).map(function (key) {
					return { value: key, label: state.statuses[key].label };
				}), state.status, 'Todos');
			}

			function load() {
				if (state.disposed) return;
				const mine = ++state.seq;
				const params = { page: state.page, per_page: PER_PAGE };
				if (state.status) params.status = state.status;
				if (state.supplierId) params.supplier_id = state.supplierId;
				if (state.search) params.search = state.search;

				MJ.api.get('rma', params).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.error = null;
					state.rows = Array.isArray(res && res.items) ? res.items : [];
					state.pages = Math.max(1, num(res && res.pages) || 1);
					state.totals = res && res.totals ? res.totals : null;
					if (!state.statuses && res && res.statuses) { state.statuses = res.statuses; paintStatuses(); }
					region('totals').innerHTML = totalsHtml(state.totals);
					region('table').innerHTML = tableHtml(state);

					// El globo del menú dice lo mismo que esta pantalla.
					const rma = salesInfo().rma;
					if (rma && state.totals) {
						rma.open_count = num(state.totals.open_count) || 0;
						if (MJ.app && MJ.app.refreshNavBadges) MJ.app.refreshNavBadges();
					}
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = err;
					region('table').innerHTML = tableHtml(state);
				});
			}

			function findRow(el) {
				const tr = el.closest('tr[data-id]');
				if (!tr || !state.rows) return null;
				return state.rows.find(function (r) { return String(r.id) === tr.getAttribute('data-id'); }) || null;
			}

			const searchDebounced = MJ.ui.debounce(function () { state.page = 1; load(); }, SEARCH_DELAY);

			container.addEventListener('input', function (e) {
				if (e.target.name === 'search') {
					state.search = String(e.target.value || '').trim();
					searchDebounced();
				}
			});

			container.addEventListener('change', function (e) {
				if (e.target.name === 'status') { state.status = String(e.target.value || ''); state.page = 1; load(); }
				else if (e.target.name === 'supplier_id') { state.supplierId = supplier ? supplier.getValue() : String(e.target.value || ''); state.page = 1; load(); }
			});

			container.addEventListener('click', function (e) {
				const btn = e.target.closest ? e.target.closest('[data-action]') : null;
				if (!btn) return;
				const action = btn.getAttribute('data-action');

				if (action === 'reload') { load(); return; }
				if (action === 'prev') { if (state.page > 1) { state.page--; load(); } return; }
				if (action === 'next') { if (state.page < state.pages) { state.page++; load(); } return; }

				const row = findRow(btn);
				if (!row) return;
				if (action === 'edit') editModal(row, load);
				else if (action === 'restock') resolveModal(row, STATUS_RESTOCKED, load);
				else if (action === 'write-off') resolveModal(row, STATUS_WRITTEN_OFF, load);
			});

			load();

			return function () {
				state.disposed = true;
				if (supplier) supplier.destroy();
			};
		},
	};
})();
