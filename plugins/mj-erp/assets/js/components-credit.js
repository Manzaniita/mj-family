/**
 * MJ ERP — components-credit.js
 * El saldo a favor de un cliente: cómo se lee y lo que se hace a mano con él. MJ.components.creditModal
 * le suma (a favor), le resta (en contra) o lo usa para pagar una venta; creditPayoutModal es
 * «le di la plata».
 *
 * El saldo no se convierte: queda en la moneda en que el cliente pagó. Cuando hace falta una
 * referencia en la otra moneda se muestra al dólar de hoy, y solo como referencia.
 *
 * REST: GET /customers/{id}/credit, GET /customers/{id}/sales, POST /customers/{id}/credit/adjust,
 *       POST /customers/{id}/credit/payout, POST /sales/{id}/payments.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};
	const C = MJ.components;

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	function num(v) { return MJ.fmt.toNumber(v); }
	function info() { return ((MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}).credit || {}; }
	function baseCur() { return String(MJ.setting('base_currency', 'USD')).toUpperCase(); }

	C.creditEnabled = function () { return !!info().enabled; };
	C.creditLabel = function () { return info().label || 'Saldo a favor'; };
	C.creditMethod = function () { return info().method || ''; };

	/** [{currency, amount}] o {ARS: 100} → lista sin monedas en cero. */
	C.creditBalancesList = function (balances) {
		let list = [];
		if (Array.isArray(balances)) {
			list = balances.map(function (b) {
				return {
					currency: String(b.currency || '').toUpperCase(),
					amount: num(b.amount) || 0,
					equivalent: b.equivalent === undefined ? null : num(b.equivalent),
				};
			});
		} else if (balances && typeof balances === 'object') {
			list = Object.keys(balances).map(function (key) {
				return { currency: key.toUpperCase(), amount: num(balances[key]) || 0, equivalent: null };
			});
		}
		return list.filter(function (b) { return b.currency && Math.abs(b.amount) >= 0.005; });
	};

	/** "US$ 20 · $ 5.000": cada moneda por su lado. */
	C.creditBalancesText = function (balances) {
		return C.creditBalancesList(balances).map(function (b) { return MJ.fmt.money(b.amount, b.currency); }).join(' · ');
	};

	/**
	 * Un importe en otra moneda al dólar de hoy. null si no hay dólar cargado.
	 * Espejo de MJERP_Credit::convert(): el servidor es el que decide al guardar.
	 */
	C.creditConvert = function (amount, from, to) {
		const f = String(from || '').toUpperCase();
		const t = String(to || '').toUpperCase();
		const n = num(amount) || 0;
		if (f === t) return n;
		const rate = MJ.fmt.tc();
		if (!rate) return null;
		const base = baseCur();
		const inBase = f === base ? n : n / rate;
		return t === base ? inBase : inBase * rate;
	};

	function currencyOptions(list) {
		return list.map(function (code) { return { value: code, label: code }; });
	}

	/* ------------------------------------------------------------------ */
	/* creditModal                                                         */
	/* ------------------------------------------------------------------ */

	const MODE_FAVOR = 'favor';
	const MODE_AGAINST = 'contra';
	const MODE_PAY = 'pagar';

	/** Los decimales de la plata, los de Configuración. */
	function decimals() {
		const d = parseInt(MJ.setting('decimals', 2), 10);
		return isNaN(d) ? 2 : Math.max(0, Math.min(6, d));
	}

	/** Un número listo para un campo, escrito como lo vuelve a leer MJ.fmt.toNumber. */
	function inputNumber(v) {
		const n = num(v) || 0;
		return MJ.fmt.number(n, Math.abs(n - Math.round(n)) < 0.005 ? 0 : decimals());
	}

	/**
	 * El saldo de un cliente, a mano.
	 *
	 * A favor le suma, en contra le resta (nunca por debajo de cero: lo que debe sale de sus ventas),
	 * los dos con motivo y, si corresponde, la venta a la que se refieren. Y si tiene saldo y ventas
	 * que deben, lo usa para pagar una: queda como un cobro de esa venta con el saldo como medio.
	 *
	 * REST: GET /customers/{id}/credit?with_sales=1, POST /customers/{id}/credit/adjust,
	 *       POST /sales/{id}/payments.
	 *
	 * @param {Object} opts { customer, onSaved() }
	 */
	C.creditModal = function (opts) {
		opts = opts || {};
		const customer = opts.customer || {};
		const local = String(MJ.setting('local_currency', 'ARS')).toUpperCase();
		const configured = (MJ.setting('currencies', []) || []).map(function (c) { return String(c).toUpperCase(); });
		const currencies = configured.length ? configured : [local];

		const state = {
			mode: MODE_FAVOR,
			statement: null,
			recent: [],
			owing: [],
		};

		const modal = MJ.ui.modal({
			title: C.creditLabel() + ' · ' + (customer.name || ''),
			size: 'md',
			body: '<div class="mj-loading">' + MJ.ui.spinner() + '<span>Cargando el saldo y las ventas…</span></div>',
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save" disabled>' + MJ.icon('check') + '<span data-save-label>Guardar</span></button>',
		});

		function positive() {
			return C.creditBalancesList(state.statement && state.statement.balances).filter(function (b) { return b.amount > 0.004; });
		}

		function owing() {
			return state.owing.filter(function (s) { return (num(s.due) || 0) > 0.004; });
		}

		function reasonRequired() {
			return !state.statement || state.statement.requires_reason !== false;
		}

		function saleLabel(sale, withDue) {
			const due = num(sale.due) || 0;
			return sale.code + ' · ' + MJ.fmt.date(sale.sale_date) + ' · ' + MJ.fmt.money(sale.total, sale.currency) +
				(withDue && due > 0.004 ? ' · debe ' + MJ.fmt.money(due, sale.currency) : '');
		}

		/** "Debe $ 16.830": cada moneda por su lado, como el saldo. */
		function debtText() {
			const byCurrency = {};
			owing().forEach(function (s) {
				const cur = String(s.currency || local).toUpperCase();
				byCurrency[cur] = (byCurrency[cur] || 0) + (num(s.due) || 0);
			});
			return Object.keys(byCurrency).map(function (cur) { return MJ.fmt.money(byCurrency[cur], cur); }).join(' · ');
		}

		function summaryHtml() {
			const balances = positive();
			const debts = owing();
			const parts = [
				balances.length ? 'Tiene ' + h(C.creditBalancesText(balances)) + ' a favor.' : 'No tiene saldo a favor.',
				debts.length
					? 'Debe ' + h(debtText()) + (debts.length === 1 ? ' en 1 venta.' : ' en ' + MJ.fmt.int(debts.length) + ' ventas.')
					: 'No debe nada.',
			];
			return '<div class="mj-alert mj-alert--info mj-mb">' + parts.join(' ') + '</div>';
		}

		function radio(value, label, disabled) {
			return '<label class="mj-checkbox"><input type="radio" name="mode" value="' + value + '"' +
				(state.mode === value ? ' checked' : '') + (disabled ? ' disabled' : '') + '> <span>' + h(label) + '</span></label>';
		}

		function formHtml() {
			const canPay = positive().length > 0 && owing().length > 0;

			return '<form class="mj-form" data-form novalidate autocomplete="off">' +
				summaryHtml() +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Qué hacés</span>' +
					'<div class="mj-row mj-row--wrap">' +
						radio(MODE_FAVOR, 'A favor: le sumo saldo') +
						radio(MODE_AGAINST, 'En contra: le resto saldo', !positive().length) +
						(canPay ? radio(MODE_PAY, 'Uso el saldo para pagar una venta') : '') +
					'</div>' +
					(!positive().length ? '<div class="mj-field__hint">Para restar tiene que tener saldo a favor. Lo que debe sale de sus ventas.</div>' : '') +
				'</div>' +

				'<div class="mj-grid mj-grid--2" data-section="adjust">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cm-amount">Importe</label>' +
						'<input class="mj-input" id="mj-cm-amount" name="amount" type="text" inputmode="decimal">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cm-currency">Moneda</label>' +
						'<select class="mj-select" id="mj-cm-currency" name="currency"></select>' +
					'</div>' +
					'<div class="mj-field__hint mj-grid__full" data-adjust-hint></div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-cm-sale">Venta relacionada <span class="mj-muted">(opcional)</span></label>' +
						'<select class="mj-select" id="mj-cm-sale" name="sale_id"></select>' +
						'<div class="mj-field__hint">El ajuste queda enlazado a esa venta y anotado en su historial.</div>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-cm-reason">Motivo' + (reasonRequired() ? '' : ' <span class="mj-muted">(opcional)</span>') + '</label>' +
						'<textarea class="mj-textarea" id="mj-cm-reason" name="reason" rows="2" maxlength="255" placeholder="Por ejemplo: compensación por la demora del envío"></textarea>' +
						'<div class="mj-field__hint">' + (reasonRequired() ? 'Obligatorio: queda' : 'Queda') + ' en los movimientos del saldo.</div>' +
					'</div>' +
				'</div>' +

				'<div class="mj-grid mj-grid--2 mj-hidden" data-section="pay">' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-cm-pay-sale">Qué venta paga</label>' +
						'<select class="mj-select" id="mj-cm-pay-sale" name="pay_sale_id"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cm-pay-currency">De qué saldo</label>' +
						'<select class="mj-select" id="mj-cm-pay-currency" name="credit_currency"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cm-pay-amount">Importe</label>' +
						'<input class="mj-input" id="mj-cm-pay-amount" name="pay_amount" type="text" inputmode="decimal">' +
						'<div class="mj-field__hint" data-pay-hint></div>' +
					'</div>' +
					'<div class="mj-field__hint mj-grid__full">Queda como un cobro de la venta con «' + h(C.creditLabel()) + '»: baja lo que debe y baja el saldo.</div>' +
				'</div>' +

				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';
		}

		function field(name) { return qs('[name="' + name + '"]', modal.el); }

		/** La moneda del ajuste: a favor, cualquiera; en contra, solo las que tienen saldo. */
		function syncAdjust() {
			const select = field('currency');
			const hint = qs('[data-adjust-hint]', modal.el);
			if (state.mode === MODE_AGAINST) {
				const balances = positive();
				const current = balances.some(function (b) { return b.currency === select.value; }) ? select.value : (balances[0] && balances[0].currency);
				MJ.ui.fillSelect(select, balances.map(function (b) { return { value: b.currency, label: b.currency + ' · tiene ' + MJ.fmt.money(b.amount, b.currency) }; }), current);
				const row = balances.find(function (b) { return b.currency === select.value; });
				hint.innerHTML = row
					? 'Puede restarle hasta ' + h(MJ.fmt.money(row.amount, row.currency)) + ': el saldo no queda en negativo. <button type="button" class="mj-link" data-action="all">Restar todo</button>'
					: '';
			} else {
				MJ.ui.fillSelect(select, currencyOptions(currencies), currencies.indexOf(select.value) >= 0 ? select.value : local);
				hint.textContent = 'Queda en esta moneda y no se convierte.';
			}
		}

		/** Lo que paga: lo que debe la venta, o lo que alcance el saldo elegido. */
		function syncPay() {
			const sale = owing().find(function (s) { return String(s.id) === field('pay_sale_id').value; });
			const balance = positive().find(function (b) { return b.currency === field('credit_currency').value; });
			const hint = qs('[data-pay-hint]', modal.el);
			if (!sale || !balance) { hint.textContent = ''; return; }

			const due = num(sale.due) || 0;
			const reach = C.creditConvert(balance.amount, balance.currency, sale.currency);
			const factor = Math.pow(10, decimals());
			const amount = reach === null ? due : Math.min(due, Math.floor(reach * factor) / factor);
			field('pay_amount').value = inputNumber(amount);
			hint.textContent = 'En ' + String(sale.currency).toUpperCase() + '. Debe ' + MJ.fmt.money(due, sale.currency) +
				(reach !== null && balance.currency !== String(sale.currency).toUpperCase() ? '; el saldo alcanza para ' + MJ.fmt.money(reach, sale.currency) + ' al dólar de hoy.' : '.');
		}

		function paintMode() {
			const pay = state.mode === MODE_PAY;
			qs('[data-section="adjust"]', modal.el).classList.toggle('mj-hidden', pay);
			qs('[data-section="pay"]', modal.el).classList.toggle('mj-hidden', !pay);
			const labels = {};
			labels[MODE_FAVOR] = 'Sumar saldo';
			labels[MODE_AGAINST] = 'Restar saldo';
			labels[MODE_PAY] = 'Cobrar con el saldo';
			qs('[data-save-label]', modal.el).textContent = labels[state.mode];
			if (pay) syncPay();
			else syncAdjust();
		}

		function render() {
			if (!state.statement.can_manage) {
				modal.setBody(summaryHtml() + MJ.ui.alert('Mover el saldo a mano lo hace solo quien puede corregir ventas.', 'warning'));
				return;
			}

			modal.setBody(formHtml());

			MJ.ui.fillSelect(field('sale_id'), state.recent.map(function (s) { return { value: s.id, label: saleLabel(s, true) }; }), '', 'Ninguna');

			const debts = owing();
			const balances = positive();
			if (debts.length && balances.length) {
				MJ.ui.fillSelect(field('pay_sale_id'), debts.map(function (s) { return { value: s.id, label: saleLabel(s, true) }; }), debts[0].id);
				const own = balances.find(function (b) { return b.currency === String(debts[0].currency || '').toUpperCase(); });
				MJ.ui.fillSelect(field('credit_currency'), balances.map(function (b) {
					return { value: b.currency, label: b.currency + ' · ' + MJ.fmt.money(b.amount, b.currency) };
				}), (own || balances[0]).currency);
			}

			paintMode();
			qs('[data-action="save"]', modal.el).disabled = false;
			const first = field('amount');
			if (first) first.focus();
		}

		// Las ventas vienen del servidor: las últimas (cuántas, lo decide un filtro) y todas las que deben.
		MJ.api.get('customers/' + customer.id + '/credit', { with_sales: 1 }).then(function (res) {
			if (!modal.isOpen()) return;
			state.statement = res || {};
			const sales = state.statement.sales || {};
			state.recent = Array.isArray(sales.recent) ? sales.recent : [];
			state.owing = Array.isArray(sales.owing) ? sales.owing : [];
			render();
		}).catch(function (err) {
			if (!modal.isOpen()) return;
			modal.setBody(MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar el saldo del cliente.'), 'danger'));
		});

		MJ.ui.on(modal.el, 'change', '[name="mode"]', function (e, input) {
			state.mode = input.value;
			MJ.ui.clearErrors(qs('[data-form]', modal.el));
			paintMode();
		});
		MJ.ui.on(modal.el, 'change', '[name="currency"]', function () { if (state.mode === MODE_AGAINST) syncAdjust(); });
		MJ.ui.on(modal.el, 'change', '[name="pay_sale_id"], [name="credit_currency"]', syncPay);
		MJ.ui.on(modal.el, 'click', '[data-action="all"]', function () {
			const row = positive().find(function (b) { return b.currency === field('currency').value; });
			if (row) field('amount').value = inputNumber(row.amount);
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			if (!form) return;
			MJ.ui.clearErrors(form);

			let request;
			let done;

			if (state.mode === MODE_PAY) {
				const saleId = field('pay_sale_id').value;
				const sale = owing().find(function (s) { return String(s.id) === saleId; });
				const amount = num(field('pay_amount').value) || 0;
				const errors = {};
				if (!sale) errors.pay_sale_id = 'Elegí qué venta paga.';
				if (amount <= 0) errors.pay_amount = 'Escribí cuánto.';
				else if (sale && amount > (num(sale.due) || 0) + 0.004) errors.pay_amount = 'Debe ' + MJ.fmt.money(sale.due, sale.currency) + ': no puede pagar más que eso.';
				if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

				request = MJ.api.post('sales/' + saleId + '/payments', {
					amount: amount,
					method: C.creditMethod(),
					credit_currency: field('credit_currency').value,
					payment_date: MJ.config.today || undefined,
				});
				done = 'Cobrado con el saldo: ' + sale.code + '.';
			} else {
				const amount = num(field('amount').value) || 0;
				const reason = String(field('reason').value || '').trim();
				const currency = field('currency').value;
				const errors = {};
				if (amount <= 0) errors.amount = 'Escribí cuánto.';
				if (!reason && reasonRequired()) errors.reason = 'Contá el motivo: queda en los movimientos del saldo.';
				if (state.mode === MODE_AGAINST) {
					const row = positive().find(function (b) { return b.currency === currency; });
					if (row && amount > row.amount + 0.004) errors.amount = 'Tiene ' + MJ.fmt.money(row.amount, row.currency) + ': no puede quedar en negativo.';
				}
				if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

				request = MJ.api.post('customers/' + customer.id + '/credit/adjust', {
					amount: state.mode === MODE_AGAINST ? -amount : amount,
					currency: currency,
					reason: reason,
					sale_id: parseInt(field('sale_id').value, 10) || 0,
				});
				done = state.mode === MODE_AGAINST ? 'Saldo restado.' : 'Saldo sumado.';
			}

			MJ.ui.setLoading(btn, true);
			request.then(function () {
				MJ.ui.toast(done, 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved();
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo guardar.');
			});
		});

		return modal;
	};

	/* ------------------------------------------------------------------ */
	/* creditPayoutModal                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * «Le di la plata»: el saldo sale como dinero, de una caja.
	 * @param {Object} opts { customer, balances, onSaved(statement) }
	 */
	C.creditPayoutModal = function (opts) {
		opts = opts || {};
		const customer = opts.customer || {};
		const balances = C.creditBalancesList(opts.balances).filter(function (b) { return b.amount > 0.004; });
		if (!balances.length) {
			MJ.ui.toast('No tiene saldo a favor para devolverle.', 'warning');
			return null;
		}

		const helpers = C.saleHelpers || {};
		const creditKey = C.creditMethod();
		const methods = (helpers.methodOptions ? helpers.methodOptions() : []).filter(function (m) { return m.value !== creditKey; });

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				'<div class="mj-alert mj-alert--info mj-mb">Tiene ' + h(C.creditBalancesText(balances)) + ' a favor. Lo que le des sale de ese saldo.</div>' +
				'<div class="mj-grid mj-grid--2">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cp-currency">De qué saldo</label>' +
						'<select class="mj-select" id="mj-cp-currency" name="currency"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cp-amount">Importe</label>' +
						'<input class="mj-input" id="mj-cp-amount" name="amount" type="text" inputmode="decimal">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cp-method">Cómo se la diste</label>' +
						'<select class="mj-select" id="mj-cp-method" name="method"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-cp-cashbox">De qué caja salió</label>' +
						'<select class="mj-select" id="mj-cp-cashbox" name="cashbox_id"></select>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-cp-reference">Referencia</label>' +
						'<input class="mj-input" id="mj-cp-reference" name="reference" type="text" maxlength="190" placeholder="Nº de transferencia, recibo…">' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-cp-reason">Nota <span class="mj-muted">(opcional)</span></label>' +
						'<input class="mj-input" id="mj-cp-reason" name="reason" type="text" maxlength="255">' +
					'</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Le di la plata · ' + (customer.name || ''),
			size: 'md',
			body: body,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('cash') + '<span>Registrar</span></button>',
		});

		const currencySelect = qs('#mj-cp-currency', modal.el);
		const amountInput = qs('#mj-cp-amount', modal.el);
		const methodSelect = qs('#mj-cp-method', modal.el);
		const cashboxSelect = qs('#mj-cp-cashbox', modal.el);
		const cashboxes = helpers.cashboxOptions ? helpers.cashboxOptions() : [];

		MJ.ui.fillSelect(currencySelect, balances.map(function (b) {
			return { value: b.currency, label: b.currency + ' · ' + MJ.fmt.money(b.amount, b.currency) };
		}), balances[0].currency);

		function fillAmount() {
			const row = balances.find(function (b) { return b.currency === currencySelect.value; });
			amountInput.value = row ? MJ.fmt.number(row.amount, Math.abs(row.amount - Math.round(row.amount)) < 0.005 ? 0 : 2) : '';
		}
		fillAmount();

		const defaultMethod = ((helpers.defaults ? helpers.defaults() : {}) || {}).payment_method || (methods[0] && methods[0].value);
		MJ.ui.fillSelect(methodSelect, methods, defaultMethod);
		MJ.ui.fillSelect(cashboxSelect, cashboxes, helpers.cashboxForMethod ? helpers.cashboxForMethod(methodSelect.value) : '', 'Sin caja');

		let cashboxTouched = false;
		MJ.ui.on(modal.el, 'change', '#mj-cp-currency', fillAmount);
		MJ.ui.on(modal.el, 'change', '#mj-cp-cashbox', function () { cashboxTouched = true; });
		MJ.ui.on(modal.el, 'change', '#mj-cp-method', function () {
			if (cashboxTouched || !helpers.cashboxForMethod) return;
			MJ.ui.fillSelect(cashboxSelect, cashboxes, helpers.cashboxForMethod(methodSelect.value), 'Sin caja');
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.clearErrors(form);

			if ((num(data.amount) || 0) <= 0) { MJ.ui.showErrors(form, { amount: 'Escribí cuánto le diste.' }); return; }

			MJ.ui.setLoading(btn, true);
			MJ.api.post('customers/' + customer.id + '/credit/payout', {
				amount: num(data.amount),
				currency: data.currency,
				method: data.method,
				cashbox_id: data.cashbox_id,
				reference: data.reference,
				reason: data.reason,
			}).then(function (statement) {
				MJ.ui.toast('Anotado: se le devolvió la plata.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(statement);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo registrar.');
			});
		});

		return modal;
	};
})();
