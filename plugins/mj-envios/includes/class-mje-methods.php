<?php
/**
 * Los métodos de envío: definición, guardado y condiciones.
 *
 * Un método es una fila de la opción mje_methods, indexada por su slug. Todo lo
 * que decide cómo se comporta está ahí: qué tipo es (de dónde sale el precio),
 * a qué destino va, con qué condiciones aparece, con qué medios de pago se
 * combina, qué datos pide y qué le dice al cliente.
 *
 * Tipos (lo único que cambia de dónde sale el precio):
 *   tabla      Tabla propia: filas por CP, provincia, localidad o "todos", con
 *              tope de peso, precio y precio por kilo extra.
 *   fijo       Un precio y listo.
 *   correo     Cotización en vivo con el correo elegido. Si falla, hace lo que
 *              diga el ajuste general (tabla propia, a coordinar u ocultar).
 *   nativo     El precio lo pone el plugin oficial del correo, que ya está
 *              instalado y conectado. MJ Envíos no cotiza: se queda con la
 *              tarifa que ese plugin calculó y le aplica las reglas de la casa.
 *   gratis     Sin costo, siempre.
 *   retiro     Retiro en el local: sin costo (o con el precio que le pongas) y
 *              sin dirección de entrega.
 *   coordinar  Se ofrece sin precio y se arregla después de la compra.
 *
 * Las condiciones son todas opcionales: lo que queda en 0 o vacío no filtra.
 */

defined('ABSPATH') || exit;

class MJE_Methods {

	private static $cache = null;

	/**
	 * Los tipos disponibles.
	 *
	 * "Correo conectado por su plugin" solo se ofrece cuando hay al menos un
	 * plugin de correo instalado: no tiene sentido elegir un tipo que no puede
	 * cotizar nada en esta tienda.
	 */
	public static function types() {
		$types = [
			'tabla'     => 'Tabla propia (por destino y peso)',
			'fijo'      => 'Precio fijo',
			'correo'    => 'Cotización en vivo con un correo',
			'gratis'    => 'Gratis',
			'retiro'    => 'Retiro en el local',
			'coordinar' => 'A coordinar (sin precio)',
		];
		if (class_exists('MJE_Native') && MJE_Native::any()) {
			$types['nativo'] = 'Correo conectado por su propio plugin';
		}
		return $types;
	}

	/** Todos los tipos, estén disponibles o no: para leer lo que ya está guardado. */
	public static function all_types() {
		return array_merge(self::types(), ['nativo' => 'Correo conectado por su propio plugin']);
	}

	public static function destinations() {
		return [
			'domicilio' => 'A domicilio (pide calle y número)',
			'sucursal'  => 'A sucursal del correo (pide sucursal)',
			'local'     => 'Se retira en el local (no pide dirección)',
		];
	}

	public static function scopes() {
		return [
			'todos'     => 'Cualquier destino',
			'cp'        => 'Códigos postales',
			'provincia' => 'Provincias',
			'localidad' => 'Localidades',
		];
	}

	public static function user_choices() {
		return [
			'todos'      => 'Cualquiera',
			'logueados'  => 'Solo con cuenta',
			'invitados'  => 'Solo sin cuenta',
			'roles'      => 'Solo estos roles',
		];
	}

	public static function payment_modes() {
		return [
			'todos'   => 'Con cualquier medio de pago',
			'solo'    => 'Solo con estos medios de pago',
			'excepto' => 'Con todos menos estos',
		];
	}

	/* ----------------------------- Definición ---------------------------- */

