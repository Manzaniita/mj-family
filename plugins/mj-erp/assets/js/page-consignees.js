/**
 * MJ ERP — page-consignees.js
 * MJ.pages.consignees: pantalla de Consignaciones con dos solapas — «Revendedores» (quién tiene
 * qué y quién debe cuánto) y «Entregas» (los documentos de salida de mercadería).
 *
 * La solapa activa vive en el query string (?tab=entregas).
 *
 * REST usado: GET /consignees?with_account=1, DELETE /consignees/{id},
 *             GET /consignments, POST /consignments, GET /consignees/candidates,
 *             POST /consignees, PUT /consignees/{id}.
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
	const TAB_PEOPLE = 'revendedores';
	const TAB_DELIVERIES = 'entregas';
	const SEARCH_DELAY = 350;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function num(v) { return MJ.fmt.toNumber(v); }

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCur() { return String(setting('base_currency', 'USD')).toUpperCase(); }

	/** "US$ 129": sin decimales cuando el importe es entero. */
	function moneyBase(value) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, baseCur(), decimals);
	}

	function dash() { return '<span class="mj-muted">—</span>'; }

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function tabFromQuery(query) {
		const raw = query && query.tab ? String(query.tab).toLowerCase() : '';
		return raw === TAB_DELIVERIES ? TAB_DELIVERIES : TAB_PEOPLE;
	}

	function consigneeLink(id) { return MJ.router.link(PATH + '/revendedor/' + id); }
	function consignmentLink(id) { return MJ.router.link(PATH + '/entrega/' + id); }

	function statusBadge(status) {
		const map = (MJ.state.bootstrap && MJ.state.bootstrap.consignment && MJ.state.bootstrap.consignment.statuses) || {};
		const info = map[status] || { label: status || '—', color: 'neutral' };
		return MJ.ui.badgeRaw(info.label, info.color);
	}

	/**
	 * Saldo del revendedor. Positivo = me debe; negativo = tiene saldo a favor.
	 * El color no es decorativo: es la primera cosa que se mira en esta tabla.
	 */
	function balanceCell(account) {
		const balance = num(account && account.balance_base);
		if (balance === null) return '<td class="is-num">' + dash() + '</td>';
		if (Math.abs(balance) < 0.005) {
			return '<td class="is-num"><span class="mj-muted">' + h(moneyBase(0)) + '</span></td>';
		}
		const owes = balance > 0;
		const cls = owes ? (account.over_limit ? 'mj-danger-text' : 'mj-strong') : 'mj-success-text';
		const sub = owes && account.over_limit
			? '<div class="mj-table__sub mj-small mj-danger-text">Pasó el límite</div>'
			: (!owes ? '<div class="mj-table__sub mj-small mj-muted">A favor</div>' : '');
		return '<td class="is-num"><span class="' + cls + '">' + h(moneyBase(Math.abs(balance))) + '</span>' + sub + '</td>';
	}

	function stockCell(account) {
		const units = num(account && account.on_hand_units);
		if (units === null || units <= 0) {
			return '<td class="is-num"><span class="mj-muted">Sin stock</span></td>';
		}
		return '<td class="is-num">' +
			'<span class="mj-strong">' + h(MJ.fmt.qty(units)) + '</span>' +
			'<div class="mj-table__sub mj-small mj-muted">' + h(moneyBase(account.on_hand_value_base)) + '</div>' +
		'</td>';
	}

	function pendingCell(account) {
		const pending = parseInt(account && account.pending_sales, 10) || 0;
		if (!pending) return '<td class="is-num">' + dash() + '</td>';
		return '<td class="is-num">' +
			MJ.ui.badgeRaw(pending === 1 ? '1 a confirmar' : pending + ' a confirmar', 'warning') +
		'</td>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — revendedores                                                 */
	/* ------------------------------------------------------------------ */

	function headerHtml(tab, totals) {
		const isPeople = tab === TAB_PEOPLE;
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Consignaciones</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-region="subtitle">' + h(subtitleText(totals)) + '</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				(isPeople
					? '<button type="button" class="mj-btn mj-btn--primary" data-action="new-consignee">' + MJ.icon('plus') + '<span>Nuevo revendedor</span></button>'
					: '<button type="button" class="mj-btn mj-btn--primary" data-action="new-consignment">' + MJ.icon('plus') + '<span>Nueva entrega</span></button>') +
			'</div>' +
		'</div>';
	}

	function subtitleText(totals) {
		if (!totals) return 'Mercadería entregada a revendedores, ventas y cuenta corriente';
		const parts = [];
		const people = parseInt(totals.consignees, 10) || 0;
		parts.push(people === 1 ? '1 revendedor activo' : people + ' revendedores activos');
		const balance = num(totals.balance_base) || 0;
		if (Math.abs(balance) >= 0.005) {
			parts.push(balance > 0 ? 'te deben ' + moneyBase(balance) : 'saldo a favor ' + moneyBase(Math.abs(balance)));
		}
		const units = num(totals.on_hand_units) || 0;
		if (units > 0) parts.push(MJ.fmt.qty(units) + ' unidades en la calle');
		return parts.join(' · ');
	}

	function tabsHtml(uid, active) {
		const tabs = [
			{ key: TAB_PEOPLE, label: 'Revendedores', icon: 'users' },
			{ key: TAB_DELIVERIES, label: 'Entregas', icon: 'truck' },
		];
		return '<div class="mj-tabs" role="tablist">' +
			tabs.map(function (t) {
				const on = t.key === active;
				return '<a class="mj-tabs__tab' + (on ? ' is-active' : '') + '" role="tab" id="' + uid + '-tab-' + t.key + '" ' +
					'aria-selected="' + (on ? 'true' : 'false') + '" aria-controls="' + uid + '-panel" ' +
					'href="' + h(MJ.router.link(PATH + (t.key === TAB_PEOPLE ? '' : '?tab=' + t.key))) + '">' +
					MJ.icon(t.icon) + '<span>' + h(t.label) + '</span></a>';
			}).join('') +
		'</div>';
	}

	function alertsHtml(totals) {
		if (!totals) return '';
		let out = '';
		const pending = parseInt(totals.pending_sales, 10) || 0;
		if (pending) {
			out += MJ.ui.alert(
				pending === 1
					? 'Hay 1 venta cargada por un revendedor esperando que la confirmes. Hasta que la confirmes no baja el stock ni se genera la deuda.'
					: 'Hay ' + MJ.fmt.int(pending) + ' ventas cargadas por revendedores esperando que las confirmes. Hasta que las confirmes no baja el stock ni se genera la deuda.',
				'warning'
			);
		}
		const credit = num(totals.unallocated_base) || 0;
		if (credit >= 0.005) {
			out += MJ.ui.alert('Hay ' + moneyBase(credit) + ' en pagos que todavía no están imputados a ninguna venta: quedan como saldo a favor y se aplican solos en la próxima venta.', 'info');
		}
		const over = parseInt(totals.over_limit, 10) || 0;
		if (over) {
			out += MJ.ui.alert(over === 1 ? 'Un revendedor pasó su límite de crédito.' : over + ' revendedores pasaron su límite de crédito.', 'danger');
		}
		return out;
	}

	function peopleFiltersHtml(state) {
		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<form class="mj-form mj-inline-form mj-row mj-row--wrap" novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:2 1 260px">' +
					'<label class="mj-field__label" for="mj-cons-search">Buscar revendedor</label>' +
					'<div class="mj-input-group">' +
						'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
						'<input class="mj-input" id="mj-cons-search" type="search" placeholder="Nombre, email o teléfono…" value="' + h(state.search) + '" data-filter="search">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 auto">' +
					'<label class="mj-field__label">&nbsp;</label>' +
					'<label class="mj-checkbox"><input type="checkbox" data-filter="inactive"' + (state.includeInactive ? ' checked' : '') + '> <span>Ver desactivados</span></label>' +
				'</div>' +
			'</form>' +
		'</div></div>';
	}

	function peopleTableHtml(rows) {
		if (!rows.length) {
			return MJ.ui.empty({
				icon: 'users',
				title: 'Todavía no cargaste revendedores',
				text: 'Un revendedor es un cliente con rol de reventa a quien le dejás mercadería para que venda. Se crea como usuario de WordPress, así queda también en WooCommerce.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Revendedor</th>' +
			'<th>Lista</th>' +
			'<th class="is-num">En su poder</th>' +
			'<th class="is-num">Saldo</th>' +
			'<th class="is-num">Ventas</th>' +
			'<th class="is-num">Pendientes</th>' +
			'<th>Último movimiento</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		rows.map(peopleRowHtml).join('') +
		'</tbody></table></div>';
	}

	function peopleRowHtml(row) {
		const account = row.account || {};
		const active = row.is_active !== false;
		const avatar = row.avatar
			? '<img class="mj-avatar mj-avatar--sm" src="' + h(row.avatar) + '" alt="" width="28" height="28" loading="lazy">'
			: '';
		const list = row.price_list && row.price_list.label ? row.price_list.label : '';
		const lastSale = account.last_sale_date ? MJ.fmt.date(account.last_sale_date) : '';
		const lastPay = account.last_payment_date ? MJ.fmt.date(account.last_payment_date) : '';

		let last = dash();
		if (lastSale || lastPay) {
			last = '<div class="mj-small">' +
				(lastSale ? '<div>Venta ' + h(lastSale) + '</div>' : '') +
				(lastPay ? '<div class="mj-muted">Pago ' + h(lastPay) + '</div>' : '') +
			'</div>';
		}

		return '<tr data-id="' + h(row.id) + '">' +
			'<td>' +
				'<div class="mj-row mj-row--center">' + avatar +
					'<div>' +
						'<a class="mj-link mj-strong" href="' + h(consigneeLink(row.id)) + '">' + h(row.name || ('Revendedor #' + row.id)) + '</a>' +
						(active ? '' : ' ' + MJ.ui.badgeRaw('Desactivado', 'neutral')) +
						(row.email ? '<div class="mj-small mj-muted mj-truncate">' + h(row.email) + '</div>' : '') +
					'</div>' +
				'</div>' +
			'</td>' +
			'<td>' + (list ? h(list) : dash()) + '</td>' +
			stockCell(account) +
			balanceCell(account) +
			'<td class="is-num">' + h(MJ.fmt.int(account.sales_count || 0)) + '</td>' +
			pendingCell(account) +
			'<td>' + last + '</td>' +
			'<td class="is-actions">' +
				'<div class="mj-btn-group">' +
					'<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(consigneeLink(row.id)) + '" title="Abrir cuenta" aria-label="' + h('Abrir la cuenta de ' + (row.name || '')) + '">' + MJ.icon('eye') + '</a>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="edit" title="Editar" aria-label="' + h('Editar ' + (row.name || '')) + '">' + MJ.icon('edit') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="delete" title="Eliminar" aria-label="' + h('Eliminar ' + (row.name || '')) + '">' + MJ.icon('trash') + '</button>' +
				'</div>' +
			'</td>' +
		'</tr>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — entregas                                                     */
	/* ------------------------------------------------------------------ */

	function deliveriesFiltersHtml(state) {
		const statuses = (MJ.state.bootstrap && MJ.state.bootstrap.consignment && MJ.state.bootstrap.consignment.statuses) || {};
		const options = Object.keys(statuses).map(function (key) {
			return { value: key, label: statuses[key].label };
		});
		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<form class="mj-form mj-inline-form mj-row mj-row--wrap" novalidate autocomplete="off">' +
				'<div class="mj-field" style="flex:2 1 240px">' +
					'<label class="mj-field__label" for="mj-del-search">Buscar entrega</label>' +
					'<div class="mj-input-group">' +
						'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
						'<input class="mj-input" id="mj-del-search" type="search" placeholder="Código…" value="' + h(state.search) + '" data-filter="search">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field" style="flex:1 1 180px">' +
					'<label class="mj-field__label" for="mj-del-status">Estado</label>' +
					'<select class="mj-select" id="mj-del-status" data-filter="status">' +
						'<option value="">Todos los estados</option>' +
						options.map(function (o) {
							return '<option value="' + h(o.value) + '"' + (state.status === o.value ? ' selected' : '') + '>' + h(o.label) + '</option>';
						}).join('') +
					'</select>' +
				'</div>' +
			'</form>' +
		'</div></div>';
	}

	function deliveriesTableHtml(result) {
		const rows = (result && result.items) || [];
		if (!rows.length) {
			return MJ.ui.empty({
				icon: 'truck',
				title: 'Todavía no registraste entregas',
				text: 'Una entrega es la mercadería que le dejás a un revendedor, con el precio acordado por unidad. El stock recién sale de WooCommerce cuando el revendedor vende.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Código</th>' +
			'<th>Revendedor</th>' +
			'<th>Estado</th>' +
			'<th>Fecha</th>' +
			'<th class="is-num">Productos</th>' +
			'<th class="is-num">Unidades</th>' +
			'<th class="is-num">Valor acordado</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		rows.map(function (row) {
			return '<tr data-id="' + h(row.id) + '">' +
				'<td><a class="mj-link mj-mono mj-strong" href="' + h(consignmentLink(row.id)) + '">' + h(row.code || ('#' + row.id)) + '</a></td>' +
				'<td>' + (row.consignee_name ? h(row.consignee_name) : dash()) + '</td>' +
				'<td>' + statusBadge(row.status) + '</td>' +
				'<td>' + (row.delivered_at ? h(MJ.fmt.date(row.delivered_at)) : dash()) + '</td>' +
				'<td class="is-num">' + h(MJ.fmt.int(row.items_count || 0)) + '</td>' +
				'<td class="is-num">' + h(MJ.fmt.qty(row.qty_total || 0)) + '</td>' +
				'<td class="is-num"><span class="mj-money">' + h(moneyBase(row.value_total_base)) + '</span></td>' +
				'<td class="is-actions">' +
					'<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(consignmentLink(row.id)) + '" title="Abrir entrega" aria-label="Abrir entrega">' + MJ.icon('eye') + '</a>' +
				'</td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>' +
		pagerHtml(result);
	}

	function pagerHtml(result) {
		return MJ.components.pagination({
			page: result && result.page,
			pages: result && result.pages,
			total: result && result.total,
		}).html;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.consignees = {
		title(params, query) {
			return tabFromQuery(query) === TAB_DELIVERIES ? 'Consignaciones · Entregas' : 'Consignaciones';
		},

		render(container, params, query) {
			const uid = 'mj-cons-' + Math.random().toString(36).slice(2, 8);
			const state = {
				tab: tabFromQuery(query),
				search: '',
				status: '',
				includeInactive: false,
				page: 1,
				people: null,
				deliveries: null,
				totals: null,
				error: null,
				seq: 0,
				disposed: false,
			};
			const offs = [];
			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML =
				headerHtml(state.tab, null) +
				tabsHtml(uid, state.tab) +
				'<div id="' + uid + '-panel" role="tabpanel" data-tab-panel="' + h(state.tab) + '">' +
					'<div data-region="alerts"></div>' +
					'<div data-region="filters"></div>' +
					'<div data-region="list">' + loadingHtml() + '</div>' +
				'</div>';

			function paintFilters() {
				const el = region('filters');
				if (!el) return;
				el.innerHTML = state.tab === TAB_PEOPLE ? peopleFiltersHtml(state) : deliveriesFiltersHtml(state);
			}

			function paintAlerts() {
				const el = region('alerts');
				if (el) el.innerHTML = alertsHtml(state.totals);
			}

			function paintSubtitle() {
				const el = region('subtitle');
				if (el) el.textContent = subtitleText(state.totals);
			}

			function paintList() {
				const el = region('list');
				if (!el) return;
				if (state.error) {
					el.innerHTML = MJ.ui.alert(state.error, 'danger');
					return;
				}
				if (state.tab === TAB_PEOPLE) {
					el.innerHTML = state.people === null ? loadingHtml() : peopleTableHtml(state.people);
				} else {
					el.innerHTML = state.deliveries === null ? loadingHtml() : deliveriesTableHtml(state.deliveries);
				}
			}

			function load() {
				const seq = ++state.seq;
				state.error = null;
				paintList();

				const request = state.tab === TAB_PEOPLE
					? MJ.api.get('consignees', {
						search: state.search || undefined,
						include_inactive: state.includeInactive ? 1 : undefined,
						with_account: 1,
					})
					: MJ.api.get('consignments', {
						search: state.search || undefined,
						status: state.status || undefined,
						page: state.page,
						per_page: 20,
					});

				return request.then(function (res) {
					if (state.disposed || seq !== state.seq) return;
					if (state.tab === TAB_PEOPLE) {
						state.people = Array.isArray(res && res.items) ? res.items : [];
						if (res && res.totals) {
							state.totals = res.totals;
							// Los totales recién traídos son más frescos que los del bootstrap.
							MJ.events.emit('consignment:changed', { pending_sales: res.totals.pending_sales });
						}
					} else {
						state.deliveries = res && typeof res === 'object' ? res : { items: [] };
					}
					paintList();
					paintAlerts();
					paintSubtitle();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = MJ.api.message(err, 'No se pudo cargar la información de consignaciones.');
					paintList();
				});
			}

			paintFilters();
			load();

			/* ---------------- filters ---------------- */

			const debouncedLoad = MJ.ui.debounce(function () {
				state.page = 1;
				load();
			}, SEARCH_DELAY);

			offs.push(MJ.ui.on(container, 'input', '[data-filter="search"]', function (e, el) {
				state.search = String(el.value || '').trim();
				debouncedLoad();
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-filter="inactive"]', function (e, el) {
				state.includeInactive = !!el.checked;
				load();
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-filter="status"]', function (e, el) {
				state.status = String(el.value || '');
				state.page = 1;
				load();
			}));

			offs.push(MJ.components.bindPagination(container, function (page) {
				state.page = page;
				load();
				MJ.ui.scrollTop();
			}));

			/* ---------------- actions ---------------- */

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-consignee"]', function () {
				MJ.components.consigneeModal({
					onSaved() { load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-consignment"]', function () {
				MJ.components.consignmentCreateModal({
					onCreated(consignment) {
						if (consignment && consignment.id) MJ.router.navigate(PATH + '/entrega/' + consignment.id);
					},
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="edit"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-id'), 10) : 0;
				const row = (state.people || []).find(function (r) { return parseInt(r.id, 10) === id; });
				if (!row) return;
				MJ.components.consigneeModal({
					consignee: row,
					onSaved() { load(); },
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="delete"]', function (e, el) {
				const tr = el.closest('tr');
				const id = tr ? parseInt(tr.getAttribute('data-id'), 10) : 0;
				const row = (state.people || []).find(function (r) { return parseInt(r.id, 10) === id; });
				if (!row) return;

				const account = row.account || {};
				const hasMovements = (parseInt(account.sales_count, 10) || 0) > 0 || Math.abs(num(account.balance_base) || 0) >= 0.005;

				MJ.ui.confirm({
					title: 'Eliminar revendedor',
					message: hasMovements
						? 'Este revendedor tiene movimientos, así que no se borra: queda desactivado y su historial se conserva. La cuenta de WordPress no se toca.'
						: 'Se elimina el revendedor. La cuenta de WordPress no se toca.',
					okLabel: hasMovements ? 'Desactivar' : 'Eliminar',
					cancelLabel: 'Cancelar',
					danger: true,
				}).then(function (ok) {
					if (!ok || state.disposed) return;
					MJ.api.del('consignees/' + id).then(function (res) {
						if (state.disposed) return;
						MJ.ui.toast(res && res.deactivated ? 'Revendedor desactivado.' : 'Revendedor eliminado.', 'success');
						load();
					}).catch(function (err) {
						toastError(err, 'No se pudo eliminar el revendedor.');
					});
				});
			}));

			return function cleanup() {
				state.disposed = true;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
