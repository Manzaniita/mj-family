/**
 * MJ ERP — components-customers.js
 *
 * La libreta de clientes: alta/edición de una ficha y el diálogo que la ata a una cuenta de la
 * tienda.
 *
 * La distinción que atraviesa todo el archivo: una **ficha** es a quién le vendiste, y existe
 * aunque esa persona nunca haya entrado a la tienda; una **cuenta** es un usuario de WooCommerce.
 * Nada de acá crea cuentas ni manda mails. Vincularlas es un paso aparte y explícito, porque une
 * dos historiales y es de lo poco que conviene mirar antes de hacer.
 *
 * REST: POST /customers, PUT /customers/{id}, GET /customers/accounts,
 *       POST /customers/{id}/link, DELETE /customers/{id}/link.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};
	const C = MJ.components;

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	/* ------------------------------------------------------------------ */
	/* Config                                                              */
	/* ------------------------------------------------------------------ */

	/** Tipos de documento configurados en wp-admin. */
	function docTypes() {
		const list = MJ.setting('customers_doc_types', []);
		return Array.isArray(list) ? list.filter(function (t) { return t && t.key; }) : [];
	}

	/** Etiqueta de un tipo de documento. */
	function docTypeLabel(key) {
		const t = docTypes().find(function (x) { return x.key === key; });
		return t ? t.label : (key || '');
	}

	/** Campos opcionales que la ficha muestra (configurable). */
	function fieldOn(key) {
		const list = MJ.setting('customers_fields', ['phone', 'email', 'doc', 'address', 'notes']);
		return Array.isArray(list) ? list.indexOf(key) >= 0 : true;
	}

	function isActiveRow(row) {
		if (!row) return true;
		const v = row.is_active;
		if (v === undefined || v === null || v === '') return true;
		if (typeof v === 'boolean') return v;
		if (typeof v === 'number') return v !== 0;
		const s = String(v).trim().toLowerCase();
		return !(s === '0' || s === 'false' || s === 'no');
	}

	C.customerDocTypes = docTypes;
	C.customerDocTypeLabel = docTypeLabel;
	C.customerFieldOn = fieldOn;

	/* ------------------------------------------------------------------ */
	/* customerModal                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Alta / edición de una ficha de cliente.
	 * @param {Object} opts { customer?, onSaved(customer) }
	 */
	C.customerModal = function (opts) {
		opts = opts || {};
		const c = opts.customer || {};
		const isEdit = !!c.id;
		const fid = 'mj-cus-' + Math.random().toString(36).slice(2, 8);
		const types = docTypes();
		const lists = MJ.lists.priceLists();
		const defaultDocType = String(c.doc_type || MJ.setting('customers_default_doc_type', ''));

		const docField = !fieldOn('doc') ? '' :
			'<div class="mj-form-row">' +
				'<div class="mj-field" style="flex:0 1 150px">' +
					'<label class="mj-field__label" for="' + fid + '-doctype">Documento</label>' +
					'<select class="mj-select" id="' + fid + '-doctype" name="doc_type">' +
						'<option value="">—</option>' +
						types.map(function (t) {
							return '<option value="' + h(t.key) + '"' + (defaultDocType === t.key ? ' selected' : '') + '>' + h(t.label) + '</option>';
						}).join('') +
					'</select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-docnum">Número</label>' +
					'<input class="mj-input" id="' + fid + '-docnum" name="doc_number" type="text" maxlength="60" value="' + h(c.doc_number || '') + '">' +
				'</div>' +
			'</div>';

		const contactRow = (!fieldOn('phone') && !fieldOn('email')) ? '' :
			'<div class="mj-form-row">' +
				(fieldOn('phone')
					? '<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-phone">Teléfono</label>' +
						'<input class="mj-input" id="' + fid + '-phone" name="phone" type="tel" maxlength="60" value="' + h(c.phone || '') + '">' +
					'</div>'
					: '') +
				(fieldOn('email')
					? '<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-email">Email</label>' +
						'<input class="mj-input" id="' + fid + '-email" name="email" type="email" maxlength="190" value="' + h(c.email || '') + '">' +
					'</div>'
					: '') +
			'</div>';

		const addressBlock = !fieldOn('address') ? '' :
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="' + fid + '-address">Dirección</label>' +
				'<input class="mj-input" id="' + fid + '-address" name="address" type="text" maxlength="255" value="' + h(c.address || '') + '">' +
			'</div>' +
			'<div class="mj-form-row">' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-city">Localidad</label>' +
					'<input class="mj-input" id="' + fid + '-city" name="city" type="text" maxlength="120" value="' + h(c.city || '') + '">' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-province">Provincia</label>' +
					'<input class="mj-input" id="' + fid + '-province" name="province" type="text" maxlength="120" value="' + h(c.province || '') + '">' +
				'</div>' +
				'<div class="mj-field" style="flex:0 1 130px">' +
					'<label class="mj-field__label" for="' + fid + '-postcode">CP</label>' +
					'<input class="mj-input" id="' + fid + '-postcode" name="postcode" type="text" maxlength="30" value="' + h(c.postcode || '') + '">' +
				'</div>' +
			'</div>';

		const companyField = !fieldOn('company') ? '' :
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="' + fid + '-company">Empresa</label>' +
				'<input class="mj-input" id="' + fid + '-company" name="company" type="text" maxlength="190" value="' + h(c.company || '') + '">' +
			'</div>';

		const priceListField = !fieldOn('price_list') || !lists.length ? '' :
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="' + fid + '-list">Lista de precios</label>' +
				'<select class="mj-select" id="' + fid + '-list" name="price_list_key">' +
					'<option value="">La que use la venta</option>' +
					lists.map(function (l) {
						return '<option value="' + h(l.key) + '"' + (String(c.price_list_key || '') === l.key ? ' selected' : '') + '>' + h(l.label) + '</option>';
					}).join('') +
				'</select>' +
				'<div class="mj-field__hint mj-small mj-muted">Si elegís una, es la que se aplica cuando le vendas a este cliente.</div>' +
			'</div>';

		const notesField = !fieldOn('notes') ? '' :
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
				'<textarea class="mj-textarea" id="' + fid + '-notes" name="notes" rows="2">' + h(c.notes || '') + '</textarea>' +
			'</div>';

		const activeField = !isEdit ? '' :
			'<div class="mj-field">' +
				'<label class="mj-switch">' +
					'<input type="checkbox" name="is_active"' + (isActiveRow(c) ? ' checked' : '') + '>' +
					'<span class="mj-switch__slider"></span>' +
					'<span class="mj-switch__label">Activo</span>' +
				'</label>' +
				'<div class="mj-field__hint mj-small mj-muted">Un cliente inactivo no aparece al cargar una venta, pero su historial queda.</div>' +
			'</div>';

		const linkNote = c.is_linked && c.account
			? '<div class="mj-alert mj-alert--info mj-mb">Vinculado a la cuenta <strong>' + h(c.account.name || c.account.email || ('#' + c.account.id)) + '</strong> de la tienda.</div>'
			: (isEdit
				? '<div class="mj-alert mj-alert--info mj-mb">Este cliente no tiene cuenta en la tienda. Si algún día se crea una, podés vincularla desde la lista de clientes.</div>'
				: '<div class="mj-alert mj-alert--info mj-mb">Es solo un registro de ventas: no crea una cuenta en la tienda ni manda ningún mail.</div>');

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate autocomplete="off">' +
				linkNote +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-name">Nombre *</label>' +
					'<input class="mj-input" id="' + fid + '-name" name="name" type="text" required maxlength="190" value="' + h(c.name || '') + '" placeholder="Nombre y apellido" autofocus>' +
				'</div>' +
				companyField +
				contactRow +
				docField +
				addressBlock +
				priceListField +
				notesField +
				activeField +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('check') +
				'<span>' + (isEdit ? 'Guardar cambios' : 'Crear cliente') + '</span></button>';

		return MJ.ui.modal({
			title: isEdit ? 'Editar cliente' : 'Nuevo cliente',
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

					const email = String(data.email || '').trim();
					if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Email inválido.';

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = { name: data.name };
					['company', 'doc_type', 'doc_number', 'phone', 'address', 'city', 'province', 'postcode', 'price_list_key', 'notes'].forEach(function (key) {
						if (data[key] !== undefined) payload[key] = String(data[key] || '').trim();
					});
					payload.email = email;
					if (isEdit) payload.is_active = !!data.is_active;

					MJ.ui.setLoading(submitBtn, true);

					const req = isEdit
						? MJ.api.put('customers/' + encodeURIComponent(c.id), payload)
						: MJ.api.post('customers', payload);

					req.then(function (saved) {
						const row = saved && saved.id ? saved : Object.assign({}, c, payload);
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast(isEdit ? 'Cliente actualizado.' : 'Cliente creado.', 'success');
						MJ.events.emit('customers:changed', row);
						modal.close(row);
						if (typeof opts.onSaved === 'function') opts.onSaved(row);
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
	/* customerLinkModal                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Ata una ficha del mostrador a una cuenta de la tienda.
	 *
	 * @param {Object} opts { customer, account?, onLinked(customer) }
	 */
	C.customerLinkModal = function (opts) {
		opts = opts || {};
		const c = opts.customer || {};
		const fid = 'mj-lnk-' + Math.random().toString(36).slice(2, 8);
		let chosen = opts.account || null;
		let seq = 0;

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate autocomplete="off">' +
				'<div class="mj-alert mj-alert--info mj-mb">' +
					'Vas a unir el historial de <strong>' + h(c.name || 'este cliente') + '</strong> con una cuenta de la tienda. ' +
					'Desde ese momento, lo que compre online y lo que compre en el mostrador es la misma persona.' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-q">Cuenta de la tienda</label>' +
					'<input class="mj-input" id="' + fid + '-q" type="search" placeholder="Buscar por nombre o email…" autocomplete="off">' +
					'<div class="mj-field__hint mj-small mj-muted">Solo busca cuentas que ya existen. Esto no crea ninguna.</div>' +
				'</div>' +
				'<div data-region="results" class="mj-list mj-mt"></div>' +
				'<div data-region="chosen" class="mj-mt"></div>' +
				'<label class="mj-checkbox mj-mt" for="' + fid + '-backfill">' +
					'<input type="checkbox" id="' + fid + '-backfill" name="backfill" checked>' +
					'<span>Marcar las ventas anteriores de este cliente como de esa cuenta</span>' +
				'</label>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="button" class="mj-btn mj-btn--primary" data-submit disabled>' + MJ.icon('link') + '<span>Vincular</span></button>';

		return MJ.ui.modal({
			title: 'Vincular con una cuenta',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const input = qs('#' + fid + '-q', root);
				const results = qs('[data-region="results"]', root);
				const chosenEl = qs('[data-region="chosen"]', root);
				const submitBtn = qs('[data-submit]', root);
				const backfill = qs('#' + fid + '-backfill', root);

				function paintChosen() {
					if (!chosen) {
						chosenEl.innerHTML = '';
						submitBtn.disabled = true;
						return;
					}
					chosenEl.innerHTML =
						'<div class="mj-alert mj-alert--success">' +
							'Elegida: <strong>' + h(chosen.name || chosen.email || ('#' + chosen.user_id)) + '</strong>' +
							(chosen.email ? ' <span class="mj-muted">· ' + h(chosen.email) + '</span>' : '') +
						'</div>';
					submitBtn.disabled = false;
				}

				function paintResults(rows) {
					if (!rows.length) {
						results.innerHTML = '<div class="mj-empty mj-small mj-muted">Ninguna cuenta coincide.</div>';
						return;
					}
					results.innerHTML = rows.map(function (a, idx) {
						// Una cuenta ya tomada se muestra igual pero no se puede elegir: el servidor la
						// rechazaría, y es mejor verlo antes que después.
						const taken = a.taken_by;
						return '<button type="button" class="mj-list__item' + (taken ? ' is-disabled' : '') + '" data-index="' + idx + '"' + (taken ? ' disabled' : '') + '>' +
							(a.avatar ? '<img class="mj-autocomplete__thumb" src="' + h(a.avatar) + '" alt="" loading="lazy">' : '') +
							'<span class="mj-list__text">' +
								'<span class="mj-strong">' + h(a.name || ('#' + a.user_id)) + '</span>' +
								(a.email ? '<span class="mj-small mj-muted"> · ' + h(a.email) + '</span>' : '') +
								(taken ? '<span class="mj-small mj-muted"> · ya es «' + h(taken.name) + '»</span>' : '') +
							'</span>' +
						'</button>';
					}).join('');
					results.__rows = rows;
				}

				const onInput = MJ.ui.debounce(function () {
					const term = String(input.value || '').trim();
					if (term.length < 2) { results.innerHTML = ''; return; }
					const mine = ++seq;
					results.innerHTML = '<div class="mj-small mj-muted">Buscando…</div>';
					MJ.api.get('customers/accounts', { q: term, limit: 15 }).then(function (res) {
						if (mine !== seq) return;
						paintResults(Array.isArray(res) ? res : (res && res.items) || []);
					}).catch(function () {
						if (mine !== seq) return;
						results.innerHTML = '<div class="mj-small mj-danger-text">No se pudo buscar.</div>';
					});
				}, 300);

				input.addEventListener('input', onInput);

				results.addEventListener('click', function (e) {
					const btn = e.target.closest ? e.target.closest('[data-index]') : null;
					if (!btn || btn.disabled) return;
					const rows = results.__rows || [];
					chosen = rows[parseInt(btn.getAttribute('data-index'), 10)] || null;
					paintChosen();
				});

				submitBtn.addEventListener('click', function () {
					if (!chosen) return;
					MJ.ui.setLoading(submitBtn, true);
					MJ.api.post('customers/' + encodeURIComponent(c.id) + '/link', {
						user_id: chosen.user_id || chosen.id,
						backfill: !!backfill.checked,
					}).then(function (saved) {
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Cliente vinculado a la cuenta.', 'success');
						MJ.events.emit('customers:changed', saved);
						modal.close(saved);
						if (typeof opts.onLinked === 'function') opts.onLinked(saved);
					}).catch(function (err) {
						MJ.ui.setLoading(submitBtn, false);
						if (err && err.handled) return;
						MJ.ui.toastError(err, 'No se pudo vincular el cliente.');
					});
				});

				paintChosen();
				input.focus();
			},
		});
	};
})();
