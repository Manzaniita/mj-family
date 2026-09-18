<?php
/**
 * El puente con los plugins de los correos.
 *
 * Cuando un correo ya tiene su plugin oficial instalado y conectado (Andreani,
 * Correo Argentino, el que sea), MJ Envíos no le vuelve a pedir las
 * credenciales ni rehace su API: deja que ese plugin calcule su tarifa, se la
 * queda antes de armar la lista del checkout y le aplica encima las reglas de
 * la casa. Una sola configuración, una sola lista para el cliente.
 *
 * CÓMO LLEGA LA TARIFA DEL CORREO
 *   El plugin del correo registra un método de envío de WooCommerce y lo
 *   agregás a una zona, como siempre. Cuando WooCommerce calcula el carrito, su
 *   tarifa entra por `woocommerce_package_rates` junto con todas las demás, y
 *   MJ Envíos la guarda acá (capture) antes de decidir qué muestra. No se
 *   duplica ninguna llamada a la API del correo: la que ya hizo su plugin es la
 *   que se usa.
 *
 *   Por eso el panel avisa cuando un correo está conectado pero no está en
 *   ninguna zona: ahí su tarifa no llega nunca, y no es culpa de MJ Envíos.
 *
 * GENÉRICO PRIMERO
 *   La captura no sabe de ningún correo en particular: agarra cualquier tarifa
 *   que no sea de MJ Envíos, venga del plugin que venga. Los adaptadores
 *   (MJE_Native_Plugin) agregan solo lo que no se puede adivinar: las
 *   sucursales, dónde se anota la que eligió el cliente, el estado de la
 *   conexión y qué hay que escribirle al pedido para que el plugin del correo
 *   lo reconozca como suyo.
 *
 * SIN DEPENDENCIA
 *   Todo esto se apaga solo si no hay plugins de correo instalados, y ninguno
 *   de ellos se entera de que MJ Envíos existe. Sumar otro correo con plugin
 *   propio es registrar un adaptador con el filtro `mje_native_plugins`.
 */

defined('ABSPATH') || exit;

class MJE_Native {

	/** Adaptadores registrados: id => MJE_Native_Plugin. */
	private static $plugins = null;

	/** Tarifas ajenas del cálculo en curso: rate_id => datos. */
	private static $rates = [];

	/** Para el panel: qué se capturó la última vez, aunque haya sido otra carga. */
	const TRANSIENT_SEEN = 'mje_native_seen';

