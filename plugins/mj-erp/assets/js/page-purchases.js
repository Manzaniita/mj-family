/**
 * MJ ERP — page-purchases.js
 * MJ.pages.purchases: listado de compras con filtros, paginación, alta ("Nueva compra"), duplicar y eliminar.
 *
 * REST usado: GET /purchases, GET /purchases/next-code, POST /purchases, POST /purchases/{id}/duplicate, DELETE /purchases/{id}.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const PER_PAGE = 20;
	const SORTABLE = ['purchase_date', 'code', 'total', 'created_at'];
	const CUSTOM_DESTINATION = '__custom__';
	const PRORATION_OPTIONS = [
		{ value: 'fob', label: 'Por FOB (proporcional al valor)' },
		{ value: 'qty', label: 'Por cantidad (proporcional a las unidades)' },
		{ value: 'none', label: 'Sin prorrateo' },
	];

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

	function pad2(n) { return (n < 10 ? '0' : '') + n; }

	function today() {
		const d = new Date();
		return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
	}

	function isYmd(s) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(String(s || ''))) return false;
		return !isNaN(new Date(String(s) + 'T00:00:00').getTime());
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

	function alertBox(message, type) {
		type = ['info', 'warning', 'danger', 'success'].indexOf(type) >= 0 ? type : 'info';
		const icon = type === 'success' ? 'check' : (type === 'info' ? 'info' : 'warning');
		return '<div class="mj-alert mj-alert--' + type + '" role="' + (type === 'danger' ? 'alert' : 'status') + '">' + MJ.icon(icon) + '<div>' + h(message) + '</div></div>';
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function currencies() {
		const list = setting('currencies', ['USD', 'ARS']);
		const arr = Array.isArray(list) ? list.map(function (c) { return String(c).toUpperCase(); }) : ['USD', 'ARS'];
		[String(setting('base_currency', 'USD')).toUpperCase(), String(setting('local_currency', 'ARS')).toUpperCase()].forEach(function (c) {
			if (c && arr.indexOf(c) === -1) arr.push(c);
		});
		return arr;
	}

	function destinations() {
		const list = setting('destinations', []);
		return Array.isArray(list) ? list.map(String).filter(Boolean) : [];
	}

	function optionsHtml(list, value) {
		return list.map(function (o) {
			return '<option value="' + h(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
		}).join('');
	}

	function detailLink(id) { return '#/compras/' + encodeURIComponent(id); }

	function buildHashQuery(obj) {
		const pairs = [];
		Object.keys(obj || {}).forEach(function (k) {
			const v = obj[k];
			if (v === undefined || v === null || v === '') return;
			pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
		});
		return pairs.length ? '?' + pairs.join('&') : '';
	}

	function replaceHash(path, query) {
		const target = '#' + path + buildHashQuery(query);
		if (window.location.hash === target) return;
		try { window.history.replaceState(null, '', target); } catch (e) { /* noop: the URL simply is not synced */ }
	}

	function clearFormErrors(form) {
		qsa('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
		qsa('.mj-field__error', form).forEach(function (el) { el.parentNode.removeChild(el); });
		qsa('[data-form-alert]', form).forEach(function (el) { el.parentNode.removeChild(el); });
	}

	function showFormError(form, err) {
		const params = err && err.data && err.data.params && typeof err.data.params === 'object' ? err.data.params : null;
		if (params && Object.keys(params).length) {
			MJ.ui.showErrors(form, params);
			return;
		}
		const box = document.createElement('div');
		box.className = 'mj-alert mj-alert--danger mj-mb';
		box.setAttribute('data-form-alert', '1');
		box.setAttribute('role', 'alert');
		box.innerHTML = MJ.icon('warning') + '<div>' + h(errMessage(err)) + '</div>';
		form.insertBefore(box, form.firstChild);
	}

	function hasFilters(f) {
		return !!(f.search || f.status || f.supplier_id || f.date_from || f.date_to);
	}

	function parseQuery(query) {
		query = query || {};
		const statuses = MJ.state.statuses || {};
		const filters = {
			search: String(query.search || query.q || '').trim(),
			status: query.status && statuses[query.status] ? String(query.status) : '',
			supplier_id: /^\d+$/.test(String(query.supplier_id || '')) ? String(query.supplier_id) : '',
			date_from: isYmd(query.date_from) ? String(query.date_from) : '',
			date_to: isYmd(query.date_to) ? String(query.date_to) : '',
		};
		const page = parseInt(query.page, 10);
		return {
			filters: filters,
			page: page > 0 ? page : 1,
			orderby: SORTABLE.indexOf(query.orderby) >= 0 ? String(query.orderby) : 'purchase_date',
			order: String(query.order || '').toUpperCase() === 'ASC' ? 'ASC' : 'DESC',
		};
	}

	/* ------------------------------------------------------------------ */
	/* Destination field (select from settings + free text)                */
	/* ------------------------------------------------------------------ */

	function destinationFieldHtml(fid, value) {
		const list = destinations();
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

	/* ------------------------------------------------------------------ */
	/* "Nueva compra" modal                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Opens the "Nueva compra" modal. On success navigates to the new purchase detail.
	 * @param {Object} opts { onCreated(purchase)? }
	 */
	function openNewPurchaseModal(opts) {
		opts = opts || {};
		const fid = 'mj-np-' + Math.random().toString(36).slice(2, 8);
		const base = String(setting('base_currency', 'USD')).toUpperCase();
		const curList = currencies().map(function (c) { return { value: c, label: c }; });
		const defaultRate = num(setting('default_rate', 1));
		const method = String(setting('proration_method', 'fob'));

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-code">Código</label>' +
						'<input class="mj-input mj-mono" id="' + fid + '-code" name="code" type="text" maxlength="60" placeholder="Generando…" autocomplete="off">' +
						'<div class="mj-field__hint">Se genera automáticamente; podés cambiarlo.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha *</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="purchase_date" type="date" value="' + h(today()) + '" required>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-supplier">Proveedor</label>' +
					'<select class="mj-select" id="' + fid + '-supplier" name="supplier_id"></select>' +
				'</div>' +
				destinationFieldHtml(fid, '') +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cur">Moneda base</label>' +
						'<select class="mj-select" id="' + fid + '-cur" name="base_currency">' + optionsHtml(curList, base) + '</select>' +
						'<div class="mj-field__hint">Moneda en la que se costea la compra.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-rate">TC por defecto *</label>' +
						'<input class="mj-input" id="' + fid + '-rate" name="default_rate" type="text" inputmode="decimal" data-type="number" value="' + h(MJ.fmt.rate(defaultRate)) + '">' +
						'<div class="mj-field__hint">' + h(String(setting('local_currency', 'ARS')).toUpperCase()) + ' por cada 1 ' + h(base) + '.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-method">Prorrateo de gastos</label>' +
						'<select class="mj-select" id="' + fid + '-method" name="proration_method">' + optionsHtml(PRORATION_OPTIONS, method) + '</select>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
					'<textarea class="mj-textarea" id="' + fid + '-notes" name="notes" rows="3" placeholder="Observaciones, tracking, condiciones…"></textarea>' +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('plus') + '<span>Crear compra</span></button>';

		return MJ.ui.modal({
			title: 'Nueva compra',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const codeInput = qs('[name="code"]', form);
				const submitBtn = qs('[data-submit]', root);
				let codeTouched = false;
				let submitting = false;

				codeInput.addEventListener('input', function () { codeTouched = true; });

				MJ.api.get('purchases/next-code').then(function (res) {
					if (!codeTouched && codeInput.value.trim() === '' && res && res.code) {
						codeInput.value = String(res.code);
					}
					codeInput.placeholder = 'Ej: IMP-2026-0001';
				}).catch(function (err) {
					codeInput.placeholder = 'Se generará al guardar';
					if (err && err.status !== 401) MJ.ui.toast('No se pudo sugerir un código; se generará al guardar.', 'warning');
				});

				try {
					MJ.components.supplierSelect({ select: qs('[name="supplier_id"]', form), value: '', allowCreate: true });
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

					const date = String(data.purchase_date || '').trim();
					if (!isYmd(date)) errors.purchase_date = 'Ingresá una fecha válida.';

					let rate = data.default_rate;
					if (typeof rate !== 'number') rate = MJ.fmt.parse(rate);
					if (rate === null || isNaN(rate) || rate <= 0) errors.default_rate = 'El TC debe ser mayor a 0.';

					const destination = readDestination(data);
					if (data.destination_select === CUSTOM_DESTINATION && !destination) errors.destination_custom = 'Escribí el destino.';

					const code = String(data.code || '').trim();
					if (code.length > 60) errors.code = 'El código no puede superar 60 caracteres.';

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const supplierId = parseInt(data.supplier_id, 10);
					const payload = {
						code: code,
						purchase_date: date,
						supplier_id: supplierId > 0 ? supplierId : 0,
						destination: destination,
						base_currency: String(data.base_currency || setting('base_currency', 'USD')).toUpperCase(),
						default_rate: rate,
						proration_method: ['fob', 'qty', 'none'].indexOf(data.proration_method) >= 0 ? data.proration_method : 'fob',
						notes: String(data.notes || ''),
					};

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);
					MJ.api.post('purchases', payload).then(function (purchase) {
						MJ.ui.setLoading(submitBtn, false);
						submitting = false;
						MJ.ui.toast('Compra ' + (purchase && purchase.code ? purchase.code : '') + ' creada.', 'success');
						modal.close(purchase);
						if (typeof opts.onCreated === 'function') opts.onCreated(purchase);
						if (purchase && purchase.id) MJ.router.navigate('/compras/' + purchase.id);
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						submitting = false;
						if (err && (err.handled || err.status === 401)) return;
						showFormError(form, err);
					});
				});
			},
		});
	}

	/* ------------------------------------------------------------------ */
	/* HTML builders                                                       */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Compras</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-count>Importaciones y compras a proveedores</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>Nueva compra</span></button>' +
			'</div>' +
		'</div>';
	}

	function toolbarHtml(state) {
		const f = state.filters;
		return '<div class="mj-card mj-mb">' +
			'<div class="mj-card__body">' +
				'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-filters novalidate autocomplete="off">' +
					'<div class="mj-field" style="flex:2 1 220px">' +
						'<label class="mj-field__label" for="mj-pl-search">Buscar</label>' +
						'<div class="mj-input-group">' +
							'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
							'<input class="mj-input" id="mj-pl-search" type="search" name="search" placeholder="Código, proveedor o notas…" value="' + h(f.search) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field" style="flex:1 1 150px">' +
						'<label class="mj-field__label" for="mj-pl-status">Estado</label>' +
						'<select class="mj-select" id="mj-pl-status" name="status"></select>' +
					'</div>' +
					'<div class="mj-field" style="flex:1 1 180px">' +
						'<label class="mj-field__label" for="mj-pl-supplier">Proveedor</label>' +
						'<select class="mj-select" id="mj-pl-supplier" name="supplier_id"></select>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 150px">' +
						'<label class="mj-field__label" for="mj-pl-from">Desde</label>' +
						'<input class="mj-input" id="mj-pl-from" type="date" name="date_from" value="' + h(f.date_from) + '">' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 150px">' +
						'<label class="mj-field__label" for="mj-pl-to">Hasta</label>' +
						'<input class="mj-input" id="mj-pl-to" type="date" name="date_to" value="' + h(f.date_to) + '">' +
					'</div>' +
					'<div class="mj-field" style="flex:0 0 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<button type="button" class="mj-btn mj-btn--ghost" data-action="clear-filters"' + (hasFilters(f) ? '' : ' disabled') + '>' + MJ.icon('x') + '<span>Limpiar</span></button>' +
					'</div>' +
				'</form>' +
			'</div>' +
		'</div>';
	}

	function sortTh(state, key, label, cls) {
		const active = state.orderby === key;
		const dir = active ? state.order : null;
		return '<th class="' + (cls ? cls + ' ' : '') + 'is-sortable' + (active ? ' is-sorted' : '') + '" data-sort="' + h(key) + '" role="button" tabindex="0" ' +
			'aria-sort="' + (active ? (dir === 'ASC' ? 'ascending' : 'descending') : 'none') + '" title="Ordenar por ' + h(label.toLowerCase()) + '">' +
			'<span>' + h(label) + '</span>' + (active ? MJ.icon(dir === 'ASC' ? 'chevron-down' : 'chevron-down') : '') +
		'</th>';
	}

	function rowHtml(p) {
		const cur = p.base_currency || setting('base_currency', 'USD');
		const canDelete = p.status !== 'recibida';
		const link = detailLink(p.id);
		return '<tr class="is-clickable" data-id="' + h(p.id) + '" data-code="' + h(p.code) + '" data-href="' + h(link) + '" tabindex="0">' +
			'<td><a class="mj-strong mj-mono mj-link" href="' + h(link) + '">' + h(p.code) + '</a></td>' +
			'<td>' + h(MJ.fmt.date(p.purchase_date)) + '</td>' +
			'<td>' + (p.supplier_name ? h(p.supplier_name) : '<span class="mj-muted">—</span>') + '</td>' +
			'<td>' + (p.destination ? h(p.destination) : '<span class="mj-muted">—</span>') + '</td>' +
			'<td>' + MJ.ui.badge(p.status) + '</td>' +
			'<td class="is-num">' + h(MJ.fmt.int(p.items_count)) + '</td>' +
			'<td class="is-num mj-money">' + h(MJ.fmt.money(p.fob_total, cur)) + '</td>' +
			'<td class="is-num">' + h(MJ.fmt.money(p.expenses_total, cur)) + '</td>' +
			'<td class="is-num mj-money mj-strong">' + h(MJ.fmt.money(p.total, cur)) + '</td>' +
			'<td class="is-actions">' +
				'<div class="mj-btn-group">' +
					'<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(link) + '" title="Abrir" aria-label="Abrir ' + h(p.code) + '">' + MJ.icon('eye') + '</a>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="duplicate" title="Duplicar" aria-label="Duplicar ' + h(p.code) + '">' + MJ.icon('copy') + '</button>' +
					(canDelete ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete" title="Eliminar" aria-label="Eliminar ' + h(p.code) + '">' + MJ.icon('trash') + '</button>' : '') +
				'</div>' +
			'</td>' +
		'</tr>';
	}

	function tableHtml(state) {
		const data = state.data;
		if (!data) return loadingHtml('Cargando compras…');
		if (!data.items.length) {
			if (hasFilters(state.filters)) {
				return MJ.ui.empty({
					icon: 'search',
					title: 'Sin resultados',
					text: 'Ninguna compra coincide con los filtros aplicados.',
					action: '<button type="button" class="mj-btn" data-action="clear-filters">' + MJ.icon('x') + '<span>Limpiar filtros</span></button>',
				});
			}
			return MJ.ui.empty({
				icon: 'cart',
				title: 'Todavía no hay compras',
				text: 'Registrá tu primera importación para empezar a costear.',
				action: '<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>Nueva compra</span></button>',
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			sortTh(state, 'code', 'Código') +
			sortTh(state, 'purchase_date', 'Fecha') +
			'<th>Proveedor</th><th>Destino</th><th>Estado</th><th class="is-num">Ítems</th><th class="is-num">FOB</th><th class="is-num">Gastos</th>' +
			sortTh(state, 'total', 'Total', 'is-num') +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
			'</tr></thead><tbody>';
		data.items.forEach(function (p) { html += rowHtml(p); });
		html += '</tbody></table></div>';
		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.purchases = {
		title: 'Compras',
		openNewModal: openNewPurchaseModal,

		render(container, params, query) {
			const parsed = parseQuery(query);
			const state = {
				filters: parsed.filters,
				page: parsed.page,
				orderby: parsed.orderby,
				order: parsed.order,
				data: null,
				loading: false,
				seq: 0,
				disposed: false,
				busyRows: {},
			};
			const offs = [];
			let supplierSel = null;

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML = headerHtml() + toolbarHtml(state) +
				'<div class="mj-card mj-card--flush">' +
					'<div data-region="table">' + loadingHtml('Cargando compras…') + '</div>' +
					'<div class="mj-card__footer" data-region="pagination"></div>' +
				'</div>';

			/* ---- toolbar components ---- */
			function initToolbar() {
				const statusSel = qs('[name="status"]', container);
				try {
					MJ.components.statusSelect(statusSel, state.filters.status, { includeAll: true, allLabel: 'Todos los estados' });
				} catch (e) {
					console.error('[MJ] statusSelect', e);
				}
				if (statusSel && !qs('option[value=""]', statusSel)) {
					const opt = document.createElement('option');
					opt.value = '';
					opt.textContent = 'Todos los estados';
					statusSel.insertBefore(opt, statusSel.firstChild);
				}
				if (statusSel) statusSel.value = state.filters.status;

				const supplierEl = qs('[name="supplier_id"]', container);
				try {
					supplierSel = MJ.components.supplierSelect({ select: supplierEl, value: state.filters.supplier_id, emptyLabel: 'Todos los proveedores' });
				} catch (e) {
					console.error('[MJ] supplierSelect', e);
				}
				if (supplierEl) {
					const emptyOpt = qs('option[value=""]', supplierEl);
					if (emptyOpt) emptyOpt.textContent = 'Todos los proveedores';
					else {
						const opt = document.createElement('option');
						opt.value = '';
						opt.textContent = 'Todos los proveedores';
						supplierEl.insertBefore(opt, supplierEl.firstChild);
					}
					supplierEl.value = state.filters.supplier_id;
				}
			}

			function syncClearButton() {
				const btn = qs('[data-filters] [data-action="clear-filters"]', container);
				if (btn) btn.disabled = !hasFilters(state.filters);
			}

			function syncHash() {
				replaceHash('/compras', {
					search: state.filters.search,
					status: state.filters.status,
					supplier_id: state.filters.supplier_id,
					date_from: state.filters.date_from,
					date_to: state.filters.date_to,
					page: state.page > 1 ? state.page : '',
					orderby: state.orderby !== 'purchase_date' ? state.orderby : '',
					order: state.order !== 'DESC' ? state.order : '',
				});
			}

			function paintCount() {
				const el = qs('[data-count]', container);
				if (!el) return;
				if (!state.data) { el.textContent = 'Importaciones y compras a proveedores'; return; }
				const total = state.data.total;
				el.textContent = (total === 1 ? '1 compra' : MJ.fmt.int(total) + ' compras') + (hasFilters(state.filters) ? ' con los filtros aplicados' : ' registradas');
			}

			function paintTable() {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = tableHtml(state);
			}

			function paintPagination() {
				const el = region('pagination');
				if (!el) return;
				if (!state.data || !state.data.items.length) { el.innerHTML = ''; el.classList.add('mj-hidden'); return; }
				el.classList.remove('mj-hidden');
				const pg = MJ.components.pagination({ page: state.data.page, pages: state.data.pages, total: state.data.total, perPage: PER_PAGE, onChange: goToPage });
				el.innerHTML = typeof pg === 'string' ? pg : (pg && pg.html ? pg.html : String(pg));
			}

			function paintError(err) {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = '<div class="mj-card__body">' + alertBox(errMessage(err, 'No se pudieron cargar las compras.'), 'danger') +
					'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div></div>';
				const pg = region('pagination');
				if (pg) { pg.innerHTML = ''; pg.classList.add('mj-hidden'); }
			}

			function load() {
				if (state.disposed) return Promise.resolve();
				const mine = ++state.seq;
				state.loading = true;
				const el = region('table');
				if (el) {
					if (!state.data) el.innerHTML = loadingHtml('Cargando compras…');
					else { el.classList.add('is-loading'); el.setAttribute('aria-busy', 'true'); }
				}
				const params = { page: state.page, per_page: PER_PAGE, orderby: state.orderby, order: state.order };
				['search', 'status', 'supplier_id', 'date_from', 'date_to'].forEach(function (k) {
					if (state.filters[k]) params[k] = state.filters[k];
				});
				return MJ.api.get('purchases', params).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					res = res && typeof res === 'object' ? res : {};
					const items = Array.isArray(res.items) ? res.items : [];
					const pages = Math.max(1, parseInt(res.pages, 10) || 1);
					const page = parseInt(res.page, 10) || state.page;
					if (!items.length && state.page > 1 && pages < state.page) {
						state.page = pages;
						load();
						return;
					}
					state.data = { items: items, total: parseInt(res.total, 10) || 0, pages: pages, page: page };
					state.page = page;
					paintTable();
					paintPagination();
					paintCount();
					syncHash();
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					if (err && (err.handled || err.status === 401)) return;
					if (state.data) {
						paintTable();
						toastError(err, 'No se pudieron cargar las compras.');
					} else {
						paintError(err);
					}
				});
			}

			function applyFilter(key, value) {
				if (state.filters[key] === value) return;
				state.filters[key] = value;
				state.page = 1;
				syncClearButton();
				load();
			}

			function goToPage(page) {
				page = parseInt(page, 10);
				if (!page || page < 1 || page === state.page) return;
				state.page = page;
				load().then(function () {
					const el = region('table');
					if (el && el.scrollIntoView) { try { el.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { /* noop */ } }
				});
			}

			function clearFilters() {
				state.filters = { search: '', status: '', supplier_id: '', date_from: '', date_to: '' };
				state.page = 1;
				const form = qs('[data-filters]', container);
				if (form) {
					qs('[name="search"]', form).value = '';
					qs('[name="status"]', form).value = '';
					qs('[name="supplier_id"]', form).value = '';
					if (supplierSel && typeof supplierSel.setValue === 'function') supplierSel.setValue('');
					qs('[name="date_from"]', form).value = '';
					qs('[name="date_to"]', form).value = '';
				}
				syncClearButton();
				load();
			}

			function rowOf(el) {
				const tr = el.closest('tr[data-id]');
				if (!tr) return null;
				return { id: tr.getAttribute('data-id'), code: tr.getAttribute('data-code') || ('#' + tr.getAttribute('data-id')), tr: tr };
			}

			function duplicate(btn) {
				const row = rowOf(btn);
				if (!row || state.busyRows[row.id]) return;
				state.busyRows[row.id] = true;
				MJ.ui.setLoading(btn, true);
				MJ.api.post('purchases/' + encodeURIComponent(row.id) + '/duplicate').then(function (res) {
					delete state.busyRows[row.id];
					if (state.disposed) return;
					MJ.ui.setLoading(btn, false);
					MJ.ui.toast('Compra duplicada como ' + (res && res.code ? res.code : 'nueva compra') + '.', 'success');
					if (res && res.id) MJ.router.navigate('/compras/' + res.id);
					else load();
				}).catch(function (err) {
					delete state.busyRows[row.id];
					if (state.disposed) return;
					MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo duplicar la compra.');
				});
			}

			function remove(btn) {
				const row = rowOf(btn);
				if (!row || state.busyRows[row.id]) return;
				MJ.ui.confirm({
					title: 'Eliminar compra',
					message: 'Se eliminará la compra ' + row.code + ' con todos sus productos y gastos. Esta acción no se puede deshacer.',
					okLabel: 'Eliminar',
					cancelLabel: 'Cancelar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					state.busyRows[row.id] = true;
					MJ.ui.setLoading(btn, true);
					MJ.api.del('purchases/' + encodeURIComponent(row.id)).then(function () {
						delete state.busyRows[row.id];
						if (state.disposed) return;
						MJ.ui.toast('Compra ' + row.code + ' eliminada.', 'success');
						load();
					}).catch(function (err) {
						delete state.busyRows[row.id];
						if (state.disposed) return;
						MJ.ui.setLoading(btn, false);
						toastError(err, 'No se pudo eliminar la compra.');
					});
				});
			}

			function toggleSort(key) {
				if (SORTABLE.indexOf(key) === -1) return;
				if (state.orderby === key) {
					state.order = state.order === 'ASC' ? 'DESC' : 'ASC';
				} else {
					state.orderby = key;
					state.order = key === 'code' ? 'ASC' : 'DESC';
				}
				state.page = 1;
				load();
			}

			/* ---- events ---- */
			function bind() {
				const form = qs('[data-filters]', container);
				const search = qs('[name="search"]', form);
				const onSearch = MJ.ui.debounce(function () { applyFilter('search', search.value.trim()); }, 350);
				search.addEventListener('input', onSearch);
				search.addEventListener('search', function () { onSearch.cancel && onSearch.cancel(); applyFilter('search', search.value.trim()); });
				offs.push(function () { search.removeEventListener('input', onSearch); });
				form.addEventListener('submit', function (e) { e.preventDefault(); applyFilter('search', search.value.trim()); });

				offs.push(MJ.ui.on(form, 'change', '[name="status"]', function (e, sel) { applyFilter('status', sel.value); }));
				offs.push(MJ.ui.on(form, 'change', '[name="supplier_id"]', function (e, sel) { applyFilter('supplier_id', /^\d+$/.test(sel.value) ? sel.value : ''); }));
				offs.push(MJ.ui.on(form, 'change', '[name="date_from"]', function (e, input) {
					const v = isYmd(input.value) ? input.value : '';
					if (!v) input.value = '';
					applyFilter('date_from', v);
				}));
				offs.push(MJ.ui.on(form, 'change', '[name="date_to"]', function (e, input) {
					const v = isYmd(input.value) ? input.value : '';
					if (!v) input.value = '';
					applyFilter('date_to', v);
				}));

				offs.push(MJ.ui.on(container, 'click', '[data-action]', function (e, btn) {
					const action = btn.getAttribute('data-action');
					if (action === 'new') { e.preventDefault(); openNewPurchaseModal(); }
					else if (action === 'reload') { e.preventDefault(); MJ.ui.setLoading(btn, true); load().then(function () { MJ.ui.setLoading(btn, false); }); }
					else if (action === 'clear-filters') { e.preventDefault(); clearFilters(); }
					else if (action === 'duplicate') { e.preventDefault(); e.stopPropagation(); duplicate(btn); }
					else if (action === 'delete') { e.preventDefault(); e.stopPropagation(); remove(btn); }
				}));

				offs.push(MJ.ui.on(container, 'click', 'tr[data-href]', function (e, tr) {
					if (e.target.closest('a, button, input, select')) return;
					e.preventDefault();
					MJ.router.navigate(tr.getAttribute('data-href').replace(/^#/, ''));
				}));
				offs.push(MJ.ui.on(container, 'keydown', 'tr[data-href]', function (e, tr) {
					if (e.target !== tr) return;
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); MJ.router.navigate(tr.getAttribute('data-href').replace(/^#/, '')); }
				}));

				offs.push(MJ.ui.on(container, 'click', 'th[data-sort]', function (e, th) { e.preventDefault(); toggleSort(th.getAttribute('data-sort')); }));
				offs.push(MJ.ui.on(container, 'keydown', 'th[data-sort]', function (e, th) {
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleSort(th.getAttribute('data-sort')); }
				}));

				offs.push(MJ.components.bindPagination(region('pagination'), goToPage));
			}

			initToolbar();
			bind();
			load();

			if (query && (String(query.new) === '1' || String(query.new) === 'true')) {
				replaceHash('/compras', {});
				openNewPurchaseModal();
			}

			return function cleanup() {
				state.disposed = true;
				state.seq++;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
				if (supplierSel && typeof supplierSel.destroy === 'function') supplierSel.destroy();
			};
		},
	};
})();
