/**
 * MJ ERP — components-sales.js
 * Piezas compartidas del módulo de ventas: buscador de clientes, modal de cobro, entrega (qué hay
 * que hacer para que lo vendido llegue a manos del cliente) y el aviso de imputación a
 * consignación.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};
	const C = MJ.components;

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	function el(sel) { return typeof sel === 'string' ? qs(sel) : sel; }

	function salesInfo() {
		return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {};
	}

	function saleCurrency() {
		return salesInfo().currency || MJ.config.wcCurrency || MJ.setting('local_currency', 'ARS');
	}

	function money(value, currency) {
		return MJ.fmt.money(value, currency || saleCurrency());
	}

	function methodOptions() {
		return (salesInfo().payment_methods || []).map(function (m) {
			return { value: m.key, label: m.label };
		});
	}

	function channelOptions() {
		return (salesInfo().channels || []).map(function (c) {
			return { value: c.key, label: c.label };
		});
	}

	function defaults() {
		return salesInfo().defaults || {};
	}

	/**
	 * Caja donde entra la plata de un medio de pago, tal como quedó configurada en
	 * Configuración → Ventas. 0 = la caja por defecto del ERP.
	 *
	 * Es la misma regla que aplica el servidor al guardar; acá solo se adelanta para que el
	 * formulario muestre desde el principio la caja correcta.
	 *
	 * @param {string} method Clave del medio de pago.
	 * @returns {number} Id de caja (0 = ninguna).
	 */
	function cashboxForMethod(method) {
		const key = String(method || '');
		const rows = salesInfo().payment_methods || [];
		for (let i = 0; i < rows.length; i++) {
			if (String(rows[i].key) === key) {
				const box = parseInt(rows[i].cashbox_id, 10) || 0;
				if (box > 0) return box;
				break;
			}
		}
		return parseInt(defaults().fallback_cashbox_id, 10) || 0;
	}

	/** Opciones de caja para los formularios de cobro. */
	function cashboxOptions() {
		return (MJ.state.cashboxes || []).map(function (c) {
			return { value: c.id, label: c.name + (c.currency ? ' (' + c.currency + ')' : '') };
		});
	}

	/* ------------------------------------------------------------------ */
	/* Entrega                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * ¿La tienda usa el paso de entrega? (Configuración → Ventas → Entrega).
	 *
	 * Quien vende solo por mostrador lo apaga y no ve nada de esto en ninguna pantalla.
	 */
	function deliveryEnabled() {
		return salesInfo().delivery_enabled !== false && MJ.setting('sales_delivery_enabled', true) !== false;
	}

	/** Métodos de entrega configurados: [{ key, label, delivered }]. */
	function deliveryMethods() {
		return salesInfo().delivery_methods || [];
	}

	function deliveryMethodOptions() {
		return deliveryMethods().map(function (m) {
			return { value: m.key, label: m.label };
		});
	}

	/** ¿Este método entrega en el acto? Es lo que decide con qué estado nace la venta. */
	function deliveryIsInstant(key) {
		const row = deliveryMethods().find(function (m) { return String(m.key) === String(key || ''); });
		return !!(row && MJ.fmt.toNumber(row.delivered));
	}

	/** Catálogo de estados: { pendiente: {label,color}, … }. */
	function deliveryStatuses() {
		return salesInfo().delivery_statuses || {};
	}

	function deliveryStatusLabel(key) {
		const row = deliveryStatuses()[String(key || '')];
		return row && row.label ? row.label : String(key || '');
	}

	/** El globo de estado de una venta, listo para pegar en una tabla. */
	function deliveryBadge(sale) {
		if (!sale) return '';
		const status = String(sale.delivery_status || 'pendiente');
		const label = sale.delivery_status_label || deliveryStatusLabel(status);
		const color = sale.delivery_status_color || (status === 'entregado' ? 'success' : 'warning');
		return MJ.ui.badgeRaw(label, color);
	}

	/* ------------------------------------------------------------------ */
	/* Cuándo la venta se vuelve firme                                     */
	/* ------------------------------------------------------------------ */

	/** La regla configurada (Configuración → Ventas → Pedido en WooCommerce). */
	function commitWhen() {
		return String(salesInfo().commit_when || MJ.setting('sales_commit_when', 'pago_o_entrega'));
	}

	/**
	 * ¿Una venta así de cobrada y así de entregada ya compromete la mercadería?
	 *
	 * Espejo de MJERP_Sales::is_committed() en PHP. Acá solo sirve para adelantar el aviso: quien
	 * decide de verdad, y quien emite el pedido, es el servidor.
	 *
	 * @param {Object} state { paid, delivered }
	 */
	function commitsNow(state) {
		const paid = !!(state && state.paid);
		const delivered = !!(state && state.delivered);
		switch (commitWhen()) {
			case 'registrar': return true;
			case 'pago': return paid;
			case 'entrega': return delivered;
			case 'manual': return false;
			default: return paid || delivered;
		}
	}

	/** ¿Esta venta ya guardada está firme? */
	function saleIsCommitted(sale) {
		if (!sale) return false;
		return commitsNow({
			paid: String(sale.payment_status || '') === 'pagada',
			delivered: !!sale.delivered,
		});
	}

	/** Qué falta para que se emita el pedido, en una frase. */
	function commitPendingText() {
		switch (commitWhen()) {
			case 'pago': return 'cuando la cobres';
			case 'entrega': return 'cuando la entregues';
			case 'manual': return 'cuando emitas el pedido a mano';
			case 'registrar': return '';
			default: return 'cuando la cobres o la entregues';
		}
	}

	C.saleHelpers = {
		info: salesInfo,
		currency: saleCurrency,
		money: money,
		methodOptions: methodOptions,
		channelOptions: channelOptions,
		defaults: defaults,
		cashboxForMethod: cashboxForMethod,
		cashboxOptions: cashboxOptions,
		deliveryEnabled: deliveryEnabled,
		deliveryMethods: deliveryMethods,
		deliveryMethodOptions: deliveryMethodOptions,
		deliveryIsInstant: deliveryIsInstant,
		deliveryStatuses: deliveryStatuses,
		deliveryStatusLabel: deliveryStatusLabel,
		deliveryBadge: deliveryBadge,
		commitWhen: commitWhen,
		commitsNow: commitsNow,
		saleIsCommitted: saleIsCommitted,
		commitPendingText: commitPendingText,
	};

	/* ------------------------------------------------------------------ */
	/* saleDeliveryModal                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Registrar la entrega de una venta: cómo se entrega, dónde y en qué estado quedó.
	 *
	 * El camino rápido no pasa por acá —desde la lista se marca entregado de un toque—; este modal
	 * es para cuando hay algo que decir: cambiar el método, anotar el punto de encuentro o el
	 * seguimiento, o volver atrás una entrega que se marcó de más.
	 *
	 * @param {Object} opts { sale, onSaved(sale) }
	 */
	C.saleDeliveryModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const statuses = deliveryStatuses();

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				'<div class="mj-grid mj-grid--2">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sd-method">Cómo se entrega</label>' +
						'<select class="mj-select" id="mj-sd-method" name="method"></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sd-status">Estado</label>' +
						'<select class="mj-select" id="mj-sd-status" name="status">' +
							Object.keys(statuses).map(function (key) {
								return '<option value="' + h(key) + '"' + (String(sale.delivery_status || 'pendiente') === key ? ' selected' : '') + '>' + h(statuses[key].label) + '</option>';
							}).join('') +
						'</select>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-sd-note">Dónde / referencia</label>' +
						'<input class="mj-input" id="mj-sd-note" name="note" type="text" maxlength="190" value="' + h(sale.delivery_note || '') + '" placeholder="Punto de encuentro, dirección, seguimiento…">' +
						'<div class="mj-field__hint mj-small mj-muted">Lo que necesites para acordarte al momento de salir.</div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Entrega',
			size: 'md',
			body: body,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('check') + '<span>Guardar</span></button>',
		});

		const methodSelect = qs('#mj-sd-method', modal.el);
		MJ.ui.fillSelect(methodSelect, deliveryMethodOptions(), sale.delivery_method || '', 'Sin definir');

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.ui.clearErrors(form);

			MJ.api.post('sales/' + sale.id + '/delivery', data).then(function (updated) {
				MJ.ui.toast('Entrega actualizada.', 'success');
				modal.close();
				MJ.events.emit('sales:changed', {});
				if (typeof opts.onSaved === 'function') opts.onSaved(updated);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo registrar la entrega.');
			});
		});

		return modal;
	};

	/* ------------------------------------------------------------------ */
	/* customerSearch                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Autocompletado sobre GET /customers/search.
	 *
	 * Trae dos cosas distintas y lo dice: las **fichas** del ERP (`type: 'customer'`), que existen
	 * aunque la persona nunca haya entrado a la tienda, y las **cuentas** de WooCommerce
	 * (`type: 'account'`) que todavía no tienen ficha. Cuando no aparece ninguna, ofrece crear la
	 * ficha ahí mismo con lo que se venía tipeando.
	 *
	 * @param {Object} opts { input, onSelect(customer), onClear(), onCreate(customer), minChars=2,
	 *                        limit=15, allowCreate=true }
	 */
	C.customerSearch = function (opts) {
		opts = opts || {};
		const input = el(opts.input);
		if (!input) throw new Error('customerSearch: input requerido');
		const minChars = opts.minChars === undefined ? 2 : Math.max(1, opts.minChars);
		const limit = opts.limit || 15;

		let wrap = input.closest('.mj-autocomplete');
		if (!wrap) {
			wrap = document.createElement('div');
			wrap.className = 'mj-autocomplete';
			input.parentNode.insertBefore(wrap, input);
			wrap.appendChild(input);
		}
		const listId = 'mj-cs-' + Math.random().toString(36).slice(2, 9);
		const list = document.createElement('div');
		list.className = 'mj-autocomplete__list mj-hidden';
		list.id = listId;
		list.setAttribute('role', 'listbox');
		wrap.appendChild(list);

		input.setAttribute('autocomplete', 'off');
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', listId);

		let items = [];
		let active = -1;
		let seq = 0;
		let destroyed = false;

		function setOpen(v) {
			list.classList.toggle('mj-hidden', !v);
			input.setAttribute('aria-expanded', v ? 'true' : 'false');
			if (!v) active = -1;
		}

		function metaOf(c) {
			const meta = [];
			if (c.email) meta.push(c.email);
			if (c.phone) meta.push(c.phone);
			if (c.company) meta.push(c.company);
			if (c.stats && c.stats.sales_count) meta.push(MJ.fmt.int(c.stats.sales_count) + (c.stats.sales_count === 1 ? ' venta' : ' ventas'));
			else if (c.orders_count) meta.push(MJ.fmt.int(c.orders_count) + (c.orders_count === 1 ? ' pedido' : ' pedidos'));
			return meta;
		}

		function tagOf(c) {
			// Una cuenta sin ficha se marca: al elegirla, el ERP le va a abrir una.
			if (c.type === 'account') return '<span class="mj-badge mj-badge--neutral mj-small">Cuenta de la tienda</span>';
			if (c.is_linked) return '<span class="mj-badge mj-badge--info mj-small">Con cuenta</span>';
			return '';
		}

		function render() {
			list.innerHTML = items.map(function (c, idx) {
				if (c.type === 'message') {
					return '<div class="mj-autocomplete__item is-disabled" aria-disabled="true">' + h(c.text) + '</div>';
				}
				if (c.type === 'create') {
					return '<div class="mj-autocomplete__item is-create' + (idx === active ? ' is-active' : '') + '" role="option" ' +
						'aria-selected="' + (idx === active ? 'true' : 'false') + '" data-index="' + idx + '">' +
						'<div class="mj-autocomplete__text">' +
							'<div class="mj-autocomplete__name">' + MJ.icon('plus') + ' Crear cliente «' + h(c.term) + '»' + '</div>' +
							'<div class="mj-autocomplete__meta mj-small mj-muted">Queda en tu libreta. No crea una cuenta en la tienda.</div>' +
						'</div>' +
					'</div>';
				}
				const meta = metaOf(c);
				const tag = tagOf(c);
				return '<div class="mj-autocomplete__item' + (idx === active ? ' is-active' : '') + '" role="option" ' +
					'aria-selected="' + (idx === active ? 'true' : 'false') + '" data-index="' + idx + '">' +
					(c.avatar ? '<img class="mj-autocomplete__thumb" src="' + h(c.avatar) + '" alt="" loading="lazy">' : '') +
					'<div class="mj-autocomplete__text">' +
						'<div class="mj-autocomplete__name">' + h(c.name || c.login || ('#' + c.id)) + (tag ? ' ' + tag : '') + '</div>' +
						(meta.length ? '<div class="mj-autocomplete__meta mj-small mj-muted">' + h(meta.join(' · ')) + '</div>' : '') +
					'</div>' +
				'</div>';
			}).join('');
			setOpen(items.length > 0);
		}

		function search(term) {
			const mine = ++seq;
			MJ.api.get('customers/search', { q: term, limit: limit }).then(function (res) {
				if (destroyed || mine !== seq) return;
				const rows = Array.isArray(res) ? res : (res && res.items) || [];
				items = rows.slice();
				if (canCreate()) items.push({ type: 'create', term: term });
				if (!items.length) items = [{ type: 'message', text: 'Sin clientes con ese nombre' }];
				active = -1;
				render();
			}).catch(function () {
				if (destroyed || mine !== seq) return;
				items = [];
				setOpen(false);
			});
		}

		const onInput = MJ.ui.debounce(function () {
			const term = String(input.value || '').trim();
			if (term.length < minChars) {
				items = [];
				setOpen(false);
				if (typeof opts.onClear === 'function') opts.onClear();
				return;
			}
			search(term);
		}, 300);

		input.addEventListener('input', onInput);

		function canCreate() {
			return opts.allowCreate !== false &&
				MJ.setting('customers_enabled', true) !== false &&
				MJ.setting('customers_quick_create', true) !== false &&
				!!(MJ.components && MJ.components.customerModal);
		}

		function choose(idx) {
			const c = items[idx];
			if (!c || c.type === 'message') return;
			setOpen(false);

			if (c.type === 'create') {
				MJ.components.customerModal({
					customer: { name: c.term },
					onSaved(saved) {
						input.value = saved.name || '';
						if (typeof opts.onCreate === 'function') opts.onCreate(saved);
						if (typeof opts.onSelect === 'function') opts.onSelect(Object.assign({ type: 'customer', customer_id: saved.id }, saved));
					},
				});
				return;
			}

			input.value = c.name || c.login || '';
			if (typeof opts.onSelect === 'function') opts.onSelect(c);
		}

		list.addEventListener('mousedown', function (e) {
			const item = e.target.closest ? e.target.closest('[data-index]') : null;
			if (!item) return;
			e.preventDefault();
			choose(parseInt(item.getAttribute('data-index'), 10));
		});

		input.addEventListener('keydown', function (e) {
			if (list.classList.contains('mj-hidden')) return;
			if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); render(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); render(); }
			else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
			else if (e.key === 'Escape') { setOpen(false); }
		});

		input.addEventListener('blur', function () { window.setTimeout(function () { setOpen(false); }, 120); });

		return {
			clear() { input.value = ''; items = []; setOpen(false); },
			destroy() { destroyed = true; input.removeEventListener('input', onInput); if (list.parentNode) list.parentNode.removeChild(list); },
		};
	};

	/* ------------------------------------------------------------------ */
	/* salePaymentModal                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Registrar un cobro contra una venta.
	 * @param {Object} opts { sale, onSaved(sale) }
	 */
	C.salePaymentModal = function (opts) {
		opts = opts || {};
		const sale = opts.sale || {};
		const due = MJ.fmt.toNumber(sale.due) || 0;
		const cashboxes = cashboxOptions();

		const body =
			'<form class="mj-form" data-form novalidate autocomplete="off">' +
				(due > 0
					? '<div class="mj-alert mj-alert--info mj-mb">Falta cobrar ' + h(money(due, sale.currency)) + ' de esta venta.</div>'
					: '<div class="mj-alert mj-alert--success mj-mb">Esta venta ya está cobrada. Podés registrar un cobro igual si hace falta.</div>') +
				'<div class="mj-grid mj-grid--2">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sp-amount">Importe</label>' +
						'<input class="mj-input" id="mj-sp-amount" name="amount" type="text" inputmode="decimal" value="' + h(due > 0 ? MJ.fmt.number(due, 2) : '') + '" autofocus>' +
						'<div class="mj-field__hint mj-small mj-muted">Dejalo vacío para cobrar todo lo que falta.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sp-date">Fecha</label>' +
						'<input class="mj-input" id="mj-sp-date" name="payment_date" type="date" value="' + h(MJ.config.today || '') + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sp-method">Medio de pago</label>' +
						'<select class="mj-select" id="mj-sp-method" name="method"></select>' +
					'</div>' +
					'<div class="mj-field" data-cashbox-field>' +
						'<label class="mj-field__label" for="mj-sp-cashbox">Caja</label>' +
						'<select class="mj-select" id="mj-sp-cashbox" name="cashbox_id"></select>' +
					'</div>' +
					'<div class="mj-field mj-hidden" data-credit-field>' +
						'<label class="mj-field__label" for="mj-sp-credit-cur">De qué saldo</label>' +
						'<select class="mj-select" id="mj-sp-credit-cur" name="credit_currency" disabled></select>' +
						'<div class="mj-field__hint mj-small mj-muted" data-credit-hint></div>' +
					'</div>' +
					'<div class="mj-field mj-grid__full">' +
						'<label class="mj-field__label" for="mj-sp-ref">Referencia</label>' +
						'<input class="mj-input" id="mj-sp-ref" name="reference" type="text" placeholder="Nº de operación, últimos dígitos…">' +
					'</div>' +
				'</div>' +
				'<div class="mj-form__errors mj-hidden" data-errors></div>' +
			'</form>';

		const modal = MJ.ui.modal({
			title: 'Registrar cobro',
			size: 'md',
			body: body,
			footer:
				'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
				'<button type="button" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('cash') + '<span>Registrar</span></button>',
		});

		const methodSelect = qs('#mj-sp-method', modal.el);
		const cashboxSelect = qs('#mj-sp-cashbox', modal.el);
		const initialMethod = sale.payment_method || defaults().payment_method;

		MJ.ui.fillSelect(methodSelect, methodOptions(), initialMethod);
		MJ.ui.fillSelect(cashboxSelect, cashboxes, sale.cashbox_id || cashboxForMethod(initialMethod), 'Sin caja');

		/*
		 * Saldo a favor: aparece como un medio más solo si el cliente tiene. No entra a ninguna caja,
		 * así que en su lugar se elige de qué saldo sale (cada moneda por separado).
		 */
		const creditKey = C.creditMethod ? C.creditMethod() : '';
		const creditSelect = qs('#mj-sp-credit-cur', modal.el);
		let creditBalances = [];

		function isCredit() { return !!creditKey && methodSelect.value === creditKey; }

		function paintCreditHint() {
			const hint = qs('[data-credit-hint]', modal.el);
			const row = creditBalances.find(function (b) { return b.currency === creditSelect.value; });
			if (!hint || !row) return;
			const inSale = C.creditConvert(row.amount, row.currency, sale.currency || saleCurrency());
			hint.textContent = 'Tiene ' + MJ.fmt.money(row.amount, row.currency) +
				(row.currency !== String(sale.currency || saleCurrency()).toUpperCase() && inSale !== null
					? ' (≈ ' + money(inSale, sale.currency) + ' al dólar de hoy).'
					: '.');
		}

		function syncCredit() {
			const on = isCredit();
			qs('[data-credit-field]', modal.el).classList.toggle('mj-hidden', !on);
			qs('[data-cashbox-field]', modal.el).classList.toggle('mj-hidden', on);
			creditSelect.disabled = !on;
			cashboxSelect.disabled = on;
			if (on) paintCreditHint();
		}

		if (creditKey && sale.customer_id && C.creditEnabled && C.creditEnabled()) {
			MJ.api.get('customers/' + sale.customer_id + '/credit').then(function (res) {
				if (!modal.isOpen()) return;
				creditBalances = C.creditBalancesList(res && res.balances).filter(function (b) { return b.amount > 0.004; });
				if (!creditBalances.length) return;
				MJ.ui.fillSelect(methodSelect, methodOptions().concat([{ value: creditKey, label: C.creditLabel() + ' · ' + C.creditBalancesText(creditBalances) }]), methodSelect.value);
				const own = creditBalances.find(function (b) { return b.currency === String(sale.currency || '').toUpperCase(); });
				MJ.ui.fillSelect(creditSelect, creditBalances.map(function (b) {
					return { value: b.currency, label: b.currency + ' · ' + MJ.fmt.money(b.amount, b.currency) };
				}), (own || creditBalances[0]).currency);
			}).catch(function () { /* sin saldo se cobra igual con los otros medios */ });
		}

		// La caja sigue al medio de pago: si cobrás en dólares, la plata va a la caja de dólares.
		// Se puede pisar a mano, y a partir de ahí el select deja de moverse solo.
		let cashboxTouched = false;
		MJ.ui.on(modal.el, 'change', '#mj-sp-cashbox', function () { cashboxTouched = true; });
		MJ.ui.on(modal.el, 'change', '#mj-sp-credit-cur', paintCreditHint);
		MJ.ui.on(modal.el, 'change', '#mj-sp-method', function (e, elx) {
			syncCredit();
			if (cashboxTouched || isCredit()) return;
			MJ.ui.fillSelect(cashboxSelect, cashboxes, cashboxForMethod(elx.value), 'Sin caja');
		});

		MJ.ui.on(modal.el, 'click', '[data-action="save"]', function (e, btn) {
			const form = qs('[data-form]', modal.el);
			const data = MJ.ui.formData(form);
			MJ.ui.setLoading(btn, true);
			MJ.ui.clearErrors(form);

			MJ.api.post('sales/' + sale.id + '/payments', data).then(function (updated) {
				MJ.ui.toast('Cobro registrado.', 'success');
				modal.close();
				if (typeof opts.onSaved === 'function') opts.onSaved(updated);
			}).catch(function (err) {
				MJ.ui.setLoading(btn, false);
				MJ.ui.showErrors(form, err);
				MJ.ui.toastError(err, 'No se pudo registrar el cobro.');
			});
		});

		return modal;
	};

	/* ------------------------------------------------------------------ */
	/* consignmentNotice                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * El aviso que explica, en una línea, qué parte de una venta salió de la consignación de un
	 * revendedor. Es lo primero que se mira cuando algo no cierra, así que se muestra igual en la
	 * pantalla de carga y en el detalle.
	 */
	C.consignmentNotice = function (sale) {
		if (!sale) return '';
		const qty = MJ.fmt.toNumber(sale.consignment_qty) || 0;
		if (qty <= 0) return '';

		const baseCur = MJ.setting('base_currency', 'USD');
		const debt = MJ.fmt.money(sale.consignment_base, baseCur);
		const who = sale.consignee_name ? h(sale.consignee_name) : 'el revendedor';

		return MJ.ui.alert(
			MJ.fmt.qty(qty) + ' de estas unidades salieron de la consignación de ' + who +
			'. Se le descuentan de su stock y le quedan como deuda por ' + debt + '.',
			'info'
		);
	};
})();
