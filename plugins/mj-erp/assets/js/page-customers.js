/**
 * MJ ERP — page-customers.js
 *
 * MJ.pages.customers: la libreta de clientes. Quiénes son, cuánto compraron, y cuáles ya tienen
 * cuenta en la tienda.
 *
 * Arriba de todo aparecen las sugerencias de vinculación: cuentas que se crearon en la tienda y que
 * el ERP reconoce como alguien que ya venía comprando en el mostrador. No se unen solas (salvo que
 * lo configures así) porque unir dos historiales es de lo poco acá que conviene mirar antes de
 * hacer: dos personas de la misma casa comparten teléfono.
 *
 * REST: GET /customers, DELETE /customers/{id}, GET /customers/suggestions,
 *       DELETE /customers/suggestions/{user_id}, DELETE /customers/{id}/link.
 * Las altas y ediciones las resuelve MJ.components.customerModal; la vinculación,
 * MJ.components.customerLinkModal.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const SEARCH_DELAY = 350;
	const PER_PAGE = 25;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function dash() { return '<span class="mj-muted">—</span>'; }

	function errMessage(err, fallback) {
		if (!err) return fallback || 'Ocurrió un error inesperado.';
		if (typeof err === 'string') return err;
		if (err.message) return String(err.message);
		return fallback || 'Ocurrió un error inesperado.';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function money(value) {
		return MJ.fmt.money(MJ.fmt.toNumber(value) || 0, MJ.setting('local_currency', 'ARS'));
	}

	function mailCell(email) {
		const value = String(email || '').trim();
		if (!value) return dash();
		return '<a class="mj-link mj-truncate" href="' + h('mailto:' + value) + '" title="' + h(value) + '">' + h(value) + '</a>';
	}

	function phoneCell(phone) {
		const value = String(phone || '').trim();
		if (!value) return dash();
		const dial = value.replace(/[^\d+]/g, '');
		if (!dial || dial === '+') return '<span>' + h(value) + '</span>';
		return '<a class="mj-link" href="' + h('tel:' + dial) + '">' + h(value) + '</a>';
	}

	function docCell(row) {
		const num = String(row.doc_number || '').trim();
		if (!num) return dash();
		const label = MJ.components.customerDocTypeLabel ? MJ.components.customerDocTypeLabel(row.doc_type) : '';
		return '<span class="mj-mono">' + h((label ? label + ' ' : '') + num) + '</span>';
	}

	function accountCell(row) {
		if (!row.is_linked || !row.account) {
			return '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="link">' + MJ.icon('link') + '<span>Vincular</span></button>';
		}
		const a = row.account;
		return '<div class="mj-row mj-row--tight">' +
			MJ.ui.badgeRaw('Con cuenta', 'info') +
			'<span class="mj-truncate mj-small" title="' + h(a.email || a.name) + '">' + h(a.name || a.email || ('#' + a.id)) + '</span>' +
		'</div>';
	}

	function creditOn() {
		return !!(MJ.components && MJ.components.creditEnabled && MJ.components.creditEnabled());
	}

	/** Saldo a favor, moneda por moneda: no se suma ni se convierte. */
	function creditCell(row) {
		const text = MJ.components.creditBalancesText(row.credit);
		return text ? '<span class="mj-success-text">' + h(text) + '</span>' : dash();
	}

	function rowHtml(row) {
		const stats = row.stats || {};
		const sales = MJ.fmt.toNumber(stats.sales_count) || 0;
		const due = MJ.fmt.toNumber(stats.due) || 0;
		const company = String(row.company || '').trim();

		return '<tr data-id="' + h(row.id) + '"' + (row.is_active === false ? ' class="is-inactive"' : '') + '>' +
			'<td>' +
				'<div class="mj-strong mj-truncate" title="' + h(row.name) + '"><a class="mj-link" href="' + h(MJ.router.link('/clientes/' + row.id)) + '">' + h(row.name) + '</a></div>' +
				(company ? '<div class="mj-table__sub mj-muted mj-truncate" title="' + h(company) + '">' + h(company) + '</div>' : '') +
			'</td>' +
			'<td>' + phoneCell(row.phone) + '</td>' +
			'<td>' + mailCell(row.email) + '</td>' +
			'<td>' + docCell(row) + '</td>' +
			'<td class="mj-text-right">' + (sales ? MJ.fmt.int(sales) : dash()) + '</td>' +
			'<td class="mj-text-right">' + (stats.total ? h(money(stats.total)) : dash()) + '</td>' +
			'<td class="mj-text-right">' + (due > 0 ? '<span class="mj-danger-text">' + h(money(due)) + '</span>' : dash()) + '</td>' +
			(creditOn() ? '<td class="mj-text-right">' + creditCell(row) + '</td>' : '') +
			'<td>' + accountCell(row) + '</td>' +
			'<td class="is-actions">' +
				'<div class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="sale" title="Nueva venta" aria-label="' + h('Nueva venta a ' + row.name) + '">' + MJ.icon('cash') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit" title="Editar" aria-label="' + h('Editar ' + row.name) + '">' + MJ.icon('edit') + '</button>' +
					(row.is_linked ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="unlink" title="Desvincular la cuenta" aria-label="' + h('Desvincular ' + row.name) + '">' + MJ.icon('unlink') + '</button>' : '') +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete" title="Eliminar" aria-label="' + h('Eliminar ' + row.name) + '">' + MJ.icon('trash') + '</button>' +
				'</div>' +
			'</td>' +
		'</tr>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Clientes</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-count>A quién le vendés. No son cuentas de la tienda: son tu registro de ventas.</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>Nuevo cliente</span></button>' +
			'</div>' +
		'</div>';
	}

	function toolbarHtml(uid, state) {
		return '<div class="mj-card mj-mb">' +
			'<div class="mj-card__body">' +
				'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-filters novalidate autocomplete="off">' +
					'<div class="mj-field" style="flex:2 1 260px">' +
						'<label class="mj-field__label" for="' + uid + '-search">Buscar cliente</label>' +
						'<div class="mj-input-group">' +
							'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
							'<input class="mj-input" id="' + uid + '-search" type="search" name="search" placeholder="Nombre, empresa, email, teléfono o documento…" value="' + h(state.search) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 190px">' +
						'<label class="mj-field__label" for="' + uid + '-linked">Cuenta en la tienda</label>' +
						'<select class="mj-select" id="' + uid + '-linked" name="linked">' +
							'<option value=""' + (state.linked === '' ? ' selected' : '') + '>Todos</option>' +
							'<option value="si"' + (state.linked === 'si' ? ' selected' : '') + '>Con cuenta</option>' +
							'<option value="no"' + (state.linked === 'no' ? ' selected' : '') + '>Sin cuenta</option>' +
						'</select>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<label class="mj-checkbox" for="' + uid + '-inactive">' +
							'<input type="checkbox" id="' + uid + '-inactive" name="include_inactive"' + (state.includeInactive ? ' checked' : '') + '>' +
							'<span>Mostrar inactivos</span>' +
						'</label>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 0 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<button type="button" class="mj-btn mj-btn--ghost" data-action="clear-filters"' + (hasFilters(state) ? '' : ' disabled') + '>' + MJ.icon('x') + '<span>Limpiar</span></button>' +
					'</div>' +
				'</form>' +
			'</div>' +
		'</div>';
	}

	function hasFilters(state) {
		return !!(state.search || state.linked || state.includeInactive);
	}

	function suggestionsHtml(state) {
		const items = state.suggestions;
		if (!items || !items.length) return '';

		const rows = items.map(function (s) {
			const user = s.user || {};
			const customer = s.customer || {};
			return '<div class="mj-list__item" data-user="' + h(user.id) + '" data-customer="' + h(customer.id) + '">' +
				'<span class="mj-list__text">' +
					'<span class="mj-strong">' + h(user.name || user.email || ('#' + user.id)) + '</span>' +
					'<span class="mj-small mj-muted"> se creó una cuenta y coincide con tu cliente <strong>' + h(customer.name) + '</strong>' +
					(customer.email ? ' (' + h(customer.email) + ')' : '') + '.</span>' +
				'</span>' +
				'<span class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="accept-suggestion">' + MJ.icon('link') + '<span>Es la misma persona</span></button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="dismiss-suggestion">' + MJ.icon('x') + '<span>No</span></button>' +
				'</span>' +
			'</div>';
		}).join('');

		return '<div class="mj-card mj-mb" data-suggestions>' +
			'<div class="mj-card__header">' +
				'<h3 class="mj-card__title">' + MJ.icon('link') + '<span>Posibles vinculaciones</span></h3>' +
				'<div class="mj-card__subtitle mj-muted mj-small">Alguien que ya te compraba se creó una cuenta en la tienda. Si es la misma persona, uní los dos historiales.</div>' +
			'</div>' +
			'<div class="mj-card__body mj-list">' + rows + '</div>' +
		'</div>';
	}

	function tableHtml(state) {
		if (state.error) {
			return '<div class="mj-card__body">' +
				MJ.ui.alert(errMessage(state.error, 'No se pudieron cargar los clientes.'), 'danger') +
				'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
			'</div>';
		}
		if (!state.rows) return loadingHtml('Cargando clientes…');
		if (!state.rows.length) {
			if (hasFilters(state)) {
				return MJ.ui.empty({
					icon: 'search',
					title: 'Sin resultados',
					text: 'Ningún cliente coincide con la búsqueda.',
					action: '<button type="button" class="mj-btn" data-action="clear-filters">' + MJ.icon('x') + '<span>Limpiar filtros</span></button>',
				});
			}
			return MJ.ui.empty({
				icon: 'users',
				title: 'Todavía no hay clientes',
				text: 'Cargá a quién le vendés para saber después quién compró qué. No hace falta que tengan cuenta en la tienda.',
				action: '<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>Nuevo cliente</span></button>',
			});
		}

		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Nombre</th><th>Teléfono</th><th>Email</th><th>Documento</th>' +
			'<th class="mj-text-right">Ventas</th><th class="mj-text-right">Total</th><th class="mj-text-right">Debe</th>' +
			(creditOn() ? '<th class="mj-text-right">' + h(MJ.components.creditLabel()) + '</th>' : '') +
			'<th>Cuenta</th><th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>';
		state.rows.forEach(function (row) { html += rowHtml(row); });
		html += '</tbody></table></div>';

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
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.customers = {
		title() { return 'Clientes'; },

		render(container, params, query) {
			const uid = 'mj-cus-' + Math.random().toString(36).slice(2, 8);
			const state = {
				search: query && query.q ? String(query.q) : '',
				linked: '',
				includeInactive: false,
				rows: null,
				suggestions: null,
				total: 0,
				page: 1,
				pages: 1,
				error: null,
				seq: 0,
				disposed: false,
				busy: {},
			};

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML =
				headerHtml() +
				'<div data-region="suggestions"></div>' +
				toolbarHtml(uid, state) +
				'<div class="mj-card mj-card--flush">' +
					'<div data-region="table">' + loadingHtml('Cargando clientes…') + '</div>' +
				'</div>';

			/* ---- painting ---- */

			function paintCount() {
				const el = qs('[data-count]', container);
				if (!el) return;
				if (!state.rows || state.error) return;
				if (!state.total) {
					el.textContent = 'A quién le vendés. No son cuentas de la tienda: son tu registro de ventas.';
					return;
				}
				el.textContent = state.total === 1 ? '1 cliente' : MJ.fmt.int(state.total) + ' clientes';
			}

			function paintTable() {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = tableHtml(state);
			}

			function paintSuggestions() {
				const el = region('suggestions');
				if (el) el.innerHTML = suggestionsHtml(state);
			}

			function syncClearButton() {
				const btn = qs('[data-filters] [data-action="clear-filters"]', container);
				if (btn) btn.disabled = !hasFilters(state);
			}

			/* ---- data ---- */

			function load() {
				if (state.disposed) return Promise.resolve();
				const mine = ++state.seq;
				const el = region('table');
				if (el) {
					if (!state.rows || state.error) el.innerHTML = loadingHtml('Cargando clientes…');
					else { el.classList.add('is-loading'); el.setAttribute('aria-busy', 'true'); }
				}

				const reqParams = { page: state.page, per_page: PER_PAGE };
				if (state.search) reqParams.search = state.search;
				if (state.linked) reqParams.linked = state.linked;
				if (!state.includeInactive) reqParams.is_active = 1;

				return MJ.api.get('customers', reqParams).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.error = null;
					state.rows = Array.isArray(res && res.items) ? res.items : [];
					state.total = MJ.fmt.toNumber(res && res.total) || 0;
					state.pages = Math.max(1, MJ.fmt.toNumber(res && res.pages) || 1);
					paintTable();
					paintCount();
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					if (state.rows) {
						paintTable();
						toastError(err, 'No se pudieron cargar los clientes.');
					} else {
						state.error = err;
						paintTable();
					}
				});
			}

			function loadSuggestions() {
				if (state.disposed) return Promise.resolve();
				return MJ.api.get('customers/suggestions', { limit: 10 }).then(function (res) {
					if (state.disposed) return;
					state.suggestions = Array.isArray(res && res.items) ? res.items : [];
					paintSuggestions();
				}).catch(function () {
					// Una sugerencia que no carga no rompe la pantalla: es una ayuda, no el contenido.
					if (state.disposed) return;
					state.suggestions = [];
					paintSuggestions();
				});
			}

			function findRow(id) {
				if (!state.rows) return null;
				return state.rows.find(function (r) { return String(r.id) === String(id); }) || null;
			}

			function rowOf(el) {
				const tr = el.closest('tr[data-id]');
				if (!tr) return null;
				const id = tr.getAttribute('data-id');
				return { id: id, row: findRow(id) };
			}

			/* ---- actions ---- */

			function openModal(row) {
				MJ.components.customerModal({
					customer: row || null,
					onSaved() { if (!state.disposed) load(); },
				});
			}

			function link(row) {
				MJ.components.customerLinkModal({
					customer: row,
					onLinked() {
						if (state.disposed) return;
						load();
						loadSuggestions();
					},
				});
			}

			function unlink(row) {
				MJ.ui.confirm({
					title: 'Desvincular la cuenta',
					message: 'El cliente «' + row.name + '» deja de estar asociado a su cuenta de la tienda. La ficha y todas sus ventas quedan como están.',
					okLabel: 'Desvincular',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.del('customers/' + encodeURIComponent(row.id) + '/link').then(function () {
						if (state.disposed) return;
						MJ.ui.toast('Cliente desvinculado.', 'success');
						load();
					}).catch(function (err) { toastError(err, 'No se pudo desvincular el cliente.'); });
				});
			}

			function remove(row) {
				if (state.busy[row.id]) return;
				MJ.ui.confirm({
					title: 'Eliminar cliente',
					message: 'Se eliminará el cliente «' + row.name + '». Si ya tiene ventas no se borra: queda archivado para no perder el historial.',
					okLabel: 'Eliminar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					state.busy[row.id] = true;
					MJ.api.del('customers/' + encodeURIComponent(row.id)).then(function (res) {
						state.busy[row.id] = false;
						if (state.disposed) return;
						MJ.ui.toast(
							res && res.archived
								? 'El cliente «' + row.name + '» tiene ventas: queda archivado en lugar de eliminarse.'
								: 'Cliente «' + row.name + '» eliminado.',
							res && res.archived ? 'info' : 'success'
						);
						load();
					}).catch(function (err) {
						state.busy[row.id] = false;
						toastError(err, 'No se pudo eliminar el cliente.');
					});
				});
			}

			function acceptSuggestion(item) {
				const userId = item.getAttribute('data-user');
				const customerId = item.getAttribute('data-customer');
				MJ.api.post('customers/' + encodeURIComponent(customerId) + '/link', { user_id: userId }).then(function () {
					if (state.disposed) return;
					MJ.ui.toast('Historiales unidos.', 'success');
					load();
					loadSuggestions();
				}).catch(function (err) { toastError(err, 'No se pudo vincular.'); });
			}

			function dismissSuggestion(item) {
				const userId = item.getAttribute('data-user');
				MJ.api.del('customers/suggestions/' + encodeURIComponent(userId)).then(function () {
					if (state.disposed) return;
					loadSuggestions();
				}).catch(function (err) { toastError(err, 'No se pudo descartar la sugerencia.'); });
			}

			/* ---- events ---- */

			const searchDebounced = MJ.ui.debounce(function () {
				state.page = 1;
				load();
			}, SEARCH_DELAY);

			container.addEventListener('input', function (e) {
				const el = e.target;
				if (el.name === 'search') {
					state.search = String(el.value || '').trim();
					syncClearButton();
					searchDebounced();
				}
			});

			container.addEventListener('change', function (e) {
				const el = e.target;
				if (el.name === 'linked') { state.linked = String(el.value || ''); state.page = 1; syncClearButton(); load(); }
				else if (el.name === 'include_inactive') { state.includeInactive = !!el.checked; state.page = 1; syncClearButton(); load(); }
			});

			container.addEventListener('click', function (e) {
				const btn = e.target.closest ? e.target.closest('[data-action]') : null;
				if (!btn) return;
				const action = btn.getAttribute('data-action');

				if (action === 'new') { openModal(null); return; }
				if (action === 'reload') { load(); loadSuggestions(); return; }
				if (action === 'prev') { if (state.page > 1) { state.page--; load(); } return; }
				if (action === 'next') { if (state.page < state.pages) { state.page++; load(); } return; }
				if (action === 'clear-filters') {
					state.search = '';
					state.linked = '';
					state.includeInactive = false;
					state.page = 1;
					const search = qs('#' + uid + '-search', container);
					const linked = qs('#' + uid + '-linked', container);
					const inactive = qs('#' + uid + '-inactive', container);
					if (search) search.value = '';
					if (linked) linked.value = '';
					if (inactive) inactive.checked = false;
					syncClearButton();
					load();
					return;
				}

				const suggestion = btn.closest ? btn.closest('[data-user]') : null;
				if (suggestion && action === 'accept-suggestion') { acceptSuggestion(suggestion); return; }
				if (suggestion && action === 'dismiss-suggestion') { dismissSuggestion(suggestion); return; }

				const found = rowOf(btn);
				if (!found || !found.row) return;

				if (action === 'edit') openModal(found.row);
				else if (action === 'link') link(found.row);
				else if (action === 'unlink') unlink(found.row);
				else if (action === 'delete') remove(found.row);
				else if (action === 'sale') MJ.router.navigate('/ventas/nueva?cliente=' + encodeURIComponent(found.row.id));
			});

			load();
			loadSuggestions();

			return function () { state.disposed = true; };
		},
	};
})();
