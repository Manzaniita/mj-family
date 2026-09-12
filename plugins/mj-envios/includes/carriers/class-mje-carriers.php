<?php
/**
 * Registro de correos y caché de cotizaciones.
 *
 * Los tres que vienen (Vía Cargo, Andreani, Correo Argentino) se registran acá,
 * y sumar otro es engancharse al filtro mje_carriers con una clase que extienda
 * MJE_Carrier:
 *
 *     add_filter('mje_carriers', function ($all) {
 *         $all['oca'] = 'Mi_Carrier_OCA';
 *         return $all;
 *     });
 *
 * Toda cotización pasa por la caché de la tabla wp_mje_quotes: el mismo destino,
 * peso y valor no se pregunta dos veces mientras no venza (ajuste cache_hours).
 * Los errores también se guardan, con una vida más corta, para que una API caída
 * no haga esperar a cada visitante del checkout.
 */

defined('ABSPATH') || exit;

class MJE_Carriers {

	/** Vida de una cotización fallida: corta, para reintentar pronto sin castigar al cliente. */
	const ERROR_TTL = 300;

	private static $instances = null;

	public static function init() {
		/* Nada que engancharse: el registro se arma la primera vez que se lo pide. */
	}

	/** Clase de cada correo: clave => nombre de clase. */
	public static function classes() {
		return (array) apply_filters('mje_carriers', [
			'viacargo' => 'MJE_Carrier_ViaCargo',
			'andreani' => 'MJE_Carrier_Andreani',
			'correo'   => 'MJE_Carrier_Correo',
		]);
	}

	/** Todas las instancias: clave => objeto. */
	public static function all() {
		if (null === self::$instances) {
			self::$instances = [];
			foreach (self::classes() as $id => $class) {
				if (is_string($class) && class_exists($class)) {
					$carrier = new $class();
					if ($carrier instanceof MJE_Carrier) {
						self::$instances[(string) $id] = $carrier;
					}
				}
			}
		}
		return self::$instances;
	}

	public static function get($id) {
		$all = self::all();
		$id  = sanitize_key((string) $id);
		return isset($all[$id]) ? $all[$id] : null;
	}

	/** Para los selectores del panel: clave => nombre. */
	public static function choices() {
		$out = [];
		foreach (self::all() as $id => $carrier) {
			$out[$id] = $carrier->label();
		}
		return $out;
	}

	/** Solo los que tienen sus credenciales cargadas. */
	public static function connected() {
		return array_filter(self::all(), function ($carrier) {
			return $carrier->connected();
		});
	}

	/** Servicios de un correo (para el método de envío). */
	public static function services($id) {
		$carrier = self::get($id);
		return $carrier ? $carrier->services() : [];
	}

	/* ----------------------------- Cotización ---------------------------- */

	/**
	 * Cotiza con un correo, pasando por la caché.
	 *
	 * @return array|WP_Error
	 */
	public static function quote($id, array $shipment) {
		$carrier = self::get($id);
		if (!$carrier) {
			return new WP_Error('mje_no_carrier', 'Ese correo no está disponible.');
		}
		if (!$carrier->connected()) {
			return new WP_Error('mje_not_connected', sprintf('%s todavía no tiene sus credenciales cargadas.', $carrier->label()));
		}

		$hash   = self::hash($id, $shipment);
		$cached = self::cached($hash);
		if (null !== $cached) {
			return $cached;
		}

		$result = $carrier->quote($shipment);
		self::store($hash, $id, $shipment, $result);
		return $result;
	}

	private static function hash($id, array $shipment) {
		$origin = isset($shipment['origin']) ? (array) $shipment['origin'] : [];
		$dest   = isset($shipment['dest']) ? (array) $shipment['dest'] : [];
		$parts  = [
			$id,
			isset($shipment['service']) ? $shipment['service'] : '',
			isset($origin['postcode']) ? $origin['postcode'] : '',
			isset($origin['branch']) ? $origin['branch'] : '',
			isset($dest['postcode']) ? $dest['postcode'] : '',
			isset($dest['branch']) ? $dest['branch'] : '',
			round((float) (isset($shipment['weight']) ? $shipment['weight'] : 0), 2),
			(int) (isset($shipment['packages']) ? $shipment['packages'] : 1),
			(int) (isset($shipment['length']) ? $shipment['length'] : 0),
			(int) (isset($shipment['width']) ? $shipment['width'] : 0),
			(int) (isset($shipment['height']) ? $shipment['height'] : 0),
			/* El valor declarado se agrupa: mover $100 no justifica otra consulta. */
			round((float) (isset($shipment['value']) ? $shipment['value'] : 0) / 1000),
		];
		return sha1(implode('|', $parts));
	}

