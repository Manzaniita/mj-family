/**
 * MJ ERP — components-consignment.js
 * Diálogos compartidos del módulo de consignaciones: alta y edición de revendedores, alta de
 * entregas, registro de ventas, devoluciones y pagos.
 *
 * El diálogo de pago es el más importante: muestra, antes de confirmar, a qué ventas concretas va
 * a ir la plata. El reparto se calcula del lado del cliente con el mismo algoritmo que el servidor
 * (MJ.consign.allocate ↔ MJERP_Consignment_Payments_Repo::allocate) y el servidor manda igual.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.components = MJ.components || {};
	MJ.consign = MJ.consign || {};
	const C = MJ.components;

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	/** Los importes se guardan con 4 decimales: por debajo de esto está saldado. */
	const EPSILON = 0.0001;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function num(v) { return MJ.fmt.toNumber(v); }
	function f(v) { return num(v) === null ? 0 : num(v); }

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCur() { return String(setting('base_currency', 'USD')).toUpperCase(); }
	function localCur() { return String(setting('local_currency', 'ARS')).toUpperCase(); }

	function moneyBase(value) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, baseCur(), decimals);
	}

	/** TC vigente: primero MJ Dólar, después el TC de venta del ERP. */
	function currentRate() {
		const dolar = setting('dolar', null);
		const fromDolar = dolar && typeof dolar === 'object' ? f(dolar.rate) : 0;
		if (fromDolar > 0) return fromDolar;
		const saleRate = f(setting('sale_rate', 0));
		return saleRate > 0 ? saleRate : 1;
	}

	function paymentMethods() {
		const boot = MJ.state.bootstrap && MJ.state.bootstrap.consignment;
		const list = boot && Array.isArray(boot.payment_methods) ? boot.payment_methods : setting('consignment_payment_methods', []);
		return Array.isArray(list) && list.length ? list : [{ key: 'otro', label: 'Otro' }];
	}

	function currencies() {
		const list = setting('currencies', ['USD', 'ARS']);
		return Array.isArray(list) && list.length ? list : ['USD', 'ARS'];
	}

	function inputNum(value, decimals) {
		const n = num(value);
		if (n === null) return '';
		return MJ.fmt.number(n, decimals === undefined ? 2 : decimals).replace(/([.,]\d*?)0+$/, '$1').replace(/[.,]$/, '');
	}

	function optionsHtml(list, value) {
		return list.map(function (o) {
			return '<option value="' + h(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
		}).join('');
	}

	function clearFormErrors(form) {
		if (!form) return;
		qsa('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
		qsa('.mj-field__error', form).forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
		qsa('[data-form-alert]', form).forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
	}

	function showFormError(form, err) {
		if (!form) return;
		const params = err && err.data && err.data.params && typeof err.data.params === 'object' ? err.data.params : null;
		if (params && Object.keys(params).length) {
			MJ.ui.showErrors(form, params);
			return;
		}
		const field = err && err.data && err.data.field ? String(err.data.field) : '';
		if (field) {
			const map = {};
			map[field] = MJ.api.message(err, 'Revisá este campo.');
			MJ.ui.showErrors(form, map);
			return;
		}
		const box = document.createElement('div');
		box.className = 'mj-alert mj-alert--danger mj-mb';
		box.setAttribute('data-form-alert', '1');
		box.setAttribute('role', 'alert');
		box.innerHTML = MJ.icon('warning') + '<div class="mj-alert__body">' + h(MJ.api.message(err, 'Ocurrió un error inesperado.')) + '</div>';
		form.insertBefore(box, form.firstChild);
	}

	/* ------------------------------------------------------------------ */
	/* Allocation algorithm (mirror of the PHP engine)                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Reparte un importe entre ventas impagas, de la más vieja a la más nueva.
	 *
	 * Espejo exacto de MJERP_Consignment_Payments_Repo::allocate(). Se usa solo para previsualizar:
	 * el reparto que vale es el que hace el servidor.
	 *
	 * @param {Array} sales      Ventas ya ordenadas (más vieja primero), con id y balance_base.
	 * @param {*} amountBase     Importe disponible, en moneda base.
	 * @param {Object} [skip]    sale_id → importe ya cubierto en la misma operación.
	 * @returns {{lines: Array, allocated: number, unallocated: number}}
	 */
	MJ.consign.allocate = function (sales, amountBase, skip) {
		let remaining = MJ.fmt.round(f(amountBase), 4);
		const lines = [];
		let allocated = 0;
		skip = skip || {};

		if (remaining > EPSILON) {
			(Array.isArray(sales) ? sales : []).forEach(function (sale) {
				if (remaining <= EPSILON) return;
				const saleId = parseInt(sale && sale.id, 10) || 0;
				let balance = f(sale && sale.balance_base);
				if (Object.prototype.hasOwnProperty.call(skip, saleId)) balance -= f(skip[saleId]);
				if (saleId <= 0 || balance <= EPSILON) return;

				const take = MJ.fmt.round(Math.min(remaining, balance), 4);
				if (take <= EPSILON) return;

				lines.push({ sale_id: saleId, amount_base: take, sale: sale });
				allocated += take;
				remaining = MJ.fmt.round(remaining - take, 4);
			});
		}

		return {
			lines: lines,
			allocated: MJ.fmt.round(allocated, 4),
			unallocated: MJ.fmt.round(Math.max(0, remaining), 4),
		};
	};

	/* ------------------------------------------------------------------ */
	/* consigneeModal                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Alta / edición de un revendedor.
	 * @param {Object} opts { consignee?, onSaved(consignee) }
	 */
	C.consigneeModal = function (opts) {
		opts = opts || {};
		const editing = opts.consignee && opts.consignee.id ? opts.consignee : null;
		const fid = 'mj-cg-' + Math.random().toString(36).slice(2, 8);

		const lists = (function () {
			try { return MJ.lists.priceLists() || []; } catch (e) { return []; }
		})();
		const listOptions = lists.map(function (l) {
			return { value: l.key, label: l.label + (l.markup !== undefined ? ' (+' + MJ.fmt.number(l.markup, 0) + '%)' : '') };
		});

		const roleName = String(setting('consignee_role', 'reventa'));

		/* En edición no se toca la cuenta de WordPress: mover un usuario movería una deuda. */
		const accountBlock = editing
			? '<div class="mj-note mj-small mj-muted">' + MJ.icon('user') +
				'<span>Cuenta de WordPress: <span class="mj-mono">' + h(editing.user_login || editing.user_email || ('#' + editing.user_id)) + '</span>' +
				(editing.edit_user_link ? ' · <a class="mj-link" href="' + h(editing.edit_user_link) + '" target="_blank" rel="noopener">Editarla en WordPress</a>' : '') +
				'</span></div>'
			: '<div class="mj-field">' +
					'<span class="mj-field__label">Cuenta de WordPress</span>' +
					'<div class="mj-row mj-row--wrap" role="group">' +
						'<label class="mj-checkbox"><input type="radio" name="mode" value="new" checked> <span>Crear una cuenta nueva</span></label>' +
						'<label class="mj-checkbox"><input type="radio" name="mode" value="link"> <span>Vincular una cuenta existente</span></label>' +
					'</div>' +
					'<div class="mj-field__hint">El revendedor necesita una cuenta para entrar a su panel. Se le da el rol <span class="mj-mono">' + h(roleName) + '</span>, así queda también como cliente de WooCommerce.</div>' +
				'</div>' +
				'<div data-region="link-user" class="mj-hidden">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-user">Buscar cuenta</label>' +
						'<select class="mj-select" id="' + fid + '-user" name="user_id"><option value="">Cargando cuentas…</option></select>' +
					'</div>' +
				'</div>';

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				accountBlock +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-name">Nombre *</label>' +
					'<input class="mj-input" id="' + fid + '-name" name="name" type="text" required maxlength="190" value="' + h(editing ? editing.name : '') + '"' + (editing ? '' : ' autofocus') + '>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-email">Email' + (editing ? '' : ' *') + '</label>' +
						'<input class="mj-input" id="' + fid + '-email" name="email" type="email" maxlength="190" value="' + h(editing ? editing.email : '') + '">' +
						(editing ? '' : '<div class="mj-field__hint">Con este email entra a su panel.</div>') +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-phone">Teléfono</label>' +
						'<input class="mj-input" id="' + fid + '-phone" name="phone" type="text" maxlength="60" value="' + h(editing ? editing.phone : '') + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-list">Lista de precio</label>' +
						'<select class="mj-select" id="' + fid + '-list" name="price_list_key">' +
							(listOptions.length ? optionsHtml(listOptions, editing ? editing.price_list_key : setting('consignment_price_list', '')) : '<option value="">Sin listas configuradas</option>') +
						'</select>' +
						'<div class="mj-field__hint">Con esta lista se propone el precio acordado de cada entrega.</div>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-limit">Límite de crédito (' + h(baseCur()) + ')</label>' +
						'<input class="mj-input" id="' + fid + '-limit" name="credit_limit_base" type="text" inputmode="decimal" data-type="number" value="' + h(editing ? inputNum(editing.credit_limit_base, 2) : '') + '" placeholder="0">' +
						'<div class="mj-field__hint">0 = sin límite. Solo avisa, no bloquea.</div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
					'<textarea class="mj-input mj-textarea" id="' + fid + '-notes" name="notes" rows="2" maxlength="2000">' + h(editing ? editing.notes : '') + '</textarea>' +
					'<div class="mj-field__hint">Privadas: el revendedor no las ve.</div>' +
				'</div>' +
				(editing
					? '<label class="mj-checkbox"><input type="checkbox" name="is_active"' + (editing.is_active !== false ? ' checked' : '') + '> <span>Activo</span></label>'
					: '<label class="mj-checkbox"><input type="checkbox" name="send_invite" checked data-invite> <span>Mandarle un email para que elija su contraseña</span></label>') +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' +
				MJ.icon(editing ? 'save' : 'plus') + '<span>' + (editing ? 'Guardar' : 'Crear revendedor') + '</span></button>';

		return MJ.ui.modal({
			title: editing ? 'Editar revendedor' : 'Nuevo revendedor',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				let submitting = false;
				let mode = 'new';

				const linkRegion = qs('[data-region="link-user"]', form);
				const userSelect = qs('[name="user_id"]', form);
				const emailField = qs('[name="email"]', form);
				const nameField = qs('[name="name"]', form);

				if (!editing && userSelect) {
					MJ.api.get('consignees/candidates', { limit: 100 }).then(function (res) {
						const items = (res && Array.isArray(res.items)) ? res.items : [];
						const options = items.filter(function (u) { return !u.is_consignee; }).map(function (u) {
							return { value: u.id, label: u.name + ' · ' + u.email };
						});
						MJ.ui.fillSelect(userSelect, options, '', options.length ? 'Elegí una cuenta…' : 'No hay cuentas disponibles');
						if (res && res.can_create_users === false) {
							// Sin permiso para crear usuarios, vincular es la única opción posible.
							const radio = qs('[name="mode"][value="link"]', form);
							if (radio) { radio.checked = true; setMode('link'); }
							const newRadio = qs('[name="mode"][value="new"]', form);
							if (newRadio) newRadio.disabled = true;
						}
					}).catch(function () {
						MJ.ui.fillSelect(userSelect, [], '', 'No se pudieron cargar las cuentas');
					});
				}

				function setMode(value) {
					mode = value === 'link' ? 'link' : 'new';
					if (linkRegion) linkRegion.classList.toggle('mj-hidden', mode !== 'link');
					const invite = qs('[data-invite]', form);
					if (invite) {
						const wrap = invite.closest('.mj-checkbox');
						if (wrap) wrap.classList.toggle('mj-hidden', mode === 'link');
					}
					if (emailField) {
						const hint = emailField.parentNode ? qs('.mj-field__hint', emailField.parentNode) : null;
						if (hint) hint.textContent = mode === 'link' ? 'Opcional: si lo dejás vacío se usa el de la cuenta.' : 'Con este email entra a su panel.';
					}
				}

				MJ.ui.on(form, 'change', '[name="mode"]', function (e, el) { setMode(el.value); });

				// Al elegir una cuenta, el nombre se completa solo si está vacío.
				MJ.ui.on(form, 'change', '[name="user_id"]', function (e, el) {
					if (!nameField || nameField.value.trim() !== '') return;
					const label = el.options[el.selectedIndex] ? el.options[el.selectedIndex].textContent : '';
					const name = String(label).split(' · ')[0];
					if (name) nameField.value = name;
				});

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);

					const data = MJ.ui.formData(form);
					const errors = {};
					const name = String(data.name || '').trim();
					if (!name) errors.name = 'El nombre es obligatorio.';

					if (!editing && mode === 'new') {
						const email = String(data.email || '').trim();
						if (!email) errors.email = 'Hace falta un email para crear la cuenta.';
						else if (email.indexOf('@') === -1) errors.email = 'El email es inválido.';
					}
					if (!editing && mode === 'link') {
						const userId = parseInt(data.user_id, 10) || 0;
						if (!userId) errors.user_id = 'Elegí una cuenta.';
					}
					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = {
						name: name,
						phone: String(data.phone || '').trim(),
						price_list_key: String(data.price_list_key || ''),
						credit_limit_base: data.credit_limit_base === null || data.credit_limit_base === undefined ? 0 : data.credit_limit_base,
						notes: String(data.notes || ''),
					};
					const email = String(data.email || '').trim();
					if (email) payload.email = email;

					if (editing) {
						payload.is_active = !!data.is_active;
					} else if (mode === 'link') {
						payload.user_id = parseInt(data.user_id, 10) || 0;
					} else {
						payload.send_invite = !!data.send_invite;
					}

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					const request = editing
						? MJ.api.put('consignees/' + editing.id, payload)
						: MJ.api.post('consignees', payload);

					request.then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast(editing ? 'Revendedor actualizado.' : 'Revendedor creado.', 'success');
						modal.close();
						const password = res && res.generated_password ? String(res.generated_password) : '';
						if (password) showPassword(res.consignee, password);
						if (typeof opts.onSaved === 'function') opts.onSaved(res && res.consignee ? res.consignee : null);
					}).catch(function (err) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						if (err && (err.handled || err.status === 401)) return;
						showFormError(form, err);
					});
				});

				setMode('new');
			},
		});
	};

	/**
	 * Muestra una sola vez la contraseña generada: no queda guardada en ningún lado.
	 *
	 * @param {Object} consignee Revendedor creado.
	 * @param {string} password  Contraseña generada.
	 */
	function showPassword(consignee, password) {
		const portalUrl = (MJ.state.bootstrap && MJ.state.bootstrap.consignment && MJ.state.bootstrap.consignment.portal_url) || '';
		MJ.ui.modal({
			title: 'Datos de acceso',
			size: 'sm',
			body:
				MJ.ui.alert('Esta contraseña se muestra una sola vez: no queda guardada en ningún lado. Copiala y mandásela ahora.', 'warning') +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Usuario</span>' +
					'<div class="mj-mono mj-strong">' + h((consignee && (consignee.user_login || consignee.email)) || '') + '</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Contraseña</span>' +
					'<div class="mj-row mj-row--center">' +
						'<code class="mj-code mj-strong" data-password>' + h(password) + '</code>' +
						'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-copy title="Copiar">' + MJ.icon('copy') + '</button>' +
					'</div>' +
				'</div>' +
				(portalUrl
					? '<div class="mj-field"><span class="mj-field__label">Dirección de su panel</span>' +
						'<div><a class="mj-link mj-mono" href="' + h(portalUrl) + '" target="_blank" rel="noopener">' + h(portalUrl) + '</a></div></div>'
					: ''),
			footer: '<button type="button" class="mj-btn mj-btn--primary" data-modal-close>Listo</button>',
			onMount(root) {
				MJ.ui.on(root, 'click', '[data-copy]', function () {
					MJ.ui.copyText(password);
					MJ.ui.toast('Contraseña copiada.', 'success');
				});
			},
		});
	}

	/* ------------------------------------------------------------------ */
	/* consigneeSelect                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Llena un <select> con los revendedores activos.
	 * @param {HTMLElement|string} select
	 * @param {number|string} value
	 * @param {Object} [opts] { placeholder }
	 * @returns {Promise}
	 */
	C.consigneeSelect = function (select, value, opts) {
		opts = opts || {};
		const el = typeof select === 'string' ? qs(select) : select;
		if (!el) return Promise.resolve([]);

		MJ.ui.fillSelect(el, [], '', 'Cargando revendedores…');

		return MJ.api.get('consignees', { with_account: 0 }).then(function (res) {
			const items = (res && Array.isArray(res.items)) ? res.items : [];
			const options = items.map(function (c) { return { value: c.id, label: c.name }; });
			MJ.ui.fillSelect(el, options, value, options.length ? (opts.placeholder || 'Elegí un revendedor…') : 'No hay revendedores activos');
			return items;
		}).catch(function () {
			MJ.ui.fillSelect(el, [], '', 'No se pudieron cargar los revendedores');
			return [];
		});
	};

	/* ------------------------------------------------------------------ */
	/* consignmentCreateModal                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Alta de una entrega (queda en borrador; los productos se cargan en su pantalla).
	 * @param {Object} opts { consigneeId?, onCreated(consignment) }
	 */
	C.consignmentCreateModal = function (opts) {
		opts = opts || {};
		const fid = 'mj-cne-' + Math.random().toString(36).slice(2, 8);
		const today = (MJ.state.bootstrap && MJ.state.bootstrap.today) || MJ.fmt.today();

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-cg">Revendedor *</label>' +
					'<select class="mj-select" id="' + fid + '-cg" name="consignee_id" required></select>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha de entrega</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="delivered_at" type="date" value="' + h(today) + '">' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-code">Código</label>' +
						'<input class="mj-input mj-mono" id="' + fid + '-code" name="code" type="text" maxlength="60" placeholder="Se genera solo">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-notes">Notas</label>' +
					'<textarea class="mj-input mj-textarea" id="' + fid + '-notes" name="notes" rows="2" maxlength="2000"></textarea>' +
				'</div>' +
				MJ.ui.alert('La entrega se crea en borrador. Cargá los productos y el precio acordado, y después marcala como entregada.', 'info') +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('plus') + '<span>Crear entrega</span></button>';

		return MJ.ui.modal({
			title: 'Nueva entrega',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				let submitting = false;

				C.consigneeSelect(qs('[name="consignee_id"]', form), opts.consigneeId || '');

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);

					const data = MJ.ui.formData(form);
					const consigneeId = parseInt(data.consignee_id, 10) || 0;
					if (!consigneeId) { MJ.ui.showErrors(form, { consignee_id: 'Elegí un revendedor.' }); return; }

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.post('consignments', {
						consignee_id: consigneeId,
						delivered_at: String(data.delivered_at || ''),
						code: String(data.code || '').trim(),
						notes: String(data.notes || ''),
					}).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Entrega creada.', 'success');
						modal.close();
						if (typeof opts.onCreated === 'function') opts.onCreated(res);
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
	/* saleModal                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Registrar una venta del revendedor. El precio sale del acordado en la entrega.
	 * @param {Object} opts { consigneeId, stock: [], item?, onSaved(res) }
	 */
	C.saleModal = function (opts) {
		opts = opts || {};
		const fid = 'mj-sale-' + Math.random().toString(36).slice(2, 8);
		const stock = Array.isArray(opts.stock) ? opts.stock.filter(function (r) { return f(r.qty_on_hand) > 0; }) : [];
		const today = (MJ.state.bootstrap && MJ.state.bootstrap.today) || MJ.fmt.today();

		if (!stock.length) {
			MJ.ui.modal({
				title: 'Registrar venta',
				size: 'sm',
				body: MJ.ui.empty({
					icon: 'inbox',
					title: 'El revendedor no tiene stock',
					text: 'Para registrar una venta hace falta una entrega en estado entregada con unidades sin vender.',
				}),
				footer: '<button type="button" class="mj-btn mj-btn--primary" data-modal-close>Entendido</button>',
			});
			return null;
		}

		const preselect = opts.item && opts.item.id ? parseInt(opts.item.id, 10) : parseInt(stock[0].id, 10);

		const options = stock.map(function (r) {
			return {
				value: r.id,
				label: r.name + (r.sku ? ' · ' + r.sku : '') + ' — ' + MJ.fmt.qty(r.qty_on_hand) + ' u. a ' + moneyBase(r.unit_price_base),
			};
		});

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-item">Producto vendido *</label>' +
					'<select class="mj-select" id="' + fid + '-item" name="consignment_item_id" required>' + optionsHtml(options, preselect) + '</select>' +
					'<div class="mj-field__hint" data-region="item-hint"></div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-qty">Cantidad *</label>' +
						'<input class="mj-input" id="' + fid + '-qty" name="qty" type="text" inputmode="decimal" data-type="number" value="1" required>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha de la venta</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="sale_date" type="date" value="' + h(today) + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-price">Precio acordado por unidad (' + h(baseCur()) + ')</label>' +
					'<input class="mj-input" id="' + fid + '-price" name="unit_price_base" type="text" inputmode="decimal" data-type="number" value="">' +
					'<div class="mj-field__hint">Vacío = el precio de la entrega. Cambialo solo si acordaste otra cosa por esta venta.</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-retail">A cuánto lo vendió (opcional)</label>' +
					'<div class="mj-input-group">' +
						'<input class="mj-input" id="' + fid + '-retail" name="retail_amount" type="text" inputmode="decimal" data-type="number" placeholder="0,00">' +
						'<select class="mj-select mj-select--sm" name="retail_currency">' +
							optionsHtml(currencies().map(function (c) { return { value: c, label: c }; }), localCur()) +
						'</select>' +
					'</div>' +
					'<div class="mj-field__hint">Solo informativo: no cambia lo que te debe.</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-note">Nota</label>' +
					'<input class="mj-input" id="' + fid + '-note" name="note" type="text" maxlength="255">' +
				'</div>' +
				'<div class="mj-summary" data-region="summary"></div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('check') + '<span>Registrar venta</span></button>';

		return MJ.ui.modal({
			title: 'Registrar venta del revendedor',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				const itemSel = qs('[name="consignment_item_id"]', form);
				const qtyInput = qs('[name="qty"]', form);
				const priceInput = qs('[name="unit_price_base"]', form);
				let submitting = false;

				function selectedItem() {
					const id = parseInt(itemSel.value, 10) || 0;
					return stock.find(function (r) { return parseInt(r.id, 10) === id; }) || null;
				}

				function refresh() {
					const item = selectedItem();
					const hint = qs('[data-region="item-hint"]', form);
					const summary = qs('[data-region="summary"]', form);

					if (!item) {
						if (hint) hint.textContent = '';
						if (summary) summary.innerHTML = '';
						return;
					}

					if (hint) {
						hint.textContent = 'Entrega ' + (item.consignment_code || '') + ' · quedan ' +
							MJ.fmt.qty(item.qty_on_hand) + ' de ' + MJ.fmt.qty(item.qty) + ' unidades.';
					}

					const qty = f(MJ.fmt.parse(qtyInput.value));
					const price = priceInput.value.trim() !== '' ? f(MJ.fmt.parse(priceInput.value)) : f(item.unit_price_base);
					const total = MJ.fmt.round(qty * price, 4);

					if (summary) {
						summary.innerHTML =
							'<div class="mj-stat-list">' +
								'<div class="mj-stat-list__row"><span>Precio por unidad</span><span class="mj-num">' + h(moneyBase(price)) + '</span></div>' +
								'<div class="mj-stat-list__row mj-stat-list__row--total"><span>Te va a deber</span>' +
									'<span class="mj-num mj-money mj-strong">' + h(moneyBase(total)) + '</span></div>' +
							'</div>';
					}
				}

				MJ.ui.on(form, 'change', '[name="consignment_item_id"]', refresh);
				MJ.ui.on(form, 'input', '[name="qty"]', refresh);
				MJ.ui.on(form, 'input', '[name="unit_price_base"]', refresh);
				refresh();

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);

					const data = MJ.ui.formData(form);
					const item = selectedItem();
					const errors = {};

					if (!item) errors.consignment_item_id = 'Elegí un producto.';
					const qty = typeof data.qty === 'number' ? data.qty : MJ.fmt.parse(data.qty);
					if (qty === null || isNaN(qty) || qty <= 0) errors.qty = 'La cantidad tiene que ser mayor a cero.';
					else if (item && qty > f(item.qty_on_hand) + EPSILON) errors.qty = 'Solo quedan ' + MJ.fmt.qty(item.qty_on_hand) + ' unidades.';

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = {
						consignment_item_id: parseInt(item.id, 10),
						qty: qty,
						sale_date: String(data.sale_date || ''),
						note: String(data.note || '').trim(),
					};
					if (data.unit_price_base !== null && data.unit_price_base !== undefined && String(data.unit_price_base) !== '') {
						payload.unit_price_base = data.unit_price_base;
					}
					if (data.retail_amount !== null && data.retail_amount !== undefined && String(data.retail_amount) !== '') {
						payload.retail_amount = data.retail_amount;
						payload.retail_currency = String(data.retail_currency || localCur());
					}

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.post('consignment-sales', payload).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						const sale = res && res.sale ? res.sale : null;
						MJ.ui.toast(
							sale && sale.status === 'pendiente' ? 'Venta registrada, queda pendiente de confirmación.' : 'Venta registrada.',
							'success'
						);
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
	/* returnModal                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Registrar una devolución: el revendedor trae de vuelta lo que no vendió.
	 * @param {Object} opts { stock: [], item?, onSaved(res) }
	 */
	C.returnModal = function (opts) {
		opts = opts || {};
		const fid = 'mj-ret-' + Math.random().toString(36).slice(2, 8);
		const stock = Array.isArray(opts.stock) ? opts.stock.filter(function (r) { return f(r.qty_on_hand) > 0; }) : [];
		const today = (MJ.state.bootstrap && MJ.state.bootstrap.today) || MJ.fmt.today();

		if (!stock.length) {
			MJ.ui.toast('El revendedor no tiene unidades para devolver.', 'warning');
			return null;
		}

		const preselect = opts.item && opts.item.id ? parseInt(opts.item.id, 10) : parseInt(stock[0].id, 10);
		const options = stock.map(function (r) {
			return { value: r.id, label: r.name + (r.sku ? ' · ' + r.sku : '') + ' — ' + MJ.fmt.qty(r.qty_on_hand) + ' u.' };
		});

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-item">Producto devuelto *</label>' +
					'<select class="mj-select" id="' + fid + '-item" name="consignment_item_id" required>' + optionsHtml(options, preselect) + '</select>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-qty">Cantidad *</label>' +
						'<input class="mj-input" id="' + fid + '-qty" name="qty" type="text" inputmode="decimal" data-type="number" value="1" required>' +
					'</div>' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="return_date" type="date" value="' + h(today) + '">' +
					'</div>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="' + fid + '-note">Nota</label>' +
					'<input class="mj-input" id="' + fid + '-note" name="note" type="text" maxlength="255" placeholder="Ej: no se vendió, packaging dañado…">' +
				'</div>' +
				MJ.ui.alert('La devolución libera el compromiso: esas unidades vuelven a estar disponibles para vender por la web. El stock de WooCommerce no cambia, porque nunca había salido.', 'info') +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('undo') + '<span>Registrar devolución</span></button>';

		return MJ.ui.modal({
			title: 'Registrar devolución',
			size: 'md',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				const itemSel = qs('[name="consignment_item_id"]', form);
				let submitting = false;

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);

					const data = MJ.ui.formData(form);
					const id = parseInt(itemSel.value, 10) || 0;
					const item = stock.find(function (r) { return parseInt(r.id, 10) === id; });
					const errors = {};

					if (!item) errors.consignment_item_id = 'Elegí un producto.';
					const qty = typeof data.qty === 'number' ? data.qty : MJ.fmt.parse(data.qty);
					if (qty === null || isNaN(qty) || qty <= 0) errors.qty = 'La cantidad tiene que ser mayor a cero.';
					else if (item && qty > f(item.qty_on_hand) + EPSILON) errors.qty = 'Solo tiene ' + MJ.fmt.qty(item.qty_on_hand) + ' unidades.';

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.post('consignment-returns', {
						consignment_item_id: id,
						qty: qty,
						return_date: String(data.return_date || ''),
						note: String(data.note || '').trim(),
					}).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						MJ.ui.toast('Devolución registrada.', 'success');
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
	/* paymentModal                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Registrar un pago (o un ajuste manual) e imputarlo a ventas concretas.
	 *
	 * El reparto se propone de la venta más vieja a la más nueva y se puede editar antes de
	 * confirmar. Un ajuste manual exige motivo: es la única forma de mover un saldo sin una venta
	 * detrás, y tiene que quedar dicho por qué.
	 *
	 * @param {Object} opts { consigneeId, consigneeName?, outstanding: [], account?, onSaved(res) }
	 */
	C.paymentModal = function (opts) {
		opts = opts || {};
		const fid = 'mj-pay-' + Math.random().toString(36).slice(2, 8);
		const today = (MJ.state.bootstrap && MJ.state.bootstrap.today) || MJ.fmt.today();
		const outstanding = Array.isArray(opts.outstanding) ? opts.outstanding.slice() : [];
		const methods = paymentMethods().map(function (m) { return { value: m.key, label: m.label }; });
		const rate = currentRate();
		const dolar = setting('dolar', null);
		const rateFromDolar = dolar && typeof dolar === 'object' && f(dolar.rate) > 0;

		const body =
			'<form class="mj-form" id="' + fid + '" novalidate>' +
				'<div class="mj-field">' +
					'<span class="mj-field__label">Qué estás registrando</span>' +
					'<div class="mj-row mj-row--wrap" role="group">' +
						'<label class="mj-checkbox"><input type="radio" name="kind" value="pago" checked> <span>Un pago que me hizo</span></label>' +
						'<label class="mj-checkbox"><input type="radio" name="kind" value="ajuste"> <span>Un ajuste manual</span></label>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-amount">Importe *</label>' +
						'<div class="mj-input-group">' +
							'<input class="mj-input" id="' + fid + '-amount" name="amount" type="text" inputmode="decimal" data-type="number" required placeholder="0,00">' +
							'<select class="mj-select mj-select--sm" name="currency">' +
								optionsHtml(currencies().map(function (c) { return { value: c, label: c }; }), localCur()) +
							'</select>' +
						'</div>' +
						'<div class="mj-field__hint" data-region="adjust-hint"></div>' +
					'</div>' +
					'<div class="mj-field" data-region="rate-field">' +
						'<label class="mj-field__label" for="' + fid + '-rate">Tipo de cambio</label>' +
						'<input class="mj-input" id="' + fid + '-rate" name="rate" type="text" inputmode="decimal" data-type="number" value="' + h(inputNum(rate, 2)) + '">' +
						'<div class="mj-field__hint">' + h(rateFromDolar ? 'Viene de MJ Dólar. La deuda está en ' + baseCur() + ', así que hace falta para pasar el pago.' : 'La deuda está en ' + baseCur() + ', así que hace falta para pasar el pago.') + '</div>' +
					'</div>' +
				'</div>' +
				'<div class="mj-form-row">' +
					'<div class="mj-field">' +
						'<label class="mj-field__label" for="' + fid + '-date">Fecha</label>' +
						'<input class="mj-input" id="' + fid + '-date" name="payment_date" type="date" value="' + h(today) + '">' +
					'</div>' +
					'<div class="mj-field" data-region="method-field">' +
						'<label class="mj-field__label" for="' + fid + '-method">Cómo me pagó</label>' +
						'<select class="mj-select" id="' + fid + '-method" name="method">' + optionsHtml(methods, 'efectivo') + '</select>' +
					'</div>' +
				'</div>' +
				'<div class="mj-field" data-region="reference-field">' +
					'<label class="mj-field__label" for="' + fid + '-ref">Referencia</label>' +
					'<input class="mj-input" id="' + fid + '-ref" name="reference" type="text" maxlength="190" placeholder="Nº de transferencia, comprobante…">' +
				'</div>' +
				'<div class="mj-field mj-hidden" data-region="reason-field">' +
					'<label class="mj-field__label" for="' + fid + '-reason">Motivo del ajuste *</label>' +
					'<input class="mj-input" id="' + fid + '-reason" name="reason" type="text" maxlength="255" placeholder="Ej: le condono la venta del vaper fallado">' +
					'<div class="mj-field__hint">Obligatorio. Un ajuste mueve el saldo sin una venta detrás, así que tiene que quedar escrito por qué.</div>' +
				'</div>' +
				'<div class="mj-divider"></div>' +
				'<div data-region="allocation">' + allocationHtml(outstanding, 0, {}) + '</div>' +
			'</form>';

		const footer =
			'<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cancelar</button>' +
			'<button type="submit" class="mj-btn mj-btn--primary" form="' + fid + '" data-submit>' + MJ.icon('cash') + '<span>Registrar</span></button>';

		return MJ.ui.modal({
			title: opts.consigneeName ? 'Registrar pago de ' + opts.consigneeName : 'Registrar pago',
			size: 'lg',
			body: body,
			footer: footer,
			onMount(root, modal) {
				const form = qs('#' + fid, root);
				const submitBtn = qs('[data-submit]', root);
				let submitting = false;
				/** sale_id → importe fijado a mano ('' = lo decide el reparto automático). */
				let manual = {};
				let kind = 'pago';

				function amountBase() {
					const data = MJ.ui.formData(form);
					const amount = f(typeof data.amount === 'number' ? data.amount : MJ.fmt.parse(data.amount));
					const currency = String(data.currency || localCur()).toUpperCase();
					if (currency === baseCur()) return MJ.fmt.round(amount, 4);
					const r = f(typeof data.rate === 'number' ? data.rate : MJ.fmt.parse(data.rate));
					return r > 0 ? MJ.fmt.round(amount / r, 4) : 0;
				}

				function paintAllocation() {
					const el = qs('[data-region="allocation"]', form);
					if (!el) return;
					el.innerHTML = allocationHtml(outstanding, amountBase(), manual, kind);
				}

				function setKind(value) {
					kind = value === 'ajuste' ? 'ajuste' : 'pago';
					const reason = qs('[data-region="reason-field"]', form);
					const method = qs('[data-region="method-field"]', form);
					const reference = qs('[data-region="reference-field"]', form);
					const hint = qs('[data-region="adjust-hint"]', form);
					if (reason) reason.classList.toggle('mj-hidden', kind !== 'ajuste');
					if (method) method.classList.toggle('mj-hidden', kind === 'ajuste');
					if (reference) reference.classList.toggle('mj-hidden', kind === 'ajuste');
					if (hint) {
						hint.textContent = kind === 'ajuste'
							? 'Positivo le baja la deuda, negativo se la aumenta.'
							: '';
					}
					paintAllocation();
				}

				MJ.ui.on(form, 'change', '[name="kind"]', function (e, el) { setKind(el.value); });
				MJ.ui.on(form, 'input', '[name="amount"], [name="rate"]', paintAllocation);
				MJ.ui.on(form, 'change', '[name="currency"]', function () {
					const data = MJ.ui.formData(form);
					const rateField = qs('[data-region="rate-field"]', form);
					// Pagando en la misma moneda que la deuda, el TC no juega.
					if (rateField) rateField.classList.toggle('mj-hidden', String(data.currency || '').toUpperCase() === baseCur());
					paintAllocation();
				});

				MJ.ui.on(form, 'input', '[data-alloc-amount]', function (e, el) {
					const saleId = parseInt(el.getAttribute('data-alloc-amount'), 10) || 0;
					if (!saleId) return;
					const raw = String(el.value || '').trim();
					if (raw === '') delete manual[saleId];
					else manual[saleId] = f(MJ.fmt.parse(raw));
					paintAllocationTotals();
				});

				MJ.ui.on(form, 'click', '[data-alloc-reset]', function () {
					manual = {};
					paintAllocation();
				});

				function paintAllocationTotals() {
					// Solo los totales: repintar todo mientras se escribe perdería el foco.
					const plan = currentPlan();
					const el = qs('[data-region="alloc-totals"]', form);
					if (el) el.innerHTML = allocationTotalsHtml(plan, amountBase(), kind);
				}

				function currentPlan() {
					const base = amountBase();
					if (kind === 'ajuste') {
						return { lines: [], allocated: 0, unallocated: 0, manualUsed: false };
					}
					const keys = Object.keys(manual);
					if (!keys.length) {
						const auto = MJ.consign.allocate(outstanding, base);
						auto.manualUsed = false;
						return auto;
					}
					// Con algún importe fijado a mano, manda exactamente lo que se escribió.
					const lines = [];
					let allocated = 0;
					keys.forEach(function (key) {
						const amount = MJ.fmt.round(f(manual[key]), 4);
						if (amount <= EPSILON) return;
						const sale = outstanding.find(function (s) { return parseInt(s.id, 10) === parseInt(key, 10); });
						lines.push({ sale_id: parseInt(key, 10), amount_base: amount, sale: sale });
						allocated += amount;
					});
					return {
						lines: lines,
						allocated: MJ.fmt.round(allocated, 4),
						unallocated: MJ.fmt.round(Math.max(0, base - allocated), 4),
						manualUsed: true,
					};
				}

				setKind('pago');

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (submitting) return;
					clearFormErrors(form);

					const data = MJ.ui.formData(form);
					const errors = {};
					const amount = typeof data.amount === 'number' ? data.amount : MJ.fmt.parse(data.amount);

					if (amount === null || isNaN(amount)) {
						errors.amount = 'Poné un importe.';
					} else if (kind === 'pago' && amount <= 0) {
						errors.amount = 'El importe del pago tiene que ser mayor a cero.';
					} else if (kind === 'ajuste' && amount === 0) {
						errors.amount = 'El importe del ajuste no puede ser cero.';
					}

					if (kind === 'ajuste' && !String(data.reason || '').trim()) {
						errors.reason = 'Un ajuste manual necesita un motivo.';
					}

					const currency = String(data.currency || localCur()).toUpperCase();
					if (currency !== baseCur()) {
						const r = typeof data.rate === 'number' ? data.rate : MJ.fmt.parse(data.rate);
						if (r === null || isNaN(r) || r <= 0) errors.rate = 'Poné el tipo de cambio.';
					}

					const plan = currentPlan();
					if (kind === 'pago' && plan.allocated > amountBase() + EPSILON) {
						errors.amount = 'Estás imputando más de lo que entra.';
					}

					if (Object.keys(errors).length) { MJ.ui.showErrors(form, errors); return; }

					const payload = {
						consignee_id: parseInt(opts.consigneeId, 10) || 0,
						kind: kind,
						amount: amount,
						currency: currency,
						rate: data.rate === null || data.rate === undefined ? '' : data.rate,
						payment_date: String(data.payment_date || ''),
						note: '',
					};
					if (kind === 'ajuste') {
						payload.reason = String(data.reason || '').trim();
					} else {
						payload.method = String(data.method || 'otro');
						payload.reference = String(data.reference || '').trim();
					}
					// Solo se mandan imputaciones explícitas si se tocó el reparto: si no, decide el servidor.
					if (plan.manualUsed) {
						payload.allocations = plan.lines.map(function (line) {
							return { sale_id: line.sale_id, amount_base: line.amount_base };
						});
					}

					submitting = true;
					MJ.ui.setLoading(submitBtn, true);

					MJ.api.post('consignment-payments', payload).then(function (res) {
						submitting = false;
						MJ.ui.setLoading(submitBtn, false);
						const payment = res && res.payment ? res.payment : null;
						let message = kind === 'ajuste' ? 'Ajuste registrado.' : 'Pago registrado.';
						if (payment && f(payment.unallocated_base) >= 0.005) {
							message += ' Quedaron ' + moneyBase(payment.unallocated_base) + ' a favor.';
						}
						MJ.ui.toast(message, 'success');
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

	/**
	 * Tabla del reparto del pago: qué venta paga y cuánto.
	 *
	 * @param {Array} outstanding Ventas impagas.
	 * @param {number} amountBase Importe del pago en moneda base.
	 * @param {Object} manual     Importes fijados a mano.
	 * @param {string} kind       pago | ajuste.
	 * @returns {string}
	 */
	function allocationHtml(outstanding, amountBase, manual, kind) {
		if (kind === 'ajuste') {
			return '<div class="mj-note mj-small mj-muted">' + MJ.icon('info') +
				'<span>Un ajuste manual no se reparte entre ventas: mueve el saldo directo, con el motivo que escribas.</span></div>';
		}

		if (!outstanding.length) {
			return '<div class="mj-note mj-small mj-muted">' + MJ.icon('info') +
				'<span>El revendedor no tiene ventas impagas. El pago va a quedar como saldo a favor y se aplica solo en la próxima venta que registres.</span></div>';
		}

		const keys = Object.keys(manual || {});
		const plan = keys.length
			? { lines: keys.map(function (k) { return { sale_id: parseInt(k, 10), amount_base: f(manual[k]) }; }) }
			: MJ.consign.allocate(outstanding, amountBase);

		const byId = {};
		plan.lines.forEach(function (line) { byId[line.sale_id] = line.amount_base; });

		const rows = outstanding.map(function (sale) {
			const assigned = byId[sale.id];
			const isManual = Object.prototype.hasOwnProperty.call(manual || {}, sale.id);
			return '<tr>' +
				'<td>' +
					'<div class="mj-strong">' + h(sale.name || ('Venta #' + sale.id)) + '</div>' +
					'<div class="mj-small mj-muted">' + h(MJ.fmt.date(sale.sale_date)) + ' · ' + h(MJ.fmt.qty(sale.qty)) + ' u. · #' + h(sale.id) + '</div>' +
				'</td>' +
				'<td class="is-num">' + h(moneyBase(sale.amount_base)) + '</td>' +
				'<td class="is-num">' + h(moneyBase(sale.balance_base)) + '</td>' +
				'<td class="is-num">' +
					'<input class="mj-input mj-input--sm" type="text" inputmode="decimal" data-alloc-amount="' + h(sale.id) + '" ' +
						'value="' + h(isManual ? inputNum(manual[sale.id], 2) : (assigned ? inputNum(assigned, 2) : '')) + '" ' +
						'placeholder="0" aria-label="' + h('Imputar a la venta ' + (sale.name || sale.id)) + '">' +
				'</td>' +
			'</tr>';
		}).join('');

		return '<div class="mj-row mj-row--between mj-row--center">' +
				'<span class="mj-field__label">A qué ventas va este pago</span>' +
				(keys.length ? '<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-alloc-reset>' + MJ.icon('refresh') + '<span>Volver al automático</span></button>' : '') +
			'</div>' +
			'<div class="mj-field__hint mj-mb">Se propone de la venta más vieja a la más nueva. Podés cambiar los importes si el revendedor te dijo que está pagando algo puntual.</div>' +
			'<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
				'<th>Venta</th><th class="is-num">Total</th><th class="is-num">Debe</th><th class="is-num">Se imputa</th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table></div>' +
			'<div data-region="alloc-totals">' + allocationTotalsHtml(plan.lines ? { lines: plan.lines, allocated: plan.lines.reduce(function (a, l) { return a + f(l.amount_base); }, 0), unallocated: 0 } : plan, amountBase, kind) + '</div>';
	}

	/**
	 * Pie del reparto: cuánto se imputa y cuánto queda a favor.
	 *
	 * @param {Object} plan       Reparto actual.
	 * @param {number} amountBase Importe del pago.
	 * @param {string} kind       pago | ajuste.
	 * @returns {string}
	 */
	function allocationTotalsHtml(plan, amountBase, kind) {
		if (kind === 'ajuste') return '';
		const allocated = MJ.fmt.round(f(plan && plan.allocated), 4);
		const total = MJ.fmt.round(f(amountBase), 4);
		const left = MJ.fmt.round(Math.max(0, total - allocated), 4);
		const over = allocated > total + EPSILON;

		let rows = '<div class="mj-stat-list mj-mt">' +
			'<div class="mj-stat-list__row"><span>Pago en ' + h(baseCur()) + '</span><span class="mj-num">' + h(moneyBase(total)) + '</span></div>' +
			'<div class="mj-stat-list__row"><span>Se imputa a ventas</span><span class="mj-num">' + h(moneyBase(allocated)) + '</span></div>';

		if (over) {
			rows += '<div class="mj-stat-list__row mj-danger-text"><span>Estás imputando de más</span>' +
				'<span class="mj-num mj-strong">' + h(moneyBase(allocated - total)) + '</span></div>';
		} else if (left >= 0.005) {
			rows += '<div class="mj-stat-list__row"><span>Queda a favor</span>' +
				'<span class="mj-num mj-strong">' + h(moneyBase(left)) + '</span></div>';
		}
		rows += '</div>';

		if (!over && left >= 0.005) {
			rows += '<div class="mj-note mj-small mj-muted">' + MJ.icon('info') +
				'<span>El sobrante queda como saldo a favor y se aplica solo a la próxima venta que registres.</span></div>';
		}

		return rows;
	}
})();
