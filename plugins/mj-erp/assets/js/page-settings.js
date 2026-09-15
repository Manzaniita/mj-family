/**
 * MJ ERP — page-settings.js
 * MJ.pages.settings: configuración de la aplicación en pestañas (General, Compras, Listas de precio,
 * Recepción y stock, Acceso, Avanzado) con editores de listas, validación y guardado completo.
 *
 * La pestaña activa se refleja en el hash (`#/configuracion?tab=compras`). El modelo editado vive en
 * `state.model`; cada control escribe en él (`data-setting`) y el guardado envía el objeto completo.
 *
 * REST usado: GET /settings, PUT /settings.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	/* ------------------------------------------------------------------ */
	/* Constants                                                           */
	/* ------------------------------------------------------------------ */

	const PAGE_PATH = '/configuracion';
	const EXAMPLE_COST = 10.72;
	const LOCKED_EXPENSE_TYPE = 'otro';
	const RESERVED_SLUGS = ['wp-admin', 'wp-json', 'wp-login', 'wp-content', 'wp-includes', 'feed', 'shop', 'cart', 'checkout', 'my-account'];

	/**
	 * Cuándo una venta se vuelve firme: cuándo se emite el pedido y cuándo baja el stock.
	 *
	 * Anotar una venta no reserva nada. Lo que compromete la mercadería es que el cliente la pague
	 * o que se la lleve; cuál de las dos vale lo elige el dueño de la tienda.
	 */
	const COMMIT_WHEN_OPTIONS = [
		{ value: 'pago_o_entrega', label: 'Cuando se cobra o se entrega' },
		{ value: 'pago', label: 'Solo cuando se cobra' },
		{ value: 'entrega', label: 'Solo cuando se entrega' },
		{ value: 'registrar', label: 'Al registrar la venta' },
		{ value: 'manual', label: 'Nunca sola (a mano desde la venta)' },
	];
	const COMMIT_WHEN_VALUES = COMMIT_WHEN_OPTIONS.map(function (o) { return o.value; });

	const TABS = [
		{ key: 'general', label: 'General', icon: 'settings' },
		{ key: 'ventas', label: 'Ventas', icon: 'cash' },
		{ key: 'clientes', label: 'Clientes', icon: 'user' },
		{ key: 'compras', label: 'Compras', icon: 'cart' },
		{ key: 'precios', label: 'Listas de precio', icon: 'tag' },
		{ key: 'stock', label: 'Recepción y stock', icon: 'box' },
		{ key: 'consignaciones', label: 'Consignaciones', icon: 'layers' },
		{ key: 'acceso', label: 'Acceso', icon: 'users' },
		{ key: 'avanzado', label: 'Avanzado', icon: 'zap' },
	];

	/** Setting key → tab that contains it (used to jump to the first invalid field). */
	const KEY_TAB = {
		app_title: 'general', route_slug: 'general', theme: 'general', locale: 'general', decimals: 'general',
		shop_link: 'general', shop_link_label: 'general', shop_link_url: 'general',
		base_currency: 'general', local_currency: 'general', currencies: 'general', currency_symbols: 'general',
		default_rate: 'general', sale_rate: 'general', sale_rate_source: 'general', proration_method: 'general',
		code_prefix: 'compras', code_format: 'compras', code_seq_digits: 'compras',
		destinations: 'compras', expense_types: 'compras',
		price_lists: 'precios', price_list_for_price: 'precios',
		stock_on_receive: 'stock', enable_stock_management: 'stock', update_cost_on_receive: 'stock',
		cost_meta_key: 'stock', update_price_on_receive: 'stock', price_target: 'stock',
		price_rounding: 'stock', allow_unreceive: 'stock', new_product_status: 'stock',
		price_publish_mode: 'stock', usd_rounding: 'stock', usd_rounding_mode: 'stock',
		sales_enabled: 'ventas', sales_code_prefix: 'ventas', sales_code_format: 'ventas', sales_code_seq_digits: 'ventas',
		sales_channels: 'ventas', sales_default_channel: 'ventas', sales_shop_channel: 'ventas',
		sales_payment_methods: 'ventas', sales_default_payment_method: 'ventas', sales_default_cashbox: 'ventas',
		sales_price_list: 'ventas', sales_track_cost: 'ventas', sales_allow_discount: 'ventas', sales_allow_oversell: 'ventas',
		sales_create_order: 'ventas', sales_commit_when: 'ventas', sales_order_status_paid: 'ventas', sales_order_status_unpaid: 'ventas',
		sales_order_created_via: 'ventas', sales_order_note: 'ventas', sales_reduce_stock: 'ventas',
		sales_capture_shop_orders: 'ventas', sales_capture_manual_orders: 'ventas', sales_capture_payment: 'ventas',
		sales_capture_on: 'ventas', sales_capture_statuses: 'ventas', sales_void_statuses: 'ventas',
		sales_consignment_enabled: 'ventas', sales_consignment_allocation: 'ventas', sales_consignment_approval: 'ventas',
		sales_consignment_partial_lines: 'ventas', sales_consignment_payment_method: 'ventas',
		shop_price_mode: 'ventas', shop_price_list: 'ventas', shop_price_notice: 'ventas',
		sales_price_rounding_mode: 'ventas', sales_price_rounding: 'ventas',
		sales_pos_per_page: 'ventas', sales_pos_images: 'ventas', sales_pos_steps_width: 'ventas',
		sales_delivery_enabled: 'ventas', sales_delivery_methods: 'ventas',
		sales_default_delivery_method: 'ventas', sales_shop_delivery_method: 'ventas',
		sales_admin_roles: 'ventas', sales_log_enabled: 'ventas',
		sales_edit_enabled: 'ventas', sales_edit_require_reason: 'ventas', sales_edit_redeliver: 'ventas',
		customers_enabled: 'clientes', customers_doc_types: 'clientes', customers_default_doc_type: 'clientes',
		customers_link_mode: 'clientes', customers_link_match: 'clientes', customers_link_backfill: 'clientes',
		customers_quick_create: 'clientes', customers_require_on_sale: 'clientes', customers_fields: 'clientes',
		portal_permissions: 'consignaciones',
		consignee_role: 'consignaciones', consignee_role_label: 'consignaciones',
		consignment_code_prefix: 'consignaciones', consignment_code_format: 'consignaciones',
		consignment_code_seq_digits: 'consignaciones', consignment_price_list: 'consignaciones',
		consignment_stock_on_sale: 'consignaciones', consignment_approval: 'consignaciones',
		consignment_allow_oversell: 'consignaciones', consignment_allocation: 'consignaciones',
		consignment_payment_methods: 'consignaciones',
		portal_enabled: 'consignaciones', portal_slug: 'consignaciones', portal_title: 'consignaciones',
		portal_allow_sales: 'consignaciones', portal_allow_returns: 'consignaciones', portal_undo_minutes: 'consignaciones',
		allowed_roles: 'acceso',
		delete_data_on_uninstall: 'avanzado',
	};

	/** Mirrors MJERP_Settings::defaults(); also defines the exact payload sent on PUT. */
	const DEFAULTS = {
		route_slug: 'mjerp',
		app_title: 'MJ ERP',
		shop_link: true,
		shop_link_label: '',
		shop_link_url: '',
		theme: 'dark',
		locale: 'es-AR',
		decimals: 2,
		base_currency: 'USD',
		local_currency: 'ARS',
		currencies: ['USD', 'ARS'],
		currency_symbols: { USD: 'US$', ARS: '$' },
		default_rate: 1000,
		sale_rate: 1000,
		sale_rate_source: 'mjdolar',
		proration_method: 'fob',
		destinations: ['Local', 'Depósito'],
		expense_types: [
			{ key: 'envio', label: 'Envío' },
			{ key: 'despachante', label: 'Despachante' },
			{ key: 'impuestos', label: 'Impuestos' },
			{ key: 'comision', label: 'Comisión' },
			{ key: 'otro', label: 'Otro' },
		],
		price_lists: [
			{ key: 'publico', label: 'Público', markup: 70 },
			{ key: 'mayorista', label: 'Mayorista', markup: 40 },
		],
		code_prefix: 'IMP',
		code_format: '{prefix}-{year}-{seq}',
		code_seq_digits: 4,
		stock_on_receive: 'increase',
		enable_stock_management: true,
		update_cost_on_receive: true,
		cost_meta_key: '_mjerp_cost',
		update_price_on_receive: false,
		price_list_for_price: 'publico',
		price_target: 'regular_price',
		price_rounding: 0,
		price_publish_mode: 'auto',
		usd_rounding: 0,
		usd_rounding_mode: 'nearest',
		allow_unreceive: true,
		consignee_role: 'reventa',
		consignee_role_label: 'Reventa',
		consignment_code_prefix: 'CON',
		consignment_code_format: '{prefix}-{year}-{seq}',
		consignment_code_seq_digits: 4,
		consignment_price_list: 'mayorista',
		consignment_stock_on_sale: 'decrease',
		consignment_approval: 'never',
		consignment_allow_oversell: false,
		consignment_allocation: 'fifo',
		consignment_payment_methods: [
			{ key: 'efectivo', label: 'Efectivo', cashbox_id: 0 },
			{ key: 'transferencia', label: 'Transferencia', cashbox_id: 0 },
			{ key: 'dolares', label: 'Dólares', cashbox_id: 0 },
			{ key: 'otro', label: 'Otro', cashbox_id: 0 },
		],
		portal_enabled: true,
		portal_slug: 'reventa',
		portal_title: 'Mi cuenta',
		portal_allow_sales: true,
		portal_allow_returns: false,
		portal_undo_minutes: 1440,
		portal_permissions: {
			defaults: {
				report_sale: true,
				register_payment: false,
				partial_payments: true,
				payment_needs_approval: true,
				payment_methods: [],
				payment_receipt: 'heredar',
				returns: false,
				see_account: true,
				shop_consignment: true,
				price_mode: 'heredar',
				price_list_key: '',
			},
			roles: {},
		},
		sales_enabled: true,
		sales_code_prefix: 'VTA',
		sales_code_format: '{prefix}-{year}-{seq}',
		sales_code_seq_digits: 4,
		sales_channels: [
			{ key: 'mostrador', label: 'Mostrador' },
			{ key: 'whatsapp', label: 'WhatsApp' },
			{ key: 'feria', label: 'Feria' },
			{ key: 'tienda', label: 'Tienda online' },
			{ key: 'otro', label: 'Otro' },
		],
		sales_default_channel: 'mostrador',
		sales_shop_channel: 'tienda',
		// cashbox_id = caja de ingreso de ese medio (0 = la caja por defecto).
		sales_payment_methods: [
			{ key: 'efectivo', label: 'Efectivo', cashbox_id: 0 },
			{ key: 'transferencia', label: 'Transferencia', cashbox_id: 0 },
			{ key: 'tarjeta', label: 'Tarjeta', cashbox_id: 0 },
			{ key: 'dolares', label: 'Dólares', cashbox_id: 0 },
			{ key: 'cuenta_corriente', label: 'Cuenta corriente', cashbox_id: 0 },
			{ key: 'otro', label: 'Otro', cashbox_id: 0 },
		],
		sales_default_payment_method: 'efectivo',
		sales_default_cashbox: 0,
		sales_price_list: 'publico',
		sales_price_rounding_mode: 'tienda',
		sales_price_rounding: 0,
		sales_pos_per_page: 24,
		sales_pos_images: true,
		sales_pos_steps_width: 900,
		sales_delivery_enabled: true,
		sales_delivery_methods: [
			{ key: 'mostrador', label: 'Entregado en el momento', delivered: 1 },
			{ key: 'punto_encuentro', label: 'Punto de encuentro', delivered: 0 },
			{ key: 'envio', label: 'Envío', delivered: 0 },
			{ key: 'retira_local', label: 'Retira en el local', delivered: 0 },
		],
		sales_default_delivery_method: 'mostrador',
		sales_shop_delivery_method: 'envio',
		sales_admin_roles: ['administrator'],
		sales_log_enabled: true,
		sales_edit_enabled: true,
		sales_edit_require_reason: false,
		sales_edit_redeliver: true,
		customers_enabled: true,
		customers_doc_types: [
			{ key: 'dni', label: 'DNI' },
			{ key: 'cuit', label: 'CUIT' },
			{ key: 'cuil', label: 'CUIL' },
			{ key: 'pasaporte', label: 'Pasaporte' },
			{ key: 'otro', label: 'Otro' },
		],
		customers_default_doc_type: 'dni',
		customers_link_mode: 'sugerir',
		customers_link_match: ['email'],
		customers_link_backfill: true,
		customers_quick_create: true,
		customers_require_on_sale: false,
		customers_fields: ['phone', 'email', 'doc', 'address', 'notes'],
		sales_track_cost: true,
		sales_allow_discount: true,
		sales_allow_oversell: false,
		sales_create_order: true,
		sales_commit_when: 'pago_o_entrega',
		sales_order_status_paid: 'completed',
		sales_order_status_unpaid: 'on-hold',
		sales_order_created_via: 'mj-erp',
		sales_order_note: 'Venta registrada desde MJ ERP',
		sales_reduce_stock: 'auto',
		sales_capture_shop_orders: true,
		sales_capture_manual_orders: true,
		sales_capture_payment: true,
		sales_capture_on: 'creada',
		sales_capture_statuses: ['processing', 'completed'],
		sales_void_statuses: ['cancelled', 'refunded', 'failed'],
		sales_consignment_enabled: true,
		sales_consignment_allocation: 'fifo',
		sales_consignment_approval: 'auto',
		sales_consignment_partial_lines: true,
		sales_consignment_payment_method: 'cuenta_corriente',
		shop_price_mode: 'publico',
		shop_price_list: 'mayorista',
		shop_price_notice: true,
		allowed_roles: ['administrator', 'shop_manager'],
		new_product_status: 'draft',
		delete_data_on_uninstall: false,
	};

	const THEME_OPTIONS = [
		{ value: 'dark', label: 'Oscuro' },
		{ value: 'light', label: 'Claro' },
	];
	const LOCALE_OPTIONS = [
		{ value: 'es-AR', label: 'Español (Argentina) · es-AR' },
		{ value: 'es-CL', label: 'Español (Chile) · es-CL' },
		{ value: 'es-CO', label: 'Español (Colombia) · es-CO' },
		{ value: 'es-MX', label: 'Español (México) · es-MX' },
		{ value: 'es-UY', label: 'Español (Uruguay) · es-UY' },
		{ value: 'es-ES', label: 'Español (España) · es-ES' },
		{ value: 'en-US', label: 'Inglés (Estados Unidos) · en-US' },
		{ value: 'pt-BR', label: 'Portugués (Brasil) · pt-BR' },
	];
	const PRORATION_OPTIONS = [
		{ value: 'fob', label: 'Por FOB (proporcional al valor)' },
		{ value: 'qty', label: 'Por cantidad (proporcional a las unidades)' },
		{ value: 'none', label: 'Sin prorrateo' },
	];
	const STOCK_ON_RECEIVE_OPTIONS = [
		{ value: 'increase', label: 'Sumar las unidades recibidas' },
		{ value: 'none', label: 'No modificar el stock' },
	];
	const PRICE_TARGET_OPTIONS = [
		{ value: 'regular_price', label: 'Precio normal (regular_price)' },
		{ value: 'sale_price', label: 'Precio de oferta (sale_price)' },
	];
	const ROUNDING_OPTIONS = [
		{ value: '0', label: 'Sin redondeo' },
		{ value: '10', label: 'Al múltiplo de 10 más cercano' },
		{ value: '50', label: 'Al múltiplo de 50 más cercano' },
		{ value: '100', label: 'Al múltiplo de 100 más cercano' },
		{ value: '500', label: 'Al múltiplo de 500 más cercano' },
		{ value: '1000', label: 'Al múltiplo de 1.000 más cercano' },
	];
	const ROUNDING_VALUES = [0, 10, 50, 100, 500, 1000];
	const PRODUCT_STATUS_OPTIONS = [
		{ value: 'draft', label: 'Borrador' },
		{ value: 'publish', label: 'Publicado' },
	];
	/** Where the suggested price is written when a purchase is received. */
	const PRICE_PUBLISH_OPTIONS = [
		{ value: 'auto', label: 'Automático: en dólares si MJ Dólar está listo' },
		{ value: 'usd', label: 'En dólares, vía MJ Dólar' },
		{ value: 'local', label: 'En la moneda de la tienda (precio fijo)' },
	];
	/** Mirrors MJERP_Settings::usd_rounding_choices(). */
	const USD_ROUNDING_OPTIONS = [
		{ value: '0', label: 'Sin redondeo (con centavos)' },
		{ value: '0.5', label: 'A los 50 centavos' },
		{ value: '1', label: 'Al dólar' },
		{ value: '5', label: 'A los 5 dólares' },
		{ value: '10', label: 'A los 10 dólares' },
	];
	const USD_ROUNDING_VALUES = [0, 0.5, 1, 5, 10];
	const USD_ROUNDING_MODE_OPTIONS = [
		{ value: 'nearest', label: 'Al valor más cercano' },
		{ value: 'up', label: 'Siempre para arriba' },
	];
	const SALE_RATE_SOURCE_OPTIONS = [
		{ value: 'mjdolar', label: 'El TC de MJ Dólar (recomendado)' },
		{ value: 'manual', label: 'Un TC propio del ERP' },
	];
	/* --- Consignaciones ---------------------------------------------------- */
	const CONSIGN_STOCK_OPTIONS = [
		{ value: 'decrease', label: 'Bajar el stock cuando el revendedor vende' },
		{ value: 'none', label: 'No tocar el stock de WooCommerce' },
	];
	const CONSIGN_APPROVAL_OPTIONS = [
		{ value: 'never', label: 'Ninguna: la venta vale desde que se carga (recomendado)' },
		{ value: 'portal', label: 'Solo lo que carga el revendedor' },
		{ value: 'always', label: 'Todas las ventas, también las que cargo yo' },
	];
	const CONSIGN_ALLOCATION_OPTIONS = [
		{ value: 'fifo', label: 'De la venta más vieja a la más nueva (recomendado)' },
		{ value: 'manual', label: 'Solo lo que elija a mano en cada pago' },
	];

	/* ------------------------------------------------------------------ */
	/* Local helpers                                                       */
	/* ------------------------------------------------------------------ */

	function str(v) { return (v === null || v === undefined) ? '' : String(v); }

	/**
	 * Snaps a USD rounding step to one of the offered values. The server accepts only those, so a
	 * value coming from an older payload (or a hand-edited option) has to land on one of them.
	 */
	function nearestUsdStep(value) {
		const v = toFloat(value, 0);
		let best = USD_ROUNDING_VALUES[0];
		USD_ROUNDING_VALUES.forEach(function (step) {
			if (Math.abs(step - v) < Math.abs(best - v)) best = step;
		});
		return best;
	}

	/** Read-only MJ Dólar block injected into the settings by MJERP_Dolar. */
	function dolarOf(m) {
		const d = m && m.dolar;
		return d && typeof d === 'object' ? d : { active: false, ready: false, rate: 0, publish_mode: 'local' };
	}

	/** Whether the settings as edited would publish the suggested price in dollars. */
	function willPublishUsd(m) {
		const d = dolarOf(m);
		return str(m.price_publish_mode) !== 'local' && !!d.active && toFloat(d.rate, 0) > 0;
	}

	/**
	 * USD context built from the model being edited (not from the saved settings), so the examples
	 * react to the selects as you change them.
	 */
	function usdCfgOf(m) {
		const d = dolarOf(m);
		if (!MJ.calc || typeof MJ.calc.usdConfig !== 'function') return null;
		return MJ.calc.usdConfig({
			rounding: nearestUsdStep(m.usd_rounding),
			mode: m.usd_rounding_mode,
			store_rate: d.rate,
			store_rounding: d.rounding,
			store_mode: d.rounding_mode,
		});
	}

	function clone(v) {
		try { return JSON.parse(JSON.stringify(v)); } catch (e) { return v; }
	}

	function isPlainObject(v) { return !!v && typeof v === 'object' && !Array.isArray(v); }

	function toBool(v, def) {
		if (v === true || v === false) return v;
		if (v === undefined || v === null || v === '') return !!def;
		if (typeof v === 'number') return v !== 0;
		const s = String(v).trim().toLowerCase();
		if (['1', 'true', 'yes', 'si', 'sí', 'on'].indexOf(s) >= 0) return true;
		if (['0', 'false', 'no', 'off'].indexOf(s) >= 0) return false;
		return !!def;
	}

	function toFloat(v, def) {
		if (typeof v === 'number') return isFinite(v) ? v : def;
		const n = MJ.fmt.parse(v);
		return isNaN(n) ? def : n;
	}

	function toInt(v, def) {
		const n = toFloat(v, NaN);
		return isNaN(n) ? def : Math.round(n);
	}

	function clampInt(n, min, max) {
		if (isNaN(n)) return min;
		return Math.min(max, Math.max(min, n));
	}

	function pad2(n) { return (n < 10 ? '0' : '') + n; }

	function padLeft(value, len) {
		let s = String(value);
		while (s.length < len) s = '0' + s;
		return s;
	}

	/** Machine-readable text for a numeric input (no thousand separators). */
	function numText(v) {
		if (typeof v === 'number') return isFinite(v) ? String(v) : '';
		return str(v);
	}

	/** Same idea as sanitize_key(sanitize_title($label)) on the PHP side. */
	function slugify(value) {
		let s = str(value).toLowerCase().trim();
		s = s.replace(/[áàäâãå]/g, 'a').replace(/[éèëê]/g, 'e').replace(/[íìïî]/g, 'i')
			.replace(/[óòöôõ]/g, 'o').replace(/[úùüû]/g, 'u').replace(/[ñ]/g, 'n').replace(/[ç]/g, 'c');
		s = s.replace(/[^a-z0-9_-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '');
		return s;
	}

	function cleanCurrency(value) {
		return str(value).toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
	}

	function errMessage(err, fallback) {
		if (!err) return fallback || 'Ocurrió un error inesperado.';
		if (typeof err === 'string') return err;
		if (err.message) return String(err.message);
		return fallback || 'Ocurrió un error inesperado.';
	}

	function toastError(err, fallback) {
		if (!err || err.handled || err.status === 401) return;
		MJ.ui.toastError(err, fallback);
	}

	function loadingHtml(text) {
		return '<div class="mj-loading">' + MJ.ui.spinner() + '<span>' + h(text || 'Cargando…') + '</span></div>';
	}

	function buildHashQuery(obj) {
		const pairs = [];
		Object.keys(obj || {}).forEach(function (k) {
			const v = obj[k];
			if (v === undefined || v === null || v === '') return;
			pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
		});
		return pairs.length ? '?' + pairs.join('&') : '';
	}

	function replaceHash(path, query) {
		const target = '#' + path + buildHashQuery(query);
		if (window.location.hash === target) return;
		try { window.history.replaceState(null, '', target); } catch (e) { /* noop: the URL simply is not synced */ }
	}

	function optionsHtml(list, value) {
		return (list || []).map(function (o) {
			return '<option value="' + h(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + h(o.label) + '</option>';
		}).join('');
	}

	/** Currency symbol from the edited model (not from the saved settings). */
	function symbolOf(model, currency) {
		const cur = cleanCurrency(currency) || str(currency).toUpperCase();
		const symbols = isPlainObject(model.currency_symbols) ? model.currency_symbols : {};
		const sym = symbols[cur];
		return sym ? String(sym) : cur;
	}

	/** Money formatting using the edited model's symbols/decimals. */
	function moneyOf(model, value, currency, decimals) {
		const n = MJ.fmt.toNumber(value);
		if (n === null) return '—';
		const d = (decimals === undefined || decimals === null) ? clampInt(toInt(model.decimals, 2), 0, 6) : decimals;
		return (n < 0 ? '-' : '') + symbolOf(model, currency) + ' ' + MJ.fmt.number(Math.abs(n), d);
	}

	/* ------------------------------------------------------------------ */
	/* Model                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Builds the editable model from a raw settings object (server or bootstrap).
	 * Values keep their real types; numeric inputs may later hold raw strings while typing.
	 *
	 * @param {Object} raw Settings coming from the API.
	 * @returns {Object} Normalized model with every key of DEFAULTS.
	 */
	function normalize(raw) {
		const src = isPlainObject(raw) ? raw : {};
		const m = clone(DEFAULTS);

		m.route_slug = slugify(src.route_slug !== undefined ? src.route_slug : m.route_slug) || 'mjerp';
		m.app_title = str(src.app_title !== undefined ? src.app_title : m.app_title).trim() || DEFAULTS.app_title;
		m.shop_link = toBool(src.shop_link, DEFAULTS.shop_link);
		m.shop_link_label = str(src.shop_link_label !== undefined ? src.shop_link_label : m.shop_link_label).trim();
		m.shop_link_url = str(src.shop_link_url !== undefined ? src.shop_link_url : m.shop_link_url).trim();
		m.theme = str(src.theme).toLowerCase() === 'light' ? 'light' : 'dark';
		m.locale = str(src.locale !== undefined ? src.locale : m.locale).trim() || DEFAULTS.locale;
		m.decimals = clampInt(toInt(src.decimals, DEFAULTS.decimals), 0, 6);

		const currencies = [];
		const rawCurrencies = Array.isArray(src.currencies) ? src.currencies : DEFAULTS.currencies;
		rawCurrencies.forEach(function (c) {
			const code = cleanCurrency(c);
			if (code && currencies.indexOf(code) === -1) currencies.push(code);
		});
		m.base_currency = cleanCurrency(src.base_currency) || DEFAULTS.base_currency;
		m.local_currency = cleanCurrency(src.local_currency) || DEFAULTS.local_currency;
		[m.base_currency, m.local_currency].forEach(function (c) {
			if (c && currencies.indexOf(c) === -1) currencies.push(c);
		});
		m.currencies = currencies.length ? currencies : clone(DEFAULTS.currencies);

		m.currency_symbols = {};
		const rawSymbols = isPlainObject(src.currency_symbols) ? src.currency_symbols : DEFAULTS.currency_symbols;
		Object.keys(rawSymbols).forEach(function (code) {
			const key = cleanCurrency(code);
			if (key) m.currency_symbols[key] = str(rawSymbols[code]);
		});

		m.default_rate = toFloat(src.default_rate, DEFAULTS.default_rate);
		m.sale_rate = toFloat(src.sale_rate, DEFAULTS.sale_rate);
		m.sale_rate_source = str(src.sale_rate_source) === 'manual' ? 'manual' : 'mjdolar';
		m.proration_method = ['fob', 'qty', 'none'].indexOf(str(src.proration_method)) >= 0 ? str(src.proration_method) : DEFAULTS.proration_method;

		// Read-only block injected by MJERP_Dolar; never sent back on save.
		m.dolar = isPlainObject(src.dolar) ? clone(src.dolar) : { active: false, ready: false, rate: 0, publish_mode: 'local' };

		m.destinations = (Array.isArray(src.destinations) ? src.destinations : DEFAULTS.destinations)
			.map(function (d) { return str(d).trim(); })
			.filter(function (d) { return d !== ''; });

		m.expense_types = normalizePairs(src.expense_types, DEFAULTS.expense_types, false);
		if (!m.expense_types.some(function (t) { return t.key === LOCKED_EXPENSE_TYPE; })) {
			m.expense_types.push({ key: LOCKED_EXPENSE_TYPE, label: 'Otro' });
		}
		m.price_lists = normalizePairs(src.price_lists, DEFAULTS.price_lists, true);

		m.code_prefix = str(src.code_prefix !== undefined ? src.code_prefix : m.code_prefix).trim();
		m.code_format = str(src.code_format !== undefined ? src.code_format : m.code_format).trim() || DEFAULTS.code_format;
		m.code_seq_digits = clampInt(toInt(src.code_seq_digits, DEFAULTS.code_seq_digits), 1, 10);

		m.stock_on_receive = str(src.stock_on_receive) === 'none' ? 'none' : 'increase';
		m.enable_stock_management = toBool(src.enable_stock_management, DEFAULTS.enable_stock_management);
		m.update_cost_on_receive = toBool(src.update_cost_on_receive, DEFAULTS.update_cost_on_receive);
		m.cost_meta_key = str(src.cost_meta_key !== undefined ? src.cost_meta_key : m.cost_meta_key).trim() || DEFAULTS.cost_meta_key;
		m.update_price_on_receive = toBool(src.update_price_on_receive, DEFAULTS.update_price_on_receive);

		const listKeys = m.price_lists.map(function (p) { return p.key; });
		const wantedList = str(src.price_list_for_price !== undefined ? src.price_list_for_price : m.price_list_for_price);
		m.price_list_for_price = listKeys.indexOf(wantedList) >= 0 ? wantedList : (listKeys.length ? listKeys[0] : '');

		m.price_target = str(src.price_target) === 'sale_price' ? 'sale_price' : 'regular_price';
		const rounding = toInt(src.price_rounding, DEFAULTS.price_rounding);
		m.price_rounding = ROUNDING_VALUES.indexOf(rounding) >= 0 ? rounding : 0;

		const publishMode = str(src.price_publish_mode);
		m.price_publish_mode = ['auto', 'usd', 'local'].indexOf(publishMode) >= 0 ? publishMode : DEFAULTS.price_publish_mode;
		m.usd_rounding = nearestUsdStep(toFloat(src.usd_rounding, DEFAULTS.usd_rounding));
		m.usd_rounding_mode = str(src.usd_rounding_mode) === 'up' ? 'up' : 'nearest';

		m.allow_unreceive = toBool(src.allow_unreceive, DEFAULTS.allow_unreceive);
		m.new_product_status = str(src.new_product_status) === 'publish' ? 'publish' : 'draft';

		/* --- Consignaciones ------------------------------------------------ */
		m.consignee_role = slugify(src.consignee_role !== undefined ? src.consignee_role : m.consignee_role).replace(/-/g, '_') || DEFAULTS.consignee_role;
		m.consignee_role_label = str(src.consignee_role_label !== undefined ? src.consignee_role_label : m.consignee_role_label).trim() || DEFAULTS.consignee_role_label;
		m.consignment_code_prefix = str(src.consignment_code_prefix !== undefined ? src.consignment_code_prefix : m.consignment_code_prefix).trim() || DEFAULTS.consignment_code_prefix;
		m.consignment_code_format = str(src.consignment_code_format !== undefined ? src.consignment_code_format : m.consignment_code_format).trim() || DEFAULTS.consignment_code_format;
		m.consignment_code_seq_digits = clampInt(toInt(src.consignment_code_seq_digits, DEFAULTS.consignment_code_seq_digits), 1, 10);

		const consignList = str(src.consignment_price_list !== undefined ? src.consignment_price_list : m.consignment_price_list);
		m.consignment_price_list = listKeys.indexOf(consignList) >= 0 ? consignList : m.price_list_for_price;

		m.consignment_stock_on_sale = str(src.consignment_stock_on_sale) === 'none' ? 'none' : 'decrease';
		const approval = str(src.consignment_approval);
		m.consignment_approval = ['portal', 'always', 'never'].indexOf(approval) >= 0 ? approval : DEFAULTS.consignment_approval;
		m.consignment_allow_oversell = toBool(src.consignment_allow_oversell, DEFAULTS.consignment_allow_oversell);
		m.consignment_allocation = str(src.consignment_allocation) === 'manual' ? 'manual' : 'fifo';
		m.consignment_payment_methods = normalizePairs(src.consignment_payment_methods, DEFAULTS.consignment_payment_methods, false, true);
		if (!m.consignment_payment_methods.some(function (x) { return x.key === 'otro'; })) {
			m.consignment_payment_methods.push({ key: 'otro', label: 'Otro', cashbox_id: 0 });
		}

		m.portal_enabled = toBool(src.portal_enabled, DEFAULTS.portal_enabled);
		m.portal_slug = slugify(src.portal_slug !== undefined ? src.portal_slug : m.portal_slug) || DEFAULTS.portal_slug;
		m.portal_title = str(src.portal_title !== undefined ? src.portal_title : m.portal_title).trim() || DEFAULTS.portal_title;
		m.portal_allow_sales = toBool(src.portal_allow_sales, DEFAULTS.portal_allow_sales);
		m.portal_allow_returns = toBool(src.portal_allow_returns, DEFAULTS.portal_allow_returns);
		m.portal_undo_minutes = clampInt(toInt(src.portal_undo_minutes, DEFAULTS.portal_undo_minutes), 0, 10080);

		m.portal_permissions = normalizePermissions(src.portal_permissions);

		/* --- Ventas ---------------------------------------------------------
		 * Todo este bloque faltaba: el modelo se armaba con los valores por defecto y el panel
		 * de Ventas nunca mostraba (ni podia guardar) lo que estaba realmente configurado.
		 * -------------------------------------------------------------------- */
		m.sales_enabled = toBool(src.sales_enabled, DEFAULTS.sales_enabled);
		m.sales_code_prefix = str(src.sales_code_prefix !== undefined ? src.sales_code_prefix : m.sales_code_prefix).trim() || DEFAULTS.sales_code_prefix;
		m.sales_code_format = str(src.sales_code_format !== undefined ? src.sales_code_format : m.sales_code_format).trim() || DEFAULTS.sales_code_format;
		m.sales_code_seq_digits = clampInt(toInt(src.sales_code_seq_digits, DEFAULTS.sales_code_seq_digits), 1, 10);

		m.sales_channels = normalizePairs(src.sales_channels, DEFAULTS.sales_channels, false);
		if (!m.sales_channels.some(function (x) { return x.key === 'otro'; })) {
			m.sales_channels.push({ key: 'otro', label: 'Otro' });
		}
		m.sales_payment_methods = normalizePairs(src.sales_payment_methods, DEFAULTS.sales_payment_methods, false, true);
		if (!m.sales_payment_methods.some(function (x) { return x.key === 'otro'; })) {
			m.sales_payment_methods.push({ key: 'otro', label: 'Otro', cashbox_id: 0 });
		}

		const channelKeys = m.sales_channels.map(function (c) { return c.key; });
		const methodKeys = m.sales_payment_methods.map(function (c) { return c.key; });
		m.sales_default_channel = pickKey(src.sales_default_channel, channelKeys, DEFAULTS.sales_default_channel);
		m.sales_shop_channel = pickKey(src.sales_shop_channel, channelKeys, DEFAULTS.sales_shop_channel);
		m.sales_default_payment_method = pickKey(src.sales_default_payment_method, methodKeys, DEFAULTS.sales_default_payment_method);
		m.sales_consignment_payment_method = pickKey(src.sales_consignment_payment_method, methodKeys, DEFAULTS.sales_consignment_payment_method);
		m.sales_default_cashbox = Math.max(0, toInt(src.sales_default_cashbox, DEFAULTS.sales_default_cashbox));

		const salesList = str(src.sales_price_list !== undefined ? src.sales_price_list : m.sales_price_list);
		m.sales_price_list = listKeys.indexOf(salesList) >= 0 ? salesList : '';

		m.sales_price_rounding_mode = pickEnum(src.sales_price_rounding_mode, ['tienda', 'erp', 'personalizado', 'ninguno'], DEFAULTS.sales_price_rounding_mode);
		m.sales_price_rounding = Math.max(0, toInt(src.sales_price_rounding, DEFAULTS.sales_price_rounding));
		m.sales_pos_per_page = clampInt(toInt(src.sales_pos_per_page, DEFAULTS.sales_pos_per_page), 6, 96);
		m.sales_pos_images = toBool(src.sales_pos_images, DEFAULTS.sales_pos_images);
		m.sales_pos_steps_width = clampInt(toInt(src.sales_pos_steps_width, DEFAULTS.sales_pos_steps_width), 0, 1600);

		/* Entrega: sin métodos no hay paso que dar, así que la lista vacía vuelve a la de fábrica. */
		m.sales_delivery_enabled = toBool(src.sales_delivery_enabled, DEFAULTS.sales_delivery_enabled);
		m.sales_delivery_methods = normalizeDeliveryMethods(src.sales_delivery_methods, m.sales_delivery_methods);
		const deliveryKeys = m.sales_delivery_methods.map(function (d) { return d.key; });
		m.sales_default_delivery_method = pickKey(src.sales_default_delivery_method, deliveryKeys, DEFAULTS.sales_default_delivery_method);
		m.sales_shop_delivery_method = pickKey(src.sales_shop_delivery_method, deliveryKeys, DEFAULTS.sales_shop_delivery_method);

		/* --- Clientes -------------------------------------------------------- */
		m.customers_enabled = toBool(src.customers_enabled, DEFAULTS.customers_enabled);
		m.customers_doc_types = normalizePairs(src.customers_doc_types, DEFAULTS.customers_doc_types, false);
		const docKeys = m.customers_doc_types.map(function (x) { return x.key; });
		m.customers_default_doc_type = pickKey(src.customers_default_doc_type, docKeys, docKeys.length ? docKeys[0] : '');
		m.customers_link_mode = pickEnum(src.customers_link_mode, ['sugerir', 'automatico', 'nunca'], DEFAULTS.customers_link_mode);
		m.customers_link_match = keyList(src.customers_link_match, ['email', 'phone', 'doc_number'], DEFAULTS.customers_link_match);
		m.customers_link_backfill = toBool(src.customers_link_backfill, DEFAULTS.customers_link_backfill);
		m.customers_quick_create = toBool(src.customers_quick_create, DEFAULTS.customers_quick_create);
		m.customers_require_on_sale = toBool(src.customers_require_on_sale, DEFAULTS.customers_require_on_sale);
		m.customers_fields = keyList(src.customers_fields, ['phone', 'email', 'doc', 'company', 'address', 'notes', 'price_list'], DEFAULTS.customers_fields);

		m.sales_track_cost = toBool(src.sales_track_cost, DEFAULTS.sales_track_cost);
		m.sales_allow_discount = toBool(src.sales_allow_discount, DEFAULTS.sales_allow_discount);
		m.sales_allow_oversell = toBool(src.sales_allow_oversell, DEFAULTS.sales_allow_oversell);

		m.sales_create_order = toBool(src.sales_create_order, DEFAULTS.sales_create_order);
		m.sales_commit_when = pickEnum(src.sales_commit_when, COMMIT_WHEN_VALUES, DEFAULTS.sales_commit_when);
		m.sales_order_status_paid = orderStatus(src.sales_order_status_paid, m.sales_order_status_paid);
		m.sales_order_status_unpaid = orderStatus(src.sales_order_status_unpaid, m.sales_order_status_unpaid);
		m.sales_order_created_via = str(src.sales_order_created_via !== undefined ? src.sales_order_created_via : m.sales_order_created_via).trim() || DEFAULTS.sales_order_created_via;
		m.sales_order_note = str(src.sales_order_note !== undefined ? src.sales_order_note : m.sales_order_note);
		m.sales_reduce_stock = pickEnum(src.sales_reduce_stock, ['auto', 'siempre', 'nunca'], DEFAULTS.sales_reduce_stock);

		m.sales_capture_shop_orders = toBool(src.sales_capture_shop_orders, DEFAULTS.sales_capture_shop_orders);
		m.sales_capture_manual_orders = toBool(src.sales_capture_manual_orders, DEFAULTS.sales_capture_manual_orders);
		m.sales_capture_payment = toBool(src.sales_capture_payment, DEFAULTS.sales_capture_payment);
		m.sales_capture_on = pickEnum(src.sales_capture_on, ['creada', 'estado'], DEFAULTS.sales_capture_on);
		m.sales_capture_statuses = statusList(src.sales_capture_statuses, DEFAULTS.sales_capture_statuses);
		m.sales_void_statuses = statusList(src.sales_void_statuses, DEFAULTS.sales_void_statuses);

		m.sales_consignment_enabled = toBool(src.sales_consignment_enabled, DEFAULTS.sales_consignment_enabled);
		m.sales_consignment_allocation = pickEnum(src.sales_consignment_allocation, ['fifo', 'lifo'], DEFAULTS.sales_consignment_allocation);
		m.sales_consignment_approval = pickEnum(src.sales_consignment_approval, ['auto', 'pendiente'], DEFAULTS.sales_consignment_approval);
		m.sales_consignment_partial_lines = toBool(src.sales_consignment_partial_lines, DEFAULTS.sales_consignment_partial_lines);

		m.shop_price_mode = pickEnum(src.shop_price_mode, ['publico', 'lista'], DEFAULTS.shop_price_mode);
		const shopList = str(src.shop_price_list !== undefined ? src.shop_price_list : m.shop_price_list);
		m.shop_price_list = listKeys.indexOf(shopList) >= 0 ? shopList : (listKeys.length ? listKeys[0] : '');
		m.shop_price_notice = toBool(src.shop_price_notice, DEFAULTS.shop_price_notice);

		m.allowed_roles = roleKeys(src.allowed_roles, DEFAULTS.allowed_roles);
		m.sales_admin_roles = roleKeys(src.sales_admin_roles, DEFAULTS.sales_admin_roles);
		m.sales_log_enabled = toBool(src.sales_log_enabled, DEFAULTS.sales_log_enabled);
		m.sales_edit_enabled = toBool(src.sales_edit_enabled, DEFAULTS.sales_edit_enabled);
		m.sales_edit_require_reason = toBool(src.sales_edit_require_reason, DEFAULTS.sales_edit_require_reason);
		m.sales_edit_redeliver = toBool(src.sales_edit_redeliver, DEFAULTS.sales_edit_redeliver);

		m.delete_data_on_uninstall = toBool(src.delete_data_on_uninstall, DEFAULTS.delete_data_on_uninstall);
		return m;
	}

	/** Roles como lista de claves, sin repetidos y con el administrador siempre adentro. */
	function roleKeys(raw, def) {
		const roles = [];
		(Array.isArray(raw) ? raw : def).forEach(function (r) {
			const key = slugify(r).replace(/-/g, '_');
			if (key && roles.indexOf(key) === -1) roles.push(key);
		});
		if (roles.indexOf('administrator') === -1) roles.unshift('administrator');
		return roles;
	}

	/** Idem, para el payload. */
	function payloadRoles(list) {
		const roles = [];
		(list || []).forEach(function (r) {
			const key = str(r).trim();
			if (key && roles.indexOf(key) === -1) roles.push(key);
		});
		if (roles.indexOf('administrator') === -1) roles.unshift('administrator');
		return roles;
	}

	/** Elige `value` si es una de las claves conocidas; si no, el default. */
	function pickKey(value, keys, def) {
		const wanted = slugify(value).replace(/-/g, '_');
		if (keys.indexOf(wanted) >= 0) return wanted;
		if (keys.indexOf(def) >= 0) return def;
		return keys.length ? keys[0] : '';
	}

	function pickEnum(value, allowed, def) {
		const wanted = str(value).toLowerCase();
		return allowed.indexOf(wanted) >= 0 ? wanted : def;
	}

	/**
	 * Lista de claves filtrada contra las permitidas, sin repetidos.
	 *
	 * Una lista vacía es una respuesta válida (por ejemplo, «no compares por ningún campo»), así que
	 * solo se cae al default cuando lo que llegó no era una lista.
	 */
	function keyList(raw, allowed, def) {
		if (!Array.isArray(raw)) return (def || []).slice();
		const out = [];
		raw.forEach(function (item) {
			const key = slugify(item && item.key !== undefined ? item.key : item).replace(/-/g, '_');
			if (allowed.indexOf(key) >= 0 && out.indexOf(key) === -1) out.push(key);
		});
		return out;
	}

	/** Estado de pedido en su forma corta ('processing', no 'wc-processing'). */
	function orderStatus(value, def) {
		const key = str(value === undefined || value === null ? def : value).trim().replace(/^wc-/, '');
		return key || str(def).replace(/^wc-/, '');
	}

	function statusList(raw, def) {
		const list = Array.isArray(raw) ? raw : def;
		const out = [];
		list.forEach(function (item) {
			const key = orderStatus(item && item.key !== undefined ? item.key : item, '');
			if (key && out.indexOf(key) === -1) out.push(key);
		});
		return out;
	}

	/**
	 * Arbol de permisos del portal. `defaults` lleva el juego completo; cada rol guarda solo lo
	 * que pisa, y una clave ausente significa «heredar» — es la diferencia entre "este rol dice
	 * que no" y "este rol no opina".
	 */
	function normalizePermissions(raw) {
		const src = isPlainObject(raw) ? raw : {};
		const out = { defaults: clone(DEFAULTS.portal_permissions.defaults), roles: {} };
		const keys = Object.keys(out.defaults);

		if (isPlainObject(src.defaults)) {
			keys.forEach(function (key) {
				if (src.defaults[key] === undefined) return;
				out.defaults[key] = permissionValue(key, src.defaults[key]);
			});
		}
		if (isPlainObject(src.roles)) {
			Object.keys(src.roles).forEach(function (role) {
				const set = src.roles[role];
				if (!isPlainObject(set)) return;
				const clean = {};
				keys.forEach(function (key) {
					if (set[key] === undefined) return;
					clean[key] = permissionValue(key, set[key]);
				});
				if (Object.keys(clean).length) out.roles[slugify(role).replace(/-/g, '_')] = clean;
			});
		}
		return out;
	}

	function permissionValue(key, raw) {
		if (key === 'payment_methods') {
			const list = [];
			(Array.isArray(raw) ? raw : []).forEach(function (item) {
				const value = slugify(item && item.key !== undefined ? item.key : item).replace(/-/g, '_');
				if (value && list.indexOf(value) === -1) list.push(value);
			});
			return list;
		}
		if (key === 'price_mode') return pickEnum(raw, ['heredar', 'publico', 'lista'], 'heredar');
		if (key === 'payment_receipt') return pickEnum(raw, ['heredar', 'no', 'opcional', 'obligatorio'], 'heredar');
		if (key === 'price_list_key') return slugify(raw).replace(/-/g, '_');
		return toBool(raw, false);
	}

	/**
	 * Normalizes a list of `{key,label[,markup][,cashbox_id]}` rows.
	 *
	 * @param {*} raw          Raw value from the API.
	 * @param {Array} def      Fallback list.
	 * @param {boolean} markup Whether rows carry a `markup` number.
	 * @param {boolean} [cashbox] Whether rows carry the cashbox the money lands in.
	 * @returns {Array}
	 */
	function normalizePairs(raw, def, markup, cashbox) {
		const list = Array.isArray(raw) ? raw : def;
		const out = [];
		list.forEach(function (row) {
			if (!row) return;
			let key = '';
			let label = '';
			let mk = 0;
			let box = 0;
			if (typeof row === 'string') {
				label = row.trim();
			} else if (typeof row === 'object') {
				key = slugify(row.key);
				label = str(row.label).trim();
				mk = toFloat(row.markup, 0);
				box = toInt(row.cashbox_id, 0);
			}
			if (!key) key = slugify(label);
			if (!key && !label) return;
			const item = { key: key, label: label || key };
			if (markup) item.markup = mk;
			if (cashbox) item.cashbox_id = box > 0 ? box : 0;
			out.push(item);
		});
		return out;
	}

	/** Idem, para el payload: descarta filas vacías y deduplica por clave. */
	function payloadPairs(list, opts) {
		const options = opts || {};
		const out = [];
		const seen = {};
		(Array.isArray(list) ? list : []).forEach(function (row) {
			if (!row) return;
			const label = str(row.label).trim();
			const key = slugify(row.key) || slugify(label);
			if (!key || seen[key]) return;
			seen[key] = true;
			const item = { key: key, label: label || key };
			if (options.markup) item.markup = MJ.fmt.round(toFloat(row.markup, 0), 4);
			if (options.cashbox) item.cashbox_id = Math.max(0, toInt(row.cashbox_id, 0));
			out.push(item);
		});
		if (options.locked && !seen[options.locked]) {
			const extra = { key: options.locked, label: options.lockedLabel || 'Otro' };
			if (options.markup) extra.markup = 0;
			if (options.cashbox) extra.cashbox_id = 0;
			out.push(extra);
		}
		return out;
	}

	/**
	 * Builds the settings object sent to the API (types coerced, empty rows dropped).
	 * Deterministic key order so it can double as a dirty-check fingerprint.
	 *
	 * @param {Object} m Model.
	 * @returns {Object} Full settings payload.
	 */
	function toPayload(m) {
		const currencies = [];
		(m.currencies || []).forEach(function (c) {
			const code = cleanCurrency(c);
			if (code && currencies.indexOf(code) === -1) currencies.push(code);
		});
		const base = cleanCurrency(m.base_currency) || DEFAULTS.base_currency;
		const local = cleanCurrency(m.local_currency) || DEFAULTS.local_currency;
		[base, local].forEach(function (c) { if (currencies.indexOf(c) === -1) currencies.push(c); });

		const symbols = {};
		currencies.forEach(function (code) {
			const sym = str((m.currency_symbols || {})[code]).trim();
			if (sym) symbols[code] = sym;
		});

		const expenseTypes = payloadPairs(m.expense_types, { locked: LOCKED_EXPENSE_TYPE });
		const priceLists = payloadPairs(m.price_lists, { markup: true });
		const seenList = {};
		priceLists.forEach(function (row) { seenList[row.key] = true; });

		const paymentMethods = payloadPairs(m.consignment_payment_methods, { cashbox: true, locked: 'otro' });
		const salesChannels = payloadPairs(m.sales_channels, { locked: 'otro' });
		const salesMethods = payloadPairs(m.sales_payment_methods, { cashbox: true, locked: 'otro' });
		const deliveryMethods = normalizeDeliveryMethods(m.sales_delivery_methods, DEFAULTS.sales_delivery_methods);
		const deliveryKeys = deliveryMethods.map(function (d) { return d.key; });
		const channelKeys = salesChannels.map(function (c) { return c.key; });
		const methodKeys = salesMethods.map(function (c) { return c.key; });
		const inList = function (key, keys, def) {
			const wanted = str(key);
			if (keys.indexOf(wanted) >= 0) return wanted;
			if (keys.indexOf(def) >= 0) return def;
			return keys.length ? keys[0] : '';
		};

		const destinations = [];
		(m.destinations || []).forEach(function (d) {
			const value = str(d).trim();
			if (value && destinations.indexOf(value) === -1) destinations.push(value);
		});

		const roles = payloadRoles(m.allowed_roles);

		const listForPrice = str(m.price_list_for_price);
		const rounding = toInt(m.price_rounding, 0);

		return {
			route_slug: slugify(m.route_slug) || 'mjerp',
			app_title: str(m.app_title).trim() || DEFAULTS.app_title,
			shop_link: !!m.shop_link,
			shop_link_label: str(m.shop_link_label).trim(),
			shop_link_url: str(m.shop_link_url).trim(),
			theme: m.theme === 'light' ? 'light' : 'dark',
			locale: str(m.locale).trim() || DEFAULTS.locale,
			decimals: clampInt(toInt(m.decimals, DEFAULTS.decimals), 0, 6),
			base_currency: base,
			local_currency: local,
			currencies: currencies,
			currency_symbols: symbols,
			default_rate: MJ.fmt.round(toFloat(m.default_rate, DEFAULTS.default_rate), 6),
			sale_rate: MJ.fmt.round(toFloat(m.sale_rate, DEFAULTS.sale_rate), 6),
			sale_rate_source: m.sale_rate_source === 'manual' ? 'manual' : 'mjdolar',
			proration_method: ['fob', 'qty', 'none'].indexOf(m.proration_method) >= 0 ? m.proration_method : 'fob',
			destinations: destinations,
			expense_types: expenseTypes,
			price_lists: priceLists,
			code_prefix: str(m.code_prefix).trim(),
			code_format: str(m.code_format).trim() || DEFAULTS.code_format,
			code_seq_digits: clampInt(toInt(m.code_seq_digits, DEFAULTS.code_seq_digits), 1, 10),
			stock_on_receive: m.stock_on_receive === 'none' ? 'none' : 'increase',
			enable_stock_management: !!m.enable_stock_management,
			update_cost_on_receive: !!m.update_cost_on_receive,
			cost_meta_key: str(m.cost_meta_key).trim() || DEFAULTS.cost_meta_key,
			update_price_on_receive: !!m.update_price_on_receive,
			price_list_for_price: seenList[listForPrice] ? listForPrice : (priceLists.length ? priceLists[0].key : ''),
			price_target: m.price_target === 'sale_price' ? 'sale_price' : 'regular_price',
			price_rounding: ROUNDING_VALUES.indexOf(rounding) >= 0 ? rounding : 0,
			price_publish_mode: ['auto', 'usd', 'local'].indexOf(m.price_publish_mode) >= 0 ? m.price_publish_mode : 'auto',
			usd_rounding: nearestUsdStep(toFloat(m.usd_rounding, DEFAULTS.usd_rounding)),
			usd_rounding_mode: m.usd_rounding_mode === 'up' ? 'up' : 'nearest',
			allow_unreceive: !!m.allow_unreceive,

			/* Ventas — sin estas claves el panel entero se guardaba en el vacío. */
			sales_enabled: !!m.sales_enabled,
			sales_code_prefix: str(m.sales_code_prefix).trim() || DEFAULTS.sales_code_prefix,
			sales_code_format: str(m.sales_code_format).trim() || DEFAULTS.sales_code_format,
			sales_code_seq_digits: clampInt(toInt(m.sales_code_seq_digits, DEFAULTS.sales_code_seq_digits), 1, 10),
			sales_channels: salesChannels,
			sales_default_channel: inList(m.sales_default_channel, channelKeys, DEFAULTS.sales_default_channel),
			sales_shop_channel: inList(m.sales_shop_channel, channelKeys, DEFAULTS.sales_shop_channel),
			sales_payment_methods: salesMethods,
			sales_default_payment_method: inList(m.sales_default_payment_method, methodKeys, DEFAULTS.sales_default_payment_method),
			sales_default_cashbox: Math.max(0, toInt(m.sales_default_cashbox, 0)),
			sales_price_list: seenList[str(m.sales_price_list)] ? str(m.sales_price_list) : '',
			sales_price_rounding_mode: pickEnum(m.sales_price_rounding_mode, ['tienda', 'erp', 'personalizado', 'ninguno'], DEFAULTS.sales_price_rounding_mode),
			sales_price_rounding: Math.max(0, toInt(m.sales_price_rounding, 0)),
			sales_pos_per_page: clampInt(toInt(m.sales_pos_per_page, DEFAULTS.sales_pos_per_page), 6, 96),
			sales_pos_images: !!m.sales_pos_images,
			sales_pos_steps_width: clampInt(toInt(m.sales_pos_steps_width, DEFAULTS.sales_pos_steps_width), 0, 1600),
			sales_delivery_enabled: !!m.sales_delivery_enabled,
			sales_delivery_methods: deliveryMethods,
			sales_default_delivery_method: inList(m.sales_default_delivery_method, deliveryKeys, DEFAULTS.sales_default_delivery_method),
			sales_shop_delivery_method: inList(m.sales_shop_delivery_method, deliveryKeys, DEFAULTS.sales_shop_delivery_method),
			sales_admin_roles: payloadRoles(m.sales_admin_roles),
			sales_log_enabled: !!m.sales_log_enabled,
			sales_edit_enabled: !!m.sales_edit_enabled,
			sales_edit_require_reason: !!m.sales_edit_require_reason,
			sales_edit_redeliver: !!m.sales_edit_redeliver,

			customers_enabled: !!m.customers_enabled,
			customers_doc_types: normalizePairs(m.customers_doc_types, DEFAULTS.customers_doc_types, false),
			customers_default_doc_type: str(m.customers_default_doc_type),
			customers_link_mode: pickEnum(m.customers_link_mode, ['sugerir', 'automatico', 'nunca'], DEFAULTS.customers_link_mode),
			customers_link_match: keyList(m.customers_link_match, ['email', 'phone', 'doc_number'], DEFAULTS.customers_link_match),
			customers_link_backfill: !!m.customers_link_backfill,
			customers_quick_create: !!m.customers_quick_create,
			customers_require_on_sale: !!m.customers_require_on_sale,
			customers_fields: keyList(m.customers_fields, ['phone', 'email', 'doc', 'company', 'address', 'notes', 'price_list'], DEFAULTS.customers_fields),

			sales_track_cost: !!m.sales_track_cost,
			sales_allow_discount: !!m.sales_allow_discount,
			sales_allow_oversell: !!m.sales_allow_oversell,
			sales_create_order: !!m.sales_create_order,
			sales_commit_when: pickEnum(m.sales_commit_when, COMMIT_WHEN_VALUES, DEFAULTS.sales_commit_when),
			sales_order_status_paid: orderStatus(m.sales_order_status_paid, DEFAULTS.sales_order_status_paid),
			sales_order_status_unpaid: orderStatus(m.sales_order_status_unpaid, DEFAULTS.sales_order_status_unpaid),
			sales_order_created_via: str(m.sales_order_created_via).trim() || DEFAULTS.sales_order_created_via,
			sales_order_note: str(m.sales_order_note),
			sales_reduce_stock: pickEnum(m.sales_reduce_stock, ['auto', 'siempre', 'nunca'], DEFAULTS.sales_reduce_stock),
			sales_capture_shop_orders: !!m.sales_capture_shop_orders,
			sales_capture_manual_orders: !!m.sales_capture_manual_orders,
			sales_capture_payment: !!m.sales_capture_payment,
			sales_capture_on: pickEnum(m.sales_capture_on, ['creada', 'estado'], DEFAULTS.sales_capture_on),
			sales_capture_statuses: statusList(m.sales_capture_statuses, []),
			sales_void_statuses: statusList(m.sales_void_statuses, []),
			sales_consignment_enabled: !!m.sales_consignment_enabled,
			sales_consignment_allocation: pickEnum(m.sales_consignment_allocation, ['fifo', 'lifo'], DEFAULTS.sales_consignment_allocation),
			sales_consignment_approval: pickEnum(m.sales_consignment_approval, ['auto', 'pendiente'], DEFAULTS.sales_consignment_approval),
			sales_consignment_partial_lines: !!m.sales_consignment_partial_lines,
			sales_consignment_payment_method: inList(m.sales_consignment_payment_method, methodKeys, DEFAULTS.sales_consignment_payment_method),
			shop_price_mode: pickEnum(m.shop_price_mode, ['publico', 'lista'], DEFAULTS.shop_price_mode),
			shop_price_list: seenList[str(m.shop_price_list)] ? str(m.shop_price_list) : (priceLists.length ? priceLists[0].key : ''),
			shop_price_notice: !!m.shop_price_notice,

			consignee_role: slugify(m.consignee_role).replace(/-/g, '_') || DEFAULTS.consignee_role,
			consignee_role_label: str(m.consignee_role_label).trim() || DEFAULTS.consignee_role_label,
			consignment_code_prefix: str(m.consignment_code_prefix).trim() || DEFAULTS.consignment_code_prefix,
			consignment_code_format: str(m.consignment_code_format).trim() || DEFAULTS.consignment_code_format,
			consignment_code_seq_digits: clampInt(toInt(m.consignment_code_seq_digits, DEFAULTS.consignment_code_seq_digits), 1, 10),
			consignment_price_list: seenList[str(m.consignment_price_list)] ? str(m.consignment_price_list) : (priceLists.length ? priceLists[0].key : ''),
			consignment_stock_on_sale: m.consignment_stock_on_sale === 'none' ? 'none' : 'decrease',
			consignment_approval: ['portal', 'always', 'never'].indexOf(m.consignment_approval) >= 0 ? m.consignment_approval : 'portal',
			consignment_allow_oversell: !!m.consignment_allow_oversell,
			consignment_allocation: m.consignment_allocation === 'manual' ? 'manual' : 'fifo',
			consignment_payment_methods: paymentMethods,
			portal_enabled: !!m.portal_enabled,
			portal_slug: slugify(m.portal_slug) || DEFAULTS.portal_slug,
			portal_title: str(m.portal_title).trim() || DEFAULTS.portal_title,
			portal_allow_sales: !!m.portal_allow_sales,
			portal_allow_returns: !!m.portal_allow_returns,
			portal_undo_minutes: clampInt(toInt(m.portal_undo_minutes, DEFAULTS.portal_undo_minutes), 0, 10080),
			portal_permissions: normalizePermissions(m.portal_permissions),
			allowed_roles: roles,
			new_product_status: m.new_product_status === 'publish' ? 'publish' : 'draft',
			delete_data_on_uninstall: !!m.delete_data_on_uninstall,
		};
	}

	function fingerprint(m) {
		try { return JSON.stringify(toPayload(m)); } catch (e) { return String(Math.random()); }
	}

	/**
	 * Client-side validation with Spanish messages.
	 *
	 * @param {Object} m Model.
	 * @returns {Object} Map settingKey → message (empty when everything is valid).
	 */
	function validate(m) {
		const errors = {};

		if (!str(m.app_title).trim()) errors.app_title = 'El título de la aplicación no puede estar vacío.';
		else if (str(m.app_title).trim().length > 100) errors.app_title = 'El título no puede superar 100 caracteres.';

		const slug = slugify(m.route_slug);
		if (!slug) errors.route_slug = 'El slug de la ruta no puede estar vacío (solo letras, números y guiones).';
		else if (RESERVED_SLUGS.indexOf(slug) >= 0) errors.route_slug = 'Ese slug está reservado por WordPress/WooCommerce; elegí otro.';

		if (['dark', 'light'].indexOf(m.theme) === -1) errors.theme = 'El tema debe ser oscuro o claro.';
		if (!str(m.locale).trim()) errors.locale = 'Elegí un formato regional.';

		const decimals = MJ.fmt.parse(m.decimals);
		if (isNaN(decimals) || decimals < 0 || decimals > 6 || Math.round(decimals) !== decimals) {
			errors.decimals = 'Los decimales deben ser un número entero entre 0 y 6.';
		}

		const currencies = [];
		let badCurrency = false;
		let dupCurrency = '';
		(m.currencies || []).forEach(function (c) {
			const raw = str(c).trim();
			if (!raw) return;
			const code = cleanCurrency(raw);
			if (code.length !== 3) { badCurrency = true; return; }
			if (currencies.indexOf(code) >= 0) dupCurrency = code;
			else currencies.push(code);
		});
		if (!currencies.length) errors.currencies = 'Tenés que permitir al menos una moneda (código de 3 letras, ej. USD).';
		else if (badCurrency) errors.currencies = 'Cada moneda debe ser un código de 3 letras (ej. USD, ARS).';
		else if (dupCurrency) errors.currencies = 'La moneda «' + dupCurrency + '» está repetida.';

		if (cleanCurrency(m.base_currency).length !== 3) errors.base_currency = 'Elegí una moneda base válida.';
		if (cleanCurrency(m.local_currency).length !== 3) errors.local_currency = 'Elegí una moneda local válida.';

		const defaultRate = MJ.fmt.parse(m.default_rate);
		if (isNaN(defaultRate) || defaultRate <= 0) errors.default_rate = 'El tipo de cambio por defecto debe ser mayor a 0.';
		const saleRate = MJ.fmt.parse(m.sale_rate);
		if (isNaN(saleRate) || saleRate <= 0) errors.sale_rate = 'El tipo de cambio de venta debe ser mayor a 0.';

		if (['fob', 'qty', 'none'].indexOf(m.proration_method) === -1) errors.proration_method = 'Método de prorrateo inválido.';

		const format = str(m.code_format).trim();
		if (!format) errors.code_format = 'El formato del código no puede estar vacío.';
		else if (format.indexOf('{seq}') === -1) errors.code_format = 'El formato debe incluir el token {seq}.';
		if (str(m.code_prefix).length > 20) errors.code_prefix = 'El prefijo no puede superar 20 caracteres.';

		const digits = MJ.fmt.parse(m.code_seq_digits);
		if (isNaN(digits) || digits < 1 || digits > 10 || Math.round(digits) !== digits) {
			errors.code_seq_digits = 'Los dígitos del correlativo deben ser un entero entre 1 y 10.';
		}

		const destSeen = {};
		let dupDest = '';
		(m.destinations || []).forEach(function (d) {
			const value = str(d).trim().toLowerCase();
			if (!value) return;
			if (destSeen[value]) dupDest = str(d).trim();
			destSeen[value] = true;
		});
		if (dupDest) errors.destinations = 'El destino «' + dupDest + '» está repetido.';

		const expenseSeen = {};
		let expenseError = '';
		(m.expense_types || []).forEach(function (row) {
			const label = str(row.label).trim();
			const key = slugify(row.key) || slugify(label);
			if (!key && !label) return;
			if (!key) { expenseError = expenseError || 'El tipo «' + label + '» necesita una clave válida (letras o números).'; return; }
			if (expenseSeen[key]) { expenseError = expenseError || 'La clave de tipo de gasto «' + key + '» está repetida.'; return; }
			expenseSeen[key] = true;
			if (!label) expenseError = expenseError || 'Cada tipo de gasto necesita un nombre.';
		});
		if (expenseError) errors.expense_types = expenseError;

		const listSeen = {};
		let listError = '';
		(m.price_lists || []).forEach(function (row) {
			const label = str(row.label).trim();
			const key = slugify(row.key) || slugify(label);
			const markup = MJ.fmt.parse(row.markup);
			if (!key && !label && (row.markup === '' || row.markup === undefined || row.markup === null)) return;
			if (!key) { listError = listError || 'La lista «' + label + '» necesita una clave válida (letras o números).'; return; }
			if (listSeen[key]) { listError = listError || 'La clave de lista «' + key + '» está repetida.'; return; }
			listSeen[key] = true;
			if (!label) { listError = listError || 'Cada lista de precios necesita un nombre.'; return; }
			if (isNaN(markup)) listError = listError || 'El markup de «' + (label || key) + '» debe ser un número (ej. 70).';
			else if (markup <= -100) listError = listError || 'El markup de «' + (label || key) + '» debe ser mayor a -100%.';
		});
		if (listError) errors.price_lists = listError;

		const metaKey = str(m.cost_meta_key).trim();
		if (!metaKey) errors.cost_meta_key = 'La clave de meta del costo no puede estar vacía.';
		else if (!/^[A-Za-z0-9_-]+$/.test(metaKey)) errors.cost_meta_key = 'Solo se permiten letras, números, guiones y guiones bajos.';

		if (['increase', 'none'].indexOf(m.stock_on_receive) === -1) errors.stock_on_receive = 'Valor inválido para el stock al recibir.';

		if (['auto', 'usd', 'local'].indexOf(str(m.price_publish_mode)) === -1) errors.price_publish_mode = 'Valor inválido para la forma de publicar el precio.';
		if (['mjdolar', 'manual'].indexOf(str(m.sale_rate_source)) === -1) errors.sale_rate_source = 'Valor inválido para el origen del TC de venta.';
		if (['nearest', 'up'].indexOf(str(m.usd_rounding_mode)) === -1) errors.usd_rounding_mode = 'Valor inválido para el modo de redondeo en dólares.';
		if (USD_ROUNDING_VALUES.indexOf(nearestUsdStep(m.usd_rounding)) === -1) errors.usd_rounding = 'Valor inválido para el redondeo del precio en dólares.';

		/* --- Consignaciones ------------------------------------------------ */
		const consigneeRole = slugify(m.consignee_role).replace(/-/g, '_');
		if (!consigneeRole) errors.consignee_role = 'El rol de reventa no puede estar vacío.';
		else if (consigneeRole === 'administrator') errors.consignee_role = 'No uses el rol administrator para los revendedores.';
		if (!str(m.consignee_role_label).trim()) errors.consignee_role_label = 'El nombre del rol no puede estar vacío.';

		if (!str(m.consignment_code_prefix).trim()) errors.consignment_code_prefix = 'El prefijo no puede estar vacío.';
		const consignFormat = str(m.consignment_code_format).trim();
		if (!consignFormat) errors.consignment_code_format = 'El formato no puede estar vacío.';
		else if (consignFormat.indexOf('{seq}') === -1) errors.consignment_code_format = 'El formato tiene que incluir {seq}.';

		if (['decrease', 'none'].indexOf(str(m.consignment_stock_on_sale)) === -1) errors.consignment_stock_on_sale = 'Valor inválido.';
		if (['portal', 'always', 'never'].indexOf(str(m.consignment_approval)) === -1) errors.consignment_approval = 'Valor inválido.';
		if (['fifo', 'manual'].indexOf(str(m.consignment_allocation)) === -1) errors.consignment_allocation = 'Valor inválido.';

		const methodSeen = {};
		let methodError = '';
		(m.consignment_payment_methods || []).forEach(function (row) {
			const label = str(row.label).trim();
			const key = slugify(row.key) || slugify(label);
			if (!key && !label) return;
			if (!key) { methodError = methodError || 'El medio «' + label + '» necesita una clave válida.'; return; }
			if (methodSeen[key]) { methodError = methodError || 'La clave de medio de cobro «' + key + '» está repetida.'; return; }
			methodSeen[key] = true;
			if (!label) methodError = methodError || 'Cada medio de cobro necesita un nombre.';
		});
		if (methodError) errors.consignment_payment_methods = methodError;

		const portalSlug = slugify(m.portal_slug);
		if (!portalSlug) {
			errors.portal_slug = 'El slug del panel no puede estar vacío.';
		} else if (portalSlug === slugify(m.route_slug)) {
			errors.portal_slug = 'El panel del revendedor no puede usar la misma dirección que el ERP.';
		} else if (RESERVED_SLUGS.indexOf(portalSlug) >= 0) {
			errors.portal_slug = 'Ese slug está reservado por WordPress o WooCommerce.';
		}
		if (!str(m.portal_title).trim()) errors.portal_title = 'El título del panel no puede estar vacío.';
		if (['regular_price', 'sale_price'].indexOf(m.price_target) === -1) errors.price_target = 'Precio destino inválido.';
		if (['draft', 'publish'].indexOf(m.new_product_status) === -1) errors.new_product_status = 'Estado de producto inválido.';
		if (ROUNDING_VALUES.indexOf(toInt(m.price_rounding, -1)) === -1) errors.price_rounding = 'Redondeo inválido.';

		if (!(m.allowed_roles || []).length) errors.allowed_roles = 'Tenés que habilitar al menos un rol.';

		return errors;
	}

	/* ------------------------------------------------------------------ */
	/* Field builders                                                      */
	/* ------------------------------------------------------------------ */

	function fieldId(key) { return 'mj-set-' + String(key).replace(/[^A-Za-z0-9_-]/g, '-'); }

	function hintHtml(hint) { return hint ? '<div class="mj-field__hint">' + hint + '</div>' : ''; }

	/**
	 * Text input bound to a setting.
	 * @param {Object} o { key, label, value, hint(html), kind, inputmode, maxlength, placeholder, mono, style, after(html) }
	 * @returns {string}
	 */
	function textFieldHtml(o) {
		const id = fieldId(o.key);
		return '<div class="mj-field"' + (o.style ? ' style="' + h(o.style) + '"' : '') + '>' +
			'<label class="mj-field__label" for="' + id + '">' + h(o.label) + '</label>' +
			'<input class="mj-input' + (o.mono ? ' mj-mono' : '') + '" id="' + id + '" type="text"' +
				' name="' + h(o.key) + '" data-setting="' + h(o.key) + '" data-kind="' + h(o.kind || 'text') + '"' +
				(o.inputmode ? ' inputmode="' + h(o.inputmode) + '"' : '') +
				(o.maxlength ? ' maxlength="' + h(o.maxlength) + '"' : '') +
				(o.placeholder ? ' placeholder="' + h(o.placeholder) + '"' : '') +
				' value="' + h(o.value) + '" autocomplete="off" spellcheck="false">' +
			hintHtml(o.hint) +
			(o.after || '') +
		'</div>';
	}

	/**
	 * Select bound to a setting.
	 * @param {Object} o { key, label, value, options, hint(html), style, after(html), kind }
	 * @returns {string}
	 */
	function selectFieldHtml(o) {
		const id = fieldId(o.key);
		return '<div class="mj-field"' + (o.style ? ' style="' + h(o.style) + '"' : '') + '>' +
			'<label class="mj-field__label" for="' + id + '">' + h(o.label) + '</label>' +
			'<select class="mj-select" id="' + id + '" name="' + h(o.key) + '" data-setting="' + h(o.key) + '" data-kind="' + h(o.kind || 'text') + '">' +
				optionsHtml(o.options, o.value) +
			'</select>' +
			hintHtml(o.hint) +
			(o.after || '') +
		'</div>';
	}

	/**
	 * Checkbox bound to a boolean setting.
	 * @param {Object} o { key, label, checked, hint(html) }
	 * @returns {string}
	 */
	function checkboxFieldHtml(o) {
		const id = fieldId(o.key);
		return '<div class="mj-field">' +
			'<label class="mj-checkbox" for="' + id + '">' +
				'<input type="checkbox" id="' + id + '" name="' + h(o.key) + '" data-setting="' + h(o.key) + '" data-kind="bool"' + (o.checked ? ' checked' : '') + '>' +
				'<span>' + h(o.label) + '</span>' +
			'</label>' +
			hintHtml(o.hint) +
		'</div>';
	}

	function sectionHtml(title, subtitle, body) {
		return '<div class="mj-section">' +
			'<h3 class="mj-card__title">' + h(title) + '</h3>' +
			(subtitle ? '<p class="mj-muted mj-small">' + h(subtitle) + '</p>' : '') +
			body +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Generic list editor (add / remove / reorder)                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Renders a `.mj-list-editor` for an array setting. Rows are objects; scalar lists use
	 * a single field named `value`. Binding is delegated at the page level through the
	 * `data-editor` / `data-row` / `data-field` / `data-editor-action` attributes.
	 *
	 * @param {Object} cfg   { name, fields:[{key,label,placeholder,width,maxlength,inputmode,mono,upper,
	 *                                        type:'select', options():[{value,label}], number}],
	 *                         addLabel, emptyText, showHead, locked(row,i), lockedTitle, lockedField(row,field) }
	 * @param {Array}  rows  Rows to render.
	 * @param {Function} [rowNote] (row, index) → html rendered under the row.
	 * @returns {string} html
	 */
	function listEditorHtml(cfg, rows, rowNote) {
		const list = Array.isArray(rows) ? rows : [];
		const fields = cfg.fields || [];
		let html = '<div class="mj-list-editor" data-editor="' + h(cfg.name) + '">';

		if (cfg.showHead && list.length) {
			html += '<div class="mj-list-editor__head mj-row mj-row--wrap mj-small mj-muted">';
			fields.forEach(function (f) {
				html += '<div style="flex:' + h(f.width || '1 1 160px') + '">' + h(f.label) + '</div>';
			});
			html += '<div style="flex:0 0 auto">Acciones</div>';
			html += '</div>';
		}

		if (!list.length) {
			html += '<div class="mj-list-editor__empty mj-muted mj-small">' + h(cfg.emptyText || 'La lista está vacía.') + '</div>';
		}

		list.forEach(function (row, index) {
			const locked = typeof cfg.locked === 'function' ? !!cfg.locked(row, index) : false;
			html += '<div class="mj-list-editor__row mj-row mj-row--wrap" data-row="' + index + '">';
			fields.forEach(function (f) {
				const value = row && row[f.key] !== undefined && row[f.key] !== null ? row[f.key] : '';
				const readonly = locked && typeof cfg.lockedField === 'function' && cfg.lockedField(row, f.key);
				const label = h(f.label + ' (fila ' + (index + 1) + ')');

				if (f.type === 'select') {
					const options = typeof f.options === 'function' ? (f.options() || []) : (f.options || []);
					html += '<div class="mj-field" style="flex:' + h(f.width || '1 1 160px') + '">' +
						'<select class="mj-select mj-select--sm" data-field="' + h(f.key) + '" aria-label="' + label + '">' +
							optionsHtml(options, String(value)) +
						'</select>' +
					'</div>';
					return;
				}

				html += '<div class="mj-field" style="flex:' + h(f.width || '1 1 160px') + '">' +
					'<input class="mj-input mj-input--sm' + (f.mono ? ' mj-mono' : '') + '" type="text"' +
						' data-field="' + h(f.key) + '"' +
						' aria-label="' + label + '"' +
						(f.placeholder ? ' placeholder="' + h(f.placeholder) + '"' : '') +
						(f.maxlength ? ' maxlength="' + h(f.maxlength) + '"' : '') +
						(f.inputmode ? ' inputmode="' + h(f.inputmode) + '"' : '') +
						(readonly ? ' readonly title="Esta clave es fija y no se puede modificar."' : '') +
						' value="' + h(numText(value)) + '" autocomplete="off" spellcheck="false">' +
				'</div>';
			});
			html += '<div class="mj-field" style="flex:0 0 auto">' +
				'<div class="mj-btn-group">' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-editor-action="up" title="Subir" aria-label="Subir fila ' + (index + 1) + '"' + (index === 0 ? ' disabled' : '') + '>' + MJ.icon('chevron-up') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-editor-action="down" title="Bajar" aria-label="Bajar fila ' + (index + 1) + '"' + (index === list.length - 1 ? ' disabled' : '') + '>' + MJ.icon('chevron-down') + '</button>' +
					'<button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon mj-danger-text" data-editor-action="remove"' +
						(locked ? ' disabled title="' + h(cfg.lockedTitle || 'Esta fila no se puede eliminar.') + '"' : ' title="Eliminar" aria-label="Eliminar fila ' + (index + 1) + '"') + '>' + MJ.icon('trash') + '</button>' +
				'</div>' +
			'</div>';
			html += '</div>';
			const note = typeof rowNote === 'function' ? rowNote(row, index) : '';
			if (note) html += '<div class="mj-list-editor__note mj-field__hint" data-row-note="' + index + '">' + note + '</div>';
		});

		html += '<div class="mj-list-editor__footer mj-mt">' +
			'<button type="button" class="mj-btn mj-btn--sm" data-editor-action="add">' + MJ.icon('plus') + '<span>' + h(cfg.addLabel || 'Agregar') + '</span></button>' +
		'</div>';
		html += '<div data-editor-error="' + h(cfg.name) + '"></div>';
		html += '</div>';
		return html;
	}

	/**
	 * Métodos de entrega: [{ key, label, delivered }].
	 *
	 * `delivered` es la marca que dice «con este método la venta ya queda entregada». Es lo que
	 * hace que una venta de mostrador no aparezca después en la lista de lo que falta entregar.
	 */
	function normalizeDeliveryMethods(value, fallback) {
		const rows = [];
		const seen = {};
		(Array.isArray(value) ? value : (Array.isArray(fallback) ? fallback : [])).forEach(function (row) {
			if (!row || typeof row !== 'object') return;
			const label = str(row.label).trim();
			const key = slugify(str(row.key).trim() || label);
			if (!key || !label || seen[key]) return;
			seen[key] = true;
			rows.push({ key: key, label: label, delivered: toBool(row.delivered, false) ? 1 : 0 });
		});
		return rows.length ? rows : DEFAULTS.sales_delivery_methods.slice();
	}

	const EDITORS = {
		currencies: {
			name: 'currencies',
			fields: [{ key: 'value', label: 'Código ISO', placeholder: 'USD', maxlength: 3, mono: true, width: '0 1 140px', upper: true }],
			addLabel: 'Agregar moneda',
			emptyText: 'Agregá al menos una moneda (ej. USD).',
		},
		destinations: {
			name: 'destinations',
			fields: [{ key: 'value', label: 'Destino', placeholder: 'Local, Depósito, Sucursal…', maxlength: 100, width: '1 1 240px' }],
			addLabel: 'Agregar destino',
			emptyText: 'Todavía no cargaste destinos. Las compras van a quedar sin destino.',
		},
		expense_types: {
			name: 'expense_types',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Despachante', maxlength: 80, width: '1 1 200px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 160px' },
			],
			addLabel: 'Agregar tipo de gasto',
			emptyText: 'No hay tipos de gasto configurados.',
			locked: function (row) { return slugify(row && row.key) === LOCKED_EXPENSE_TYPE; },
			lockedField: function (row, field) { return field === 'key'; },
			lockedTitle: 'El tipo «Otro» no se puede eliminar: es el comodín para gastos sin categoría.',
		},
		price_lists: {
			name: 'price_lists',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Público', maxlength: 80, width: '1 1 180px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 150px' },
				{ key: 'markup', label: 'Markup %', placeholder: '70', maxlength: 12, inputmode: 'decimal', width: '0 1 120px' },
			],
			addLabel: 'Agregar lista de precios',
			emptyText: 'No hay listas de precio: no vas a poder sugerir precios de venta.',
		},
		customers_doc_types: {
			name: 'customers_doc_types',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'DNI', maxlength: 80, width: '1 1 200px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 160px' },
			],
			addLabel: 'Agregar tipo de documento',
			emptyText: 'Sin tipos de documento: la ficha del cliente no va a pedir documento.',
		},
		sales_channels: {
			name: 'sales_channels',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Mostrador', maxlength: 80, width: '1 1 200px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 160px' },
			],
			addLabel: 'Agregar canal',
			emptyText: 'No hay canales configurados.',
			locked: function (row) { return slugify(row && row.key) === 'otro'; },
			lockedField: function (row, field) { return field === 'key'; },
			lockedTitle: 'El canal «Otro» no se puede eliminar: es el comodín.',
		},
		sales_payment_methods: {
			name: 'sales_payment_methods',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Efectivo', maxlength: 80, width: '1 1 180px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 150px' },
				{ key: 'cashbox_id', label: 'Caja de ingreso', type: 'select', number: true, options: methodCashboxOptions, width: '1 1 180px' },
			],
			addLabel: 'Agregar medio de pago',
			emptyText: 'No hay medios de pago configurados.',
			locked: function (row) { return slugify(row && row.key) === 'otro'; },
			lockedField: function (row, field) { return field === 'key'; },
			lockedTitle: 'El medio «Otro» no se puede eliminar: es el comodín.',
		},
		sales_delivery_methods: {
			name: 'sales_delivery_methods',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Envío', maxlength: 80, width: '1 1 200px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 150px' },
				{
					key: 'delivered', label: '¿Ya queda entregada?', type: 'select', number: true, width: '1 1 200px',
					options: [
						{ value: '0', label: 'No: queda a entregar' },
						{ value: '1', label: 'Sí: se entrega en el acto' },
					],
				},
			],
			addLabel: 'Agregar forma de entrega',
			emptyText: 'Sin formas de entrega no se puede cargar el paso en la venta.',
		},
		consignment_payment_methods: {
			name: 'consignment_payment_methods',
			showHead: true,
			fields: [
				{ key: 'label', label: 'Nombre', placeholder: 'Transferencia', maxlength: 80, width: '1 1 180px' },
				{ key: 'key', label: 'Clave', placeholder: 'se genera del nombre', maxlength: 40, mono: true, width: '1 1 150px' },
				{ key: 'cashbox_id', label: 'Caja de ingreso', type: 'select', number: true, options: methodCashboxOptions, width: '1 1 180px' },
			],
			addLabel: 'Agregar medio de cobro',
			emptyText: 'No hay medios de cobro configurados.',
			locked: function (row) { return slugify(row && row.key) === 'otro'; },
			lockedField: function (row, field) { return field === 'key'; },
			lockedTitle: 'El medio «Otro» no se puede eliminar: es el comodín cuando no encaja ninguno.',
		},
	};

	/** Wraps scalar list values as `{ value }` rows so the editor can be generic. */
	function scalarRows(list) {
		return (Array.isArray(list) ? list : []).map(function (v) { return { value: str(v) }; });
	}

	/* ------------------------------------------------------------------ */
	/* Derived / preview html                                              */
	/* ------------------------------------------------------------------ */

	function currencyOptions(m) {
		const out = [];
		const seen = {};
		(m.currencies || []).forEach(function (c) {
			const code = cleanCurrency(c);
			if (code && !seen[code]) { seen[code] = true; out.push({ value: code, label: code + ' · ' + symbolOf(m, code) }); }
		});
		[cleanCurrency(m.base_currency), cleanCurrency(m.local_currency)].forEach(function (code) {
			if (code && !seen[code]) { seen[code] = true; out.push({ value: code, label: code + ' · ' + symbolOf(m, code) }); }
		});
		if (!out.length) out.push({ value: '', label: 'Sin monedas configuradas' });
		return out;
	}

	function localeOptions(m) {
		const out = LOCALE_OPTIONS.slice();
		const current = str(m.locale).trim();
		if (current && !out.some(function (o) { return o.value === current; })) out.unshift({ value: current, label: current });
		return out;
	}

	function priceListOptions(m) {
		const out = [];
		(m.price_lists || []).forEach(function (row) {
			const key = slugify(row.key) || slugify(row.label);
			const label = str(row.label).trim() || key;
			if (key) out.push({ value: key, label: label + ' (+' + MJ.fmt.number(toFloat(row.markup, 0), 2) + '%)' });
		});
		if (!out.length) out.push({ value: '', label: 'Sin listas de precio' });
		return out;
	}

	function currencySelectsHtml(m) {
		const options = currencyOptions(m);
		return '<div class="mj-form-row">' +
			selectFieldHtml({
				key: 'base_currency', label: 'Moneda base', value: cleanCurrency(m.base_currency), options: options, kind: 'upper',
				hint: 'Moneda en la que se costean las compras (FOB, gastos prorrateados y costo unitario).',
			}) +
			selectFieldHtml({
				key: 'local_currency', label: 'Moneda local', value: cleanCurrency(m.local_currency), options: options, kind: 'upper',
				hint: 'Moneda de la tienda y de la mayoría de los gastos.',
			}) +
		'</div>';
	}

	function currencySymbolsHtml(m) {
		const list = (m.currencies || []).map(cleanCurrency).filter(Boolean);
		if (!list.length) {
			return '<div class="mj-muted mj-small">Agregá monedas para poder definir sus símbolos.</div>';
		}
		let html = '<div class="mj-row mj-row--wrap">';
		list.forEach(function (code) {
			const id = 'mj-set-sym-' + code;
			html += '<div class="mj-field" style="flex:0 1 140px">' +
				'<label class="mj-field__label" for="' + id + '">' + h(code) + '</label>' +
				'<input class="mj-input mj-input--sm" id="' + id + '" type="text" data-symbol="' + h(code) + '" maxlength="8"' +
					' placeholder="' + h(code) + '" value="' + h(str((m.currency_symbols || {})[code])) + '" autocomplete="off" spellcheck="false"' +
					' aria-label="Símbolo de ' + h(code) + '">' +
			'</div>';
		});
		html += '</div>';
		return html;
	}

	function ratePreviewHtml(m, key) {
		const n = MJ.fmt.parse(m[key]);
		if (isNaN(n)) return '<span class="mj-danger-text">Ingresá un número válido.</span>';
		if (n <= 0) return '<span class="mj-danger-text">Debe ser mayor a 0.</span>';
		const base = cleanCurrency(m.base_currency) || 'USD';
		const local = cleanCurrency(m.local_currency) || 'ARS';
		return 'Se guarda como <span class="mj-mono">' + h(MJ.fmt.number(n, 4)) + '</span> · ' +
			h(moneyOf(m, n, local)) + ' por cada ' + h(moneyOf(m, 1, base));
	}

	function slugPreviewHtml(m) {
		const slug = slugify(m.route_slug) || 'mjerp';
		const home = str(MJ.config && MJ.config.homeUrl).replace(/\/+$/, '');
		const permalinks = !!(MJ.config && MJ.config.permalinksEnabled);
		if (!permalinks) {
			return '<span class="mj-warning-text">Los enlaces permanentes están desactivados: la app se abre en ' +
				'<span class="mj-mono">' + h((home || '') + '/?mjerp_app=1') + '</span> y el slug no se usa.</span>';
		}
		return 'La app va a quedar en <span class="mj-mono">' + h((home || '') + '/' + slug + '/') + '</span>';
	}

	function codePreviewHtml(m) {
		const format = str(m.code_format).trim() || DEFAULTS.code_format;
		const digits = clampInt(toInt(m.code_seq_digits, DEFAULTS.code_seq_digits), 1, 10);
		const now = new Date();
		const code = format
			.replace(/\{prefix\}/g, str(m.code_prefix).trim())
			.replace(/\{year\}/g, String(now.getFullYear()))
			.replace(/\{month\}/g, pad2(now.getMonth() + 1))
			.replace(/\{seq\}/g, padLeft(1, digits));
		if (format.indexOf('{seq}') === -1) {
			return '<span class="mj-danger-text">El formato debe incluir el token <span class="mj-mono">{seq}</span>.</span>';
		}
		return 'Próximo código de ejemplo: <span class="mj-mono mj-strong">' + h(code) + '</span>';
	}

	function priceListNoteHtml(m, row) {
		const markup = MJ.fmt.parse(row && row.markup);
		const base = cleanCurrency(m.base_currency) || 'USD';
		const local = cleanCurrency(m.local_currency) || base;
		const saleRate = toFloat(m.sale_rate, 0);
		const rounding = toInt(m.price_rounding, 0);
		if (isNaN(markup)) return '<span class="mj-danger-text">El markup debe ser un número (ej. 70).</span>';
		let price = { base: toFloat(EXAMPLE_COST, 0) * (1 + markup / 100), local: 0, usd: 0, usd_local: 0 };
		if (MJ.calc && typeof MJ.calc.priceFor === 'function') {
			// The same USD context the server uses, so the example matches the published price.
			price = MJ.calc.priceFor(EXAMPLE_COST, markup, saleRate, rounding, usdCfgOf(m));
		}
		// Publishing in dollars, the rounded USD price is what the customer effectively pays for.
		const usdMode = willPublishUsd(m);
		const shownBase = usdMode ? price.usd : price.base;
		const shownLocal = usdMode ? price.usd_local : price.local;
		let out = 'Ejemplo: un costo de ' + h(moneyOf(m, EXAMPLE_COST, base)) + ' se vende a <span class="mj-strong">' + h(moneyOf(m, shownBase, base)) + '</span>';
		if (local !== base && (usdMode || saleRate > 0)) out += ' · ' + h(moneyOf(m, shownLocal, local));
		return out;
	}

	/** Footnote under the price-list editor: says which rate and rounding the examples use. */
	function priceListsHintHtml(m) {
		const cost = h(moneyOf(m, EXAMPLE_COST, cleanCurrency(m.base_currency) || 'USD'));
		if (willPublishUsd(m)) {
			return 'Si dejás la clave vacía se genera a partir del nombre. Los ejemplos usan un costo final de ' + cost +
				', el redondeo en dólares configurado y el TC de MJ Dólar.';
		}
		return 'Si dejás la clave vacía se genera a partir del nombre. Los ejemplos usan un costo final de ' + cost +
			', el TC de venta y el redondeo configurados.';
	}

	function priceListSelectHtml(m) {
		return selectFieldHtml({
			key: 'price_list_for_price',
			label: 'Lista usada para el precio de venta',
			value: str(m.price_list_for_price),
			options: priceListOptions(m),
			hint: 'Es la lista que se propone al recibir una compra cuando se publican precios.',
		});
	}

	function roleList(m, settingKey) {
		const out = [];
		const seen = {};
		let source = [];
		if (MJ.config && Array.isArray(MJ.config.roles)) source = MJ.config.roles;
		else if (MJ.state.bootstrap && Array.isArray(MJ.state.bootstrap.roles)) source = MJ.state.bootstrap.roles;
		source.forEach(function (r) {
			if (!r) return;
			const key = typeof r === 'string' ? str(r) : str(r.key || r.slug || r.value || r.name);
			if (!key || seen[key]) return;
			seen[key] = true;
			out.push({ key: key, label: (typeof r === 'string' ? key : str(r.label || r.name)) || key });
		});
		(m[settingKey || 'allowed_roles'] || []).forEach(function (key) {
			if (!key || seen[key]) return;
			seen[key] = true;
			out.push({ key: key, label: str(key) });
		});
		if (!seen.administrator) out.unshift({ key: 'administrator', label: 'Administrador' });
		out.sort(function (a, b) {
			if (a.key === 'administrator') return -1;
			if (b.key === 'administrator') return 1;
			return a.label.localeCompare(b.label, 'es');
		});
		return out;
	}

	/**
	 * Checklist de roles para un ajuste que guarda una lista de roles.
	 *
	 * @param {Object} m          Model.
	 * @param {string} settingKey Ajuste (allowed_roles por defecto).
	 * @param {string} adminHint  Aclaración junto al administrador (HTML fijo).
	 */
	function rolesHtml(m, settingKey, adminHint) {
		const setting = settingKey || 'allowed_roles';
		const roles = roleList(m, setting);
		if (!roles.length) {
			return MJ.ui.empty({
				icon: 'users',
				title: 'No se pudieron leer los roles',
				text: 'Recargá la aplicación; mientras tanto solo los administradores tienen acceso.',
			});
		}
		const allowed = m[setting] || [];
		let html = '<div class="mj-stack">';
		roles.forEach(function (role) {
			const isAdmin = role.key === 'administrator';
			const checked = isAdmin || allowed.indexOf(role.key) >= 0;
			const id = 'mj-set-' + setting + '-' + role.key.replace(/[^A-Za-z0-9_-]/g, '-');
			html += '<div class="mj-field">' +
				'<label class="mj-checkbox" for="' + id + '">' +
					'<input type="checkbox" id="' + id + '" data-role="' + h(role.key) + '" data-role-setting="' + h(setting) + '"' + (checked ? ' checked' : '') + (isAdmin ? ' disabled' : '') + '>' +
					'<span>' + h(role.label) + ' <span class="mj-muted mj-mono mj-small">' + h(role.key) + '</span></span>' +
				'</label>' +
				(isAdmin ? '<div class="mj-field__hint">' + (adminHint || 'Los administradores siempre tienen acceso (capacidad <span class="mj-mono">manage_options</span>).') + '</div>' : '') +
			'</div>';
		});
		html += '</div>';
		html += '<div data-editor-error="' + h(setting) + '"></div>';
		return html;
	}

	function infoRow(label, valueHtml) {
		return '<div class="mj-stat-list__row"><span class="mj-muted">' + h(label) + '</span><span>' + valueHtml + '</span></div>';
	}

	function infoBlockHtml(appUrl) {
		const cfg = MJ.config || {};
		const url = str(appUrl || cfg.appUrl);
		const restUrl = str(cfg.restUrl);
		const version = str(cfg.version) || '—';
		const copyBtn = function (value, label) {
			if (!value) return '';
			return ' <button type="button" class="mj-btn mj-btn--sm mj-btn--ghost mj-btn--icon" data-action="copy" data-copy="' + h(value) + '" title="Copiar" aria-label="Copiar ' + h(label) + '">' + MJ.icon('copy') + '</button>';
		};
		return '<div class="mj-stat-list">' +
			infoRow('URL de la aplicación', (url
				? '<a class="mj-link mj-mono" href="' + h(url) + '" rel="noopener">' + h(url) + '</a>'
				: '<span class="mj-muted">—</span>') + copyBtn(url, 'la URL de la aplicación')) +
			infoRow('Base de la REST API', (restUrl
				? '<span class="mj-mono">' + h(restUrl) + '</span>'
				: '<span class="mj-muted">—</span>') + copyBtn(restUrl, 'la base de la REST API')) +
			infoRow('Versión del plugin', '<span class="mj-mono">' + h(version) + '</span>') +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Panels                                                              */
	/* ------------------------------------------------------------------ */

	function panelGeneralHtml(m) {
		return sectionHtml('Identidad y apariencia', 'Cómo se ve y cómo se llama la aplicación.',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'app_title', label: 'Título de la aplicación', value: str(m.app_title), maxlength: 100,
					hint: 'Se muestra en la barra lateral y en el título de la pestaña del navegador.',
				}) +
				selectFieldHtml({
					key: 'theme', label: 'Tema', value: m.theme, options: THEME_OPTIONS,
					hint: 'El cambio se aplica al guardar.',
				}) +
			'</div>' +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'locale', label: 'Formato regional', value: str(m.locale), options: localeOptions(m),
					hint: 'Define cómo se muestran números, importes y fechas.',
				}) +
				textFieldHtml({
					key: 'decimals', label: 'Decimales', value: numText(m.decimals), kind: 'number', inputmode: 'numeric', maxlength: 2,
					hint: 'Cantidad de decimales para mostrar importes (entre 0 y 6).',
				}) +
			'</div>'
		) +
		sectionHtml('Acceso a la tienda', 'El camino de vuelta: un botón arriba a la derecha para pasar del ERP a la tienda sin escribir la dirección.',
			checkboxFieldHtml({
				key: 'shop_link', label: 'Mostrar el acceso a la tienda', checked: !!m.shop_link,
				hint: 'Aparece en la barra superior de todas las pantallas. El enlace del menú lateral no se toca.',
			}) +
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'shop_link_label', label: 'Texto del botón', value: str(m.shop_link_label), maxlength: 60, placeholder: 'Ir a la tienda',
					hint: 'Vacío = «Ir a la tienda».',
				}) +
				textFieldHtml({
					key: 'shop_link_url', label: 'A dónde lleva', value: str(m.shop_link_url), mono: true, maxlength: 190, placeholder: MJ.config.homeUrl || '',
					hint: 'Vacío = la portada de la tienda. Poné una dirección propia si preferís entrar por otra página.',
				}) +
			'</div>'
		) +
		sectionHtml('Dirección de la aplicación', '',
			textFieldHtml({
				key: 'route_slug', label: 'Slug de la ruta', value: str(m.route_slug), mono: true, maxlength: 60, placeholder: 'mjerp',
				hint: 'Solo letras, números y guiones. Se normaliza automáticamente al guardar.',
				after: '<div class="mj-field__hint" data-region="slug-preview">' + slugPreviewHtml(m) + '</div>',
			}) +
			MJ.ui.alert('Si cambiás el slug también cambia la URL de la aplicación: al guardar vas a tener que volver a abrirla en la nueva dirección y actualizar los favoritos o accesos directos que tengas guardados. La dirección anterior deja de funcionar.', 'warning')
		) +
		sectionHtml('Monedas', 'Las monedas habilitadas se ofrecen en los ítems y gastos de cada compra.',
			'<div data-region="currency-selects">' + currencySelectsHtml(m) + '</div>' +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Monedas permitidas</span>' +
				'<div data-region="editor-currencies">' + listEditorHtml(EDITORS.currencies, scalarRows(m.currencies)) + '</div>' +
				'<div class="mj-field__hint">Códigos ISO de 3 letras. La moneda base y la local se agregan solas si faltan.</div>' +
			'</div>' +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Símbolos</span>' +
				'<div data-region="currency-symbols">' + currencySymbolsHtml(m) + '</div>' +
				'<div class="mj-field__hint">Se usan al mostrar importes (ej. <span class="mj-mono">US$</span> para USD). Si lo dejás vacío se muestra el código.</div>' +
			'</div>'
		) +
		sectionHtml('Tipos de cambio y prorrateo', '',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'default_rate', label: 'TC por defecto', value: numText(m.default_rate), kind: 'number', inputmode: 'decimal',
					hint: 'Tipo de cambio inicial de las compras nuevas.',
					after: '<div class="mj-field__hint" data-region="rate-preview-default_rate">' + ratePreviewHtml(m, 'default_rate') + '</div>',
				}) +
				textFieldHtml({
					key: 'sale_rate', label: 'TC de venta', value: numText(m.sale_rate), kind: 'number', inputmode: 'decimal',
					hint: 'Se usa para pasar costos y precios sugeridos a moneda local.',
					after: '<div class="mj-field__hint" data-region="rate-preview-sale_rate">' + ratePreviewHtml(m, 'sale_rate') + '</div>',
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'sale_rate_source', label: 'Origen del TC de venta', value: m.sale_rate_source, options: SALE_RATE_SOURCE_OPTIONS,
				hint: 'Con el TC de MJ Dólar, el precio sugerido en el ERP y el precio que ve el cliente son el mismo número. ' +
					'Con un TC propio pueden separarse.',
			}) +
			'<div data-region="sale-rate-source">' + saleRateSourceNoteHtml(m) + '</div>' +
			selectFieldHtml({
				key: 'proration_method', label: 'Prorrateo de gastos por defecto', value: m.proration_method, options: PRORATION_OPTIONS,
				hint: 'Cómo se reparten los gastos prorrateables entre los productos de la compra.',
			})
		);
	}

	function panelComprasHtml(m) {
		return sectionHtml('Códigos de compra', 'Se generan al crear una compra; siempre podés escribirlos a mano.',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'code_prefix', label: 'Prefijo', value: str(m.code_prefix), mono: true, maxlength: 20, placeholder: 'IMP',
					hint: 'Reemplaza al token <span class="mj-mono">{prefix}</span>.',
				}) +
				textFieldHtml({
					key: 'code_format', label: 'Formato', value: str(m.code_format), mono: true, maxlength: 60, placeholder: '{prefix}-{year}-{seq}',
					hint: 'Tokens: <span class="mj-mono">{prefix}</span> prefijo · <span class="mj-mono">{year}</span> año (4 dígitos) · ' +
						'<span class="mj-mono">{month}</span> mes (2 dígitos) · <span class="mj-mono">{seq}</span> correlativo (obligatorio).',
				}) +
				textFieldHtml({
					key: 'code_seq_digits', label: 'Dígitos del correlativo', value: numText(m.code_seq_digits), kind: 'number', inputmode: 'numeric', maxlength: 2,
					hint: 'Entre 1 y 10 (4 → 0001).',
				}) +
			'</div>' +
			'<div class="mj-field__hint" data-region="code-preview">' + codePreviewHtml(m) + '</div>'
		) +
		sectionHtml('Destinos', 'Se ofrecen al crear una compra; igual se puede escribir un destino libre.',
			'<div data-region="editor-destinations">' + listEditorHtml(EDITORS.destinations, scalarRows(m.destinations)) + '</div>'
		) +
		sectionHtml('Tipos de gasto', 'Se usan al cargar los gastos de una importación.',
			'<div data-region="editor-expense_types">' + listEditorHtml(EDITORS.expense_types, m.expense_types) + '</div>' +
			'<div class="mj-field__hint">Si dejás la clave vacía se genera a partir del nombre. El tipo <span class="mj-mono">otro</span> no se puede eliminar porque es el comodín para gastos sin categoría.</div>'
		);
	}

	function panelPreciosHtml(m) {
		const noLists = !(m.price_lists || []).length;
		return sectionHtml('Listas de precio', 'El markup se aplica sobre el costo final (landed) de cada producto.',
			(noLists ? MJ.ui.alert('Sin listas de precio no vas a poder comparar precios sugeridos ni actualizar precios de WooCommerce al recibir una compra.', 'warning') : '') +
			'<div data-region="editor-price_lists">' +
				listEditorHtml(EDITORS.price_lists, m.price_lists, function (row) { return priceListNoteHtml(m, row); }) +
			'</div>' +
			'<div class="mj-field__hint" data-region="price-lists-hint">' + priceListsHintHtml(m) + '</div>'
		) +
		sectionHtml('Lista por defecto', '',
			'<div data-region="price-list-select">' + priceListSelectHtml(m) + '</div>'
		);
	}

	function panelStockHtml(m) {
		return sectionHtml('Stock al recibir', 'Qué pasa en WooCommerce cuando marcás una compra como recibida.',
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'stock_on_receive', label: 'Stock', value: m.stock_on_receive, options: STOCK_ON_RECEIVE_OPTIONS,
					hint: 'Valor por defecto del check «Sumar stock» en el diálogo de recepción.',
				}) +
				selectFieldHtml({
					key: 'new_product_status', label: 'Estado de productos nuevos', value: m.new_product_status, options: PRODUCT_STATUS_OPTIONS,
					hint: 'Con qué estado se crean los productos desde el ERP.',
				}) +
			'</div>' +
			checkboxFieldHtml({
				key: 'enable_stock_management', label: 'Activar la gestión de stock si el producto no la tiene', checked: !!m.enable_stock_management,
				hint: 'Si el producto no controla stock, se activa antes de sumar las unidades recibidas.',
			}) +
			checkboxFieldHtml({
				key: 'allow_unreceive', label: 'Permitir deshacer una recepción', checked: !!m.allow_unreceive,
				hint: 'Al deshacer se descuenta el stock sumado. Los costos y precios actualizados no se revierten.',
			})
		) +
		sectionHtml('Costos', '',
			checkboxFieldHtml({
				key: 'update_cost_on_receive', label: 'Actualizar el costo de los productos al recibir', checked: !!m.update_cost_on_receive,
				hint: 'Guarda el costo final (landed) en el producto de WooCommerce.',
			}) +
			textFieldHtml({
				key: 'cost_meta_key', label: 'Clave de meta del costo', value: str(m.cost_meta_key), mono: true, maxlength: 60, placeholder: '_mjerp_cost',
				hint: 'Solo letras, números, guiones y guiones bajos. Cambiarla desconecta los costos ya guardados con la clave anterior.',
			})
		) +
		sectionHtml('Precio de venta al recibir', 'Dónde se publica el precio sugerido cuando marcás una compra como recibida.',
			'<div data-region="dolar-status">' + dolarStatusHtml(m) + '</div>' +
			checkboxFieldHtml({
				key: 'update_price_on_receive', label: 'Publicar el precio de venta al recibir', checked: !!m.update_price_on_receive,
				hint: 'Valor por defecto del check en el diálogo de recepción; siempre se puede cambiar ahí.',
			}) +
			selectFieldHtml({
				key: 'price_publish_mode', label: 'Cómo se publica', value: m.price_publish_mode, options: PRICE_PUBLISH_OPTIONS,
				hint: 'En dólares el precio lo guarda MJ Dólar y la tienda lo pasa a pesos con su TC, así se actualiza solo. ' +
					'En moneda de la tienda el precio queda fijo hasta la próxima recepción.',
			}) +
			'<div data-region="price-list-select">' + priceListSelectHtml(m) + '</div>' +
			selectFieldHtml({
				key: 'price_target', label: 'Precio destino', value: m.price_target, options: PRICE_TARGET_OPTIONS,
				hint: 'Campo que se escribe con el precio sugerido: el precio normal o el de oferta.',
			}) +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'usd_rounding', label: 'Redondeo del precio en dólares', value: String(nearestUsdStep(m.usd_rounding)), options: USD_ROUNDING_OPTIONS,
					hint: 'Se aplica al precio que se publica en MJ Dólar (US$ 128,74 → US$ 129).',
				}) +
				selectFieldHtml({
					key: 'usd_rounding_mode', label: 'Modo de redondeo en dólares', value: m.usd_rounding_mode, options: USD_ROUNDING_MODE_OPTIONS,
					hint: '«Siempre para arriba» nunca deja el precio por debajo del margen buscado.',
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'price_rounding', label: 'Redondeo del precio en moneda local', value: String(toInt(m.price_rounding, 0)), options: ROUNDING_OPTIONS, kind: 'intval',
				hint: 'Solo se usa cuando el precio se publica en la moneda de la tienda. Publicando en dólares, el redondeo en pesos lo hace MJ Dólar.',
			})
		);
	}

	/** Explains that the TC field above is being overridden by MJ Dólar (or why it is not). */
	function saleRateSourceNoteHtml(m) {
		const d = dolarOf(m);
		if (str(m.sale_rate_source) !== 'mjdolar') {
			return '';
		}
		if (!d.active) {
			return MJ.ui.alert('MJ Dólar no está activo, así que se usa el TC de venta cargado acá.', 'info');
		}
		if (!toFloat(d.rate, 0)) {
			return MJ.ui.alert('MJ Dólar no tiene TC cargado, así que por ahora se usa el TC de venta cargado acá.', 'warning');
		}
		return MJ.ui.alert('El TC de venta lo manda MJ Dólar: ' + MJ.fmt.rate(toFloat(d.rate, 0)) +
			'. Al guardar, el campo de arriba queda con ese mismo valor, que se usa de respaldo si algún día desactivás el plugin.', 'info');
	}

	/**
	 * Where the suggested price will land, given the current MJ Dólar state. Shown at the top of the
	 * price section because it is the one thing that decides whether the two plugins agree.
	 */
	function dolarStatusHtml(m) {
		const d = dolarOf(m);
		const link = d.settings_url ? ' <a href="' + h(str(d.settings_url)) + '" target="_blank" rel="noopener">Abrir MJ Dólar</a>' : '';

		if (!d.active) {
			return MJ.ui.alert('MJ Dólar no está activo. Los precios se publican en la moneda de la tienda y quedan fijos hasta la próxima recepción.', 'info', { html: true });
		}
		if (!toFloat(d.rate, 0)) {
			return MJ.ui.alert('MJ Dólar está activo pero no tiene tipo de cambio cargado, así que todavía no se puede publicar en dólares.' + link, 'warning', { html: true });
		}
		if (!willPublishUsd(m)) {
			return MJ.ui.alert('MJ Dólar está listo (TC ' + h(MJ.fmt.rate(toFloat(d.rate, 0))) + '), pero elegiste publicar en la moneda de la tienda: los precios van a quedar fijos y MJ Dólar los va a pisar en los productos que tengan precio en dólares.' + link, 'warning', { html: true });
		}
		return MJ.ui.alert('Los precios se publican en dólares con MJ Dólar (TC ' + h(MJ.fmt.rate(toFloat(d.rate, 0))) +
			'). La tienda los muestra en pesos y se actualizan solos cuando cambiás el TC.' + link, 'success', { html: true });
	}

	/* --- Ventas ------------------------------------------------------------------ */

	const SALES_CAPTURE_OPTIONS = [
		{ value: 'creada', label: 'Apenas entra el pedido' },
		{ value: 'estado', label: 'Cuando el pedido llega a uno de los estados de abajo' },
	];

	const SALES_STOCK_OPTIONS = [
		{ value: 'auto', label: 'Que lo maneje WooCommerce (recomendado)' },
		{ value: 'siempre', label: 'Descontar siempre al emitir el pedido' },
		{ value: 'nunca', label: 'No tocar el stock' },
	];

	const SALES_CONSIGN_APPROVAL_OPTIONS = [
		{ value: 'auto', label: 'Se imputan solas' },
		{ value: 'pendiente', label: 'Esperan que las confirmes' },
	];

	const SALES_ALLOCATION_OPTIONS = [
		{ value: 'fifo', label: 'Primero lo más viejo que tenga (FIFO)' },
		{ value: 'lifo', label: 'Primero lo último que le entregaste (LIFO)' },
	];

	const SHOP_PRICE_OPTIONS = [
		{ value: 'publico', label: 'El precio público, como cualquier cliente' },
		{ value: 'lista', label: 'El precio de su lista' },
	];

	/** Estados de pedido de WooCommerce, del bootstrap. */
	function orderStatusOptions() {
		const info = (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {};
		return (info.order_statuses || []).map(function (o) {
			return { value: o.key, label: o.label };
		});
	}

	function cashboxOptions() {
		const boxes = (MJ.state.cashboxes || []).map(function (c) {
			return { value: String(c.id), label: c.name + (c.currency ? ' (' + c.currency + ')' : '') };
		});
		boxes.unshift({ value: '0', label: 'Sin caja' });
		return boxes;
	}

	/**
	 * Cajas para la columna de cada medio de pago. El 0 no es «sin caja»: es «la que diga el
	 * ajuste general», así un medio sin decidir sigue el default en vez de perder la plata.
	 */
	function methodCashboxOptions() {
		const boxes = (MJ.state.cashboxes || []).map(function (c) {
			return { value: String(c.id), label: c.name + (c.currency ? ' · ' + c.currency : '') };
		});
		boxes.unshift({ value: '0', label: boxes.length ? 'La caja por defecto' : 'Creá cajas en Proveedores → Cajas' });
		return boxes;
	}

	function deliveryOptionsFrom(m) {
		return (m.sales_delivery_methods || []).map(function (d) {
			return { value: d.key, label: d.label + (MJ.fmt.toNumber(d.delivered) ? ' · entrega en el acto' : '') };
		});
	}

	function channelOptionsFrom(m) {
		return (Array.isArray(m.sales_channels) ? m.sales_channels : []).map(function (c) {
			return { value: slugify(c.key) || slugify(c.label), label: c.label || c.key };
		});
	}

	function salesMethodOptionsFrom(m) {
		return (Array.isArray(m.sales_payment_methods) ? m.sales_payment_methods : []).map(function (c) {
			return { value: slugify(c.key) || slugify(c.label), label: c.label || c.key };
		});
	}

	/** Grupo de casillas para una lista de estados de pedido. */
	function statusChecklistHtml(key, label, selected, hint) {
		const options = orderStatusOptions();
		const current = Array.isArray(selected) ? selected.map(String) : [];

		if (!options.length) {
			return '<div class="mj-field"><span class="mj-field__label">' + h(label) + '</span>' +
				hintHtml('WooCommerce no está disponible para leer los estados de pedido.') + '</div>';
		}

		return '<div class="mj-field">' +
			'<span class="mj-field__label">' + h(label) + '</span>' +
			'<div class="mj-row mj-row--wrap mj-checkbox-grid" data-status-group="' + h(key) + '">' +
				options.map(function (o) {
					const on = current.indexOf(String(o.value)) >= 0;
					return '<label class="mj-checkbox"><input type="checkbox" data-status-key="' + h(key) + '" value="' + h(o.value) + '"' + (on ? ' checked' : '') + '> <span>' + h(o.label) + '</span></label>';
				}).join('') +
			'</div>' +
			hintHtml(hint) +
		'</div>';
	}

	/**
	 * Grupo de casillas cuyo valor es una lista de claves.
	 *
	 * Reusa el mismo marcado que los estados de pedido (`data-status-key` + `data-status-group`),
	 * así el handler que ya recolecta esos grupos sirve tal cual.
	 */
	function keyChecklistHtml(key, label, options, selected, hint) {
		const current = Array.isArray(selected) ? selected.map(String) : [];
		return '<div class="mj-field">' +
			'<span class="mj-field__label">' + h(label) + '</span>' +
			'<div class="mj-row mj-row--wrap mj-checkbox-grid" data-status-group="' + h(key) + '">' +
				options.map(function (o) {
					const on = current.indexOf(String(o.value)) >= 0;
					return '<label class="mj-checkbox"><input type="checkbox" data-status-key="' + h(key) + '" value="' + h(o.value) + '"' + (on ? ' checked' : '') + '> <span>' + h(o.label) + '</span></label>';
				}).join('') +
			'</div>' +
			hintHtml(hint) +
		'</div>';
	}

	function docTypeOptionsFrom(m) {
		return (Array.isArray(m.customers_doc_types) ? m.customers_doc_types : []).map(function (t) {
			return { value: slugify(t.key) || slugify(t.label), label: t.label || t.key };
		});
	}

	function panelClientesHtml(m) {
		return sectionHtml('La libreta', 'Quiénes te compran. Un cliente de acá es tu registro de ventas: no es una cuenta de la tienda, no se le crea usuario y no recibe ningún mail.',
			checkboxFieldHtml({
				key: 'customers_enabled', label: 'Llevar una libreta de clientes', checked: !!m.customers_enabled,
				hint: 'Apagado, las ventas siguen guardando el nombre que escribas, pero sin ficha ni historial por persona.',
			}) +
			checkboxFieldHtml({
				key: 'customers_quick_create', label: 'Crear clientes desde la pantalla de venta', checked: !!m.customers_quick_create,
				hint: 'Agrega un «Crear cliente…» al final del buscador, para no cortar la venta cuando llega alguien nuevo.',
			}) +
			checkboxFieldHtml({
				key: 'customers_require_on_sale', label: 'Pedir siempre un cliente', checked: !!m.customers_require_on_sale,
				hint: 'Activado, no se puede registrar una venta sin elegir a quién. Dejalo apagado si vendés mucho al mostrador sin preguntar el nombre.',
			})
		) +
		sectionHtml('La ficha', 'Qué datos pide el formulario, además del nombre.',
			keyChecklistHtml('customers_fields', 'Campos que se muestran', [
				{ value: 'phone', label: 'Teléfono' },
				{ value: 'email', label: 'Email' },
				{ value: 'doc', label: 'Documento' },
				{ value: 'company', label: 'Empresa' },
				{ value: 'address', label: 'Dirección' },
				{ value: 'notes', label: 'Notas' },
				{ value: 'price_list', label: 'Lista de precios propia' },
			], m.customers_fields, 'El nombre siempre se pide: es lo único obligatorio.') +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Tipos de documento</span>' +
				'<div data-region="editor-customers_doc_types">' + listEditorHtml(EDITORS.customers_doc_types, m.customers_doc_types) + '</div>' +
				'<div class="mj-field__hint">Si dejás la clave vacía se genera del nombre.</div>' +
			'</div>' +
			selectFieldHtml({
				key: 'customers_default_doc_type', label: 'Tipo por defecto', value: str(m.customers_default_doc_type), options: docTypeOptionsFrom(m),
				hint: 'Con el que arranca una ficha nueva.',
			})
		) +
		sectionHtml('Cuando se crean una cuenta en la tienda',
			'El caso que esto resuelve: alguien que venís atendiendo en el mostrador un día se registra en la tienda. Son la misma persona, pero para el sistema son dos cosas distintas hasta que las unís.',
			selectFieldHtml({
				key: 'customers_link_mode', label: 'Qué hace el ERP', value: str(m.customers_link_mode),
				options: [
					{ value: 'sugerir', label: 'Avisarme y que yo decida' },
					{ value: 'automatico', label: 'Vincularlos solo' },
					{ value: 'nunca', label: 'No buscar coincidencias' },
				],
				hint: 'Avisar es lo más seguro: dos personas de la misma casa comparten teléfono, y unir dos historiales por error es de lo poco difícil de deshacer acá. La sugerencia aparece arriba de la lista de clientes.',
			}) +
			keyChecklistHtml('customers_link_match', 'Por qué datos reconocerlos', [
				{ value: 'email', label: 'Email' },
				{ value: 'phone', label: 'Teléfono' },
				{ value: 'doc_number', label: 'Documento' },
			], m.customers_link_match, 'El teléfono se compara por los últimos dígitos, así «+54 9 11 5555-1234» y «1155551234» son el mismo número. Sin ningún campo marcado, el ERP no propone nada.') +
			checkboxFieldHtml({
				key: 'customers_link_backfill', label: 'Al vincular, marcar también las ventas anteriores', checked: !!m.customers_link_backfill,
				hint: 'Las ventas que ya le habías cargado en el mostrador pasan a figurar como de esa cuenta. Es lo que hace que el historial quede completo de un solo lado.',
			})
		);
	}

	function panelVentasHtml(m) {
		return sectionHtml('El módulo', 'Las ventas se registran acá vengan de donde vengan: del mostrador, de la tienda o de un revendedor.',
			checkboxFieldHtml({
				key: 'sales_enabled', label: 'Registrar ventas', checked: !!m.sales_enabled,
				hint: 'Si lo apagás, el ERP deja de espejar los pedidos de la tienda y no vas a poder cargar ventas.',
			}) +
			checkboxFieldHtml({
				key: 'sales_track_cost', label: 'Guardar el costo de cada venta', checked: !!m.sales_track_cost,
				hint: 'Congela el costo landed del momento de la venta. Es lo que hace que el margen sea real y no cambie cuando cambia el dólar.',
			}) +
			checkboxFieldHtml({
				key: 'sales_allow_discount', label: 'Permitir descuentos al cargar', checked: !!m.sales_allow_discount,
			}) +
			checkboxFieldHtml({
				key: 'sales_allow_oversell', label: 'Dejar vender sin stock', checked: !!m.sales_allow_oversell,
				hint: 'Desactivado, el ERP no deja cargar una venta de algo que no tenés (salvo que el producto acepte pedidos pendientes).',
			})
		) +
		sectionHtml('Corregir ventas', 'Cambiar lo que ya se vendió toca plata que ya existe: lo hace solo quien elijas acá, y cada cambio queda anotado en la venta.',
			checkboxFieldHtml({
				key: 'sales_log_enabled', label: 'Llevar el historial de cada venta', checked: !!m.sales_log_enabled,
				hint: 'Anota quién cargó, cobró, entregó, corrigió o anuló cada venta, y cuándo. Se ve en el detalle de la venta, abajo de los cobros.',
			}) +
			checkboxFieldHtml({
				key: 'sales_edit_enabled', label: 'Dejar editar los productos de una venta sin cobrar', checked: !!m.sales_edit_enabled,
				hint: 'Cambiar cantidades, precios, descuento y envío, sacar productos o agregar otros, sin anular. Solo mientras la venta no tenga cobros ni productos de la consignación de un revendedor. El stock y el pedido de WooCommerce se acomodan solos.',
			}) +
			checkboxFieldHtml({
				key: 'sales_edit_require_reason', label: 'Pedir el motivo de cada corrección', checked: !!m.sales_edit_require_reason,
				hint: 'Queda escrito en el historial de la venta y en la nota del pedido.',
			}) +
			checkboxFieldHtml({
				key: 'sales_edit_redeliver', label: 'Si una venta entregada suma productos, vuelve a «A entregar»', checked: !!m.sales_edit_redeliver,
				hint: 'Lo que se agregó todavía no se lo llevó. Apagado, la entrega queda como estaba.',
			}) +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Quién puede corregir ventas y ver el historial</span>' +
				'<div data-region="roles-sales_admin_roles">' + rolesHtml(m, 'sales_admin_roles', 'El administrador siempre puede.') + '</div>' +
				'<div class="mj-field__hint">Además de uno de estos roles, hace falta poder entrar al ERP (pestaña Acceso).</div>' +
			'</div>'
		) +
		sectionHtml('Códigos de venta', 'Se generan solos al registrar una venta.',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'sales_code_prefix', label: 'Prefijo', value: str(m.sales_code_prefix), mono: true, maxlength: 20, placeholder: 'VTA',
					hint: 'Reemplaza al token <span class="mj-mono">{prefix}</span>.',
				}) +
				textFieldHtml({
					key: 'sales_code_format', label: 'Formato', value: str(m.sales_code_format), mono: true, maxlength: 60, placeholder: '{prefix}-{year}-{seq}',
					hint: 'Tokens: <span class="mj-mono">{prefix}</span> · <span class="mj-mono">{year}</span> · <span class="mj-mono">{month}</span> · <span class="mj-mono">{seq}</span>.',
				}) +
				textFieldHtml({
					key: 'sales_code_seq_digits', label: 'Dígitos', value: numText(m.sales_code_seq_digits), kind: 'number', inputmode: 'numeric', maxlength: 2,
					hint: 'Entre 1 y 10 (4 → 0001).',
				}) +
			'</div>'
		) +
		sectionHtml('Cargar una venta', 'Los valores con los que arranca la pantalla de venta nueva.',
			'<div class="mj-field">' +
				'<span class="mj-field__label">Canales</span>' +
				'<div data-region="editor-sales_channels">' + listEditorHtml(EDITORS.sales_channels, m.sales_channels) + '</div>' +
				'<div class="mj-field__hint">De dónde vino la venta: mostrador, WhatsApp, feria… Es solo para que después puedas filtrar.</div>' +
			'</div>' +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_default_channel', label: 'Canal por defecto', value: str(m.sales_default_channel), options: channelOptionsFrom(m),
				}) +
				selectFieldHtml({
					key: 'sales_shop_channel', label: 'Canal de la tienda', value: str(m.sales_shop_channel), options: channelOptionsFrom(m),
					hint: 'Con este canal quedan marcadas las ventas que entran solas por la tienda.',
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'sales_price_list', label: 'Lista de precios sugerida', value: str(m.sales_price_list), options: priceListOptions(m),
				hint: 'Con esta lista se propone el precio de cada producto. Dejala vacía para usar el precio que tiene el producto en la tienda.',
			}) +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_price_rounding_mode', label: 'Redondeo del precio sugerido', value: str(m.sales_price_rounding_mode),
					options: [
						{ value: 'tienda', label: 'Como la tienda' },
						{ value: 'erp', label: 'Como el ERP (Recepción y stock)' },
						{ value: 'personalizado', label: 'Paso propio' },
						{ value: 'ninguno', label: 'Sin redondear' },
					],
					hint: '«Como la tienda» usa la misma escalera con la que MJ Dólar publica el precio en la vidriera: lo que ves en el mostrador es lo que paga quien compra online. Solo afecta al precio que el ERP <span class="mj-strong">sugiere</span>; un precio escrito a mano se respeta tal cual.',
				}) +
				textFieldHtml({
					key: 'sales_price_rounding', label: 'Paso propio', value: numText(m.sales_price_rounding), kind: 'number', inputmode: 'numeric', maxlength: 9,
					hint: 'Solo se usa con «Paso propio». 100 redondea a los $100 más cercanos; 0, a los centavos.',
				}) +
			'</div>' +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Medios de pago</span>' +
				'<div data-region="editor-sales_payment_methods">' + listEditorHtml(EDITORS.sales_payment_methods, m.sales_payment_methods) + '</div>' +
				'<div class="mj-field__hint">Son las opciones de «cómo pagó». Si dejás la clave vacía se genera del nombre. <span class="mj-strong">La caja de ingreso</span> decide dónde entra esa plata: una transferencia a la caja de transferencias, los dólares físicos a la caja de dólares. Vale para las ventas del mostrador, los cobros de reventa y los pedidos de la tienda. Las cajas se crean en <span class="mj-strong">Proveedores → Cajas</span>.</div>' +
			'</div>' +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_default_payment_method', label: 'Medio por defecto', value: str(m.sales_default_payment_method), options: salesMethodOptionsFrom(m),
				}) +
				selectFieldHtml({
					key: 'sales_default_cashbox', label: 'Caja por defecto', value: String(m.sales_default_cashbox || 0), options: cashboxOptions(), kind: 'intval',
					hint: 'La que se usa cuando el medio de pago no tiene una caja propia.',
				}) +
			'</div>' +
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'sales_pos_per_page', label: 'Productos por página en el catálogo', value: numText(m.sales_pos_per_page), kind: 'number', inputmode: 'numeric', maxlength: 2,
					hint: 'Cuántos productos trae de una el catálogo de la pantalla de venta (entre 6 y 96).',
				}) +
				checkboxFieldHtml({
					key: 'sales_pos_images', label: 'Mostrar las fotos de los productos', checked: !!m.sales_pos_images,
					hint: 'Con foto se elige más rápido de memoria; sin foto entran más productos en pantalla.',
				}) +
			'</div>' +
			textFieldHtml({
				key: 'sales_pos_steps_width', label: 'Ancho para el paso a paso', value: numText(m.sales_pos_steps_width), kind: 'number', inputmode: 'numeric', maxlength: 4,
				hint: 'En pantallas más angostas que esto (en píxeles) la venta se carga <span class="mj-strong">en pasos</span>: productos, cantidades, cliente, pago y entrega, con los botones fijos abajo. ' +
					'Más anchas usan el mostrador de dos columnas. 900 deja el paso a paso en celulares y tablets verticales; 0 lo apaga.',
			})
		) +
		sectionHtml('Entrega', 'Lo vendido todavía tiene que llegar a manos del cliente. Cada forma de entrega dice si la venta ya queda entregada al elegirla: así el mostrador no ensucia la lista de lo que falta entregar.',
			checkboxFieldHtml({
				key: 'sales_delivery_enabled', label: 'Usar el paso de entrega', checked: !!m.sales_delivery_enabled,
				hint: 'Apagado, las ventas no piden ni muestran entrega en ninguna pantalla.',
			}) +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Formas de entrega</span>' +
				'<div data-region="editor-sales_delivery_methods">' + listEditorHtml(EDITORS.sales_delivery_methods, m.sales_delivery_methods) + '</div>' +
				'<div class="mj-field__hint">Envío, punto de encuentro, retira en el local, entrega en mano… las que uses. Si dejás la clave vacía se genera del nombre.</div>' +
			'</div>' +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_default_delivery_method', label: 'Forma por defecto', value: str(m.sales_default_delivery_method), options: deliveryOptionsFrom(m),
					hint: 'Con la que arranca la pantalla de venta nueva.',
				}) +
				selectFieldHtml({
					key: 'sales_shop_delivery_method', label: 'Forma de los pedidos de la tienda', value: str(m.sales_shop_delivery_method), options: deliveryOptionsFrom(m),
					hint: 'Con esta nacen las ventas que entran solas por WooCommerce, que todavía no salieron de acá.',
				}) +
			'</div>'
		) +
		sectionHtml('Pedido en WooCommerce', 'Es lo que hace que la venta exista para la tienda: aparece en los informes de Woo y te llega la notificación en la app del celular.',
			checkboxFieldHtml({
				key: 'sales_create_order', label: 'Emitir el pedido en WooCommerce', checked: !!m.sales_create_order,
				hint: 'Podés desmarcarlo venta por venta desde la pantalla de carga.',
			}) +
			selectFieldHtml({
				key: 'sales_commit_when', label: 'Cuándo se emite y baja el stock', value: str(m.sales_commit_when), options: COMMIT_WHEN_OPTIONS,
				hint: 'Anotar una venta no reserva nada: el producto se sigue pudiendo vender hasta que alguien lo pague o se lo lleve. ' +
					'Con <span class="mj-strong">cobrada o entregada</span> el pedido se emite solo en cuanto pasa cualquiera de las dos, ' +
					'y ahí baja el stock. Un pedido anotado que nadie pagó ni retiró no toca nada.',
			}) +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_order_status_paid', label: 'Estado si está cobrada', value: str(m.sales_order_status_paid), options: orderStatusOptions(),
				}) +
				selectFieldHtml({
					key: 'sales_order_status_unpaid', label: 'Estado si queda a cobrar', value: str(m.sales_order_status_unpaid), options: orderStatusOptions(),
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'sales_reduce_stock', label: 'Stock del pedido', value: str(m.sales_reduce_stock), options: SALES_STOCK_OPTIONS,
				hint: 'WooCommerce descuenta solo según el estado del pedido. Cambiá esto únicamente si en tu tienda no lo está haciendo.',
			}) +
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'sales_order_created_via', label: 'Origen del pedido', value: str(m.sales_order_created_via), mono: true, maxlength: 60, placeholder: 'mj-erp',
					hint: 'Es la etiqueta que WooCommerce muestra como «creado vía».',
				}) +
				textFieldHtml({
					key: 'sales_order_note', label: 'Nota del pedido', value: str(m.sales_order_note), maxlength: 190, placeholder: 'Venta registrada desde MJ ERP',
					hint: 'Se agrega como nota interna. Dejala vacía para no agregar ninguna.',
				}) +
			'</div>'
		) +
		sectionHtml('Lo que se vende en la tienda', 'Cada pedido de la tienda entra al ERP como una venta más, con su costo y su margen.',
			checkboxFieldHtml({
				key: 'sales_capture_shop_orders', label: 'Registrar los pedidos de la tienda', checked: !!m.sales_capture_shop_orders,
			}) +
			checkboxFieldHtml({
				key: 'sales_capture_manual_orders', label: 'Registrar también los pedidos cargados a mano en WordPress', checked: !!m.sales_capture_manual_orders,
			}) +
			checkboxFieldHtml({
				key: 'sales_capture_payment', label: 'Dar por cobrada la venta cuando el pedido se paga', checked: !!m.sales_capture_payment,
				hint: 'Si el pedido se pagó por la pasarela, el cobro queda registrado solo.',
			}) +
			selectFieldHtml({
				key: 'sales_capture_on', label: 'Cuándo se registra', value: str(m.sales_capture_on), options: SALES_CAPTURE_OPTIONS,
			}) +
			statusChecklistHtml('sales_capture_statuses', 'Estados que confirman la venta', m.sales_capture_statuses,
				'Solo se usan con la segunda opción de arriba.') +
			statusChecklistHtml('sales_void_statuses', 'Estados que la anulan', m.sales_void_statuses,
				'Con estos estados se deshace la venta y, si era de un revendedor, se le saca la deuda. El stock lo repone WooCommerce.')
		) +
		sectionHtml('Cuando compra un revendedor', 'Si el que compra tiene mercadería tuya en consignación, lo que compre sale de ahí en vez del depósito.',
			MJ.ui.alert('Se revisa producto por producto: lo que tiene entregado se le imputa a su cuenta al precio acordado, y lo que no tiene sale del depósito como una venta común.', 'info') +
			checkboxFieldHtml({
				key: 'sales_consignment_enabled', label: 'Descontar de la consignación', checked: !!m.sales_consignment_enabled,
				hint: 'Podés apagarlo para un revendedor puntual desde su ficha.',
			}) +
			checkboxFieldHtml({
				key: 'sales_consignment_partial_lines', label: 'Partir una línea entre consignación y depósito', checked: !!m.sales_consignment_partial_lines,
				hint: 'Si compra 5 y tiene 2, con esto se le imputan 2 y las otras 3 salen del depósito. Sin esto, las 5 salen del depósito.',
			}) +
			'<div class="mj-form-row">' +
				selectFieldHtml({
					key: 'sales_consignment_allocation', label: 'Qué entrega se consume primero', value: str(m.sales_consignment_allocation), options: SALES_ALLOCATION_OPTIONS,
				}) +
				selectFieldHtml({
					key: 'sales_consignment_approval', label: 'Estas ventas', value: str(m.sales_consignment_approval), options: SALES_CONSIGN_APPROVAL_OPTIONS,
					hint: 'La venta ya ocurrió en la tienda, así que lo normal es que se imputen solas.',
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'sales_consignment_payment_method', label: 'Medio de pago de esa parte', value: str(m.sales_consignment_payment_method), options: salesMethodOptionsFrom(m),
				hint: 'Lo que sale de la consignación no se cobra en la venta: va a su cuenta corriente.',
			})
		) +
		sectionHtml('Precio que ve un revendedor en la tienda', 'Mientras esté con su sesión iniciada.',
			selectFieldHtml({
				key: 'shop_price_mode', label: 'Precio', value: str(m.shop_price_mode), options: SHOP_PRICE_OPTIONS,
			}) +
			selectFieldHtml({
				key: 'shop_price_list', label: 'Lista de precios', value: str(m.shop_price_list), options: priceListOptions(m),
				hint: 'Se calcula desde el costo landed más el markup de la lista, igual que los precios que publica el ERP. Un producto sin costo cargado mantiene su precio normal.',
			}) +
			checkboxFieldHtml({
				key: 'shop_price_notice', label: 'Avisarle que está viendo su precio', checked: !!m.shop_price_notice,
			})
		);
	}

	/* --- Permisos del revendedor -------------------------------------------------- */

	const PERM_TRISTATE = [
		{ value: '', label: 'Heredar' },
		{ value: '1', label: 'Sí' },
		{ value: '0', label: 'No' },
	];

	/** Catálogo de permisos, tal como lo declara el servidor. */
	function permissionKeys() {
		const info = (MJ.state.bootstrap && MJ.state.bootstrap.sales) || {};
		const keys = info.permissions;
		return keys && typeof keys === 'object' ? keys : {};
	}

	function permissionTree(m) {
		const tree = m.portal_permissions && typeof m.portal_permissions === 'object' ? m.portal_permissions : {};
		return {
			defaults: tree.defaults && typeof tree.defaults === 'object' ? tree.defaults : {},
			roles: tree.roles && typeof tree.roles === 'object' ? tree.roles : {},
		};
	}

	/** Roles a los que tiene sentido darles permisos de revendedor. */
	function permissionRoles(m) {
		const roles = (MJ.state.bootstrap && MJ.state.bootstrap.roles) || MJ.config.roles || [];
		const consigneeRole = slugify(m.consignee_role);
		const out = [];
		roles.forEach(function (r) {
			if (!r || !r.key || r.key === 'administrator') return;
			out.push({ key: r.key, label: r.label || r.key, isConsignee: r.key === consigneeRole });
		});
		// El rol de reventa primero: es el que se toca siempre.
		out.sort(function (a, b) { return (b.isConsignee ? 1 : 0) - (a.isConsignee ? 1 : 0); });
		return out;
	}

	function permDefaultsHtml(m) {
		const keys = permissionKeys();
		const tree = permissionTree(m);
		const values = tree.defaults;
		const lists = priceListOptions(m);
		const methods = Array.isArray(m.consignment_payment_methods) ? m.consignment_payment_methods : [];

		let html = '';

		Object.keys(keys).forEach(function (key) {
			const def = keys[key] || {};
			const value = values[key];

			if (def.type === 'enum') {
				const options = Object.keys(def.options || {}).map(function (k) {
					return { value: k, label: def.options[k] };
				});
				html += '<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-perm-' + h(key) + '">' + h(def.label || key) + '</label>' +
					'<select class="mj-select" id="mj-perm-' + h(key) + '" data-perm-scope="defaults" data-perm-key="' + h(key) + '" data-perm-type="enum">' +
						optionsHtml(options, value) +
					'</select>' +
					hintHtml(def.help) +
				'</div>';
			} else if (def.type === 'price_list') {
				html += '<div class="mj-field">' +
					'<label class="mj-field__label" for="mj-perm-' + h(key) + '">' + h(def.label || key) + '</label>' +
					'<select class="mj-select" id="mj-perm-' + h(key) + '" data-perm-scope="defaults" data-perm-key="' + h(key) + '" data-perm-type="enum">' +
						optionsHtml([{ value: '', label: 'La lista general de la tienda' }].concat(lists), value) +
					'</select>' +
					hintHtml(def.help) +
				'</div>';
			} else if (def.type === 'methods') {
				const selected = Array.isArray(value) ? value.map(String) : [];
				html += '<div class="mj-field">' +
					'<span class="mj-field__label">' + h(def.label || key) + '</span>' +
					'<div class="mj-row mj-row--wrap mj-checkbox-grid" data-perm-methods="defaults">' +
						methods.map(function (method) {
							const mk = slugify(method.key) || slugify(method.label);
							if (!mk) return '';
							const on = selected.indexOf(mk) >= 0;
							return '<label class="mj-checkbox"><input type="checkbox" data-perm-method="' + h(mk) + '"' + (on ? ' checked' : '') + '> <span>' + h(method.label || mk) + '</span></label>';
						}).join('') +
					'</div>' +
					hintHtml(def.help) +
				'</div>';
			} else {
				html += '<div class="mj-field">' +
					'<label class="mj-checkbox">' +
						'<input type="checkbox" data-perm-scope="defaults" data-perm-key="' + h(key) + '" data-perm-type="bool"' + (value ? ' checked' : '') + '> ' +
						'<span>' + h(def.label || key) + '</span>' +
					'</label>' +
					hintHtml(def.help) +
				'</div>';
			}
		});

		return html;
	}

	function permRolesHtml(m) {
		const keys = permissionKeys();
		const tree = permissionTree(m);
		const roles = permissionRoles(m);

		if (!roles.length) return '<div class="mj-muted">No hay otros roles en esta instalación.</div>';

		const boolKeys = Object.keys(keys).filter(function (k) { return (keys[k] || {}).type !== 'methods'; });

		return roles.map(function (role) {
			const overrides = tree.roles[role.key] && typeof tree.roles[role.key] === 'object' ? tree.roles[role.key] : {};
			const count = Object.keys(overrides).length;

			return '<details class="mj-details"' + (count || role.isConsignee ? ' open' : '') + '>' +
				'<summary class="mj-details__summary">' +
					'<span class="mj-strong">' + h(role.label) + '</span> ' +
					'<span class="mj-mono mj-small mj-muted">' + h(role.key) + '</span> ' +
					(count ? MJ.ui.badgeRaw(count === 1 ? '1 cambio' : count + ' cambios', 'info') : '<span class="mj-small mj-muted">hereda todo</span>') +
				'</summary>' +
				'<div class="mj-details__body mj-grid mj-grid--2">' +
					boolKeys.map(function (key) {
						const def = keys[key] || {};
						const has = Object.prototype.hasOwnProperty.call(overrides, key);
						const id = 'mj-perm-' + h(role.key) + '-' + h(key);

						if (def.type === 'enum' || def.type === 'price_list') {
							const options = def.type === 'enum'
								? Object.keys(def.options || {}).map(function (k) { return { value: k, label: def.options[k] }; })
								: [{ value: '', label: 'La lista general' }].concat(priceListOptions(m));
							return '<div class="mj-field">' +
								'<label class="mj-field__label" for="' + id + '">' + h(def.label || key) + '</label>' +
								'<select class="mj-select" id="' + id + '" data-perm-scope="' + h(role.key) + '" data-perm-key="' + h(key) + '" data-perm-type="enum-optional">' +
									optionsHtml([{ value: '__inherit__', label: 'Heredar' }].concat(options), has ? String(overrides[key]) : '__inherit__') +
								'</select>' +
							'</div>';
						}

						return '<div class="mj-field">' +
							'<label class="mj-field__label" for="' + id + '">' + h(def.label || key) + '</label>' +
							'<select class="mj-select" id="' + id + '" data-perm-scope="' + h(role.key) + '" data-perm-key="' + h(key) + '" data-perm-type="tristate">' +
								optionsHtml(PERM_TRISTATE, has ? (overrides[key] ? '1' : '0') : '') +
							'</select>' +
						'</div>';
					}).join('') +
				'</div>' +
			'</details>';
		}).join('');
	}

	function panelConsignacionesHtml(m) {
		const portalUrl = (MJ.state.bootstrap && MJ.state.bootstrap.consignment && MJ.state.bootstrap.consignment.portal_url) || '';
		const listOptions = priceListOptions(m);

		return sectionHtml('Revendedores', 'Los revendedores son usuarios de WordPress: así quedan también como clientes de WooCommerce y pueden entrar a su panel.',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'consignee_role', label: 'Rol de reventa', value: str(m.consignee_role), mono: true, maxlength: 40, placeholder: 'reventa',
					hint: 'Si el rol no existe, se crea solo con permiso de lectura y acceso al panel del revendedor. Cambiar la clave no le quita el rol a los que ya lo tienen.',
				}) +
				textFieldHtml({
					key: 'consignee_role_label', label: 'Nombre del rol', value: str(m.consignee_role_label), maxlength: 60, placeholder: 'Reventa',
					hint: 'Es el nombre que se ve en la lista de usuarios de WordPress.',
				}) +
			'</div>' +
			selectFieldHtml({
				key: 'consignment_price_list', label: 'Lista de precio por defecto', value: str(m.consignment_price_list), options: listOptions,
				hint: 'Con esta lista se propone el precio acordado de cada entrega. Cada revendedor puede tener otra.',
			})
		) +
		sectionHtml('Códigos de entrega', 'Se generan al crear una entrega; siempre podés escribirlos a mano.',
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'consignment_code_prefix', label: 'Prefijo', value: str(m.consignment_code_prefix), mono: true, maxlength: 20, placeholder: 'CON',
					hint: 'Reemplaza al token <span class="mj-mono">{prefix}</span>.',
				}) +
				textFieldHtml({
					key: 'consignment_code_format', label: 'Formato', value: str(m.consignment_code_format), mono: true, maxlength: 60, placeholder: '{prefix}-{year}-{seq}',
					hint: 'Mismos tokens que las compras: <span class="mj-mono">{prefix}</span> · <span class="mj-mono">{year}</span> · <span class="mj-mono">{month}</span> · <span class="mj-mono">{seq}</span>.',
				}) +
				textFieldHtml({
					key: 'consignment_code_seq_digits', label: 'Dígitos', value: numText(m.consignment_code_seq_digits), kind: 'number', inputmode: 'numeric', maxlength: 2,
					hint: 'Entre 1 y 10 (4 → 0001).',
				}) +
			'</div>'
		) +
		sectionHtml('Ventas del revendedor', 'Qué pasa cuando el revendedor vende una unidad.',
			MJ.ui.alert('El stock de WooCommerce no baja al entregar: baja cuando el revendedor avisa la venta. Mientras la mercadería está en la calle, esas unidades siguen contando como stock propio, así que revisá el listado de Productos antes de prometer entregas grandes.', 'info') +
			selectFieldHtml({
				key: 'consignment_stock_on_sale', label: 'Stock al vender', value: m.consignment_stock_on_sale, options: CONSIGN_STOCK_OPTIONS,
				hint: 'Con la primera opción, cada venta descuenta de WooCommerce y queda registrada en los movimientos de stock.',
			}) +
			selectFieldHtml({
				key: 'consignment_approval', label: 'Qué ventas necesitan tu confirmación', value: m.consignment_approval, options: CONSIGN_APPROVAL_OPTIONS,
				hint: 'Lo normal es que ninguna: el revendedor avisa lo que vendió, baja el stock y le queda la deuda. Lo que sí confirmás es <span class="mj-strong">el pago</span>, más abajo, porque es lo único que podés verificar vos. Una venta sin confirmar no baja stock ni genera deuda, pero igual reserva las unidades para que no se vendan dos veces.',
			}) +
			checkboxFieldHtml({
				key: 'consignment_allow_oversell', label: 'Dejar registrar más ventas que unidades entregadas', checked: !!m.consignment_allow_oversell,
				hint: 'Desactivado (recomendado), el ERP no deja vender lo que el revendedor no tiene. Activalo solo si vas a ordenar los papeles después.',
			})
		) +
		sectionHtml('Cobranzas', 'Cómo se imputan los pagos a las ventas.',
			selectFieldHtml({
				key: 'consignment_allocation', label: 'Imputación automática', value: m.consignment_allocation, options: CONSIGN_ALLOCATION_OPTIONS,
				hint: 'Siempre podés cambiar el reparto a mano antes de confirmar el pago. Lo que sobre queda como saldo a favor y se aplica solo en la próxima venta.',
			}) +
			'<div class="mj-field">' +
				'<span class="mj-field__label">Medios de cobro</span>' +
				'<div data-region="editor-consignment_payment_methods">' + listEditorHtml(EDITORS.consignment_payment_methods, m.consignment_payment_methods) + '</div>' +
				'<div class="mj-field__hint">Son las opciones del campo «Cómo me pagó». Si dejás la clave vacía se genera a partir del nombre. La caja de cada medio es la misma que usás en Ventas: configúrala en cualquiera de los dos lados y vale para ambos.</div>' +
			'</div>'
		) +
		sectionHtml('Panel del revendedor', 'Una pantalla propia, pensada para el celular, donde el revendedor ve lo que tiene y avisa lo que vendió.',
			checkboxFieldHtml({
				key: 'portal_enabled', label: 'Habilitar el panel del revendedor', checked: !!m.portal_enabled,
				hint: 'Desactivado, la dirección deja de responder y las entregas y ventas las cargás solo vos.',
			}) +
			'<div class="mj-form-row">' +
				textFieldHtml({
					key: 'portal_slug', label: 'Slug del panel', value: str(m.portal_slug), mono: true, maxlength: 60, placeholder: 'reventa',
					hint: 'No puede ser el mismo que el del ERP.',
					after: portalUrl ? '<div class="mj-field__hint">Hoy responde en <a class="mj-link mj-mono" href="' + h(portalUrl) + '" target="_blank" rel="noopener">' + h(portalUrl) + '</a></div>' : '',
				}) +
				textFieldHtml({
					key: 'portal_title', label: 'Título', value: str(m.portal_title), maxlength: 60, placeholder: 'Mi cuenta',
					hint: 'Se ve arriba en la pestaña del navegador del revendedor.',
				}) +
			'</div>' +
			checkboxFieldHtml({
				key: 'portal_allow_sales', label: 'Dejar que el revendedor cargue sus ventas', checked: !!m.portal_allow_sales,
				hint: 'Cada venta que avisa baja el stock y le queda la deuda en el momento. Solo espera tu confirmación si lo pediste arriba.',
			}) +
			textFieldHtml({
				key: 'portal_undo_minutes', label: 'Minutos para deshacer una venta', value: numText(m.portal_undo_minutes), kind: 'number', inputmode: 'numeric', maxlength: 5,
				hint: 'Como la venta cuenta en el momento, este es el rato que tiene para arreglar un botón mal apretado: borra la venta, devuelve la unidad y saca la deuda. Solo sobre ventas que cargó él y que todavía no tengan un pago aplicado. 0 = no puede deshacer nada y las corregís vos desde el ERP.',
			}) +
			checkboxFieldHtml({
				key: 'portal_allow_returns', label: 'Dejar que el revendedor registre devoluciones', checked: !!m.portal_allow_returns,
				hint: 'Desactivado, las devoluciones las registrás vos cuando la mercadería vuelve a tus manos.',
			}) +
			MJ.ui.alert('El revendedor nunca ve precios de costo, márgenes, ni las cuentas de otros revendedores. Tampoco puede entrar al ERP ni al panel de WordPress: si intenta, va a su propia pantalla.', 'info')
		) +
		sectionHtml('Qué puede hacer un revendedor', 'Esto vale para todos. Cada rol puede cambiar lo que quiera de acá abajo, y la ficha de cada revendedor puede cambiar lo suyo.',
			'<div data-region="perm-defaults">' + permDefaultsHtml(m) + '</div>'
		) +
		sectionHtml('Por rol', 'Solo lo que marques como «Sí» o «No» pisa lo de arriba; lo que dejes en «Heredar» sigue el valor general aunque después lo cambies.',
			'<div data-region="perm-roles">' + permRolesHtml(m) + '</div>'
		);
	}

	function panelAccesoHtml(m) {
		return sectionHtml('Roles con acceso', '',
			MJ.ui.alert('Los roles marcados reciben la capacidad <span class="mj-mono">manage_mjerp</span>, que es la que habilita abrir el ERP y usar su API. Al guardar, la capacidad se quita de los roles desmarcados. Los administradores siempre conservan el acceso.', 'info', { html: true }) +
			'<div data-region="roles">' + rolesHtml(m) + '</div>'
		);
	}

	function panelAvanzadoHtml(m, appUrl) {
		return sectionHtml('Datos al desinstalar', '',
			MJ.ui.alert('Si activás esta opción, al eliminar el plugin se borran de forma permanente las compras, ítems, gastos, proveedores, cajas, movimientos y la configuración. No se puede deshacer.', 'danger') +
			checkboxFieldHtml({
				key: 'delete_data_on_uninstall', label: 'Borrar todos los datos del ERP al desinstalar el plugin', checked: !!m.delete_data_on_uninstall,
				hint: 'Desactivado, los datos quedan en la base por si reinstalás el plugin.',
			})
		) +
		sectionHtml('Información del sistema', 'Datos de solo lectura, útiles para soporte.',
			infoBlockHtml(appUrl)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page shell                                                          */
	/* ------------------------------------------------------------------ */

	function headerHtml() {
		return '<div class="mj-page-header">' +
			'<div>' +
				'<h2 class="mj-page-header__title">Configuración</h2>' +
				'<div class="mj-page-header__subtitle mj-muted">Ajustes generales, compras, precios, recepción y acceso</div>' +
			'</div>' +
			'<div class="mj-page-header__actions">' +
				'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon" data-action="reload" title="Volver a cargar" aria-label="Volver a cargar">' + MJ.icon('refresh') + '</button>' +
			'</div>' +
		'</div>';
	}

	function tabsHtml(active) {
		let html = '<div class="mj-tabs" role="tablist" aria-label="Secciones de configuración">';
		TABS.forEach(function (t) {
			const isActive = t.key === active;
			html += '<button type="button" class="mj-tabs__tab' + (isActive ? ' is-active' : '') + '" data-tab="' + h(t.key) + '"' +
				' role="tab" id="mj-set-tab-' + h(t.key) + '" aria-controls="mj-set-panel-' + h(t.key) + '" aria-selected="' + (isActive ? 'true' : 'false') + '">' +
				MJ.icon(t.icon) + '<span>' + h(t.label) + '</span>' +
			'</button>';
		});
		html += '</div>';
		return html;
	}

	function panelsHtml(m, active, appUrl) {
		const bodies = {
			general: panelGeneralHtml(m),
			ventas: panelVentasHtml(m),
			clientes: panelClientesHtml(m),
			compras: panelComprasHtml(m),
			precios: panelPreciosHtml(m),
			stock: panelStockHtml(m),
			consignaciones: panelConsignacionesHtml(m),
			acceso: panelAccesoHtml(m),
			avanzado: panelAvanzadoHtml(m, appUrl),
		};
		let html = '';
		TABS.forEach(function (t) {
			html += '<div data-tab-panel="' + h(t.key) + '" id="mj-set-panel-' + h(t.key) + '" role="tabpanel" aria-labelledby="mj-set-tab-' + h(t.key) + '"' +
				(t.key === active ? '' : ' class="mj-hidden"') + '>' + bodies[t.key] + '</div>';
		});
		return html;
	}

	function footerHtml() {
		return '<div class="mj-card__footer mj-row mj-row--between mj-row--wrap" style="position:sticky;bottom:0;z-index:4">' +
			'<div class="mj-small mj-muted" data-dirty-note></div>' +
			'<div class="mj-row">' +
				'<button type="button" class="mj-btn mj-btn--ghost" data-action="reset">' + MJ.icon('refresh') + '<span>Restablecer</span></button>' +
				'<button type="submit" class="mj-btn mj-btn--primary" data-action="save">' + MJ.icon('save') + '<span>Guardar cambios</span></button>' +
			'</div>' +
		'</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	MJ.pages.settings = {
		title: 'Configuración',

		render(container, params, query) {
			const requested = query && query.tab ? String(query.tab) : '';
			const state = {
				model: null,
				savedJson: '',
				tab: TABS.some(function (t) { return t.key === requested; }) ? requested : 'general',
				appUrl: str(MJ.config && MJ.config.appUrl),
				notice: '',
				loading: false,
				saving: false,
				disposed: false,
				seq: 0,
			};
			const offs = [];
			let tabsApi = null;

			container.innerHTML = headerHtml() +
				'<div data-region="notice"></div>' +
				'<div data-region="body">' + loadingHtml('Cargando configuración…') + '</div>';

			const region = function (name) { return qs('[data-region="' + name + '"]', container); };

			/* ---------------- painting ---------------- */

			function paintNotice() {
				const el = region('notice');
				if (!el) return;
				el.innerHTML = state.notice ? '<div class="mj-mb">' + state.notice + '</div>' : '';
			}

			function paint() {
				const body = region('body');
				if (!body || !state.model) return;
				if (tabsApi && typeof tabsApi.destroy === 'function') { tabsApi.destroy(); tabsApi = null; }
				body.innerHTML =
					tabsHtml(state.tab) +
					'<form class="mj-form" data-form novalidate autocomplete="off">' +
						'<div class="mj-card">' +
							'<div class="mj-card__body" data-panels>' + panelsHtml(state.model, state.tab, state.appUrl) + '</div>' +
							footerHtml() +
						'</div>' +
					'</form>';
				const tabsRoot = qs('.mj-tabs', body);
				const panelsRoot = qs('[data-panels]', body);
				if (tabsRoot) {
					tabsApi = MJ.ui.tabs(tabsRoot, {
						panels: panelsRoot,
						active: state.tab,
						onChange: function (key) {
							state.tab = key;
							syncHash();
						},
					});
				}
				paintDirty();
			}

			function paintError(err) {
				const body = region('body');
				if (!body) return;
				body.innerHTML = '<div class="mj-card"><div class="mj-card__body">' +
					MJ.ui.alert(errMessage(err, 'No se pudo cargar la configuración.'), 'danger') +
					'<div class="mj-mt"><button type="button" class="mj-btn" data-action="reload">' + MJ.icon('refresh') + '<span>Reintentar</span></button></div>' +
				'</div></div>';
			}

			function paintRegion(name, html) {
				qsa('[data-region="' + name + '"]', container).forEach(function (el) { el.innerHTML = html; });
			}

			function paintDirty() {
				const note = qs('[data-dirty-note]', container);
				const saveBtn = qs('[data-action="save"]', container);
				const dirty = isDirty();
				if (note) {
					note.innerHTML = state.saving
						? '<span class="mj-muted">Guardando…</span>'
						: (dirty ? '<span class="mj-warning-text">Tenés cambios sin guardar.</span>' : '<span class="mj-muted">Todo guardado.</span>');
				}
				if (saveBtn && !state.saving) saveBtn.disabled = !dirty;
			}

			function isDirty() {
				if (!state.model) return false;
				return fingerprint(state.model) !== state.savedJson;
			}

			function syncHash() {
				replaceHash(PAGE_PATH, { tab: state.tab !== 'general' ? state.tab : '' });
			}

			function activateTab(key) {
				if (!TABS.some(function (t) { return t.key === key; })) return;
				state.tab = key;
				if (tabsApi && typeof tabsApi.activate === 'function') tabsApi.activate(key);
				else paint();
				syncHash();
			}

			/* ---------------- derived regions ---------------- */

			function refreshCurrencyRegions() {
				const m = state.model;
				paintRegion('currency-selects', currencySelectsHtml(m));
				paintRegion('currency-symbols', currencySymbolsHtml(m));
			}

			function refreshPriceListSelects() {
				paintRegion('price-list-select', priceListSelectHtml(state.model));
			}

			/**
			 * Los selectores que eligen «cuál de estos» tienen que seguir a la lista que editaste:
			 * si borrás el canal que estaba por defecto, el select no puede quedar apuntando a él.
			 */
			function refreshSalesSelects() {
				const m = state.model;
				if (!m) return;

				const pairs = [
					{ keys: ['sales_default_channel', 'sales_shop_channel'], options: channelOptionsFrom(m) },
					{ keys: ['sales_default_payment_method', 'sales_consignment_payment_method'], options: salesMethodOptionsFrom(m) },
					{ keys: ['sales_default_delivery_method', 'sales_shop_delivery_method'], options: deliveryOptionsFrom(m) },
				];

				pairs.forEach(function (pair) {
					pair.keys.forEach(function (key) {
						const select = qs('[data-setting="' + MJ.ui.cssEscape(key) + '"]', container);
						if (!select) return;
						const exists = pair.options.some(function (o) { return String(o.value) === String(m[key]); });
						if (!exists && pair.options.length) m[key] = pair.options[0].value;
						MJ.ui.fillSelect(select, pair.options, m[key]);
					});
				});
			}

			/** El «tipo por defecto» tiene que seguir a la lista de tipos que se está editando. */
			function refreshDocTypeSelect() {
				const m = state.model;
				if (!m) return;
				const select = qs('[data-setting="' + MJ.ui.cssEscape('customers_default_doc_type') + '"]', container);
				if (!select) return;
				const options = docTypeOptionsFrom(m);
				const exists = options.some(function (o) { return String(o.value) === String(m.customers_default_doc_type); });
				if (!exists) m.customers_default_doc_type = options.length ? options[0].value : '';
				MJ.ui.fillSelect(select, options, m.customers_default_doc_type);
			}

			function refreshPriceListNotes() {
				const editor = qs('[data-editor="price_lists"]', container);
				if (!editor) return;
				(state.model.price_lists || []).forEach(function (row, index) {
					const note = qs('[data-row-note="' + index + '"]', editor);
					if (note) note.innerHTML = priceListNoteHtml(state.model, row);
				});
			}

			/**
			 * Repaints every region that depends on `key`.
			 * @param {string} key Setting key that changed.
			 * @param {boolean} structural True on `change` (blur), false while typing.
			 */
			function refreshDerived(key, structural) {
				const m = state.model;
				if (!m) return;
				if (key === 'route_slug') paintRegion('slug-preview', slugPreviewHtml(m));
				if (key === 'code_prefix' || key === 'code_format' || key === 'code_seq_digits') paintRegion('code-preview', codePreviewHtml(m));
				if (key === 'default_rate') paintRegion('rate-preview-default_rate', ratePreviewHtml(m, 'default_rate'));
				if (key === 'sale_rate') { paintRegion('rate-preview-sale_rate', ratePreviewHtml(m, 'sale_rate')); refreshPriceListNotes(); }
				if (key === 'decimals' || key === 'price_rounding' || key === 'base_currency' || key === 'local_currency') refreshPriceListNotes();
				if (key === 'sale_rate_source') paintRegion('sale-rate-source', saleRateSourceNoteHtml(m));
				// The publishing mode and the USD rounding both change which number gets published,
				// so the status banner and every price example have to follow.
				if (key === 'price_publish_mode' || key === 'usd_rounding' || key === 'usd_rounding_mode') {
					paintRegion('dolar-status', dolarStatusHtml(m));
					paintRegion('price-lists-hint', priceListsHintHtml(m));
					refreshPriceListNotes();
				}
				paintDirty();
			}

			/* ---------------- model writes ---------------- */

			function writeControl(el, structural) {
				const key = el.getAttribute('data-setting');
				if (!key || !state.model) return;
				const kind = el.getAttribute('data-kind') || 'text';
				let value;
				if (el.type === 'checkbox') value = !!el.checked;
				else if (kind === 'upper') value = cleanCurrency(el.value);
				else if (kind === 'intval') value = toInt(el.value, 0);
				else value = el.value;
				state.model[key] = value;
				// Keep every control bound to the same setting in sync (price_list_for_price lives in two tabs).
				qsa('[data-setting="' + MJ.ui.cssEscape(key) + '"]', container).forEach(function (other) {
					if (other === el) return;
					if (other.type === 'checkbox') other.checked = !!value;
					else other.value = String(value);
				});
				clearFieldError(key);
				refreshDerived(key, structural);
			}

			function writeSymbol(el) {
				const code = cleanCurrency(el.getAttribute('data-symbol'));
				if (!code || !state.model) return;
				if (!isPlainObject(state.model.currency_symbols)) state.model.currency_symbols = {};
				state.model.currency_symbols[code] = el.value;
				refreshPriceListNotes();
				paintDirty();
			}

			function writeRole(el) {
				const role = str(el.getAttribute('data-role'));
				const setting = str(el.getAttribute('data-role-setting')) || 'allowed_roles';
				if (!role || !state.model) return;
				const roles = Array.isArray(state.model[setting]) ? state.model[setting].slice() : [];
				const at = roles.indexOf(role);
				if (el.checked && at === -1) roles.push(role);
				if (!el.checked && at >= 0) roles.splice(at, 1);
				if (roles.indexOf('administrator') === -1) roles.unshift('administrator');
				state.model[setting] = roles;
				clearFieldError(setting);
				paintDirty();
			}

			/* ---------------- list editors ---------------- */

			function editorRows(name) {
				if (!state.model) return [];
				if (name === 'currencies') return scalarRows(state.model.currencies);
				if (name === 'destinations') return scalarRows(state.model.destinations);
				return Array.isArray(state.model[name]) ? state.model[name] : [];
			}

			function paintEditor(name) {
				const el = region('editor-' + name);
				if (!el) return;
				const cfg = EDITORS[name];
				const rows = editorRows(name);
				el.innerHTML = name === 'price_lists'
					? listEditorHtml(cfg, rows, function (row) { return priceListNoteHtml(state.model, row); })
					: listEditorHtml(cfg, rows);
			}

			function setEditorRows(name, rows) {
				if (!state.model) return;
				if (name === 'currencies') state.model.currencies = rows.map(function (r) { return cleanCurrency(r.value); });
				else if (name === 'destinations') state.model.destinations = rows.map(function (r) { return str(r.value); });
				else state.model[name] = rows;
			}

			function editorFieldInput(el, structural) {
				const editorEl = el.closest('[data-editor]');
				const rowEl = el.closest('[data-row]');
				if (!editorEl || !rowEl || !state.model) return;
				const name = editorEl.getAttribute('data-editor');
				const cfg = EDITORS[name];
				if (!cfg) return;
				const index = parseInt(rowEl.getAttribute('data-row'), 10);
				const field = el.getAttribute('data-field');
				const rows = editorRows(name);
				if (isNaN(index) || !rows[index] || !field) return;

				let value = el.value;
				const fieldCfg = (cfg.fields || []).find(function (f) { return f.key === field; });
				if (fieldCfg && fieldCfg.upper) {
					value = cleanCurrency(value);
					if (el.value !== value) el.value = value;
				}
				if (fieldCfg && fieldCfg.number) value = Math.max(0, toInt(value, 0));
				rows[index][field] = value;

				// Keys are auto-slugified from the label when left empty (on blur).
				if (structural && field === 'label' && (name === 'expense_types' || name === 'price_lists')) {
					if (!str(rows[index].key).trim()) {
						rows[index].key = slugify(value);
						const keyInput = qs('[data-field="key"]', rowEl);
						if (keyInput && !keyInput.readOnly) keyInput.value = rows[index].key;
					}
				}
				if (structural && field === 'key') {
					const slug = slugify(value);
					rows[index].key = slug;
					if (el.value !== slug) el.value = slug;
				}
				setEditorRows(name, rows);
				clearFieldError(name);

				if (name === 'sales_channels' || name === 'sales_payment_methods' || name === 'sales_delivery_methods') {
					if (structural) refreshSalesSelects();
				} else if (name === 'currencies') {
					if (structural) refreshCurrencyRegions();
				} else if (name === 'price_lists') {
					const note = qs('[data-row-note="' + index + '"]', editorEl);
					if (note) note.innerHTML = priceListNoteHtml(state.model, rows[index]);
					if (structural) refreshPriceListSelects();
				} else if (name === 'customers_doc_types') {
					if (structural) refreshDocTypeSelect();
				}
				paintDirty();
			}

			function editorAction(btn) {
				const editorEl = btn.closest('[data-editor]');
				if (!editorEl || !state.model) return;
				const name = editorEl.getAttribute('data-editor');
				const cfg = EDITORS[name];
				if (!cfg) return;
				const action = btn.getAttribute('data-editor-action');
				const rowEl = btn.closest('[data-row]');
				const index = rowEl ? parseInt(rowEl.getAttribute('data-row'), 10) : -1;
				const rows = editorRows(name);

				if (action === 'add') {
					const row = {};
					(cfg.fields || []).forEach(function (f) { row[f.key] = f.number ? 0 : ''; });
					rows.push(row);
				} else if (action === 'remove') {
					if (isNaN(index) || !rows[index]) return;
					if (typeof cfg.locked === 'function' && cfg.locked(rows[index], index)) return;
					rows.splice(index, 1);
				} else if (action === 'up') {
					if (isNaN(index) || index <= 0) return;
					const moved = rows.splice(index, 1)[0];
					rows.splice(index - 1, 0, moved);
				} else if (action === 'down') {
					if (isNaN(index) || index >= rows.length - 1) return;
					const moved2 = rows.splice(index, 1)[0];
					rows.splice(index + 1, 0, moved2);
				} else {
					return;
				}

				setEditorRows(name, rows);
				clearFieldError(name);
				paintEditor(name);

				if (name === 'currencies') refreshCurrencyRegions();
				if (name === 'price_lists') refreshPriceListSelects();
				if (name === 'sales_channels' || name === 'sales_payment_methods' || name === 'sales_delivery_methods') refreshSalesSelects();
				if (name === 'customers_doc_types') refreshDocTypeSelect();
				paintDirty();

				const editorAfter = region('editor-' + name);
				if (!editorAfter) return;
				if (action === 'add') {
					const inputs = qsa('.mj-list-editor__row [data-field]', editorAfter);
					const lastRow = qsa('.mj-list-editor__row', editorAfter).pop();
					const first = lastRow ? qs('[data-field]', lastRow) : (inputs.length ? inputs[inputs.length - 1] : null);
					if (first) { try { first.focus(); } catch (e) { /* noop */ } }
				} else {
					const btnAgain = qs('[data-editor-action="' + action + '"]', editorAfter);
					if (btnAgain) { try { btnAgain.focus(); } catch (e) { /* noop */ } }
				}
			}

			/* ---------------- errors ---------------- */

			function clearAllErrors() {
				qsa('.is-invalid', container).forEach(function (el) { el.classList.remove('is-invalid'); });
				qsa('.mj-field__error[data-generated]', container).forEach(function (el) { el.parentNode.removeChild(el); });
				qsa('[data-editor-error]', container).forEach(function (el) { el.innerHTML = ''; });
			}

			function clearFieldError(key) {
				const control = qs('[data-setting="' + MJ.ui.cssEscape(key) + '"]', container);
				if (control) {
					control.classList.remove('is-invalid');
					const field = control.closest('.mj-field');
					if (field) qsa('.mj-field__error[data-generated]', field).forEach(function (el) { el.parentNode.removeChild(el); });
				}
				const box = qs('[data-editor-error="' + MJ.ui.cssEscape(key) + '"]', container);
				if (box) box.innerHTML = '';
			}

			/**
			 * Marks invalid fields (.is-invalid + .mj-field__error) and jumps to the first one.
			 * @param {Object} errors Map settingKey → message.
			 */
			function showErrors(errors) {
				clearAllErrors();
				const keys = Object.keys(errors || {});
				if (!keys.length) return;
				let firstControl = null;
				let firstTab = '';
				keys.forEach(function (key) {
					const message = str(errors[key]) || 'Valor inválido.';
					const control = qs('[data-setting="' + MJ.ui.cssEscape(key) + '"]', container);
					const tab = KEY_TAB[key] || '';
					if (control) {
						control.classList.add('is-invalid');
						const field = control.closest('.mj-field');
						if (field) {
							const err = document.createElement('div');
							err.className = 'mj-field__error';
							err.setAttribute('data-generated', '1');
							err.textContent = message;
							field.appendChild(err);
						}
						if (!firstControl) { firstControl = control; firstTab = tab; }
						return;
					}
					const box = qs('[data-editor-error="' + MJ.ui.cssEscape(key) + '"]', container);
					if (box) {
						box.innerHTML = '<div class="mj-field__error" data-generated="1">' + h(message) + '</div>';
						if (!firstControl && !firstTab) firstTab = tab;
						return;
					}
					if (!firstTab) firstTab = tab;
					MJ.ui.toast(message, 'error');
				});
				if (firstTab && firstTab !== state.tab) activateTab(firstTab);
				if (firstControl) {
					try { firstControl.focus({ preventScroll: false }); } catch (e) { /* noop */ }
				}
			}

			/**
			 * Maps the REST validation payload (`data.errors` / `data.params`) to field errors.
			 * @param {Object} err ApiError.
			 * @returns {boolean} True when at least one field could be marked.
			 */
			function showServerErrors(err) {
				const data = err && err.data ? err.data : null;
				let map = null;
				if (data && isPlainObject(data.errors)) map = data.errors;
				else if (data && isPlainObject(data.params)) map = data.params;
				if (!map || !Object.keys(map).length) return false;
				const errors = {};
				Object.keys(map).forEach(function (key) {
					let message = map[key];
					if (Array.isArray(message)) message = message.join(' ');
					if (message && typeof message === 'object') message = message.message || 'Valor inválido.';
					errors[key] = str(message) || 'Valor inválido.';
				});
				showErrors(errors);
				return true;
			}

			/* ---------------- load / save / reset ---------------- */

			function applyLoaded(res) {
				const raw = res && isPlainObject(res.settings) ? res.settings : res;
				state.model = normalize(raw);
				state.savedJson = fingerprint(state.model);
				if (res && res.app_url) state.appUrl = str(res.app_url);
			}

			function load(showSpinner) {
				if (state.disposed) return Promise.resolve();
				const mine = ++state.seq;
				state.loading = true;
				if (showSpinner) {
					const body = region('body');
					if (body) body.innerHTML = loadingHtml('Cargando configuración…');
				}
				return MJ.api.get('settings').then(function (res) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					applyLoaded(res);
					paint();
				}).catch(function (err) {
					if (state.disposed || mine !== state.seq) return;
					state.loading = false;
					if (err && (err.handled || err.status === 401)) return;
					if (state.model) {
						toastError(err, 'No se pudo recargar la configuración.');
					} else {
						paintError(err);
					}
				});
			}

			function save() {
				if (state.saving || !state.model || state.disposed) return;
				const errors = validate(state.model);
				if (Object.keys(errors).length) {
					showErrors(errors);
					MJ.ui.toast('Revisá los campos marcados antes de guardar.', 'error');
					return;
				}
				clearAllErrors();

				const payload = toPayload(state.model);
				const previousUrl = state.appUrl;
				const btn = qs('[data-action="save"]', container);
				state.saving = true;
				if (btn) MJ.ui.setLoading(btn, true);
				paintDirty();

				MJ.api.put('settings', payload).then(function (res) {
					state.saving = false;
					if (state.disposed) return;
					if (btn) MJ.ui.setLoading(btn, false);

					const saved = res && isPlainObject(res.settings) ? res.settings : (isPlainObject(res) ? res : payload);
					MJ.applySettings(saved);
					state.model = normalize(saved);
					state.savedJson = fingerprint(state.model);

					const newUrl = res && res.app_url ? str(res.app_url) : previousUrl;
					const urlChanged = !!newUrl && !!previousUrl && newUrl !== previousUrl;
					state.appUrl = newUrl || previousUrl;
					state.notice = urlChanged
						? MJ.ui.alert(
							'La dirección de la aplicación cambió. Abrila en <a class="mj-link mj-mono" href="' + h(state.appUrl) + '" rel="noopener">' + h(state.appUrl) + '</a> ' +
							'y actualizá tus favoritos: la dirección anterior deja de funcionar.',
							'warning', { html: true })
						: '';

					paint();
					paintNotice();
					MJ.ui.toast('Configuración guardada.', 'success');
					if (urlChanged) MJ.ui.scrollTop();
				}).catch(function (err) {
					state.saving = false;
					if (state.disposed) return;
					if (btn) MJ.ui.setLoading(btn, false);
					paintDirty();
					if (err && (err.handled || err.status === 401)) return;
					const mapped = showServerErrors(err);
					MJ.ui.toast(errMessage(err, 'No se pudo guardar la configuración.'), 'error');
					if (!mapped && err && err.status === 403) {
						state.notice = MJ.ui.alert('Tu usuario no tiene permiso para cambiar la configuración.', 'danger');
						paintNotice();
					}
				});
			}

			function reset() {
				if (state.saving) return;
				const doReset = function () {
					state.notice = '';
					paintNotice();
					load(true);
				};
				if (!isDirty()) { doReset(); return; }
				MJ.ui.confirm({
					title: 'Restablecer cambios',
					message: 'Se descartan los cambios sin guardar y se vuelven a cargar los valores guardados en el servidor.',
					okLabel: 'Descartar cambios',
					cancelLabel: 'Seguir editando',
					danger: true,
				}).then(function (ok) {
					if (ok && !state.disposed) doReset();
				});
			}

			function copyValue(btn) {
				const value = btn.getAttribute('data-copy');
				if (!value) return;
				MJ.ui.copyText(value).then(function () {
					MJ.ui.toast('Copiado al portapapeles.', 'success');
				}).catch(function () {
					MJ.ui.toast('No se pudo copiar. Copialo a mano.', 'error');
				});
			}

			/* ---------------- events ---------------- */

			function bind() {
				offs.push(MJ.ui.on(container, 'input', '[data-setting]', function (e, el) { writeControl(el, false); }));
				offs.push(MJ.ui.on(container, 'change', '[data-setting]', function (e, el) { writeControl(el, true); }));
				offs.push(MJ.ui.on(container, 'input', '[data-symbol]', function (e, el) { writeSymbol(el); }));
				offs.push(MJ.ui.on(container, 'change', '[data-role]', function (e, el) { writeRole(el); }));
				offs.push(MJ.ui.on(container, 'input', '[data-editor] [data-field]', function (e, el) { editorFieldInput(el, false); }));
				offs.push(MJ.ui.on(container, 'change', '[data-editor] [data-field]', function (e, el) { editorFieldInput(el, true); }));
				offs.push(MJ.ui.on(container, 'click', '[data-editor-action]', function (e, btn) { e.preventDefault(); editorAction(btn); }));

				/*
				 * Permisos. `defaults` guarda el valor tal cual; un rol guarda solo lo que pisa, y
				 * «Heredar» borra la clave — es la diferencia entre "este rol dice que no" y "este
				 * rol no opina", que es justo lo que hace que subir un permiso general llegue a
				 * todos los que nunca lo tocaron.
				 */
				offs.push(MJ.ui.on(container, 'change', '[data-perm-key]', function (e, el) {
					if (!state.model) return;
					const scope = el.getAttribute('data-perm-scope');
					const key = el.getAttribute('data-perm-key');
					const type = el.getAttribute('data-perm-type');
					if (!scope || !key) return;

					const tree = permissionTree(state.model);
					const target = scope === 'defaults' ? tree.defaults : (tree.roles[scope] = tree.roles[scope] || {});

					if (type === 'bool') {
						target[key] = !!el.checked;
					} else if (type === 'enum') {
						target[key] = el.value;
					} else if (type === 'tristate') {
						if (el.value === '') delete target[key];
						else target[key] = el.value === '1';
					} else if (type === 'enum-optional') {
						if (el.value === '__inherit__') delete target[key];
						else target[key] = el.value;
					}

					if (scope !== 'defaults' && !Object.keys(tree.roles[scope]).length) delete tree.roles[scope];

					state.model.portal_permissions = tree;
					clearFieldError('portal_permissions');
					paintDirty();
					paintRegion('perm-roles', permRolesHtml(state.model));
				}));

				// Medios de pago habilitados: el grupo entero es un array de claves.
				offs.push(MJ.ui.on(container, 'change', '[data-perm-method]', function (e, el) {
					if (!state.model) return;
					const group = el.closest('[data-perm-methods]');
					if (!group) return;
					const tree = permissionTree(state.model);
					tree.defaults.payment_methods = qsa('[data-perm-method]', group)
						.filter(function (input) { return input.checked; })
						.map(function (input) { return input.getAttribute('data-perm-method'); });
					state.model.portal_permissions = tree;
					paintDirty();
				}));

				// Listas de estados de pedido: el grupo entero se guarda como array de claves.
				offs.push(MJ.ui.on(container, 'change', '[data-status-key]', function (e, el) {
					const key = el.getAttribute('data-status-key');
					if (!key || !state.model) return;
					const group = el.closest('[data-status-group]');
					if (!group) return;
					state.model[key] = qsa('[data-status-key="' + MJ.ui.cssEscape(key) + '"]', group)
						.filter(function (input) { return input.checked; })
						.map(function (input) { return input.value; });
					clearFieldError(key);
					paintDirty();
				}));

				offs.push(MJ.ui.on(container, 'keydown', '[data-editor] [data-field]', function (e, el) {
					if (e.key !== 'Enter') return;
					e.preventDefault();
					const editorEl = el.closest('[data-editor]');
					const addBtn = editorEl ? qs('[data-editor-action="add"]', editorEl) : null;
					if (addBtn) editorAction(addBtn);
				}));

				offs.push(MJ.ui.on(container, 'click', '[data-action]', function (e, btn) {
					const action = btn.getAttribute('data-action');
					if (action === 'reload') { e.preventDefault(); reset(); }
					else if (action === 'reset') { e.preventDefault(); reset(); }
					else if (action === 'save') { e.preventDefault(); save(); }
					else if (action === 'copy') { e.preventDefault(); copyValue(btn); }
				}));

				// Enter inside a plain field submits the form (native) → save(); the list editors
				// intercept Enter above to add a row instead.
				offs.push(MJ.ui.on(container, 'submit', 'form[data-form]', function (e) { e.preventDefault(); save(); }));

				const onBeforeUnload = function (e) {
					if (state.disposed || state.saving || !isDirty()) return undefined;
					e.preventDefault();
					e.returnValue = '';
					return '';
				};
				window.addEventListener('beforeunload', onBeforeUnload);
				offs.push(function () { window.removeEventListener('beforeunload', onBeforeUnload); });
			}

			bind();
			syncHash();
			load(false);

			return function cleanup() {
				state.disposed = true;
				state.seq++;
				offs.forEach(function (off) { if (typeof off === 'function') off(); });
				if (tabsApi && typeof tabsApi.destroy === 'function') tabsApi.destroy();
				tabsApi = null;
			};
		},
	};
})();