	public static function defaults() {
		return [
			'slug'             => '',
			'title'            => '',
			'short'            => '',   // Nombre corto para el resumen ('' = el título).
			'description'      => '',   // Lo que se lee abajo del título en el checkout.
			'icon'             => '',   // Emoji, si querés que lleve uno.
			'enabled'          => 1,
			'order'            => 0,
			'group'            => '',   // Grupo del checkout ('' = se muestra suelto). Ver MJE_Groups.

			/* Qué es y de dónde sale el precio ---------------------------- */
			'type'             => 'tabla',
			'destination'      => 'domicilio',
			'carrier'          => '',
			'service'          => '',
			'price'            => 0,    // Precio fijo, o base de la tabla.
			'table'            => [],   // Ver sanitize_table().

			/* Tipo 'nativo': qué plugin de correo y qué servicio suyo ------ */
			'native_plugin'    => '',   // Id del adaptador ('andreani', 'correo').
			'native_service'   => '',   // Modo de entrega suyo ('' = el primero que haya).
			'native_adopted'   => 0,    // 1 = lo creó solo la adopción automática.

			/* Ajustes de precio (-1 = heredar del general) ---------------- */
			'markup_pct'       => -1,
			'markup_fixed'     => -1,
			'insurance_pct'    => -1,
			'min_cost'         => -1,
			'free_allowed'     => 1,    // Este método puede quedar gratis.
			'free_min'         => -1,   // Monto desde el que es gratis (-1 heredar, 0 nunca).

			/* Condiciones del carrito ------------------------------------ */
			'min_total'        => 0,
			'max_total'        => 0,
			'min_weight'       => 0,
			'max_weight'       => 0,
			'min_qty'          => 0,
			'max_qty'          => 0,

			/* Condiciones del destino ------------------------------------ */
			'countries'        => '',
			'states'           => '',
			'states_exclude'   => '',
			'cp_include'       => '',   // "1000-1999,5000,B7600"
			'cp_exclude'       => '',
			'cities'           => '',
			'cities_exclude'   => '',

			/* Condiciones del contenido ---------------------------------- */
			'products'         => '',
			'products_exclude' => '',
			'cats'             => '',
			'cats_exclude'     => '',

			/* Condiciones de quién compra -------------------------------- */
			'users'            => 'todos',
			'roles'            => '',

			/* Medios de pago (filtra en los dos sentidos) ---------------- */
			'payments_mode'    => 'todos',
			'payments'         => '',

			/* Qué se le promete al cliente ------------------------------- */
			'days_min'         => 0,
			'days_max'         => 0,
			'eta_text'         => '',   // '' = el texto general con {min} y {max}.
			'instructions'     => '',   // Página de gracias y email.
			'tracking_url'     => '',   // Plantilla con {codigo}.

			/* Familia MJ ------------------------------------------------- */
			'erp_delivery'     => '',   // Forma de entrega equivalente en MJ ERP ('' = la de la tienda).

			/* Qué datos pide -------------------------------------------- */
			'fields'           => [],
		];
	}

	/**
	 * Métodos de ejemplo de la primera activación.
	 *
	 * Nacen APAGADOS a propósito: activar el plugin no puede cambiar lo que la
	 * tienda cobra por el envío. Están para que la pantalla no arranque vacía y
	 * se vea de qué se trata; se editan, se encienden o se borran.
	 */
	public static function examples() {
		$defaults = self::defaults();
		$examples = [
			'retiro' => [
				'slug'         => 'retiro',
				'title'        => 'Retiro en el local',
				'description'  => 'Coordinamos por WhatsApp cuándo pasás.',
				'icon'         => '🏠',
				'type'         => 'retiro',
				'destination'  => 'local',
				'order'        => 10,
				'days_max'     => 1,
				'erp_delivery' => 'retira_local',
				'instructions' => 'Te avisamos cuando esté listo para retirar.',
			],
			'domicilio' => [
				'slug'        => 'domicilio',
				'title'       => 'Envío a domicilio',
				'description' => 'Llega a la puerta de tu casa.',
				'icon'        => '🚚',
				'type'        => 'tabla',
				'destination' => 'domicilio',
				'order'       => 20,
				'days_min'    => 3,
				'days_max'    => 7,
				'table'       => [
					['scope' => 'todos', 'match' => '', 'weight_max' => 0, 'price' => 0, 'per_kg' => 0],
				],
			],
			'coordinar' => [
				'slug'        => 'coordinar',
				'title'       => 'Envío a coordinar',
				'description' => 'Lo calculamos con vos después de la compra.',
				'icon'        => '💬',
				'type'        => 'coordinar',
				'destination' => 'domicilio',
				'order'       => 30,
			],
		];
		$out = [];
		foreach ($examples as $slug => $def) {
			$def['enabled'] = 0;
			$out[$slug]     = wp_parse_args($def, $defaults);
		}
		return $out;
	}

	/* ------------------------------ Guardado ----------------------------- */

	/** Todos los métodos guardados, ordenados: slug => definición completa. */
	public static function all() {
		if (null === self::$cache) {
			$saved       = get_option(MJE_Settings::METHODS, []);
			self::$cache = [];
			if (is_array($saved)) {
				foreach ($saved as $slug => $def) {
					$slug = sanitize_key($slug);
					if ('' === $slug || !is_array($def)) {
						continue;
					}
					$def         = wp_parse_args($def, self::defaults());
					$def['slug'] = $slug;
					self::$cache[$slug] = $def;
				}
			}
			uasort(self::$cache, function ($a, $b) {
				if ((int) $a['order'] === (int) $b['order']) {
					return strcasecmp((string) $a['title'], (string) $b['title']);
				}
				return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
			});
		}
		return self::$cache;
	}

	/** Solo los activos. */
	public static function enabled() {
		return array_filter(self::all(), function ($def) {
			return !empty($def['enabled']);
		});
	}

