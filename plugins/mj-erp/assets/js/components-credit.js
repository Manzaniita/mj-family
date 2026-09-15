/**
 * MJ ERP — components-credit.js
 * El saldo a favor de un cliente: cómo se lee y los dos movimientos a mano, ajustar y
 * «le di la plata».
 *
 * El saldo no se convierte: queda en la moneda en que el cliente pagó. Cuando hace falta una
 * referencia en la otra moneda se muestra al dólar de hoy, y solo como referencia.
 *
 * REST: POST /customers/{id}/credit/adjust, POST /customers/{id}/credit/payout.
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
	/* creditAdjustModal                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Sumar o restar saldo a mano, con motivo.
	 * @param {Object} opts { customer, balances, onSaved(statement) }
	 */
	C.creditAdjustModal = function (opts) {
		opts = opts || {};
		const customer = opts.customer || {};
		const balances = C.creditBalancesList(opts.balances);
		const local = String(MJ.setting('local_currency', 'ARS')).toUpperCase();
		const currencies = (MJ.setting('currencies', []) || []).map(function (c) { return String(c).toUpperCase(); });

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				(balances.length
					? '<div class="mj-alert mj-alert--info mj-mb">Hoy tiene ' + h(C.creditBalancesText(balances)) + '.</div>'
					: '') +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Qué hacés</span>' +
					'<div class="mj-row mj-row--wrap">' +
						'<label class="mj-checkbox"><input type="radio" name="direction" value="sumar" checked> <span>Sumar al saldo</span></label>' +
						'<label class="mj-checkbox"><input type="radio" name="direction" value="restar"> <span>Restar del saldo</span></label>' +
					'</div>' +
				'</div>' +
				'<div class="mj-grid mj-grid--2">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-ca-amount">Importe</label>' +
						'<input class="mj-input" id="mj-ca-amount" name="amount" type="text" inputmode="decimal" autofocus>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-ca-currency">Moneda</label>' +
						'<select class="mj-select" id="mj-ca-currency" name="currency"></select>' +
						'<div class="mj-field__hint">El saldo queda en esta moneda y no se convierte.</div>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-ca-reason">Motivo</label>' +
						'<textarea class="mj-textarea" id="mj-ca-reason" name="reason" rows="2" maxlength="255" placeholder="Por ejemplo: compensación por la demora del envío"></textarea>' +
						'<div class="mj-field__hint">Obligatorio: queda en los movimientos del saldo.</div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Ajustar el saldo · ' + (customer.name || ''),
			size: 'md',
			body: body,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('check') + '<span>Guardar ajuste</span></button>',
		});

		MJ.ui.fillSelect(qs('#mj-ca-currency', modal.el), currencyOptions(currencies.length ? currencies : [local]), local);

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.clearErrors(form);

			const amount = num(data.amount) || 0;
			const errors = {};
			if (amount <= 0) errors.amount = 'Escribí cuánto.';
			if (!String(data.reason || '').trim()) errors.reason = 'Contá el motivo del ajuste.';
			if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

			MJ.ui.setLoading(btn, true);
			MJ.api.post('customers/' + customer.id + '/credit/adjust', {
				amount: data.direction === 'restar' ? -amount : amount,
				currency: data.currency,
				reason: data.reason,
			}).then(function (statement) {
				MJ.ui.toast('Saldo ajustado.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(statement);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo ajustar el saldo.');
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
