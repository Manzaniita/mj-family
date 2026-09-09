/**
 * MJ ERP — page-suppliers.js
 * MJ.pages.suppliers: ABM de proveedores y de cajas en una sola pantalla con dos solapas
 * ("Proveedores" y "Cajas"). La solapa activa se guarda en el query string (?tab=cajas)
 * y se sincroniza con MJ.router.navigate(..., { replace: true }).
 *
 * REST usado: GET /suppliers?search=&include_inactive=, DELETE /suppliers/{id},
 *             GET /cashboxes?search=&include_inactive=, DELETE /cashboxes/{id}.
 * Las altas y ediciones se resuelven con MJ.components.supplierModal / MJ.components.cashboxModal,
 * que hacen POST/PUT y refrescan MJ.state por su cuenta.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PATH = '/proveedores';
	const TAB_SUPPLIERS = 'proveedores';
	const TAB_CASHBOXES = 'cajas';
	const SEARCH_DELAY = 350;

	/* ------------------------------------------------------------------ */
	/* Local helpers                                                       */
	/* ------------------------------------------------------------------ */

	/** First non-empty string, trimmed (used for server messages with a local fallback). */
	function serverText(value, fallback) {
		const s = value === null || value === undefined ? '' : String(value).trim();
		return s || fallback || '';
	}

	/** Human readable message for anything thrown by the API layer. */
	function errMessage(err, fallback) {
		if (!err) return fallback || 'Ocurrió un error inesperado.';
		if (typeof err === 'string') return err;
		if (err.message) return String(err.message);
		return fallback || 'Ocurrió un error inesperado.';
	}

	/** Toast an API error, ignoring the ones core.js already handled (401 / sesión expirada). */
	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	/** The REST list endpoints answer a bare array; be tolerant with wrapped shapes too. */
	function asRows(res) {
		if (Array.isArray(res)) return res;
		if (res && typeof res === 'object') {
			if (Array.isArray(res.items)) return res.items;
			if (Array.isArray(res.suppliers)) return res.suppliers;
			if (Array.isArray(res.cashboxes)) return res.cashboxes;
		}
		return [];
	}

	/** `is_active` may arrive as bool, 1/0 or "1"/"0"; missing means active. */
	function isActive(row) {
		if (!row) return false;
		const v = row.is_active;
		if (v === undefined || v === null || v === '') return true;
		if (typeof v === 'boolean') return v;
		if (typeof v === 'number') return v !== 0;
		const s = String(v).trim().toLowerCase();
		return !(s === '0' || s === 'false' || s === 'no');
	}

	function statusBadge(row, cfg) {
		const active = isActive(row);
		return MJ.ui.badgeRaw(active ? cfg.activeLabel : cfg.inactiveLabel, active ? 'success' : 'neutral');
	}

	function dash() { return '<span class="mj-muted">—</span>'; }

	function mailCell(email) {
		const value = String(email || '').trim();
		if (!value) return dash();
		if (value.indexOf('@') === -1) return '<span class="mj-truncate">' + h(value) + '</span>';
		return '<a class="mj-link mj-truncate" href="' + h('mailto:' + value) + '" title="' + h(value) + '">' + h(value) + '</a>';
	}

	function phoneCell(phone) {
		const value = String(phone || '').trim();
		if (!value) return dash();
		const dial = value.replace(/[^\d+]/g, '');
		if (!dial || dial === '+') return '<span>' + h(value) + '</span>';
		return '<a class="mj-link" href="' + h('tel:' + dial) + '">' + h(value) + '</a>';
	}

	function textCell(value) {
		const s = String(value === null || value === undefined ? '' : value).trim();
		return s ? h(s) : dash();
	}

	function currencyCell(value) {
		const s = String(value || '').trim().toUpperCase();
		return s ? '<span class="mj-mono">' + h(s) + '</span>' : dash();
	}

	function rowName(row, cfg) {
		const name = row && row.name ? String(row.name).trim() : '';
		return name || (cfg.itemLabel + ' #' + (row && row.id ? row.id : '?'));
	}

	function actionsCell(row, cfg) {
		const name = rowName(row, cfg);
		return '<td class="is-actions">' +
			'<div class="mj-btn-group">' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit" title="Editar" aria-label="' + h('Editar ' + name) + '">' + MJ.icon('edit') + '</button>' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete" title="Eliminar" aria-label="' + h('Eliminar ' + name) + '">' + MJ.icon('trash') + '</button>' +
			'</div>' +
		'</td>';
	}

	function supplierRowHtml(s, cfg) {
		const contact = String(s.contact_name || '').trim();
		return '<tr data-id="' + h(s.id) + '"' + (isActive(s) ? '' : ' class="is-inactive"') + '>' +
			'<td>' +
				'<div class="mj-strong mj-truncate" title="' + h(rowName(s, cfg)) + '">' + h(rowName(s, cfg)) + '</div>' +
				(contact ? '<div class="mj-table__sub mj-muted mj-truncate" title="' + h(contact) + '">' + h(contact) + '</div>' : '') +
			'</td>' +
			'<td>' + mailCell(s.email) + '</td>' +
			'<td>' + phoneCell(s.phone) + '</td>' +
			'<td>' + textCell(s.country) + '</td>' +
			'<td>' + currencyCell(s.default_currency) + '</td>' +
			'<td>' + statusBadge(s, cfg) + '</td>' +
			actionsCell(s, cfg) +
		'</tr>';
	}

	function cashboxRowHtml(c, cfg) {
		return '<tr data-id="' + h(c.id) + '"' + (isActive(c) ? '' : ' class="is-inactive"') + '>' +
			'<td><div class="mj-strong mj-truncate" title="' + h(rowName(c, cfg)) + '">' + h(rowName(c, cfg)) + '</div></td>' +
			'<td>' + currencyCell(c.currency) + '</td>' +
			'<td>' + statusBadge(c, cfg) + '</td>' +
			actionsCell(c, cfg) +
		'</tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Tab descriptors — everything that differs between both tabs         */
	/* ------------------------------------------------------------------ */

	const TABS = {};

	TABS[TAB_SUPPLIERS] = {
		key: TAB_SUPPLIERS,
		label: 'Proveedores',
		icon: 'users',
		endpoint: 'suppliers',
		itemLabel: 'Proveedor',
		activeLabel: 'Activo',
		inactiveLabel: 'Inactivo',
		newLabel: 'Nuevo proveedor',
		searchLabel: 'Buscar proveedor',
		searchPlaceholder: 'Nombre, contacto, email, teléfono o país…',
		inactiveToggleLabel: 'Mostrar inactivos',
		subtitle: 'Quiénes te venden: contacto, moneda habitual y estado.',
		loadingText: 'Cargando proveedores…',
		loadError: 'No se pudieron cargar los proveedores.',
		deleteError: 'No se pudo eliminar el proveedor.',
		emptyTitle: 'Todavía no hay proveedores',
		emptyText: 'Cargá tus proveedores para asociarlos a cada compra y ver de quién viene la mercadería.',
		emptySearchText: 'Ningún proveedor coincide con la búsqueda.',
		note: '',
		count(n) { return n === 1 ? '1 proveedor' : MJ.fmt.int(n) + ' proveedores'; },
		foundWord: 'encontrados',
		inactiveHint: 'incluye inactivos',
		headHtml: '<th>Nombre</th><th>Email</th><th>Teléfono</th><th>País</th><th>Moneda</th><th>Estado</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>',
		rowHtml: supplierRowHtml,
		openModal(row, onSaved) { MJ.components.supplierModal({ supplier: row || null, onSaved: onSaved }); },
		refreshState() { return MJ.refreshSuppliers(); },
		deleteTitle: 'Eliminar proveedor',
		deleteMessage(name) {
			return 'Se eliminará el proveedor «' + name + '». Si tiene compras asociadas no se borra: queda desactivado para no perder el historial.';
		},
		deletedToast(name) { return 'Proveedor «' + name + '» eliminado.'; },
		deactivatedToast(name) {
			return 'El proveedor «' + name + '» tiene compras asociadas: queda como inactivo en lugar de eliminarse.';
		},
	};

	TABS[TAB_CASHBOXES] = {
		key: TAB_CASHBOXES,
		label: 'Cajas',
		icon: 'cash',
		endpoint: 'cashboxes',
		itemLabel: 'Caja',
		activeLabel: 'Activa',
		inactiveLabel: 'Inactiva',
		newLabel: 'Nueva caja',
		searchLabel: 'Buscar caja',
		searchPlaceholder: 'Nombre o moneda…',
		inactiveToggleLabel: 'Mostrar inactivas',
		subtitle: 'De dónde sale la plata de cada gasto de importación.',
		loadingText: 'Cargando cajas…',
		loadError: 'No se pudieron cargar las cajas.',
		deleteError: 'No se pudo eliminar la caja.',
		emptyTitle: 'Todavía no hay cajas',
		emptyText: 'Creá una caja por cada origen de fondos (pesos, dólares, cuenta bancaria) para imputar los gastos.',
		emptySearchText: 'Ninguna caja coincide con la búsqueda.',
		note: 'Las cajas son la base del módulo «Caja» que llega más adelante: por ahora se usan para indicar de dónde sale cada gasto de una importación. Cuando el módulo esté listo, estos mismos registros van a llevar saldos y movimientos.',
		count(n) { return n === 1 ? '1 caja' : MJ.fmt.int(n) + ' cajas'; },
		foundWord: 'encontradas',
		inactiveHint: 'incluye inactivas',
		headHtml: '<th>Nombre</th><th>Moneda</th><th>Estado</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>',
		rowHtml: cashboxRowHtml,
		openModal(row, onSaved) { MJ.components.cashboxModal({ cashbox: row || null, onSaved: onSaved }); },
		refreshState() { return MJ.refreshCashboxes(); },
		deleteTitle: 'Eliminar caja',
		deleteMessage(name) {
			return 'Se eliminará la caja «' + name + '». Si tiene gastos asociados no se borra: queda desactivada para no perder el historial.';
		},
		deletedToast(name) { return 'Caja «' + name + '» eliminada.'; },
		deactivatedToast(name) {
			return 'La caja «' + name + '» tiene gastos asociados: queda como inactiva en lugar de eliminarse.';
		},
	};

	function tabFromQuery(query) {
		const raw = query && query.tab ? String(query.tab).toLowerCase().trim() : '';
		return TABS[raw] ? raw : TAB_SUPPLIERS;
	}

	function pathForTab(tab) {
		return tab === TAB_SUPPLIERS ? PATH : PATH + '?tab=' + encodeURIComponent(tab);
	}

	/* ------------------------------------------------------------------ */
	/* HTML builders                                                       */
	/* ------------------------------------------------------------------ */

	function headerHtml(cfg) {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Proveedores</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-count>' + h(cfg.subtitle) + '</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>' + h(cfg.newLabel) + '</span></button>' +
			'</div>' +
		'</div>';
	}

	function tabsHtml(uid, activeTab) {
		const items = [TABS[TAB_SUPPLIERS], TABS[TAB_CASHBOXES]].map(function (cfg) {
			const active = cfg.key === activeTab;
			return '<button type="button" class="mj-tabs__tab' + (active ? ' is-active' : '') + '" data-tab="' + h(cfg.key) + '" ' +
				'id="' + uid + '-tab-' + h(cfg.key) + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '" ' +
				'aria-controls="' + uid + '-panel">' + MJ.icon(cfg.icon) + '<span>' + h(cfg.label) + '</span></button>';
		}).join('');
		return '<div class="mj-tabs" role="tablist" aria-label="Secciones de proveedores">' + items + '</div>';
	}

	function toolbarHtml(uid, state) {
		const cfg = state.cfg;
		return '<div class="mj-card mj-mb">' +
			'<div class="mj-card__body">' +
				'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-filters novalidate autocomplete="off">' +
					'<div class="mj-field" style="flex:2 1 260px">' +
						'<label class="mj-field__label" for="' + uid + '-search">' + h(cfg.searchLabel) + '</label>' +
						'<div class="mj-input-group">' +
							'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
							'<input class="mj-input" id="' + uid + '-search" type="search" name="search" placeholder="' + h(cfg.searchPlaceholder) + '" value="' + h(state.search) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<label class="mj-checkbox" for="' + uid + '-inactive">' +
							'<input type="checkbox" id="' + uid + '-inactive" name="include_inactive"' + (state.includeInactive ? ' checked' : '') + '>' +
							'<span>' + h(cfg.inactiveToggleLabel) + '</span>' +
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
		return !!(state.search || state.includeInactive);
	}

	function tableHtml(state) {
		const cfg = state.cfg;
		if (state.error) {
			return '<div class="mj-card__body">' +
				MJ.ui.alert(errMessage(state.error, cfg.loadError), 'danger') +
				'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
			'</div>';
		}
		if (!state.rows) return loadingHtml(cfg.loadingText);
		if (!state.rows.length) {
			if (hasFilters(state)) {
				return MJ.ui.empty({
					icon: 'search',
					title: 'Sin resultados',
					text: cfg.emptySearchText,
					action: '<button type="button" class="mj-btn" data-action="clear-filters">' + MJ.icon('x') + '<span>Limpiar filtros</span></button>',
				});
			}
			return MJ.ui.empty({
				icon: cfg.icon,
				title: cfg.emptyTitle,
				text: cfg.emptyText,
				action: '<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>' + h(cfg.newLabel) + '</span></button>',
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' + cfg.headHtml + '</tr></thead><tbody>';
		state.rows.forEach(function (row) { html += cfg.rowHtml(row, cfg); });
		html += '</tbody></table></div>';
		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.suppliers = {
		title(params, query) {
			const cfg = TABS[tabFromQuery(query)];
			return cfg.key === TAB_SUPPLIERS ? 'Proveedores' : 'Proveedores · ' + cfg.label;
		},

		render(container, params, query) {
			const uid = 'mj-sup-' + Math.random().toString(36).slice(2, 8);
			const tab = tabFromQuery(query);
			const state = {
				tab: tab,
				cfg: TABS[tab],
				search: '',
				includeInactive: false,
				rows: null,
				error: null,
				seq: 0,
				disposed: false,
				busy: {},
			};
			const offs = [];
			let searchDebounced = null;

			const cfg = state.cfg;
			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML =
				headerHtml(cfg) +
				tabsHtml(uid, state.tab) +
				'<div id="' + uid + '-panel" role="tabpanel" aria-labelledby="' + uid + '-tab-' + h(state.tab) + '" data-tab-panel="' + h(state.tab) + '">' +
					(cfg.note ? MJ.ui.alert(cfg.note, 'info', { className: 'mj-mb mj-note' }) : '') +
					toolbarHtml(uid, state) +
					'<div class="mj-card mj-card--flush">' +
						'<div data-region="table">' + loadingHtml(cfg.loadingText) + '</div>' +
					'</div>' +
				'</div>';

			/* ---- painting ---- */

			function paintCount() {
				const el = qs('[data-count]', container);
				if (!el) return;
				if (!state.rows || state.error) { el.textContent = cfg.subtitle; return; }
				if (!state.rows.length && !hasFilters(state)) { el.textContent = cfg.subtitle; return; }
				let label = cfg.count(state.rows.length);
				if (state.search) label += ' ' + cfg.foundWord + ' con la búsqueda';
				else if (state.includeInactive) label += ' (' + cfg.inactiveHint + ')';
				el.textContent = label;
			}

			function paintTable() {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = tableHtml(state);
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
					if (!state.rows || state.error) el.innerHTML = loadingHtml(cfg.loadingText);
					else { el.classList.add('is-loading'); el.setAttribute('aria-busy', 'true'); }
				}
				const reqParams = {};
				if (state.search) reqParams.search = state.search;
				if (state.includeInactive) reqParams.include_inactive = 1;

				return MJ.api.get(cfg.endpoint, reqParams).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.error = null;
					state.rows = asRows(res).filter(function (row) { return row && typeof row === 'object'; });
					paintTable();
					paintCount();
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					if (state.rows) {
						paintTable();
						toastError(err, cfg.loadError);
					} else {
						state.error = err;
						paintTable();
						paintCount();
					}
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
				return { id: id, row: findRow(id), tr: tr };
			}

			/* ---- actions ---- */

			function openModal(row) {
				try {
					cfg.openModal(row, function () {
						// El modal ya hizo POST/PUT y refrescó MJ.state; sólo hay que releer la tabla.
						if (state.disposed) return;
						load();
					});
				} catch (e) {
					console.error('[MJ] ' + cfg.endpoint + ' modal', e);
					MJ.ui.toast('No se pudo abrir el formulario.', 'error');
				}
			}

			function edit(btn) {
				const found = rowOf(btn);
				if (!found) return;
				if (!found.row) { load(); return; }
				openModal(found.row);
			}

			function remove(btn) {
				const found = rowOf(btn);
				if (!found || state.busy[found.id]) return;
				const name = rowName(found.row || { id: found.id }, cfg);
				MJ.ui.confirm({
					title: cfg.deleteTitle,
					message: cfg.deleteMessage(name),
					okLabel: 'Eliminar',
					cancelLabel: 'Cancelar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					state.busy[found.id] = true;
					MJ.ui.setLoading(btn, true);
					MJ.api.del(cfg.endpoint + '/' + encodeURIComponent(found.id)).then(function (res) {
						delete state.busy[found.id];
						if (state.disposed) return;
						const deactivated = !!(res && res.deactivated);
						if (deactivated) {
							MJ.ui.toast(serverText(res && res.message, cfg.deactivatedToast(name)), 'warning');
						} else {
							MJ.ui.toast(cfg.deletedToast(name), 'success');
						}
						// Mantener MJ.state (selects de otras pantallas) en sincronía.
						return cfg.refreshState().catch(function () { return null; }).then(function () {
							if (state.disposed) return null;
							return load();
						});
					}).catch(function (err) {
						delete state.busy[found.id];
						if (state.disposed) return;
						MJ.ui.setLoading(btn, false);
						toastError(err, cfg.deleteError);
					});
				});
			}

			function applySearch(value) {
				const next = String(value || '').trim();
				if (next === state.search) return;
				state.search = next;
				syncClearButton();
				load();
			}

			function applyIncludeInactive(checked) {
				const next = !!checked;
				if (next === state.includeInactive) return;
				state.includeInactive = next;
				syncClearButton();
				load();
			}

			function clearFilters() {
				const form = qs('[data-filters]', container);
				if (form) {
					const searchEl = qs('[name="search"]', form);
					const inactiveEl = qs('[name="include_inactive"]', form);
					if (searchEl) searchEl.value = '';
					if (inactiveEl) inactiveEl.checked = false;
				}
				if (searchDebounced && searchDebounced.cancel) searchDebounced.cancel();
				if (!hasFilters(state)) { syncClearButton(); return; }
				state.search = '';
				state.includeInactive = false;
				syncClearButton();
				load();
			}

			/** La solapa vive en la URL: navegar (replace) vuelve a renderizar la página con ?tab=. */
			function goToTab(next) {
				if (!TABS[next] || next === state.tab) return;
				MJ.router.navigate(pathForTab(next), { replace: true });
			}

			/* ---- events ---- */

			function bind() {
				const form = qs('[data-filters]', container);
				if (form) {
					const searchEl = qs('[name="search"]', form);
					if (searchEl) {
						searchDebounced = MJ.ui.debounce(function () { applySearch(searchEl.value); }, SEARCH_DELAY);
						searchEl.addEventListener('input', searchDebounced);
						searchEl.addEventListener('search', function () {
							if (searchDebounced.cancel) searchDebounced.cancel();
							applySearch(searchEl.value);
						});
						offs.push(function () { searchEl.removeEventListener('input', searchDebounced); });
					}
					offs.push(MJ.ui.on(form, 'submit', function (e) {
						e.preventDefault();
						if (searchDebounced && searchDebounced.cancel) searchDebounced.cancel();
						if (searchEl) applySearch(searchEl.value);
					}));
					offs.push(MJ.ui.on(form, 'change', '[name="include_inactive"]', function (e, input) {
						applyIncludeInactive(input.checked);
					}));
				}

				offs.push(MJ.ui.on(container, 'click', '.mj-tabs__tab[data-tab]', function (e, btn) {
					e.preventDefault();
					goToTab(btn.getAttribute('data-tab'));
				}));

				offs.push(MJ.ui.on(container, 'click', '[data-action]', function (e, btn) {
					const action = btn.getAttribute('data-action');
					if (action === 'new') { e.preventDefault(); openModal(null); }
					else if (action === 'edit') { e.preventDefault(); edit(btn); }
					else if (action === 'delete') { e.preventDefault(); remove(btn); }
					else if (action === 'clear-filters') { e.preventDefault(); clearFilters(); }
					else if (action === 'reload') {
						e.preventDefault();
						MJ.ui.setLoading(btn, true);
						load().then(function () { if (!state.disposed) MJ.ui.setLoading(btn, false); });
					}
				}));
			}

			bind();
			load();

			return function cleanup() {
				state.disposed = true;
				state.seq++;
				if (searchDebounced && searchDebounced.cancel) searchDebounced.cancel();
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
				offs.length = 0;
			};
		},
	};
})();