	public static function get($slug) {
		$all  = self::all();
		$slug = sanitize_key($slug);
		return isset($all[$slug]) ? $all[$slug] : null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Guarda un método (lo crea si no existe). Devuelve el slug con el que quedó.
	 *
	 * `$flush` en false guarda sin vaciar las cachés de WooCommerce. Es para la
	 * adopción automática, que corre DENTRO del cálculo del carrito: purgar las
	 * cachés ahí es caro y no hace falta, porque las tarifas que se están
	 * calculando ya incluyen el método nuevo.
	 */
	public static function save($slug, $def, $flush = true) {
		$all  = self::all();
		$def  = self::sanitize($def);
		$slug = sanitize_key('' !== $slug ? $slug : $def['slug']);
		if ('' === $slug) {
			$slug = self::unique_slug($def['title']);
		}
		$def['slug'] = $slug;
		$all[$slug]  = $def;
		self::store($all, $flush);
		return $slug;
	}

	public static function delete($slug) {
		$all  = self::all();
		$slug = sanitize_key($slug);
		if (!isset($all[$slug])) {
			return false;
		}
		unset($all[$slug]);
		self::store($all);
		return true;
	}

	/** Copia un método con otro slug (para armar variantes sin recargar todo). */
	public static function duplicate($slug) {
		$def = self::get($slug);
		if (!$def) {
			return '';
		}
		$copy          = $def;
		$copy['slug']  = self::unique_slug($def['title'] . ' copia');
		$copy['title'] = trim((string) $def['title']) . ' (copia)';
		$copy['enabled'] = 0;
		$copy['order']   = (int) $def['order'] + 1;
		$all             = self::all();
		$all[$copy['slug']] = $copy;
		self::store($all);
		return $copy['slug'];
	}

	/**
	 * Quién lleva el paquete de este método.
	 *
	 * → ['key' => clave estable para agrupar, 'label' => cómo se llama]
	 *
	 * Es lo que le permite al checkout mostrar una tarjeta por correo con todos
	 * sus modos de entrega adentro, en vez de una lista larga de opciones
	 * sueltas. Un método propio (tabla, precio fijo, retiro) no tiene correo: va
	 * con la clave vacía y se muestra sin encabezado.
	 */
	public static function carrier_of(array $def) {
		if ('nativo' === (string) $def['type'] && '' !== (string) $def['native_plugin']) {
			$id = (string) $def['native_plugin'];
			return ['key' => 'nativo:' . $id, 'label' => MJE_Native::label_of($id)];
		}
		if ('correo' === (string) $def['type'] && '' !== (string) $def['carrier']) {
			$id      = (string) $def['carrier'];
			$carrier = MJE_Carriers::get($id);
			return ['key' => 'correo:' . $id, 'label' => $carrier ? $carrier->label() : ucfirst($id)];
		}
		return ['key' => '', 'label' => ''];
	}

	/**
	 * De dónde salen las sucursales de un método.
	 *
	 * Un método que cotiza con el plugin oficial del correo usa las sucursales
	 * de ese plugin, guardadas aparte de las del adaptador propio: la misma
	 * tienda puede tener las dos cosas con cuentas distintas.
	 */
	public static function branch_source(array $def) {
		if ('nativo' === (string) $def['type'] && '' !== (string) $def['native_plugin']) {
			return MJE_Native::carrier_key((string) $def['native_plugin']);
		}
		return (string) $def['carrier'];
	}

	/* ------------------------- Correos con plugin ------------------------ */

	/**
	 * El método que envuelve a un servicio de un correo con plugin propio.
	 *
	 * Un servicio vacío en el método guardado significa "cualquiera de ese
	 * correo", así que también sirve de comodín.
	 */
	public static function by_native($plugin_id, $service) {
		$plugin_id = sanitize_key((string) $plugin_id);
		$service   = MJE_Settings::key_of((string) $service);
		$fallback  = null;

		foreach (self::all() as $def) {
			if ('nativo' !== (string) $def['type'] || sanitize_key((string) $def['native_plugin']) !== $plugin_id) {
				continue;
			}
			$own = MJE_Settings::key_of((string) $def['native_service']);
			if ($own === $service) {
				return $def;
			}
			if ('' === $own && null === $fallback) {
				$fallback = $def;
			}
		}
		return $fallback;
	}

	/**
	 * Crea el método que envuelve a un servicio de un correo con plugin.
	 *
	 * Es lo que hace que no haya que cargar a mano cada modo de entrega: la
	 * primera vez que el correo cotiza un servicio, queda un método propio con
	 * ese nombre, listo para renombrar, ordenar, condicionar o apagar. Nace
	 * ENCENDIDO porque su tarifa ya se estaba mostrando en la tienda: adoptarla
	 * no puede hacerla desaparecer.
	 */
	public static function adopt($plugin_id, $service, $title, $branch = false, $group = '', $short = '') {
		$plugin_id = sanitize_key((string) $plugin_id);
		if ('' === $plugin_id) {
			return null;
		}
		$existing = self::by_native($plugin_id, $service);
		if ($existing) {
			return $existing;
		}

		$slug = self::unique_slug($plugin_id . ' ' . $service);
		$def  = wp_parse_args([
			'slug'           => $slug,
			'title'          => (string) $title,
			/* El modo solo: es lo que se lee adentro de la tarjeta del correo. */
			'short'          => (string) $short,
			'type'           => 'nativo',
			'destination'    => $branch ? 'sucursal' : 'domicilio',
			'native_plugin'  => $plugin_id,
			'native_service' => (string) $service,
			'native_adopted' => 1,
			'enabled'        => 1,
			'order'          => 50,
			'group'          => sanitize_key((string) $group),
		], self::defaults());

		/* Sin vaciar cachés: esto corre dentro del cálculo del carrito. */
		self::save($slug, $def, false);
		return self::get($slug);
	}

	/** Reordena por la lista de slugs que llega del panel. */
	public static function reorder(array $slugs) {
		$all   = self::all();
		$order = 10;
		foreach ($slugs as $slug) {
			$slug = sanitize_key($slug);
			if (isset($all[$slug])) {
				$all[$slug]['order'] = $order;
				$order += 10;
			}
		}
		self::store($all);
	}

	private static function store(array $all, $flush = true) {
		$clean = [];
		foreach ($all as $slug => $def) {
			$slug = sanitize_key($slug);
			if ('' !== $slug) {
				$def['slug']  = $slug;
				$clean[$slug] = $def;
			}
		}
		update_option(MJE_Settings::METHODS, $clean);
		/* La caché propia siempre se vacía: lo que sigue tiene que ver el método nuevo. */
		self::$cache = null;
		if ($flush) {
			MJE_Settings::flush_caches();
		}
	}

	private static function unique_slug($title) {
		$base = sanitize_key(sanitize_title((string) $title));
		if ('' === $base) {
			$base = 'envio';
		}
		$all  = self::all();
		$slug = $base;
		$i    = 2;
		while (isset($all[$slug])) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	public static function sanitize($in) {
		$d   = self::defaults();
		$in  = is_array($in) ? $in : [];
		$out = $d;

		$out['slug']  = isset($in['slug']) ? sanitize_key($in['slug']) : '';
		$out['title'] = isset($in['title']) ? mb_substr(trim(sanitize_text_field($in['title'])), 0, 120) : '';
		if ('' === $out['title']) {
			$out['title'] = 'Envío';
		}
		foreach (['short', 'icon'] as $key) {
			$out[$key] = isset($in[$key]) ? mb_substr(trim(sanitize_text_field($in[$key])), 0, 60) : '';
		}
		foreach (['description', 'instructions'] as $key) {
			$out[$key] = isset($in[$key]) ? sanitize_textarea_field($in[$key]) : '';
		}
		$out['eta_text']     = isset($in['eta_text']) ? mb_substr(trim(sanitize_text_field($in['eta_text'])), 0, 190) : '';
		$url                 = isset($in['tracking_url']) ? trim((string) $in['tracking_url']) : '';
		$out['tracking_url'] = '' !== $url ? esc_url_raw($url) : '';

		$out['enabled'] = empty($in['enabled']) ? 0 : 1;
		$out['order']   = isset($in['order']) ? max(0, min(9999, (int) $in['order'])) : 0;
		/* El grupo se guarda aunque ya no exista: borrar un grupo no rompe el método. */
		$out['group']   = isset($in['group']) ? sanitize_key($in['group']) : '';

		$type         = isset($in['type']) ? sanitize_key($in['type']) : $d['type'];
		$out['type']  = array_key_exists($type, self::all_types()) ? $type : $d['type'];
		$dest         = isset($in['destination']) ? sanitize_key($in['destination']) : $d['destination'];
		$out['destination'] = array_key_exists($dest, self::destinations()) ? $dest : $d['destination'];

		$carrier        = isset($in['carrier']) ? sanitize_key($in['carrier']) : '';
		$out['carrier'] = array_key_exists($carrier, MJE_Carriers::choices()) ? $carrier : '';
		$out['service'] = isset($in['service']) ? mb_substr(trim(sanitize_text_field($in['service'])), 0, 60) : '';

		/*
		 * El plugin de correo se guarda aunque no esté instalado en este
		 * momento: si se desactiva y se vuelve a activar, el método sigue
		 * apuntando a donde apuntaba.
		 */
		$out['native_plugin']  = isset($in['native_plugin']) ? sanitize_key($in['native_plugin']) : '';
		$out['native_service'] = isset($in['native_service']) ? mb_substr(trim(sanitize_text_field($in['native_service'])), 0, 120) : '';
		$out['native_adopted'] = empty($in['native_adopted']) ? 0 : 1;

		$out['price'] = max(0, MJE_Settings::decimal(isset($in['price']) ? $in['price'] : 0));
		$out['table'] = self::sanitize_table(isset($in['table']) ? $in['table'] : []);

		/* -1 = heredar; cualquier otro valor pisa el general. */
		foreach (['markup_pct', 'markup_fixed', 'insurance_pct', 'min_cost', 'free_min'] as $key) {
			$raw       = isset($in[$key]) ? trim((string) $in[$key]) : '';
			$out[$key] = '' === $raw ? -1 : max(-1, MJE_Settings::decimal($raw));
		}
		$out['free_allowed'] = empty($in['free_allowed']) ? 0 : 1;

		foreach (['min_total', 'max_total', 'min_weight', 'max_weight'] as $key) {
			$out[$key] = max(0, MJE_Settings::decimal(isset($in[$key]) ? $in[$key] : 0));
		}
		foreach (['min_qty', 'max_qty', 'days_min', 'days_max'] as $key) {
			$out[$key] = max(0, min(9999, absint(isset($in[$key]) ? $in[$key] : 0)));
		}

		$out['countries'] = isset($in['countries']) ? strtoupper(MJE_Settings::slug_list($in['countries'])) : '';
		foreach (['states', 'states_exclude', 'cities', 'cities_exclude'] as $key) {
			$out[$key] = isset($in[$key]) ? MJE_Settings::text_list($in[$key]) : '';
		}
		foreach (['cp_include', 'cp_exclude'] as $key) {
			$out[$key] = isset($in[$key]) ? self::sanitize_cp_list($in[$key]) : '';
		}
		foreach (['products', 'products_exclude'] as $key) {
			$ids = [];
			foreach (MJE_Settings::to_array(isset($in[$key]) ? $in[$key] : '') as $id) {
				$id = absint($id);
				if ($id) {
					$ids[] = $id;
				}
			}
			$out[$key] = implode(',', array_unique($ids));
		}
		foreach (['cats', 'cats_exclude'] as $key) {
			$out[$key] = isset($in[$key]) ? MJE_Settings::slug_list($in[$key]) : '';
		}

		$users        = isset($in['users']) ? sanitize_key($in['users']) : $d['users'];
		$out['users'] = array_key_exists($users, self::user_choices()) ? $users : $d['users'];
		$out['roles'] = isset($in['roles']) ? MJE_Settings::slug_list($in['roles']) : '';

		$mode                  = isset($in['payments_mode']) ? sanitize_key($in['payments_mode']) : $d['payments_mode'];
		$out['payments_mode']  = array_key_exists($mode, self::payment_modes()) ? $mode : $d['payments_mode'];
		$out['payments']       = isset($in['payments']) ? MJE_Settings::slug_list($in['payments']) : '';

		$out['erp_delivery'] = isset($in['erp_delivery']) ? sanitize_key($in['erp_delivery']) : '';

		$out['fields'] = MJE_Fields::sanitize_method_fields(isset($in['fields']) ? $in['fields'] : []);

		return $out;
	}

	/**
	 * Filas de la tabla de precios.
	 *
	 * Cada fila: a qué destinos aplica (scope + match), hasta qué peso vale,
	 * cuánto cuesta y cuánto se suma por kilo que pase de ese peso.
	 */
	public static function sanitize_table($rows) {
		$clean = [];
		foreach ((array) $rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$scope = isset($row['scope']) ? sanitize_key($row['scope']) : 'todos';
			$scope = array_key_exists($scope, self::scopes()) ? $scope : 'todos';
			$match = isset($row['match']) ? trim((string) $row['match']) : '';
			$match = 'cp' === $scope ? self::sanitize_cp_list($match) : MJE_Settings::text_list($match);
			if ('todos' !== $scope && '' === $match) {
				continue; // Una fila que no dice a qué destino aplica no sirve.
			}
			$price  = max(0, MJE_Settings::decimal(isset($row['price']) ? $row['price'] : 0));
			$weight = max(0, MJE_Settings::decimal(isset($row['weight_max']) ? $row['weight_max'] : 0));
			$perkg  = max(0, MJE_Settings::decimal(isset($row['per_kg']) ? $row['per_kg'] : 0));

			/*
			 * La fila vacía no se guarda. El formulario siempre dibuja una de más
			 * para que haya dónde escribir, y si se guardaba tal cual quedaba una
			 * regla "cualquier destino → $0" que después cobraba el envío gratis
			 * sin que nadie la hubiera escrito. Vale también para limpiar las que
			 * ya estén guardadas: se van al próximo guardado.
			 */
			if ('todos' === $scope && '' === $match && 0.0 === (float) $price && 0.0 === (float) $perkg && 0.0 === (float) $weight) {
				continue;
			}

			$clean[] = [
				'scope'      => $scope,
				'match'      => $match,
				'weight_max' => $weight,
				'price'      => $price,
				'per_kg'     => $perkg,
			];
		}
		/* Primero las filas más específicas y con el tope de peso más chico. */
		usort($clean, function ($a, $b) {
			$rank = ['cp' => 0, 'localidad' => 1, 'provincia' => 2, 'todos' => 3];
			if ($rank[$a['scope']] !== $rank[$b['scope']]) {
				return $rank[$a['scope']] < $rank[$b['scope']] ? -1 : 1;
			}
			$aw = $a['weight_max'] > 0 ? $a['weight_max'] : PHP_INT_MAX;
			$bw = $b['weight_max'] > 0 ? $b['weight_max'] : PHP_INT_MAX;
			if ($aw === $bw) {
				return 0;
			}
			return $aw < $bw ? -1 : 1;
		});
		return $clean;
	}

	/** "1000 - 1999, 5000; B7600" → "1000-1999,5000,B7600". */
	public static function sanitize_cp_list($raw) {
		if (is_array($raw)) {
			$raw = implode(',', $raw);
		}
		$out = [];
		foreach (preg_split('/[;,\n\r]+/', (string) $raw) as $part) {
			$part = strtoupper(trim($part));
			if ('' === $part) {
				continue;
			}
			if (preg_match('/^\s*([A-Z0-9]+)\s*-\s*([A-Z0-9]+)\s*$/', $part, $m)) {
				$out[] = $m[1] . '-' . $m[2];
				continue;
			}
			$code = preg_replace('/[^A-Z0-9\*]/', '', $part);
			if ('' !== $code) {
				$out[] = $code;
			}
		}
		return implode(',', array_unique($out));
	}

	/* ----------------------------- Condiciones --------------------------- */

	/** ¿El CP entra en una lista con rangos y comodines? */
	public static function cp_matches($cp, $list) {
		$cp    = MJE_Geo::normalize_cp($cp);
		$parts = MJE_Settings::to_array($list);
		if ('' === $cp || !$parts) {
			return false;
		}
		$numeric = preg_replace('/\D+/', '', $cp);
		foreach ($parts as $part) {
			$part = strtoupper(trim($part));
			if (false !== strpos($part, '-')) {
				list($from, $to) = array_pad(explode('-', $part, 2), 2, '');
				$from = preg_replace('/\D+/', '', $from);
				$to   = preg_replace('/\D+/', '', $to);
				if ('' !== $from && '' !== $to && '' !== $numeric && (int) $numeric >= (int) $from && (int) $numeric <= (int) $to) {
					return true;
				}
				continue;
			}
			if (false !== strpos($part, '*')) {
				$regex = '/^' . str_replace('\*', '.*', preg_quote($part, '/')) . '$/';
				if (preg_match($regex, $cp)) {
					return true;
				}
				continue;
			}
			if ($part === $cp || ('' !== $numeric && preg_replace('/\D+/', '', $part) === $numeric)) {
				return true;
			}
		}
		return false;
	}

	/** ¿Alguno de estos nombres coincide con el texto? (sin acentos ni mayúsculas) */
	private static function name_matches($needle, $list) {
		$key = MJE_Settings::key_of($needle);
		if ('' === $key) {
			return false;
		}
		foreach (MJE_Settings::to_array($list) as $item) {
			if (MJE_Settings::key_of($item) === $key) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ¿Este método se puede ofrecer para este paquete?
	 *
	 * $ctx trae lo que no está en el paquete: el medio de pago vigente y los
	 * datos que el cliente ya eligió (sucursal, localidad).
	 */
	public static function passes(array $def, array $package, array $ctx = []) {
		if (empty($def['enabled'])) {
			return false;
		}

		$destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : [];
		$country     = isset($destination['country']) ? strtoupper((string) $destination['country']) : '';
		$state       = isset($destination['state']) ? (string) $destination['state'] : '';
		$city        = isset($destination['city']) ? (string) $destination['city'] : '';
		$cp          = isset($destination['postcode']) ? MJE_Geo::normalize_cp($destination['postcode']) : '';

		/* Destino. El retiro en el local no mira la dirección del cliente. */
		if ('local' !== $def['destination']) {
			if ('' !== (string) $def['countries'] && '' !== $country && !in_array($country, MJE_Settings::to_array(strtoupper($def['countries'])), true)) {
				return false;
			}
			if ('' !== (string) $def['states'] && !(self::name_matches($state, $def['states']) || self::name_matches(MJE_Geo::state_name($state, $country), $def['states']))) {
				return false;
			}
			if ('' !== (string) $def['states_exclude'] && (self::name_matches($state, $def['states_exclude']) || self::name_matches(MJE_Geo::state_name($state, $country), $def['states_exclude']))) {
				return false;
			}
			if ('' !== (string) $def['cities'] && !self::name_matches($city, $def['cities'])) {
				return false;
			}
			if ('' !== (string) $def['cities_exclude'] && self::name_matches($city, $def['cities_exclude'])) {
				return false;
			}
			if ('' !== (string) $def['cp_include'] && !self::cp_matches($cp, $def['cp_include'])) {
				return false;
			}
			if ('' !== (string) $def['cp_exclude'] && self::cp_matches($cp, $def['cp_exclude'])) {
				return false;
			}
		}

		/* Carrito: monto, peso y cantidad. */
		$subtotal = isset($ctx['subtotal']) ? (float) $ctx['subtotal'] : MJE_Rates::package_subtotal($package);
		$weight   = isset($ctx['weight']) ? (float) $ctx['weight'] : MJE_Rates::package_weight($package);
		$qty      = 0;
		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$qty += isset($item['quantity']) ? (int) $item['quantity'] : 0;
		}
		if ((float) $def['min_total'] > 0 && $subtotal < (float) $def['min_total']) {
			return false;
		}
		if ((float) $def['max_total'] > 0 && $subtotal > (float) $def['max_total']) {
			return false;
		}
		if ((float) $def['min_weight'] > 0 && $weight < (float) $def['min_weight']) {
			return false;
		}
		if ((float) $def['max_weight'] > 0 && $weight > (float) $def['max_weight']) {
			return false;
		}
		if ((int) $def['min_qty'] > 0 && $qty < (int) $def['min_qty']) {
			return false;
		}
		if ((int) $def['max_qty'] > 0 && $qty > (int) $def['max_qty']) {
			return false;
		}

		/* Contenido: productos y categorías. */
		if (!self::contents_pass($def, $package)) {
			return false;
		}

		/* Quién compra. */
		if ('logueados' === $def['users'] && !is_user_logged_in()) {
			return false;
		}
		if ('invitados' === $def['users'] && is_user_logged_in()) {
			return false;
		}
		if ('roles' === $def['users']) {
			$roles = MJE_Settings::to_array($def['roles']);
			$user  = wp_get_current_user();
			if (!$user || !$user->ID || !array_intersect($roles, (array) $user->roles)) {
				return false;
			}
		}

		/* Medio de pago vigente. */
		$gateway = isset($ctx['gateway']) ? (string) $ctx['gateway'] : MJE_Cart::chosen_gateway();
		if ('' !== $gateway && !self::allows_gateway($def, $gateway)) {
			return false;
		}

		return (bool) apply_filters('mje_method_available', true, $def, $package, $ctx);
	}

	/** Productos y categorías del carrito contra las listas del método. */
	private static function contents_pass(array $def, array $package) {
		$include_p = MJE_Settings::to_array($def['products']);
		$exclude_p = MJE_Settings::to_array($def['products_exclude']);
		$include_c = MJE_Settings::to_array($def['cats']);
		$exclude_c = MJE_Settings::to_array($def['cats_exclude']);
		if (!$include_p && !$exclude_p && !$include_c && !$exclude_c) {
			return true;
		}

		$ids  = [];
		$cats = [];
		foreach ((array) (isset($package['contents']) ? $package['contents'] : []) as $item) {
			$product = isset($item['data']) ? $item['data'] : null;
			if (!$product instanceof WC_Product) {
				continue;
			}
			$parent = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
			$ids[]  = $parent;
			$ids[]  = (int) $product->get_id();
			foreach (wc_get_product_term_ids($parent, 'product_cat') as $term_id) {
				$term = get_term($term_id, 'product_cat');
				if ($term && !is_wp_error($term)) {
					$cats[] = (string) $term->slug;
					$cats[] = (string) $term_id;
				}
			}
		}
		$ids  = array_unique(array_map('strval', $ids));
		$cats = array_unique($cats);

		/* "Solo estos": alcanza con que el carrito tenga uno. */
		if ($include_p && !array_intersect($include_p, $ids)) {
			return false;
		}
		if ($include_c && !array_intersect($include_c, $cats)) {
			return false;
		}
		/* "Nunca con estos": si aparece uno, el método no se ofrece. */
		if ($exclude_p && array_intersect($exclude_p, $ids)) {
			return false;
		}
		if ($exclude_c && array_intersect($exclude_c, $cats)) {
			return false;
		}
		return true;
	}

	/** ¿Este método admite ese medio de pago? (la misma regla filtra en los dos sentidos) */
	public static function allows_gateway(array $def, $gateway_id) {
		$gateway = sanitize_key((string) $gateway_id);
		$list    = MJE_Settings::to_array($def['payments']);
		if ('' === $gateway || 'todos' === $def['payments_mode'] || !$list) {
			return true;
		}
		$in = in_array($gateway, $list, true);
		return 'solo' === $def['payments_mode'] ? $in : !$in;
	}

	/** Los métodos que pasan condiciones para un paquete, en orden. */
	public static function available(array $package, array $ctx = []) {
		$out = [];
		foreach (self::enabled() as $slug => $def) {
			if (self::passes($def, $package, $ctx)) {
				$out[$slug] = $def;
			}
		}
		return $out;
	}

	/* ------------------------------- Textos ------------------------------ */

	public static function name(array $def) {
		$title = trim((string) $def['title']);
		$icon  = trim((string) $def['icon']);
		return '' !== $icon ? $icon . ' ' . $title : $title;
	}

	public static function short(array $def) {
		return '' !== trim((string) $def['short']) ? trim((string) $def['short']) : trim((string) $def['title']);
	}

	/** Plazo prometido, con los textos generales y sus reemplazos. */
	public static function eta(array $def) {
		$min = (int) $def['days_min'];
		$max = (int) $def['days_max'];
		if ($min <= 0 && $max <= 0) {
			return '';
		}
		$text = trim((string) $def['eta_text']);
		if ('' === $text) {
			$rango = $min > 0 && $max > 0 && $min !== $max;
			$dia   = !$rango && 1 === ($max > 0 ? $max : $min);
			/* Un solo día tiene su propio texto: "1 días hábiles" no lo escribe nadie. */
			if ($rango) {
				$text = (string) MJE_Settings::get('eta_text');
			} elseif ($dia) {
				$text = (string) MJE_Settings::get('eta_text_day');
			} else {
				$text = (string) MJE_Settings::get('eta_text_one');
			}
		}
		return trim(strtr($text, [
			'{min}' => (string) ($min > 0 ? $min : $max),
			'{max}' => (string) ($max > 0 ? $max : $min),
		]));
	}

	/* --------------------------- Config pública -------------------------- */

	/**
	 * Lo que ve la tienda (window.MJ.shipping y GET /mje/v1/config).
	 *
	 * No lleva precios: esos salen del carrito, que es el único que conoce el
	 * destino real. Acá va lo que la tienda necesita para dibujar el paso.
	 */
	public static function public_payload() {
		$s       = MJE_Settings::get();
		$methods = [];
		foreach (self::enabled() as $slug => $def) {
			$methods[] = [
				'slug'        => $slug,
				'title'       => (string) $def['title'],
				'short'       => self::short($def),
				'description' => (string) $def['description'],
				'icon'        => (string) $def['icon'],
				'type'        => (string) $def['type'],
				'destination' => (string) $def['destination'],
				'carrier'     => (string) $def['carrier'],
				'group'       => (string) $def['group'],
				'eta'         => self::eta($def),
				'fields'      => MJE_Fields::public_payload($def),
				'needsBranch' => 'sucursal' === $def['destination'],
				'payments'    => [
					'mode' => (string) $def['payments_mode'],
					'list' => MJE_Settings::to_array($def['payments']),
				],
				'instructions' => (string) $def['instructions'],
			];
		}

		return [
			'enabled'   => (bool) $s['enabled'],
			'country'   => MJE_Geo::country(),
			'states'    => MJE_Geo::states(),
			'methods'   => $methods,
			'groups'    => MJE_Groups::public_payload(),
			'labels'    => MJE_Fields::labels(),
			'cp'        => [
				'label'    => (string) $s['cp_label'],
				'help'     => (string) $s['cp_help'],
				'ask'      => (string) $s['cp_ask'],
				'invalid'  => (string) $s['cp_invalid'],
				'required' => (bool) $s['cp_required'],
				'strict'   => (bool) $s['cp_strict'],
				'minLen'   => (int) $s['cp_min_len'],
				'maxLen'   => (int) $s['cp_max_len'],
			],
			'texts'     => [
				'stepTitle'  => (string) $s['step_title'],
				'stepIntro'  => (string) $s['step_intro'],
				'none'       => (string) $s['none_text'],
				'coordinate' => (string) $s['coordinate_text'],
				'error'      => (string) $s['error_text'],
				'free'       => (string) $s['free_label'],
				'branch'     => (string) $s['branch_label'],
				'branchHelp' => (string) $s['branch_help'],
				'pickup'     => (string) $s['pickup_label'],
			],
			'quote'     => [
				'enabled' => (bool) $s['quote_product'],
				'title'   => (string) $s['quote_title'],
				'button'  => (string) $s['quote_button'],
				'help'    => (string) $s['quote_help'],
			],
			'freeFrom'  => (float) MJE_Rates::free_threshold(),
			'pickup'    => MJE_Settings::origin(),
		];
	}
}
