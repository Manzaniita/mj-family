<?php
/**
 * REST propia (mjpm/v1), extensiones de la Store API y datos para window.MJ.
 *
 *   GET /wp-json/mjpm/v1/methods                          config pública
 *   GET /wp-json/mjpm/v1/price?amount=100000&method=bacs  precio con un método
 *
 * Store API: extensions.mjpm en producto ({eligible}), en cart-item ({eligible})
 * y en el carrito ({method, kind, value, amount, label, currency, usd_total}).
 */

defined('ABSPATH') || exit;

class MJPM_REST {

	const NS = 'mjpm/v1';

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);
		add_filter('mj_app_config', [__CLASS__, 'app_config']);

		if (did_action('woocommerce_blocks_loaded')) {
			self::store_api();
		} else {
			add_action('woocommerce_blocks_loaded', [__CLASS__, 'store_api']);
		}
	}

	public static function routes() {
		register_rest_route(self::NS, '/methods', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'methods'],
			'permission_callback' => '__return_true',
		]);
		register_rest_route(self::NS, '/price', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'price'],
			'permission_callback' => '__return_true',
			'args'                => [
				'amount' => ['required' => true],
				'method' => ['required' => true],
			],
		]);
	}

	public static function methods() {
		return rest_ensure_response(MJPM_Methods::public_payload());
	}

	public static function price(WP_REST_Request $request) {
		$amount = MJPM_Settings::decimal($request->get_param('amount'));
		$id     = sanitize_key((string) $request->get_param('method'));
		if (!MJPM_Methods::gateway($id)) {
			return new WP_Error('mjpm_unknown_method', 'Medio de pago desconocido.', ['status' => 404]);
		}
		$cfg   = MJPM_Methods::config($id);
		$price = MJPM_Methods::price_for($amount, $cfg);
		return rest_ensure_response([
			'method'    => $id,
			'kind'      => $cfg['kind'],
			'value'     => (float) $cfg['value'],
			'base'      => $amount,
			'price'     => $price,
			'formatted' => MJPM_Methods::money($price),
		]);
	}

	/* -------------------------- window.MJ (tema) ------------------------- */

	public static function app_config($config) {
		if (!is_array($config)) {
			$config = [];
		}
		$config['paymentPricing'] = MJPM_Methods::public_payload();
		return $config;
	}

	/* ----------------------------- Store API ----------------------------- */

	public static function store_api() {
		if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
			return;
		}
		woocommerce_store_api_register_endpoint_data([
			'endpoint'        => 'product',
			'namespace'       => 'mjpm',
			'data_callback'   => [__CLASS__, 'product_data'],
			'schema_callback' => [__CLASS__, 'eligible_schema'],
			'schema_type'     => ARRAY_A,
		]);
		woocommerce_store_api_register_endpoint_data([
			'endpoint'        => 'cart-item',
			'namespace'       => 'mjpm',
			'data_callback'   => [__CLASS__, 'cart_item_data'],
			'schema_callback' => [__CLASS__, 'eligible_schema'],
			'schema_type'     => ARRAY_A,
		]);
		woocommerce_store_api_register_endpoint_data([
			'endpoint'        => 'cart',
			'namespace'       => 'mjpm',
			'data_callback'   => [__CLASS__, 'cart_data'],
			'schema_callback' => [__CLASS__, 'cart_schema'],
			'schema_type'     => ARRAY_A,
		]);
	}

	public static function product_data($product) {
		return ['eligible' => MJPM_Methods::product_eligible($product)];
	}

	public static function cart_item_data($cart_item) {
		$product = is_array($cart_item) && isset($cart_item['data']) ? $cart_item['data'] : null;
		return ['eligible' => MJPM_Methods::product_eligible($product)];
	}

	public static function cart_data() {
		$out = [
			'method'    => '',
			'kind'      => 'none',
			'mode'      => 'percent',
			'value'     => 0,
			'amount'    => 0,
			'label'     => '',
			'currency'  => get_woocommerce_currency(),
			'usd_total' => null,
			'rate'      => null,
		];
		$id = MJPM_Cart::effective_method_id();
		if ('' === $id || !function_exists('WC') || !WC()->cart) {
			return $out;
		}
		$calc = MJPM_Methods::calculate_for_cart(WC()->cart, $id);
		$out['method'] = $id;
		$out['kind']   = $calc['kind'];
		$out['mode']   = $calc['mode'];
		$out['value']  = (float) $calc['value'];
		$out['amount'] = (float) $calc['amount'];
		$out['label']  = (string) $calc['label'];

		$custom = MJPM_Settings::custom_by_gateway($id);
		if ($custom && 'USD' === $custom['currency'] && class_exists('MJD_Rate') && MJD_Rate::is_set()) {
			$rate             = MJD_Rate::get();
			$out['currency']  = 'USD';
			$out['rate']      = $rate;
			$out['usd_total'] = round((float) WC()->cart->get_total('edit') / $rate, 2);
		}
		return $out;
	}

	public static function eligible_schema() {
		return [
			'eligible' => [
				'description' => 'Si el producto participa de descuentos/recargos por medio de pago.',
				'type'        => 'boolean',
				'readonly'    => true,
				'context'     => ['view', 'edit'],
			],
		];
	}

	public static function cart_schema() {
		$ro = ['readonly' => true, 'context' => ['view', 'edit']];
		return [
			'method'    => array_merge($ro, ['type' => 'string', 'description' => 'Medio de pago vigente en la sesión.']),
			'kind'      => array_merge($ro, ['type' => 'string']),
			'mode'      => array_merge($ro, ['type' => 'string']),
			'value'     => array_merge($ro, ['type' => 'number']),
			'amount'    => array_merge($ro, ['type' => 'number', 'description' => 'Ajuste aplicado (negativo = descuento).']),
			'label'     => array_merge($ro, ['type' => 'string']),
			'currency'  => array_merge($ro, ['type' => 'string']),
			'usd_total' => array_merge($ro, ['type' => ['number', 'null']]),
			'rate'      => array_merge($ro, ['type' => ['number', 'null']]),
		];
	}
}
