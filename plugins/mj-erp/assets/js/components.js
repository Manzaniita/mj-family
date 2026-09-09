/**
 * MJ ERP — components.js
 * Shared UI components: product search, product create modal, supplier/cashbox selects & modals,
 * pagination, CSV/TSV import modal, bulk add modal, status/price-list/currency selects.
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

	function el(sel) { return typeof sel === 'string' ? qs(sel) : sel; }

	function isActiveRow(row) {
		if (!row) return false;
		if (row.is_active === undefined || row.is_active === null) return true;
		return row.is_active === true || Number(row.is_active) === 1;
	}

	function stockText(p) {
		if (!p) return '';
		if (p.manage_stock && p.stock_quantity !== null && p.stock_quantity !== undefined) return 'Stock: ' + MJ.fmt.qty(p.stock_quantity);
		const map = { instock: 'En stock', outofstock: 'Sin stock', onbackorder: 'Pedido pendiente' };
		return map[p.stock_status] || (p.stock_status ? String(p.stock_status) : 'Stock: —');
	}

	function statusText(status) {
		const map = { publish: 'Publicado', draft: 'Borrador', private: 'Privado', pending: 'Pendiente', trash: 'Papelera' };
		return map[status] || status || '';
	}

	function wcCurrency() {
		return (MJ.state.bootstrap && MJ.state.bootstrap.wc && MJ.state.bootstrap.wc.currency) || MJ.config.wcCurrency || MJ.setting('local_currency', 'ARS');
	}

	/* ------------------------------------------------------------------ */
	/* productSearch                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Autocomplete over GET /products/search.
	 * @param {Object} opts { input, onSelect(product), onCreate(term)?, minChars=2, limit=15, clearOnSelect=false }
	 * @returns {{ close(), open(), clear(), destroy(), setTerm(term), getSelected() }}
	 */
	C.productSearch = function (opts) {
		opts = opts || {};
		const input = el(opts.input);
		if (!input) throw new Error('productSearch: input requerido');
		const minChars = opts.minChars === undefined ? 2 : Math.max(1, opts.minChars);
		const limit = opts.limit || 15;

		let wrap = input.closest('.mj-autocomplete');
		if (!wrap) {
			wrap = document.createElement('div');
			wrap.className = 'mj-autocomplete';
			input.parentNode.insertBefore(wrap, input);
			wrap.appendChild(input);
		}
		const listId = 'mj-ac-' + Math.random().toString(36).slice(2, 9);
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
		let isOpen = false;
		let selected = null;
		let lastTerm = '';
		let destroyed = false;

		function setOpen(v) {
			isOpen = v;
			list.classList.toggle('mj-hidden', !v);
			input.setAttribute('aria-expanded', v ? 'true' : 'false');
			if (!v) { active = -1; input.removeAttribute('aria-activedescendant'); }
		}

		function renderItem(it, idx) {
			const cls = 'mj-autocomplete__item' + (idx === active ? ' is-active' : '') + (it.type === 'create' ? ' mj-autocomplete__create' : '') + (it.type === 'message' ? ' is-disabled' : '');
			if (it.type === 'message') {
				return '<div class="' + cls + '" aria-disabled="true">' + h(it.text) + '</div>';
			}
			if (it.type === 'create') {
				return '<div class="' + cls + '" id="' + listId + '-' + idx + '" role="option" data-index="' + idx + '">' + MJ.icon('plus') + '<span>Crear producto «' + h(it.term) + '»</span></div>';
			}
			const p = it.product;
			const meta = [];
			if (p.sku) meta.push('SKU ' + p.sku);
			meta.push(stockText(p));
			if (p.price !== null && p.price !== undefined && p.price !== '') meta.push('Precio ' + MJ.fmt.money(p.price, wcCurrency()));
			if (p.cost_base !== null && p.cost_base !== undefined) meta.push('Costo ' + MJ.fmt.money(p.cost_base, p.cost_currency || MJ.setting('base_currency', 'USD')));
			if (p.status && p.status !== 'publish') meta.push(statusText(p.status));
			return '<div class="' + cls + '" id="' + listId + '-' + idx + '" role="option" aria-selected="' + (idx === active ? 'true' : 'false') + '" data-index="' + idx + '">' +
				(p.image ? '<img class="mj-autocomplete__thumb" src="' + h(p.image) + '" alt="" loading="lazy">' : '') +
				'<div class="mj-autocomplete__text">' +
					'<div class="mj-autocomplete__name">' + h(p.name) + (p.is_variation ? ' <span class="mj-tag">variación</span>' : '') + '</div>' +
					'<div class="mj-autocomplete__meta mj-small mj-muted">' + h(meta.join(' · ')) + '</div>' +
				'</div>' +
			'</div>';
		}

		function render() {
			list.innerHTML = items.map(renderItem).join('');
			setOpen(items.length > 0);
			if (active >= 0) {
				input.setAttribute('aria-activedescendant', listId + '-' + active);
				const node = qs('[data-index="' + active + '"]', list);
				if (node && node.scrollIntoView) node.scrollIntoView({ block: 'nearest' });
			}
		}

		function buildItems(products, term) {
			const out = products.map(function (p) { return { type: 'product', product: p }; });
			if (!out.length) out.push({ type: 'message', text: 'Sin resultados para «' + term + '»' });
			if (typeof opts.onCreate === 'function') out.push({ type: 'create', term: term });
			return out;
		}

		function selectableIndexes() {
			return items.map(function (it, i) { return it.type === 'message' ? -1 : i; }).filter(function (i) { return i >= 0; });
		}

		function move(delta) {
			const sel = selectableIndexes();
			if (!sel.length) return;
			let pos = sel.indexOf(active);
			pos = pos < 0 ? (delta > 0 ? 0 : sel.length - 1) : (pos + delta + sel.length) % sel.length;
			active = sel[pos];
			render();
		}

		function choose(idx) {
			const it = items[idx];
			if (!it || it.type === 'message') return;
			if (it.type === 'create') {
				setOpen(false);
				if (typeof opts.onCreate === 'function') opts.onCreate(it.term);
				return;
			}
			selected = it.product;
			input.dataset.productId = String(it.product.id);
			input.value = opts.clearOnSelect ? '' : it.product.name;
			lastTerm = input.value;
			setOpen(false);
			if (typeof opts.onSelect === 'function') opts.onSelect(it.product);
		}

		const search = MJ.ui.debounce(function (term) {
			const mine = ++seq;
			items = [{ type: 'message', text: 'Buscando…' }];
			render();
			MJ.api.get('products/search', { q: term, limit: limit }).then(function (res) {
				if (mine !== seq || destroyed) return;
				const products = Array.isArray(res) ? res : (res && Array.isArray(res.items) ? res.items : []);
				items = buildItems(products, term);
				active = products.length ? 0 : -1;
				if (!products.length && typeof opts.onCreate === 'function') active = items.length - 1;
				render();
			}).catch(function (err) {
				if (mine !== seq || destroyed) return;
				if (err && err.handled) { setOpen(false); return; }
				items = [{ type: 'message', text: 'Error al buscar: ' + MJ.api.message(err) }];
				if (typeof opts.onCreate === 'function') items.push({ type: 'create', term: term });
				active = -1;
				render();
			});
		}, opts.delay || 250);

		function onInput() {
			const term = input.value.trim();
			selected = null;
			delete input.dataset.productId;
			lastTerm = term;
			if (term.length < minChars) { search.cancel(); seq++; setOpen(false); items = []; return; }
			search(term);
		}

		function onKeydown(e) {
			if (e.key === 'ArrowDown') {
				if (!isOpen) {
					if (items.length) { setOpen(true); render(); } else if (input.value.trim().length >= minChars) { search(input.value.trim()); }
				} else move(1);
				e.preventDefault();
				return;
			}
			if (!isOpen) return;
			if (e.key === 'ArrowUp') { move(-1); e.preventDefault(); return; }
			if (e.key === 'Enter') {
				if (active >= 0) { e.preventDefault(); e.stopPropagation(); choose(active); }
				else { setOpen(false); }
				return;
			}
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setOpen(false); return; }
			if (e.key === 'Tab') { setOpen(false); }
		}

		function onFocus() {
			if (items.length && input.value.trim().length >= minChars && !selected) { setOpen(true); render(); }
		}

		function onDocClick(e) {
			if (!wrap.contains(e.target)) setOpen(false);
		}

		input.addEventListener('input', onInput);
		input.addEventListener('keydown', onKeydown);
		input.addEventListener('focus', onFocus);
		list.addEventListener('mousedown', function (e) { e.preventDefault(); });
		const offClick = MJ.ui.on(list, 'click', '[data-index]', function (e, node) { e.preventDefault(); choose(parseInt(node.getAttribute('data-index'), 10)); });
		const offHover = MJ.ui.on(list, 'mousemove', '[data-index]', function (e, node) {
			const i = parseInt(node.getAttribute('data-index'), 10);
			if (i !== active) { active = i; qsa('.mj-autocomplete__item', list).forEach(function (n) { n.classList.toggle('is-active', n === node); }); }
		});
		document.addEventListener('click', onDocClick);

		return {
			close() { setOpen(false); },
			open() { if (items.length) { setOpen(true); render(); } },
			clear() { input.value = ''; selected = null; delete input.dataset.productId; items = []; setOpen(false); },
			/**
			 * Marks a product as the current selection without searching again: use it after
			 * creating a product from `onCreate`, so refocusing the input does not reopen the
			 * stale list (which would cover the form underneath).
			 */
			setSelected(product) {
				selected = product || null;
				items = [];
				search.cancel();
				seq++;
				if (product) {
					input.value = product.name ? String(product.name) : input.value;
					input.dataset.productId = String(product.id);
				} else {
					delete input.dataset.productId;
				}
				lastTerm = input.value;
				setOpen(false);
			},
			setTerm(term) { input.value = term || ''; onInput(); },
			getSelected() { return selected; },
			getTerm() { return lastTerm; },
			destroy() {
				destroyed = true;
				search.cancel();
				input.removeEventListener('input', onInput);
				input.removeEventListener('keydown', onKeydown);
				input.removeEventListener('focus', onFocus);
				document.removeEventListener('click', onDocClick);
				offClick();
				offHover();
				if (list.parentNode) list.parentNode.removeChild(list);
			},
		};
	};

	/* ------------------------------------------------------------------ */
	/* productCreateModal                                                  */
	/* ------------------------------------------------------------------ */

	function categoryOptions(cats) {
		const byParent = {};
		(cats || []).forEach(function (c) {
			const p = Number(c.parent) || 0;
			(byParent[p] = byParent[p] || []).push(c);
		});
		Object.keys(byParent).forEach(function (k) { byParent[k].sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); }); });
		const out = [];
		const walk = function (parent, depth) {
			(byParent[parent] || []).forEach(function (c) {
				out.push({ value: c.id, label: (depth ? '— '.repeat(depth) : '') + c.name });
				if (depth < 6) walk(Number(c.id), depth + 1);
			});
		};
		walk(0, 0);
		// orphans (parent not in list)
		(cats || []).forEach(function (c) {
			if (!out.some(function (o) { return Number(o.value) === Number(c.id); })) out.push({ value: c.id, label: c.name });
		});
		return out;
	}

	/** Read-only MJ Dólar block injected into the settings by MJERP_Dolar. */
	function dolarInfo() {
		const d = MJ.setting('dolar', null);
		return d && typeof d === 'object' ? d : { active: false, ready: false, rate: 0, publish_mode: 'local' };
	}

	/**
	 * Modal to create a WooCommerce simple product (POST /products).
	 *
	 * With MJ Dólar publishing enabled the price field is asked in dollars: MJ Dólar owns the peso
	 * price from then on, so writing pesos here would be overwritten on its next read.
	 *
	 * @param {Object} opts { initialName, initialSku, initialCost, initialPrice, initialUsd, onCreated(product) }
	 */
	C.productCreateModal = function (opts) {
		opts = opts || {};
		const base = MJ.setting('base_currency', 'USD');
		const defaultStatus = MJ.setting('new_product_status', 'draft') === 'publish' ? 'publish' : 'draft';
		const fid = 'mj-pc-' + Math.random().toString(36).slice(2, 8);
		const costVal = (opts.initialCost !== undefined && opts.initialCost !== null && opts.initialCost !== '') ? MJ.fmt.number(opts.initialCost, 4).replace(/([.,]\d*?)0+$/, '$1').replace(/[.,]$/, '') : '';

		const usdMode = String(dolarInfo().publish_mode) === 'usd';
		const rawPrice = usdMode
			? (opts.initialUsd !== undefined ? opts.initialUsd : opts.initialPrice)
			: opts.initialPrice;
		const priceVal = (rawPrice !== undefined && rawPrice !== null && rawPrice !== '')
			? MJ.fmt.number(rawPrice, 2)
			: '';
		const priceField = usdMode ? 'usd_regular' : 'regular_price';
		const priceLabel = usdMode
			? 'Precio de venta (' + base + ')'
			: 'Precio regular (' + wcCurrency() + ')';
		const priceHint = usdMode
			? 'Se publica en MJ Dólar; la tienda lo muestra en pesos al TC vigente.'
			: '';

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-name">Nombre *</label>' +
					'<input class="mj-input" id="' + fid + '-name" name="name" type="text" required maxlength="255" value="' + h(opts.initialName || '') + '" autofocus>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-sku">SKU</label>' +
						'<input class="mj-input" id="' + fid + '-sku" name="sku" type="text" maxlength="100" value="' + h(opts.initialSku || '') + '">' +
						'<div class="mj-field__hint">Opcional. Debe ser único en WooCommerce.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-price">' + h(priceLabel) + '</label>' +
						'<input class="mj-input" id="' + fid + '-price" name="' + h(priceField) + '" type="text" inputmode="decimal" data-type="number" value="' + h(priceVal) + '" placeholder="0,00">' +
						(priceHint ? '<div class="mj-field__hint">' + h(priceHint) + '</div>' : '') +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cat">Categoría</label>' +
						'<select class="mj-select" id="' + fid + '-cat" name="category_id"><option value="">Cargando categorías…</option></select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-status">Estado</label>' +
						'<select class="mj-select" id="' + fid + '-status" name="status">' +
							'<option value="draft"' + (defaultStatus === 'draft' ? ' selected' : '') + '>Borrador</option>' +
							'<option value="publish"' + (defaultStatus === 'publish' ? ' selected' : '') + '>Publicado</option>' +
						'</select>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-checkbox"><input type="checkbox" name="manage_stock" checked> <span>Manejar stock</span></label>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-stock">Stock inicial</label>' +
						'<input class="mj-input" id="' + fid + '-stock" name="stock_quantity" type="text" inputmode="numeric" data-type="int" value="0">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cost">Costo unitario (' + h(base) + ')</label>' +
						'<input class="mj-input" id="' + fid + '-cost" name="cost" type="text" inputmode="decimal" data-type="number" value="' + h(costVal) + '" placeholder="0,00">' +
						'<div class="mj-field__hint">Opcional. Se guarda como costo actual del producto.</div>' +
					'</div>' +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('plus') + '<span>Crear producto</span></button>';

		return MJ.ui.modal({
			title: 'Crear producto',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const catSelect = qs('[name="category_id"]', form);
				const manage = qs('[name="manage_stock"]', form);
				const stockInput = qs('[name="stock_quantity"]', form);
				const submitBtn = qs('[data-submit]', root);

				const syncStock = function () { stockInput.disabled = !manage.checked; };
				manage.addEventListener('change', syncStock);
				syncStock();

				MJ.api.get('products/categories').then(function (cats) {
					const list = Array.isArray(cats) ? cats : (cats && Array.isArray(cats.items) ? cats.items : []);
					MJ.ui.fillSelect(catSelect, categoryOptions(list), '', 'Sin categoría');
				}).catch(function (err) {
					MJ.ui.fillSelect(catSelect, [], '', 'Sin categoría');
					if (!(err && err.handled)) MJ.ui.toast('No se pudieron cargar las categorías.', 'warning');
				});

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					MJ.ui.clearErrors(form);
					const data = MJ.ui.formData(form);
					const errors = {};
					const name = String(data.name || '').trim();
					if (!name) errors.name = 'El nombre es obligatorio.';
					const priceValue = data[priceField];
					if (priceValue !== null && priceValue !== undefined && (isNaN(priceValue) || priceValue < 0)) errors[priceField] = 'Precio inválido.';
					if (data.cost !== null && data.cost !== undefined && (isNaN(data.cost) || data.cost < 0)) errors.cost = 'Costo inválido.';
					if (manage.checked && data.stock_quantity !== null && data.stock_quantity !== undefined && (isNaN(data.stock_quantity) || data.stock_quantity < 0)) errors.stock_quantity = 'Stock inválido.';
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = {
						name: name,
						sku: String(data.sku || '').trim(),
						status: data.status === 'publish' ? 'publish' : 'draft',
						manage_stock: !!manage.checked,
						stock_quantity: manage.checked ? (data.stock_quantity === null ? 0 : data.stock_quantity) : 0,
						category_ids: data.category_id ? [parseInt(data.category_id, 10)] : [],
					};
					if (priceValue !== null && priceValue !== undefined) payload[priceField] = priceValue;
					if (data.cost !== null && data.cost !== undefined) payload.cost = data.cost;

					MJ.ui.setLoading(submitBtn, true);
					MJ.api.post('products', payload).then(function (product) {
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Producto «' + (product && product.name ? product.name : name) + '» creado.', 'success');
						modal.close(product);
						if (typeof opts.onCreated === 'function') opts.onCreated(product);
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						if (err && err.handled) return;
						if (err && err.code === 'mjerp_sku_exists') MJ.ui.showErrors(form, { sku: err.message || 'Ese SKU ya existe.' });
						else MJ.ui.showErrors(form, err);
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Suppliers                                                           */
	/* ------------------------------------------------------------------ */

	const NEW_VALUE = '__new__';

	/**
	 * Fill a <select> with MJ.state.suppliers. Keeps itself in sync with 'suppliers:changed'.
	 * @param {Object} opts { select, value, allowCreate, includeEmpty=true, emptyLabel='Sin proveedor', onChange(id, supplier) }
	 * @returns {{ refresh(), destroy(), getValue(), setValue(id), getSupplier() }}
	 */
	C.supplierSelect = function (opts) {
		opts = opts || {};
		const select = el(opts.select);
		if (!select) throw new Error('supplierSelect: select requerido');
		let current = opts.value !== undefined && opts.value !== null ? String(opts.value) : '';
		let extraRow = opts.supplier || null; // a supplier row not in the active list (e.g. inactive) to keep visible

		function fill() {
			const list = (MJ.state.suppliers || []).filter(isActiveRow).slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
			const options = list.map(function (s) { return { value: s.id, label: s.name }; });
			if (current && !list.some(function (s) { return String(s.id) === current; })) {
				options.push({ value: current, label: (extraRow && extraRow.name ? extraRow.name : 'Proveedor #' + current) + ' (inactivo)' });
			}
			if (opts.allowCreate) options.push({ value: NEW_VALUE, label: '+ Nuevo proveedor' });
			MJ.ui.fillSelect(select, options, current, opts.includeEmpty === false ? undefined : (opts.emptyLabel || 'Sin proveedor'));
			if (current) select.value = current;
		}

		function onChange() {
			if (select.value === NEW_VALUE) {
				const prev = current;
				select.value = prev;
				C.supplierModal({
					onSaved(s) {
						current = String(s.id);
						extraRow = s;
						fill();
						select.value = current;
						if (typeof opts.onChange === 'function') opts.onChange(current, s);
						select.dispatchEvent(new Event('change', { bubbles: true }));
					},
				});
				return;
			}
			current = select.value;
			if (typeof opts.onChange === 'function') opts.onChange(current, api.getSupplier());
		}

		const offEvt = MJ.events.on('suppliers:changed', fill);
		select.addEventListener('change', onChange);
		fill();

		const api = {
			refresh: fill,
			getValue() { return select.value === NEW_VALUE ? current : select.value; },
			setValue(id) { current = id === null || id === undefined ? '' : String(id); fill(); },
			getSupplier() {
				const v = api.getValue();
				return (MJ.state.suppliers || []).find(function (s) { return String(s.id) === String(v); }) || (extraRow && String(extraRow.id) === String(v) ? extraRow : null);
			},
			destroy() { offEvt(); select.removeEventListener('change', onChange); },
		};
		return api;
	};

	/**
	 * Create / edit supplier modal (POST /suppliers, PUT /suppliers/{id}).
	 * @param {Object} opts { supplier?, onSaved(supplier) }
	 */
	C.supplierModal = function (opts) {
		opts = opts || {};
		const s = opts.supplier || {};
		const isEdit = !!s.id;
		const fid = 'mj-sup-' + Math.random().toString(36).slice(2, 8);
		const currencies = MJ.lists.currencies();

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-name">Nombre *</label>' +
					'<input class="mj-input" id="' + fid + '-name" name="name" type="text" required maxlength="190" value="' + h(s.name || '') + '" autofocus>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-contact">Contacto</label>' +
						'<input class="mj-input" id="' + fid + '-contact" name="contact_name" type="text" maxlength="190" value="' + h(s.contact_name || '') + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-country">País</label>' +
						'<input class="mj-input" id="' + fid + '-country" name="country" type="text" maxlength="100" value="' + h(s.country || '') + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-email">Email</label>' +
						'<input class="mj-input" id="' + fid + '-email" name="email" type="email" maxlength="190" value="' + h(s.email || '') + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-phone">Teléfono</label>' +
						'<input class="mj-input" id="' + fid + '-phone" name="phone" type="text" maxlength="60" value="' + h(s.phone || '') + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cur">Moneda habitual</label>' +
						'<select class="mj-select" id="' + fid + '-cur" name="default_currency">' +
							'<option value="">—</option>' +
							currencies.map(function (c) { return '<option value="' + h(c) + '"' + (String(s.default_currency || '').toUpperCase() === c ? ' selected' : '') + '>' + h(c) + '</option>'; }).join('') +
						'</select>' +
					'</div>' +
					(isEdit ? '<div class="mj-field"><label class="mj-field__label">&nbsp;</label><label class="mj-switch"><input type="checkbox" name="is_active"' + (isActiveRow(s) ? ' checked' : '') + '><span class="mj-switch__slider"></span><span class="mj-switch__label">Activo</span></label></div>' : '') +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
					'<textarea class="mj-textarea" id="' + fid + '-notes" name="notes" rows="3">' + h(s.notes || '') + '</textarea>' +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('check') + '<span>' + (isEdit ? 'Guardar cambios' : 'Crear proveedor') + '</span></button>';

		return MJ.ui.modal({
			title: isEdit ? 'Editar proveedor' : 'Nuevo proveedor',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					MJ.ui.clearErrors(form);
					const data = MJ.ui.formData(form);
					const errors = {};
					data.name = String(data.name || '').trim();
					if (!data.name) errors.name = 'El nombre es obligatorio.';
					if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(data.email).trim())) errors.email = 'Email inválido.';
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }
					const payload = {
						name: data.name,
						contact_name: String(data.contact_name || '').trim(),
						email: String(data.email || '').trim(),
						phone: String(data.phone || '').trim(),
						country: String(data.country || '').trim(),
						default_currency: String(data.default_currency || '').toUpperCase(),
						notes: String(data.notes || ''),
					};
					if (isEdit) payload.is_active = !!data.is_active;
					MJ.ui.setLoading(submitBtn, true);
					const req = isEdit ? MJ.api.put('suppliers/' + encodeURIComponent(s.id), payload) : MJ.api.post('suppliers', payload);
					req.then(function (saved) {
						const row = saved && saved.id ? saved : Object.assign({}, s, payload, { id: s.id });
						return MJ.refreshSuppliers().catch(function () { return null; }).then(function () {
							MJ.ui.setLoading(submitBtn, false);
							MJ.ui.toast(isEdit ? 'Proveedor actualizado.' : 'Proveedor creado.', 'success');
							modal.close(row);
							if (typeof opts.onSaved === 'function') opts.onSaved(row);
						});
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						if (err && err.handled) return;
						MJ.ui.showErrors(form, err);
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Cashboxes                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Create / edit cashbox modal (POST /cashboxes, PUT /cashboxes/{id}).
	 * @param {Object} opts { cashbox?, onSaved(cashbox) }
	 */
	C.cashboxModal = function (opts) {
		opts = opts || {};
		const c = opts.cashbox || {};
		const isEdit = !!c.id;
		const fid = 'mj-cb-' + Math.random().toString(36).slice(2, 8);
		const currencies = MJ.lists.currencies();
		const defaultCur = String(c.currency || MJ.setting('local_currency', 'ARS')).toUpperCase();

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-name">Nombre *</label>' +
					'<input class="mj-input" id="' + fid + '-name" name="name" type="text" required maxlength="190" value="' + h(c.name || '') + '" placeholder="Ej: Caja pesos, Cuenta USD" autofocus>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cur">Moneda *</label>' +
						'<select class="mj-select" id="' + fid + '-cur" name="currency">' +
							currencies.map(function (cur) { return '<option value="' + h(cur) + '"' + (cur === defaultCur ? ' selected' : '') + '>' + h(cur) + '</option>'; }).join('') +
						'</select>' +
					'</div>' +
					(isEdit ? '<div class="mj-field"><label class="mj-field__label">&nbsp;</label><label class="mj-switch"><input type="checkbox" name="is_active"' + (isActiveRow(c) ? ' checked' : '') + '><span class="mj-switch__slider"></span><span class="mj-switch__label">Activa</span></label></div>' : '') +
				'</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('check') + '<span>' + (isEdit ? 'Guardar cambios' : 'Crear caja') + '</span></button>';

		return MJ.ui.modal({
			title: isEdit ? 'Editar caja' : 'Nueva caja',
			size: 'sm',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					MJ.ui.clearErrors(form);
					const data = MJ.ui.formData(form);
					const errors = {};
					data.name = String(data.name || '').trim();
					if (!data.name) errors.name = 'El nombre es obligatorio.';
					if (!data.currency) errors.currency = 'Elegí una moneda.';
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }
					const payload = { name: data.name, currency: String(data.currency).toUpperCase() };
					if (isEdit) payload.is_active = !!data.is_active;
					MJ.ui.setLoading(submitBtn, true);
					const req = isEdit ? MJ.api.put('cashboxes/' + encodeURIComponent(c.id), payload) : MJ.api.post('cashboxes', payload);
					req.then(function (saved) {
						const row = saved && saved.id ? saved : Object.assign({}, c, payload, { id: c.id });
						return MJ.refreshCashboxes().catch(function () { return null; }).then(function () {
							MJ.ui.setLoading(submitBtn, false);
							MJ.ui.toast(isEdit ? 'Caja actualizada.' : 'Caja creada.', 'success');
							modal.close(row);
							if (typeof opts.onSaved === 'function') opts.onSaved(row);
						});
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						if (err && err.handled) return;
						MJ.ui.showErrors(form, err);
					});
				});
			},
		});
	};

	/**
	 * Fill a <select> with MJ.state.cashboxes (+ "Sin caja"). Syncs with 'cashboxes:changed'.
	 * @param {Object} opts { select, value, allowCreate, emptyLabel='Sin caja', onChange(id, cashbox) }
	 */
	C.cashboxSelect = function (opts) {
		opts = opts || {};
		const select = el(opts.select);
		if (!select) throw new Error('cashboxSelect: select requerido');
		let current = opts.value !== undefined && opts.value !== null && String(opts.value) !== '0' ? String(opts.value) : '';
		let extraRow = opts.cashbox || null;

		function fill() {
			const list = (MJ.state.cashboxes || []).filter(isActiveRow);
			const options = list.map(function (c) { return { value: c.id, label: c.name + (c.currency ? ' (' + c.currency + ')' : '') }; });
			if (current && !list.some(function (c) { return String(c.id) === current; })) {
				options.push({ value: current, label: (extraRow && extraRow.name ? extraRow.name : 'Caja #' + current) + ' (inactiva)' });
			}
			if (opts.allowCreate) options.push({ value: NEW_VALUE, label: '+ Nueva caja' });
			MJ.ui.fillSelect(select, options, current, opts.emptyLabel || 'Sin caja');
			if (current) select.value = current;
		}

		function onChange() {
			if (select.value === NEW_VALUE) {
				select.value = current;
				C.cashboxModal({
					onSaved(c) {
						current = String(c.id);
						extraRow = c;
						fill();
						select.value = current;
						if (typeof opts.onChange === 'function') opts.onChange(current, c);
						select.dispatchEvent(new Event('change', { bubbles: true }));
					},
				});
				return;
			}
			current = select.value;
			if (typeof opts.onChange === 'function') opts.onChange(current, api.getCashbox());
		}

		const offEvt = MJ.events.on('cashboxes:changed', fill);
		select.addEventListener('change', onChange);
		fill();

		const api = {
			refresh: fill,
			getValue() { return select.value === NEW_VALUE ? current : select.value; },
			setValue(id) { current = id === null || id === undefined || String(id) === '0' ? '' : String(id); fill(); },
			getCashbox() {
				const v = api.getValue();
				return (MJ.state.cashboxes || []).find(function (c) { return String(c.id) === String(v); }) || null;
			},
			destroy() { offEvt(); select.removeEventListener('change', onChange); },
		};
		return api;
	};

	/* ------------------------------------------------------------------ */
	/* Pagination                                                          */
	/* ------------------------------------------------------------------ */

	function pageWindow(page, pages) {
		const out = [];
		if (pages <= 7) { for (let i = 1; i <= pages; i++) out.push(i); return out; }
		const set = {};
		[1, 2, page - 1, page, page + 1, pages - 1, pages].forEach(function (p) { if (p >= 1 && p <= pages) set[p] = true; });
		const nums = Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
		let prev = 0;
		nums.forEach(function (n) {
			if (prev && n - prev > 1) out.push('…');
			out.push(n);
			prev = n;
		});
		return out;
	}

	/**
	 * Build pagination markup. Returns an object usable as a string (toString → html) with .html and .bind(el).
	 * @param {Object} opts { page, pages, total, perPage?, onChange(page)?, label? }
	 */
	C.pagination = function (opts) {
		opts = opts || {};
		const page = Math.max(1, parseInt(opts.page, 10) || 1);
		const pages = Math.max(1, parseInt(opts.pages, 10) || 1);
		const total = opts.total === undefined || opts.total === null ? null : (parseInt(opts.total, 10) || 0);
		let info = '';
		if (total !== null) {
			info = total === 0 ? 'Sin resultados' : (MJ.fmt.int(total) + (total === 1 ? ' resultado' : ' resultados') + (pages > 1 ? ' · página ' + page + ' de ' + pages : ''));
		} else if (pages > 1) {
			info = 'Página ' + page + ' de ' + pages;
		}
		let html = '<div class="mj-pagination" data-page-current="' + page + '" data-pages="' + pages + '">';
		html += '<div class="mj-pagination__info">' + h(info) + '</div>';
		if (pages > 1) {
			html += '<div class="mj-pagination__pages">';
			html += '<button type="button" class="mj-pagination__btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + ' aria-label="Anterior">' + MJ.icon('chevron-left') + '</button>';
			pageWindow(page, pages).forEach(function (p) {
				if (p === '…') html += '<span class="mj-pagination__ellipsis">…</span>';
				else html += '<button type="button" class="mj-pagination__btn' + (p === page ? ' is-active' : '') + '" data-page="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
			});
			html += '<button type="button" class="mj-pagination__btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + ' aria-label="Siguiente">' + MJ.icon('chevron-right') + '</button>';
			html += '</div>';
		}
		html += '</div>';
		const result = {
			html: html,
			page: page,
			pages: pages,
			total: total,
			bind(root) { return C.bindPagination(root, opts.onChange); },
			toString() { return html; },
		};
		return result;
	};

	/**
	 * Delegated click binder for pagination buttons inside `root`.
	 * @returns {Function} unbind
	 */
	C.bindPagination = function (root, onChange) {
		root = el(root);
		if (!root || typeof onChange !== 'function') return function () {};
		return MJ.ui.on(root, 'click', '.mj-pagination__btn[data-page]', function (e, btn) {
			e.preventDefault();
			if (btn.disabled || btn.classList.contains('is-active')) return;
			const p = parseInt(btn.getAttribute('data-page'), 10);
			const wrap = btn.closest('.mj-pagination');
			const max = wrap ? parseInt(wrap.getAttribute('data-pages'), 10) || 1 : Infinity;
			if (p >= 1 && p <= max) onChange(p);
		});
	};

	/* ------------------------------------------------------------------ */
	/* CSV / TSV import                                                    */
	/* ------------------------------------------------------------------ */

	const COLS = [
		{ key: '', label: 'Ignorar' },
		{ key: 'sku', label: 'SKU' },
		{ key: 'name', label: 'Nombre' },
		{ key: 'quantity', label: 'Cantidad' },
		{ key: 'unit_cost', label: 'Costo unit.' },
		{ key: 'currency', label: 'Moneda' },
		{ key: 'rate', label: 'TC' },
	];

	const HEADER_PATTERNS = {
		sku: /^(sku|c[oó]d(igo)?|code|ref(erencia)?|art[ií]culo\s*n|item\s*code)/i,
		name: /(nombre|producto|descripci[oó]n|description|name|item|art[ií]culo|detalle|title|t[ií]tulo)/i,
		quantity: /(cantidad|^cant\.?$|^cant|^qty|quantity|unidades|^u\.?$|^q$|^cnt)/i,
		unit_cost: /(costo|cost|precio|price|unitario|valor|fob|importe|monto)/i,
		currency: /(moneda|currency|divisa|^cur$|^ccy$)/i,
		rate: /(^tc$|t\.?c\.?|tipo\s*de\s*cambio|cotizaci[oó]n|^rate$|exchange|cambio|d[oó]lar)/i,
	};

	function detectDelimiter(text) {
		const lines = text.split(/\r\n|\n|\r/).filter(function (l) { return l.trim() !== ''; }).slice(0, 10);
		const counts = { '\t': 0, ';': 0, ',': 0, '|': 0 };
		lines.forEach(function (l) {
			Object.keys(counts).forEach(function (d) { counts[d] += l.split(d).length - 1; });
		});
		if (counts['\t'] > 0) return '\t';
		if (counts[';'] >= counts[','] && counts[';'] > 0) return ';';
		if (counts[','] > 0) return ',';
		if (counts['|'] > 0) return '|';
		return '\t';
	}

	function parseDelimited(text, delimiter) {
		const rows = [];
		let row = [];
		let field = '';
		let inQuotes = false;
		const s = String(text || '').replace(new RegExp('^' + String.fromCharCode(0xFEFF)), '');
		for (let i = 0; i < s.length; i++) {
			const ch = s[i];
			if (inQuotes) {
				if (ch === '"') {
					if (s[i + 1] === '"') { field += '"'; i++; }
					else inQuotes = false;
				} else {
					field += ch;
				}
				continue;
			}
			if (ch === '"' && field === '') { inQuotes = true; continue; }
			if (ch === delimiter) { row.push(field); field = ''; continue; }
			if (ch === '\r') { if (s[i + 1] === '\n') i++; row.push(field); rows.push(row); row = []; field = ''; continue; }
			if (ch === '\n') { row.push(field); rows.push(row); row = []; field = ''; continue; }
			field += ch;
		}
		if (field !== '' || row.length) { row.push(field); rows.push(row); }
		return rows.map(function (r) { return r.map(function (c) { return String(c).trim(); }); })
			.filter(function (r) { return r.some(function (c) { return c !== ''; }); });
	}

	function looksNumeric(v) {
		if (v === null || v === undefined) return false;
		const s = String(v).trim();
		if (!s) return false;
		return /^[-+]?[\d.,\s]*\d[\d.,\s]*$/.test(s) && !isNaN(MJ.fmt.parse(s));
	}

	function detectHeader(row) {
		if (!row || !row.length) return false;
		let matches = 0;
		let numeric = 0;
		row.forEach(function (cell) {
			if (looksNumeric(cell)) { numeric++; return; }
			const c = String(cell).trim();
			if (!c) return;
			for (const k in HEADER_PATTERNS) if (HEADER_PATTERNS[k].test(c)) { matches++; break; }
		});
		return matches >= 1 && numeric === 0;
	}

	function mapFromHeader(row) {
		const map = {};
		const used = {};
		const order = ['sku', 'currency', 'rate', 'quantity', 'unit_cost', 'name'];
		row.forEach(function (cell, idx) {
			const c = String(cell).trim();
			if (!c) return;
			for (let i = 0; i < order.length; i++) {
				const k = order[i];
				if (!used[k] && HEADER_PATTERNS[k].test(c)) { map[idx] = k; used[k] = true; return; }
			}
		});
		return map;
	}

	function guessMap(rows) {
		const map = {};
		if (!rows.length) return map;
		const width = Math.max.apply(null, rows.map(function (r) { return r.length; }));
		const numericCols = [];
		const textCols = [];
		const currencyCols = [];
		const currencies = MJ.lists.currencies();
		for (let c = 0; c < width; c++) {
			const vals = rows.map(function (r) { return r[c] === undefined ? '' : String(r[c]).trim(); }).filter(function (v) { return v !== ''; });
			if (!vals.length) continue;
			const nums = vals.filter(looksNumeric).length;
			const curs = vals.filter(function (v) { return currencies.indexOf(v.toUpperCase()) >= 0; }).length;
			if (curs === vals.length) currencyCols.push(c);
			else if (nums / vals.length >= 0.8) numericCols.push(c);
			else textCols.push(c);
		}
		if (currencyCols.length) map[currencyCols[0]] = 'currency';
		if (textCols.length >= 2) { map[textCols[0]] = 'sku'; map[textCols[1]] = 'name'; }
		else if (textCols.length === 1) map[textCols[0]] = 'name';
		// Numeric columns: [sku?] quantity, unit_cost, rate — a leading numeric column with integer-looking values and 3+ numeric cols → sku.
		let nc = numericCols.slice();
		if (nc.length >= 4 && textCols.length === 0) { map[nc[0]] = 'sku'; nc = nc.slice(1); }
		if (nc[0] !== undefined) map[nc[0]] = 'quantity';
		if (nc[1] !== undefined) map[nc[1]] = 'unit_cost';
		if (nc[2] !== undefined) map[nc[2]] = 'rate';
		return map;
	}

	/**
	 * Spreadsheet import modal. Calls onImport(rows, { match_by, create_missing, default_currency, default_rate }).
	 * `onImport` may return a Promise: the modal waits and closes on success.
	 * @param {Object} opts { onImport, defaultCurrency?, defaultRate?, title? }
	 */
	C.csvImportModal = function (opts) {
		opts = opts || {};
		const currencies = MJ.lists.currencies();
		const base = String(MJ.setting('base_currency', 'USD')).toUpperCase();
		const defaultCurrency = String(opts.defaultCurrency || base).toUpperCase();
		const defaultRate = opts.defaultRate !== undefined && opts.defaultRate !== null ? opts.defaultRate : MJ.setting('default_rate', 1);
		const fid = 'mj-csv-' + Math.random().toString(36).slice(2, 8);

		let rawRows = [];
		let hasHeader = false;
		let header = [];
		let colMap = {};
		let parsed = { valid: [], errors: 0, total: 0 };

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-alert mj-alert--info">' + MJ.icon('info') + '<div class="mj-alert__body">Pegá las filas desde Excel o Google Sheets (o cargá un archivo .csv/.txt). Columnas reconocidas: <strong>SKU, Nombre, Cantidad, Costo, Moneda, TC</strong>. La fila de encabezado se detecta sola; podés ajustar el mapeo de columnas en la vista previa.</div></div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field" style="flex:2">' +
						'<label class="mj-field__label" for="' + fid + '-text">Datos</label>' +
						'<textarea class="mj-textarea mj-mono" id="' + fid + '-text" name="text" rows="7" placeholder="SKU\tNombre\tCantidad\tCosto\tMoneda\tTC&#10;ELF-CF\tElfbar 40K Cherry Fuse\t10\t9,50\tUSD\t1000" spellcheck="false"></textarea>' +
					'</div>' +
					'<div class="mj-field" style="flex:1">' +
						'<label class="mj-field__label" for="' + fid + '-file">Archivo</label>' +
						'<input class="mj-input" id="' + fid + '-file" type="file" accept=".csv,.txt,.tsv,text/csv,text/plain,text/tab-separated-values">' +
						'<div class="mj-field__hint">CSV o TSV (UTF-8).</div>' +
						'<div class="mj-mt"><span class="mj-small mj-muted" data-detect></span></div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-match">Vincular productos por</label>' +
						'<select class="mj-select" id="' + fid + '-match" name="match_by">' +
							'<option value="both" selected>SKU y luego nombre</option>' +
							'<option value="sku">Solo SKU</option>' +
							'<option value="name">Solo nombre</option>' +
						'</select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-cur">Moneda por defecto</label>' +
						'<select class="mj-select" id="' + fid + '-cur" name="default_currency">' +
							currencies.map(function (c) { return '<option value="' + h(c) + '"' + (c === defaultCurrency ? ' selected' : '') + '>' + h(c) + '</option>'; }).join('') +
						'</select>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-rate">TC por defecto</label>' +
						'<input class="mj-input" id="' + fid + '-rate" name="default_rate" type="text" inputmode="decimal" data-type="number" value="' + h(MJ.fmt.rate(defaultRate)) + '">' +
					'</div>' +
					'<div class="mj-field mj-field--check">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<label class="mj-checkbox"><input type="checkbox" name="create_missing"> <span>Crear productos que no existan</span></label>' +
					'</div>' +
				'</div>' +
				'<div data-preview></div>' +
			'</form>';

		const footer =
			'<span class="mj-small mj-muted mj-ml-auto" data-summary></span>' +
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit disabled>' + MJ.icon('upload') + '<span>Importar</span></button>';

		return MJ.ui.modal({
			title: opts.title || 'Importar planilla de ítems',
			size: 'lg',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const textarea = qs('[name="text"]', form);
				const fileInput = qs('#' + fid + '-file', form);
				const preview = qs('[data-preview]', form);
				const detectEl = qs('[data-detect]', form);
				const summaryEl = qs('[data-summary]', root);
				const submitBtn = qs('[data-submit]', root);
				const curSelect = qs('[name="default_currency"]', form);
				const rateInput = qs('[name="default_rate"]', form);

				function currentDefaults() {
					const rate = MJ.fmt.parse(rateInput.value);
					return { currency: String(curSelect.value || defaultCurrency).toUpperCase(), rate: isNaN(rate) ? NaN : rate };
				}

				function buildRows() {
					const defs = currentDefaults();
					const dataRows = hasHeader ? rawRows.slice(1) : rawRows;
					const idxOf = {};
					Object.keys(colMap).forEach(function (i) { if (colMap[i]) idxOf[colMap[i]] = parseInt(i, 10); });
					const valid = [];
					const flagged = [];
					dataRows.forEach(function (cells, i) {
						const get = function (k) { return idxOf[k] === undefined ? '' : (cells[idxOf[k]] === undefined ? '' : String(cells[idxOf[k]]).trim()); };
						const row = { sku: get('sku'), name: get('name') };
						const problems = [];
						const qtyRaw = get('quantity');
						const costRaw = get('unit_cost');
						const curRaw = get('currency').toUpperCase();
						const rateRaw = get('rate');
						row.quantity = qtyRaw === '' ? 1 : MJ.fmt.parse(qtyRaw);
						row.unit_cost = costRaw === '' ? 0 : MJ.fmt.parse(costRaw);
						row.currency = curRaw || defs.currency;
						row.rate = rateRaw === '' ? defs.rate : MJ.fmt.parse(rateRaw);
						if (!row.sku && !row.name) problems.push('Sin SKU ni nombre');
						if (idxOf.quantity === undefined) problems.push('Falta columna Cantidad');
						else if (isNaN(row.quantity) || row.quantity <= 0) problems.push('Cantidad inválida');
						if (idxOf.unit_cost === undefined) problems.push('Falta columna Costo');
						else if (isNaN(row.unit_cost) || row.unit_cost < 0) problems.push('Costo inválido');
						if (currencies.indexOf(row.currency) === -1) problems.push('Moneda «' + row.currency + '» no permitida');
						if (isNaN(row.rate) || row.rate <= 0) problems.push('TC inválido');
						flagged.push({ index: i, cells: cells, row: row, problems: problems });
						if (!problems.length) valid.push(row);
					});
					parsed = { valid: valid, flagged: flagged, errors: flagged.length - valid.length, total: flagged.length };
				}

				function renderPreview() {
					if (!rawRows.length) {
						preview.innerHTML = '';
						summaryEl.textContent = '';
						submitBtn.disabled = true;
						return;
					}
					buildRows();
					const width = Math.max.apply(null, rawRows.map(function (r) { return r.length; }));
					let html = '<div class="mj-row mj-row--between mj-row--wrap mj-mt"><div class="mj-strong">Vista previa</div>' +
						'<label class="mj-checkbox"><input type="checkbox" data-has-header' + (hasHeader ? ' checked' : '') + '> <span>La primera fila es encabezado</span></label></div>';
					html += '<div class="mj-table-wrap mj-mt"><table class="mj-table mj-table--compact"><thead><tr><th>#</th>';
					for (let c = 0; c < width; c++) {
						html += '<th><select class="mj-select mj-select--sm" data-col="' + c + '">' +
							COLS.map(function (col) { return '<option value="' + h(col.key) + '"' + ((colMap[c] || '') === col.key ? ' selected' : '') + '>' + h(col.label) + '</option>'; }).join('') +
							'</select>' + (hasHeader && header[c] ? '<div class="mj-small mj-muted mj-truncate">' + h(header[c]) + '</div>' : '') + '</th>';
					}
					html += '<th>Estado</th></tr></thead><tbody>';
					const maxRows = 40;
					parsed.flagged.slice(0, maxRows).forEach(function (f) {
						html += '<tr class="' + (f.problems.length ? 'is-error' : '') + '"><td class="mj-muted">' + (f.index + 1) + '</td>';
						for (let c = 0; c < width; c++) html += '<td class="mj-truncate" title="' + h(f.cells[c] || '') + '">' + h(f.cells[c] || '') + '</td>';
						html += '<td>' + (f.problems.length ? '<span class="mj-badge mj-badge--danger" title="' + h(f.problems.join(' · ')) + '">' + h(f.problems[0]) + (f.problems.length > 1 ? ' +' + (f.problems.length - 1) : '') + '</span>' : '<span class="mj-badge mj-badge--success">OK</span>') + '</td></tr>';
					});
					html += '</tbody></table></div>';
					if (parsed.flagged.length > maxRows) html += '<div class="mj-small mj-muted mj-mt">Mostrando ' + maxRows + ' de ' + parsed.flagged.length + ' filas.</div>';
					preview.innerHTML = html;
					summaryEl.textContent = parsed.valid.length + ' fila' + (parsed.valid.length === 1 ? '' : 's') + ' lista' + (parsed.valid.length === 1 ? '' : 's') + (parsed.errors ? ' · ' + parsed.errors + ' con errores (se omiten)' : '');
					submitBtn.disabled = parsed.valid.length === 0;
				}

				function analyze(text) {
					const delimiter = detectDelimiter(text);
					rawRows = parseDelimited(text, delimiter);
					hasHeader = rawRows.length ? detectHeader(rawRows[0]) : false;
					header = hasHeader ? rawRows[0] : [];
					colMap = hasHeader ? mapFromHeader(rawRows[0]) : guessMap(rawRows);
					if (hasHeader && !Object.keys(colMap).length) colMap = guessMap(rawRows.slice(1));
					const delimLabel = delimiter === '\t' ? 'tabulaciones (TSV)' : (delimiter === ';' ? 'punto y coma' : (delimiter === ',' ? 'comas (CSV)' : 'barras'));
					detectEl.textContent = rawRows.length ? 'Detectado: ' + delimLabel + ' · ' + (hasHeader ? 'con encabezado' : 'sin encabezado') + ' · ' + (rawRows.length - (hasHeader ? 1 : 0)) + ' filas' : '';
					renderPreview();
				}

				const onText = MJ.ui.debounce(function () { analyze(textarea.value); }, 300);
				textarea.addEventListener('input', onText);
				textarea.addEventListener('paste', function () { setTimeout(function () { analyze(textarea.value); }, 0); });

				fileInput.addEventListener('change', function () {
					const file = fileInput.files && fileInput.files[0];
					if (!file) return;
					if (file.size > 2 * 1024 * 1024) { MJ.ui.toast('El archivo supera los 2 MB.', 'error'); fileInput.value = ''; return; }
					const reader = new FileReader();
					reader.onload = function () { textarea.value = String(reader.result || ''); analyze(textarea.value); };
					reader.onerror = function () { MJ.ui.toast('No se pudo leer el archivo.', 'error'); };
					reader.readAsText(file, 'UTF-8');
				});

				curSelect.addEventListener('change', function () { if (rawRows.length) renderPreview(); });
				rateInput.addEventListener('input', MJ.ui.debounce(function () { if (rawRows.length) renderPreview(); }, 250));

				MJ.ui.on(preview, 'change', '[data-col]', function (e, sel) {
					const c = parseInt(sel.getAttribute('data-col'), 10);
					const key = sel.value;
					if (key) Object.keys(colMap).forEach(function (i) { if (colMap[i] === key && parseInt(i, 10) !== c) delete colMap[i]; });
					if (key) colMap[c] = key; else delete colMap[c];
					renderPreview();
				});
				MJ.ui.on(preview, 'change', '[data-has-header]', function (e, cb) {
					hasHeader = cb.checked;
					header = hasHeader ? rawRows[0] : [];
					if (hasHeader) { const m = mapFromHeader(rawRows[0]); if (Object.keys(m).length) colMap = m; }
					else colMap = guessMap(rawRows);
					renderPreview();
				});

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					MJ.ui.clearErrors(form);
					if (!rawRows.length) { MJ.ui.showErrors(form, 'Pegá o cargá datos para importar.'); return; }
					const defs = currentDefaults();
					if (isNaN(defs.rate) || defs.rate <= 0) { MJ.ui.showErrors(form, { default_rate: 'Ingresá un TC válido.' }); return; }
					buildRows();
					if (!parsed.valid.length) { MJ.ui.showErrors(form, 'No hay filas válidas para importar. Revisá el mapeo de columnas.'); return; }
					const data = MJ.ui.formData(form);
					const options = {
						match_by: ['sku', 'name', 'both'].indexOf(data.match_by) >= 0 ? data.match_by : 'both',
						create_missing: !!data.create_missing,
						default_currency: defs.currency,
						default_rate: defs.rate,
					};
					const rows = parsed.valid.map(function (r) {
						return { sku: r.sku, name: r.name, quantity: r.quantity, unit_cost: r.unit_cost, currency: r.currency, rate: r.rate };
					});
					if (typeof opts.onImport !== 'function') { modal.close({ rows: rows, options: options }); return; }
					MJ.ui.setLoading(submitBtn, true);
					let result;
					try { result = opts.onImport(rows, options); } catch (err) { MJ.ui.setLoading(submitBtn, false); MJ.ui.showErrors(form, MJ.api.message(err)); return; }
					Promise.resolve(result).then(function () {
						MJ.ui.setLoading(submitBtn, false);
						modal.close({ rows: rows, options: options });
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						if (err && err.handled) return;
						MJ.ui.showErrors(form, MJ.api.message(err, 'No se pudo importar.'));
					});
				});
			},
		});
	};

	/* ------------------------------------------------------------------ */
	/* Simple selects                                                      */
	/* ------------------------------------------------------------------ */

	function normalizeSelectArgs(a, b, c) {
		if (a && typeof a === 'object' && !(a instanceof Element) && (a.select !== undefined)) return Object.assign({}, a);
		return Object.assign({ select: a, value: b }, c || {});
	}

	/**
	 * statusSelect(select, value, { includeAll, allLabel, only: [keys] }) or statusSelect({ select, value, ... }).
	 */
	C.statusSelect = function (a, b, c) {
		const o = normalizeSelectArgs(a, b, c);
		const select = el(o.select);
		if (!select) return null;
		const statuses = MJ.state.statuses || {};
		const keys = Array.isArray(o.only) && o.only.length ? o.only : Object.keys(statuses);
		const options = keys.map(function (k) { return { value: k, label: MJ.ui.statusLabel(k) }; });
		MJ.ui.fillSelect(select, options, o.value === undefined || o.value === null ? '' : String(o.value), o.includeAll ? (o.allLabel || 'Todos los estados') : (o.placeholder !== undefined ? o.placeholder : undefined));
		return select;
	};

	/**
	 * priceListSelect(select, value, { includeEmpty, emptyLabel }) — options "Público (+70%)".
	 */
	C.priceListSelect = function (a, b, c) {
		const o = normalizeSelectArgs(a, b, c);
		const select = el(o.select);
		if (!select) return null;
		const lists = MJ.lists.priceLists();
		const options = lists.map(function (l) { return { value: l.key, label: l.label + ' (+' + MJ.fmt.rate(l.markup) + '%)' }; });
		let value = o.value === undefined || o.value === null || o.value === '' ? '' : String(o.value);
		if (!value && !o.includeEmpty && lists.length) value = MJ.setting('price_list_for_price', '') && lists.some(function (l) { return l.key === MJ.setting('price_list_for_price', ''); }) ? MJ.setting('price_list_for_price', '') : lists[0].key;
		MJ.ui.fillSelect(select, options, value, o.includeEmpty ? (o.emptyLabel || 'Sin lista') : undefined);
		return select;
	};

	/**
	 * currencySelect(select, value, { includeEmpty, emptyLabel }) — options from settings.currencies.
	 */
	C.currencySelect = function (a, b, c) {
		const o = normalizeSelectArgs(a, b, c);
		const select = el(o.select);
		if (!select) return null;
		const list = MJ.lists.currencies();
		const options = list.map(function (cur) { return { value: cur, label: cur + (MJ.fmt.symbol(cur) !== cur ? ' (' + MJ.fmt.symbol(cur) + ')' : '') }; });
		const value = o.value ? String(o.value).toUpperCase() : (o.includeEmpty ? '' : (list[0] || ''));
		MJ.ui.fillSelect(select, options, value, o.includeEmpty ? (o.emptyLabel || '—') : undefined);
		return select;
	};

	/**
	 * expenseTypeSelect(select, value) — options from settings.expense_types.
	 */
	C.expenseTypeSelect = function (a, b, c) {
		const o = normalizeSelectArgs(a, b, c);
		const select = el(o.select);
		if (!select) return null;
		const types = MJ.lists.expenseTypes();
		const options = types.map(function (t) { return { value: t.key, label: t.label || t.key }; });
		const value = o.value ? String(o.value) : (o.includeEmpty ? '' : (types[0] ? types[0].key : ''));
		MJ.ui.fillSelect(select, options, value, o.includeEmpty ? (o.emptyLabel || 'Todos los tipos') : undefined);
		return select;
	};

	/**
	 * destinationSelect(select, value, { includeEmpty }) — options from settings.destinations; unknown value is added.
	 */
	C.destinationSelect = function (a, b, c) {
		const o = normalizeSelectArgs(a, b, c);
		const select = el(o.select);
		if (!select) return null;
		const list = MJ.lists.destinations();
		const options = list.map(function (d) { return { value: d, label: d }; });
		const value = o.value ? String(o.value) : '';
		if (value && list.indexOf(value) === -1) options.push({ value: value, label: value });
		MJ.ui.fillSelect(select, options, value, o.includeEmpty === false ? undefined : (o.emptyLabel || 'Sin destino'));
		return select;
	};

	/* ------------------------------------------------------------------ */
	/* bulkAddModal — alta masiva de productos                             */
	/* ------------------------------------------------------------------ */

	/** Clave estable de una fila: product_id + ':' + variation_id. */
	function bulkRowKey(productId, variationId) {
		return String(parseInt(productId, 10) || 0) + ':' + String(parseInt(variationId, 10) || 0);
	}

	/** Separador decimal del locale configurado (es-AR → ","). */
	function bulkDecimalSep() {
		const s = MJ.fmt.number(1.5, 1);
		const m = String(s === undefined || s === null ? '' : s).match(/[.,]/);
		return m ? m[0] : ',';
	}

	/** Número listo para un <input type="text"> (sin miles, con la coma local). */
	function bulkNumStr(value, decimals) {
		const n = MJ.fmt.toNumber(value);
		if (n === null || !isFinite(n)) return '';
		const d = (decimals === undefined || decimals === null) ? 4 : Math.max(0, decimals);
		let s = Number(n).toFixed(d);
		if (s.indexOf('.') >= 0) s = s.replace(/0+$/, '').replace(/\.$/, '');
		return s.replace('.', bulkDecimalSep());
	}

	/** 'variation' | 'variable' | 'simple' a partir del product summary. */
	function bulkProductKind(p) {
		if (!p) return 'simple';
		if (p.is_variation || p.type === 'variation' || (parseInt(p.variation_id, 10) || 0) > 0) return 'variation';
		if (p.type === 'variable') return 'variable';
		return 'simple';
	}

	/** Atributos de una variación ("Cherry Fuse") a partir de name / parent_name. */
	function bulkVariationAttrs(p) {
		const name = String((p && p.name) || '');
		const parent = String((p && p.parent_name) || '');
		if (parent && name.length > parent.length && name.slice(0, parent.length) === parent) {
			return name.slice(parent.length).replace(/^[\s\-–—·]+/, '').trim();
		}
		return '';
	}

	/**
	 * Modal de alta masiva de ítems de compra.
	 *
	 * Flujo: buscar (o recorrer el catálogo) → tildar varios productos → cargar una
	 * "configuración común" (cantidad / costo / moneda / TC) → copiarla a las filas
	 * tildadas o a todas → editar a mano lo que haga falta → agregar todo de una.
	 *
	 * @param {Object}   opts             Opciones.
	 * @param {Object}   opts.purchase    Compra completa (base_currency, local_currency, default_rate).
	 * @param {Function} opts.onSubmit    onSubmit(rows) → puede devolver una Promise; el modal
	 *                                    espera, cierra al resolver y queda abierto al rechazar.
	 *                                    rows: [{ product_id, variation_id, name, sku, quantity,
	 *                                    unit_cost, currency, rate }].
	 * @param {string}   [opts.title]     Título del modal.
	 * @returns {Object} La API del modal (MJ.ui.modal).
	 */
	C.bulkAddModal = function (opts) {
		opts = opts || {};
		const purchase = opts.purchase || {};
		const base = String(purchase.base_currency || MJ.setting('base_currency', 'USD')).toUpperCase();
		const currencies = MJ.lists.currencies();
		const purchaseRate = MJ.fmt.toNumber(purchase.default_rate);
		const settingRate = MJ.fmt.toNumber(MJ.setting('default_rate', 1));
		const defaultRate = (purchaseRate !== null && purchaseRate > 0) ? purchaseRate : ((settingRate !== null && settingRate > 0) ? settingRate : 1);
		const fid = 'mj-bulk-' + Math.random().toString(36).slice(2, 8);
		const PER_PAGE = 20;
		const SEARCH_LIMIT = 30;
		const MIN_CHARS = 2;

		/* --- estado ---------------------------------------------------- */
		const rows = [];                 // orden de la tabla
		const rowMap = Object.create(null); // key → fila (selección acumulada)
		let mode = 'search';             // 'search' | 'catalog'
		let term = '';
		let page = 1;
		let results = [];
		let meta = { total: 0, pages: 1, page: 1 };
		let loading = false;
		let loadError = '';
		let seq = 0;
		let submitting = false;
		let destroyed = false;

		/* --- refs (se completan en onMount) ---------------------------- */
		let rootEl = null;
		let qInput = null;
		let pickAllEl = null;
		let resultsEl = null;
		let resultsInfoEl = null;
		let pagerEl = null;
		let rowsEl = null;
		let countEl = null;
		let errorEl = null;
		let summaryEl = null;
		let submitBtn = null;
		let submitLabelEl = null;
		let cfgQty = null;
		let cfgCost = null;
		let cfgCur = null;
		let cfgRate = null;

		const offs = [];
		let tabsApi = null;
		let modalApi = null;

		/* --- helpers de cálculo ---------------------------------------- */

		function rowBase(r) {
			const qty = MJ.fmt.toNumber(r.quantity);
			const cost = MJ.fmt.toNumber(r.unit_cost);
			if (qty === null || cost === null) return 0;
			let unit;
			try { unit = MJ.calc.toBase(cost, r.currency, r.rate, base); } catch (e) { unit = 0; }
			const total = qty * (isFinite(unit) ? unit : 0);
			return isFinite(total) ? total : 0;
		}

		function totalBase() {
			return rows.reduce(function (acc, r) { return acc + rowBase(r); }, 0);
		}

		function checkedRows() {
			return rows.filter(function (r) { return r.checked; });
		}

		function suggestedCost(p, currency) {
			const cur = String(currency || base).toUpperCase();
			const costCur = String((p && p.cost_currency) || base).toUpperCase();
			const c = MJ.fmt.toNumber(p ? p.cost_base : null);
			return (c !== null && c > 0 && cur === costCur) ? c : 0;
		}

		/** Lee la barra de configuración común. Campos vacíos → null (no se copian). */
		function readConfig() {
			const out = { quantity: null, unit_cost: null, currency: '', rate: null };
			if (!cfgQty) return out;
			const q = MJ.fmt.parse(cfgQty.value);
			if (String(cfgQty.value).trim() !== '' && !isNaN(q)) out.quantity = q;
			const c = MJ.fmt.parse(cfgCost.value);
			if (String(cfgCost.value).trim() !== '' && !isNaN(c)) out.unit_cost = c;
			out.currency = String(cfgCur.value || '').toUpperCase();
			const r = MJ.fmt.parse(cfgRate.value);
			if (String(cfgRate.value).trim() !== '' && !isNaN(r)) out.rate = r;
			return out;
		}

		/* --- mensajes -------------------------------------------------- */

		function showError(message) {
			if (!errorEl) return;
			errorEl.innerHTML = MJ.ui.alert(message, 'danger');
			if (errorEl.scrollIntoView) errorEl.scrollIntoView({ block: 'nearest' });
		}

		function clearError() {
			if (errorEl) errorEl.innerHTML = '';
		}

		/* --- filas elegidas -------------------------------------------- */

		function addProduct(p) {
			if (!p || bulkProductKind(p) === 'variable') return null;
			const productId = parseInt((p.product_id === undefined || p.product_id === null) ? p.id : p.product_id, 10) || 0;
			const variationId = parseInt(p.variation_id, 10) || 0;
			if (!productId) return null;
			const key = bulkRowKey(productId, variationId);
			if (rowMap[key]) return rowMap[key];

			const cfg = readConfig();
			const currency = cfg.currency || base;
			const sub = [];
			if (p.sku) sub.push('SKU ' + p.sku);
			const attrs = bulkProductKind(p) === 'variation' ? bulkVariationAttrs(p) : '';
			if (attrs) sub.push('Variación: ' + attrs);
			if (p.status && p.status !== 'publish') sub.push(statusText(p.status));

			const row = {
				key: key,
				product_id: productId,
				variation_id: variationId,
				name: String(p.name || 'Sin nombre'),
				sku: String(p.sku || ''),
				sub: sub.join(' · '),
				quantity: cfg.quantity !== null ? cfg.quantity : 1,
				unit_cost: cfg.unit_cost !== null ? cfg.unit_cost : suggestedCost(p, currency),
				currency: currency,
				rate: cfg.rate !== null ? cfg.rate : defaultRate,
				checked: true,
				invalid: { quantity: false, unit_cost: false, rate: false },
			};
			rowMap[key] = row;
			rows.push(row);
			return row;
		}

		function removeRow(key) {
			if (!rowMap[key]) return;
			delete rowMap[key];
			for (let i = 0; i < rows.length; i++) {
				if (rows[i].key === key) { rows.splice(i, 1); break; }
			}
		}

		/* --- resultados del buscador / catálogo ------------------------ */

		function resultRowHtml(p, idx) {
			const kind = bulkProductKind(p);
			const isVariable = kind === 'variable';
			const productId = parseInt((p.product_id === undefined || p.product_id === null) ? p.id : p.product_id, 10) || 0;
			const variationId = parseInt(p.variation_id, 10) || 0;
			const key = bulkRowKey(productId, variationId);
			const picked = !isVariable && !!rowMap[key];
			const attrs = kind === 'variation' ? bulkVariationAttrs(p) : '';

			const metaParts = [];
			if (p.sku) metaParts.push('SKU ' + p.sku);
			metaParts.push(stockText(p));
			if (p.status && p.status !== 'publish') metaParts.push(statusText(p.status));

			let nameHtml = '<div class="mj-strong">' + h(p.name || 'Sin nombre') + '</div>';
			if (kind === 'variation') {
				nameHtml += '<div class="mj-row mj-row--wrap"><span class="mj-tag">' + MJ.icon('layers') + '<span>' + h(attrs || 'Variación') + '</span></span></div>';
			}
			nameHtml += '<div class="mj-table__sub mj-small mj-muted">' + h(metaParts.join(' · ')) + '</div>';
			if (isVariable) {
				nameHtml += '<div class="mj-small mj-warning-text">Producto variable: elegí una variación de la lista.</div>';
			}

			return '<tr class="' + (isVariable ? 'is-disabled' : (picked ? 'is-picked' : '')) + '">' +
				'<td>' +
					(isVariable
						? '<span class="mj-muted" aria-hidden="true">' + MJ.icon('minus') + '</span>'
						: '<label class="mj-checkbox"><input type="checkbox" data-pick data-idx="' + idx + '"' + (picked ? ' checked' : '') + ' aria-label="Agregar ' + h(p.name || '') + '"></label>') +
				'</td>' +
				'<td>' + nameHtml + '</td>' +
				'<td class="is-num mj-money">' + h(MJ.fmt.money(p.price, wcCurrency())) + '</td>' +
			'</tr>';
		}

		function renderResults() {
			if (!resultsEl) return;
			if (loading) {
				MJ.ui.loading(resultsEl, mode === 'catalog' ? 'Cargando catálogo…' : 'Buscando…');
				if (resultsInfoEl) resultsInfoEl.textContent = '';
				if (pagerEl) pagerEl.innerHTML = '';
				syncPickAll();
				return;
			}
			if (loadError) {
				resultsEl.innerHTML = MJ.ui.alert(loadError, 'danger');
				if (resultsInfoEl) resultsInfoEl.textContent = '';
				if (pagerEl) pagerEl.innerHTML = '';
				syncPickAll();
				return;
			}
			if (mode === 'search' && term.length < MIN_CHARS) {
				resultsEl.innerHTML = MJ.ui.empty({
					icon: 'search',
					title: 'Buscá productos',
					text: 'Escribí al menos ' + MIN_CHARS + ' letras del nombre o el SKU, o pasá a «Ver catálogo».',
				});
				if (resultsInfoEl) resultsInfoEl.textContent = '';
				if (pagerEl) pagerEl.innerHTML = '';
				syncPickAll();
				return;
			}
			if (!results.length) {
				resultsEl.innerHTML = MJ.ui.empty({
					icon: 'box',
					title: 'Sin resultados',
					text: term ? 'No encontramos productos para «' + term + '».' : 'No hay productos para mostrar.',
				});
				if (resultsInfoEl) resultsInfoEl.textContent = '';
				if (pagerEl) pagerEl.innerHTML = '';
				syncPickAll();
				return;
			}

			let html = '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
				'<th style="width:36px"><span class="mj-hidden">Elegir</span></th>' +
				'<th>Producto</th>' +
				'<th class="is-num">Precio WC</th>' +
			'</tr></thead><tbody>';
			results.forEach(function (p, i) { html += resultRowHtml(p, i); });
			html += '</tbody></table></div>';
			resultsEl.innerHTML = html;

			if (resultsInfoEl) {
				resultsInfoEl.textContent = mode === 'catalog'
					? (MJ.fmt.int(meta.total) + ' productos · página ' + meta.page + ' de ' + meta.pages)
					: (results.length + (results.length === 1 ? ' resultado' : ' resultados'));
			}
			if (pagerEl) {
				pagerEl.innerHTML = (mode === 'catalog' && meta.pages > 1)
					? C.pagination({ page: meta.page, pages: meta.pages, total: meta.total, perPage: PER_PAGE }).html
					: '';
			}
			syncPickAll();
		}

		function selectableResults() {
			return results.filter(function (p) { return bulkProductKind(p) !== 'variable'; });
		}

		function syncPickAll() {
			if (!pickAllEl) return;
			const sel = selectableResults();
			pickAllEl.disabled = sel.length === 0;
			pickAllEl.checked = sel.length > 0 && sel.every(function (p) {
				const productId = parseInt((p.product_id === undefined || p.product_id === null) ? p.id : p.product_id, 10) || 0;
				return !!rowMap[bulkRowKey(productId, p.variation_id)];
			});
		}

		function load() {
			if (destroyed) return;
			if (mode === 'search' && term.length < MIN_CHARS) {
				seq++;
				loading = false;
				loadError = '';
				results = [];
				meta = { total: 0, pages: 1, page: 1 };
				renderResults();
				return;
			}
			const mine = ++seq;
			loading = true;
			loadError = '';
			renderResults();
			const req = mode === 'catalog'
				? MJ.api.get('products', { search: term, page: page, per_page: PER_PAGE })
				: MJ.api.get('products/search', { q: term, limit: SEARCH_LIMIT });
			req.then(function (res) {
				if (mine !== seq || destroyed) return;
				loading = false;
				if (mode === 'catalog') {
					results = (res && Array.isArray(res.items)) ? res.items : [];
					meta = {
						total: parseInt(res && res.total, 10) || results.length,
						pages: Math.max(1, parseInt(res && res.pages, 10) || 1),
						page: Math.max(1, parseInt(res && res.page, 10) || page),
					};
					page = meta.page;
				} else {
					results = Array.isArray(res) ? res : ((res && Array.isArray(res.items)) ? res.items : []);
					meta = { total: results.length, pages: 1, page: 1 };
				}
				renderResults();
			}).catch(function (err) {
				if (mine !== seq || destroyed) return;
				loading = false;
				results = [];
				meta = { total: 0, pages: 1, page: 1 };
				loadError = (err && err.handled) ? '' : MJ.api.message(err, 'No se pudieron cargar los productos.');
				renderResults();
			});
		}

		const loadDebounced = MJ.ui.debounce(function () { load(); }, 300);

		/* --- tabla de filas elegidas ----------------------------------- */

		function currencyOptions(value) {
			const cur = String(value || base).toUpperCase();
			let html = '';
			let found = false;
			currencies.forEach(function (c) {
				if (c === cur) found = true;
				html += '<option value="' + h(c) + '"' + (c === cur ? ' selected' : '') + '>' + h(c) + '</option>';
			});
			if (!found && cur) html += '<option value="' + h(cur) + '" selected>' + h(cur) + '</option>';
			return html;
		}

		function rowHtml(r) {
			return '<tr data-row="' + h(r.key) + '">' +
				'<td><label class="mj-checkbox"><input type="checkbox" data-row-check' + (r.checked ? ' checked' : '') + ' aria-label="Marcar ' + h(r.name) + '"></label></td>' +
				'<td>' +
					'<div class="mj-strong">' + h(r.name) + '</div>' +
					(r.sub ? '<div class="mj-table__sub mj-small mj-muted">' + h(r.sub) + '</div>' : '') +
				'</td>' +
				'<td class="is-num"><input class="mj-input mj-input--sm' + (r.invalid.quantity ? ' is-invalid' : '') + '" type="text" inputmode="decimal" data-field="quantity" value="' + h(bulkNumStr(r.quantity, 4)) + '" aria-label="Cantidad de ' + h(r.name) + '"></td>' +
				'<td class="is-num"><input class="mj-input mj-input--sm' + (r.invalid.unit_cost ? ' is-invalid' : '') + '" type="text" inputmode="decimal" data-field="unit_cost" value="' + h(bulkNumStr(r.unit_cost, 6)) + '" aria-label="Costo unitario de ' + h(r.name) + '"></td>' +
				'<td><select class="mj-select mj-select--sm" data-field="currency" aria-label="Moneda de ' + h(r.name) + '">' + currencyOptions(r.currency) + '</select></td>' +
				'<td class="is-num"><input class="mj-input mj-input--sm' + (r.invalid.rate ? ' is-invalid' : '') + '" type="text" inputmode="decimal" data-field="rate" value="' + h(bulkNumStr(r.rate, 4)) + '" aria-label="Tipo de cambio de ' + h(r.name) + '"></td>' +
				'<td class="is-num mj-money mj-strong" data-fob>' + h(MJ.fmt.money(rowBase(r), base)) + '</td>' +
				'<td class="is-actions"><button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-remove title="Quitar" aria-label="Quitar ' + h(r.name) + '">' + MJ.icon('trash') + '</button></td>' +
			'</tr>';
		}

		function renderRows() {
			if (!rowsEl) return;
			if (!rows.length) {
				rowsEl.innerHTML = MJ.ui.empty({
					icon: 'box',
					title: 'Todavía no elegiste productos',
					text: 'Tildá productos en la lista de arriba: se van sumando acá y podés editar cada fila.',
				});
			} else {
				const allChecked = rows.every(function (r) { return r.checked; });
				let html = '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
					'<th style="width:36px"><label class="mj-checkbox"><input type="checkbox" data-check-all' + (allChecked ? ' checked' : '') + ' aria-label="Marcar todas las filas"></label></th>' +
					'<th>Producto</th>' +
					'<th class="is-num">Cant.</th>' +
					'<th class="is-num">Costo unit.</th>' +
					'<th>Moneda</th>' +
					'<th class="is-num">TC</th>' +
					'<th class="is-num">FOB ' + h(base) + '</th>' +
					'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
				'</tr></thead><tbody>';
				rows.forEach(function (r) { html += rowHtml(r); });
				html += '</tbody><tfoot><tr>' +
					'<td colspan="6" class="mj-strong">Total FOB</td>' +
					'<td class="is-num mj-money mj-strong" data-total>' + h(MJ.fmt.money(totalBase(), base)) + '</td>' +
					'<td></td>' +
				'</tr></tfoot></table></div>';
				rowsEl.innerHTML = html;
			}
			updateCounts();
			syncPickAll();
		}

		function updateCounts() {
			const n = rows.length;
			if (countEl) countEl.textContent = n === 1 ? '1 producto seleccionado' : n + ' productos seleccionados';
			const total = MJ.fmt.money(totalBase(), base);
			const totalCell = rowsEl ? qs('[data-total]', rowsEl) : null;
			if (totalCell) totalCell.textContent = total;
			if (summaryEl) summaryEl.textContent = n ? (n === 1 ? '1 producto · Total FOB ' + total : n + ' productos · Total FOB ' + total) : '';
			if (submitLabelEl) submitLabelEl.textContent = n === 1 ? 'Agregar 1 producto' : 'Agregar ' + n + ' productos';
			if (submitBtn && !submitting) submitBtn.disabled = n === 0;
		}

		/** <tr> de una fila por clave (sin selectores escapados: la clave lleva ":"). */
		function rowEl(key) {
			if (!rowsEl) return null;
			const list = qsa('[data-row]', rowsEl);
			for (let i = 0; i < list.length; i++) {
				if (list[i].getAttribute('data-row') === key) return list[i];
			}
			return null;
		}

		function refreshRow(r) {
			const tr = rowEl(r.key);
			if (tr) {
				const fob = qs('[data-fob]', tr);
				if (fob) fob.textContent = MJ.fmt.money(rowBase(r), base);
			}
			updateCounts();
		}

		/* --- configuración común --------------------------------------- */

		function applyConfig(scope) {
			const cfg = readConfig();
			if (cfg.quantity === null && cfg.unit_cost === null && !cfg.currency && cfg.rate === null) {
				showError('Completá al menos un campo de la configuración común para poder copiarlo.');
				return;
			}
			if (!rows.length) {
				showError('Todavía no elegiste productos: tildá algunos en la lista de arriba.');
				return;
			}
			const targets = scope === 'all' ? rows.slice() : checkedRows();
			if (!targets.length) {
				showError('Marcá al menos una fila de la tabla para aplicarle la configuración.');
				return;
			}
			targets.forEach(function (r) {
				if (cfg.quantity !== null) r.quantity = cfg.quantity;
				if (cfg.unit_cost !== null) r.unit_cost = cfg.unit_cost;
				if (cfg.currency) r.currency = cfg.currency;
				if (cfg.rate !== null) r.rate = cfg.rate;
				r.invalid = { quantity: false, unit_cost: false, rate: false };
			});
			clearError();
			renderRows();
			MJ.ui.toast('Configuración aplicada a ' + targets.length + (targets.length === 1 ? ' producto.' : ' productos.'), 'success');
		}

		/* --- validación y envío ---------------------------------------- */

		function validate() {
			const problems = { quantity: 0, unit_cost: 0, rate: 0 };
			let bad = 0;
			rows.forEach(function (r) {
				const inv = { quantity: false, unit_cost: false, rate: false };
				const q = MJ.fmt.toNumber(r.quantity);
				const c = MJ.fmt.toNumber(r.unit_cost);
				const t = MJ.fmt.toNumber(r.rate);
				if (q === null || !isFinite(q) || q <= 0) { inv.quantity = true; problems.quantity++; }
				if (c === null || !isFinite(c) || c < 0) { inv.unit_cost = true; problems.unit_cost++; }
				if (t === null || !isFinite(t) || t <= 0) { inv.rate = true; problems.rate++; }
				r.invalid = inv;
				if (inv.quantity || inv.unit_cost || inv.rate) bad++;
			});
			paintInvalid();
			if (!bad) return null;
			const parts = [];
			if (problems.quantity) parts.push('la cantidad tiene que ser mayor a 0');
			if (problems.unit_cost) parts.push('el costo unitario no puede ser negativo');
			if (problems.rate) parts.push('el TC tiene que ser mayor a 0');
			return 'Revisá ' + bad + (bad === 1 ? ' fila marcada en rojo: ' : ' filas marcadas en rojo: ') + parts.join('; ') + '.';
		}

		function paintInvalid() {
			if (!rowsEl) return;
			qsa('[data-row]', rowsEl).forEach(function (tr) {
				const r = rowMap[tr.getAttribute('data-row')];
				if (!r) return;
				['quantity', 'unit_cost', 'rate'].forEach(function (field) {
					const input = qs('[data-field="' + field + '"]', tr);
					if (input) input.classList.toggle('is-invalid', !!r.invalid[field]);
				});
			});
		}

		function submit() {
			if (submitting) return;
			clearError();
			if (!rows.length) { showError('Elegí al menos un producto para agregar.'); return; }
			const message = validate();
			if (message) {
				showError(message);
				const firstBad = rowsEl ? qs('.is-invalid', rowsEl) : null;
				if (firstBad && firstBad.focus) { try { firstBad.focus(); } catch (e) { /* noop */ } }
				return;
			}
			const payload = rows.map(function (r) {
				return {
					product_id: r.product_id,
					variation_id: r.variation_id,
					name: r.name,
					sku: r.sku,
					quantity: MJ.fmt.toNumber(r.quantity),
					unit_cost: MJ.fmt.toNumber(r.unit_cost),
					currency: String(r.currency || base).toUpperCase(),
					rate: MJ.fmt.toNumber(r.rate),
				};
			});
			if (typeof opts.onSubmit !== 'function') { modalApi.close(payload); return; }

			submitting = true;
			MJ.ui.setLoading(submitBtn, true);
			let result;
			try {
				result = opts.onSubmit(payload);
			} catch (err) {
				submitting = false;
				MJ.ui.setLoading(submitBtn, false);
				showError(MJ.api.message(err, 'No se pudieron agregar los productos.'));
				return;
			}
			Promise.resolve(result).then(function () {
				submitting = false;
				MJ.ui.setLoading(submitBtn, false);
				modalApi.close(payload);
			}).catch(function (err) {
				submitting = false;
				MJ.ui.setLoading(submitBtn, false);
				if (err && err.handled) return;
				showError(MJ.api.message(err, 'No se pudieron agregar los productos.'));
			});
		}

		/* --- markup ----------------------------------------------------- */

		const body =
			'<div class="mj-bulk-add" id="' + fid + '">' +
				'<div class="mj-bulk-add__picker">' +
					'<div class="mj-tabs" data-modes role="tablist" aria-label="Modo de búsqueda">' +
						'<button type="button" class="mj-tabs__tab is-active" data-tab="search" role="tab" aria-selected="true">' + MJ.icon('search') + '<span>Buscar</span></button>' +
						'<button type="button" class="mj-tabs__tab" data-tab="catalog" role="tab" aria-selected="false">' + MJ.icon('list') + '<span>Ver catálogo</span></button>' +
					'</div>' +
					'<div class="mj-form-row mj-mt">' +
						'<div class="mj-field" style="flex:2">' +
							'<label class="mj-field__label" for="' + fid + '-q">Buscar producto</label>' +
							'<input class="mj-input" id="' + fid + '-q" type="search" data-q placeholder="Nombre o SKU…" autocomplete="off" spellcheck="false" autofocus>' +
							'<div class="mj-field__hint">Las variaciones se pueden elegir; el producto variable «padre», no.</div>' +
						'</div>' +
						'<div class="mj-field mj-field--check" style="flex:1">' +
							'<label class="mj-field__label">&nbsp;</label>' +
							'<label class="mj-checkbox"><input type="checkbox" data-pick-all> <span>Seleccionar todos los resultados</span></label>' +
							'<div class="mj-small mj-muted" data-results-info></div>' +
						'</div>' +
					'</div>' +
					'<div class="mj-bulk-add__results" data-results style="max-height:250px;overflow-y:auto"></div>' +
					'<div data-pager></div>' +
				'</div>' +

				'<div class="mj-card mj-bulk-add__config mj-mt">' +
					'<div class="mj-card__header"><div class="mj-card__title">Configuración común</div></div>' +
					'<div class="mj-card__body">' +
						'<div class="mj-form-row">' +
							'<div class="mj-field">' +
								'<label class="mj-field__label" for="' + fid + '-cq">Cantidad</label>' +
								'<input class="mj-input" id="' + fid + '-cq" type="text" inputmode="decimal" data-cfg="quantity" placeholder="1">' +
							'</div>' +
							'<div class="mj-field">' +
								'<label class="mj-field__label" for="' + fid + '-cc">Costo unitario</label>' +
								'<input class="mj-input" id="' + fid + '-cc" type="text" inputmode="decimal" data-cfg="unit_cost" placeholder="0,00">' +
							'</div>' +
							'<div class="mj-field">' +
								'<label class="mj-field__label" for="' + fid + '-cm">Moneda</label>' +
								'<select class="mj-select" id="' + fid + '-cm" data-cfg="currency">' +
									'<option value="">Sin cambio</option>' +
									currencies.map(function (c) { return '<option value="' + h(c) + '"' + (c === base ? ' selected' : '') + '>' + h(c) + '</option>'; }).join('') +
								'</select>' +
							'</div>' +
							'<div class="mj-field">' +
								'<label class="mj-field__label" for="' + fid + '-ct">TC</label>' +
								'<input class="mj-input" id="' + fid + '-ct" type="text" inputmode="decimal" data-cfg="rate" value="' + h(bulkNumStr(defaultRate, 4)) + '">' +
							'</div>' +
						'</div>' +
						'<div class="mj-row mj-row--wrap">' +
							'<button type="button" class="mj-btn mj-btn--sm" data-apply="selected">' + MJ.icon('copy') + '<span>Aplicar a seleccionados</span></button>' +
							'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-apply="all">' + MJ.icon('layers') + '<span>Aplicar a todos</span></button>' +
							'<span class="mj-small mj-muted">Los campos vacíos no se copian: podés copiar sólo el TC, sólo el costo, etc.</span>' +
						'</div>' +
					'</div>' +
				'</div>' +

				'<div data-error class="mj-mt"></div>' +

				'<div class="mj-row mj-row--between mj-row--wrap mj-mt">' +
					'<div class="mj-strong" data-count>0 productos seleccionados</div>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-clear>' + MJ.icon('trash') + '<span>Vaciar lista</span></button>' +
				'</div>' +
				'<div class="mj-bulk-add__rows" data-rows></div>' +
			'</div>';

		const footer =
			'<span class="mj-small mj-muted mj-ml-auto" data-summary></span>' +
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="button" class="mj-btn mj-btn--primary" data-submit disabled>' + MJ.icon('plus') + '<span data-submit-label>Agregar productos</span></button>';

		modalApi = MJ.ui.modal({
			title: opts.title || 'Agregar productos en masa',
			size: 'xl',
			body: body,
			footer: footer,
			onClose() {
				destroyed = true;
				seq++;
				loadDebounced.cancel();
				if (tabsApi && typeof tabsApi.destroy === 'function') tabsApi.destroy();
				offs.forEach(function (off) { try { off(); } catch (e) { /* noop */ } });
				offs.length = 0;
			},
			onMount(root) {
				rootEl = qs('#' + fid, root) || root;
				qInput = qs('[data-q]', rootEl);
				pickAllEl = qs('[data-pick-all]', rootEl);
				resultsEl = qs('[data-results]', rootEl);
				resultsInfoEl = qs('[data-results-info]', rootEl);
				pagerEl = qs('[data-pager]', rootEl);
				rowsEl = qs('[data-rows]', rootEl);
				countEl = qs('[data-count]', rootEl);
				errorEl = qs('[data-error]', rootEl);
				summaryEl = qs('[data-summary]', root);
				submitBtn = qs('[data-submit]', root);
				submitLabelEl = qs('[data-submit-label]', root);
				cfgQty = qs('[data-cfg="quantity"]', rootEl);
				cfgCost = qs('[data-cfg="unit_cost"]', rootEl);
				cfgCur = qs('[data-cfg="currency"]', rootEl);
				cfgRate = qs('[data-cfg="rate"]', rootEl);

				/* Modo buscar / catálogo */
				tabsApi = MJ.ui.tabs(qs('[data-modes]', rootEl), {
					active: 'search',
					onChange(key) {
						mode = key === 'catalog' ? 'catalog' : 'search';
						page = 1;
						loadDebounced.cancel();
						load();
					},
				});

				/* Buscador */
				offs.push(MJ.ui.on(rootEl, 'input', '[data-q]', function () {
					term = qInput.value.trim();
					page = 1;
					loadDebounced();
				}));
				offs.push(MJ.ui.on(rootEl, 'keydown', '[data-q]', function (e) {
					if (e.key !== 'Enter') return;
					e.preventDefault();
					term = qInput.value.trim();
					page = 1;
					loadDebounced.cancel();
					load();
				}));

				/* Elegir productos del resultado */
				offs.push(MJ.ui.on(resultsEl, 'change', '[data-pick]', function (e, cb) {
					const idx = parseInt(cb.getAttribute('data-idx'), 10);
					const p = results[idx];
					if (!p) return;
					const productId = parseInt((p.product_id === undefined || p.product_id === null) ? p.id : p.product_id, 10) || 0;
					const key = bulkRowKey(productId, p.variation_id);
					if (cb.checked) addProduct(p); else removeRow(key);
					const tr = cb.closest('tr');
					if (tr) tr.classList.toggle('is-picked', cb.checked);
					clearError();
					renderRows();
				}));

				offs.push(MJ.ui.on(rootEl, 'change', '[data-pick-all]', function () {
					const sel = selectableResults();
					sel.forEach(function (p) {
						const productId = parseInt((p.product_id === undefined || p.product_id === null) ? p.id : p.product_id, 10) || 0;
						const key = bulkRowKey(productId, p.variation_id);
						if (pickAllEl.checked) addProduct(p); else removeRow(key);
					});
					clearError();
					renderResults();
					renderRows();
				}));

				/* Paginación del catálogo */
				offs.push(C.bindPagination(pagerEl, function (p) {
					page = p;
					loadDebounced.cancel();
					load();
					if (resultsEl && resultsEl.scrollTo) { try { resultsEl.scrollTo({ top: 0 }); } catch (err) { resultsEl.scrollTop = 0; } }
				}));

				/* Configuración común */
				offs.push(MJ.ui.on(rootEl, 'click', '[data-apply]', function (e, btn) {
					e.preventDefault();
					applyConfig(btn.getAttribute('data-apply'));
				}));

				/* Edición fila por fila */
				offs.push(MJ.ui.on(rowsEl, 'input', 'input[data-field]', function (e, input) {
					const tr = input.closest('[data-row]');
					const r = tr ? rowMap[tr.getAttribute('data-row')] : null;
					if (!r) return;
					const field = input.getAttribute('data-field');
					const raw = String(input.value).trim();
					const value = raw === '' ? NaN : MJ.fmt.parse(raw);
					r[field] = isNaN(value) ? NaN : value;
					input.classList.remove('is-invalid');
					r.invalid[field] = false;
					clearError();
					refreshRow(r);
				}));

				offs.push(MJ.ui.on(rowsEl, 'change', 'select[data-field]', function (e, select) {
					const tr = select.closest('[data-row]');
					const r = tr ? rowMap[tr.getAttribute('data-row')] : null;
					if (!r) return;
					r.currency = String(select.value || base).toUpperCase();
					clearError();
					refreshRow(r);
				}));

				offs.push(MJ.ui.on(rowsEl, 'change', '[data-row-check]', function (e, cb) {
					const tr = cb.closest('[data-row]');
					const r = tr ? rowMap[tr.getAttribute('data-row')] : null;
					if (!r) return;
					r.checked = cb.checked;
					const all = qs('[data-check-all]', rowsEl);
					if (all) all.checked = rows.length > 0 && rows.every(function (x) { return x.checked; });
				}));

				offs.push(MJ.ui.on(rowsEl, 'change', '[data-check-all]', function (e, cb) {
					rows.forEach(function (r) { r.checked = cb.checked; });
					qsa('[data-row-check]', rowsEl).forEach(function (n) { n.checked = cb.checked; });
				}));

				offs.push(MJ.ui.on(rowsEl, 'click', '[data-remove]', function (e, btn) {
					e.preventDefault();
					const tr = btn.closest('[data-row]');
					if (!tr) return;
					removeRow(tr.getAttribute('data-row'));
					clearError();
					renderResults();
					renderRows();
				}));

				/* Enter → misma columna de la fila siguiente */
				offs.push(MJ.ui.on(rowsEl, 'keydown', '[data-field]', function (e, input) {
					if (e.key !== 'Enter') return;
					e.preventDefault();
					const field = input.getAttribute('data-field');
					const tr = input.closest('[data-row]');
					const next = tr ? tr.nextElementSibling : null;
					const target = next ? qs('[data-field="' + field + '"]', next) : null;
					if (target) {
						target.focus();
						if (target.select) { try { target.select(); } catch (err) { /* noop */ } }
					} else if (input.blur) {
						input.blur();
					}
				}));

				/* Vaciar lista */
				offs.push(MJ.ui.on(rootEl, 'click', '[data-clear]', function (e) {
					e.preventDefault();
					if (!rows.length) return;
					rows.slice().forEach(function (r) { removeRow(r.key); });
					clearError();
					renderResults();
					renderRows();
				}));

				offs.push(MJ.ui.on(root, 'click', '[data-submit]', function (e) {
					e.preventDefault();
					submit();
				}));

				renderRows();
				renderResults();
			},
		});

		return modalApi;
	};
})();
