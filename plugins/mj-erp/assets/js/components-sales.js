/**
 * MJ ERP — components-sales.js
 * Piezas compartidas del módulo de ventas: buscador de clientes, modal de cobro y el aviso de
 * imputación a consignación.
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

	C.saleHelpers = {
		info: salesInfo,
		currency: saleCurrency,
		money: money,
		methodOptions: methodOptions,
		channelOptions: channelOptions,
		defaults: defaults,
		cashboxForMethod: cashboxForMethod,
		cashboxOptions: cashboxOptions,
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
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sp-cashbox">Caja</label>' +
						'<select class="mj-select" id="mj-sp-cashbox" name="cashbox_id"></select>' +
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

		// La caja sigue al medio de pago: si cobrás en dólares, la plata va a la caja de dólares.
		// Se puede pisar a mano, y a partir de ahí el select deja de moverse solo.
		let cashboxTouched = false;
		MJ.ui.on(modal.el, 'change', '#mj-sp-cashbox', function () { cashboxTouched = true; });
		MJ.ui.on(modal.el, 'change', '#mj-sp-method', function (e, elx) {
			if (cashboxTouched) return;
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
