/**
 * MJ ERP — components-consignment-edit.js
 * Diálogos para corregir lo ya cargado en una consignación: el precio acordado de una entrega,
 * una venta, un pago, y el visor de comprobantes.
 *
 * El precio acordado se guarda SIEMPRE en moneda base (USD). Los pesos son una forma de
 * escribirlo y de leerlo, no un segundo número guardado: si acordaste "22.000 pesos" con el
 * dólar a 1.570, lo que queda registrado es US$ 14,01, y mañana con otro dólar esos mismos
 * US$ 14,01 se leen como otra cantidad de pesos. Es lo que hace que el margen no se licúe.
 *
 * Cada corrección queda anotada en la bitácora (MJERP_Consignment_Log), que el revendedor ve
 * desde su panel: si le cambiás el precio de algo que ya tiene, se tiene que enterar.
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

	function num(v) { return MJ.fmt.toNumber(v); }
	function f(v) { return num(v) === null ? 0 : num(v); }

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCur() { return String(setting('base_currency', 'USD')).toUpperCase(); }
	function localCur() { return String(setting('local_currency', 'ARS')).toUpperCase(); }
	function tc() { return MJ.fmt.tc(); }

	function moneyBase(value) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, baseCur(), decimals);
	}

	function optionsHtml(list, value) {
		return (list || []).map(function (o) {
			return '<option value="' + h(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
		}).join('');
	}

	function showFormError(form, err) {
		const box = qs('[data-errors]', form);
		const message = MJ.api.message(err, 'No se pudo guardar.');
		if (box) {
			box.textContent = message;
			box.classList.remove('mj-hidden');
		}
		MJ.ui.showErrors(form, err);
	}

	function clearFormError(form) {
		const box = qs('[data-errors]', form);
		if (box) {
			box.textContent = '';
			box.classList.add('mj-hidden');
		}
		MJ.ui.clearErrors(form);
	}

	/* ------------------------------------------------------------------ */
	/* Campo de importe en dos monedas                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Un importe que se puede escribir en dólares o en pesos, indistintamente: al tipear en uno
	 * se recalcula el otro con el TC vigente. Lo que se manda al servidor es siempre el valor en
	 * moneda base.
	 *
	 * @param {Object} o { id, label, value(base), hint, required }
	 * @returns {string} html
	 */
	function dualAmountHtml(o) {
		const rate = tc();
		const base = f(o.value);
		const local = rate > 0 ? base * rate : null;

		return '<div class="mj-field">' +
			'<label class="mj-field__label" for="' + h(o.id) + '-base">' + h(o.label) + (o.required ? ' *' : '') + '</label>' +
			'<div class="mj-dual-amount" data-dual>' +
				'<div class="mj-dual-amount__side">' +
					'<span class="mj-dual-amount__cur">' + h(baseCur()) + '</span>' +
					'<input class="mj-input" id="' + h(o.id) + '-base" name="' + h(o.name || 'amount_base') + '" type="text" inputmode="decimal" ' +
						'data-dual-base value="' + h(base ? MJ.fmt.number(base, 2) : '') + '" autocomplete="off">' +
				'</div>' +
				(rate > 0
					? '<span class="mj-dual-amount__eq" aria-hidden="true">=</span>' +
					  '<div class="mj-dual-amount__side">' +
						'<span class="mj-dual-amount__cur">' + h(localCur()) + '</span>' +
						'<input class="mj-input" id="' + h(o.id) + '-local" type="text" inputmode="decimal" ' +
							'data-dual-local value="' + h(local ? MJ.fmt.number(local, 0) : '') + '" autocomplete="off">' +
					  '</div>'
					: '') +
			'</div>' +
			(rate > 0
				? '<div class="mj-field__hint">Escribilo en la moneda que quieras: el otro se ajusta solo con el dólar a ' +
					h(MJ.fmt.rate(rate)) + '. Se guarda en ' + h(baseCur()) + '.</div>'
				: '<div class="mj-field__hint mj-warning-text">No hay tipo de cambio cargado, así que solo se puede escribir en ' + h(baseCur()) + '.</div>') +
			(o.hint ? '<div class="mj-field__hint">' + o.hint + '</div>' : '') +
		'</div>';
	}

	/** Mantiene los dos lados del campo sincronizados. */
	function bindDualAmount(root) {
		qsa('[data-dual]', root).forEach(function (wrap) {
			const baseInput = qs('[data-dual-base]', wrap);
			const localInput = qs('[data-dual-local]', wrap);
			if (!baseInput || !localInput) return;

			baseInput.addEventListener('input', function () {
				const rate = tc();
				const value = num(baseInput.value);
				if (rate <= 0) return;
				localInput.value = value === null ? '' : MJ.fmt.number(value * rate, 0);
			});

			localInput.addEventListener('input', function () {
				const rate = tc();
				const value = num(localInput.value);
				if (rate <= 0) return;
				// Cuatro decimales: son los que guarda la base, y así 22.000 pesos no se
				// convierten en 14,00 perdiendo el centavo que después no cierra.
				baseInput.value = value === null ? '' : MJ.fmt.number(value / rate, 4);
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Precio acordado de una entrega                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Cambia el precio acordado de una línea entregada.
	 *
	 * @param {Object} opts { item, onSaved(item) }
	 */
	C.consignmentPriceModal = function (opts) {
		opts = opts || {};
		const item = opts.item || {};
		const fid = 'mj-price-' + Math.random().toString(36).slice(2, 8);
		const sold = f(item.qty_sold);

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-note mj-small mj-muted">' + MJ.icon('package') +
					'<span><span class="mj-strong">' + h(item.name || '') + '</span>' +
					(item.sku ? ' · <span class="mj-mono">' + h(item.sku) + '</span>' : '') +
					'<br>Entregadas ' + h(MJ.fmt.qty(item.qty)) + ' · le quedan ' + h(MJ.fmt.qty(item.qty_on_hand)) + '</span>' +
				'</div>' +
				(sold > 0
					? MJ.ui.alert('Ya vendió ' + MJ.fmt.qty(sold) + ' unidades a ' + moneyBase(item.unit_price_base) +
						'. Las ventas ya registradas conservan el precio con el que se cargaron: el precio nuevo rige de acá en adelante.', 'warning')
					: '') +
				dualAmountHtml({
					id: fid,
					name: 'unit_price_base',
					label: 'Precio acordado por unidad',
					value: item.unit_price_base,
					required: true,
					hint: 'Es lo que el revendedor te va a deber por cada unidad que venda.',
				}) +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-reason">Por qué lo cambiás</label>' +
					'<input class="mj-input" id="' + fid + '-reason" name="reason" type="text" maxlength="255" placeholder="Ej: le dije 22.000 pesos">' +
					'<div class="mj-field__hint">Queda en su historial: el revendedor ve que le cambiaste el precio y por qué.</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('save') + '<span>Guardar precio</span></button>';

		return MJ.ui.modal({
			title: 'Precio acordado',
			size: 'sm',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				let submitting = false;

				bindDualAmount(root);

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormError(form);

					const price = num(qs('[data-dual-base]', form).value);
					if (price === null || price < 0) {
						MJ.ui.showErrors(form, { unit_price_base: 'Poné el precio acordado.' });
						return;
					}

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.put('consignments/' + item.consignment_id + '/items/' + item.id, {
						unit_price_base: price,
						reason: String(qs('[name="reason"]', form).value || ''),
					}).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Precio actualizado.', 'success');
						modal.close();
						if (typeof opts.onSaved === 'function') opts.onSaved(res);
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						showFormError(form, err);
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Editar una venta ya registrada                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @param {Object} opts { sale, onSaved() }
	 */
	C.saleEditModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const fid = 'mj-esale-' + Math.random().toString(36).slice(2, 8);
		const allocated = f(sale.allocated_base);

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-note mj-small mj-muted">' + MJ.icon('cart') +
					'<span><span class="mj-strong">' + h(sale.name || '') + '</span>' +
					(sale.sku ? ' · <span class="mj-mono">' + h(sale.sku) + '</span>' : '') + '</span>' +
				'</div>' +
				(allocated > 0.0001
					? MJ.ui.alert('Esta venta ya tiene ' + moneyBase(allocated) + ' de pagos aplicados. Si la achicás por debajo de eso, la diferencia le queda como saldo a favor.', 'warning')
					: '') +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-qty">Cantidad</label>' +
						'<input class="mj-input" id="' + fid + '-qty" name="qty" type="text" inputmode="decimal" value="' + h(MJ.fmt.number(f(sale.qty), 4).replace(/[.,]0+$/, '')) + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="sale_date" type="date" value="' + h(sale.sale_date || '') + '">' +
					'</div>' +
				'</div>' +
				dualAmountHtml({
					id: fid,
					name: 'unit_price_base',
					label: 'Precio por unidad',
					value: sale.unit_price_base,
					required: true,
				}) +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-note">Nota</label>' +
					'<input class="mj-input" id="' + fid + '-note" name="note" type="text" maxlength="255" value="' + h(sale.note || '') + '">' +
				'</div>' +
				'<div class="mj-summary" data-region="total"></div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('save') + '<span>Guardar</span></button>';

		return MJ.ui.modal({
			title: 'Editar la venta',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				const totalEl = qs('[data-region="total"]', form);
				let submitting = false;

				bindDualAmount(root);

				function refreshTotal() {
					const qty = f(num(qs('[name="qty"]', form).value));
					const price = f(num(qs('[data-dual-base]', form).value));
					const total = qty * price;
					totalEl.innerHTML = total > 0
						? '<div class="mj-summary__row"><span>Va a deber</span><strong>' + h(MJ.fmt.moneyPair(total)) + '</strong></div>'
						: '';
				}

				MJ.ui.on(form, 'input', 'input', refreshTotal);
				refreshTotal();

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormError(form);

					const qty = num(qs('[name="qty"]', form).value);
					const price = num(qs('[data-dual-base]', form).value);
					const errors = {};
					if (qty === null || qty <= 0) errors.qty = 'La cantidad tiene que ser mayor a cero.';
					if (price === null || price < 0) errors.unit_price_base = 'Poné el precio.';
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.put('consignment-sales/' + sale.id, {
						qty: qty,
						unit_price_base: price,
						sale_date: String(qs('[name="sale_date"]', form).value || ''),
						note: String(qs('[name="note"]', form).value || ''),
					}).then(function () {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Venta actualizada.', 'success');
						modal.close();
						if (typeof opts.onSaved === 'function') opts.onSaved();
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						showFormError(form, err);
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Editar un pago ya registrado                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * @param {Object} opts { payment, methods, onSaved() }
	 */
	C.paymentEditModal = function (opts) {
		opts = opts || {};
		const payment = opts.payment || {};
		const fid = 'mj-epay-' + Math.random().toString(36).slice(2, 8);
		const methods = Array.isArray(opts.methods) ? opts.methods : [];
		const isAdjust = payment.kind === 'ajuste';

		const today = tc();
		const paidInBase = String(payment.currency || '').toUpperCase() === baseCur();
		let rate0 = f(payment.rate);
		/*
		 * Un pago en dólares no usa el TC para nada, así que algunos quedaron guardados con el TC mal
		 * leído ("1.570" tomado como 1,57). Acá sí sirve para mostrar los pesos: si está fuera de
		 * toda escala, se arranca con el dólar de hoy.
		 */
		if (paidInBase && today > 0 && (rate0 <= 0 || rate0 < today / 10 || rate0 > today * 10)) rate0 = today;
		const base0 = f(payment.amount_base);
		const local0 = paidInBase ? base0 * rate0 : f(payment.amount);

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				MJ.ui.alert('Al guardar se vuelve a repartir sobre las ventas sin cobrar. Lo que sobre queda a favor.', 'info') +
				MJ.consign.amountFieldsHtml(fid, {
					label: 'Importe',
					required: true,
					base: base0,
					local: rate0 > 0 ? local0 : null,
					rate: rate0 > 0 ? rate0 : null,
				}) +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="payment_date" type="date" value="' + h(payment.payment_date || '') + '">' +
					'</div>' +
					(isAdjust
						? ''
						: '<div class="mj-field">' +
							'<label class="mj-field__label" for="' + fid + '-method">Cómo pagó</label>' +
							'<select class="mj-select" id="' + fid + '-method" name="method">' +
								optionsHtml(methods.map(function (m) { return { value: m.key, label: m.label }; }), payment.method) +
							'</select>' +
						'</div>') +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-ref">Referencia</label>' +
					'<input class="mj-input" id="' + fid + '-ref" name="reference" type="text" maxlength="190" value="' + h(payment.reference || '') + '">' +
				'</div>' +
				(isAdjust
					? '<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-reason">Motivo *</label>' +
						'<input class="mj-input" id="' + fid + '-reason" name="reason" type="text" maxlength="255" value="' + h(payment.reason || '') + '" required>' +
					'</div>'
					: '') +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-note">Nota</label>' +
					'<input class="mj-input" id="' + fid + '-note" name="note" type="text" maxlength="255" value="' + h(payment.note || '') + '">' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('save') + '<span>Guardar</span></button>';

		return MJ.ui.modal({
			title: isAdjust ? 'Editar el ajuste' : 'Editar el pago',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				const amounts = MJ.consign.bindAmountFields(form, {
					fixed: paidInBase ? ['rate', 'base'] : ['rate', 'local'],
				});
				let submitting = false;

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormError(form);

					const money = amounts.values();
					const amount = money.amount;
					const errors = {};
					if (amount === null || (!isAdjust && amount <= 0)) errors.amount = 'Poné el importe.';
					if (money.currency !== baseCur() && !(money.rate > 0)) errors.rate = 'Poné el tipo de cambio.';
					if (isAdjust && !String(qs('[name="reason"]', form).value || '').trim()) errors.reason = 'Un ajuste necesita un motivo.';
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = {
						amount: MJ.fmt.round(amount, 4),
						currency: money.currency,
						rate: money.rate > 0 ? MJ.fmt.round(money.rate, 6) : 0,
						payment_date: String(qs('[name="payment_date"]', form).value || ''),
						reference: String(qs('[name="reference"]', form).value || ''),
						note: String(qs('[name="note"]', form).value || ''),
					};
					const methodEl = qs('[name="method"]', form);
					if (methodEl) payload.method = String(methodEl.value || '');
					const reasonEl = qs('[name="reason"]', form);
					if (reasonEl) payload.reason = String(reasonEl.value || '');

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.put('consignment-payments/' + payment.id, payload).then(function () {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Pago actualizado.', 'success');
						modal.close();
						if (typeof opts.onSaved === 'function') opts.onSaved();
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						showFormError(form, err);
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Visor de comprobantes                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Muestra los comprobantes que subió el revendedor para un pago, sin salir del ERP, y deja
	 * aprobarlos o rechazarlos desde acá.
	 *
	 * @param {Object} opts { payment, onChanged() }
	 */
	C.receiptModal = function (opts) {
		opts = opts || {};
		const payment = opts.payment || {};

		const modal = MJ.ui.modal({
			title: 'Comprobantes del pago',
			size: 'lg',
			body: '<div data-region="receipts"><div class="mj-loading">' + MJ.ui.spinner() + '<span>Buscando los comprobantes…</span></div></div>',
			footer: '<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cerrar</button>',
		});

		const region = qs('[data-region="receipts"]', modal.el);
		let files = [];

		function paint() {
			if (!files.length) {
				region.innerHTML = MJ.ui.empty({
					icon: 'file',
					title: 'No hay comprobantes',
					text: 'Todavía no subió ningún archivo para este pago.',
				});
				return;
			}

			region.innerHTML = '<div class="mj-receipts">' + files.map(function (file) {
				const badge = MJ.ui.badgeRaw(
					file.status_label || file.status,
					file.status === 'aprobado' ? 'success' : (file.status === 'rechazado' ? 'danger' : 'warning')
				);

				const preview = !file.available
					? '<div class="mj-receipts__missing">' + MJ.icon('archive') + '<span>El archivo ya no está guardado.</span></div>'
					: (file.is_image
						? '<a href="' + h(file.url) + '" target="_blank" rel="noopener" class="mj-receipts__thumb">' +
							'<img src="' + h(file.url) + '" alt="' + h(file.file_name || 'Comprobante') + '" loading="lazy">' +
						  '</a>'
						: '<a href="' + h(file.url) + '" target="_blank" rel="noopener" class="mj-receipts__file">' +
							MJ.icon('file') + '<span>' + h(file.file_name || 'Archivo') + '</span>' +
						  '</a>');

				return '<div class="mj-receipts__item" data-receipt="' + h(file.id) + '">' +
					preview +
					'<div class="mj-receipts__meta">' +
						'<div>' + badge + ' <span class="mj-small mj-muted">' + h(file.size_text || '') + '</span></div>' +
						(file.created_at ? '<div class="mj-small mj-muted">Subido ' + h(MJ.fmt.datetime(file.created_at)) + '</div>' : '') +
						(file.reference ? '<div class="mj-small">Ref. ' + h(file.reference) + '</div>' : '') +
						(file.reason ? '<div class="mj-small mj-danger-text">' + h(file.reason) + '</div>' : '') +
						'<div class="mj-btn-group mj-mt">' +
							(file.available ? '<a class="mj-btn mj-btn--sm mj-btn--ghost" href="' + h(file.download_url || file.url) + '" target="_blank" rel="noopener">' + MJ.icon('download') + '<span>Descargar</span></a>' : '') +
							(file.can_review && file.status !== 'aprobado' ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--primary" data-action="approve-receipt">' + MJ.icon('check') + '<span>Aprobar</span></button>' : '') +
							(file.can_review && file.status !== 'rechazado' ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-danger-text" data-action="reject-receipt">' + MJ.icon('x') + '<span>Rechazar</span></button>' : '') +
						'</div>' +
					'</div>' +
				'</div>';
			}).join('') + '</div>';
		}

		function load() {
			return MJ.api.get('consignment-payments/' + payment.id + '/receipts').then(function (res) {
				files = (res && Array.isArray(res.items)) ? res.items : [];
				paint();
			}).catch(function (err) {
				region.innerHTML = MJ.ui.alert(MJ.api.message(err, 'No se pudieron cargar los comprobantes.'), 'danger');
			});
		}

		function review(id, decision, reason) {
			return MJ.api.post('receipts/' + id + '/review', { decision: decision, reason: reason || '' }).then(function () {
				MJ.ui.toast(decision === 'aprobado' ? 'Comprobante aprobado.' : 'Comprobante rechazado.', 'success');
				if (typeof opts.onChanged === 'function') opts.onChanged();
				return load();
			}).catch(function (err) {
				MJ.ui.toastError(err, 'No se pudo revisar el comprobante.');
			});
		}

		MJ.ui.on(modal.el, 'click', '[data-action="approve-receipt"]', function (e, btn) {
			const item = btn.closest('[data-receipt]');
			if (item) review(parseInt(item.getAttribute('data-receipt'), 10), 'aprobado');
		});

		MJ.ui.on(modal.el, 'click', '[data-action="reject-receipt"]', function (e, btn) {
			const item = btn.closest('[data-receipt]');
			if (!item) return;
			MJ.ui.prompt({
				title: 'Rechazar el comprobante',
				label: 'Motivo (lo ve el revendedor)',
				placeholder: 'Ej: la imagen está cortada',
				okLabel: 'Rechazar',
				danger: true,
			}).then(function (reason) {
				if (reason === null) return;
				review(parseInt(item.getAttribute('data-receipt'), 10), 'rechazado', reason);
			});
		});

		load();
		return modal;
	};
})();
