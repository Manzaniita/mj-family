<?php
/**
 * REST propia (mje/v1), extensión de la Store API y datos para window.MJ.
 *
 *   GET  /wp-json/mje/v1/config                         config pública del envío
 *   GET  /wp-json/mje/v1/provincias                     provincias del país base
 *   GET  /wp-json/mje/v1/geo/cp?cp=1425                 provincia y localidades de un CP
 *   GET  /wp-json/mje/v1/geo/localidades?q=vil&prov=X   autocompletado de localidad
 *   GET  /wp-json/mje/v1/sucursales?metodo=sucursal     sucursales de ese método
 *   POST /wp-json/mje/v1/cotizar                        cuánto sale a un destino
 *   GET  /wp-json/mje/v1/seguimiento?pedido=&clave=     seguimiento de un pedido
 *
 * Store API: extensions.mje en el carrito, con el método elegido, la sucursal,
 * los datos cargados y lo que falta para poder cerrar la compra.
 */

defined('ABSPATH') || exit;

class MJE_REST {

	const NS = 'mje/v1';

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
		$public = ['permission_callback' => '__return_true'];

		register_rest_route(self::NS, '/config', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'config'],
		]));

		register_rest_route(self::NS, '/provincias', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'provinces'],
		]));

		register_rest_route(self::NS, '/geo/cp', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'geo_cp'],
			'args'     => ['cp' => ['required' => true]],
		]));

		register_rest_route(self::NS, '/geo/localidades', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'geo_cities'],
			'args'     => ['q' => ['required' => true]],
		]));

		register_rest_route(self::NS, '/sucursales', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'branches'],
		]));

		register_rest_route(self::NS, '/cotizar', array_merge($public, [
			'methods'  => 'POST',
			'callback' => [__CLASS__, 'quote'],
		]));

		register_rest_route(self::NS, '/seguimiento', array_merge($public, [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'tracking'],
			'args'     => ['pedido' => ['required' => true]],
		]));
	}

	/* ------------------------------ Endpoints ---------------------------- */

	public static function config() {
		return rest_ensure_response(MJE_Methods::public_payload());
	}

	public static function provinces() {
		$states = MJE_Geo::states();
		if (!$states) {
			$states = MJE_Geo::georef_states();
		}
		$out = [];
		foreach ($states as $code => $name) {
			$out[] = ['code' => (string) $code, 'name' => (string) $name];
		}
		return rest_ensure_response($out);
	}

	/**
	 * Qué hay en un código postal.
	 *
	 * Devuelve siempre la misma forma, incluso cuando no se pudo resolver: la
	 * tienda necesita saber la diferencia entre "CP mal escrito" y "CP válido
	 * que todavía no conocemos" para decidir si pide la localidad a mano.
	 */
	public static function geo_cp(WP_REST_Request $request) {
		$cp   = (string) $request->get_param('cp');
		$data = MJE_Geo::resolve_cp($cp);
		return rest_ensure_response([
			'cp'          => $data['postcode'],
			'valido'      => (bool) $data['valid'],
			'encontrado'  => (bool) $data['found'],
			'provincia'   => $data['state'],
			'provinciaId' => $data['state_code'],
			'localidades' => array_map(function ($row) {
				return ['nombre' => $row['city'], 'provincia' => $row['state'], 'provinciaId' => $row['state_code']];
			}, $data['cities']),
			'mensaje'     => $data['valid'] ? '' : (string) MJE_Settings::get('cp_invalid'),
		]);
	}

	public static function geo_cities(WP_REST_Request $request) {
		$rows = MJE_Geo::cities((string) $request->get_param('q'), (string) $request->get_param('prov'));
		return rest_ensure_response(array_map(function ($row) {
			return [
				'nombre'      => $row['city'],
				'provincia'   => $row['state'],
				'provinciaId' => $row['state_code'],
				'cp'          => $row['postcode'],
			];
		}, $rows));
	}

	/** Sucursales de un método (o de un correo), para el selector del checkout. */
	public static function branches(WP_REST_Request $request) {
		$slug    = sanitize_key((string) $request->get_param('metodo'));
		$carrier = sanitize_key((string) $request->get_param('correo'));
		$cp      = MJE_Geo::normalize_cp((string) $request->get_param('cp'));

		$native = '';
		if ('' !== $slug) {
			$def = MJE_Methods::get($slug);
			if (!$def) {
				return new WP_Error('mje_no_method', 'Ese método de envío no existe.', ['status' => 404]);
			}
			$carrier = (string) $def['carrier'];
			$native  = 'nativo' === (string) $def['type'] ? (string) $def['native_plugin'] : '';
		}
		if ('' === $carrier && '' === $native) {
			return rest_ensure_response([]);
		}

		/* Un correo con plugin propio trae sus sucursales por el puente; el resto, por su adaptador. */
		if ('' !== $native) {
			$rows = MJE_Native::branches($native, $cp);
			if (!$rows && '' !== $cp) {
				$rows = MJE_Native::branches($native, '');
			}
		} else {
			$rows = MJE_Carriers::branches($carrier, $cp);
			if (!$rows && '' !== $cp) {
				/* Sin sucursales en ese CP, se ofrecen todas las que haya cargadas. */
				$rows = MJE_Carriers::branches($carrier, '');
			}
		}

		/* La más cómoda primero: por distancia si el correo publica coordenadas, si no por CP y localidad. */
		$city = (string) $request->get_param('localidad');
		$rows = MJE_Carriers::sort_branches($rows, $cp, $city, $request->get_param('lat'), $request->get_param('lon'));

		return rest_ensure_response(array_map(function ($row) {
			return [
				'codigo'    => $row['code'],
				'nombre'    => $row['name'],
				'direccion' => $row['address'],
				'localidad' => $row['city'],
				'provincia' => $row['state'],
				'cp'        => $row['postcode'],
				'horarios'  => $row['hours'],
				'distancia' => isset($row['distance']) ? $row['distance'] : '',
			];
		}, $rows));
	}

	/**
	 * Cuánto sale enviar a un destino.
	 *
	 * Sirve para preguntar el costo antes de tener un carrito armado (por
	 * ejemplo, en la ficha de un producto). Con el carrito cargado, la respuesta
	 * usa su contenido real; sin carrito, se cotiza con el peso y el monto que
	 * manden.
	 */
	public static function quote(WP_REST_Request $request) {
		/*
		 * Esta puerta es pública y dispara llamadas salientes a la API del
		 * correo: sin freno, cualquiera puede hacer que la tienda gaste
		 * cotizaciones toda la tarde. Mismo freno que usa MJ Medios de Pago
		 * para la subida de comprobantes.
		 */
		$limited = self::rate_limited();
		if (is_wp_error($limited)) {
			return $limited;
		}

		$cp    = MJE_Geo::normalize_cp((string) $request->get_param('cp'));
		$state = (string) $request->get_param('provincia');
		$city  = (string) $request->get_param('localidad');

		if (!MJE_Geo::cp_valid($cp)) {
			return new WP_Error('mje_cp', (string) MJE_Settings::get('cp_invalid'), ['status' => 400]);
		}

		$package = self::package_for($cp, $state, $city, $request);
		$out     = [];
		foreach (MJE_Methods::available($package) as $slug => $def) {
			$quote = MJE_Rates::quote($def, $package);
			if (null === $quote['cost']) {
				continue;
			}
			$out[] = [
				'metodo'      => $slug,
				'titulo'      => MJE_Methods::name($def),
				'descripcion' => (string) $def['description'],
				'costo'       => (float) $quote['cost'],
				'formateado'  => $quote['coordinate'] ? (string) MJE_Settings::get('coordinate_text') : MJE_Settings::money((float) $quote['cost']),
				'gratis'      => 0.0 === (float) $quote['cost'] && !$quote['coordinate'],
				'aCoordinar'  => (bool) $quote['coordinate'],
				'plazo'       => MJE_Methods::eta(array_merge($def, ['days_min' => $quote['days_min'], 'days_max' => $quote['days_max']])),
				'destino'     => (string) $def['destination'],
			];
		}
		return rest_ensure_response([
			'cp'       => $cp,
			'opciones' => $out,
			'mensaje'  => $out ? '' : (string) MJE_Settings::get('none_text'),
		]);
	}

	/** Un paquete armado a mano, con el carrito si hay y con lo que manden si no. */
	private static function package_for($cp, $state, $city, WP_REST_Request $request) {
		$contents      = [];
		$contents_cost = 0.0;

		if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
			$contents      = WC()->cart->get_cart();
			$contents_cost = (float) WC()->cart->get_displayed_subtotal();
		}
		if (!$contents) {
			$product_id = absint($request->get_param('producto'));
			$qty        = max(1, absint($request->get_param('cantidad')));
			$product    = $product_id ? wc_get_product($product_id) : null;
			if ($product instanceof WC_Product) {
				$contents['mje'] = [
					'key'        => 'mje',
					'data'       => $product,
					'quantity'   => $qty,
					'line_total' => (float) $product->get_price() * $qty,
				];
				$contents_cost   = (float) $product->get_price() * $qty;
			}
		}
		if (null !== $request->get_param('monto')) {
			$contents_cost = MJE_Settings::decimal($request->get_param('monto'));
		}

		return [
			'contents'      => $contents,
			'contents_cost' => $contents_cost,
			'destination'   => [
				'country'  => MJE_Geo::country(),
				'state'    => '' !== $state ? MJE_Geo::state_code($state) : '',
				'city'     => $city,
				'postcode' => $cp,
				'address'  => '',
			],
		];
	}

	/**
	 * Seguimiento de un pedido.
	 *
	 * Con sesión alcanza con que el pedido sea del usuario; sin sesión hace
	 * falta la clave del pedido, que es la misma prueba de propiedad que usa la
	 * página de gracias de WooCommerce.
	 */
	public static function tracking(WP_REST_Request $request) {
		$order_id = absint($request->get_param('pedido'));
		$key      = (string) $request->get_param('clave');
		$order    = $order_id ? wc_get_order($order_id) : null;
		if (!$order instanceof WC_Order) {
			return new WP_Error('mje_no_order', 'Ese pedido no existe.', ['status' => 404]);
		}
		$own = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		if (!$own && ('' === $key || !hash_equals((string) $order->get_order_key(), $key))) {
			return new WP_Error('mje_forbidden', 'No podemos mostrar ese pedido.', ['status' => 403]);
		}

		$shipment = MJE_Orders::shipment($order);
		if (!$shipment) {
			return rest_ensure_response(['codigo' => '', 'link' => '', 'estado' => '']);
		}
		$status = MJE_Tracking::status($order);
		return rest_ensure_response([
			'metodo'   => $shipment['title'],
			'codigo'   => $shipment['tracking'],
			'link'     => $shipment['tracking_url'],
			'desde'    => $shipment['shipped_at'],
			'plazo'    => $shipment['eta'],
			'estado'   => isset($status['status']) ? (string) $status['status'] : '',
		]);
	}

	/* -------------------------- window.MJ (tema) ------------------------- */

	public static function app_config($config) {
		if (!is_array($config)) {
			$config = [];
		}
		$config['shipping'] = MJE_Methods::public_payload();
		return $config;
	}

	/**
	 * Freno por IP para las cotizaciones sueltas.
	 *
	 * @return WP_Error|null
	 */
	private static function rate_limited() {
		/**
		 * Cuántas cotizaciones puede pedir una misma IP por hora.
		 *
		 * @param int $max Tope. 0 = sin freno.
		 */
		$max = (int) apply_filters('mje_quote_rate_limit', 60);
		if ($max <= 0) {
			return null;
		}
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if ('' === $ip) {
			return null;
		}
		$key   = 'mje_rl_' . md5($ip);
		$count = (int) get_transient($key);
		if ($count >= $max) {
			return new WP_Error('mje_rate_limited', 'Demasiadas consultas seguidas. Esperá un rato y probá de nuevo.', ['status' => 429]);
		}
		set_transient($key, $count + 1, HOUR_IN_SECONDS);
		return null;
	}

	/* ----------------------------- Store API ----------------------------- */

	public static function store_api() {
		if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
			return;
		}
		woocommerce_store_api_register_endpoint_data([
			'endpoint'        => 'cart',
			'namespace'       => 'mje',
			'data_callback'   => [__CLASS__, 'cart_data'],
			'schema_callback' => [__CLASS__, 'cart_schema'],
			'schema_type'     => ARRAY_A,
		]);
	}

	/** Lo que la tienda necesita del envío en cada respuesta del carrito. */
	public static function cart_data() {
		$data = MJE_Cart::session_data();
		$def  = MJE_Cart::chosen_method();
		return [
			'method'      => $def ? (string) $def['slug'] : (string) $data['method'],
			'destination' => $def ? (string) $def['destination'] : '',
			'branch'      => (string) $data['branch'],
			'city'        => (string) $data['city'],
			'fields'      => is_array($data['fields']) ? array_map('strval', $data['fields']) : [],
			'problems'    => array_values(MJE_Cart::problems()),
			'freeFrom'    => (float) MJE_Rates::free_threshold(),
			'rates'       => MJE_Rates::storefront_rates(),
		];
	}

	public static function cart_schema() {
		return [
			'method'      => ['description' => 'Método de envío elegido.', 'type' => 'string', 'readonly' => true],
			'destination' => ['description' => 'A dónde entrega ese método.', 'type' => 'string', 'readonly' => true],
			'branch'      => ['description' => 'Sucursal elegida.', 'type' => 'string', 'readonly' => true],
			'city'        => ['description' => 'Localidad del envío.', 'type' => 'string', 'readonly' => true],
			'fields'      => ['description' => 'Datos cargados para el envío.', 'type' => 'object', 'readonly' => true],
			'problems'    => ['description' => 'Qué falta para poder cerrar la compra.', 'type' => 'array', 'readonly' => true],
			'freeFrom'    => ['description' => 'Monto desde el que el envío es gratis (0 = no aplica).', 'type' => 'number', 'readonly' => true],
			'rates'       => ['description' => 'Lo que la tienda dibuja de cada tarifa, por id de tarifa.', 'type' => 'object', 'readonly' => true],
		];
	}
}
