<?php
/**
 * El cálculo: peso del paquete, precio de cada método y las tarifas que ve el
 * carrito.
 *
 * Las tarifas entran por el filtro woocommerce_package_rates, no como método de
 * una zona de envío. Eso es a propósito: así no hay que configurar ninguna zona
 * (las condiciones ya están en cada método) y las tarifas valen igual en la
 * tienda React por la Store API, en el checkout clásico, en los emails y en los
 * informes de WooCommerce.
 *
 * WooCommerce guarda las tarifas calculadas en la sesión con un hash del
 * paquete. Por eso el paquete se firma con lo que el cliente eligió (sucursal,
 * medio de pago): sin eso, cambiar de sucursal no recalcularía nada.
 */

defined('ABSPATH') || exit;

class MJE_Rates {

	/** method_id de todas las tarifas de este plugin. */
	const METHOD_ID = 'mje';

	public static function init() {
		add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'sign_packages'], 20, 1);
		add_filter('woocommerce_package_rates', [__CLASS__, 'rates'], 20, 2);
		add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'filter_gateways'], 20, 1);

		/* La barra de envío gratis del tema pregunta por acá. */
		add_filter('mj_free_shipping_threshold', [__CLASS__, 'free_threshold_filter'], 20, 1);
	}

	/* ------------------------------ Paquetes ----------------------------- */

	/**
	 * Firma el paquete con lo que el cliente eligió.
	 *
	 * Lo que se agrega no se usa para calcular: está para que cambie el hash con
	 * el que WooCommerce guarda las tarifas, y una sucursal o un medio de pago
	 * distinto obliguen a recalcular.
	 */
	public static function sign_packages($packages) {
		if (!is_array($packages)) {
			return $packages;
		}
		$session = MJE_Cart::session_data();
		$stamp   = [
			'method'  => isset($session['method']) ? (string) $session['method'] : '',
			'branch'  => isset($session['branch']) ? (string) $session['branch'] : '',
			'city'    => isset($session['city']) ? (string) $session['city'] : '',
			'gateway' => MJE_Cart::chosen_gateway(),
		];
		foreach ($packages as $index => $package) {
			$packages[$index]['mje'] = $stamp;
		}
		return $packages;
	}

	/** Subtotal de los productos del paquete (sin envío ni impuestos). */
	public static function package_subtotal(array $package) {
		if (isset($package['contents_cost'])) {
			return (float) $package['contents_cost'];
		}
		$total = 0.0;
		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$total += isset($item['line_total']) ? (float) $item['line_total'] : 0.0;
		}
		return $total;
	}

	/**
	 * Peso de un producto para cotizar.
	 *
	 * Prioridad: el peso cargado en WooCommerce, el peso por defecto del
	 * producto (campo de MJ Envíos), el de su categoría y, al final, el general.
	 * Así una tienda que no carga pesos igual cotiza, y una que los carga usa los
	 * de verdad.
	 */
	public static function product_weight($product) {
		if (!$product instanceof WC_Product) {
			return (float) MJE_Settings::get('weight_default');
		}
		$weight = (float) MJE_Settings::decimal($product->get_weight());
		if ($weight > 0) {
			return $weight;
		}
		$own = (float) MJE_Settings::decimal($product->get_meta('_mje_weight'));
		if ($own > 0) {
			return $own;
		}
		$parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ($product->is_type('variation')) {
			$parent = wc_get_product($parent_id);
			if ($parent instanceof WC_Product) {
				$inherited = (float) MJE_Settings::decimal($parent->get_weight());
				if ($inherited <= 0) {
					$inherited = (float) MJE_Settings::decimal($parent->get_meta('_mje_weight'));
				}
				if ($inherited > 0) {
					return $inherited;
				}
			}
		}
		foreach (wc_get_product_term_ids($parent_id, 'product_cat') as $term_id) {
			$term_weight = (float) MJE_Settings::decimal(get_term_meta($term_id, 'mje_weight', true));
			if ($term_weight > 0) {
				return $term_weight;
			}
		}
		return (float) MJE_Settings::get('weight_default');
	}

	/** Peso total del paquete, con el embalaje, el piso y el peso volumétrico. */
	public static function package_weight(array $package) {
		$weight = 0.0;
		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$product = isset($item['data']) ? $item['data'] : null;
			$qty     = max(0, (int) (isset($item['quantity']) ? $item['quantity'] : 0));
			$weight += self::product_weight($product) * $qty;
		}
		$weight += (float) MJE_Settings::get('weight_extra');

		$divisor = (float) MJE_Settings::get('volumetric');
		if ($divisor > 0) {
			$dims       = self::package_dims($package);
			$volumetric = ($dims['length'] * $dims['width'] * $dims['height']) / $divisor;
			$weight     = max($weight, $volumetric);
		}

		$min = (float) MJE_Settings::get('weight_min');
		if ($min > 0) {
			$weight = max($weight, $min);
		}
		return (float) apply_filters('mje_package_weight', round($weight, 3), $package);
	}

	/** Medidas del bulto: las del producto más grande, o las generales. */
	public static function package_dims(array $package) {
		$length = 0.0;
		$width  = 0.0;
		$height = 0.0;
		$stack  = 'alto' === (string) MJE_Settings::get('dim_stack');

		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$product = isset($item['data']) ? $item['data'] : null;
			if (!$product instanceof WC_Product) {
				continue;
			}
			$qty = max(1, (int) (isset($item['quantity']) ? $item['quantity'] : 1));
			$l   = (float) MJE_Settings::decimal($product->get_length());
			$w   = (float) MJE_Settings::decimal($product->get_width());
			$h   = (float) MJE_Settings::decimal($product->get_height());
			if ($l <= 0 && $w <= 0 && $h <= 0) {
				continue;
			}
			$length = max($length, $l);
			$width  = max($width, $w);
			$height = $stack ? $height + ($h * $qty) : max($height, $h);
		}

		if ($length <= 0) {
			$length = (float) MJE_Settings::get('dim_length');
		}
		if ($width <= 0) {
			$width = (float) MJE_Settings::get('dim_width');
		}
		if ($height <= 0) {
			$height = (float) MJE_Settings::get('dim_height');
		}
		return ['length' => $length, 'width' => $width, 'height' => $height];
	}

	/** En cuántos bultos se parte el paquete (por el techo de peso por bulto). */
	public static function package_count(array $package) {
		$max = (float) MJE_Settings::get('weight_max');
		if ($max <= 0) {
			return 1;
		}
		$weight = self::package_weight($package);
		return max(1, (int) ceil($weight / $max));
	}

	/** Valor declarado según el ajuste. */
	public static function declared_value(array $package) {
		$mode     = (string) MJE_Settings::get('declared_value');
		$subtotal = self::package_subtotal($package);
		if ('none' === $mode) {
			return 0.0;
		}
		if ('percent' === $mode) {
			return round($subtotal * ((float) MJE_Settings::get('declared_pct') / 100), 2);
		}
		return round($subtotal, 2);
	}

	/** El envío tal como lo recibe un correo. */
	public static function shipment(array $def, array $package) {
		$destination = isset($package['destination']) ? (array) $package['destination'] : [];
		$session     = MJE_Cart::session_data();
		$dims        = self::package_dims($package);
		$items       = 0;
		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$items += (int) (isset($item['quantity']) ? $item['quantity'] : 0);
		}

		$city = isset($destination['city']) ? (string) $destination['city'] : '';
		if ('' === $city && !empty($session['city'])) {
			$city = (string) $session['city'];
		}

		return [
			'origin'   => MJE_Settings::origin(),
			'dest'     => [
				'postcode' => MJE_Geo::normalize_cp(isset($destination['postcode']) ? $destination['postcode'] : ''),
				'city'     => $city,
				'state'    => MJE_Geo::state_name(isset($destination['state']) ? $destination['state'] : '', isset($destination['country']) ? $destination['country'] : ''),
				'country'  => isset($destination['country']) ? (string) $destination['country'] : '',
				'branch'   => isset($session['branch']) ? (string) $session['branch'] : '',
			],
			'weight'   => self::package_weight($package),
			'packages' => self::package_count($package),
			'length'   => (int) round($dims['length']),
			'width'    => (int) round($dims['width']),
			'height'   => (int) round($dims['height']),
			'value'    => self::declared_value($package),
			'service'  => '' !== (string) $def['service'] ? (string) $def['service'] : (string) $def['destination'],
			'items'    => $items,
		];
	}

	/* ------------------------------- Precio ------------------------------ */

	/** Un ajuste del método que puede estar en "heredar" (-1). */
	private static function inherited(array $def, $key, $general_key) {
		$value = isset($def[$key]) ? (float) $def[$key] : -1;
		return $value < 0 ? (float) MJE_Settings::get($general_key) : $value;
	}

	/** Precio de la tabla propia del método para este destino y peso. */
	public static function table_price(array $def, array $package) {
		$destination = isset($package['destination']) ? (array) $package['destination'] : [];
		$cp          = MJE_Geo::normalize_cp(isset($destination['postcode']) ? $destination['postcode'] : '');
		$state       = isset($destination['state']) ? (string) $destination['state'] : '';
		$state_name  = MJE_Geo::state_name($state, isset($destination['country']) ? $destination['country'] : '');
		$city        = isset($destination['city']) ? (string) $destination['city'] : '';
		$weight      = self::package_weight($package);

		foreach ((array) $def['table'] as $row) {
			$scope = (string) $row['scope'];
			$match = (string) $row['match'];
			if ('cp' === $scope && !MJE_Methods::cp_matches($cp, $match)) {
				continue;
			}
			if ('provincia' === $scope) {
				$names = array_map(['MJE_Settings', 'key_of'], MJE_Settings::to_array($match));
				if (!in_array(MJE_Settings::key_of($state), $names, true) && !in_array(MJE_Settings::key_of($state_name), $names, true)) {
					continue;
				}
			}
			if ('localidad' === $scope) {
				$names = array_map(['MJE_Settings', 'key_of'], MJE_Settings::to_array($match));
				if (!in_array(MJE_Settings::key_of($city), $names, true)) {
					continue;
				}
			}

			$max = (float) $row['weight_max'];
			if ($max > 0 && $weight > $max) {
				/* Pasa el tope: solo sirve si la fila cobra el kilo extra. */
				if ((float) $row['per_kg'] <= 0) {
					continue;
				}
				return (float) $row['price'] + ((float) $row['per_kg'] * ceil($weight - $max));
			}
			return (float) $row['price'];
		}
		return null; // Ninguna fila cubre este destino.
	}

	/** ¿Este método queda gratis para este carrito? */
	public static function free_applies(array $def, array $package) {
		if (empty($def['free_allowed'])) {
			return false;
		}
		$allowed = MJE_Settings::to_array(MJE_Settings::get('free_methods'));
		if ($allowed && !in_array((string) $def['slug'], $allowed, true)) {
			return false;
		}

		$states = MJE_Settings::to_array(MJE_Settings::get('free_states'));
		if ($states) {
			$destination = isset($package['destination']) ? (array) $package['destination'] : [];
			$state       = isset($destination['state']) ? (string) $destination['state'] : '';
			$state_name  = MJE_Geo::state_name($state, isset($destination['country']) ? $destination['country'] : '');
			$keys        = array_map(['MJE_Settings', 'key_of'], $states);
			if (!in_array(MJE_Settings::key_of($state), $keys, true) && !in_array(MJE_Settings::key_of($state_name), $keys, true)) {
				return false;
			}
		}

		$min = isset($def['free_min']) ? (float) $def['free_min'] : -1;
		if ($min < 0) {
			$min = MJE_Settings::get('free_enabled') ? (float) MJE_Settings::get('free_min') : 0.0;
		}
		if ($min <= 0) {
			return false;
		}
		return self::package_subtotal($package) >= $min;
	}

	/**
	 * El umbral de envío gratis que se anuncia en la tienda.
	 *
	 * Es el más bajo de los que puedan aplicar: el general, o el de un método
	 * que lo pise. 0 = no se anuncia nada.
	 */
	public static function free_threshold() {
		$candidates = [];
		if (MJE_Settings::get('free_enabled') && (float) MJE_Settings::get('free_min') > 0) {
			$candidates[] = (float) MJE_Settings::get('free_min');
		}
		foreach (MJE_Methods::enabled() as $def) {
			if (empty($def['free_allowed'])) {
				continue;
			}
			$min = (float) $def['free_min'];
			if ($min > 0) {
				$candidates[] = $min;
			}
		}
		return $candidates ? min($candidates) : 0.0;
	}

	/** El tema pregunta el umbral por este filtro; el plugin manda si tiene uno. */
	public static function free_threshold_filter($value) {
		if (!MJE_Settings::get('enabled')) {
			return $value;
		}
		$own = self::free_threshold();
		return $own > 0 ? $own : $value;
	}

	/**
	 * Precio de un método para un paquete.
	 *
	 * → ['cost','source','error','coordinate','days_min','days_max']
	 *   cost null = no se puede ofrecer.
	 */
	public static function quote(array $def, array $package) {
		$out = [
			'cost'        => null,
			'base'        => null, // Lo que cobra el correo, antes del margen: es el costo real.
			'source'      => (string) $def['type'],
			'error'       => '',
			'coordinate'  => false,
			'days_min'    => (int) $def['days_min'],
			'days_max'    => (int) $def['days_max'],
			'native_rate' => '', // Id de la tarifa del plugin del correo que se envolvió.
		];

		/* Gratis por monto: no hace falta preguntarle el precio a nadie. */
		if (self::free_applies($def, $package)) {
			$out['cost']   = 0.0;
			$out['source'] = 'gratis';
			return (array) apply_filters('mje_quote', $out, $def, $package);
		}

		switch ((string) $def['type']) {
			case 'gratis':
				$out['cost'] = 0.0;
				break;

			case 'retiro':
			case 'fijo':
				$out['cost'] = (float) $def['price'];
				break;

			case 'coordinar':
				$out['cost']       = 0.0;
				$out['coordinate'] = true;
				return (array) apply_filters('mje_quote', $out, $def, $package);

			case 'tabla':
				$price = self::table_price($def, $package);
				if (null === $price) {
					$out['error'] = 'Ninguna fila de la tabla cubre ese destino.';
					return (array) apply_filters('mje_quote', $out, $def, $package);
				}
				$out['cost'] = $price;
				break;

			case 'nativo':
				/*
				 * El precio lo puso el plugin del correo cuando WooCommerce
				 * calculó el carrito: acá no se llama a ninguna API, se toma esa
				 * tarifa. Si no está (el correo no cotizó ese destino, o su
				 * método no quedó en ninguna zona), vale la misma red que para
				 * un correo propio que no responde.
				 */
				$native = MJE_Native::find((string) $def['native_plugin'], (string) $def['native_service']);
				if (!$native) {
					$error = new WP_Error('mje_native_sin_tarifa', sprintf('%s no cotizó este destino.', MJE_Native::label_of((string) $def['native_plugin'])));
					return (array) apply_filters('mje_quote', self::on_carrier_error($def, $package, $error, $out), $def, $package);
				}
				$out['cost']        = (float) $native['cost'];
				$out['base']        = (float) $native['cost'];
				$out['source']      = 'nativo';
				$out['native_rate'] = (string) $native['rate_id'];
				break;

			case 'correo':
				$result = MJE_Carriers::quote((string) $def['carrier'], self::shipment($def, $package));
				if (is_wp_error($result)) {
					return (array) apply_filters('mje_quote', self::on_carrier_error($def, $package, $result, $out), $def, $package);
				}
				$out['cost']     = (float) $result['cost'];
				$out['base']     = (float) $result['cost'];
				$out['source']   = 'correo';
				$out['days_min'] = (int) $result['days_min'] > 0 ? (int) $result['days_min'] : $out['days_min'];
				$out['days_max'] = (int) $result['days_max'] > 0 ? (int) $result['days_max'] : $out['days_max'];
				break;
		}

		if (null !== $out['cost']) {
			$out['cost'] = self::apply_extras((float) $out['cost'], $def, $package);
		}
		return (array) apply_filters('mje_quote', $out, $def, $package);
	}

	/** Qué hacer cuando el correo no cotiza: lo que diga el ajuste general. */
	private static function on_carrier_error(array $def, array $package, WP_Error $error, array $out) {
		$out['error'] = $error->get_error_message();
		switch ((string) MJE_Settings::get('on_error')) {
			case 'coordinar':
				$out['cost']       = 0.0;
				$out['coordinate'] = true;
				$out['source']     = 'coordinar';
				return $out;

			case 'tabla':
				$price = self::table_price($def, $package);
				if (null !== $price) {
					$out['cost']   = self::apply_extras((float) $price, $def, $package);
					$out['source'] = 'tabla';
					return $out;
				}
				/* Sin tabla que lo cubra, se oculta: mejor que un precio inventado. */
				return $out;

			default:
				return $out; // ocultar
		}
	}

	/** Margen, seguro, piso y redondeo sobre el costo. */
	public static function apply_extras($cost, array $def, array $package) {
		$cost += $cost * (self::inherited($def, 'markup_pct', 'markup_pct') / 100);
		$cost += self::inherited($def, 'markup_fixed', 'markup_fixed');

		$insurance = self::inherited($def, 'insurance_pct', 'insurance_pct');
		if ($insurance > 0) {
			$cost += self::declared_value($package) * ($insurance / 100);
		}

		$min = self::inherited($def, 'min_cost', 'min_cost');
		if ($min > 0) {
			$cost = max($cost, $min);
		}
		return MJE_Settings::round_price(max(0, $cost));
	}

	/* ------------------------------- Tarifas ----------------------------- */

	/** Id de tarifa de un método (el que guarda WooCommerce en la sesión). */
	public static function rate_id($slug) {
		return self::METHOD_ID . ':' . $slug;
	}

	/** Slug del método a partir de un id de tarifa. */
	public static function slug_from_rate($rate_id) {
		$rate_id = (string) $rate_id;
		if (0 !== strpos($rate_id, self::METHOD_ID . ':')) {
			return '';
		}
		return sanitize_key(substr($rate_id, strlen(self::METHOD_ID) + 1));
	}

	/**
	 * Las tarifas del carrito.
	 *
	 * Agrega una por método que cumpla condiciones y pueda cotizar. Si el ajuste
	 * "reemplazar las tarifas de WooCommerce" está encendido y hay al menos una
	 * propia, las de las zonas se van: la tienda muestra una sola lista.
	 */
	public static function rates($rates, $package) {
		if (!MJE_Settings::get('enabled') || !is_array($rates) || !is_array($package)) {
			return $rates;
		}

		/*
		 * Primero, lo que ya calcularon los plugins de los correos. Sus tarifas
		 * llegan acá como cualquier otra y son la única fuente de precio para
		 * los métodos de tipo "nativo": no se le vuelve a preguntar a ninguna
		 * API lo que su propio plugin ya preguntó.
		 */
		MJE_Native::capture($rates);
		self::adopt_native();

		$ctx = [
			'subtotal' => self::package_subtotal($package),
			'weight'   => self::package_weight($package),
			'gateway'  => MJE_Cart::chosen_gateway(),
		];

		$own     = [];
		$wrapped = [];
		foreach (MJE_Methods::available($package, $ctx) as $slug => $def) {
			$quote = self::quote($def, $package);
			if (null === $quote['cost']) {
				continue;
			}

			$label = MJE_Methods::name($def);
			$rate  = new WC_Shipping_Rate(self::rate_id($slug), $label, (float) $quote['cost'], [], self::METHOD_ID, 0);

			/*
			 * Todo lo que la tienda necesita saber de la tarifa viaja en su
			 * meta: la Store API lo publica tal cual, así el checkout puede
			 * dibujar el plazo, el "a coordinar" y los datos que hay que pedir
			 * sin preguntar nada más.
			 *
			 * Las claves van con guion bajo a propósito: WooCommerce copia la
			 * meta de la tarifa a la línea de envío del pedido, y sin el guion
			 * estos datos internos se imprimirían en el email y en el admin.
			 */
			$rate->add_meta_data('_mje_slug', $slug);
			$rate->add_meta_data('_mje_type', (string) $def['type']);
			$rate->add_meta_data('_mje_destination', (string) $def['destination']);
			$rate->add_meta_data('_mje_carrier', (string) $def['carrier']);
			$rate->add_meta_data('_mje_source', (string) $quote['source']);
			if ('' !== (string) $quote['native_rate']) {
				/* La tarifa del plugin del correo que hay detrás: el pedido la necesita para que ese plugin lo reconozca. */
				$rate->add_meta_data('_mje_native_plugin', (string) $def['native_plugin']);
				$rate->add_meta_data('_mje_native_rate', (string) $quote['native_rate']);
				$wrapped[] = (string) $quote['native_rate'];
			}
			if (null !== $quote['base']) {
				$rate->add_meta_data('_mje_base', (string) $quote['base']);
			}
			$rate->add_meta_data('_mje_coordinate', $quote['coordinate'] ? '1' : '');
			$rate->add_meta_data('_mje_description', (string) $def['description']);
			$eta = MJE_Methods::eta(array_merge($def, ['days_min' => $quote['days_min'], 'days_max' => $quote['days_max']]));
			if ('' !== $eta) {
				$rate->add_meta_data('_mje_eta', $eta);
			}
			if ($quote['coordinate']) {
				$rate->add_meta_data('_mje_note', (string) MJE_Settings::get('coordinate_text'));
			}

			$own[self::rate_id($slug)] = $rate;
		}

		if (!$own) {
			return $rates;
		}
		if (MJE_Settings::get('replace_native')) {
			return $own;
		}
		/*
		 * Sin reemplazar, igual se van las tarifas de los correos que ya quedaron
		 * envueltas en un método propio: si no, el cliente ve dos veces el mismo
		 * envío, una con el nombre del plugin y otra con el tuyo.
		 */
		foreach ($wrapped as $rate_id) {
			unset($rates[$rate_id]);
		}
		return array_merge($rates, $own);
	}

	/**
	 * Crea el método que falta para cada servicio de un correo con plugin.
	 *
	 * Es lo que hace que conectar Andreani (o el correo que sea) alcance: sus
	 * modos de entrega aparecen solos en la lista de métodos, con las reglas
	 * generales ya aplicadas, y desde ahí se les cambia el nombre, el orden, las
	 * condiciones o se apagan. Sin esto habría que cargar a mano un método por
	 * cada servicio de cada correo.
	 */
	private static function adopt_native() {
		if (!MJE_Settings::get('native_adopt') || !MJE_Native::any()) {
			return;
		}
		foreach (MJE_Native::rates() as $data) {
			if ('' === $data['plugin'] || MJE_Methods::by_native($data['plugin'], $data['service'])) {
				continue;
			}
			$plugin = MJE_Native::get($data['plugin']);
			$title  = $plugin ? $plugin->service_label($data['service']) : (string) $data['label'];
			MJE_Methods::adopt($data['plugin'], $data['service'], $title, !empty($data['branch']));
		}
	}

	/* --------------------------- Medios de pago -------------------------- */

	/**
	 * El método de envío elegido puede esconder medios de pago.
	 *
	 * Es la misma regla que filtra los envíos por medio de pago, leída al
	 * revés: si el método dice "solo transferencia", en el checkout no aparece
	 * nada más. Así se arma, por ejemplo, un envío propio que solo se cobra en
	 * efectivo contra entrega.
	 */
	public static function filter_gateways($gateways) {
		if (!MJE_Settings::get('enabled') || !is_array($gateways) || is_admin()) {
			return $gateways;
		}
		$def = MJE_Cart::chosen_method();
		if (!$def || 'todos' === (string) $def['payments_mode']) {
			return $gateways;
		}
		$out = [];
		foreach ($gateways as $id => $gateway) {
			if (MJE_Methods::allows_gateway($def, $id)) {
				$out[$id] = $gateway;
			}
		}
		/* Nunca se deja el checkout sin ninguna forma de pagar. */
		return $out ? $out : $gateways;
	}
}
