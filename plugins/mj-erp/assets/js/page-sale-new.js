/**
 * MJ ERP — page-sale-new.js
 * MJ.pages.saleNew: cargar una venta.
 *
 * Dos formas de la misma pantalla, según el ancho:
 *
 *   - **Mostrador** (compu): el buscador arriba, el catálogo a la izquierda y el carrito fijo a la
 *     derecha. Con pantalla grande se ve todo junto y se carga una venta sin scrollear.
 *   - **Paso a paso** (celular): productos → cantidades → cliente → pago → entrega, con los
 *     botones de Atrás y Siguiente fijos abajo. En una mano y con el cliente enfrente, dos
 *     columnas no entran: lo que sirve es una decisión por pantalla y el pulgar cerca del botón.
 *
 * El ancho que separa una de otra se configura (Configuración → Ventas → Cargar una venta).
 * Cambiar de forma no pierde nada: la venta que se está cargando es la misma.
 *
 * Todo lo que define la venta —cliente, canal, lista de precios, fecha, entrega y cobro— se decide
 * en el carrito, junto al total: son decisiones sobre la venta, no sobre el producto que se está
 * eligiendo, y así no hay que volver arriba para tocarlas.
 *
 * Cada cambio le pregunta al servidor (POST /sales/preview) cómo queda la venta, así el reparto
 * entre depósito y consignación se ve antes de confirmar y no después.
 *
 * REST: GET /products, GET /products/categories, GET /products/search, POST /sales/preview,
 * POST /sales, GET /sales/customer/{id}, GET /customers/search.
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
	const SEARCH_DELAY = 300;

	function info() { return (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {}; }
	function defaults() { return info().defaults || {}; }
	function currency() { return info().currency || MJ.config.wcCurrency || MJ.setting('local_currency', 'ARS'); }
	function money(v) { return MJ.fmt.money(v, currency()); }
	function baseCur() { return MJ.setting('base_currency', 'USD'); }
	function num(v) { return MJ.fmt.toNumber(v); }

	/* Catálogo: tamaño de página y fotos salen de Configuración → Ventas. */
	function perPage() {
		const n = parseInt(MJ.setting('sales_pos_per_page', 24), 10) || 24;
		return Math.max(6, Math.min(96, n));
	}
	function showImages() { return MJ.setting('sales_pos_images', true) !== false; }

	/** Ancho (px) por debajo del cual se carga en pasos. 0 = nunca. */
	function stepsWidth() {
		const n = parseInt(MJ.setting('sales_pos_steps_width', 900), 10);
		return isNaN(n) ? 900 : Math.max(0, Math.min(1600, n));
	}

	function customersEnabled() { return MJ.setting('customers_enabled', true) !== false; }
	function canCreateCustomer() { return customersEnabled() && MJ.setting('customers_quick_create', true) !== false; }

	/**
	 * Los pasos del celular.
	 *
	 * Salen de lo que la tienda tenga encendido: sin libreta de clientes no hay paso de cliente, y
	 * sin entregas no hay paso de entrega. Nadie pasa por una pantalla que no usa.
	 */
	function stepList() {
		return [
			{ key: 'items', label: 'Productos', icon: 'box' },
			{ key: 'lines', label: 'Cantidades', icon: 'list' },
			{ key: 'customer', label: 'Cliente', icon: 'user', on: customersEnabled },
			{ key: 'payment', label: 'Pago', icon: 'cash' },
			{ key: 'delivery', label: 'Entrega', icon: 'truck', on: function () { return MJ.components.saleHelpers.deliveryEnabled(); } },
		].filter(function (step) { return !step.on || step.on(); });
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	/** Identidad de una línea: un producto simple o una variación concreta. */
	function keyOf(productId, variationId) {
		return String(productId || 0) + ':' + String(variationId || 0);
	}

	/**
	 * Ids de venta de un producto del catálogo.
	 *
	 * El resumen del servidor ya trae el padre y la variación separados; el buscador viejo usaba
	 * `parent_id`, así que se aceptan los dos nombres.
	 */
	function idsOf(product) {
		const variationId = num(product.variation_id) || (product.is_variation ? num(product.id) : 0);
		const productId = num(product.product_id) ||
			(product.is_variation ? num(product.parent_id) : num(product.id)) || num(product.id);
		return { productId: productId, variationId: variationId || 0 };
	}

	/* Un producto variable no se vende: se venden sus variaciones, que vienen en la misma lista. */
	function sellable(product) {
		return product && product.type !== 'variable';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — estructura                                                   */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Nueva venta</h2>' +
				'<div class="mj-page-header__subtitle mj-muted">Elegí los productos del catálogo y cerrá la venta desde el carrito.</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link(PATH)) + '">' + MJ.icon('arrow-left') + '<span>Volver</span></a>' +
			'</div>' +
		'</div>';
	}

	function shellHtml(state) {
		return '<div class="mj-pos">' +
			'<section class="mj-pos__catalog" aria-label="Catálogo">' +
				searchHtml(state) +
				'<div class="mj-pos__cats" data-region="categories"></div>' +
				'<div class="mj-pos__grid" data-region="catalog"></div>' +
				'<div class="mj-pos__more" data-region="catalog-more"></div>' +
			'</section>' +

			'<aside class="mj-pos__cart" aria-label="Carrito">' +
				'<div class="mj-card mj-pos__cart-card">' +
					'<div class="mj-pos__cart-scroll">' +
						'<div data-region="cart-settings"></div>' +
						'<div class="mj-pos__lines" data-region="cart-lines"></div>' +
						'<div data-region="cart-notice"></div>' +
						'<div data-region="cart-extras"></div>' +
						'<div data-region="cart-totals"></div>' +
						'<div data-region="cart-delivery"></div>' +
						'<div data-region="cart-payment"></div>' +
					'</div>' +
					'<div class="mj-pos__cart-foot" data-region="cart-actions"></div>' +
				'</div>' +
			'</aside>' +
		'</div>';
	}

	/* El buscador de productos, que en los dos modos es el mismo. */
	function searchHtml(state) {
		return '<div class="mj-pos__search">' +
			'<div class="mj-input-group">' +
				'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
				'<input class="mj-input" id="mj-sale-product" type="search" placeholder="Buscar por nombre o SKU… (Enter agrega)" autocomplete="off" aria-label="Buscar productos">' +
			'</div>' +
			'<label class="mj-checkbox mj-pos__stock-filter"><input type="checkbox" data-field="only_stock"' + (state.catalog.onlyStock ? ' checked' : '') + '> <span>Solo con stock</span></label>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — paso a paso (celular)                                        */
	/* ------------------------------------------------------------------ */

	function stepsShellHtml() {
		return '<div class="mj-steps">' +
			'<div class="mj-steps__head" data-region="steps-head"></div>' +
			'<div class="mj-steps__body" data-region="step-body"></div>' +
			'<div class="mj-steps__foot" data-region="steps-foot"></div>' +
		'</div>';
	}

	function stepsHeadHtml(state) {
		const steps = stepList();
		const index = Math.min(state.step, steps.length - 1);
		const current = steps[index];

		return '<div class="mj-steps__dots" role="tablist" aria-label="Pasos de la venta">' +
			steps.map(function (step, i) {
				const cls = i === index ? ' is-current' : (i < index ? ' is-done' : '');
				return '<button type="button" class="mj-steps__dot' + cls + '" data-step="' + i + '"' +
					' role="tab" aria-selected="' + (i === index ? 'true' : 'false') + '" title="' + h(step.label) + '">' +
					'<span class="mj-steps__dot-num">' + (i < index ? MJ.icon('check') : (i + 1)) + '</span>' +
				'</button>';
			}).join('') +
		'</div>' +
		'<div class="mj-steps__title">' +
			'<span class="mj-strong">' + h(current ? current.label : '') + '</span>' +
			'<span class="mj-small mj-muted">Paso ' + (index + 1) + ' de ' + steps.length + '</span>' +
		'</div>';
	}

	function stepsFootHtml(state) {
		const steps = stepList();
		const index = Math.min(state.step, steps.length - 1);
		const last = index >= steps.length - 1;
		const units = state.lines.reduce(function (acc, l) { return acc + (num(l.qty) || 0); }, 0);
		const totals = (state.preview && state.preview.totals) || null;

		return '<button type="button" class="mj-btn mj-btn--ghost mj-steps__back" data-action="step-prev"' + (index === 0 ? ' disabled' : '') + '>' +
				MJ.icon('chevron-left') + '<span>Atrás</span>' +
			'</button>' +
			'<div class="mj-steps__summary mj-small">' +
				'<span class="mj-muted">' + h(MJ.fmt.qty(units)) + (units === 1 ? ' ítem' : ' ítems') + '</span>' +
				'<span class="mj-strong">' + h(totals ? money(totals.total) : money(0)) + '</span>' +
			'</div>' +
			(last
				? '<button type="button" class="mj-btn mj-btn--primary mj-steps__next" data-action="confirm"' + (state.lines.length ? '' : ' disabled') + '>' +
					MJ.icon('check') + '<span data-region="confirm-label">Confirmar</span>' +
				'</button>'
				: '<button type="button" class="mj-btn mj-btn--primary mj-steps__next" data-action="step-next">' +
					'<span>Siguiente</span>' + MJ.icon('chevron-right') +
				'</button>');
	}

	/** El cuerpo del paso: las mismas piezas del mostrador, una por pantalla. */
	function stepBodyHtml(state) {
		const steps = stepList();
		const step = steps[Math.min(state.step, steps.length - 1)];
		if (!step) return '';

		switch (step.key) {
			case 'items':
				return searchHtml(state) +
					'<div class="mj-pos__cats" data-region="categories"></div>' +
					'<div class="mj-pos__grid" data-region="catalog"></div>' +
					'<div class="mj-pos__more" data-region="catalog-more"></div>';

			case 'lines':
				return '<div class="mj-pos__lines" data-region="cart-lines"></div>' +
					'<div data-region="cart-notice"></div>' +
					'<div data-region="cart-extras"></div>' +
					'<div data-region="cart-totals"></div>';

			case 'customer':
				return customerStepHtml(state);

			case 'payment':
				return '<div data-region="cart-payment"></div>';

			case 'delivery':
				return '<div data-region="cart-delivery"></div>' +
					saleSummaryHtml(state);

			default:
				return '';
		}
	}

	/**
	 * Paso del cliente: elegir, crear o dejarla sin cliente.
	 *
	 * Las tres son decisiones válidas y las tres están a la vista; lo que no se puede es dejar una
	 * deuda sin dueño, y eso lo dice el paso del pago cuando corresponde.
	 */
	function customerStepHtml(state) {
		const chosen = state.customer;

		return '<div class="mj-step-block">' +
			(chosen
				? '<div class="mj-card mj-chosen-customer"><div class="mj-card__body">' +
						'<div class="mj-row mj-row--center">' +
							'<div>' +
								'<div class="mj-strong">' + h(chosen.name || state.customerName || 'Cliente') + '</div>' +
								'<div class="mj-small mj-muted">' + h([chosen.email, chosen.phone].filter(Boolean).join(' · ') || (customerCardId(state) ? 'Ficha del ERP' : 'Cuenta de la tienda')) + '</div>' +
							'</div>' +
							'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost" data-action="clear-customer">' + MJ.icon('x') + '<span>Quitar</span></button>' +
						'</div>' +
						'<div data-region="customer-note"></div>' +
					'</div></div>'
				: '<div class="mj-field">' +
						'<label class="mj-field__label" for="mj-sale-customer">Buscar cliente</label>' +
						'<input class="mj-input" id="mj-sale-customer" type="search" placeholder="Nombre, teléfono o email…" value="' + h(state.customerName) + '" autocomplete="off">' +
					'</div>' +
					'<div class="mj-step-actions">' +
						(canCreateCustomer()
							? '<button type="button" class="mj-btn mj-btn--block" data-action="new-customer">' + MJ.icon('plus') + '<span>Cliente nuevo</span></button>'
							: '') +
						'<button type="button" class="mj-btn mj-btn--ghost mj-btn--block" data-action="no-customer">' + MJ.icon('user') + '<span>Seguir sin cliente</span></button>' +
					'</div>' +
					'<div class="mj-field__hint mj-small mj-muted">Sin cliente la venta tiene que quedar cobrada: una deuda necesita saber de quién es.</div>' +
					'<div data-region="customer-note"></div>') +
		'</div>' +
		'<div class="mj-step-block">' + saleFieldsHtml(state) + '</div>';
	}

	/** Canal, lista de precios y fecha: los datos de la venta que no son el cliente. */
	function saleFieldsHtml(state) {
		return '<div class="mj-pos__settings-row">' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-channel">Canal</label>' +
				'<select class="mj-select" id="mj-sale-channel" data-field="channel"></select>' +
			'</div>' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-list">Lista de precios</label>' +
				'<select class="mj-select" id="mj-sale-list" data-field="price_list_key"></select>' +
			'</div>' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-date">Fecha</label>' +
				'<input class="mj-input" id="mj-sale-date" type="date" data-field="sale_date" value="' + h(state.date) + '">' +
			'</div>' +
		'</div>';
	}

	/** Resumen final, antes de confirmar. */
	function saleSummaryHtml(state) {
		const totals = (state.preview && state.preview.totals) || null;
		const helpers = MJ.components.saleHelpers;
		const rows = [];

		rows.push(['Productos', MJ.fmt.qty(state.lines.reduce(function (a, l) { return a + (num(l.qty) || 0); }, 0)) + ' u.']);
		if (totals) rows.push(['Total', money(totals.total)]);
		rows.push(['Cliente', state.customer ? h(state.customer.name || state.customerName) : '<span class="mj-muted">Sin cliente</span>']);

		const collectable = totals ? (num(totals.collectable) || 0) : 0;
		const paying = state.charge ? (String(state.paymentAmount).trim() === '' ? collectable : (num(state.paymentAmount) || 0)) : 0;
		const due = Math.max(0, collectable - paying);
		rows.push(['Cobro', state.charge
			? (due > 0.004 ? 'Parcial · queda debiendo ' + money(due) : 'Cobrada')
			: (collectable > 0.004 ? '<span class="mj-warning-text">Queda debiendo ' + money(collectable) + '</span>' : 'Sin saldo')]);

		if (helpers.deliveryEnabled()) {
			const label = (helpers.deliveryMethods().find(function (m) { return String(m.key) === String(state.deliveryMethod); }) || {}).label;
			rows.push(['Entrega', label ? h(label) : '<span class="mj-muted">Sin definir</span>']);
		}

		return '<div class="mj-card mj-mt"><div class="mj-card__header"><h3 class="mj-card__title">Resumen</h3></div>' +
			'<div class="mj-card__body"><dl class="mj-deflist">' +
				rows.map(function (r) {
					return '<div class="mj-deflist__row"><dt>' + h(r[0]) + '</dt><dd>' + r[1] + '</dd></div>';
				}).join('') +
			'</dl>' +
			'<div class="mj-field mj-mt">' +
				'<label class="mj-field__label" for="mj-sale-note-steps">Nota</label>' +
				'<textarea class="mj-textarea" id="mj-sale-note-steps" rows="2" data-field="note" placeholder="Opcional">' + h(state.note) + '</textarea>' +
			'</div>' +
			'<label class="mj-checkbox"><input type="checkbox" data-field="create_order"' + (state.createOrder ? ' checked' : '') + '> <span>Emitir el pedido en WooCommerce</span></label>' +
			'<div class="mj-field__hint mj-small mj-muted" data-region="commit-hint">' + commitHintHtml(state) + '</div>' +
			'</div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — catálogo                                                     */
	/* ------------------------------------------------------------------ */

	function categoriesHtml(state) {
		const cats = state.catalog.categories;
		if (!cats.length) return '';

		const chip = function (value, label, count) {
			const active = String(state.catalog.category) === String(value);
			return '<button type="button" class="mj-pos-chip' + (active ? ' is-active' : '') + '" data-category="' + h(value) + '"' +
				(active ? ' aria-pressed="true"' : ' aria-pressed="false"') + '>' +
				h(label) + (count ? ' <span class="mj-pos-chip__count">' + h(count) + '</span>' : '') +
			'</button>';
		};

		return chip('', 'Todos', 0) + cats.map(function (c) {
			return chip(c.id, c.name, c.count);
		}).join('');
	}

	function thumbHtml(product) {
		if (!showImages()) return '';
		if (product.image) {
			return '<span class="mj-pos-card__img"><img src="' + h(product.image) + '" alt="" loading="lazy"></span>';
		}
		return '<span class="mj-pos-card__img mj-pos-card__img--empty mj-muted" aria-hidden="true">' + MJ.icon('box') + '</span>';
	}

	/** Qué se ve del stock: lo que hay, y aparte lo que está en poder de revendedores. */
	function stockHtml(product) {
		if (!product.manage_stock || product.stock_quantity === null || product.stock_quantity === undefined) {
			return '<span class="mj-muted">Sin control de stock</span>';
		}
		const qty = num(product.stock_quantity) || 0;
		const cls = qty > 0 ? '' : ' mj-danger-text';
		const consigned = num(product.on_consignment) || 0;
		return '<span class="' + cls.trim() + '">' + h(MJ.fmt.qty(qty)) + ' en stock</span>' +
			(consigned > 0 ? ' <span class="mj-muted">· ' + h(MJ.fmt.qty(consigned)) + ' en reventa</span>' : '');
	}

	function productCardHtml(product, inCart) {
		const ids = idsOf(product);
		const price = product.price === null || product.price === undefined || product.price === ''
			? ''
			: money(product.price);

		return '<button type="button" class="mj-pos-card' + (inCart > 0 ? ' is-in-cart' : '') + '"' +
			' data-add="' + h(keyOf(ids.productId, ids.variationId)) + '"' +
			' title="Agregar ' + h(product.name) + '">' +
			thumbHtml(product) +
			'<span class="mj-pos-card__body">' +
				'<span class="mj-pos-card__name">' + h(product.name) + '</span>' +
				'<span class="mj-pos-card__meta mj-small mj-muted">' +
					(product.sku ? 'SKU ' + h(product.sku) + ' · ' : '') + stockHtml(product) +
				'</span>' +
			'</span>' +
			'<span class="mj-pos-card__foot">' +
				'<span class="mj-pos-card__price">' + h(price || '—') + '</span>' +
				(product.status && product.status !== 'publish' ? '<span class="mj-tag">' + h(product.status === 'draft' ? 'borrador' : product.status) + '</span>' : '') +
			'</span>' +
			(inCart > 0 ? '<span class="mj-pos-card__badge">' + h(MJ.fmt.qty(inCart)) + '</span>' : '') +
		'</button>';
	}

	function catalogHtml(state) {
		const cat = state.catalog;

		if (cat.loading && !cat.items.length) {
			return '<div class="mj-pos__state mj-loading">' + MJ.ui.spinner() + '<span>Buscando productos…</span></div>';
		}
		if (!cat.items.length) {
			return '<div class="mj-pos__state">' + MJ.ui.empty({
				icon: 'box',
				title: cat.search ? 'Sin productos para «' + cat.search + '»' : 'No hay productos para mostrar',
				text: cat.onlyStock
					? 'Probá sacando el filtro de stock, o buscá por nombre o SKU.'
					: 'Revisá el buscador y la categoría elegida.',
			}) + '</div>';
		}

		const counts = {};
		state.lines.forEach(function (line) {
			counts[keyOf(line.product_id, line.variation_id)] = (counts[keyOf(line.product_id, line.variation_id)] || 0) + (num(line.qty) || 0);
		});

		return cat.items.map(function (product) {
			const ids = idsOf(product);
			return productCardHtml(product, counts[keyOf(ids.productId, ids.variationId)] || 0);
		}).join('');
	}

	function catalogMoreHtml(state) {
		const cat = state.catalog;
		if (!cat.items.length) return '';
		if (cat.page >= cat.pages) {
			return '<div class="mj-small mj-muted">' + h(MJ.fmt.int(cat.items.length)) + ' de ' + h(MJ.fmt.int(cat.total)) + ' · no hay más</div>';
		}
		return '<button type="button" class="mj-btn mj-btn--ghost" data-action="more"' + (cat.loading ? ' disabled' : '') + '>' +
			(cat.loading ? 'Cargando…' : 'Ver más productos') +
		'</button>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML — carrito                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Datos de la venta, arriba del carrito.
	 *
	 * Se dibuja una sola vez: el buscador de clientes se engancha a este input y volver a pintarlo
	 * lo dejaría colgado de un elemento que ya no está en la pantalla.
	 */
	function settingsHtml(state) {
		return '<div class="mj-pos__settings">' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-customer">Cliente</label>' +
				'<input class="mj-input" id="mj-sale-customer" type="search" placeholder="Buscar cliente… (opcional)" value="' + h(state.customerName) + '" autocomplete="off">' +
			'</div>' +
			'<div class="mj-pos__settings-row">' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-channel">Canal</label>' +
					'<select class="mj-select" id="mj-sale-channel" data-field="channel"></select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-list">Lista de precios</label>' +
					'<select class="mj-select" id="mj-sale-list" data-field="price_list_key"></select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-date">Fecha</label>' +
					'<input class="mj-input" id="mj-sale-date" type="date" data-field="sale_date" value="' + h(state.date) + '">' +
				'</div>' +
			'</div>' +
			'<div data-region="customer-note"></div>' +
		'</div>';
	}

	/**
	 * Entrega: qué hay que hacer para que lo vendido llegue a manos del cliente.
	 *
	 * Va en el carrito, junto al cobro, porque es la otra mitad de cerrar una venta: qué se cobra y
	 * cómo se entrega. El método decide con qué estado nace —lo que se entrega en el momento no
	 * aparece después en la lista de pendientes— y el aviso de abajo lo dice con todas las letras.
	 */
	function deliveryHtml(state) {
		if (!MJ.components.saleHelpers.deliveryEnabled()) return '';

		const instant = MJ.components.saleHelpers.deliveryIsInstant(state.deliveryMethod);

		return '<div class="mj-pos__delivery">' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-delivery">Entrega</label>' +
				'<select class="mj-select mj-select--sm" id="mj-sale-delivery" data-field="delivery_method"></select>' +
			'</div>' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-delivery-note">Dónde / referencia</label>' +
				'<input class="mj-input mj-input--sm" id="mj-sale-delivery-note" type="text" maxlength="190" data-field="delivery_note" value="' + h(state.deliveryNote) + '" placeholder="Opcional">' +
			'</div>' +
			'<div class="mj-field__hint mj-small mj-muted mj-pos__delivery-hint" data-region="delivery-hint">' +
				(instant ? 'Queda entregada al confirmar.' : 'Queda pendiente de entrega: la marcás cuando la entregues.') +
			'</div>' +
		'</div>';
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
			return '<div class="mj-pos__empty-cart mj-muted">' +
				MJ.icon('cart') +
				'<p class="mj-strong">El carrito está vacío</p>' +
				'<p class="mj-small">Tocá un producto del catálogo o buscalo por SKU y apretá Enter.</p>' +
			'</div>';
		}
		return '<ul class="mj-pos-lines">' + state.lines.map(lineHtml).join('') + '</ul>';
	}

	function lineHtml(line, index) {
		const total = (num(line.qty) || 0) * (num(line.unit_price) || num(line.resolvedPrice) || 0) - (num(line.discount) || 0);
		const allowDiscount = defaults().allow_discount !== false;

		return '<li class="mj-pos-line" data-index="' + index + '">' +
			'<div class="mj-pos-line__head">' +
				'<div class="mj-pos-line__name">' +
					'<span class="mj-strong">' + h(line.name) + '</span>' +
					(line.sku ? '<span class="mj-small mj-muted">SKU ' + h(line.sku) + '</span>' : '') +
				'</div>' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-action="remove-line" aria-label="Quitar ' + h(line.name) + '">' + MJ.icon('trash') + '</button>' +
			'</div>' +
			'<div class="mj-pos-line__row">' +
				'<div class="mj-qty">' +
					'<button type="button" class="mj-qty__btn" data-action="qty-down" aria-label="Una unidad menos">' + MJ.icon('minus') + '</button>' +
					'<input class="mj-input mj-input--sm mj-qty__input" type="text" inputmode="decimal" data-line="qty" value="' + h(line.qty) + '" aria-label="Cantidad de ' + h(line.name) + '">' +
					'<button type="button" class="mj-qty__btn" data-action="qty-up" aria-label="Una unidad más">' + MJ.icon('plus') + '</button>' +
				'</div>' +
				'<input class="mj-input mj-input--sm mj-pos-line__price" type="text" inputmode="decimal" data-line="unit_price" value="' + h(line.unit_price === null || line.unit_price === undefined ? '' : line.unit_price) + '" placeholder="auto" aria-label="Precio unitario de ' + h(line.name) + '">' +
				(allowDiscount
					? '<input class="mj-input mj-input--sm mj-pos-line__discount" type="text" inputmode="decimal" data-line="discount" value="' + h(line.discount || '') + '" placeholder="desc." aria-label="Descuento de ' + h(line.name) + '">'
					: '') +
				'<span class="mj-pos-line__total mj-strong">' + h(money(total)) + '</span>' +
			'</div>' +
			'<div class="mj-small" data-region="line-origin-' + index + '"></div>' +
		'</li>';
	}

	function extrasHtml(state) {
		const allowDiscount = defaults().allow_discount !== false;
		return '<div class="mj-pos__extras">' +
			(allowDiscount
				? '<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-discount">Descuento</label>' +
					'<input class="mj-input mj-input--sm" id="mj-sale-discount" type="text" inputmode="decimal" data-field="discount_total" value="' + h(state.discount || '') + '" placeholder="0">' +
				'</div>'
				: '') +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-shipping">Envío</label>' +
				'<input class="mj-input mj-input--sm" id="mj-sale-shipping" type="text" inputmode="decimal" data-field="shipping_total" value="' + h(state.shipping || '') + '" placeholder="0">' +
			'</div>' +
		'</div>';
	}

	function totalsHtml(state) {
		const totals = (state.preview && state.preview.totals) || null;
		if (!totals) {
			return '<div class="mj-pos__totals mj-muted mj-small">Agregá un producto para ver el total.</div>';
		}

		const rows = [{ label: 'Subtotal', value: money(totals.subtotal) }];
		if ((num(totals.discount_total) || 0) > 0) {
			rows.push({ label: 'Descuento', value: '− ' + money(totals.discount_total) });
		}
		if ((num(totals.shipping_total) || 0) > 0) {
			rows.push({ label: 'Envío', value: money(totals.shipping_total) });
		}

		const consignment = num(totals.consignment_total) || 0;

		return '<dl class="mj-totals mj-pos__totals">' +
			rows.map(function (r) {
				return '<div class="mj-totals__row"><dt>' + h(r.label) + '</dt><dd>' + h(r.value) + '</dd></div>';
			}).join('') +
			'<div class="mj-totals__row mj-totals__row--total"><dt>Total</dt><dd>' + h(money(totals.total)) + '</dd></div>' +
			(consignment > 0
				? '<div class="mj-totals__row"><dt>De su consignación</dt><dd>' + h(money(consignment)) + '</dd></div>' +
				  '<div class="mj-totals__row"><dt>A cobrar ahora</dt><dd class="mj-strong">' + h(money(totals.collectable)) + '</dd></div>'
				: '') +
		'</dl>';
	}

	function paymentHtml(state) {
		return '<div class="mj-pos__payment">' +
			'<label class="mj-checkbox"><input type="checkbox" data-field="charge"' + (state.charge ? ' checked' : '') + '> <span class="mj-strong">Cobrado</span></label>' +
			'<div class="mj-pos__payment-fields' + (state.charge ? '' : ' mj-hidden') + '" data-region="payment-fields">' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-pay-method">Medio de pago</label>' +
					'<select class="mj-select mj-select--sm" id="mj-sale-pay-method" data-field="payment_method"></select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-cashbox">Caja</label>' +
					'<select class="mj-select mj-select--sm" id="mj-sale-cashbox" data-field="cashbox_id"></select>' +
				'</div>' +
				'<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-sale-pay-amount">Importe cobrado</label>' +
					'<input class="mj-input mj-input--sm" id="mj-sale-pay-amount" type="text" inputmode="decimal" data-field="payment_amount" value="' + h(state.paymentAmount) + '">' +
					'<div class="mj-field__hint mj-small mj-muted">Vacío = todo lo que hay que cobrar.</div>' +
				'</div>' +
			'</div>' +
			'<div class="mj-field">' +
				'<label class="mj-field__label" for="mj-sale-note">Nota</label>' +
				'<textarea class="mj-textarea" id="mj-sale-note" rows="2" data-field="note" placeholder="Opcional">' + h(state.note) + '</textarea>' +
			'</div>' +
			'<label class="mj-checkbox"><input type="checkbox" data-field="create_order"' + (state.createOrder ? ' checked' : '') + '> <span>Emitir el pedido en WooCommerce</span></label>' +
			'<div class="mj-field__hint mj-small mj-muted" data-region="commit-hint">' + commitHintHtml(state) + '</div>' +
		'</div>';
	}

	/**
	 * Qué va a pasar al confirmar, dicho antes de confirmar.
	 *
	 * El stock no baja por anotar una venta: baja cuando el cliente la paga o se la lleva. Como esa
	 * regla se configura, la pantalla la dice con las palabras de la venta que se está cargando, en
	 * vez de dejar que se descubra mirando el stock al día siguiente.
	 */
	function commitHintHtml(state) {
		const helpers = MJ.components.saleHelpers;
		const paid = state.charge && paysInFull(state);
		const delivered = helpers.deliveryEnabled()
			? helpers.deliveryIsInstant(state.deliveryMethod)
			: true;

		if (!helpers.commitsNow({ paid: paid, delivered: delivered })) {
			const falta = helpers.commitPendingText();
			return 'Queda anotada sin tocar el stock' + (falta ? ': el pedido se emite y el stock baja ' + h(falta) + '.' : '.');
		}
		return state.createOrder
			? 'Al confirmar se emite el pedido en WooCommerce y baja el stock.'
			: 'Al confirmar baja el stock. Sin pedido no aparece en los informes de Woo.';
	}

	/**
	 * ¿El cobro que se está cargando cubre todo lo que hay que cobrar?
	 *
	 * Vacío = «todo», que es como lo entiende el servidor; un importe menor es una seña y la venta
	 * queda con saldo.
	 */
	function paysInFull(state) {
		const collectable = state.preview && state.preview.totals ? (num(state.preview.totals.collectable) || 0) : 0;
		const typed = String(state.paymentAmount).trim();
		if (typed === '') return true;
		return (num(typed) || 0) + 0.0001 >= collectable;
	}

	function actionsHtml() {
		return '<button type="button" class="mj-btn mj-btn--primary mj-btn--lg mj-pos__confirm" data-action="confirm" disabled>' +
			MJ.icon('check') + '<span data-region="confirm-label">Confirmar venta</span>' +
		'</button>' +
		'<a class="mj-btn mj-btn--ghost mj-btn--sm" href="' + h(MJ.router.link(PATH)) + '">Cancelar</a>';
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
				deliveryMethod: defaults().delivery_method || '',
				deliveryNote: '',
				paymentMethod: defaults().payment_method || '',
				cashboxId: defaults().cashbox_id || 0,
				cashboxTouched: false,
				createOrder: defaults().create_order !== false,
				preview: null,
				seq: 0,
				saving: false,
				disposed: false,
				step: 0,
				mode: '',
				catalog: {
					items: [],
					categories: [],
					page: 1,
					pages: 1,
					total: 0,
					loading: false,
					search: '',
					category: '',
					onlyStock: false,
					seq: 0,
				},
			};

			function region(name) { return qs('[data-region="' + name + '"]', container); }
			function paint(name, html) {
				const el = region(name);
				if (el) el.innerHTML = html;
			}

			/* ---------------- catálogo ---------------- */

			function paintCatalog() {
				paint('catalog', catalogHtml(state));
				paint('catalog-more', catalogMoreHtml(state));
			}

			function loadCatalog(reset) {
				const cat = state.catalog;
				if (reset) {
					cat.page = 1;
					cat.items = [];
				}
				const seq = ++cat.seq;
				cat.loading = true;
				paintCatalog();

				MJ.api.get('products', {
					search: cat.search || undefined,
					category: cat.category || undefined,
					only_in_stock: cat.onlyStock ? 1 : 0,
					page: cat.page,
					per_page: perPage(),
					orderby: 'name',
					order: 'ASC',
				}).then(function (res) {
					if (state.disposed || seq !== cat.seq) return;
					const items = ((res && res.items) || []).filter(sellable);
					cat.items = cat.page > 1 ? cat.items.concat(items) : items;
					cat.pages = Math.max(1, parseInt(res && res.pages, 10) || 1);
					cat.total = parseInt(res && res.total, 10) || cat.items.length;
					cat.loading = false;
					paintCatalog();
				}).catch(function (err) {
					if (state.disposed || seq !== cat.seq) return;
					cat.loading = false;
					paintCatalog();
					toastError(err, 'No se pudo cargar el catálogo.');
				});
			}

			function loadCategories() {
				MJ.api.get('products/categories').then(function (res) {
					if (state.disposed) return;
					const rows = Array.isArray(res) ? res : (res && res.items) || [];
					state.catalog.categories = rows.filter(function (c) { return (parseInt(c.count, 10) || 0) > 0; });
					paint('categories', categoriesHtml(state));
				}).catch(function () { /* sin categorías se navega igual, con el buscador */ });
			}

			const searchCatalog = MJ.ui.debounce(function () {
				loadCatalog(true);
			}, SEARCH_DELAY);

			/* ---------------- carrito ---------------- */

			function paintLines() {
				paint('cart-lines', linesHtml(state));
				paintOrigins();
				paintCatalog(); // los globos «ya está en el carrito» viven en las tarjetas
				// El contador de la barra de abajo tiene que moverse con el toque, no con el preview.
				paint('steps-foot', stepsFootHtml(state));
				paintActions();
			}

			/** Etiqueta bajo cada línea con de dónde sale cada unidad. */
			function paintOrigins() {
				if (!state.preview || !state.preview.lines) return;

				const byKey = {};
				state.preview.lines.forEach(function (line) {
					const key = keyOf(line.product_id, line.variation_id);
					byKey[key] = byKey[key] || { stock: 0, consignment: 0 };
					if (line.origin === 'consignacion') byKey[key].consignment += num(line.qty) || 0;
					else byKey[key].stock += num(line.qty) || 0;
				});

				state.lines.forEach(function (line, index) {
					const el = region('line-origin-' + index);
					if (!el) return;
					const split = byKey[keyOf(line.product_id, line.variation_id)];
					if (!split || split.consignment <= 0) { el.innerHTML = ''; return; }
					el.innerHTML = '<span class="mj-badge mj-badge--info">' + h(MJ.fmt.qty(split.consignment)) + ' de su consignación</span>' +
						(split.stock > 0 ? ' <span class="mj-badge mj-badge--neutral">' + h(MJ.fmt.qty(split.stock)) + ' del depósito</span>' : '');
				});
			}

			function paintCommitHint() {
				const el = region('commit-hint');
				if (el) el.innerHTML = commitHintHtml(state);
			}

			function paintTotals() {
				paint('cart-totals', totalsHtml(state));
				paintCommitHint();
				// En el celular el total vive en la barra de abajo, que está siempre a la vista.
				paint('steps-foot', stepsFootHtml(state));

				const totals = (state.preview && state.preview.totals) || null;
				const consignment = totals ? (num(totals.consignment_total) || 0) : 0;
				paint('cart-notice', consignment > 0
					? MJ.ui.alert('Lo que sale de la consignación no se cobra acá: va a la cuenta corriente del revendedor y lo cobrás cuando te pague.', 'info')
					: '');

				/*
				 * El importe a cobrar se escribe en el placeholder en vez de repintar el bloque de
				 * cobro: si se repintara, al tipear un importe el campo se recrearía y se perdería
				 * el foco a mitad del número.
				 */
				const amount = qs('#mj-sale-pay-amount', container);
				if (amount) {
					const collectable = totals ? (num(totals.collectable) || 0) : 0;
					amount.placeholder = collectable > 0 ? MJ.fmt.number(collectable, 2) : '';
				}

				paintActions();
			}

			function paintActions() {
				const btn = qs('[data-action="confirm"]', container);
				if (!btn) return;
				const units = state.lines.reduce(function (acc, l) { return acc + (num(l.qty) || 0); }, 0);
				btn.disabled = !state.lines.length || state.saving;

				const label = region('confirm-label');
				if (!label) return;

				// En el celular el botón es angosto y la barra ya muestra ítems y total.
				if (state.mode === 'steps') {
					label.textContent = 'Confirmar';
					return;
				}

				const totals = (state.preview && state.preview.totals) || null;
				label.textContent = totals
					? 'Confirmar ' + MJ.fmt.qty(units) + (units === 1 ? ' ítem · ' : ' ítems · ') + money(totals.total)
					: 'Confirmar venta';
			}

			function paintCustomerNote() {
				paint('customer-note', customerNoteHtml(state));
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
					paintTotals();
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

					paintTotals();
					paintOrigins();
					paintLineTotals();
					paintPriceHints();
				}).catch(function (err) {
					if (state.disposed || seq !== state.seq) return;
					toastError(err, 'No se pudo calcular la venta.');
				});
			}, PREVIEW_DELAY);

			/** Subtotal de cada línea con el precio que resolvió el servidor. */
			function paintLineTotals() {
				qsa('.mj-pos-line', container).forEach(function (li) {
					const index = parseInt(li.getAttribute('data-index'), 10);
					const line = state.lines[index];
					const el = qs('.mj-pos-line__total', li);
					if (!line || !el) return;
					const price = (line.unit_price === null || line.unit_price === undefined || line.unit_price === '')
						? (num(line.resolvedPrice) || 0)
						: (num(line.unit_price) || 0);
					el.textContent = money((num(line.qty) || 0) * price - (num(line.discount) || 0));
				});
			}

			/** Muestra el precio resuelto en los campos que quedaron en «auto». */
			function paintPriceHints() {
				qsa('.mj-pos-line', container).forEach(function (li) {
					const index = parseInt(li.getAttribute('data-index'), 10);
					const line = state.lines[index];
					const input = qs('[data-line="unit_price"]', li);
					if (!line || !input || document.activeElement === input) return;
					if ((line.unit_price === null || line.unit_price === undefined || line.unit_price === '') && line.resolvedPrice !== undefined) {
						input.placeholder = MJ.fmt.number(line.resolvedPrice, 2);
					}
				});
			}

			/* ---------------- líneas ---------------- */

			function addProduct(product) {
				if (!product) return;
				const ids = idsOf(product);
				if (!ids.productId) return;

				const existing = state.lines.find(function (l) {
					return l.product_id === ids.productId && (l.variation_id || 0) === ids.variationId;
				});
				if (existing) {
					existing.qty = (num(existing.qty) || 0) + 1;
				} else {
					state.lines.push({
						product_id: ids.productId,
						variation_id: ids.variationId,
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

			/**
			 * Enter en el buscador: agrega sin esperar a que se repinte la grilla.
			 *
			 * Es el camino del lector de códigos, que escribe el SKU y manda Enter de una: se
			 * pregunta por el término exacto en vez de mirar lo que haya quedado en pantalla, y el
			 * SKU idéntico gana siempre.
			 */
			function addByTerm(term) {
				const clean = String(term || '').trim();
				if (!clean) return;

				MJ.api.get('products/search', { q: clean, limit: 5 }).then(function (res) {
					if (state.disposed) return;
					const items = (Array.isArray(res) ? res : (res && res.items) || []).filter(sellable);
					const exact = items.find(function (p) {
						return String(p.sku || '').toLowerCase() === clean.toLowerCase();
					});
					const chosen = exact || items[0];
					if (!chosen) {
						MJ.ui.toast('Sin resultados para «' + clean + '».', 'warning');
						return;
					}
					addProduct(chosen);

					const input = qs('#mj-sale-product', container);
					if (input) {
						input.value = '';
						input.focus();
					}
					// El término ya se usó: se cancela la búsqueda pendiente y se vuelve al catálogo entero.
					searchCatalog.cancel();
					state.catalog.search = '';
					loadCatalog(true);
				}).catch(function (err) {
					toastError(err, 'No se pudo buscar el producto.');
				});
			}

			/* ---------------- montaje ---------------- */

			let customerAc = null;

			/** El buscador de clientes se engancha al input, así que se rehace con cada montaje. */
			function bindCustomerSearch() {
				if (customerAc && customerAc.destroy) {
					customerAc.destroy();
					customerAc = null;
				}
				const input = qs('#mj-sale-customer', container);
				if (!input) return;

				customerAc = MJ.components.customerSearch({
					input: input,
					onSelect(customer) { chooseCustomer(customer); },
					onClear() {
						// Solo limpia lo tipeado: quitar un cliente elegido es el botón «Quitar».
						if (!state.customer) return;
						state.customer = null;
						state.context = null;
						paintCustomerNote();
						refreshPreview();
					},
				});
			}

			/** Elegir (o crear) un cliente: una sola puerta para las dos cosas. */
			function chooseCustomer(customer) {
				state.customer = customer;
				state.customerName = (customer && customer.name) || '';
				state.context = null;

				if (state.mode === 'steps') {
					paintStep();
				} else {
					paintCustomerNote();
				}

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
			}

			/** Llena los select que existan en la pantalla montada. */
			function hydrateSelects() {
				const helpers = MJ.components.saleHelpers;
				const priceLists = ((MJ.state.bootstrap && MJ.state.bootstrap.price_lists) || []).map(function (l) {
					return { value: l.key, label: l.label };
				});
				const fill = function (sel, options, value, placeholder) {
					const el = qs(sel, container);
					if (el) MJ.ui.fillSelect(el, options, value, placeholder);
				};

				fill('#mj-sale-channel', helpers.channelOptions(), state.channel);
				fill('#mj-sale-list', priceLists, state.priceList, 'Precio de la tienda');
				fill('#mj-sale-delivery', helpers.deliveryMethodOptions(), state.deliveryMethod, 'Sin definir');
				fill('#mj-sale-pay-method', helpers.methodOptions(), state.paymentMethod);
				fill('#mj-sale-cashbox', helpers.cashboxOptions(), state.cashboxId, 'Sin caja');
			}

			/* ---------------- pasos (celular) ---------------- */

			function steps() { return stepList(); }

			function stepKey() {
				const list = steps();
				const step = list[Math.min(state.step, list.length - 1)];
				return step ? step.key : '';
			}

			function goToStep(index) {
				const list = steps();
				state.step = Math.max(0, Math.min(index, list.length - 1));
				paintStep();
				MJ.ui.scrollTop();
			}

			function stepIndexOf(key) {
				const list = steps();
				for (let i = 0; i < list.length; i++) {
					if (list[i].key === key) return i;
				}
				return -1;
			}

			/** Lo que hay que cobrar y lo que va a quedar debiendo con lo cargado hasta acá. */
			function dueNow() {
				const totals = (state.preview && state.preview.totals) || null;
				const collectable = totals ? (num(totals.collectable) || 0) : 0;
				const paying = state.charge
					? (String(state.paymentAmount).trim() === '' ? collectable : (num(state.paymentAmount) || 0))
					: 0;
				return Math.round((collectable - paying) * 10000) / 10000;
			}

			/** ¿La venta tiene a quién cobrarle? Una cuenta de la tienda vale: el ERP le abre ficha. */
			function hasCustomer() {
				return customerCardId(state) > 0 || customerUserId(state) > 0;
			}

			/**
			 * ¿Se puede salir del paso actual?
			 *
			 * Acá vive la única regla que no es cosmética: una venta que queda con saldo necesita
			 * cliente, porque esa deuda tiene que aparecer en la cuenta de alguien. El servidor la
			 * vuelve a aplicar; esto es para no enterarse recién al confirmar.
			 */
			function canLeaveStep() {
				const key = stepKey();

				if ((key === 'items' || key === 'lines') && !state.lines.length) {
					MJ.ui.toast('Agregá al menos un producto.', 'warning');
					return false;
				}

				if (key === 'payment' && dueNow() > 0.004 && !hasCustomer() && customersEnabled()) {
					MJ.ui.toast('Queda debiendo ' + money(dueNow()) + ': elegí el cliente.', 'warning');
					const index = stepIndexOf('customer');
					if (index >= 0) goToStep(index);
					return false;
				}

				return true;
			}

			function paintStep() {
				paint('steps-head', stepsHeadHtml(state));
				paint('step-body', stepBodyHtml(state));
				paint('steps-foot', stepsFootHtml(state));

				hydrateSelects();
				bindCustomerSearch();

				const key = stepKey();
				if (key === 'items') {
					paintCatalog();
					paint('categories', categoriesHtml(state));
					/*
					 * A propósito sin foco automático: en el celular eso abre el teclado y tapa el
					 * catálogo, que es justo lo que se vino a mirar. El buscador está a un toque.
					 */
				}
				if (key === 'lines') {
					paintLines();
					paint('cart-extras', extrasHtml(state));
					paintTotals();
				}
				if (key === 'customer') {
					paintCustomerNote();
				}
				if (key === 'payment') {
					paint('cart-payment', paymentHtml(state));
					hydrateSelects();
					paintDebtNotice();
				}
				if (key === 'delivery') {
					paint('cart-delivery', deliveryHtml(state));
					hydrateSelects();
				}
				paintActions();
			}

			/** Rehace el bloque de cobro para que el aviso de deuda siga al importe. */
			function refreshDebtNotice() {
				if (state.mode !== 'steps' || stepKey() !== 'payment') return;
				paint('cart-payment', paymentHtml(state));
				hydrateSelects();
				paintDebtNotice();
			}

			/** El aviso de «esto queda debiendo», dentro del paso de pago. */
			function paintDebtNotice() {
				const el = region('cart-payment');
				if (!el || stepKey() !== 'payment') return;
				const due = dueNow();
				if (due <= 0.004) return;

				const who = state.customer ? (state.customer.name || state.customerName) : '';
				el.insertAdjacentHTML(
					'beforeend',
					MJ.ui.alert(
						who
							? 'Queda debiendo ' + money(due) + ', a nombre de ' + who + '. Se lo cobrás después desde la venta.'
							: 'Queda debiendo ' + money(due) + '. Elegí el cliente que va a quedar debiendo: una deuda sin cliente no se puede cobrar.',
						who ? 'info' : 'warning'
					)
				);
			}

			/* ---------------- montaje por modo ---------------- */

			function mount(mode) {
				if (state.mode === mode) return;
				state.mode = mode;

				container.innerHTML = headerHtml() + (mode === 'steps' ? stepsShellHtml() : shellHtml(state));

				if (mode === 'steps') {
					paintStep();
					return;
				}

				paint('cart-settings', settingsHtml(state));
				paint('cart-extras', extrasHtml(state));
				paint('cart-delivery', deliveryHtml(state));
				paint('cart-payment', paymentHtml(state));
				paint('cart-actions', actionsHtml());
				hydrateSelects();
				bindCustomerSearch();
				paintLines();
				paintTotals();
				paintCustomerNote();
				paintCatalog();
				paint('categories', categoriesHtml(state));
			}

			/*
			 * Qué forma corresponde. Se mira el ancho de la ventana y no el dispositivo: una tablet
			 * girada cambia de forma sin recargar, y la venta cargada sigue intacta.
			 */
			const width = stepsWidth();
			const mq = width > 0 && window.matchMedia ? window.matchMedia('(max-width: ' + width + 'px)') : null;

			function syncMode() {
				mount(mq && mq.matches ? 'steps' : 'desk');
			}

			syncMode();

			if (mq) {
				const onChange = function () { syncMode(); };
				if (mq.addEventListener) {
					mq.addEventListener('change', onChange);
					offs.push(function () { mq.removeEventListener('change', onChange); });
				} else if (mq.addListener) {
					mq.addListener(onChange);
					offs.push(function () { mq.removeListener(onChange); });
				}
			}

			loadCategories();
			loadCatalog(true);

			/* ---------------- eventos: catálogo ---------------- */

			offs.push(MJ.ui.on(container, 'input', '#mj-sale-product', function (e, el) {
				state.catalog.search = String(el.value || '').trim();
				searchCatalog();
			}));

			offs.push(MJ.ui.on(container, 'keydown', '#mj-sale-product', function (e, el) {
				if (e.key !== 'Enter') return;
				e.preventDefault();
				addByTerm(el.value);
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-field="only_stock"]', function (e, el) {
				state.catalog.onlyStock = !!el.checked;
				loadCatalog(true);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-category]', function (e, el) {
				state.catalog.category = el.getAttribute('data-category') || '';
				paint('categories', categoriesHtml(state));
				loadCatalog(true);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="more"]', function () {
				if (state.catalog.loading || state.catalog.page >= state.catalog.pages) return;
				state.catalog.page += 1;
				loadCatalog(false);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-add]', function (e, el) {
				const key = el.getAttribute('data-add');
				const product = state.catalog.items.find(function (p) {
					const ids = idsOf(p);
					return keyOf(ids.productId, ids.variationId) === key;
				});
				addProduct(product);
				const input = qs('#mj-sale-product', container);
				if (input) input.focus();
			}));

			/* ---------------- eventos: carrito ---------------- */

			offs.push(MJ.ui.on(container, 'input', '[data-line]', function (e, el) {
				const li = el.closest('.mj-pos-line');
				const index = li ? parseInt(li.getAttribute('data-index'), 10) : -1;
				const line = state.lines[index];
				if (!line) return;
				line[el.getAttribute('data-line')] = el.value;
				paintLineTotals();
				refreshPreview();
			}));

			offs.push(MJ.ui.on(container, 'keydown', '[data-line="qty"]', function (e) {
				if (e.key !== 'Enter') return;
				e.preventDefault();
				const input = qs('#mj-sale-product', container);
				if (input) input.focus();
			}));

			/* Los pasos de cantidad: en el mostrador se suma de a uno mucho más de lo que se tipea. */
			offs.push(MJ.ui.on(container, 'click', '[data-action="qty-up"], [data-action="qty-down"]', function (e, el) {
				const li = el.closest('.mj-pos-line');
				const index = li ? parseInt(li.getAttribute('data-index'), 10) : -1;
				const line = state.lines[index];
				if (!line) return;

				const step = el.getAttribute('data-action') === 'qty-up' ? 1 : -1;
				const qty = MJ.fmt.round((num(line.qty) || 0) + step, 3);
				if (qty <= 0) {
					state.lines.splice(index, 1);
				} else {
					line.qty = qty;
				}
				paintLines();
				refreshPreview();
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="remove-line"]', function (e, el) {
				const li = el.closest('.mj-pos-line');
				const index = li ? parseInt(li.getAttribute('data-index'), 10) : -1;
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
			offs.push(MJ.ui.on(container, 'change', '[data-field="delivery_method"]', function (e, el) {
				state.deliveryMethod = el.value;
				// El aviso cambia con el método: decir después que "quedó pendiente" ya no sirve.
				const hint = region('delivery-hint');
				if (hint) {
					hint.textContent = MJ.components.saleHelpers.deliveryIsInstant(state.deliveryMethod)
						? 'Queda entregada al confirmar.'
						: 'Queda pendiente de entrega: la marcás cuando la entregues.';
				}
				paintCommitHint();
			}));
			offs.push(MJ.ui.on(container, 'input', '[data-field="delivery_note"]', function (e, el) { state.deliveryNote = el.value; }));
			offs.push(MJ.ui.on(container, 'input', '[data-field="note"]', function (e, el) { state.note = el.value; }));
			offs.push(MJ.ui.on(container, 'input', '[data-field="payment_amount"]', function (e, el) {
				state.paymentAmount = el.value;
				paintCommitHint();
				refreshDebtNotice();
			}));

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
				const fields = region('payment-fields');
				if (fields) fields.classList.toggle('mj-hidden', !state.charge);
				paintCommitHint();
				refreshDebtNotice();
			}));

			offs.push(MJ.ui.on(container, 'change', '[data-field="create_order"]', function (e, el) {
				state.createOrder = !!el.checked;
				paintCommitHint();
			}));

			/* ---------------- eventos: pasos ---------------- */

			offs.push(MJ.ui.on(container, 'click', '[data-action="step-next"]', function () {
				if (!canLeaveStep()) return;
				goToStep(state.step + 1);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="step-prev"]', function () {
				goToStep(state.step - 1);
			}));

			/* Los puntos de arriba: para atrás siempre, para adelante solo si el paso está listo. */
			offs.push(MJ.ui.on(container, 'click', '[data-step]', function (e, el) {
				const index = parseInt(el.getAttribute('data-step'), 10);
				if (isNaN(index) || index === state.step) return;
				if (index > state.step && !canLeaveStep()) return;
				goToStep(index);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="new-customer"]', function () {
				if (!MJ.components.customerModal) return;
				const input = qs('#mj-sale-customer', container);
				MJ.components.customerModal({
					customer: { name: String((input && input.value) || '').trim() },
					onSaved(saved) {
						chooseCustomer(Object.assign({ type: 'customer', customer_id: saved.id }, saved));
					},
				});
			}));

			/* «Sin cliente» es una decisión, no un olvido: se marca y se sigue. */
			offs.push(MJ.ui.on(container, 'click', '[data-action="no-customer"]', function () {
				state.customer = null;
				state.customerName = '';
				state.context = null;
				refreshPreview();
				goToStep(state.step + 1);
			}));

			offs.push(MJ.ui.on(container, 'click', '[data-action="clear-customer"]', function () {
				state.customer = null;
				state.customerName = '';
				state.context = null;
				paintStep();
				refreshPreview();
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

				/*
				 * No hay deuda sin cliente. El servidor la rechaza igual, pero enterarse acá —con el
				 * cliente enfrente— es la diferencia entre elegirlo y perder la venta cargada.
				 */
				if (dueNow() > 0.004 && !hasCustomer() && customersEnabled()) {
					MJ.ui.toast('Queda debiendo ' + money(dueNow()) + ': elegí el cliente que va a quedar debiendo.', 'warning');
					const index = stepIndexOf('customer');
					if (state.mode === 'steps' && index >= 0) {
						goToStep(index);
					} else {
						const input = qs('#mj-sale-customer', container);
						if (input) input.focus();
					}
					return;
				}

				state.saving = true;
				if (btn) MJ.ui.setLoading(btn, true);

				const body = payload();
				// Un nombre suelto (sin ficha ni cuenta) igual queda escrito en la venta: es mejor que
				// «cliente sin nombre», y el día que esa persona vuelva se le puede abrir la ficha.
				const customerInput = qs('#mj-sale-customer', container);
				body.customer_name = state.customer ? '' : String((customerInput && customerInput.value) || '').trim();
				body.channel = state.channel || undefined;
				body.sale_date = state.date || undefined;
				body.note = state.note || undefined;
				body.create_order = state.createOrder;
				if (MJ.components.saleHelpers.deliveryEnabled()) {
					body.delivery_method = state.deliveryMethod || '';
					body.delivery_note = state.deliveryNote || '';
				}

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
					if (customer.price_list_key) {
						state.priceList = customer.price_list_key;
						const listSelect = qs('#mj-sale-list', container);
						if (listSelect) listSelect.value = state.priceList;
					}
					chooseCustomer(Object.assign({ type: 'customer', customer_id: customer.id }, customer));
					const input = qs('#mj-sale-customer', container);
					if (input) input.value = state.customerName;
				}).catch(function () { /* si no se puede, la venta se carga igual a mano */ });
			}

			// El foco arranca en el buscador: es lo primero que se usa siempre.
			const productInput = qs('#mj-sale-product', container);
			if (productInput && state.mode !== 'steps') {
				try { productInput.focus({ preventScroll: true }); } catch (err) { productInput.focus(); }
			}

			return function cleanup() {
				state.disposed = true;
				searchCatalog.cancel();
				refreshPreview.cancel();
				if (customerAc && customerAc.destroy) customerAc.destroy();
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};

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
})();
