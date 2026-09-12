<?php
/**
 * Código postal, provincia y localidad.
 *
 * Tres proveedores, y el orden en el que se preguntan lo elegís vos en los
 * ajustes (mje_settings → geo_providers):
 *
 *   tabla    La tabla propia del plugin (wp_mje_geo). Instantánea, sin
 *            internet y sin credenciales. Se carga importando un CSV desde el
 *            panel y se va llenando sola: todo lo que resuelve otro proveedor
 *            (o lo que el cliente elige a mano) queda guardado acá.
 *   georef   La API pública del Estado, apis.datos.gob.ar/georef. No pide
 *            credenciales ni tiene límite práctico. Resuelve provincias y
 *            localidades POR NOMBRE: es lo que hace andar el autocompletado de
 *            localidad y la validación de la provincia. Georef no publica
 *            códigos postales, así que un CP que no esté en la tabla se
 *            completa eligiendo la localidad (y ahí queda aprendido).
 *   correo   El correo conectado, si su API resuelve CP. Sin credenciales,
 *            este proveedor se saltea solo.
 *
 * Las provincias salen de WooCommerce (los estados del país base), no de una
 * lista escrita acá. Si WooCommerce no tiene estados para ese país, se usan las
 * que haya en la tabla propia.
 */

defined('ABSPATH') || exit;

class MJE_Geo {

	/** Proveedores disponibles, en el orden en que se ofrecen en el panel. */
	public static function providers() {
		return [
			'tabla'  => 'La tabla propia del plugin (instantánea, sin internet)',
			'georef' => 'API pública del Estado — Georef (localidades y provincias por nombre)',
			'correo' => 'El correo conectado, si su API resuelve códigos postales',
		];
	}

	/** Los proveedores activos, en el orden configurado. */
	public static function active_providers() {
		$list = MJE_Settings::to_array(MJE_Settings::get('geo_providers'));
		return $list ? $list : ['tabla'];
	}

	public static function country() {
		$origin = MJE_Settings::origin();
		return '' !== $origin['country'] ? $origin['country'] : 'AR';
	}

	/* ----------------------------- Provincias ---------------------------- */

	/** Provincias del país: código => nombre. */
	public static function states($country = '') {
		$country = '' !== $country ? strtoupper($country) : self::country();
		$states  = [];
		if (function_exists('WC') && WC()->countries) {
			$all = WC()->countries->get_states($country);
			if (is_array($all)) {
				$states = $all;
			}
		}
		if (!$states) {
			$states = self::table_states($country);
		}
		return $states;
	}

