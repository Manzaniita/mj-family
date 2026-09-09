/**
 * MJ ERP — page-consignment.js
 * MJ.pages.consignment: una entrega. En borrador se cargan los productos y el precio acordado;
 * al marcarla como entregada las líneas se congelan y el revendedor ya puede registrar ventas
 * contra ellas.
 *
 * REST usado: GET|PUT|DELETE /consignments/{id}, POST /consignments/{id}/status,
 *             POST|PUT|DELETE /consignments/{id}/items[/{item_id}],
 *             GET /consignments/price-suggestion.
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

	function num(v) { return MJ.fmt.toNumber(v); }
	function f(v) { return num(v) === null ? 0 : num(v); }

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCur() { return String(setting('base_currency', 'USD')).toUpperCase(); }

	function moneyBase(value) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, baseCur(), decimals);
	}

	function dash() { return '<span class="mj-muted">—</span>'; }

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function statusBadge(status) {
		const map = (MJ.state.bootstrap && MJ.state.bootstrap.consignment && MJ.state.bootstrap.consignment.statuses) || {};
		const info = map[status] || { label: status || '—', color: 'neutral' };
		return MJ.ui.badgeRaw(info.label, info.color);
	}

	function inputNum(value, decimals) {
		const n = num(value);
		if (n === null) return '';
		return MJ.fmt.number(n, decimals === undefined ? 2 : decimals).replace(/([.,]\d*?)0+$/, '$1').replace(/[.,]$/, '');
	}

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml(p) {
		const consignee = p.consignee || {};
		const can = p.can || {};

		let actions = '';
		if (can.deliver) {
			actions += '<button type="button" class="mj-btn mj-btn--primary" data-action="deliver">' + MJ.icon('truck') + '<span>Marcar entregada</span></button>';
		}
		if (can.close) {
			actions += '<button type="button" class="mj-btn mj-btn--ghost" data-action="close">' + MJ.icon('archive') + '<span>Cerrar entrega</span></button>';
		}
		if (can.reopen) {
			actions += '<button type="button" class="mj-btn mj-btn--ghost" data-action="reopen">' + MJ.icon('undo') + '<span>Reabrir</span></button>';
		}
		if (can.cancel) {
			actions += '<button type="button" class="mj-btn mj-btn--ghost mj-danger-text" data-action="cancel">' + MJ.icon('x-circle') + '<span>Cancelar</span></button>';
		}

		return '<div class="mj-page-header">' +
			'<div class="mj-row mj-row--center">' +
				'<a class="mj-btn mj-btn--ghost mj-btn--icon" href="' + h(MJ.router.link(PATH + '?tab=entregas')) + '" title="Volver" aria-label="Volver a entregas">' + MJ.icon('arrow-left') + '</a>' +
				'<div>' +
					'<h2 class="mj-page-header__title"><span class="mj-mono">' + h(p.code || ('#' + p.id)) + '</span> ' + statusBadge(p.status) + '</h2>' +
					'<div class="mj-page-header__subtitle mj-muted">' +
						(consignee.id
							? 'Para <a class="mj-link" href="' + h(MJ.router.link(PATH + '/revendedor/' + consignee.id)) + '">' + h(consignee.name || '') + '</a>'
							: 'Sin revendedor') +
						(p.delivered_at ? ' · ' + h(MJ.fmt.date(p.delivered_at)) : '') +
					'</div>' +
				'</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' + actions + '</div>' +
		'</div>';
	}

	function summaryHtml(p) {
		const items = Array.isArray(p.items) ? p.items : [];
		let sold = 0;
		let returned = 0;
		let onHand = 0;
		items.forEach(function (it) {
			sold += f(it.qty_sold);
			returned += f(it.qty_returned);
			onHand += f(it.qty_on_hand);
		});

		const cards = [
			{ icon: 'box', label: 'Productos', value: MJ.fmt.int(p.items_count || 0), sub: MJ.fmt.qty(p.qty_total) + ' unidades entregadas' },
			{ icon: 'dollar', label: 'Valor acordado', value: moneyBase(p.value_total_base), sub: 'Precio congelado en la entrega' },
			{ icon: 'cart', label: 'Vendido', value: MJ.fmt.qty(sold) + ' u.', sub: returned > 0 ? MJ.fmt.qty(returned) + ' devueltas' : 'Sin devoluciones' },
			{ icon: 'package', label: 'Le queda', value: MJ.fmt.qty(onHand) + ' u.', sub: p.status === 'entregada' ? 'Puede seguir vendiendo' : '' },
		];

		return '<div class="mj-grid mj-grid--4 mj-mb">' + cards.map(function (c) {
			return '<div class="mj-card mj-kpi">' +
				'<div class="mj-kpi__label">' + MJ.icon(c.icon) + '<span>' + h(c.label) + '</span></div>' +
				'<div class="mj-kpi__value">' + h(c.value) + '</div>' +
				'<div class="mj-kpi__sub mj-muted">' + h(c.sub || '') + '</div>' +
			'</div>';
		}).join('') + '</div>';
	}

	function noticeHtml(p) {
		if (p.status === 'borrador') {
			return MJ.ui.alert('La entrega está en borrador: podés agregar, cambiar y quitar productos. Al marcarla como entregada, los precios quedan congelados y el revendedor puede empezar a registrar ventas.', 'info');
		}
		if (p.status === 'entregada') {
			return MJ.ui.alert('Entregada. El stock de WooCommerce todavía no bajó: baja cuando se confirma cada venta del revendedor. Mientras tanto, estas unidades están comprometidas.', 'info');
		}
		if (p.status === 'cancelada') {
			return MJ.ui.alert('Esta entrega está cancelada.', 'warning');
		}
		return '';
	}

	function itemsHtml(p) {
		const items = Array.isArray(p.items) ? p.items : [];
		const editable = !!(p.can && p.can.edit);

		if (!items.length) {
			return MJ.ui.empty({
				icon: 'package',
				title: 'Todavía no cargaste productos',
				text: editable
					? 'Buscá el producto por nombre o SKU, poné la cantidad y el precio que le acordaste por unidad.'
					: 'Esta entrega no tiene productos.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Producto</th>' +
			'<th class="is-num">Cantidad</th>' +
			'<th class="is-num">Precio acordado</th>' +
			'<th class="is-num">Valor</th>' +
			(editable ? '' : '<th class="is-num">Vendido</th><th class="is-num">Le queda</th>') +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
		'</tr></thead><tbody>' +
		items.map(function (item) {
			const list = item.price_list_key ? item.price_list_key : '';
			return '<tr data-item="' + h(item.id) + '">' +
				'<td>' +
					'<div class="mj-strong">' + h(item.name || '') + '</div>' +
					'<div class="mj-small mj-muted">' +
						(item.sku ? 'SKU ' + h(item.sku) : '') +
						(list ? (item.sku ? ' · ' : '') + 'lista ' + h(list) : '') +
					'</div>' +
				'</td>' +
				'<td class="is-num">' +
					(editable
						? '<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-edit="qty" value="' + h(inputNum(item.qty, 4)) + '" aria-label="' + h('Cantidad de ' + (item.name || '')) + '">'
						: h(MJ.fmt.qty(item.qty))) +
				'</td>' +
				'<td class="is-num">' +
					(editable
						? '<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-edit="unit_price_base" value="' + h(inputNum(item.unit_price_base, 2)) + '" aria-label="' + h('Precio acordado de ' + (item.name || '')) + '">'
						: h(moneyBase(item.unit_price_base))) +
				'</td>' +
				'<td class="is-num"><span class="mj-money">' + h(moneyBase(item.value_base)) + '</span></td>' +
				(editable ? '' :
					'<td class="is-num">' + h(MJ.fmt.qty(item.qty_sold)) + '</td>' +
					'<td class="is-num"><span class="mj-strong">' + h(MJ.fmt.qty(item.qty_on_hand)) + '</span></td>') +
				'<td class="is-actions">' +
					(editable
						? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="remove-item" title="Quitar" aria-label="Quitar producto">' + MJ.icon('trash') + '</button>'
						: '') +
				'</td>' +
			'</tr>';
		}).join('') +
		'</tbody></table></div>';
	}

	function addItemHtml(p) {
		if (!(p.can && p.can.edit)) return '';
		const fid = 'mj-add-' + Math.random().toString(36).slice(2, 8);
		return '<div class="mj-card mj-mt">' +
			'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('plus') + '<span>Agregar producto</span></h3></div>' +
			'<div class="mj-card__body">' +
				'<form class="mj-form" data-add-form novalidate>' +
					'<div class="mj-row mj-row--wrap">' +
						'<div class="mj-field" style="flex:2 1 240px">' +
							'<label class="mj-field__label" for="' + fid + '-s">Producto</label>' +
							'<input class="mj-input" id="' + fid + '-s" type="text" placeholder="Buscar por nombre o SKU…" autocomplete="off" data-add-search>' +
							'<div class="mj-field__hint" data-add-hint>Elegí un producto simple o una variación concreta.</div>' +
						'</div>' +
						'<div class="mj-field" style="flex:0 1 120px">' +
							'<label class="mj-field__label" for="' + fid + '-q">Cantidad</label>' +
							'<input class="mj-input" id="' + fid + '-q" type="text" inputmode="decimal" value="1" data-add-qty>' +
						'</div>' +
						'<div class="mj-field" style="flex:0 1 200px">' +
							'<label class="mj-field__label" for="' + fid + '-p">Precio acordado (' + h(baseCur()) + ')</label>' +
							'<input class="mj-input" id="' + fid + '-p" type="text" inputmode="decimal" placeholder="Se sugiere solo" data-add-price>' +
						'</div>' +
						'<div class="mj-field" style="flex:0 0 auto">' +
							'<label class="mj-field__label">&nbsp;</label>' +
							'<button type="submit" class="mj-btn mj-btn--primary" data-add-submit disabled>' + MJ.icon('plus') + '<span>Agregar</span></button>' +
						'</div>' +
					'</div>' +
				'</form>' +
			'</div>' +
		'</div>';
	}

	function notesHtml(p) {
		return '<div class="mj-card mj-mt">' +
			'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('file') + '<span>Notas</span></h3></div>' +
			'<div class="mj-card__body">' +
				'<textarea class="mj-input mj-textarea" rows="3" maxlength="2000" data-notes placeholder="Lo que quieras recordar de esta entrega…">' + h(p.notes || '') + '</textarea>' +
				'<div class="mj-row mj-row--end mj-mt">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="save-notes">' + MJ.icon('save') + '<span>Guardar notas</span></button>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	function salesHtml(p) {
		const rows = (p.sales && p.sales.items) || [];
		if (!rows.length) return '';
		return '<div class="mj-card mj-mt">' +
			'<div class="mj-card__header"><h3 class="mj-card__title">' + MJ.icon('cart') + '<span>Ventas contra esta entrega</span></h3></div>' +
			'<div class="mj-card__body">' +
				'<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
					'<th>Fecha</th><th>Producto</th><th class="is-num">Cant.</th><th class="is-num">Total</th><th>Estado</th>' +
				'</tr></thead><tbody>' +
				rows.map(function (sale) {
					const map = { pendiente: ['A confirmar', 'warning'], aprobada: ['Confirmada', 'success'], rechazada: ['Rechazada', 'neutral'] };
					const info = map[sale.status] || [sale.status, 'neutral'];
					return '<tr>' +
						'<td>' + h(MJ.fmt.date(sale.sale_date)) + '</td>' +
						'<td>' + h(sale.name || '') + '</td>' +
						'<td class="is-num">' + h(MJ.fmt.qty(sale.qty)) + '</td>' +
						'<td class="is-num">' + h(moneyBase(sale.amount_base)) + '</td>' +
						'<td>' + MJ.ui.badgeRaw(info[0], info[1]) + '</td>' +
					'</tr>';
				}).join('') +
				'</tbody></table></div>' +
			'</div>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.consignment = {
		title() { return 'Entrega'; },

		render(container, params) {
			const id = parseInt(params && params.id, 10) || 0;
			const state = {
				id: id,
				data: null,
				error: null,
				disposed: false,
				seq: 0,
				chosen: null,
				search: null,
			};
			const offs = [];

			container.innerHTML = loadingHtml('Cargando la entrega…');

			function paint() {
				if (state.error) {
					container.innerHTML = MJ.ui.alert(state.error, 'danger') +
						'<a class="mj-btn mj-btn--ghost mj-mt" href="' + h(MJ.router.link(PATH + '?tab=entregas')) + '">' + MJ.icon('arrow-left') + '<span>Volver</span></a>';
					return;
				}
				if (!state.data) {
					container.innerHTML = loadingHtml('Cargando la entrega…');
					return;
				}

				const p = state.data;
				container.innerHTML =
					headerHtml(p) +
					summaryHtml(p) +
					noticeHtml(p) +
					itemsHtml(p) +
					addItemHtml(p) +
					salesHtml(p) +
					notesHtml(p);

				mountSearch();
			}

			function mountSearch() {
				const input = qs('[data-add-search]', container);
				if (!input) return;

				if (state.search && typeof state.search.destroy === 'function') {
					try { state.search.destroy(); } catch (e) { /* noop */ }
				}
				state.chosen = null;
				syncAddButton();

				try {
					state.search = MJ.components.productSearch({
						input: input,
						onSelect(product) {
							state.chosen = product;
							syncAddButton();
							suggestPrice(product);
						},
					});
				} catch (e) {
					console.error('[MJ] productSearch', e);
				}
			}

			function syncAddButton() {
				const btn = qs('[data-add-submit]', container);
				if (btn) btn.disabled = !state.chosen;
			}

			function suggestPrice(product) {
				const priceInput = qs('[data-add-price]', container);
				const hint = qs('[data-add-hint]', container);
				if (!product) return;

				const targetId = product.variation_id && parseInt(product.variation_id, 10) > 0
					? parseInt(product.variation_id, 10)
					: parseInt(product.id, 10);

				MJ.api.get('consignments/price-suggestion', {
					product_id: targetId,
					consignee_id: state.data ? state.data.consignee_id : 0,
				}).then(function (res) {
					if (state.disposed || !res) return;
					if (priceInput && String(priceInput.value).trim() === '') {
						priceInput.value = inputNum(res.unit_price_base, 2);
					}
					if (hint) {
						hint.textContent = res.has_cost
							? 'Sugerido a partir del costo final ' + moneyBase(res.cost_base) + ' con la lista del revendedor.'
							: 'Este producto todavía no tiene costo cargado: revisá el precio a mano.';
					}
				}).catch(function () { /* la sugerencia es un lujo, no un requisito */ });
			}

			function load() {
				const seq = ++state.seq;
				return MJ.api.get('consignments/' + state.id).then(function (res) {
					if (state.disposed || seq !== state.seq) return;
					state.data = res;
					state.error = null;
					paint();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					if (err && (err.handled || err.status === 401)) return;
					state.error = MJ.api.message(err, 'No se pudo cargar la entrega.');
					paint();
				});
			}

			load();

			/* ---------------- alta de líneas ---------------- */

			offs.push(MJ.ui.on(container, 'submit', '[data-add-form]', function (e) {
				e.preventDefault();
				if (!state.chosen) return;

				const qtyInput = qs('[data-add-qty]', container);
				const priceInput = qs('[data-add-price]', container);
				const btn = qs('[data-add-submit]', container);

				const qty = MJ.fmt.parse(qtyInput ? qtyInput.value : '1');
				if (qty === null || isNaN(qty) || qty <= 0) {
					MJ.ui.toast('La cantidad tiene que ser mayor a cero.', 'error');
					return;
				}

				const product = state.chosen;
				const payload = {
					product_id: parseInt(product.product_id || product.id, 10) || 0,
					variation_id: parseInt(product.variation_id, 10) || 0,
					qty: qty,
				};
				const rawPrice = priceInput ? String(priceInput.value).trim() : '';
				if (rawPrice !== '') payload.unit_price_base = MJ.fmt.parse(rawPrice);

				MJ.ui.setLoading(btn, true);
				MJ.api.post('consignments/' + state.id + '/items', payload).then(function (res) {
					if (state.disposed) return;
					state.data = res;
					MJ.ui.toast('Producto agregado.', 'success');
					paint();
				}).catch(function (err) {
					MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo agregar el producto.');
				});
			}));

			/* ---------------- edición en línea ---------------- */

			offs.push(MJ.ui.on(container, 'change', '[data-edit]', function (e, el) {
				const tr = el.closest('tr');
				const itemId = tr ? parseInt(tr.getAttribute('data-item'), 10) : 0;
				const field = el.getAttribute('data-edit');
				if (!itemId || !field) return;

				const value = MJ.fmt.parse(el.value);
				if (value === null || isNaN(value) || value < 0) {
					MJ.ui.toast('Valor inválido.', 'error');
					load();
					return;
				}

				const payload = {};
				payload[field] = value;

				el.disabled = true;
				MJ.api.put('consignments/' + state.id + '/items/' + itemId, payload).then(function (res) {
					if (state.disposed) return;
					state.data = res;
					paint();
				}).catch(function (err) {
					el.disabled = false;
					toastError(err, 'No se pudo actualizar el producto.');
					load();
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="remove-item"]', function (e, el) {
				const tr = el.closest('tr');
				const itemId = tr ? parseInt(tr.getAttribute('data-item'), 10) : 0;
				if (!itemId) return;
				MJ.api.del('consignments/' + state.id + '/items/' + itemId).then(function (res) {
					if (state.disposed) return;
					state.data = res;
					MJ.ui.toast('Producto quitado.', 'success');
					paint();
				}).catch(function (err) {
					toastError(err, 'No se pudo quitar el producto.');
				});
			}));

			/* ---------------- estados ---------------- */

			function setStatus(status, confirmOpts) {
				const run = function () {
					MJ.api.post('consignments/' + state.id + '/status', { status: status }).then(function (res) {
						if (state.disposed) return;
						state.data = res;
						MJ.ui.toast('Entrega actualizada.', 'success');
						paint();
					}).catch(function (err) {
						toastError(err, 'No se pudo cambiar el estado de la entrega.');
					});
				};

				if (!confirmOpts) { run(); return; }
				MJ.ui.confirm(confirmOpts).then(function (ok) { if (ok && !state.disposed) run(); });
			}

			offs.push(MJ.ui.on(container, 'click', '[data-action="deliver"]', function () {
				setStatus('entregada', {
					title: 'Marcar como entregada',
					message: 'Los precios acordados quedan congelados y el revendedor va a poder registrar ventas contra esta entrega. El stock de WooCommerce no cambia todavía: baja con cada venta confirmada.',
					okLabel: 'Marcar entregada',
					cancelLabel: 'Cancelar',
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="close"]', function () {
				setStatus('cerrada', {
					title: 'Cerrar entrega',
					message: 'Una entrega cerrada deja de aparecer como stock disponible del revendedor. Usalo cuando ya no quede nada por vender ni devolver.',
					okLabel: 'Cerrar',
					cancelLabel: 'Cancelar',
				});
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="reopen"]', function () {
				setStatus('entregada');
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="cancel"]', function () {
				setStatus('cancelada', {
					title: 'Cancelar entrega',
					message: 'La entrega queda cancelada. Solo se puede si no tiene ventas registradas.',
					okLabel: 'Cancelar entrega',
					cancelLabel: 'Volver',
					danger: true,
				});
			}));

			/* ---------------- notas ---------------- */

			offs.push(MJ.ui.on(container, 'click', '[data-action="save-notes"]', function (e, el) {
				const ta = qs('[data-notes]', container);
				if (!ta) return;
				MJ.ui.setLoading(el, true);
				MJ.api.put('consignments/' + state.id, { notes: String(ta.value || '') }).then(function (res) {
					if (state.disposed) return;
					state.data = res;
					MJ.ui.setLoading(el, false);
					MJ.ui.toast('Notas guardadas.', 'success');
				}).catch(function (err) {
					MJ.ui.setLoading(el, false);
					toastError(err, 'No se pudieron guardar las notas.');
				});
			}));

			return function cleanup() {
				state.disposed = true;
				if (state.search && typeof state.search.destroy === 'function') {
					try { state.search.destroy(); } catch (e) { /* noop */ }
				}
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
