/**
 * MJ ERP — page-products.js
 * MJ.pages.products: catálogo de WooCommerce con el costo landed escrito por el ERP, precio, margen,
 * markup, última compra, paginación y el detalle de movimientos de stock de cada producto/variación.
 *
 * REST usado: GET /products (search, page, per_page, only_with_cost), GET /products/{id}/movements,
 * POST /products (a través de MJ.components.productCreateModal).
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };

	const PER_PAGE_OPTIONS = [20, 50, 100];
	const DEFAULT_PER_PAGE = 20;
	const MOVEMENTS_LIMIT = 100;
	const SEARCH_DEBOUNCE = 350;
	const THUMB_STYLE = 'width:38px;height:38px;border-radius:6px;object-fit:cover;flex:0 0 auto';

	/** WooCommerce post statuses worth flagging in the table. */
	const PRODUCT_STATUS = {
		draft: { label: 'Borrador', color: 'neutral' },
		private: { label: 'Privado', color: 'warning' },
		pending: { label: 'Pendiente', color: 'info' },
		trash: { label: 'Papelera', color: 'danger' },
	};

	/** WooCommerce stock statuses. */
	const STOCK_STATUS = {
		instock: { label: 'En stock', color: 'success' },
		outofstock: { label: 'Sin stock', color: 'danger' },
		onbackorder: { label: 'Bajo pedido', color: 'warning' },
	};

	/** Movement types written by the ERP. */
	const MOVEMENT_TYPES = {
		purchase_receive: { label: 'Recepción', color: 'success' },
		purchase_revert: { label: 'Reversión', color: 'danger' },
		adjustment: { label: 'Ajuste', color: 'info' },
		sale: { label: 'Venta', color: 'neutral' },
		sale_revert: { label: 'Anulación de venta', color: 'warning' },
		sale_edit: { label: 'Corrección de venta', color: 'info' },
		sale_return: { label: 'Devolución', color: 'success' },
		sale_exchange: { label: 'Cambio', color: 'info' },
		rma_replacement: { label: 'Reemplazo por garantía', color: 'warning' },
		rma_restock: { label: 'RMA: volvió al stock', color: 'success' },
	};

	/* ------------------------------------------------------------------ */
	/* Local helpers                                                       */
	/* ------------------------------------------------------------------ */

	function setting(key, def) {
		const s = MJ.state && MJ.state.settings ? MJ.state.settings : {};
		return (s[key] === undefined || s[key] === null) ? def : s[key];
	}

	function baseCurrency() {
		return String(setting('base_currency', 'USD')).toUpperCase();
	}

	/** Currency WooCommerce prices (and cost_local) are expressed in. */
	function wcCurrency() {
		const boot = MJ.state && MJ.state.bootstrap;
		const fromBoot = boot && boot.wc && boot.wc.currency ? boot.wc.currency : '';
		const fromConfig = MJ.config && MJ.config.wcCurrency ? MJ.config.wcCurrency : '';
		return String(fromBoot || fromConfig || setting('local_currency', 'ARS')).toUpperCase();
	}

	function num(v) {
		return MJ.fmt.toNumber(v);
	}

	/** Only http(s) and site-relative URLs survive; anything else is dropped. */
	function safeUrl(v) {
		const s = String(v === undefined || v === null ? '' : v).trim();
		if (!s) return '';
		if (/^https?:\/\//i.test(s)) return s;
		if (s.charAt(0) === '/' && s.charAt(1) !== '/') return s;
		return '';
	}

	function dash() {
		return '<span class="mj-muted">—</span>';
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function hasFilters(state) {
		return !!(state.search || state.onlyWithCost);
	}

	function parseQuery(query) {
		query = query || {};
		const page = parseInt(query.page, 10);
		const perPage = parseInt(query.per_page, 10);
		const only = String(query.only_with_cost === undefined || query.only_with_cost === null ? '' : query.only_with_cost).toLowerCase();
		return {
			search: String(query.search || query.q || '').trim(),
			page: page > 0 ? page : 1,
			perPage: PER_PAGE_OPTIONS.indexOf(perPage) >= 0 ? perPage : DEFAULT_PER_PAGE,
			onlyWithCost: only === '1' || only === 'true' || only === 'yes' || only === 'on',
		};
	}

	function syncHash(state) {
		const target = MJ.router.link('/productos', {
			search: state.search,
			only_with_cost: state.onlyWithCost ? 1 : '',
			per_page: state.perPage !== DEFAULT_PER_PAGE ? state.perPage : '',
			page: state.page > 1 ? state.page : '',
		});
		if (window.location.hash === target) return;
		try { window.history.replaceState(null, '', target); } catch (e) { /* noop: la URL simplemente no queda sincronizada */ }
	}

	/* ------------------------------------------------------------------ */
	/* Cell builders                                                       */
	/* ------------------------------------------------------------------ */

	function thumbHtml(p) {
		const src = safeUrl(p.image);
		if (src) {
			return '<img class="mj-product-thumb" src="' + h(src) + '" alt="" width="38" height="38" loading="lazy" style="' + THUMB_STYLE + '">';
		}
		return '<span class="mj-product-thumb mj-product-thumb--empty mj-muted" aria-hidden="true" ' +
			'style="' + THUMB_STYLE + ';display:inline-flex;align-items:center;justify-content:center">' + MJ.icon('image') + '</span>';
	}

	function productCell(p) {
		const name = p.name ? String(p.name) : 'Sin nombre';
		const bits = [];
		bits.push(p.sku ? '<span class="mj-mono">' + h(p.sku) + '</span>' : '<span class="mj-muted">Sin SKU</span>');
		if (p.is_variation) {
			bits.push('<span class="mj-tag">Variación</span>');
			if (p.parent_name) bits.push('<span>' + h(p.parent_name) + '</span>');
		}
		return '<td>' +
			'<div class="mj-row">' +
				thumbHtml(p) +
				'<div class="mj-truncate" style="min-width:0">' +
					'<div class="mj-strong mj-truncate" title="' + h(name) + '">' + h(name) + '</div>' +
					'<div class="mj-table__sub mj-small mj-muted">' + bits.join(' <span aria-hidden="true">·</span> ') + '</div>' +
				'</div>' +
			'</div>' +
		'</td>';
	}

	function statusCell(p) {
		const key = String(p.status || '').toLowerCase();
		if (!key || key === 'publish') return '<td><span class="mj-small mj-muted">Publicado</span></td>';
		const info = PRODUCT_STATUS[key] || { label: key, color: 'neutral' };
		return '<td>' + MJ.ui.badgeRaw(info.label, info.color) + '</td>';
	}

	function stockCell(p) {
		const info = STOCK_STATUS[String(p.stock_status || '').toLowerCase()] || null;
		const sub = info
			? '<div class="mj-table__sub mj-small">' + MJ.ui.badgeRaw(info.label, info.color) + '</div>'
			: (p.stock_status ? '<div class="mj-table__sub mj-small mj-muted">' + h(p.stock_status) + '</div>' : '');
		/*
		 * Unidades que están en poder de un revendedor. El stock de WooCommerce todavía las cuenta
		 * (salen cuando el revendedor vende), así que sin este aviso se puede prometer por la web
		 * algo que físicamente está en otro lado.
		 */
		const consigned = num(p.on_consignment);
		const consignedNote = consigned !== null && consigned > 0
			? '<div class="mj-table__sub mj-small mj-warning-text" title="Estas unidades están en poder de un revendedor: siguen contando como stock hasta que las venda">' +
				h(MJ.fmt.qty(consigned)) + ' en la calle</div>'
			: '';

		if (!p.manage_stock) {
			return '<td class="is-num"><span class="mj-muted" title="WooCommerce no gestiona el stock de este producto">Sin gestión</span>' + sub + consignedNote + '</td>';
		}
		const qty = num(p.stock_quantity);
		const cls = qty === null ? 'mj-muted' : (qty > 0 ? 'mj-strong' : 'mj-danger-text mj-strong');
		return '<td class="is-num"><span class="' + cls + '">' + h(qty === null ? '—' : MJ.fmt.qty(qty)) + '</span>' + sub + consignedNote + '</td>';
	}

	function costCell(p) {
		const costBase = num(p.cost_base);
		if (costBase === null) {
			return '<td class="is-num"><span class="mj-muted" title="Se completa al recibir una compra">Sin costo</span></td>';
		}
		const cur = String(p.cost_currency || baseCurrency()).toUpperCase();
		const wc = wcCurrency();
		const costLocal = num(p.cost_local);
		let html = '<span class="mj-money mj-strong">' + h(MJ.fmt.money(costBase, cur)) + '</span>';
		if (costLocal !== null && wc !== cur) {
			html += '<div class="mj-table__sub mj-small mj-muted">' + h(MJ.fmt.money(costLocal, wc)) + '</div>';
		}
		return '<td class="is-num">' + html + '</td>';
	}

	/**
	 * Price column. When the product has a price published in dollars through MJ Dólar, that is the
	 * headline number: it is the one that stays put, while the peso price moves with the TC.
	 */
	function priceCell(p) {
		const wc = wcCurrency();
		const base = baseCurrency();
		const regular = num(p.regular_price);
		const sale = num(p.sale_price);
		const price = num(p.price);
		const usd = num(p.price_usd);
		const usdRegular = num(p.usd_regular);
		const usdSale = num(p.usd_sale);

		if (regular === null && sale === null && price === null && usd === null) {
			return '<td class="is-num">' + dash() + '</td>';
		}

		const onSale = sale !== null && regular !== null && sale < regular;
		const shownLocal = onSale ? sale : (regular !== null ? regular : (price !== null ? price : sale));

		if (usd !== null) {
			const usdOnSale = usdSale !== null && usdRegular !== null && usdSale < usdRegular;
			let sub = shownLocal === null ? '' : h(MJ.fmt.money(shownLocal, wc));
			if (usdOnSale) sub = '<s>' + h(moneyUsd(usdRegular, base)) + '</s> · ' + sub;
			return '<td class="is-num">' +
				'<span class="mj-strong">' + h(moneyUsd(usd, base)) + '</span>' +
				(sub ? '<div class="mj-table__sub mj-small mj-muted">' + sub + '</div>' : '') +
			'</td>';
		}

		if (onSale) {
			return '<td class="is-num">' +
				'<span class="mj-strong">' + h(MJ.fmt.money(sale, wc)) + '</span>' +
				'<div class="mj-table__sub mj-small mj-muted"><s>' + h(MJ.fmt.money(regular, wc)) + '</s> · oferta</div>' +
			'</td>';
		}
		return '<td class="is-num"><span class="mj-strong">' + h(MJ.fmt.money(shownLocal, wc)) + '</span></td>';
	}

	/** "US$ 129": no decimals when the amount is whole. */
	function moneyUsd(value, currency) {
		const n = num(value);
		if (n === null) return '—';
		const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
		return MJ.fmt.money(n, currency || baseCurrency(), decimals);
	}

	function pctCell(value, title) {
		const n = num(value);
		if (n === null) {
			return '<td class="is-num"><span class="mj-muted" title="Necesita costo y precio cargados">—</span></td>';
		}
		const cls = n < 0 ? 'mj-danger-text' : (n > 0 ? 'mj-success-text' : 'mj-muted');
		return '<td class="is-num"><span class="' + cls + '" title="' + h(title) + '">' + h(MJ.fmt.pct(n, 1)) + '</span></td>';
	}

	/**
	 * Margin measured in dollars when the product is published in dollars (cost and price are both
	 * in base currency, so it does not drift with the TC); otherwise the store-currency margin.
	 */
	function marginCell(p, usdKey, localKey, usdTitle, localTitle) {
		return num(p[usdKey]) !== null
			? pctCell(p[usdKey], usdTitle)
			: pctCell(p[localKey], localTitle);
	}

	function lastPurchaseCell(p) {
		const code = p.last_purchase_code ? String(p.last_purchase_code) : '';
		const purchaseId = parseInt(p.last_purchase_id, 10);
		const date = p.cost_updated_at ? MJ.fmt.date(p.cost_updated_at) : '';
		if (!code && !date) return '<td>' + dash() + '</td>';
		let html;
		if (code && purchaseId > 0) {
			html = '<a class="mj-link mj-mono" href="' + h(MJ.router.link('/compras/' + purchaseId)) + '" title="Abrir la compra ' + h(code) + '">' + h(code) + '</a>';
		} else if (code) {
			html = '<span class="mj-mono">' + h(code) + '</span>';
		} else {
			html = '<span class="mj-muted">Sin compra</span>';
		}
		if (date) html += '<div class="mj-table__sub mj-small mj-muted">' + h(date) + '</div>';
		return '<td>' + html + '</td>';
	}

	function actionsCell(p) {
		const name = p.name ? String(p.name) : ('#' + p.id);
		const edit = safeUrl(p.edit_link);
		const view = safeUrl(p.permalink);
		return '<td class="is-actions">' +
			'<div class="mj-btn-group">' +
				'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="movements" ' +
					'title="Movimientos de stock" aria-label="Ver movimientos de ' + h(name) + '">' + MJ.icon('list') + '</button>' +
				(edit
					? '<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(edit) + '" target="_blank" rel="noopener noreferrer" ' +
						'title="Editar en WooCommerce" aria-label="Editar ' + h(name) + ' en WooCommerce">' + MJ.icon('edit') + '</a>'
					: '') +
				(view
					? '<a class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" href="' + h(view) + '" target="_blank" rel="noopener noreferrer" ' +
						'title="Ver en la tienda" aria-label="Ver ' + h(name) + ' en la tienda">' + MJ.icon('eye') + '</a>'
					: '') +
			'</div>' +
		'</td>';
	}

	function rowHtml(p) {
		return '<tr data-id="' + h(p.id) + '">' +
			productCell(p) +
			statusCell(p) +
			stockCell(p) +
			costCell(p) +
			priceCell(p) +
			marginCell(p, 'margin_usd_pct', 'margin_pct', 'Margen en dólares, sobre el precio publicado', 'Margen sobre el precio de WooCommerce') +
			marginCell(p, 'markup_usd_pct', 'markup_pct', 'Markup en dólares, sobre el costo final', 'Markup sobre el costo') +
			lastPurchaseCell(p) +
			actionsCell(p) +
		'</tr>';
	}

	/* ------------------------------------------------------------------ */
	/* HTML builders                                                       */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Productos</h2>' +
				'<div class="mj-page-header__subtitle mj-muted" data-count>Costos, precios y márgenes del catálogo de WooCommerce</div>' +
			'</div>' +
		'</div>';
	}

	function perPageOptions(value) {
		return PER_PAGE_OPTIONS.map(function (n) {
			return '<option value="' + n + '"' + (n === value ? ' selected' : '') + '>' + n + ' por página</option>';
		}).join('');
	}

	function toolbarHtml(state) {
		return '<div class="mj-card mj-mb">' +
			'<div class="mj-card__body">' +
				'<form class="mj-form mj-inline-form mj-row mj-row--wrap" data-toolbar novalidate autocomplete="off">' +
					'<div class="mj-field" style="flex:2 1 260px">' +
						'<label class="mj-field__label" for="mj-prod-search">Buscar</label>' +
						'<div class="mj-input-group">' +
							'<span class="mj-input-group__addon">' + MJ.icon('search') + '</span>' +
							'<input class="mj-input" id="mj-prod-search" type="search" name="search" placeholder="Nombre o SKU…" value="' + h(state.search) + '">' +
						'</div>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 1 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<label class="mj-switch" title="Muestra solo los productos con costo cargado por el ERP">' +
							'<input type="checkbox" name="only_with_cost"' + (state.onlyWithCost ? ' checked' : '') + '>' +
							'<span class="mj-switch__slider"></span>' +
							'<span class="mj-switch__label">Solo con costo</span>' +
						'</label>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 0 150px">' +
						'<label class="mj-field__label" for="mj-prod-per">Filas</label>' +
						'<select class="mj-select" id="mj-prod-per" name="per_page">' + perPageOptions(state.perPage) + '</select>' +
					'</div>' +
					'<div class="mj-field" style="flex:0 0 auto">' +
						'<label class="mj-field__label">&nbsp;</label>' +
						'<div class="mj-btn-group">' +
							'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Actualizar" aria-label="Actualizar">' + MJ.icon('refresh') + '</button>' +
							'<button type="button" class="mj-btn mj-btn--ghost" data-action="clear-filters"' + (hasFilters(state) ? '' : ' disabled') + '>' + MJ.icon('x') + '<span>Limpiar</span></button>' +
							'<button type="button" class="mj-btn mj-btn--primary" data-action="new">' + MJ.icon('plus') + '<span>Nuevo producto</span></button>' +
						'</div>' +
					'</div>' +
				'</form>' +
			'</div>' +
		'</div>';
	}

	function noteHtml() {
		const base = baseCurrency();
		const wc = wcCurrency();
		return '<div class="mj-note mj-small mj-muted mj-mt">' +
			'El costo que ves acá es el último <strong>landed cost</strong> en ' + h(base) + ' (FOB del ítem + prorrateo de los gastos de esa importación) ' +
			'que el ERP escribió sobre el producto al <strong>recibir una compra</strong>; no se recalcula solo, cambia con cada recepción. ' +
			'El margen y el markup se calculan contra el <strong>precio de WooCommerce</strong> (' + h(wc) + ') usando el costo convertido con el TC de venta configurado (' + h(MJ.fmt.rate(setting('sale_rate', 1))) + ').' +
		'</div>';
	}

	function tableHtml(state) {
		const data = state.data;
		if (!data) return loadingHtml('Cargando productos…');
		if (!data.items.length) {
			if (hasFilters(state)) {
				return MJ.ui.empty({
					icon: 'search',
					title: 'Sin resultados',
					text: 'Ningún producto coincide con la búsqueda o el filtro de costo.',
					action: '<button type="button" class="mj-btn" data-action="clear-filters">' + MJ.icon('x') + '<span>Limpiar filtros</span></button>',
				});
			}
			return MJ.ui.empty({
				icon: 'box',
				title: 'Todavía no hay productos',
				text: 'Creá un producto desde acá o cargalo en WooCommerce para empezar a costearlo.',
				action: { label: 'Nuevo producto', icon: 'plus', action: 'new' },
			});
		}
		let html = '<div class="mj-table-wrap"><table class="mj-table"><thead><tr>' +
			'<th>Producto</th>' +
			'<th>Estado</th>' +
			'<th class="is-num">Stock</th>' +
			'<th class="is-num">Costo</th>' +
			'<th class="is-num">Precio</th>' +
			'<th class="is-num">Margen %</th>' +
			'<th class="is-num">Markup %</th>' +
			'<th>Última compra</th>' +
			'<th class="is-actions"><span class="mj-hidden">Acciones</span></th>' +
			'</tr></thead><tbody>';
		data.items.forEach(function (p) { html += rowHtml(p); });
		html += '</tbody></table></div>';
		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Movements modal                                                     */
	/* ------------------------------------------------------------------ */

	function movementTypeInfo(type) {
		const key = String(type || '');
		if (MOVEMENT_TYPES[key]) return MOVEMENT_TYPES[key];
		return { label: key ? key.replace(/_/g, ' ') : '—', color: 'neutral' };
	}

	function movementRowHtml(m) {
		const info = movementTypeInfo(m.type);
		const qty = num(m.qty);
		const before = num(m.stock_before);
		const after = num(m.stock_after);
		const cost = num(m.unit_cost_base);
		const code = m.ref_code ? String(m.ref_code) : '';
		const refId = parseInt(m.ref_id, 10);

		const qtyCls = qty === null ? 'mj-muted' : (qty < 0 ? 'mj-danger-text mj-strong' : 'mj-success-text mj-strong');
		const qtyText = qty === null ? '—' : ((qty > 0 ? '+' : '') + MJ.fmt.qty(qty));

		let stockHtml;
		if (before === null && after === null) {
			stockHtml = dash();
		} else {
			stockHtml = '<span class="mj-muted">' + h(before === null ? '—' : MJ.fmt.qty(before)) + '</span>' +
				' <span aria-hidden="true">→</span> ' +
				'<span class="mj-strong">' + h(after === null ? '—' : MJ.fmt.qty(after)) + '</span>';
		}

		let purchaseHtml;
		if (code && String(m.ref_type || '') === 'purchase' && refId > 0) {
			purchaseHtml = '<a class="mj-link mj-mono" href="' + h(MJ.router.link('/compras/' + refId)) + '" data-goto="' + h(refId) + '">' + h(code) + '</a>';
		} else if (code) {
			purchaseHtml = '<span class="mj-mono">' + h(code) + '</span>';
		} else {
			purchaseHtml = dash();
		}

		return '<tr>' +
			'<td><span class="mj-small">' + h(m.created_at ? MJ.fmt.datetime(m.created_at) : '—') + '</span></td>' +
			'<td>' + MJ.ui.badgeRaw(info.label, info.color) +
				(m.note ? '<div class="mj-table__sub mj-small mj-muted">' + h(m.note) + '</div>' : '') +
			'</td>' +
			'<td class="is-num"><span class="' + qtyCls + '">' + h(qtyText) + '</span></td>' +
			'<td class="is-num">' + stockHtml + '</td>' +
			'<td class="is-num">' + (cost === null ? dash() : '<span class="mj-money">' + h(MJ.fmt.money(cost, baseCurrency())) + '</span>') + '</td>' +
			'<td>' + purchaseHtml + '</td>' +
			'<td>' + (m.user_name ? h(m.user_name) : dash()) + '</td>' +
		'</tr>';
	}

	function movementsHeadHtml(product) {
		const name = product.name ? String(product.name) : ('#' + product.id);
		const bits = [];
		if (product.sku) bits.push('<span class="mj-mono">' + h(product.sku) + '</span>');
		if (product.is_variation) bits.push('<span class="mj-tag">Variación</span>');
		bits.push('<span>ID ' + h(product.id) + '</span>');
		return '<div class="mj-row mj-mb">' +
			thumbHtml(product) +
			'<div style="min-width:0">' +
				'<div class="mj-strong mj-truncate" title="' + h(name) + '">' + h(name) + '</div>' +
				'<div class="mj-small mj-muted">' + bits.join(' <span aria-hidden="true">·</span> ') + '</div>' +
			'</div>' +
		'</div>';
	}

	function movementsBodyHtml(product, rows) {
		let html = movementsHeadHtml(product);
		if (!rows.length) {
			html += MJ.ui.empty({
				icon: 'list',
				title: 'Sin movimientos',
				text: 'Este producto todavía no registra movimientos de stock generados por el ERP.',
			});
			return html;
		}
		html += '<div class="mj-table-wrap"><table class="mj-table mj-table--compact"><thead><tr>' +
			'<th>Fecha</th><th>Tipo</th><th class="is-num">Cantidad</th><th class="is-num">Stock</th>' +
			'<th class="is-num">Costo unitario</th><th>Compra</th><th>Usuario</th>' +
			'</tr></thead><tbody>';
		rows.forEach(function (m) { html += movementRowHtml(m); });
		html += '</tbody></table></div>';
		html += '<div class="mj-note mj-small mj-muted mj-mt">' +
			'Se listan los últimos ' + h(MJ.fmt.int(MOVEMENTS_LIMIT)) + ' movimientos registrados por el ERP (recepciones y reversiones de compras). ' +
			'Los ajustes hechos directamente en WooCommerce no aparecen acá.' +
		'</div>';
		return html;
	}

	function movementsErrorHtml(product, err) {
		return movementsHeadHtml(product) +
			MJ.ui.alert(MJ.api.message(err, 'No se pudieron cargar los movimientos.'), 'danger') +
			'<div class="mj-mt"><button type="button" class="mj-btn" data-action="retry">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>';
	}

	/**
	 * Opens the stock movements modal of a product/variation.
	 * @param {Object} product Product summary (needs at least `id`).
	 * @returns {Object|null} modal api
	 */
	function openMovementsModal(product) {
		if (!product || !product.id) return null;

		const modal = MJ.ui.modal({
			title: 'Movimientos de stock',
			size: 'lg',
			body: loadingHtml('Cargando movimientos…'),
			footer: '<button type="button" class="mj-btn mj-btn--ghost" data-modal-close>Cerrar</button>',
		});

		function load() {
			modal.setBody(loadingHtml('Cargando movimientos…'));
			MJ.api.get('products/' + encodeURIComponent(product.id) + '/movements', { limit: MOVEMENTS_LIMIT }).then(function (res) {
				if (!modal.isOpen()) return;
				const rows = Array.isArray(res) ? res : ((res && Array.isArray(res.items)) ? res.items : []);
				modal.setBody(movementsBodyHtml(product, rows));
			}).catch(function (err) {
				if (!modal.isOpen()) return;
				if (err && (err.handled || err.status === 401)) { modal.close(); return; }
				modal.setBody(movementsErrorHtml(product, err));
			});
		}

		MJ.ui.on(modal.el, 'click', '[data-action="retry"]', function (e) {
			e.preventDefault();
			load();
		});
		MJ.ui.on(modal.el, 'click', 'a[data-goto]', function (e, a) {
			e.preventDefault();
			const id = parseInt(a.getAttribute('data-goto'), 10);
			modal.close();
			if (id > 0) MJ.router.navigate('/compras/' + id);
		});

		load();
		return modal;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.products = {
		title: 'Productos',
		openMovementsModal: openMovementsModal,

		render(container, params, query) {
			const parsed = parseQuery(query);
			const state = {
				search: parsed.search,
				page: parsed.page,
				perPage: parsed.perPage,
				onlyWithCost: parsed.onlyWithCost,
				data: null,
				byId: {},
				loading: false,
				seq: 0,
				disposed: false,
			};
			const offs = [];

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			container.innerHTML = headerHtml() + toolbarHtml(state) +
				'<div class="mj-card mj-card--flush">' +
					'<div data-region="table">' + loadingHtml('Cargando productos…') + '</div>' +
					'<div class="mj-card__footer mj-hidden" data-region="pagination"></div>' +
				'</div>' +
				noteHtml();

			/* ---- painting ---- */

			function syncClearButton() {
				const btn = qs('[data-toolbar] [data-action="clear-filters"]', container);
				if (btn) btn.disabled = !hasFilters(state);
			}

			function paintCount() {
				const el = qs('[data-count]', container);
				if (!el) return;
				if (!state.data) {
					el.textContent = 'Costos, precios y márgenes del catálogo de WooCommerce';
					return;
				}
				const total = state.data.total;
				if (!total && !hasFilters(state)) {
					el.textContent = 'Costos, precios y márgenes del catálogo de WooCommerce';
					return;
				}
				const noun = total === 1 ? '1 producto' : MJ.fmt.int(total) + ' productos';
				const bits = [];
				if (state.search) bits.push('con «' + state.search + '»');
				if (state.onlyWithCost) bits.push('con costo cargado');
				el.textContent = noun + (bits.length ? ' ' + bits.join(' y ') : ' en el catálogo (incluye variaciones)');
			}

			function paintTable() {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = tableHtml(state);
			}

			function paintPagination() {
				const el = region('pagination');
				if (!el) return;
				if (!state.data || !state.data.items.length) {
					el.innerHTML = '';
					el.classList.add('mj-hidden');
					return;
				}
				el.classList.remove('mj-hidden');
				const pg = MJ.components.pagination({
					page: state.data.page,
					pages: state.data.pages,
					total: state.data.total,
					perPage: state.perPage,
					onChange: goToPage,
				});
				el.innerHTML = typeof pg === 'string' ? pg : (pg && pg.html ? pg.html : String(pg));
			}

			function paintError(err) {
				const el = region('table');
				if (!el) return;
				el.classList.remove('is-loading');
				el.removeAttribute('aria-busy');
				el.innerHTML = '<div class="mj-card__body">' +
					MJ.ui.alert(MJ.api.message(err, 'No se pudieron cargar los productos.'), 'danger') +
					'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
				'</div>';
				const pg = region('pagination');
				if (pg) { pg.innerHTML = ''; pg.classList.add('mj-hidden'); }
			}

			/* ---- data ---- */

			function load() {
				if (state.disposed) return Promise.resolve();
				const mine = ++state.seq;
				state.loading = true;
				const el = region('table');
				if (el) {
					if (!state.data) el.innerHTML = loadingHtml('Cargando productos…');
					else { el.classList.add('is-loading'); el.setAttribute('aria-busy', 'true'); }
				}

				const apiParams = { page: state.page, per_page: state.perPage };
				if (state.search) apiParams.search = state.search;
				if (state.onlyWithCost) apiParams.only_with_cost = 1;

				return MJ.api.get('products', apiParams).then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					res = res && typeof res === 'object' ? res : {};
					const items = Array.isArray(res.items) ? res.items : [];
					const pages = Math.max(1, parseInt(res.pages, 10) || 1);
					const page = parseInt(res.page, 10) || state.page;
					if (!items.length && state.page > 1 && pages < state.page) {
						state.page = pages;
						load();
						return;
					}
					state.data = { items: items, total: parseInt(res.total, 10) || 0, pages: pages, page: page };
					state.page = page;
					state.byId = {};
					items.forEach(function (p) { if (p && p.id !== undefined && p.id !== null) state.byId[String(p.id)] = p; });
					paintTable();
					paintPagination();
					paintCount();
					syncHash(state);
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					if (err && (err.handled || err.status === 401)) return;
					if (state.data) {
						paintTable();
						MJ.ui.toastError(err, 'No se pudieron cargar los productos.');
					} else {
						paintError(err);
					}
				});
			}

			/* ---- actions ---- */

			function applySearch(value) {
				const term = String(value || '').trim();
				if (term === state.search) return;
				state.search = term;
				state.page = 1;
				syncClearButton();
				load();
			}

			function applyOnlyWithCost(checked) {
				const flag = !!checked;
				if (flag === state.onlyWithCost) return;
				state.onlyWithCost = flag;
				state.page = 1;
				syncClearButton();
				load();
			}

			function applyPerPage(value) {
				const n = parseInt(value, 10);
				const perPage = PER_PAGE_OPTIONS.indexOf(n) >= 0 ? n : DEFAULT_PER_PAGE;
				if (perPage === state.perPage) return;
				state.perPage = perPage;
				state.page = 1;
				load();
			}

			function goToPage(page) {
				page = parseInt(page, 10);
				if (!page || page < 1 || page === state.page) return;
				state.page = page;
				load().then(function () {
					const el = region('table');
					if (el && el.scrollIntoView) {
						try { el.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { /* noop */ }
					}
				});
			}

			function clearFilters() {
				if (!hasFilters(state)) return;
				state.search = '';
				state.onlyWithCost = false;
				state.page = 1;
				const form = qs('[data-toolbar]', container);
				if (form) {
					const search = qs('[name="search"]', form);
					const only = qs('[name="only_with_cost"]', form);
					if (search) search.value = '';
					if (only) only.checked = false;
				}
				syncClearButton();
				load();
			}

			function openCreateModal() {
				try {
					MJ.components.productCreateModal({
						onCreated() {
							if (state.disposed) return;
							state.page = 1;
							load();
						},
					});
				} catch (e) {
					console.error('[MJ] productCreateModal', e);
					MJ.ui.toast('No se pudo abrir el formulario de producto.', 'error');
				}
			}

			function openMovements(btn) {
				const tr = btn.closest('tr[data-id]');
				if (!tr) return;
				const product = state.byId[String(tr.getAttribute('data-id'))];
				if (!product) {
					MJ.ui.toast('No se encontró el producto; actualizá la lista.', 'warning');
					return;
				}
				openMovementsModal(product);
			}

			/* ---- events ---- */

			function bind() {
				const form = qs('[data-toolbar]', container);
				if (form) {
					const search = qs('[name="search"]', form);
					if (search) {
						const onSearch = MJ.ui.debounce(function () { applySearch(search.value); }, SEARCH_DEBOUNCE);
						search.addEventListener('input', onSearch);
						search.addEventListener('search', function () {
							if (typeof onSearch.cancel === 'function') onSearch.cancel();
							applySearch(search.value);
						});
						offs.push(function () { search.removeEventListener('input', onSearch); });
					}
					form.addEventListener('submit', function (e) {
						e.preventDefault();
						if (search) applySearch(search.value);
					});
					offs.push(MJ.ui.on(form, 'change', '[name="only_with_cost"]', function (e, input) { applyOnlyWithCost(input.checked); }));
					offs.push(MJ.ui.on(form, 'change', '[name="per_page"]', function (e, sel) { applyPerPage(sel.value); }));
				}

				offs.push(MJ.ui.on(container, 'click', '[data-action]', function (e, btn) {
					const action = btn.getAttribute('data-action');
					if (action === 'new') {
						e.preventDefault();
						openCreateModal();
					} else if (action === 'reload') {
						e.preventDefault();
						MJ.ui.setLoading(btn, true);
						load().then(function () { if (!state.disposed) MJ.ui.setLoading(btn, false); });
					} else if (action === 'clear-filters') {
						e.preventDefault();
						clearFilters();
					} else if (action === 'movements') {
						e.preventDefault();
						openMovements(btn);
					}
				}));

				offs.push(MJ.components.bindPagination(region('pagination'), goToPage));
			}

			bind();
			load();

			return function cleanup() {
				state.disposed = true;
				state.seq++;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
			};
		},
	};
})();
