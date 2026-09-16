/**
 * MJ ERP — components-sale-edit.js
 * MJ.components.saleItemsModal: corregir los productos de una venta que todavía no se cobró.
 *
 * Lo que importa lo decide el servidor —el precio sugerido con el dólar de la venta, si hay stock,
 * qué se puede cambiar—. Esta pantalla arma el cambio y, antes de guardar, muestra cómo queda el total
 * y qué va a pasar con el stock, el pedido y la entrega.
 *
 * REST: POST /sales/{id}/items/preview, PUT /sales/{id}/items, GET /products/search.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const PREVIEW_DELAY = 350;

	function num(v) { return MJ.fmt.toNumber(v); }
	function blank(v) { return v === null || v === undefined || v === ''; }
	function salesInfo() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function allowDiscount() { return (salesInfo().defaults || {}).allow_discount !== false; }

	/**
	 * Un número listo para un campo, escrito como lo vuelve a leer MJ.fmt.toNumber ("29.000", "1,5").
	 * El número crudo no sirve: "1.575" se lee como mil quinientos setenta y cinco.
	 */
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

	/**
	 * @param {Object} opts { sale, onSaved(sale) }
	 */
	MJ.components.saleItemsModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const edit = sale.edit || {};
		const discountOn = allowDiscount();
		const money = function (v) { return MJ.fmt.money(v, sale.currency); };

		const state = {
			lines: (sale.items || []).map(function (item) {
				return {
					id: item.id,
					product_id: item.product_id,
					variation_id: item.variation_id || 0,
					name: item.name,
					sku: item.sku || '',
					qty: num(item.qty) || 0,
					unit_price: num(item.unit_price) || 0,
					discount: num(item.discount) || 0,
					isNew: false,
				};
			}),
			discount: num(sale.discount_total) || 0,
			shipping: num(sale.shipping_total) || 0,
			reason: '',
			preview: null,
			error: null,
			seq: 0,
			closed: false,
		};

		let search = null;

		function priceOf(line) {
			return blank(line.unit_price) ? (num(line.resolvedPrice) || 0) : (num(line.unit_price) || 0);
		}

		function lineTotal(line) {
			return Math.max(0, (num(line.qty) || 0) * priceOf(line) - (num(line.discount) || 0));
		}

		function payload() {
			return {
				items: state.lines.map(function (line) {
					const item = {
						id: line.id || 0,
						product_id: line.product_id,
						variation_id: line.variation_id || 0,
						qty: num(line.qty) || 0,
						discount: num(line.discount) || 0,
						name: line.name,
					};
					// Sin precio escrito: el que ya tenía la línea, o el sugerido si es nueva.
					if (!blank(line.unit_price)) item.unit_price = num(line.unit_price);
					return item;
				}),
				discount_total: num(state.discount) || 0,
				shipping_total: num(state.shipping) || 0,
				reason: state.reason,
			};
		}

		/* ---------------- HTML ---------------- */

		function lineRowHtml(line, index) {
			return '<tr data-index="' + index + '">' +
				'<td>' +
					'<div class="mj-strong">' + h(line.name) + (line.isNew ? ' ' + MJ.ui.badgeRaw('Nuevo', 'info') : '') + '</div>' +
					(line.sku ? '<div class="mj-small mj-muted">SKU ' + h(line.sku) + '</div>' : '') +
				'</td>' +
				'<td class="is-num">' +
					'<div class="mj-qty">' +
						'<button type="button" class="mj-qty__btn" data-action="qty-down" aria-label="Una unidad menos">' + MJ.icon('minus') + '</button>' +
						'<input class="mj-input mj-input--sm mj-qty__input" type="text" inputmode="decimal" data-line="qty" value="' + h(inputQty(line.qty)) + '" aria-label="Cantidad de ' + h(line.name) + '">' +
						'<button type="button" class="mj-qty__btn" data-action="qty-up" aria-label="Una unidad más">' + MJ.icon('plus') + '</button>' +
					'</div>' +
				'</td>' +
				'<td class="is-num">' +
					'<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-line="unit_price" value="' + h(blank(line.unit_price) ? '' : inputNumber(line.unit_price)) + '" ' +
						'placeholder="' + h(blank(line.resolvedPrice) ? 'sugerido' : inputNumber(line.resolvedPrice)) + '" aria-label="Precio de ' + h(line.name) + '">' +
				'</td>' +
				(discountOn
					? '<td class="is-num"><input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-line="discount" value="' + h((num(line.discount) || 0) > 0 ? inputNumber(line.discount) : '') + '" placeholder="0" aria-label="Descuento de ' + h(line.name) + '"></td>'
					: '') +
				'<td class="is-num mj-strong" data-cell="total">' + h(money(lineTotal(line))) + '</td>' +
				'<td class="is-actions">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="remove-line" title="Sacar" aria-label="Sacar ' + h(line.name) + '">' + MJ.icon('trash') + '</button>' +
				'</td>' +
			'</tr>';
		}

		function linesHtml() {
			if (!state.lines.length) {
				return '<tr><td colspan="' + (discountOn ? 6 : 5) + '" class="mj-muted">La venta se quedó sin productos. Agregá al menos uno, o cerrá esto y anulala.</td></tr>';
			}
			return state.lines.map(lineRowHtml).join('');
		}

		function totalsHtml() {
			const p = state.preview;
			if (!p) return '<div class="mj-muted mj-small">' + MJ.ui.spinner('sm') + ' Calculando…</div>';

			const t = p.totals || {};
			const rows = [['Subtotal', money(t.subtotal)]];
			if ((num(t.discount_total) || 0) > 0) rows.push(['Descuento', '− ' + money(t.discount_total)]);
			if ((num(t.shipping_total) || 0) > 0) rows.push(['Envío', money(t.shipping_total)]);
			const moved = Math.abs((num(t.total) || 0) - (num(t.total_before) || 0)) > 0.004;

			return '<dl class="mj-totals">' +
				rows.map(function (r) {
					return '<div class="mj-totals__row"><dt>' + h(r[0]) + '</dt><dd>' + h(r[1]) + '</dd></div>';
				}).join('') +
				'<div class="mj-totals__row mj-totals__row--total"><dt>Total</dt><dd>' + h(money(t.total)) + '</dd></div>' +
				(moved ? '<div class="mj-totals__row mj-small mj-muted"><dt>Antes</dt><dd>' + h(money(t.total_before)) + '</dd></div>' : '') +
			'</dl>';
		}

		/** Qué cambia y qué va a pasar con el stock, el pedido y la entrega. */
		function effectsHtml() {
			if (state.error) return MJ.ui.alert(MJ.api.message(state.error, 'No se pudo calcular la corrección.'), 'danger');
			const p = state.preview;
			if (!p) return '';

			const changes = p.changes || [];
			const parts = [
				'<div><div class="mj-strong">Qué cambia</div>' +
					(changes.length
						? '<ul class="mj-small">' + changes.map(function (c) { return '<li>' + h(c) + '</li>'; }).join('') + '</ul>'
						: '<div class="mj-small mj-muted">Todavía no cambiaste nada.</div>') +
				'</div>',
			];

			const stock = p.stock || {};
			const moves = (stock.rows || []).filter(function (r) { return Math.abs(num(r.diff) || 0) > 0.0001; });
			if (moves.length) {
				parts.push('<div class="mj-mt"><div class="mj-strong">Stock</div>' +
					(stock.taken
						? '<ul class="mj-small">' + moves.map(function (r) {
							const d = num(r.diff) || 0;
							return '<li>' + (d > 0
								? 'Salen ' + h(MJ.fmt.qty(d)) + ' más de ' + h(r.name)
								: 'Vuelven al stock ' + h(MJ.fmt.qty(-d)) + ' de ' + h(r.name)) + '</li>';
						}).join('') + '</ul>'
						: '<div class="mj-small mj-muted">Esta venta todavía no bajó stock: se descuenta lo que quede cuando se cobre o se entregue.</div>') +
				'</div>');
			}

			if (p.order) {
				parts.push('<div class="mj-mt mj-small">El pedido #' + h(p.order) + ' de WooCommerce se actualiza para que diga lo mismo.</div>');
			}
			if (p.redeliver) {
				parts.push('<div class="mj-mt">' + MJ.ui.alert('La venta estaba entregada y suma productos: vuelve a «A entregar».', 'warning') + '</div>');
			}

			// Con cobros: los cobros no se tocan, así que se avisa cómo queda el saldo con el total nuevo.
			const paid = num(p.paid) || 0;
			if (paid > 0.004) {
				const left = (num((p.totals || {}).total) || 0) - paid;
				const text = left > 0.004
					? 'Ya se cobraron ' + money(paid) + ': con el total nuevo queda debiendo ' + money(left) + '.'
					: (left < -0.004
						? 'Ya se cobraron ' + money(paid) + ': con el total nuevo sobran ' + money(-left) + '. Después corregí o borrá el cobro.'
						: 'Ya se cobraron ' + money(paid) + ': con el total nuevo queda justo.');
				parts.push('<div class="mj-mt">' + MJ.ui.alert(text, Math.abs(left) > 0.004 ? 'warning' : 'info') + '</div>');
			}

			return parts.join('');
		}

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				'<div class="mj-table-wrap"><table class="mj-table mj-table--compact mj-sale-edit__table"><thead><tr>' +
					'<th>Producto</th>' +
					'<th class="is-num">Cantidad</th>' +
					'<th class="is-num">Precio</th>' +
					(discountOn ? '<th class="is-num">Descuento</th>' : '') +
					'<th class="is-num">Subtotal</th>' +
					'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
				'</tr></thead><tbody data-region="lines">' + linesHtml() + '</tbody></table></div>' +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="mj-se-search">Agregar un producto</label>' +
					'<input class="mj-input" id="mj-se-search" type="search" placeholder="Buscá por nombre o SKU…">' +
					'<div class="mj-field__hint">Lo que agregues toma el precio sugerido con el dólar de la venta; lo podés cambiar.</div>' +
				'</div>' +
				'<div class="mj-grid mj-grid--2">' +
					(discountOn
						? '<div class="mj-field">' +
							'<label class="mj-field__label" for="mj-se-discount">Descuento de la venta</label>' +
							'<input class="mj-input" id="mj-se-discount" type="text" inputmode="decimal" data-field="discount_total" value="' + h(state.discount > 0 ? inputNumber(state.discount) : '') + '" placeholder="0">' +
						'</div>'
						: '') +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-se-shipping">Envío</label>' +
						'<input class="mj-input" id="mj-se-shipping" type="text" inputmode="decimal" data-field="shipping_total" value="' + h(state.shipping > 0 ? inputNumber(state.shipping) : '') + '" placeholder="0">' +
					'</div>' +
				'</div>' +
				'<div class="mj-sale-edit__summary">' +
					'<div data-region="totals">' + totalsHtml() + '</div>' +
					'<div data-region="effects">' + effectsHtml() + '</div>' +
				'</div>' +
				'<div class="mj-field mj-mt">' +
					'<label class="mj-field__label" for="mj-se-reason">Motivo' + (edit.requires_reason ? '' : ' <span class="mj-muted">(opcional)</span>') + '</label>' +
					'<textarea class="mj-textarea" id="mj-se-reason" name="reason" rows="2" maxlength="255" placeholder="Por ejemplo: se lleva uno más"></textarea>' +
					'<div class="mj-field__hint">Queda en el historial de la venta' + (sale.order_id ? ' y en la nota del pedido' : '') + '.</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Editar productos · ' + (sale.code || ''),
			size: 'lg',
			body: body,
			// Un clic de más afuera no puede tirar a la basura una corrección a medio hacer.
			closeOnBackdrop: false,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save" disabled>' + MJ.icon('save') + '<span>Guardar cambios</span></button>',
			onClose() {
				state.closed = true;
				refresh.cancel();
				if (search && typeof search.destroy === 'function') search.destroy();
			},
		});

		/* ---------------- pintado ---------------- */

		function region(name) { return qs('[data-region="' + name + '"]', modal.el); }

		function paintLines() {
			region('lines').innerHTML = linesHtml();
		}

		function paintSave() {
			const btn = qs('[data-action="save"]', modal.el);
			if (!btn || btn.dataset.mjLoading === '1') return;
			btn.disabled = !!state.error || !state.preview || !(state.preview.changes || []).length;
		}

		function paintSummary() {
			region('totals').innerHTML = totalsHtml();
			region('effects').innerHTML = effectsHtml();
			paintSave();
		}

		/** Subtotal de cada línea y precio sugerido, sin repintar los campos (no se pierde el foco). */
		function paintLineTotals() {
			qsa('tr[data-index]', region('lines')).forEach(function (tr) {
				const line = state.lines[parseInt(tr.getAttribute('data-index'), 10)];
				if (!line) return;
				const cell = qs('[data-cell="total"]', tr);
				if (cell) cell.textContent = money(lineTotal(line));
				const price = qs('[data-line="unit_price"]', tr);
				if (price && blank(line.unit_price) && !blank(line.resolvedPrice)) price.placeholder = inputNumber(line.resolvedPrice);
			});
		}

		/* ---------------- vista previa ---------------- */

		const refresh = MJ.ui.debounce(function () {
			if (state.closed) return;
			const seq = ++state.seq;
			MJ.api.post('sales/' + sale.id + '/items/preview', payload()).then(function (res) {
				if (state.closed || seq !== state.seq) return;
				state.preview = res;
				state.error = null;
				// El servidor devuelve las líneas en el mismo orden, sin las que quedaron en cero.
				const sent = state.lines.filter(function (l) { return (num(l.qty) || 0) > 0; });
				(res.lines || []).forEach(function (line, i) {
					if (sent[i] && blank(sent[i].unit_price)) sent[i].resolvedPrice = line.unit_price;
				});
				paintLineTotals();
				paintSummary();
			}).catch(function (err) {
				if (state.closed || seq !== state.seq) return;
				if (err && err.status === 401) return;
				state.error = err;
				paintSummary();
			});
		}, PREVIEW_DELAY);

		function changed() {
			paintLineTotals();
			refresh();
		}

		function lineOf(el) {
			const tr = el.closest('tr[data-index]');
			return tr ? { tr: tr, index: parseInt(tr.getAttribute('data-index'), 10), line: state.lines[parseInt(tr.getAttribute('data-index'), 10)] } : null;
		}

		function addProduct(product) {
			if (!product) return;
			if (product.type === 'variable') {
				MJ.ui.toast('Elegí una de sus variaciones: un producto variable no se vende entero.', 'warning');
				return;
			}
			const ids = idsOf(product);
			if (!ids.productId) return;

			const existing = state.lines.find(function (l) {
				return (num(l.product_id) || 0) === ids.productId && (num(l.variation_id) || 0) === ids.variationId;
			});
			if (existing) {
				existing.qty = (num(existing.qty) || 0) + 1;
			} else {
				state.lines.push({
					id: 0,
					product_id: ids.productId,
					variation_id: ids.variationId,
					name: product.name,
					sku: product.sku || '',
					qty: 1,
					unit_price: '',
					discount: 0,
					isNew: true,
				});
			}
			paintLines();
			changed();
		}

		/* ---------------- eventos ---------------- */

		MJ.ui.on(modal.el, 'input', '[data-line]', function (e, input) {
			const found = lineOf(input);
			if (!found || !found.line) return;
			const field = input.getAttribute('data-line');
			const raw = String(input.value || '').trim();
			if (field === 'qty') found.line.qty = num(raw) || 0;
			else if (field === 'unit_price') found.line.unit_price = raw === '' ? '' : num(raw);
			else if (field === 'discount') found.line.discount = num(raw) || 0;
			changed();
		});

		MJ.ui.on(modal.el, 'click', '[data-action="qty-up"], [data-action="qty-down"]', function (e, btn) {
			const found = lineOf(btn);
			if (!found || !found.line) return;
			const step = btn.getAttribute('data-action') === 'qty-up' ? 1 : -1;
			found.line.qty = Math.max(0, (num(found.line.qty) || 0) + step);
			const input = qs('[data-line="qty"]', found.tr);
			if (input) input.value = inputQty(found.line.qty);
			changed();
		});

		MJ.ui.on(modal.el, 'click', '[data-action="remove-line"]', function (e, btn) {
			const found = lineOf(btn);
			if (!found || found.index < 0) return;
			state.lines.splice(found.index, 1);
			paintLines();
			changed();
		});

		MJ.ui.on(modal.el, 'input', '[data-field="discount_total"]', function (e, input) {
			state.discount = num(input.value) || 0;
			refresh();
		});

		MJ.ui.on(modal.el, 'input', '[data-field="shipping_total"]', function (e, input) {
			state.shipping = num(input.value) || 0;
			refresh();
		});

		MJ.ui.on(modal.el, 'input', '#mj-se-reason', function (e, input) {
			state.reason = input.value;
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			MJ.ui.clearErrors(form);
			if (edit.requires_reason && !String(state.reason || '').trim()) {
				MJ.ui.showErrors(form, { reason: 'Contá por qué se corrige la venta: queda en el historial.' });
				return;
			}

			refresh.cancel();
			MJ.ui.setLoading(btn, true);
			MJ.api.put('sales/' + sale.id + '/items', payload()).then(function (updated) {
				MJ.ui.toast('Venta corregida.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(updated);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo corregir la venta.');
			});
		});

		try {
			search = MJ.components.productSearch({
				input: qs('#mj-se-search', modal.el),
				clearOnSelect: true,
				onSelect(product) { addProduct(product); },
			});
		} catch (e) {
			console.error('[MJ] productSearch', e);
		}

		refresh();

		return modal;
	};
})();