	public static function init() {
		/* La sucursal elegida se anota en el plugin del correo antes de cotizar. */
		add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'prime'], 5, 1);
	}

	/* ----------------------------- Adaptadores --------------------------- */

	/**
	 * Los adaptadores cuyo plugin está realmente instalado.
	 *
	 * El filtro recibe las clases, no las instancias: un adaptador de otro
	 * plugin se registra sin cargar nada hasta que hace falta.
	 */
	public static function all() {
		if (null !== self::$plugins) {
			return self::$plugins;
		}
		$classes = (array) apply_filters('mje_native_plugins', [
			'MJE_Native_Andreani',
			'MJE_Native_Correo',
		]);

		self::$plugins = [];
		foreach ($classes as $class) {
			if (!is_string($class) || !class_exists($class)) {
				continue;
			}
			$plugin = new $class();
			if (!$plugin instanceof MJE_Native_Plugin || !$plugin->available()) {
				continue;
			}
			self::$plugins[$plugin->id()] = $plugin;
		}
		return self::$plugins;
	}

	public static function get($id) {
		$all = self::all();
		$id  = sanitize_key((string) $id);
		return isset($all[$id]) ? $all[$id] : null;
	}

	/** ¿Hay algún plugin de correo instalado? */
	public static function any() {
		return (bool) self::all();
	}

	/** El nombre de un correo, aunque su plugin ya no esté instalado. */
	public static function label_of($id) {
		$plugin = self::get($id);
		if ($plugin) {
			return $plugin->label();
		}
		$id = sanitize_key((string) $id);
		return '' !== $id ? ucfirst($id) : 'El correo';
	}

	/** id => nombre, para los desplegables del panel. */
	public static function choices() {
		$out = [];
		foreach (self::all() as $id => $plugin) {
			$out[$id] = $plugin->label();
		}
		return $out;
	}

	/** El adaptador que reconoce un id de método de WooCommerce. */
	public static function by_method($method_id) {
		$method_id = (string) $method_id;
		foreach (self::all() as $plugin) {
			if ($plugin->method_id() === $method_id) {
				return $plugin;
			}
		}
		return null;
	}

	/* ------------------------------ Captura ------------------------------ */

	/**
	 * Guarda las tarifas que calcularon otros plugins.
	 *
	 * Se llama con las tarifas del paquete tal como llegan, antes de que MJ
	 * Envíos arme su lista. Lo de MJ Envíos se saltea: no tiene sentido que el
	 * puente se capture a sí mismo.
	 */
	public static function capture($rates) {
		self::$rates = [];
		if (!is_array($rates)) {
			return;
		}
		$seen = [];
		foreach ($rates as $rate_id => $rate) {
			if (!$rate instanceof WC_Shipping_Rate) {
				continue;
			}
			$method_id = (string) $rate->get_method_id();
			if (MJE_Rates::METHOD_ID === $method_id) {
				continue;
			}
			$plugin  = self::by_method($method_id);
			$service = $plugin ? $plugin->service_of($rate_id) : '';

			self::$rates[(string) $rate_id] = [
				'rate_id'   => (string) $rate_id,
				'method_id' => $method_id,
				'plugin'    => $plugin ? $plugin->id() : '',
				'service'   => $service,
				'label'     => (string) $rate->get_label(),
				'cost'      => (float) $rate->get_cost(),
				'taxes'     => $rate->get_taxes(),
				'branch'    => $plugin ? $plugin->is_branch_service($service, $rate) : false,
				'rate'      => $rate,
			];
			$seen[] = (string) $rate_id;
		}

		/*
		 * Queda anotado qué tarifas ajenas se vieron, para que el panel pueda
		 * listarlas aunque nadie esté comprando en este momento.
		 */
		if ($seen) {
			set_transient(self::TRANSIENT_SEEN, $seen, DAY_IN_SECONDS);
		}
	}

	/** Las tarifas ajenas del cálculo en curso. */
	public static function rates() {
		return self::$rates;
	}

	/** Una tarifa ajena puntual. */
	public static function rate($rate_id) {
		$rate_id = (string) $rate_id;
		return isset(self::$rates[$rate_id]) ? self::$rates[$rate_id] : null;
	}

	/**
	 * La tarifa de un correo y un servicio.
	 *
	 * El servicio vacío toma la primera del correo, que es lo que quiere un
	 * método que dice "Andreani, lo que haya".
	 */
	public static function find($plugin_id, $service = '') {
		$plugin_id = sanitize_key((string) $plugin_id);
		$service   = MJE_Settings::key_of((string) $service);
		foreach (self::$rates as $data) {
			if ($data['plugin'] !== $plugin_id) {
				continue;
			}
			if ('' === $service || MJE_Settings::key_of($data['service']) === $service) {
				return $data;
			}
		}
		return null;
	}

	/** Las tarifas ajenas que ningún método de MJ Envíos está envolviendo. */
	public static function orphans(array $wrapped) {
		$out = [];
		foreach (self::$rates as $rate_id => $data) {
			if (!in_array($rate_id, $wrapped, true)) {
				$out[$rate_id] = $data;
			}
		}
		return $out;
	}

	/** Los ids de tarifa vistos alguna vez, para armar las opciones del panel. */
	public static function seen() {
		$seen = get_transient(self::TRANSIENT_SEEN);
		return is_array($seen) ? $seen : [];
	}

	/* ------------------------------ Sucursal ----------------------------- */

	/**
	 * Le pasa al plugin del correo la sucursal que eligió el cliente.
	 *
	 * Corre antes del cálculo de tarifas porque varios correos cotizan distinto
	 * según a qué sucursal va el paquete, y la leen de su propia sesión.
	 */
	public static function prime($packages) {
		if (!MJE_Settings::get('enabled') || !self::any()) {
			return $packages;
		}
		$def = MJE_Cart::chosen_method();
		if (!$def || 'nativo' !== (string) $def['type']) {
			return $packages;
		}
		$plugin = self::get((string) $def['native_plugin']);
		if (!$plugin) {
			return $packages;
		}
		$data   = MJE_Cart::session_data();
		$branch = (string) $data['branch'];
		if ('' !== $branch) {
			$plugin->remember_branch($branch);
		}
		return $packages;
	}

	/**
	 * Las sucursales de un correo con plugin propio, guardadas en la tabla de
	 * MJ Envíos para que el checkout no dependa de que el correo esté arriba.
	 */
	public static function branches($plugin_id, $postcode = '', $refresh = false) {
		$plugin = self::get($plugin_id);
		if (!$plugin) {
			return [];
		}
		$carrier = self::carrier_key($plugin->id());

		if (!$refresh) {
			$rows = MJE_Carriers::stored_branches($carrier, $postcode);
			if ($rows) {
				return $rows;
			}
		}
		$fetched = $plugin->branches($postcode);
		foreach ($fetched as $branch) {
			MJE_Carriers::save_branch($carrier, $branch, 'nativo');
		}
		$rows = MJE_Carriers::stored_branches($carrier, $postcode);
		return $rows ? $rows : $fetched;
	}

	/**
	 * La clave con la que se guardan las sucursales de un correo con plugin.
	 *
	 * Lleva prefijo para no pisar las del adaptador propio de MJ Envíos, que
	 * puede estar configurado con otra cuenta del mismo correo.
	 */
	public static function carrier_key($plugin_id) {
		return 'nativo_' . sanitize_key((string) $plugin_id);
	}

	/** El nombre completo de una sucursal, para el pedido y los emails. */
	public static function branch_name($plugin_id, $code) {
		return MJE_Carriers::branch_name(self::carrier_key($plugin_id), $code);
	}

	/** Una sucursal guardada, entera. */
	public static function branch($plugin_id, $code) {
		$code = (string) $code;
		foreach (MJE_Carriers::stored_branches(self::carrier_key($plugin_id)) as $branch) {
			if ($branch['code'] === $code) {
				return $branch;
			}
		}
		return [];
	}
}
