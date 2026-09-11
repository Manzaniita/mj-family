/**
 * MJ ERP — page-sale-new.js
 * MJ.pages.saleNew: cargar una venta.
 *
 * Pensada para ir rápido: el foco arranca en el buscador, Enter agrega el producto, las cantidades
 * se editan sin salir del teclado y F2 confirma. Cada cambio le pregunta al servidor
 * (POST /sales/preview) cómo queda la venta, así el reparto entre depósito y consignación se ve
 * antes de confirmar y no después.
 *
 * REST: POST /sales/preview, POST /sales, GET /sales/customer/{id}, GET /customers/search.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const PATH = '/ventas';
	const PREVIEW_DELAY = 250;

	function info() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function defaults() { return info().defaults || {}; }
	function currency() { return info().currency || MJ.config.wcCurrency || MJ.setting('local_currency', 'ARS'); }
	function money(v) { return MJ.fmt.money(v, currency()); }
	function baseCur() { return MJ.setting('base_currency', 'USD'); }
	function num(v) { return MJ.fmt.toNumber(v); }

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	/* ------------------------------------------------------------------ */
	/* HTML                                                                */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Nueva venta</h2>' +
				'<div class="mj-page-header__subtitle mj-muted">Buscá el producto, poné la cantidad y confirmá. Se emite el pedido en WooCommerce.</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + '<span>Volver</span></a>' +
			'</div>' +
		'</div>';
	}

	function searchHtml() {
		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-product">Producto</label>' +
				// El autocompletado envuelve este input en su propio contenedor, así que va suelto:
				// dentro de un input-group el desplegable quedaría posicionado contra el ícono.
				'<input class="mj-input" id="mj-sale-product" type="search" placeholder="Nombre o SKU… (Enter agrega)" autocomplete="off">' +
				'<div class="mj-field__hint mj-small mj-muted">Si el mismo producto ya está en la lista, suma una unidad.</div>' +
			'</div>' +
		'</div></div>';
	}

	/**
	 * El id de la ficha del ERP, que puede no existir todavía.
	 *
	 * El buscador devuelve dos cosas distintas: una ficha de la libreta (`type: 'customer'`) o una
	 * cuenta de la tienda sin ficha (`type: 'account'`). En el segundo caso el id de ficha es 0 y el
	 * servidor le abre una al registrar la venta.
	 */
	function customerCardId(state) {
		const c = state.customer;
		if (!c) return 0;
		if (c.type === 'account') return 0;
		return num(c.customer_id !== undefined ? c.customer_id : c.id) || 0;
	}

	/** La cuenta de WooCommerce detrás del cliente elegido (0 = no tiene). */
	function customerUserId(state) {
		const c = state.customer;
		if (!c) return 0;
		return num(c.user_id) || 0;
	}

	function customerHtml(state) {
		const chosen = state.customer;
		return '<div class="mj-card mj-mb"><div class="mj-card__body">' +
			'<div class="mj-row mj-row--wrap">' +
				'<div class="mj-field" style="flex:2 1 240px">' +
					'<label class="mj-field__label" for="mj-sale-customer">Cliente</label>' +
					'<input class="mj-input" id="mj-sale-customer" type="search" placeholder="Buscar cliente… (opcional)" value="' + h(chosen ? (chosen.name || '') : state.customerName) + '" autocomplete="off">' +
				'</div>' +
				'<div class="mj-field" style="flex:1 1 160px">' +
					'<label class="mj-field__label" for="mj-sale-channel">Canal</label>' +
					'<select class="mj-select" id="mj-sale-channel" data-field="channel"></select>' +
				'</div>' +
				'<div class="mj-field" style="flex:1 1 150px">' +
					'<label class="mj-field__label" for="mj-sale-date">Fecha</label>' +
					'<input class="mj-input" id="mj-sale-date" type="date" data-field="sale_date" value="' + h(state.date) + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:1 1 170px">' +
					'<label class="mj-field__label" for="mj-sale-list">Lista de precios</label>' +
					'<select class="mj-select" id="mj-sale-list" data-field="price_list_key"></select>' +
				'</div>' +
			'</div>' +
			'<div data-region="customer-note"></div>' +
		'</div></div>';
	}

	function customerNoteHtml(state) {
		if (!state.context || !state.context.consignee) return '';

		const account = state.context.account || {};
		const stock = state.context.stock || [];
		const units = stock.reduce(function (acc, row) { return acc + (num(row.qty_on_hand) || 0); }, 0);
		const balance = num(account.balance_base) || 0;

		const parts = [];
		parts.push('Es revendedor.');
		if (units > 0) {
			parts.push('Tiene ' + MJ.fmt.qty(units) + ' unidades en su poder: lo que compre de eso se le descuenta de su consignación y le queda como deuda.');
		} else {
			parts.push('Ahora mismo no tiene stock entregado, así que la venta sale del depósito.');
		}
		if (Math.abs(balance) >= 0.005) {
			parts.push(balance > 0 ? 'Saldo actual: te debe ' + MJ.fmt.money(balance, baseCur()) + '.' : 'Saldo actual: tiene ' + MJ.fmt.money(Math.abs(balance), baseCur()) + ' a favor.');
		}

		return MJ.ui.alert(parts.join(' '), units > 0 ? 'info' : 'neutral');
	}

	function linesHtml(state) {
		if (!state.lines.length) {
			return MJ.ui.empty({
				icon: 'cart',
				title: 'Todavía no agregaste productos',
				text: 'Buscá arriba por nombre o SKU. Podés seguir agregando sin soltar el teclado.',
			});
		}

		return '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Producto</th>' +
			'<th class="is-num" style="width:110px">Cantidad</th>' +
			'<th class="is-num" style="width:140px">Precio</th>' +
			'<th class="is-num" style="width:120px">Descuento</th>' +
			'<th class="is-num" style="width:130px">Subtotal</th>' +
			'<th class="is-actions"><span class="mj-hidden">Quitar</span></th>' +
		'</tr></thead><tbody>' +
		state.lines.map(lineRowHtml).join('') +
		'</tbody></table></div>';
	}

	function lineRowHtml(line, index) {
		const total = (num(line.qty) || 0) * (num(line.unit_price) || 0) - (num(line.discount) || 0);
		return '<tr data-index="' + index + '">' +
			'<td>' +
				'<div class="mj-strong">' + h(line.name) + '</div>' +
				(line.sku ? '<div class="mj-small mj-muted">SKU ' + h(line.sku) + '</div>' : '') +
				'<div class="mj-small" data-region="line-origin-' + index + '"></div>' +
			'</td>' +
			'<td class="is-num"><input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-line="qty" value="' + h(line.qty) + '" aria-label="Cantidad"></td>' +
			'<td class="is-num"><input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-line="unit_price" value="' + h(line.unit_price === null || line.unit_price === undefined ? '' : line.unit_price) + '" placeholder="auto" aria-label="Precio unitario"></td>' +
			'<td class="is-num"><input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-line="discount" value="' + h(line.discount || '') + '" placeholder="0" aria-label="Descuento"></td>' +
			'<td class="is-num mj-strong">' + h(money(total)) + '</td>' +
			'<td class="is-actions">' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="remove-line" aria-label="Quitar ' + h(line.name) + '">' + MJ.icon('trash') + '</button>' +
			'</td>' +
		'</tr>';
	}

	function summaryHtml(state) {
		const totals = (state.preview && state.preview.totals) || null;
		const allowDiscount = defaults().allow_discount !== false;

		if (!totals) {
			return '<div class="mj-card"><div class="mj-card__body mj-muted">Agregá un producto para ver el total.</div></div>';
		}

		const rows = [
			{ label: 'Subtotal', value: money(totals.subtotal) },
		];
		if (allowDiscount && (num(totals.discount_total) || 0) > 0) {
			rows.push({ label: 'Descuento', value: '− ' + money(totals.discount_total) });
		}
		if ((num(totals.shipping_total) || 0) > 0) {
			rows.push({ label: 'Envío', value: money(totals.shipping_total) });
		}

		const consignment = num(totals.consignment_total) || 0;

		return '<div class="mj-card"><div class="mj-card__body">' +
			'<div class="mj-grid mj-grid--2">' +
				(allowDiscount
					? '<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sale-discount">Descuento</label>' +
						'<input class="mj-input" id="mj-sale-discount" type="text" inputmode="decimal" data-field="discount_total" value="' + h(state.discount || '') + '" placeholder="0">' +
					'</div>'
					: '') +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-shipping">Envío</label>' +
					'<input class="mj-input" id="mj-sale-shipping" type="text" inputmode="decimal" data-field="shipping_total" value="' + h(state.shipping || '') + '" placeholder="0">' +
				'</div>' +
			'</div>' +
			'<dl class="mj-totals">' +
				rows.map(function (r) {
					return '<div class="mj-totals__row"><dt>' + h(r.label) + '</dt><dd>' + h(r.value) + '</dd></div>';
				}).join('') +
				'<div class="mj-totals__row mj-totals__row--total"><dt>Total</dt><dd>' + h(money(totals.total)) + '</dd></div>' +
				(consignment > 0
					? '<div class="mj-totals__row"><dt>De su consignación</dt><dd>' + h(money(consignment)) + '</dd></div>' +
					  '<div class="mj-totals__row"><dt>A cobrar ahora</dt><dd class="mj-strong">' + h(money(totals.collectable)) + '</dd></div>'
					: '') +
			'</dl>' +
			(consignment > 0
				? MJ.ui.alert('Lo que sale de la consignación no se cobra acá: va a la cuenta corriente del revendedor y lo cobrás cuando te pague.', 'info')
				: '') +
		'</div></div>';
	}

	function paymentHtml(state) {
		const collectable = state.preview && state.preview.totals ? (num(state.preview.totals.collectable) || 0) : 0;

		return '<div class="mj-card mj-mt"><div class="mj-card__body">' +
			'<label class="mj-checkbox mj-mb"><input type="checkbox" data-field="charge"' + (state.charge ? ' checked' : '') + '> <span class="mj-strong">Cobrado</span></label>' +
			'<div class="mj-grid mj-grid--2' + (state.charge ? '' : ' mj-hidden') + '" data-region="payment-fields">' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-pay-method">Medio de pago</label>' +
					'<select class="mj-select" id="mj-sale-pay-method" data-field="payment_method"></select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-cashbox">Caja</label>' +
					'<select class="mj-select" id="mj-sale-cashbox" data-field="cashbox_id"></select>' +
				'</div>' +
				'<div class="mj-field mj-grid__full">' +
					'<label class="mj-field__label" for="mj-sale-pay-amount">Importe cobrado</label>' +
					'<input class="mj-input" id="mj-sale-pay-amount" type="text" inputmode="decimal" data-field="payment_amount" value="' + h(state.paymentAmount) + '" placeholder="' + h(collectable > 0 ? MJ.fmt.number(collectable, 2) : '') + '">' +
					'<div class="mj-field__hint mj-small mj-muted">Vacío = todo lo que hay que cobrar.</div>' +
				'</div>' +
			'</div>' +
			'<div class="mj-field mj-mt">' +
				'<label class="mj-field__label" for="mj-sale-note">Nota</label>' +
				'<textarea class="mj-textarea" id="mj-sale-note" rows="2" data-field="note" placeholder="Opcional">' + h(state.note) + '</textarea>' +
			'</div>' +
			'<label class="mj-checkbox mj-mt"><input type="checkbox" data-field="create_order"' + (state.createOrder ? ' checked' : '') + '> <span>Emitir el pedido en WooCommerce</span></label>' +
			'<div class="mj-field__hint mj-small mj-muted">Es lo que hace que te llegue la notificación en la app y que la venta aparezca en los informes de Woo.</div>' +
		'</div></div>';
	}

	function actionsHtml(state) {
		const ready = state.lines.length > 0;
		return '<div class="mj-sticky-actions mj-row mj-row--end mj-mt">' +
			'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link(PATH)) + '">Cancelar</a>' +
			'<button type="button" class="mj-btn mj-btn--primary mj-btn--lg" data-action="confirm"' + (ready ? '' : ' disabled') + '>' +
				MJ.icon('check') + '<span>Confirmar venta</span>' +
			'</button>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	MJ.pages.saleNew = {
		title() { return 'Nueva venta'; },

		render(container, params, query) {
			const offs = [];
			const state = {
				lines: [],
				customer: null,
				customerName: '',
				context: null,
				channel: defaults().channel || '',
				priceList: defaults().price_list || '',
				date: MJ.config.today || MJ.fmt.today(),
				discount: '',
				shipping: '',
				note: '',
				charge: true,
				paymentAmount: '',
				paymentMethod: defaults().payment_method || '',
				cashboxId: defaults().cashbox_id || 0,
				cashboxTouched: false,
				createOrder: defaults().create_order !== false,
				preview: null,
				seq: 0,
				saving: false,
				disposed: false,
			};

			container.innerHTML =
				headerHtml() +
				customerHtml(state) +
				searchHtml() +
				'<div data-region="lines"></div>' +
				'<div class="mj-grid mj-grid--sale mj-mt">' +
					'<div data-region="summary"></div>' +
					'<div data-region="payment"></div>' +
				'</div>' +
				'<div data-region="actions"></div>';

			function region(name) { return qs('[data-region="' + name + '"]', container); }

			function paintLines() {
				const el = region('lines');
				if (el) el.innerHTML = linesHtml(state);
				paintOrigins();
			}

			/** Etiqueta bajo cada producto con de dónde sale cada unidad. */
			function paintOrigins() {
				if (!state.preview || !state.preview.lines) return;

				const byIndex = {};
				state.preview.lines.forEach(function (line) {
					const key = String(line.product_id) + ':' + String(line.variation_id || 0);
					byIndex[key] = byIndex[key] || { stock: 0, consignment: 0 };
					if (line.origin === 'consignacion') byIndex[key].consignment += num(line.qty) || 0;
					else byIndex[key].stock += num(line.qty) || 0;
				});

				state.lines.forEach(function (line, index) {
					const el = qs('[data-region="line-origin-' + index + '"]', container);
					if (!el) return;
					const key = String(line.product_id) + ':' + String(line.variation_id || 0);
					const split = byIndex[key];
					if (!split || split.consignment <= 0) { el.innerHTML = ''; return; }
					el.innerHTML = '<span class="mj-badge mj-badge--info">' + h(MJ.fmt.qty(split.consignment)) + ' de su consignación</span>' +
						(split.stock > 0 ? ' <span class="mj-badge mj-badge--neutral">' + h(MJ.fmt.qty(split.stock)) + ' del depósito</span>' : '');
				});
			}

			function paintSummary() {
				const el = region('summary');
				if (el) el.innerHTML = summaryHtml(state);
			}

			function paintPayment() {
				const el = region('payment');
				if (!el) return;
				el.innerHTML = paymentHtml(state);
				MJ.ui.fillSelect(qs('#mj-sale-pay-method', el), MJ.components.saleHelpers.methodOptions(), state.paymentMethod);
				MJ.ui.fillSelect(qs('#mj-sale-cashbox', el), MJ.components.saleHelpers.cashboxOptions(), state.cashboxId, 'Sin caja');
			}

			function paintActions() {
				const el = region('actions');
				if (el) el.innerHTML = actionsHtml(state);
			}

			function paintCustomerNote() {
				const el = region('customer-note');
				if (el) el.innerHTML = customerNoteHtml(state);
			}

			function paintAll() {
				paintLines();
				paintSummary();
				paintPayment();
				paintActions();
			}

			/* ---------------- preview ---------------- */

			function payload() {
				return {
					items: state.lines.map(function (line) {
						const item = {
							product_id: line.product_id,
							variation_id: line.variation_id || 0,
							qty: num(line.qty) || 0,
							discount: num(line.discount) || 0,
							name: line.name,
						};
						if (line.unit_price !== null && line.unit_price !== undefined && line.unit_price !== '') {
							item.unit_price = num(line.unit_price);
						}
						return item;
					}),
					customer_id: customerCardId(state),
					customer_user_id: customerUserId(state),
					price_list_key: state.priceList || undefined,
					discount_total: num(state.discount) || 0,
					shipping_total: num(state.shipping) || 0,
				};
			}

			const refreshPreview = MJ.ui.debounce(function () {
				if (!state.lines.length) {
					state.preview = null;
					paintSummary();
					paintPayment();
					paintActions();
					return;
				}

				const seq = ++state.seq;
				MJ.api.post('sales/preview', payload()).then(function (res) {
					if (state.disposed || seq !== state.seq) return;
					state.preview = res;

					// El servidor resuelve el precio sugerido: si el usuario no escribió uno, se
					// muestra el que va a quedar guardado en vez de un campo vacío.
					(res.lines || []).forEach(function (line) {
						const target = state.lines.find(function (l) {
							return l.product_id === line.product_id && (l.variation_id || 0) === (line.variation_id || 0);
						});
						if (target && (target.unit_price === null || target.unit_price === undefined || target.unit_price === '')) {
							target.resolvedPrice = line.unit_price;
						}
					});

					paintSummary();
					paintPayment();
					paintActions();
					paintOrigins();
					paintPriceHints();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					toastError(err, 'No se pudo calcular la venta.');
				});
			}, PREVIEW_DELAY);

			/** Muestra el precio resuelto en los campos que quedaron en «auto». */
			function paintPriceHints() {
				qsa('tr[data-index]', container).forEach(function (tr) {
					const index = parseInt(tr.getAttribute('data-index'), 10);
					const line = state.lines[index];
					const input = qs('[data-line="unit_price"]', tr);
					if (!line || !input || document.activeElement === input) return;
					if ((line.unit_price === null || line.unit_price === undefined || line.unit_price === '') && line.resolvedPrice !== undefined) {
						input.placeholder = MJ.fmt.number(line.resolvedPrice, 2);
					}
				});
			}

			/* ---------------- líneas ---------------- */

			function addProduct(product) {
				if (!product) return;
				const productId = product.is_variation ? (product.parent_id || product.id) : product.id;
				const variationId = product.is_variation ? product.id : 0;

				const existing = state.lines.find(function (l) {
					return l.product_id === productId && (l.variation_id || 0) === variationId;
				});
				if (existing) {
					existing.qty = (num(existing.qty) || 0) + 1;
				} else {
					state.lines.push({
						product_id: productId,
						variation_id: variationId,
						name: product.name,
						sku: product.sku || '',
						qty: 1,
						unit_price: '',
						discount: '',
					});
				}

				paintLines();
				refreshPreview();
			}

			/* ---------------- montaje ---------------- */

			MJ.ui.fillSelect(qs('#mj-sale-channel', container), MJ.components.saleHelpers.channelOptions(), state.channel);
			const priceLists = ((MJ.state.bootstrap && MJ.state.bootstrap.price_lists) || []).map(function (l) {
				return { value: l.key, label: l.label };
			});
			MJ.ui.fillSelect(qs('#mj-sale-list', container), priceLists, state.priceList, 'Precio de la tienda');

			paintAll();

			const productAc = MJ.components.productSearch({
				input: qs('#mj-sale-product', container),
				clearOnSelect: true,
				onSelect(product) { addProduct(product); },
			});

			const customerAc = MJ.components.customerSearch({
				input: qs('#mj-sale-customer', container),
				onSelect(customer) {
					state.customer = customer;
					state.customerName = customer.name || '';
					state.context = null;
					paintCustomerNote();

					// Ser revendedor es una propiedad de la *cuenta*, no de la ficha: sin cuenta no hay
					// consignación que consultar, y la venta es una venta común.
					const userId = customerUserId(state);
					if (!userId) {
						refreshPreview();
						return;
					}

					MJ.api.get('sales/customer/' + userId).then(function (context) {
						if (state.disposed) return;
						state.context = context;
						paintCustomerNote();
						// Un revendedor cambia el reparto de cada línea: hay que recalcular.
						refreshPreview();
					}).catch(function () { /* el contexto es informativo */ });
				},
				onClear() {
					state.customer = null;
					state.context = null;
					paintCustomerNote();
					refreshPreview();
				},
			});

			/* ---------------- eventos ---------------- */

			offs.push(MJ.ui.on(container, 'input', '[data-line]', function (e, el) {
				const tr = el.closest('tr');
				const index = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
				const line = state.lines[index];
				if (!line) return;
				line[el.getAttribute('data-line')] = el.value;
				refreshPreview();
			}));

			offs.push(MJ.ui.on(container, 'keydown', '[data-line="qty"]', function (e, el) {
				if (e.key !== 'Enter') return;
				e.preventDefault();
				const input = qs('#mj-sale-product', container);
				if (input) input.focus();
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="remove-line"]', function (e, el) {
				const tr = el.closest('tr');
				const index = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
				if (index < 0) return;
				state.lines.splice(index, 1);
				paintLines();
				refreshPreview();
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-field="channel"]', function (e, el) { state.channel = el.value; }));
			offs.push(MJ.ui.on(container, 'change', '[data-field="sale_date"]', function (e, el) { state.date = el.value; }));
			offs.push(MJ.ui.on(container, 'change', '[data-field="price_list_key"]', function (e, el) {
				state.priceList = el.value;
				// Cambiar de lista cambia todos los precios sugeridos.
				state.lines.forEach(function (line) { line.resolvedPrice = undefined; });
				refreshPreview();
			}));

			offs.push(MJ.ui.on(container, 'input', '[data-field="discount_total"]', function (e, el) {
				state.discount = el.value;
				refreshPreview();
			}));
			offs.push(MJ.ui.on(container, 'input', '[data-field="shipping_total"]', function (e, el) {
				state.shipping = el.value;
				refreshPreview();
			}));
			offs.push(MJ.ui.on(container, 'input', '[data-field="note"]', function (e, el) { state.note = el.value; }));
			offs.push(MJ.ui.on(container, 'input', '[data-field="payment_amount"]', function (e, el) { state.paymentAmount = el.value; }));
			/*
			 * La caja sigue al medio de pago (transferencia → caja de transferencias, dólares →
			 * caja de dólares), salvo que la elijas a mano: ahí manda tu elección.
			 */
			offs.push(MJ.ui.on(container, 'change', '[data-field="payment_method"]', function (e, el) {
				state.paymentMethod = el.value;
				if (state.cashboxTouched) return;
				state.cashboxId = MJ.components.saleHelpers.cashboxForMethod(el.value);
				const select = qs('#mj-sale-cashbox', container);
				if (select) MJ.ui.fillSelect(select, MJ.components.saleHelpers.cashboxOptions(), state.cashboxId, 'Sin caja');
			}));
			offs.push(MJ.ui.on(container, 'change', '[data-field="cashbox_id"]', function (e, el) {
				state.cashboxId = el.value;
				state.cashboxTouched = true;
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-field="charge"]', function (e, el) {
				state.charge = !!el.checked;
				const fields = qs('[data-region="payment-fields"]', container);
				if (fields) fields.classList.toggle('mj-hidden', !state.charge);
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-field="create_order"]', function (e, el) {
				state.createOrder = !!el.checked;
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="confirm"]', function (e, btn) { confirm(btn); }));

			function onKey(e) {
				if (e.key === 'F2' && !MJ.ui.modalCount()) {
					e.preventDefault();
					confirm(qs('[data-action="confirm"]', container));
				}
			}
			document.addEventListener('keydown', onKey);
			offs.push(function () { document.removeEventListener('keydown', onKey); });

			function confirm(btn) {
				if (state.saving || !state.lines.length) return;
				state.saving = true;
				if (btn) MJ.ui.setLoading(btn, true);

				const body = payload();
				// Un nombre suelto (sin ficha ni cuenta) igual queda escrito en la venta: es mejor que
				// «cliente sin nombre», y el día que esa persona vuelva se le puede abrir la ficha.
				body.customer_name = state.customer ? '' : String(qs('#mj-sale-customer', container).value || '').trim();
				body.channel = state.channel || undefined;
				body.sale_date = state.date || undefined;
				body.note = state.note || undefined;
				body.create_order = state.createOrder;

				if (state.charge) {
					body.payment_method = state.paymentMethod || undefined;
					body.cashbox_id = state.cashboxId || undefined;
					body.payment = {
						method: state.paymentMethod || undefined,
						cashbox_id: state.cashboxId || undefined,
					};
					if (String(state.paymentAmount).trim() !== '') {
						body.payment.amount = num(state.paymentAmount);
					}
				}

				MJ.api.post('sales', body).then(function (sale) {
					if (state.disposed) return;
					MJ.ui.toast('Venta registrada.', 'success');
					MJ.events.emit('sales:changed', {});
					MJ.router.navigate(PATH + '/' + sale.id);
				}).catch(function (err) {
					state.saving = false;
					if (btn) MJ.ui.setLoading(btn, false);
					toastError(err, 'No se pudo registrar la venta.');
				});
			}

			// Entrada desde la lista de clientes (`/ventas/nueva?cliente=12`): la ficha ya está
			// elegida, así que la pantalla arranca con el cliente puesto y el foco en el producto.
			const preselected = query && query.cliente ? parseInt(query.cliente, 10) : 0;
			if (preselected > 0) {
				MJ.api.get('customers/' + encodeURIComponent(preselected)).then(function (customer) {
					if (state.disposed || !customer || !customer.id) return;
					state.customer = Object.assign({ type: 'customer', customer_id: customer.id }, customer);
					state.customerName = customer.name || '';
					const input = qs('#mj-sale-customer', container);
					if (input) input.value = state.customerName;
					if (customer.price_list_key) {
						state.priceList = customer.price_list_key;
						const listSelect = qs('#mj-sale-list', container);
						if (listSelect) listSelect.value = state.priceList;
					}
					paintCustomerNote();
					refreshPreview();
				}).catch(function () { /* si no se puede, la venta se carga igual a mano */ });
			}

			// El foco arranca en el buscador: es lo primero que se usa siempre.
			const productInput = qs('#mj-sale-product', container);
			if (productInput) {
				try { productInput.focus({ preventScroll: true }); } catch (err) { productInput.focus(); }
			}

			return function cleanup() {
				state.disposed = true;
				if (productAc && productAc.destroy) productAc.destroy();
				if (customerAc && customerAc.destroy) customerAc.destroy();
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
