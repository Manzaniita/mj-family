/**
 * MJ ERP — components-returns.js
 * Lo que vuelve de una venta cobrada: devoluciones, cambios y garantías
 * (MJ.components.saleReturnModal), y anular una venta cobrada eligiendo qué pasa con la plata
 * (MJ.components.saleVoidModal).
 *
 * Las cuentas las hace el servidor —cuánto se le reconoce (lo que pagó, sin el descuento), el precio
 * de lo que se lleva, si hay stock, cómo se reparte la plata entre monedas—. Esta pantalla arma la
 * devolución y, antes de guardar, muestra cómo queda la venta y qué pasa con el stock y la plata.
 *
 * REST: POST /sales/{id}/returns/preview, POST /sales/{id}/returns, POST /sales/{id}/void.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};
	const C = MJ.components;

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const PREVIEW_DELAY = 350;
	const SETTLE_CREDIT = 'saldo';
	const SETTLE_REFUND = 'reintegro';
	const DEST_STOCK = 'stock';
	const DEST_RMA = 'rma';

	function num(v) { return MJ.fmt.toNumber(v); }
	function blank(v) { return v === null || v === undefined || v === ''; }
	function helpers() { return C.saleHelpers || {}; }

	/** Un número listo para un campo, escrito como lo vuelve a leer MJ.fmt.toNumber ("29.000", "1,5"). */
	function inputNumber(v) {
		const n = num(v);
		if (n === null) return '';
		return MJ.fmt.number(n, Math.abs(n - Math.round(n)) < 0.000001 ? 0 : 2);
	}

	function inputQty(v) {
		const n = num(v);
		return n === null ? '' : MJ.fmt.qty(n);
	}

	/** Ids de venta de un resultado del buscador: un producto simple o una variación concreta. */
	function idsOf(product) {
		const variationId = num(product.variation_id) || (product.is_variation ? num(product.id) : 0);
		const productId = num(product.product_id) ||
			(product.is_variation ? num(product.parent_id) : num(product.id)) || num(product.id);
		return { productId: productId, variationId: variationId || 0 };
	}

	function creditName() { return C.creditLabel ? C.creditLabel() : 'Saldo a favor'; }

	/* ------------------------------------------------------------------ */
	/* Qué pasa con la plata                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * El bloque que decide la plata que sale de una venta: queda a favor del cliente (repartida por
	 * moneda, editable) o se le devolvió de una caja. Lo usan la devolución y la anulación.
	 *
	 * @param {Object} opts { id, sale, creditAvailable, initial }
	 */
	function settlementBlock(opts) {
		const sale = opts.sale || {};
		const creditOn = !!opts.creditAvailable && !!(C.creditEnabled && C.creditEnabled());
		const creditKey = C.creditMethod ? C.creditMethod() : '';
		const id = opts.id;

		const state = {
			settlement: creditOn && opts.initial !== SETTLE_REFUND ? SETTLE_CREDIT : SETTLE_REFUND,
			amount: 0,
			split: [],
			touched: false,
		};

		let root = null;
		let cashboxTouched = false;

		function html() {
			return '<div class="mj-return__settle" data-settle>' +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Qué pasa con la plata que sobra · <span data-settle-amount></span></span>' +
					'<div class="mj-row mj-row--wrap">' +
						(creditOn
							? '<label class="mj-checkbox"><input type="radio" name="settlement" value="' + SETTLE_CREDIT + '"' + (state.settlement === SETTLE_CREDIT ? ' checked' : '') + '> <span>Queda como ' + h(creditName().toLowerCase()) + '</span></label>'
							: '') +
						'<label class="mj-checkbox"><input type="radio" name="settlement" value="' + SETTLE_REFUND + '"' + (state.settlement === SETTLE_REFUND ? ' checked' : '') + '> <span>Le di la plata</span></label>' +
					'</div>' +
					(!creditOn
						? '<div class="mj-field__hint">' + (sale.customer_id
							? 'El saldo a favor está apagado en Configuración → Clientes.'
							: 'La venta no tiene cliente: para dejarle saldo a favor, cargale el cliente primero.') + '</div>'
						: '') +
				'</div>' +
				'<div data-settle-credit' + (state.settlement === SETTLE_CREDIT ? '' : ' class="mj-hidden"') + '>' +
					'<div class="mj-grid mj-grid--2" data-split></div>' +
					'<div class="mj-field__hint">Repartido según cómo pagó. Cada moneda queda como está: no se convierte. ' +
						'<button type="button" class="mj-link" data-action="split-reset">Repartir de nuevo</button></div>' +
				'</div>' +
				'<div class="mj-grid mj-grid--2' + (state.settlement === SETTLE_REFUND ? '' : ' mj-hidden') + '" data-settle-refund>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + id + '-method">Cómo se la diste</label>' +
						'<select class="mj-select" id="' + id + '-method" name="refund_method"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + id + '-cashbox">De qué caja salió</label>' +
						'<select class="mj-select" id="' + id + '-cashbox" name="refund_cashbox_id"></select>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="' + id + '-ref">Referencia</label>' +
						'<input class="mj-input" id="' + id + '-ref" name="refund_reference" type="text" maxlength="190" placeholder="Nº de transferencia, recibo…">' +
					'</div>' +
				'</div>' +
			'</div>';
		}

		function paintSplit() {
			const box = qs('[data-split]', root);
			if (!box) return;
			box.innerHTML = state.split.map(function (row) {
				return '<div class="mj-field">' +
					'<label class="mj-field__label" for="' + id + '-split-' + h(row.currency) + '">A favor en ' + h(row.currency) + '</label>' +
					'<input class="mj-input" id="' + id + '-split-' + h(row.currency) + '" type="text" inputmode="decimal" data-split-currency="' + h(row.currency) + '" value="' + h(inputNumber(row.amount)) + '">' +
				'</div>';
			}).join('');
		}

		function sync() {
			qs('[data-settle-credit]', root).classList.toggle('mj-hidden', state.settlement !== SETTLE_CREDIT);
			qs('[data-settle-refund]', root).classList.toggle('mj-hidden', state.settlement !== SETTLE_REFUND);
		}

		return {
			html: html,

			/** Engancha los eventos una vez que el HTML está en el modal. */
			mount(modalEl) {
				root = qs('[data-settle]', modalEl);
				const h2 = helpers();
				const methods = (h2.methodOptions ? h2.methodOptions() : []).filter(function (m) { return m.value !== creditKey; });
				const cashboxes = h2.cashboxOptions ? h2.cashboxOptions() : [];
				const methodSelect = qs('[name="refund_method"]', root);
				const cashboxSelect = qs('[name="refund_cashbox_id"]', root);
				const defaultMethod = ((h2.defaults ? h2.defaults() : {}) || {}).payment_method || (methods[0] && methods[0].value);

				MJ.ui.fillSelect(methodSelect, methods, defaultMethod);
				MJ.ui.fillSelect(cashboxSelect, cashboxes, h2.cashboxForMethod ? h2.cashboxForMethod(methodSelect.value) : '', 'Sin caja');

				MJ.ui.on(root, 'change', '[name="settlement"]', function (e, input) {
					state.settlement = input.value;
					sync();
				});
				MJ.ui.on(root, 'change', '[name="refund_cashbox_id"]', function () { cashboxTouched = true; });
				MJ.ui.on(root, 'change', '[name="refund_method"]', function () {
					if (cashboxTouched || !h2.cashboxForMethod) return;
					MJ.ui.fillSelect(cashboxSelect, cashboxes, h2.cashboxForMethod(methodSelect.value), 'Sin caja');
				});
				MJ.ui.on(root, 'input', '[data-split-currency]', function (e, input) {
					const row = state.split.find(function (r) { return r.currency === input.getAttribute('data-split-currency'); });
					if (row) row.amount = num(input.value) || 0;
					state.touched = true;
				});
				MJ.ui.on(root, 'click', '[data-action="split-reset"]', function () {
					state.touched = false;
					state.split = (state.proposed || []).map(function (r) { return { currency: r.currency, amount: num(r.amount) || 0 }; });
					paintSplit();
				});
			},

			/**
			 * Cuánto sobra y cómo propone repartirlo el servidor. Lo que se escribió a mano se respeta
			 * mientras las monedas sigan siendo las mismas.
			 */
			setAmount(amount, proposed, currency) {
				state.amount = num(amount) || 0;
				state.proposed = proposed || [];
				const label = qs('[data-settle-amount]', root);
				if (label) label.textContent = MJ.fmt.money(state.amount, currency || sale.currency);

				const same = state.split.length === state.proposed.length && state.proposed.every(function (r) {
					return state.split.some(function (s) { return s.currency === r.currency; });
				});
				if (state.touched && same) return;
				state.touched = false;
				state.split = state.proposed.map(function (r) { return { currency: r.currency, amount: num(r.amount) || 0 }; });
				paintSplit();
			},

			/** Lo que viaja al servidor. */
			value() {
				if (state.settlement === SETTLE_CREDIT) {
					return {
						settlement: SETTLE_CREDIT,
						credit_split: state.split.filter(function (r) { return (num(r.amount) || 0) > 0; }).map(function (r) {
							return { currency: r.currency, amount: num(r.amount) || 0 };
						}),
					};
				}
				return {
					settlement: SETTLE_REFUND,
					refund: {
						method: qs('[name="refund_method"]', root).value,
						cashbox_id: parseInt(qs('[name="refund_cashbox_id"]', root).value, 10) || 0,
						reference: qs('[name="refund_reference"]', root).value,
					},
				};
			},

			/** Errores por campo, o null. */
			validate() {
				if (state.settlement === SETTLE_CREDIT) {
					const total = state.split.reduce(function (sum, r) { return sum + (num(r.amount) || 0); }, 0);
					return total > 0 ? null : { settlement: 'Escribí cuánto le queda a favor.' };
				}
				return qs('[name="refund_method"]', root).value ? null : { refund_method: 'Elegí cómo le diste la plata.' };
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/* saleReturnModal                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Devolución, cambio o garantía de una venta cobrada.
	 * @param {Object} opts { sale, onSaved(sale) }
	 */
	C.saleReturnModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const rs = sale.return_state || {};
		const uid = 'mj-ret-' + Math.random().toString(36).slice(2, 8);
		const money = function (v) { return MJ.fmt.money(v, sale.currency); };

		const state = {
			rows: (sale.items || []).filter(function (item) { return (num(item.qty) || 0) > 0; }).map(function (item) {
				return { item: item, qty: 0, destination: DEST_STOCK, replace: false, problem: '' };
			}),
			newLines: [],
			reason: '',
			preview: null,
			error: null,
			seq: 0,
			closed: false,
		};

		const settle = settlementBlock({
			id: uid + '-settle',
			sale: sale,
			creditAvailable: rs.credit_available,
			initial: rs.default_settlement,
		});

		let search = null;

		function payload() {
			return {
				items: state.rows.filter(function (r) { return (num(r.qty) || 0) > 0; }).map(function (r) {
					return {
						sale_item_id: r.item.id,
						qty: num(r.qty) || 0,
						destination: r.destination,
						replace: !!r.replace,
						problem: r.problem,
					};
				}),
				new_items: state.newLines.filter(function (l) { return (num(l.qty) || 0) > 0; }).map(function (l) {
					const line = { product_id: l.product_id, variation_id: l.variation_id || 0, qty: num(l.qty) || 0 };
					if (!blank(l.unit_price)) line.unit_price = num(l.unit_price);
					return line;
				}),
				reason: state.reason,
			};
		}

		function hasSomething() {
			const p = payload();
			return p.items.length > 0 || p.new_items.length > 0;
		}

		/* ---------------- HTML ---------------- */

		function destinationOptions(row) {
			const opts2 = [{ value: DEST_STOCK, label: 'Vuelve al stock' }];
			if (rs.rma_enabled) opts2.push({ value: DEST_RMA, label: 'Vino fallado (RMA)' });
			return opts2.map(function (o) {
				return '<option value="' + h(o.value) + '"' + (row.destination === o.value ? ' selected' : '') + '>' + h(o.label) + '</option>';
			}).join('');
		}

		function returnRowHtml(row, index) {
			const item = row.item;
			const needsProblem = row.destination === DEST_RMA || row.replace;
			return '<tr data-index="' + index + '">' +
				'<td>' +
					'<div class="mj-strong">' + h(item.name) + '</div>' +
					'<div class="mj-small mj-muted">Se llevó ' + h(MJ.fmt.qty(item.qty)) + ' a ' + h(money(item.unit_price)) + (item.sku ? ' · SKU ' + h(item.sku) : '') + '</div>' +
					'<input class="mj-input mj-input--sm mj-mt' + (needsProblem ? '' : ' mj-hidden') + '" type="text" maxlength="255" data-row="problem" value="' + h(row.problem) + '" placeholder="Qué problema tiene" aria-label="Problema de ' + h(item.name) + '">' +
				'</td>' +
				'<td class="is-num">' +
					'<div class="mj-qty">' +
						'<button type="button" class="mj-qty__btn" data-action="qty-down" aria-label="Una unidad menos">' + MJ.icon('minus') + '</button>' +
						'<input class="mj-input mj-input--sm mj-qty__input" type="text" inputmode="decimal" data-row="qty" value="' + h(inputQty(row.qty)) + '" aria-label="Unidades que vuelven de ' + h(item.name) + '">' +
						'<button type="button" class="mj-qty__btn" data-action="qty-up" aria-label="Una unidad más">' + MJ.icon('plus') + '</button>' +
					'</div>' +
				'</td>' +
				'<td>' +
					'<select class="mj-select mj-select--sm" data-row="destination" aria-label="A dónde va">' + destinationOptions(row) + '</select>' +
				'</td>' +
				'<td>' +
					'<label class="mj-checkbox" title="Garantía: se le entrega otro igual sin cobrarle nada">' +
						'<input type="checkbox" data-row="replace"' + (row.replace ? ' checked' : '') + '> <span>Le doy otro igual</span>' +
					'</label>' +
				'</td>' +
			'</tr>';
		}

		function newLineHtml(line, index) {
			return '<tr data-new="' + index + '">' +
				'<td><div class="mj-strong">' + h(line.name) + '</div>' + (line.sku ? '<div class="mj-small mj-muted">SKU ' + h(line.sku) + '</div>' : '') + '</td>' +
				'<td class="is-num">' +
					'<input class="mj-input mj-input--sm mj-qty__input" type="text" inputmode="decimal" data-new-field="qty" value="' + h(inputQty(line.qty)) + '" aria-label="Cantidad de ' + h(line.name) + '">' +
				'</td>' +
				'<td class="is-num">' +
					'<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-new-field="unit_price" value="' + h(blank(line.unit_price) ? '' : inputNumber(line.unit_price)) + '" ' +
						'placeholder="' + h(blank(line.resolvedPrice) ? 'sugerido' : inputNumber(line.resolvedPrice)) + '" aria-label="Precio de ' + h(line.name) + '">' +
				'</td>' +
				'<td class="is-actions">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="remove-new" title="Sacar" aria-label="Sacar ' + h(line.name) + '">' + MJ.icon('trash') + '</button>' +
				'</td>' +
			'</tr>';
		}

		function newLinesHtml() {
			if (!state.newLines.length) {
				return '<tr><td colspan="4" class="mj-muted mj-small">Si se lleva algo a cambio, buscalo acá abajo.</td></tr>';
			}
			return state.newLines.map(newLineHtml).join('');
		}

		function totalsHtml() {
			const p = state.preview;
			if (!p) {
				// Si el cálculo falló, el aviso ya lo dice al lado: no quedarse «calculando» para siempre.
				if (state.error) return '';
				return hasSomething()
					? '<div class="mj-muted mj-small">' + MJ.ui.spinner('sm') + ' Calculando…</div>'
					: '<div class="mj-muted mj-small">Marcá qué vuelve o qué se lleva a cambio.</div>';
			}

			const rows = [];
			if ((num(p.returned_value) || 0) > 0) rows.push(['Se le reconoce', '− ' + money(p.returned_value)]);
			if ((num(p.new_value) || 0) > 0) rows.push(['Se lleva', money(p.new_value)]);
			rows.push(['Cobrado', money(p.paid)]);

			const excess = num(p.excess) || 0;
			const due = num(p.due_after) || 0;

			return '<dl class="mj-totals">' +
				'<div class="mj-totals__row mj-small mj-muted"><dt>Total antes</dt><dd>' + h(money(p.total_before)) + '</dd></div>' +
				rows.map(function (r) {
					return '<div class="mj-totals__row"><dt>' + h(r[0]) + '</dt><dd>' + h(r[1]) + '</dd></div>';
				}).join('') +
				'<div class="mj-totals__row mj-totals__row--total"><dt>Total nuevo</dt><dd>' + h(money(p.total_after)) + '</dd></div>' +
				(excess > 0.004 ? '<div class="mj-totals__row mj-success-text"><dt>Sobra</dt><dd>' + h(money(excess)) + '</dd></div>' : '') +
				(due > 0.004 ? '<div class="mj-totals__row mj-totals__row--danger"><dt>Falta cobrar</dt><dd>' + h(money(due)) + '</dd></div>' : '') +
			'</dl>';
		}

		function moveText(move) {
			const qty = num(move.qty) || 0;
			const units = MJ.fmt.qty(Math.abs(qty));
			switch (move.type) {
				case 'sale_return': return 'Entran al stock ' + units + ' de ' + move.name;
				case 'rma_replacement': return 'Salen ' + units + ' de ' + move.name + ' (reemplazo por garantía)';
				case 'sale_exchange': return 'Salen ' + units + ' de ' + move.name + ' (a cambio)';
				default: return (qty > 0 ? 'Entran ' : 'Salen ') + units + ' de ' + move.name;
			}
		}

		function effectsHtml() {
			if (state.error) return MJ.ui.alert(MJ.api.message(state.error, 'No se pudo calcular la devolución.'), 'danger');
			const p = state.preview;
			if (!p) return '';

			const changes = p.changes || [];
			const parts = [
				'<div><div class="mj-strong">' + h(p.kind_label || 'Qué pasa') + '</div>' +
					(changes.length ? '<ul class="mj-small">' + changes.map(function (c) { return '<li>' + h(c) + '</li>'; }).join('') + '</ul>' : '') +
				'</div>',
			];

			const moves = p.moves || [];
			if (p.stock_taken && moves.length) {
				parts.push('<div class="mj-mt"><div class="mj-strong">Stock</div><ul class="mj-small">' +
					moves.map(function (m) { return '<li>' + h(moveText(m)) + '</li>'; }).join('') + '</ul></div>');
			} else if (!p.stock_taken) {
				parts.push('<div class="mj-mt mj-small mj-muted">Esta venta todavía no bajó stock: no se mueve nada.</div>');
			}
			if ((num(p.rma_count) || 0) > 0) {
				parts.push('<div class="mj-mt mj-small">Lo fallado no vuelve al stock: queda en la lista de RMA para reclamarlo al proveedor.</div>');
			}
			if (p.has_order) {
				parts.push('<div class="mj-mt mj-small">El pedido #' + h(sale.order_number || sale.order_id) + ' de WooCommerce se actualiza para que diga lo mismo.</div>');
			}
			if (p.redeliver) {
				parts.push('<div class="mj-mt">' + MJ.ui.alert('La venta estaba entregada y se lleva algo: vuelve a «A entregar».', 'warning') + '</div>');
			}
			return parts.join('');
		}

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				'<div class="mj-strong mj-mb">Qué vuelve</div>' +
				'<div class="mj-table-wrap"><table class="mj-table mj-table--compact mj-return__table"><thead><tr>' +
					'<th>Producto</th><th class="is-num">Vuelven</th><th>A dónde va</th><th>Garantía</th>' +
				'</tr></thead><tbody data-region="rows">' + state.rows.map(returnRowHtml).join('') + '</tbody></table></div>' +
				'<div class="mj-field__hint">Se le reconoce lo que pagó por cada unidad, con el descuento de la venta repartido. Lo que se reemplaza por garantía no mueve plata.</div>' +
				'<div class="mj-strong mj-mt mj-mb">Qué se lleva a cambio</div>' +
				'<div class="mj-table-wrap"><table class="mj-table mj-table--compact mj-return__table"><thead><tr>' +
					'<th>Producto</th><th class="is-num">Cantidad</th><th class="is-num">Precio</th><th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
				'</tr></thead><tbody data-region="new">' + newLinesHtml() + '</tbody></table></div>' +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="' + uid + '-search">Agregar un producto</label>' +
					'<input class="mj-input" id="' + uid + '-search" type="search" placeholder="Buscá por nombre o SKU…">' +
					'<div class="mj-field__hint">Toma el precio de hoy con el dólar de la venta; lo podés cambiar.</div>' +
				'</div>' +
				'<div class="mj-sale-edit__summary">' +
					'<div data-region="totals">' + totalsHtml() + '</div>' +
					'<div data-region="effects"></div>' +
				'</div>' +
				'<div class="mj-mt mj-hidden" data-region="settle">' + settle.html() + '</div>' +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="' + uid + '-reason">Motivo' + (rs.requires_reason ? '' : ' <span class="mj-muted">(opcional)</span>') + '</label>' +
					'<textarea class="mj-textarea" id="' + uid + '-reason" name="reason" rows="2" maxlength="255" placeholder="Por ejemplo: vino con la pantalla rota"></textarea>' +
					'<div class="mj-field__hint">Queda en el historial de la venta' + (sale.order_id ? ' y en la nota del pedido' : '') + '.</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Devolución o cambio · ' + (sale.code || ''),
			size: 'lg',
			body: body,
			closeOnBackdrop: false,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save" disabled>' + MJ.icon('undo') + '<span>Registrar</span></button>',
			onClose() {
				state.closed = true;
				refresh.cancel();
				if (search && typeof search.destroy === 'function') search.destroy();
			},
		});

		settle.mount(modal.el);

		/* ---------------- pintado ---------------- */

		function region(name) { return qs('[data-region="' + name + '"]', modal.el); }

		function paintSave() {
			const btn = qs('[data-action="save"]', modal.el);
			if (!btn || btn.dataset.mjLoading === '1') return;
			btn.disabled = !!state.error || !state.preview || !hasSomething();
		}

		function paintSummary() {
			region('totals').innerHTML = totalsHtml();
			region('effects').innerHTML = effectsHtml();
			const p = state.preview;
			const excess = p ? (num(p.excess) || 0) : 0;
			region('settle').classList.toggle('mj-hidden', !(excess > 0.004) || !!state.error);
			if (p && excess > 0.004) settle.setAmount(excess, p.proposed_split, p.currency);
			paintSave();
		}

		function paintNew() {
			region('new').innerHTML = newLinesHtml();
		}

		/* ---------------- vista previa ---------------- */

		const refresh = MJ.ui.debounce(function () {
			if (state.closed) return;
			const seq = ++state.seq;
			if (!hasSomething()) {
				state.preview = null;
				state.error = null;
				paintSummary();
				return;
			}
			MJ.api.post('sales/' + sale.id + '/returns/preview', payload()).then(function (res) {
				if (state.closed || seq !== state.seq) return;
				state.preview = res;
				state.error = null;
				// Precio sugerido de lo que se lleva, en el mismo orden en que se mandó.
				const sent = state.newLines.filter(function (l) { return (num(l.qty) || 0) > 0; });
				(res.new_lines || []).forEach(function (line, i) {
					if (sent[i] && blank(sent[i].unit_price)) sent[i].resolvedPrice = line.unit_price;
				});
				qsa('tr[data-new]', region('new')).forEach(function (tr) {
					const line = state.newLines[parseInt(tr.getAttribute('data-new'), 10)];
					const price = qs('[data-new-field="unit_price"]', tr);
					if (line && price && blank(line.unit_price) && !blank(line.resolvedPrice)) price.placeholder = inputNumber(line.resolvedPrice);
				});
				paintSummary();
			}).catch(function (err) {
				if (state.closed || seq !== state.seq) return;
				if (err && err.status === 401) return;
				state.error = err;
				paintSummary();
			});
		}, PREVIEW_DELAY);

		function changed() {
			state.preview = null;
			paintSave();
			refresh();
		}

		function rowOf(el) {
			const tr = el.closest('tr[data-index]');
			if (!tr) return null;
			const row = state.rows[parseInt(tr.getAttribute('data-index'), 10)];
			return row ? { tr: tr, row: row } : null;
		}

		function clampQty(row, value) {
			return Math.min(num(row.item.qty) || 0, Math.max(0, value));
		}

		function syncProblem(found) {
			const input = qs('[data-row="problem"]', found.tr);
			if (input) input.classList.toggle('mj-hidden', !(found.row.destination === DEST_RMA || found.row.replace));
		}

		function addProduct(product) {
			if (!product) return;
			if (product.type === 'variable') {
				MJ.ui.toast('Elegí una de sus variaciones: un producto variable no se vende entero.', 'warning');
				return;
			}
			const ids = idsOf(product);
			if (!ids.productId) return;
			const existing = state.newLines.find(function (l) {
				return (num(l.product_id) || 0) === ids.productId && (num(l.variation_id) || 0) === ids.variationId;
			});
			if (existing) {
				existing.qty = (num(existing.qty) || 0) + 1;
			} else {
				state.newLines.push({
					product_id: ids.productId,
					variation_id: ids.variationId,
					name: product.name,
					sku: product.sku || '',
					qty: 1,
					unit_price: '',
				});
			}
			paintNew();
			changed();
		}

		/* ---------------- eventos ---------------- */

		MJ.ui.on(modal.el, 'input', '[data-row="qty"]', function (e, input) {
			const found = rowOf(input);
			if (!found) return;
			found.row.qty = clampQty(found.row, num(input.value) || 0);
			changed();
		});

		MJ.ui.on(modal.el, 'change', '[data-row="qty"]', function (e, input) {
			const found = rowOf(input);
			if (found) input.value = inputQty(found.row.qty);
		});

		MJ.ui.on(modal.el, 'click', '[data-action="qty-up"], [data-action="qty-down"]', function (e, btn) {
			const found = rowOf(btn);
			if (!found) return;
			const step = btn.getAttribute('data-action') === 'qty-up' ? 1 : -1;
			found.row.qty = clampQty(found.row, (num(found.row.qty) || 0) + step);
			const input = qs('[data-row="qty"]', found.tr);
			if (input) input.value = inputQty(found.row.qty);
			changed();
		});

		MJ.ui.on(modal.el, 'change', '[data-row="destination"]', function (e, select) {
			const found = rowOf(select);
			if (!found) return;
			found.row.destination = select.value;
			syncProblem(found);
			if ((num(found.row.qty) || 0) > 0) changed();
		});

		MJ.ui.on(modal.el, 'change', '[data-row="replace"]', function (e, input) {
			const found = rowOf(input);
			if (!found) return;
			found.row.replace = !!input.checked;
			syncProblem(found);
			if ((num(found.row.qty) || 0) > 0) changed();
		});

		MJ.ui.on(modal.el, 'input', '[data-row="problem"]', function (e, input) {
			const found = rowOf(input);
			if (found) found.row.problem = input.value;
		});

		MJ.ui.on(modal.el, 'input', '[data-new-field]', function (e, input) {
			const tr = input.closest('tr[data-new]');
			const line = tr ? state.newLines[parseInt(tr.getAttribute('data-new'), 10)] : null;
			if (!line) return;
			const raw = String(input.value || '').trim();
			if (input.getAttribute('data-new-field') === 'qty') line.qty = num(raw) || 0;
			else line.unit_price = raw === '' ? '' : num(raw);
			changed();
		});

		MJ.ui.on(modal.el, 'click', '[data-action="remove-new"]', function (e, btn) {
			const tr = btn.closest('tr[data-new]');
			if (!tr) return;
			state.newLines.splice(parseInt(tr.getAttribute('data-new'), 10), 1);
			paintNew();
			changed();
		});

		MJ.ui.on(modal.el, 'input', '#' + uid + '-reason', function (e, input) {
			state.reason = input.value;
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			MJ.ui.clearErrors(form);
			if (rs.requires_reason && !String(state.reason || '').trim()) {
				MJ.ui.showErrors(form, { reason: 'Contá el motivo de la devolución: queda en el historial.' });
				return;
			}

			const data = payload();
			const excess = state.preview ? (num(state.preview.excess) || 0) : 0;
			if (excess > 0.004) {
				const errors = settle.validate();
				if (errors) { MJ.ui.showErrors(form, errors); return; }
				Object.assign(data, settle.value());
			}

			refresh.cancel();
			MJ.ui.setLoading(btn, true);
			MJ.api.post('sales/' + sale.id + '/returns', data).then(function (updated) {
				MJ.ui.toast('Devolución registrada.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(updated);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo registrar la devolución.');
			});
		});

		try {
			search = C.productSearch({
				input: qs('#' + uid + '-search', modal.el),
				clearOnSelect: true,
				onSelect(product) { addProduct(product); },
			});
		} catch (e) {
			console.error('[MJ] productSearch', e);
		}

		paintSummary();

		return modal;
	};

	/* ------------------------------------------------------------------ */
	/* saleVoidModal                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Anular una venta que ya se cobró: la plata queda a favor del cliente o se le devuelve.
	 * Lo que pagó con saldo a favor vuelve solo a ese saldo.
	 * @param {Object} opts { sale, onSaved(sale) }
	 */
	C.saleVoidModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const rs = sale.return_state || {};
		const uid = 'mj-void-' + Math.random().toString(36).slice(2, 8);
		const money = function (v) { return MJ.fmt.money(v, sale.currency); };
		const refundable = num(rs.refundable_on_void) || 0;
		const withCredit = Math.max(0, (num(sale.paid_total) || 0) - refundable);
		const hasConsignment = (num(sale.consignment_qty) || 0) > 0;

		const settle = settlementBlock({
			id: uid + '-settle',
			sale: sale,
			creditAvailable: rs.credit_available,
			initial: rs.default_settlement,
		});

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				MJ.ui.alert('Se anula la venta' +
					(hasConsignment ? ', se le saca la deuda al revendedor y le vuelve el stock a su consignación' : '') +
					(sale.order_id ? ' y se cancela el pedido en WooCommerce.' : '.'), 'warning') +
				'<p class="mj-mt">Ya se cobraron ' + h(money(refundable)) + '.' +
					(withCredit > 0.004 ? ' Lo que pagó con ' + h(creditName().toLowerCase()) + ' (' + h(money(withCredit)) + ') le vuelve solo a su saldo.' : '') +
				'</p>' +
				settle.html() +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="' + uid + '-reason">Motivo <span class="mj-muted">(opcional)</span></label>' +
					'<textarea class="mj-textarea" id="' + uid + '-reason" name="reason" rows="2" maxlength="255"></textarea>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Anular venta · ' + (sale.code || ''),
			size: 'md',
			body: body,
			closeOnBackdrop: false,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--danger" data-action="save">' + MJ.icon('x-circle') + '<span>Anular</span></button>',
		});

		settle.mount(modal.el);
		settle.setAmount(refundable, rs.void_split || [], sale.currency);

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			MJ.ui.clearErrors(form);
			const errors = settle.validate();
			if (errors) { MJ.ui.showErrors(form, errors); return; }

			const data = Object.assign({ reason: qs('#' + uid + '-reason', modal.el).value }, settle.value());
			MJ.ui.setLoading(btn, true);
			MJ.api.post('sales/' + sale.id + '/void', data).then(function (updated) {
				MJ.ui.toast('Venta anulada.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(updated);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo anular la venta.');
			});
		});

		return modal;
	};
})();
