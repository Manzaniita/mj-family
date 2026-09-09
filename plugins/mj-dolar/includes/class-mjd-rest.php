<?php
/**
 * REST propia (mjd/v1), extensión de la Store API (extensions.mjd en cada
 * producto) y datos para window.MJ del tema MJ Appearance.
 *
 *   GET  /wp-json/mjd/v1/rate            público: TC vigente y formato
 *   POST /wp-json/mjd/v1/rate            admin:   {rate, note?} → guarda y dispara el recálculo
 *   GET  /wp-json/mjd/v1/convert?usd=100 público: pesos redondeados
 *   GET  /wp-json/mjd/v1/status          admin:   estado del recálculo + conteos
 */

defined('ABSPATH') || exit;

class MJD_REST {

	const NS = 'mjd/v1';

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);

		/* Tema MJ Appearance ≥ 3.2: window.MJ.exchangeRate. */
		add_filter('mj_app_config', [__CLASS__, 'app_config']);

		/* Store API: el orden de carga de plugins decide si el hook ya pasó. */
		if (did_action('woocommerce_blocks_loaded')) {
			self::store_api();
		} else {
			add_action('woocommerce_blocks_loaded', [__CLASS__, 'store_api']);
		}
	}

	public static function routes() {
		register_rest_route(self::NS, '/rate', [
			[
				'methods'             => 'GET',
				'callback'            => [__CLASS__, 'get_rate'],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'POST',
				'callback'            => [__CLASS__, 'post_rate'],
				'permission_callback' => [__CLASS__, 'can_manage'],
				'args'                => [
					'rate' => ['required' => true],
					'note' => ['required' => false],
				],
			],
		]);

		register_rest_route(self::NS, '/convert', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'convert'],
			'permission_callback' => '__return_true',
			'args'                => [
				'usd' => ['required' => true],
			],
		]);

		register_rest_route(self::NS, '/status', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'status'],
			'permission_callback' => [__CLASS__, 'can_manage'],
		]);
	}

	public static function can_manage() {
		return current_user_can('manage_options');
	}

	public static function get_rate() {
		return rest_ensure_response(MJD_Rate::payload());
	}

	public static function post_rate(WP_REST_Request $request) {
		$result = MJD_Rate::update($request->get_param('rate'), (string) $request->get_param('note'));
		if (is_wp_error($result)) {
			$result->add_data(['status' => 400]);
			return $result;
		}
		return rest_ensure_response(array_merge(MJD_Rate::payload(), ['sync' => MJD_Sync::state()]));
	}

	public static function convert(WP_REST_Request $request) {
		$usd = MJD_Product::parse_decimal($request->get_param('usd'));
		if ('' === $usd) {
			return new WP_Error('mjd_invalid_usd', 'Ingresá un importe en USD válido.', ['status' => 400]);
		}
		if (!MJD_Rate::is_set()) {
			return new WP_Error('mjd_no_rate', 'No hay tipo de cambio cargado.', ['status' => 404]);
		}
		$ars = MJD_Rate::convert($usd);
		return rest_ensure_response([
			'usd'       => (float) $usd,
			'rate'      => MJD_Rate::get(),
			'amount'    => $ars,
			'formatted' => MJD_Rate::format_ars($ars),
		]);
	}

	public static function status() {
		return rest_ensure_response(MJD_Sync::status());
	}

	/* ------------------------- window.MJ (tema) ------------------------- */

	public static function app_config($config) {
		if (!is_array($config)) {
			$config = [];
		}
		if (!MJD_Rate::is_set()) {
			$config['exchangeRate'] = null;
			return $config;
		}
		$config['exchangeRate'] = array_merge(MJD_Rate::payload(), [
			'showHeader'   => (bool) MJD_Settings::get('show_header'),
			'showUsdPrice' => (string) MJD_Settings::get('show_usd_price'),
		]);
		return $config;
	}

	/* --------------------------- Store API ------------------------------ */

	public static function store_api() {
		if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
			return;
		}
		woocommerce_store_api_register_endpoint_data([
			'endpoint'        => 'product',
			'namespace'       => 'mjd',
			'data_callback'   => [__CLASS__, 'product_data'],
			'schema_callback' => [__CLASS__, 'product_schema'],
			'schema_type'     => ARRAY_A,
		]);
	}

	/**
	 * extensions.mjd de cada producto de la Store API.
	 * Simple: usd / usd_sale. Variable: usd = mínimo entre variaciones,
	 * usd_max = máximo, y el detalle por variación para la ficha.
	 */
	public static function product_data($product) {
		$data = [
			'usd'        => null,
			'usd_sale'   => null,
			'usd_max'    => null,
			'rate'       => MJD_Rate::get(),
			'variations' => [],
		];
		if (!$product instanceof WC_Product) {
			return $data;
		}

		if ($product->is_type('variable')) {
			$min = null;
			$max = null;
			foreach ($product->get_children() as $child_id) {
				$variation = wc_get_product($child_id);
				if (!$variation) {
					continue;
				}
				list($usd, $usd_sale) = MJD_Product::usd_effective($variation);
				if (null === $usd) {
					continue;
				}
				$data['variations'][] = [
					'id'       => (int) $child_id,
					'usd'      => $usd,
					'usd_sale' => $usd_sale,
				];
				$effective = null !== $usd_sale ? $usd_sale : $usd;
				$min       = null === $min ? $effective : min($min, $effective);
				$max       = null === $max ? $effective : max($max, $effective);
			}
			$data['usd']     = $min;
			$data['usd_max'] = $max;
			return $data;
		}

		list($usd, $usd_sale) = MJD_Product::usd_effective($product);
		$data['usd']      = $usd;
		$data['usd_sale'] = $usd_sale;
		return $data;
	}

	public static function product_schema() {
		$number = ['type' => ['number', 'null'], 'readonly' => true, 'context' => ['view', 'edit']];
		return [
			'usd'        => array_merge($number, ['description' => 'Precio USD (mínimo entre variaciones en productos variables).']),
			'usd_sale'   => array_merge($number, ['description' => 'Precio de oferta USD vigente, o null.']),
			'usd_max'    => array_merge($number, ['description' => 'Solo variables: USD máximo entre variaciones.']),
			'rate'       => ['type' => 'number', 'readonly' => true, 'context' => ['view', 'edit'], 'description' => 'Tipo de cambio vigente.'],
			'variations' => [
				'type'        => 'array',
				'readonly'    => true,
				'context'     => ['view', 'edit'],
				'description' => 'USD por variación.',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'id'       => ['type' => 'integer'],
						'usd'      => ['type' => ['number', 'null']],
						'usd_sale' => ['type' => ['number', 'null']],
					],
				],
			],
		];
	}
}
