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

	/** Por qué un correo no pudo cotizar esta vez: id del correo => motivo. */
	private static $excuses = [];

	/** Fallas del cálculo actual, antes de capturar sus tarifas. */
	private static $failures = [];

	/** ¿Corrió la captura en esta carga? Si no, no hay cálculo del que hablar. */
	private static $captured = false;

	/** ¿Es una cotización suelta (ficha, prueba del panel)? No es la del carrito. */
	private static $loose = false;

	/** Para el panel: qué se capturó la última vez, aunque haya sido otra carga. */
	const TRANSIENT_SEEN = 'mje_native_seen';

	/** El último motivo por el que cada correo no pudo cotizar. */
	const TRANSIENT_EXCUSES = 'mje_native_excuses';

	/** En la sesión del cliente: qué dijo cada correo la última vez que se calculó su carrito. */
	const SESSION_LAST = 'mje_native_last';

	public static function init() {
		add_action('woocommerce_load_shipping_methods', [__CLASS__, 'protect_methods'], 100, 1);
		/* La sucursal elegida se anota en el plugin del correo antes de cotizar. */
		add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'prime'], 5, 1);
		/* Los correos conectados también saben resolver un código postal. */
		add_action('woocommerce_init', ['MJE_Geo', 'maybe_add_native_provider']);
	}

	/* ----------------------------- Adaptadores --------------------------- */

	/** Aísla cada cotizador: una cuenta desconectada no puede romper el carrito. */
	public static function protect_methods($package) {
		if (empty($package) || !MJE_Settings::get('enabled')) {
			return;
		}
		self::$failures = [];
		require_once __DIR__ . '/class-mje-native-method.php';
		$shipping = WC()->shipping();
		foreach ($shipping->shipping_methods as $key => $method) {
			$plugin = self::by_method($method->id);
			if ($plugin && !$method instanceof MJE_Native_Method) {
				$shipping->shipping_methods[$key] = new MJE_Native_Method($method, $plugin->id());
			}
		}
	}

	/** El detalle técnico queda en el registro; al cliente le mostramos un aviso seguro. */
	public static function failed_calculation($id, $error) {
		self::$failures[$id] = 'El correo no pudo cotizar en este momento. Probá otro método de envío.';
		MJE_Geo::log('nativo', $id . ' · cotización: ' . $error->getMessage());
	}

	/**
	 * Le pregunta algo al plugin del correo sin que pueda tumbar la tienda.
	 *
	 * Los plugins de los correos son ajenos y tiran excepciones: uno sin
	 * credenciales cargadas contesta "access token no válido o indefinido" a
	 * cualquier cosa que se le pregunte. Hasta acá eso era un error 500 en medio
	 * del checkout —el código postal no resolvía, el carrito no calculaba el
	 * envío— por un correo que la tienda ni siquiera estaba usando.
	 *
	 * Se atrapa Throwable y no Exception: varios de esos plugins fallan con
	 * TypeError o Error (llamar a un método de null) cuando les falta la cuenta,
	 * y eso NO es una Exception.
	 *
	 * @param string   $scope   Qué se le estaba preguntando, para el log.
	 * @param callable $fn      La pregunta.
	 * @param mixed    $default Qué contestar cuando el correo falla.
	 */
	public static function ask($scope, callable $fn, $default = []) {
		try {
			return $fn();
		} catch (\Throwable $e) {
			MJE_Geo::log('nativo', (string) $scope . ': ' . $e->getMessage());
			return $default;
		}
	}

	/** ¿Este correo está conectado? Su plugin contesta, o se lo da por no conectado. */
	public static function connected(MJE_Native_Plugin $plugin) {
		return (bool) self::ask($plugin->id() . ' · conexión', function () use ($plugin) {
			return $plugin->connected();
		}, false);
	}

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
			if (!$plugin instanceof MJE_Native_Plugin) {
				continue;
			}
			$available = self::ask($class . ' · instalado', function () use ($plugin) {
				return $plugin->available();
			}, false);
			if (!$available) {
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
		self::$rates    = [];
		self::$excuses  = self::$failures;
		self::$captured = true;
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

			/*
			 * Una tarifa de relleno NO es una tarifa: es el correo diciendo que
			 * no puede cotizar, con $0 y el motivo escrito en el nombre. Tomarla
			 * en serio sería ofrecer ese envío gratis. Se anota el motivo, que
			 * es justo lo que hay que mostrarle al dueño en el panel.
			 */
			if ($plugin) {
				$reason = $plugin->placeholder_reason($rate_id, $rate);
				if ('' !== $reason) {
					self::$excuses[$plugin->id()] = $reason;
					continue;
				}
			}

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
			if ($plugin) {
				$seen[$plugin->id()][$service] = (string) $rate->get_label();
			}
		}

		/*
		 * Queda anotado qué servicios se vieron cotizar, para que el panel pueda
		 * listarlos aunque nadie esté comprando en este momento. Es la única
		 * forma honesta de saber qué modos de entrega da tu contrato: no hay
		 * lista fija, la pone el correo.
		 */
		if ($seen) {
			set_transient(self::TRANSIENT_SEEN, $seen, WEEK_IN_SECONDS);
		}
		/*
		 * El motivo también se guarda, porque en el panel no hay carrito que
		 * calcular. Solo cuando cambió: esto corre en cada cálculo del carrito y
		 * no puede escribir en la base cada vez.
		 */
		if (self::$excuses) {
			$stored = get_transient(self::TRANSIENT_EXCUSES);
			if (!is_array($stored) || $stored !== self::$excuses) {
				set_transient(self::TRANSIENT_EXCUSES, self::$excuses, DAY_IN_SECONDS);
			}
		}
		/*
		 * Y en la sesión del cliente, lo de su carrito. Mientras el paquete no
		 * cambie, WooCommerce devuelve las tarifas guardadas sin calcular, y en
		 * esas respuestas la captura no corre: sin esto el checkout afirmaba "no
		 * devolvió ninguna tarifa" al lado de los precios de ese mismo correo.
		 * Solo se escribe cuando cambió, como el transient de arriba.
		 */
		if (!self::$loose && function_exists('WC') && WC()->session) {
			$last = [
				'excuses' => self::$excuses,
				'quoted'  => self::quoted(),
			];
			if (WC()->session->get(self::SESSION_LAST) !== $last) {
				WC()->session->set(self::SESSION_LAST, $last);
			}
		}
	}

	/**
	 * Lo mismo, pero para un paquete armado a mano.
	 *
	 * Sirve para cotizar sin carrito (la ficha del producto): se le pide a
	 * WooCommerce que calcule ese paquete, con lo cual los plugins de los
	 * correos cotizan como siempre y sus tarifas quedan capturadas. Va con una
	 * clave de paquete propia para no pisar lo que la tienda tenga calculado.
	 */
	public static function capture_for_package(array $package, $key = 'mje_quote') {
		if (!self::any() || !function_exists('WC') || !WC()->shipping()) {
			return;
		}
		/*
		 * Una ruta REST propia no arranca la sesión de WooCommerce, y sin sesión
		 * no hay dónde guardar el cálculo del paquete: los correos con plugin no
		 * cotizaban nunca en la ficha del producto. wc_load_cart() la levanta,
		 * que es lo mismo que hace la Store API.
		 */
		if (!WC()->session && function_exists('wc_load_cart')) {
			wc_load_cart();
		}
		if (!WC()->session) {
			return;
		}
		/*
		 * No alcanza con pasarle el paquete: el plugin de Correo Argentino ignora
		 * el que recibe y lee siempre el del carrito
		 * (Classes/correoargentino-shipping-method.php → updateDestinationPostcode
		 * y getDimensions usan WC()->cart->get_shipping_packages()). Sin esto,
		 * cotizaría el destino del carrito —o ninguno, si está vacío— y la ficha
		 * del producto y la prueba del panel dirían cualquier cosa de él.
		 *
		 * Por eso el paquete se presenta también por el filtro que WooCommerce
		 * usa para armar los del carrito, que es donde ese plugin va a buscarlo.
		 * Se engancha justo para esta cotización y se desengancha siempre: es el
		 * mismo filtro del que cuelgan sign_packages() y prime().
		 */
		$suplantar = function () use ($package) {
			return [$package];
		};
		add_filter('woocommerce_cart_shipping_packages', $suplantar, 99);

		/*
		 * Y se calcula siempre de nuevo. WooCommerce guarda en la sesión las
		 * tarifas de cada paquete y, si el paquete no cambió, devuelve lo guardado
		 * SIN pasar por woocommerce_package_rates: la captura no corría y los
		 * correos quedaban mudos. Al cliente con carrito o con cuenta —que tiene
		 * sesión— la segunda consulta del mismo producto al mismo código postal le
		 * decía "no devolvió ninguna tarifa", y la ficha la hace sola al abrirse
		 * con el código postal recordado. Tampoco se deja guardado después: no es
		 * un paquete del carrito. Cuesta lo mismo que la primera consulta: Andreani
		 * guarda la suya un rato, Correo Argentino pregunta cada vez, y el freno de
		 * /cotizar es el que pone el techo.
		 */
		$guardado = 'shipping_for_package_' . $key;
		WC()->session->set($guardado, null);
		self::$loose = true;

		try {
			WC()->shipping()->calculate_shipping_for_package($package, $key);
		} catch (\Throwable $e) {
			MJE_Geo::log('nativo', 'cotización suelta: ' . $e->getMessage());
		} finally {
			remove_filter('woocommerce_cart_shipping_packages', $suplantar, 99);
			WC()->session->set($guardado, null);
			self::$loose = false;
		}
	}

	/** Las tarifas ajenas del cálculo en curso. */
	public static function rates() {
		return self::$rates;
	}

	/**
	 * Por qué un correo no cotizó esta vez, dicho por él mismo.
	 *
	 * Vale para el panel y para el checkout: "Correo Argentino no tiene tarifas
	 * para ese destino" es infinitamente más útil que un envío que no aparece.
	 */
	public static function excuses() {
		$out    = self::$excuses;
		$quoted = self::quoted();

		/*
		 * Si en esta carga no se calculó nada, WooCommerce usó las tarifas que
		 * tenía guardadas: lo que vale es lo que dijeron los correos cuando se
		 * calcularon. Sin ese dato no se puede afirmar nada de nadie.
		 */
		if (!self::$captured) {
			$last = function_exists('WC') && WC()->session ? WC()->session->get(self::SESSION_LAST) : null;
			if (!is_array($last)) {
				return [];
			}
			$out    = isset($last['excuses']) && is_array($last['excuses']) ? $last['excuses'] : [];
			$quoted = isset($last['quoted']) && is_array($last['quoted']) ? $last['quoted'] : [];
		}

		/*
		 * Un correo conectado, puesto en una zona y que no devolvió NADA tampoco
		 * cotizó. Se resuelve acá y no en capture() porque mirar las zonas
		 * cuesta consultas, y capture() corre en cada cálculo del carrito;
		 * esto, en cambio, se pregunta una vez por respuesta.
		 */
		foreach (self::all() as $id => $plugin) {
			if (isset($out[$id]) || in_array($id, $quoted, true) || !self::connected($plugin)) {
				continue;
			}
			$in_zone = self::ask($id . ' · zonas', function () use ($plugin) {
				return $plugin->in_any_zone();
			}, false);
			if (!$in_zone) {
				continue;
			}
			$out[$id] = 'no devolvió ninguna tarifa para ese destino';
		}
		return $out;
	}

	/** Los correos que aportaron alguna tarifa en el cálculo en curso. */
	private static function quoted() {
		$out = [];
		foreach (self::$rates as $data) {
			if ('' !== $data['plugin'] && !in_array($data['plugin'], $out, true)) {
				$out[] = $data['plugin'];
			}
		}
		return $out;
	}

	/**
	 * El motivo de un correo, sea de este cálculo o del último que hubo.
	 *
	 * En el panel no hay carrito que calcular, así que ahí vale el guardado: es
	 * lo que le permite al dueño ver por qué su correo no está apareciendo.
	 */
	public static function excuse($plugin_id) {
		$plugin_id = sanitize_key((string) $plugin_id);
		if (isset(self::$excuses[$plugin_id])) {
			return self::$excuses[$plugin_id];
		}
		$stored = get_transient(self::TRANSIENT_EXCUSES);
		return is_array($stored) && isset($stored[$plugin_id]) ? (string) $stored[$plugin_id] : '';
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

	/**
	 * Los servicios que se vieron cotizar, por correo: id => [servicio => nombre].
	 *
	 * Lo de esta carga pisa a lo guardado: si el correo acaba de cotizar, eso es
	 * lo más fresco que hay.
	 */
	public static function seen() {
		$stored = get_transient(self::TRANSIENT_SEEN);
		$stored = is_array($stored) ? $stored : [];

		foreach (self::$rates as $data) {
			if ('' !== $data['plugin']) {
				$stored[$data['plugin']][$data['service']] = (string) $data['label'];
			}
		}
		return $stored;
	}

	/** Los servicios vistos de un correo puntual: servicio => nombre. */
	public static function seen_services($plugin_id) {
		$seen      = self::seen();
		$plugin_id = sanitize_key((string) $plugin_id);
		return isset($seen[$plugin_id]) && is_array($seen[$plugin_id]) ? $seen[$plugin_id] : [];
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
			self::ask($plugin->id() . ' · sucursal elegida', function () use ($plugin, $branch) {
				$plugin->remember_branch($branch);
				return true;
			}, false);
		}
		return $packages;
	}

	/**
	 * Las sucursales de un correo con plugin propio, guardadas en la tabla de
	 * MJ Envíos para que el checkout no dependa de que el correo esté arriba.
	 */
	public static function branches($plugin_id, $postcode = '', $refresh = false, $state = '') {
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
		$fetched = self::ask($plugin->id() . ' · sucursales', function () use ($plugin, $postcode, $state) {
			return $plugin->branches($postcode, $state);
		}, []);
		$fetched = is_array($fetched) ? $fetched : [];
		foreach ($fetched as $branch) {
			MJE_Carriers::save_branch($carrier, $branch, 'nativo');
		}
		$rows = MJE_Carriers::stored_branches($carrier, $postcode);
		return $rows ? $rows : $fetched;
	}

	/**
	 * Qué localidad y provincia hay en un código postal, según los correos.
	 *
	 * Se le pregunta al primero que sepa contestar. Sirve para completarle la
	 * localidad al cliente cuando la tabla propia del plugin todavía está vacía,
	 * que es el caso de toda tienda que no importó su CSV de códigos postales.
	 */
	public static function resolve_cp($postcode) {
		$postcode = MJE_Geo::normalize_cp($postcode);
		if ('' === $postcode) {
			return [];
		}
		foreach (self::all() as $plugin) {
			if (!self::connected($plugin)) {
				continue;
			}
			$rows = self::ask($plugin->id() . ' · código postal', function () use ($plugin, $postcode) {
				return $plugin->resolve_cp($postcode);
			}, []);
			if (is_array($rows) && $rows) {
				return $rows;
			}
		}
		return [];
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