	/** Provincias distintas que haya en la tabla propia (respaldo). */
	private static function table_states($country) {
		if (!MJE_Install::tables_exist()) {
			return [];
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		$rows  = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT state_code, state FROM {$table} WHERE country = %s AND state <> '' ORDER BY state ASC", $country), ARRAY_A); // phpcs:ignore WordPress.DB
		$out   = [];
		foreach ((array) $rows as $row) {
			$code = (string) $row['state_code'];
			$name = (string) $row['state'];
			if ('' === $name) {
				continue;
			}
			$out['' !== $code ? $code : $name] = $name;
		}
		return $out;
	}

	public static function state_name($code, $country = '') {
		$states = self::states($country);
		$code   = (string) $code;
		if (isset($states[$code])) {
			return (string) $states[$code];
		}
		/* Puede venir el nombre en lugar del código: se devuelve tal cual si existe. */
		foreach ($states as $name) {
			if (MJE_Settings::key_of($name) === MJE_Settings::key_of($code)) {
				return (string) $name;
			}
		}
		return $code;
	}

	/** Código de provincia a partir del nombre (o del código mismo). */
	public static function state_code($name, $country = '') {
		$states = self::states($country);
		$name   = (string) $name;
		if ('' === $name) {
			return '';
		}
		if (isset($states[$name])) {
			return $name;
		}
		$key = MJE_Settings::key_of($name);
		foreach ($states as $code => $label) {
			if (MJE_Settings::key_of($label) === $key) {
				return (string) $code;
			}
		}
		return '';
	}

	/* --------------------------- Código postal --------------------------- */

	public static function normalize_cp($cp) {
		return MJE_Settings::postcode($cp);
	}

	public static function cp_valid($cp) {
		$cp  = self::normalize_cp($cp);
		$len = strlen($cp);
		return $len >= (int) MJE_Settings::get('cp_min_len') && $len <= (int) MJE_Settings::get('cp_max_len');
	}

	/**
	 * Qué hay en un código postal.
	 *
	 * → ['postcode','state','state_code','cities' => [...], 'source', 'found']
	 *
	 * `found` en false significa "el CP tiene forma válida pero no sabemos qué
	 * localidad es": la tienda muestra el selector de provincia y localidad, y
	 * lo que el cliente elija queda aprendido (ajuste geo_learn).
	 */
	public static function resolve_cp($cp, $country = '') {
		$country = '' !== $country ? strtoupper($country) : self::country();
		$cp      = self::normalize_cp($cp);
		$out     = [
			'postcode'   => $cp,
			'state'      => '',
			'state_code' => '',
			'cities'     => [],
			'source'     => '',
			'found'      => false,
			'valid'      => self::cp_valid($cp),
		];
		if (!$out['valid']) {
			return apply_filters('mje_geo_cp', $out, $cp, $country);
		}

		foreach (self::active_providers() as $provider) {
			$rows = [];
			if ('tabla' === $provider) {
				$rows = self::table_by_cp($cp, $country);
			} elseif ('correo' === $provider) {
				$rows = MJE_Carriers::resolve_cp($cp, $country);
			}
			/* georef no publica códigos postales: solo alimenta el autocompletado por nombre. */
			if (!$rows) {
				continue;
			}
			$out['cities']     = $rows;
			$out['state']      = (string) $rows[0]['state'];
			$out['state_code'] = (string) $rows[0]['state_code'];
			$out['source']     = $provider;
			$out['found']      = true;
			if ('tabla' !== $provider && MJE_Settings::get('geo_learn')) {
				foreach ($rows as $row) {
					self::remember($cp, $row['city'], $row['state'], $provider, $country);
				}
			}
			break;
		}

		return apply_filters('mje_geo_cp', $out, $cp, $country);
	}

	/** Filas de la tabla propia para un CP. */
	private static function table_by_cp($cp, $country) {
		if (!MJE_Install::tables_exist()) {
			return [];
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		$rows  = $wpdb->get_results($wpdb->prepare("SELECT city, state, state_code FROM {$table} WHERE country = %s AND postcode = %s ORDER BY hits DESC, city ASC LIMIT 50", $country, $cp), ARRAY_A); // phpcs:ignore WordPress.DB
		$out   = [];
		foreach ((array) $rows as $row) {
			$out[] = [
				'city'       => (string) $row['city'],
				'state'      => (string) $row['state'],
				'state_code' => (string) $row['state_code'],
				'postcode'   => $cp,
			];
		}
		return $out;
	}

	/* ---------------------------- Localidades ---------------------------- */

	/**
	 * Localidades que coinciden con un texto, para el autocompletado.
	 *
	 * Se pregunta a los proveedores activos en orden y se juntan los resultados
	 * sin repetir: la tabla propia primero da respuesta instantánea, y Georef
	 * completa lo que todavía no está aprendido.
	 */
	public static function cities($query, $state = '', $country = '') {
		$country = '' !== $country ? strtoupper($country) : self::country();
		$query   = trim((string) $query);
		if (mb_strlen($query) < 2) {
			return [];
		}
		$out  = [];
		$seen = [];
		$push = function ($city, $state_name, $postcode, $source) use (&$out, &$seen, $country) {
			$city = trim((string) $city);
			if ('' === $city) {
				return;
			}
			$key = MJE_Settings::key_of($city) . '|' . MJE_Settings::key_of($state_name);
			if (isset($seen[$key])) {
				return;
			}
			$seen[$key] = true;
			$out[]      = [
				'city'       => $city,
				'state'      => (string) $state_name,
				'state_code' => self::state_code($state_name, $country),
				'postcode'   => (string) $postcode,
				'source'     => $source,
			];
		};

		foreach (self::active_providers() as $provider) {
			if ('tabla' === $provider) {
				foreach (self::table_by_name($query, $state, $country) as $row) {
					$push($row['city'], $row['state'], $row['postcode'], 'tabla');
				}
			} elseif ('georef' === $provider) {
				foreach (self::georef_cities($query, $state) as $row) {
					$push($row['city'], $row['state'], '', 'georef');
				}
			} elseif ('correo' === $provider) {
				foreach (MJE_Carriers::cities($query, $state, $country) as $row) {
					$push($row['city'], $row['state'], isset($row['postcode']) ? $row['postcode'] : '', 'correo');
				}
			}
			if (count($out) >= 20) {
				break;
			}
		}

		return array_slice($out, 0, 20);
	}

	private static function table_by_name($query, $state, $country) {
		if (!MJE_Install::tables_exist()) {
			return [];
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		$like  = '%' . $wpdb->esc_like(MJE_Settings::key_of($query)) . '%';
		$sql   = "SELECT city, state, state_code, postcode FROM {$table} WHERE country = %s AND norm LIKE %s";
		$args  = [$country, $like];
		if ('' !== $state) {
			$sql   .= ' AND (state = %s OR state_code = %s)';
			$args[] = self::state_name($state, $country);
			$args[] = self::state_code($state, $country);
		}
		$sql .= ' ORDER BY hits DESC, city ASC LIMIT 20';
		$rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A); // phpcs:ignore WordPress.DB
		return array_map(function ($row) {
			return [
				'city'     => (string) $row['city'],
				'state'    => (string) $row['state'],
				'postcode' => (string) $row['postcode'],
			];
		}, (array) $rows);
	}

	/** Georef: /localidades?nombre=&provincia=. Devuelve [{city, state}]. */
	private static function georef_cities($query, $state = '') {
		$args = ['nombre' => $query, 'max' => 15, 'campos' => 'id,nombre,provincia'];
		if ('' !== $state) {
			$args['provincia'] = self::state_name($state);
		}
		$data = self::georef('/localidades', $args);
		$rows = isset($data['localidades']) && is_array($data['localidades']) ? $data['localidades'] : [];
		$out  = [];
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['nombre'])) {
				continue;
			}
			$out[] = [
				'city'  => (string) $row['nombre'],
				'state' => isset($row['provincia']['nombre']) ? (string) $row['provincia']['nombre'] : '',
			];
		}
		return $out;
	}

	/** Provincias según Georef (respaldo si WooCommerce no tiene estados del país). */
	public static function georef_states() {
		$data = self::georef('/provincias', ['campos' => 'id,nombre', 'max' => 30]);
		$rows = isset($data['provincias']) && is_array($data['provincias']) ? $data['provincias'] : [];
		$out  = [];
		foreach ($rows as $row) {
			if (is_array($row) && !empty($row['nombre'])) {
				$out[(string) $row['nombre']] = (string) $row['nombre'];
			}
		}
		return $out;
	}

	/**
	 * Llamada a Georef, con caché.
	 *
	 * La URL base es un ajuste: si la API cambia de dominio, se corrige desde el
	 * panel y no hay que tocar el plugin. Si no hay URL configurada, el
	 * proveedor queda apagado.
	 */
	private static function georef($path, array $args) {
		$base = (string) MJE_Settings::get('geo_url');
		if ('' === $base) {
			return [];
		}
		$url = $base . $path . '?' . http_build_query($args);
		$key = 'mje_geo_' . md5($url);
		$hit = get_transient($key);
		if (is_array($hit)) {
			return $hit;
		}
		$response = wp_remote_get($url, [
			'timeout'     => (int) MJE_Settings::get('timeout'),
			'redirection' => 2,
			'headers'     => ['Accept' => 'application/json'],
		]);
		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			self::log('georef', is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response));
			/* Caché corta del fallo: no se castiga cada pulsación de tecla con una espera. */
			set_transient($key, [], 5 * MINUTE_IN_SECONDS);
			return [];
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		$data = is_array($data) ? $data : [];
		set_transient($key, $data, max(1, (int) MJE_Settings::get('geo_cache_days')) * DAY_IN_SECONDS);
		return $data;
	}

	/* ------------------------------ Aprender ----------------------------- */

	/** Guarda (o refresca) un par CP + localidad en la tabla propia. */
	public static function remember($cp, $city, $state, $source = 'manual', $country = '') {
		if (!MJE_Install::tables_exist()) {
			return false;
		}
		$country = '' !== $country ? strtoupper($country) : self::country();
		$cp      = self::normalize_cp($cp);
		$city    = trim(sanitize_text_field((string) $city));
		if ('' === $cp || '' === $city) {
			return false;
		}
		$state_name = self::state_name($state, $country);
		$state_code = self::state_code($state, $country);
		$norm       = MJE_Settings::key_of($city);
		$now        = current_time('mysql', true);

		global $wpdb;
		$table = MJE_Install::table_geo();
		$id    = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE country = %s AND postcode = %s AND norm = %s", $country, $cp, $norm)); // phpcs:ignore WordPress.DB
		if ($id) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET hits = hits + 1, updated_at = %s WHERE id = %d", $now, $id)); // phpcs:ignore WordPress.DB
			return true;
		}
		return (bool) $wpdb->insert($table, [ // phpcs:ignore WordPress.DB
			'country'    => $country,
			'postcode'   => $cp,
			'city'       => $city,
			'norm'       => $norm,
			'state'      => $state_name,
			'state_code' => $state_code,
			'source'     => sanitize_key($source),
			'hits'       => 1,
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	/** Cuántos pares hay cargados (lo que muestra la pantalla de códigos postales). */
	public static function count() {
		if (!MJE_Install::tables_exist()) {
			return 0;
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB
	}

	/**
	 * Importa un CSV de códigos postales.
	 *
	 * Acepta cualquier orden de columnas: se leen los encabezados y se aceptan
	 * los nombres habituales (cp / codigo_postal / postcode, localidad / ciudad
	 * / city, provincia / state). Sin encabezados, asume CP, localidad,
	 * provincia.
	 *
	 * @return array ['filas' => int, 'nuevas' => int, 'errores' => int]
	 */
	public static function import_csv($path, $country = '') {
		$out = ['filas' => 0, 'nuevas' => 0, 'errores' => 0];
		if (!MJE_Install::tables_exist() || !is_readable($path)) {
			$out['errores'] = 1;
			return $out;
		}
		$country = '' !== $country ? strtoupper($country) : self::country();
		$handle  = fopen($path, 'r');
		if (!$handle) {
			$out['errores'] = 1;
			return $out;
		}

		$map     = ['cp' => 0, 'city' => 1, 'state' => 2];
		$aliases = [
			'cp'    => ['cp', 'codigo postal', 'codigo', 'postal', 'postcode', 'zip'],
			'city'  => ['localidad', 'ciudad', 'city', 'town', 'barrio'],
			'state' => ['provincia', 'state', 'region'],
		];
		$first = fgetcsv($handle, 0, self::csv_delimiter($path));
		if (is_array($first)) {
			$found = [];
			foreach ($first as $index => $label) {
				$key = MJE_Settings::key_of($label);
				foreach ($aliases as $field => $names) {
					if (in_array($key, $names, true)) {
						$found[$field] = $index;
					}
				}
			}
			if (isset($found['cp'], $found['city'])) {
				$map = wp_parse_args($found, $map);
			} else {
				/* No eran encabezados: se procesa como fila de datos. */
				self::import_row($first, $map, $country, $out);
			}
		}
		while (($row = fgetcsv($handle, 0, self::csv_delimiter($path))) !== false) {
			self::import_row($row, $map, $country, $out);
		}
		fclose($handle);
		return $out;
	}

	private static function import_row($row, array $map, $country, array &$out) {
		if (!is_array($row)) {
			return;
		}
		$cp   = isset($row[$map['cp']]) ? self::normalize_cp($row[$map['cp']]) : '';
		$city = isset($row[$map['city']]) ? trim((string) $row[$map['city']]) : '';
		$st   = isset($map['state'], $row[$map['state']]) ? trim((string) $row[$map['state']]) : '';
		if ('' === $cp || '' === $city) {
			$out['errores']++;
			return;
		}
		$out['filas']++;
		if (self::remember($cp, $city, $st, 'csv', $country)) {
			$out['nuevas']++;
		}
	}

	/** Coma o punto y coma: lo que más aparezca en la primera línea. */
	private static function csv_delimiter($path) {
		static $cache = [];
		if (isset($cache[$path])) {
			return $cache[$path];
		}
		$handle = fopen($path, 'r');
		$line   = $handle ? (string) fgets($handle) : '';
		if ($handle) {
			fclose($handle);
		}
		$cache[$path] = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
		return $cache[$path];
	}

	/** Borra todo lo importado (el panel lo ofrece antes de reimportar). */
	public static function truncate() {
		if (!MJE_Install::tables_exist()) {
			return;
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		$wpdb->query("TRUNCATE TABLE {$table}"); // phpcs:ignore WordPress.DB
	}

	/* -------------------------------- Log -------------------------------- */

	public static function log($scope, $message) {
		if (!MJE_Settings::get('log_errors')) {
			return;
		}
		$log   = get_option('mje_log', []);
		$log   = is_array($log) ? $log : [];
		$log[] = [
			'when'    => current_time('mysql'),
			'scope'   => (string) $scope,
			'message' => mb_substr((string) $message, 0, 300),
		];
		/* Solo las últimas 50 líneas: es para mirar qué pasó, no un historial. */
		update_option('mje_log', array_slice($log, -50), false);
	}
}