	/** Lo guardado para ese hash, si no venció. null = hay que preguntar. */
	private static function cached($hash) {
		if (!MJE_Install::tables_exist()) {
			return null;
		}
		global $wpdb;
		$table = MJE_Install::table_quotes();
		$row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE hash = %s", $hash), ARRAY_A); // phpcs:ignore WordPress.DB
		if (!$row) {
			return null;
		}
		if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
			return null;
		}
		if (!empty($row['error'])) {
			return new WP_Error('mje_cached_error', (string) $row['error']);
		}
		return [
			'cost'     => (float) $row['cost'],
			'days_min' => (int) $row['days_min'],
			'days_max' => (int) $row['days_max'],
			'service'  => (string) $row['method'],
			'raw'      => [],
			'cached'   => true,
		];
	}

	private static function store($hash, $id, array $shipment, $result) {
		if (!MJE_Install::tables_exist()) {
			return;
		}
		$hours = (int) MJE_Settings::get('cache_hours');
		$ttl   = is_wp_error($result) ? self::ERROR_TTL : $hours * HOUR_IN_SECONDS;
		if ($ttl <= 0) {
			return; // Caché apagada.
		}
		$now  = current_time('mysql', true);
		$data = [
			'hash'       => $hash,
			'carrier'    => sanitize_key($id),
			'method'     => isset($shipment['service']) ? (string) $shipment['service'] : '',
			'cost'       => is_wp_error($result) ? 0 : (float) $result['cost'],
			'days_min'   => is_wp_error($result) ? 0 : (int) $result['days_min'],
			'days_max'   => is_wp_error($result) ? 0 : (int) $result['days_max'],
			'error'      => is_wp_error($result) ? mb_substr($result->get_error_message(), 0, 250) : null,
			'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
			'created_at' => $now,
		];

		global $wpdb;
		$table = MJE_Install::table_quotes();
		$id_db = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE hash = %s", $hash)); // phpcs:ignore WordPress.DB
		if ($id_db) {
			$wpdb->update($table, $data, ['id' => $id_db]); // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->insert($table, $data); // phpcs:ignore WordPress.DB
	}

	/** Borra la caché de cotizaciones (el panel lo ofrece con un botón). */
	public static function flush_quotes() {
		if (!MJE_Install::tables_exist()) {
			return;
		}
		global $wpdb;
		$table = MJE_Install::table_quotes();
		$wpdb->query("TRUNCATE TABLE {$table}"); // phpcs:ignore WordPress.DB
	}

	/* ----------------------------- Sucursales ---------------------------- */

	/**
	 * Sucursales de un correo, de la tabla propia y, si está vacía, de su API.
	 *
	 * Lo que trae la API queda guardado: el checkout nunca depende de que el
	 * correo esté arriba para poder mostrar la lista.
	 */
	public static function branches($carrier_id, $postcode = '', $refresh = false) {
		$carrier_id = sanitize_key((string) $carrier_id);
		if ('' === $carrier_id || !MJE_Install::tables_exist()) {
			return [];
		}
		if (!$refresh) {
			$rows = self::stored_branches($carrier_id, $postcode);
			if ($rows) {
				return $rows;
			}
		}
		$carrier = self::get($carrier_id);
		if (!$carrier || !$carrier->connected()) {
			return self::stored_branches($carrier_id, '');
		}
		$fetched = $carrier->branches($postcode);
		foreach ($fetched as $branch) {
			self::save_branch($carrier_id, $branch, 'api');
		}
		$rows = self::stored_branches($carrier_id, $postcode);
		return $rows ? $rows : $fetched;
	}

	/** Las sucursales guardadas, opcionalmente filtradas por CP. */
	public static function stored_branches($carrier_id, $postcode = '') {
		if (!MJE_Install::tables_exist()) {
			return [];
		}
		global $wpdb;
		$table = MJE_Install::table_branches();
		$sql   = "SELECT * FROM {$table} WHERE carrier = %s AND enabled = 1";
		$args  = [sanitize_key($carrier_id)];
		if ('' !== $postcode) {
			$sql   .= ' AND postcode = %s';
			$args[] = MJE_Geo::normalize_cp($postcode);
		}
		$sql .= ' ORDER BY name ASC LIMIT 300';
		$rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A); // phpcs:ignore WordPress.DB
		$out  = [];
		foreach ((array) $rows as $row) {
			$out[] = [
				'code'     => (string) $row['code'],
				'name'     => (string) $row['name'],
				'address'  => (string) $row['address'],
				'city'     => (string) $row['city'],
				'state'    => (string) $row['state'],
				'postcode' => (string) $row['postcode'],
				'hours'    => (string) $row['hours'],
			];
		}
		return $out;
	}

	/** Guarda (o actualiza) una sucursal. Se usa desde la API y desde el panel. */
	public static function save_branch($carrier_id, array $branch, $source = 'manual') {
		if (!MJE_Install::tables_exist()) {
			return false;
		}
		$carrier_id = sanitize_key((string) $carrier_id);
		$name       = trim(sanitize_text_field((string) (isset($branch['name']) ? $branch['name'] : '')));
		$code       = trim(sanitize_text_field((string) (isset($branch['code']) ? $branch['code'] : '')));
		if ('' === $code) {
			$code = sanitize_title($name);
		}
		if ('' === $carrier_id || '' === $code || '' === $name) {
			return false;
		}
		$data = [
			'carrier'    => $carrier_id,
			'code'       => mb_substr($code, 0, 60),
			'name'       => mb_substr($name, 0, 190),
			'address'    => mb_substr(trim(sanitize_text_field((string) (isset($branch['address']) ? $branch['address'] : ''))), 0, 250),
			'city'       => mb_substr(trim(sanitize_text_field((string) (isset($branch['city']) ? $branch['city'] : ''))), 0, 160),
			'state'      => mb_substr(trim(sanitize_text_field((string) (isset($branch['state']) ? $branch['state'] : ''))), 0, 120),
			'postcode'   => MJE_Geo::normalize_cp(isset($branch['postcode']) ? $branch['postcode'] : ''),
			'hours'      => mb_substr(trim(sanitize_text_field((string) (isset($branch['hours']) ? $branch['hours'] : ''))), 0, 250),
			'enabled'    => isset($branch['enabled']) && !$branch['enabled'] ? 0 : 1,
			'source'     => sanitize_key($source),
			'updated_at' => current_time('mysql', true),
		];

		global $wpdb;
		$table = MJE_Install::table_branches();
		$id    = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE carrier = %s AND code = %s", $data['carrier'], $data['code'])); // phpcs:ignore WordPress.DB
		if ($id) {
			return false !== $wpdb->update($table, $data, ['id' => $id]); // phpcs:ignore WordPress.DB
		}
		return false !== $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB
	}

	public static function delete_branch($carrier_id, $code) {
		if (!MJE_Install::tables_exist()) {
			return false;
		}
		global $wpdb;
		$table = MJE_Install::table_branches();
		return false !== $wpdb->delete($table, ['carrier' => sanitize_key($carrier_id), 'code' => (string) $code]); // phpcs:ignore WordPress.DB
	}

	/** Nombre legible de una sucursal guardada (para el pedido y los emails). */
	public static function branch_name($carrier_id, $code) {
		foreach (self::stored_branches($carrier_id) as $branch) {
			if ($branch['code'] === (string) $code) {
				$parts = array_filter([$branch['name'], $branch['address'], $branch['city']]);
				return implode(' · ', $parts);
			}
		}
		return (string) $code;
	}

	/* ------------------------------- Geo --------------------------------- */

	/** Primer correo conectado que sepa resolver un CP. */
	public static function resolve_cp($postcode, $country = '') {
		foreach (self::connected() as $carrier) {
			$rows = $carrier->resolve_cp($postcode);
			if ($rows) {
				$out = [];
				foreach ($rows as $row) {
					$out[] = [
						'city'       => (string) (isset($row['city']) ? $row['city'] : ''),
						'state'      => (string) (isset($row['state']) ? $row['state'] : ''),
						'state_code' => MJE_Geo::state_code(isset($row['state']) ? $row['state'] : '', $country),
						'postcode'   => MJE_Geo::normalize_cp($postcode),
					];
				}
				return $out;
			}
		}
		return [];
	}

	/** Localidades según los correos conectados que lo resuelvan. */
	public static function cities($query, $state = '', $country = '') {
		foreach (self::connected() as $carrier) {
			$rows = $carrier->cities($query, $state);
			if ($rows) {
				return $rows;
			}
		}
		return [];
	}

	/** Estado de un envío, preguntándole al correo del método. */
	public static function track($carrier_id, $code) {
		$carrier = self::get($carrier_id);
		if (!$carrier || !$carrier->connected()) {
			return [];
		}
		return $carrier->track($code);
	}
}
