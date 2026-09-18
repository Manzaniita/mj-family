<?php
/**
 * Base de datos del plugin: tablas propias, CRUD y shapes públicos.
 *
 * Seis tablas con prefijo {$wpdb->prefix}mjto_:
 *  - clients      la clienta (datos de contacto + campos extra del formulario)
 *  - appointments turnos (reserva web o carga desde el panel)
 *  - visits       ficha técnica de UNA visita; la ficha de la clienta es su
 *                 línea de tiempo de visitas
 *  - blocks       bloqueos de agenda (vacaciones, feriados, franjas)
 *  - credits      movimientos del saldo a favor de cada clienta (el saldo es
 *                 la suma; nunca se pisa un movimiento, se agrega otro)
 *  - reviews      opiniones de las clientas atendidas
 *
 * Todas las fechas se guardan como DATETIME/DATE en la zona horaria del
 * sitio (wp_timezone()). Nunca date()/time() a secas para la agenda.
 * Todas las consultas van por $wpdb->prepare. Las funciones devuelven
 * arrays asociativos (filas crudas) o null; los shapes públicos (camelCase)
 * son los que viajan por REST y al panel.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Tablas
 * ---------------------------------------------------------------------- */

/** Nombre completo de una tabla del plugin ('clients' → wp_mjto_clients). */
function mjto_table($name) {
	global $wpdb;
	return $wpdb->prefix . 'mjto_' . sanitize_key($name);
}

/** Crea o actualiza las tablas con dbDelta (activación y cambio de MJTO_DB_VERSION). */
function mjto_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset      = $wpdb->get_charset_collate();
	$clients      = mjto_table('clients');
	$appointments = mjto_table('appointments');
	$visits       = mjto_table('visits');
	$blocks       = mjto_table('blocks');
	$credits      = mjto_table('credits');
	$reviews      = mjto_table('reviews');

	dbDelta("CREATE TABLE {$clients} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(120) NOT NULL DEFAULT '',
  last_name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  phone_raw VARCHAR(64) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  locality VARCHAR(160) NOT NULL DEFAULT '',
  age SMALLINT UNSIGNED NULL,
  birthdate DATE NULL,
  notes TEXT NULL,
  extra LONGTEXT NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY phone (phone),
  KEY last_name (last_name),
  KEY email (email),
  KEY user_id (user_id)
) {$charset};");

	dbDelta("CREATE TABLE {$appointments} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  service_name VARCHAR(160) NOT NULL DEFAULT '',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  source VARCHAR(10) NOT NULL DEFAULT 'web',
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_required DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_method VARCHAR(60) NOT NULL DEFAULT '',
  deposit_notified_at DATETIME NULL,
  deposit_deadline DATETIME NULL,
  token VARCHAR(40) NOT NULL DEFAULT '',
  client_notes TEXT NULL,
  internal_notes TEXT NULL,
  reminder_sent_at DATETIME NULL,
  reminder2_sent_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
  cancelled_by VARCHAR(10) NOT NULL DEFAULT '',
  credit_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_forfeited DECIMAL(12,2) NOT NULL DEFAULT 0,
  owner_offer VARCHAR(20) NOT NULL DEFAULT '',
  rescheduled_from BIGINT UNSIGNED NULL,
  reschedule_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  review_requested_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY starts_at (starts_at),
  KEY status (status),
  UNIQUE KEY token (token)
) {$charset};");

	dbDelta("CREATE TABLE {$visits} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  visited_at DATE NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  service_name VARCHAR(160) NOT NULL DEFAULT '',
  data LONGTEXT NULL,
  warnings LONGTEXT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(60) NOT NULL DEFAULT '',
  control_date DATE NULL,
  retouch_date DATE NULL,
  maintenance_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY visited_at (visited_at),
  KEY appointment_id (appointment_id),
  KEY retouch_date (retouch_date)
) {$charset};");

	dbDelta("CREATE TABLE {$blocks} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY starts_at (starts_at)
) {$charset};");

	dbDelta("CREATE TABLE {$credits} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  kind VARCHAR(20) NOT NULL DEFAULT 'manual',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  appointment_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY appointment_id (appointment_id)
) {$charset};");

	dbDelta("CREATE TABLE {$reviews} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  service_name VARCHAR(160) NOT NULL DEFAULT '',
  author_name VARCHAR(160) NOT NULL DEFAULT '',
  rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
  body TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY appointment_id (appointment_id),
  KEY status (status),
  KEY client_id (client_id)
) {$charset};");

	update_option('mjto_db_version', MJTO_DB_VERSION, false);
}

/** Actualiza el esquema si cambió la versión (hook plugins_loaded). */
function mjto_maybe_upgrade_db() {
	if ((string) get_option('mjto_db_version', '') !== (string) MJTO_DB_VERSION) {
		mjto_install_tables();
	}
}

/* -------------------------------------------------------------------------
 * Helpers de fecha, teléfono y JSON
 * ---------------------------------------------------------------------- */

/** Ahora, en la zona horaria del sitio: 'YYYY-MM-DD HH:MM:SS'. */
function mjto_now() {
	return current_datetime()->format('Y-m-d H:i:s');
}

/** Hoy, en la zona horaria del sitio: 'YYYY-MM-DD'. */
function mjto_today() {
	return current_datetime()->format('Y-m-d');
}

/** Timestamp de una fecha/hora local del sitio (o null si no se puede parsear). */
function mjto_local_ts($datetime) {
	$datetime = trim((string) $datetime);
	if ('' === $datetime || '0000-00-00 00:00:00' === $datetime || '0000-00-00' === $datetime) {
		return null;
	}
	try {
		return (new DateTimeImmutable($datetime, wp_timezone()))->getTimestamp();
	} catch (Exception $e) {
		return null;
	}
}

/** Teléfono normalizado: solo dígitos, sin ceros iniciales. */
function mjto_normalize_phone($raw) {
	$digits = preg_replace('/\D+/', '', (string) $raw);
	return ltrim((string) $digits, '0');
}

/**
 * Número para wa.me: si ya empieza con el código de país (whatsapp_country,
 * default 54) se deja; si no, se antepone whatsapp_prefix (default 549).
 */
function mjto_whatsapp_number($phone) {
	$phone = mjto_normalize_phone($phone);
	if ('' === $phone) {
		return '';
	}
	$country = preg_replace('/\D+/', '', (string) mjto_opt('whatsapp_country'));
	$prefix  = preg_replace('/\D+/', '', (string) mjto_opt('whatsapp_prefix'));
	if ('' === $country) {
		$country = '54';
	}
	if ('' === $prefix) {
		$prefix = '549';
	}
	if (0 === strpos($phone, $country)) {
		return $phone;
	}
	return $prefix . $phone;
}

/** JSON guardado → array (vacío si es inválido). */
function mjto_json_to_array($json) {
	if (is_array($json)) {
		return $json;
	}
	$json = trim((string) $json);
	if ('' === $json) {
		return [];
	}
	$decoded = json_decode($json, true);
	return is_array($decoded) ? $decoded : [];
}

/** Array → JSON para guardar (null si está vacío). */
function mjto_array_to_json($value) {
	if (is_string($value)) {
		$value = mjto_json_to_array($value);
	}
	if (!is_array($value) || !$value) {
		return null;
	}
	return wp_json_encode($value, JSON_UNESCAPED_UNICODE);
}

/** Fecha 'YYYY-MM-DD' válida (con checkdate). */
function mjto_valid_date($date) {
	$date = (string) $date;
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
		return false;
	}
	return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Fecha y hora 'YYYY-MM-DD HH:MM[:SS]' válida → normalizada 'YYYY-MM-DD HH:MM:SS' o ''. */
function mjto_valid_datetime($datetime) {
	$datetime = trim((string) $datetime);
	if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $datetime, $m)) {
		return '';
	}
	if (!mjto_valid_date($m[1]) || (int) $m[2] > 23 || (int) $m[3] > 59) {
		return '';
	}
	$sec = isset($m[4]) ? (int) $m[4] : 0;
	return sprintf('%s %02d:%02d:%02d', $m[1], (int) $m[2], (int) $m[3], min(59, $sec));
}

/** Valor DATETIME/DATE crudo → string o null (para los shapes públicos). */
function mjto_dt_or_null($value) {
	$value = (string) $value;
	if ('' === $value || 0 === strpos($value, '0000-00-00')) {
		return null;
	}
	return $value;
}

/**
 * Filtra un array de datos dejando solo las columnas permitidas y castea
 * según el tipo ('s' string, 'i' int, 'f' float, 'ni' int|null, 'ndt' datetime|null,
 * 'nd' date|null, 'json' array→json, 'ntext' text|null).
 */
function mjto_prepare_row(array $data, array $columns) {
	$row    = [];
	$format = [];
	foreach ($columns as $col => $type) {
		if (!array_key_exists($col, $data)) {
			continue;
		}
		$v = $data[$col];
		switch ($type) {
			case 'i':
				$row[$col]    = (int) $v;
				$format[$col] = '%d';
				break;
			case 'f':
				$row[$col]    = round((float) str_replace(',', '.', (string) $v), 2);
				$format[$col] = '%f';
				break;
			case 'ni':
				$row[$col]    = ('' === $v || null === $v) ? null : (int) $v;
				$format[$col] = '%d';
				break;
			case 'ndt':
				$dt           = mjto_valid_datetime($v);
				$row[$col]    = '' === $dt ? null : $dt;
				$format[$col] = '%s';
				break;
			case 'nd':
				$row[$col]    = mjto_valid_date($v) ? (string) $v : null;
				$format[$col] = '%s';
				break;
			case 'json':
				$row[$col]    = mjto_array_to_json($v);
				$format[$col] = '%s';
				break;
			case 'ntext':
				$v            = is_null($v) ? null : sanitize_textarea_field((string) $v);
				$row[$col]    = ('' === $v) ? null : $v;
				$format[$col] = '%s';
				break;
			default:
				$row[$col]    = sanitize_text_field((string) $v);
				$format[$col] = '%s';
		}
	}
	return [$row, $format];
}

/** Formatos alineados con las claves de la fila (para $wpdb->insert/update; null se manda como NULL). */
function mjto_row_formats(array $row, array $formats) {
	$out = [];
	foreach ($row as $col => $v) {
		$out[] = isset($formats[$col]) ? $formats[$col] : '%s';
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Clientas
 * ---------------------------------------------------------------------- */

function mjto_client_columns() {
	return [
		'first_name' => 's',
		'last_name'  => 's',
		'phone'      => 's',
		'phone_raw'  => 's',
		'email'      => 's',
		'locality'   => 's',
		'age'        => 'ni',
		'birthdate'  => 'nd',
		'notes'      => 'ntext',
		'extra'      => 'json',
		'user_id'    => 'ni',
	];
}

/**
 * Edad cumplida a hoy desde una fecha de nacimiento 'YYYY-MM-DD' (null si no
 * es válida). La edad cargada a mano queda vieja con los años: si hay fecha,
 * manda la fecha.
 */
function mjto_age_from_birthdate($birthdate) {
	$birthdate = (string) $birthdate;
	if (!mjto_valid_date($birthdate)) {
		return null;
	}
	$today = current_datetime();
	$birth = new DateTimeImmutable($birthdate . ' 00:00:00', wp_timezone());
	if ($birth > $today) {
		return null;
	}
	$age = (int) $birth->diff($today)->y;
	return ($age >= 0 && $age < 130) ? $age : null;
}

/** Prepara los datos de una clienta: teléfono normalizado, email válido, edad razonable. */
function mjto_prepare_client_data(array $data) {
	if (isset($data['phone_raw']) && !isset($data['phone'])) {
		$data['phone'] = $data['phone_raw'];
	}
	if (isset($data['phone'])) {
		$raw = sanitize_text_field((string) $data['phone']);
		if (!isset($data['phone_raw']) || '' === trim((string) $data['phone_raw'])) {
			$data['phone_raw'] = $raw;
		}
		$data['phone'] = mjto_normalize_phone($raw);
	}
	if (isset($data['email'])) {
		$data['email'] = sanitize_email((string) $data['email']);
	}
	if (isset($data['age'])) {
		$age         = (int) $data['age'];
		$data['age'] = ($age > 0 && $age < 130) ? $age : null;
	}
	if (array_key_exists('birthdate', $data)) {
		$data['birthdate'] = (null !== mjto_age_from_birthdate($data['birthdate'])) ? (string) $data['birthdate'] : null;
	}
	if (isset($data['extra']) && !is_array($data['extra'])) {
		$data['extra'] = mjto_json_to_array($data['extra']);
	}
	return $data;
}

function mjto_get_client($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('clients') . ' WHERE id = %d', $id), ARRAY_A);
	return $row ? $row : null;
}

/** Clienta vinculada a un usuario de WordPress (la más antigua si hubiera varias). */
function mjto_find_client_by_user($user_id) {
	global $wpdb;
	$user_id = (int) $user_id;
	if ($user_id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('clients') . ' WHERE user_id = %d ORDER BY id ASC LIMIT 1', $user_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia; los valores van por prepare.
	return $row ? $row : null;
}

/** Clienta por email (sin cuenta vinculada o con esa misma cuenta), la más antigua. */
function mjto_find_client_by_email($email, $user_id = 0) {
	global $wpdb;
	$email = sanitize_email((string) $email);
	if (!is_email($email)) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT * FROM ' . mjto_table('clients') . ' WHERE email = %s AND (user_id IS NULL OR user_id = 0 OR user_id = %d) ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia; los valores van por prepare.
		$email,
		(int) $user_id
	), ARRAY_A);
	return $row ? $row : null;
}

/** Clienta por teléfono normalizado (la más antigua si hubiera duplicados). */
function mjto_find_client_by_phone($phone) {
	global $wpdb;
	$phone = mjto_normalize_phone($phone);
	if ('' === $phone) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('clients') . ' WHERE phone = %s ORDER BY id ASC LIMIT 1', $phone), ARRAY_A);
	return $row ? $row : null;
}

/** Alta → id o false. */
function mjto_insert_client(array $data) {
	global $wpdb;
	$data = mjto_prepare_client_data($data);
	[$row, $formats] = mjto_prepare_row($data, mjto_client_columns());
	$now               = mjto_now();
	$row['created_at'] = $now;
	$row['updated_at'] = $now;
	$ok = $wpdb->insert(mjto_table('clients'), $row, mjto_row_formats($row, $formats));
	return $ok ? (int) $wpdb->insert_id : false;
}

/** Modificación → bool. */
function mjto_update_client($id, array $data) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	$data = mjto_prepare_client_data($data);
	[$row, $formats] = mjto_prepare_row($data, mjto_client_columns());
	$row['updated_at'] = mjto_now();
	$ok = $wpdb->update(mjto_table('clients'), $row, ['id' => $id], mjto_row_formats($row, $formats), ['%d']);
	return false !== $ok;
}

/** Baja: borra también sus visitas, sus turnos, su saldo y sus opiniones. */
function mjto_delete_client($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	$wpdb->delete(mjto_table('visits'), ['client_id' => $id], ['%d']);
	$wpdb->delete(mjto_table('appointments'), ['client_id' => $id], ['%d']);
	$wpdb->delete(mjto_table('credits'), ['client_id' => $id], ['%d']);
	$wpdb->delete(mjto_table('reviews'), ['client_id' => $id], ['%d']);
	return false !== $wpdb->delete(mjto_table('clients'), ['id' => $id], ['%d']);
}

/** Condición SQL (preparada) de búsqueda por texto sobre nombre/apellido/teléfono. Devuelve '' si no hay término. */
function mjto_client_search_where($q, $alias = 'c') {
	global $wpdb;
	$q = trim((string) $q);
	if ('' === $q) {
		return '';
	}
	$like   = '%' . $wpdb->esc_like($q) . '%';
	$parts  = [
		$wpdb->prepare("{$alias}.first_name LIKE %s", $like),
		$wpdb->prepare("{$alias}.last_name LIKE %s", $like),
		$wpdb->prepare("CONCAT({$alias}.first_name, ' ', {$alias}.last_name) LIKE %s", $like),
		$wpdb->prepare("{$alias}.phone LIKE %s", $like),
		$wpdb->prepare("{$alias}.phone_raw LIKE %s", $like),
	];
	$digits = mjto_normalize_phone($q);
	if ('' !== $digits && $digits !== $q) {
		$parts[] = $wpdb->prepare("{$alias}.phone LIKE %s", '%' . $wpdb->esc_like($digits) . '%');
	}
	return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Búsqueda de clientas para el panel.
 * args: q, year, month, order (last_visit_desc | name_asc | created_desc), page, per_page.
 * Devuelve ['items' => filas con visits_count, last_visit y next_appointment, 'total' => int].
 */
function mjto_search_clients(array $args = []) {
	global $wpdb;
	$args = wp_parse_args($args, [
		'q'        => '',
		'year'     => 0,
		'month'    => 0,
		'order'    => 'last_visit_desc',
		'page'     => 1,
		'per_page' => 50,
	]);
	$clients      = mjto_table('clients');
	$visits       = mjto_table('visits');
	$appointments = mjto_table('appointments');
	$per_page     = max(1, min(500, (int) $args['per_page']));
	$page         = max(1, (int) $args['page']);
	$offset       = ($page - 1) * $per_page;
	$now          = mjto_now();

	$where = ['1=1'];
	$q     = mjto_client_search_where($args['q']);
	if ('' !== $q) {
		$where[] = $q;
	}
	$year  = (int) $args['year'];
	$month = (int) $args['month'];
	if ($year > 0) {
		if ($month >= 1 && $month <= 12) {
			$from = sprintf('%04d-%02d-01', $year, $month);
			$to   = (new DateTimeImmutable($from, wp_timezone()))->modify('last day of this month')->format('Y-m-d');
		} else {
			$from = sprintf('%04d-01-01', $year);
			$to   = sprintf('%04d-12-31', $year);
		}
		$where[] = $wpdb->prepare(
			"(EXISTS (SELECT 1 FROM {$visits} v2 WHERE v2.client_id = c.id AND v2.visited_at BETWEEN %s AND %s)
			 OR EXISTS (SELECT 1 FROM {$appointments} a2 WHERE a2.client_id = c.id AND a2.status <> 'cancelled' AND a2.starts_at BETWEEN %s AND %s))",
			$from,
			$to,
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		);
	}
	$where_sql = implode(' AND ', $where);

	switch ((string) $args['order']) {
		case 'name_asc':
			$order_sql = 'c.last_name ASC, c.first_name ASC, c.id ASC';
			break;
		case 'created_desc':
			$order_sql = 'c.created_at DESC, c.id DESC';
			break;
		default:
			$order_sql = 'last_visit IS NULL, last_visit DESC, c.last_name ASC, c.first_name ASC';
	}

	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clients} c WHERE {$where_sql}"); // phpcs:ignore WordPress.DB.PreparedSQL -- las partes variables ya están preparadas.

	/* Ojo: $where_sql ya está preparado (trae % de LIKE), así que no puede pasar por otro prepare. */
	$next_sub = $wpdb->prepare("(SELECT a.id FROM {$appointments} a WHERE a.client_id = c.id AND a.status NOT IN ('cancelled', 'no_show') AND a.starts_at >= %s ORDER BY a.starts_at ASC LIMIT 1)", $now); // phpcs:ignore WordPress.DB.PreparedSQL
	$limit    = $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset);
	$sql      = "SELECT c.*,
			(SELECT COUNT(*) FROM {$visits} v WHERE v.client_id = c.id) AS visits_count,
			(SELECT MAX(v.visited_at) FROM {$visits} v WHERE v.client_id = c.id) AS last_visit,
			{$next_sub} AS next_appointment_id
		FROM {$clients} c
		WHERE {$where_sql}
		ORDER BY {$order_sql}
		{$limit}";
	$rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	$rows = is_array($rows) ? $rows : [];

	/* Próximo turno de cada clienta en una sola consulta. */
	$next_ids = array_filter(array_map(fn ($r) => (int) $r['next_appointment_id'], $rows));
	$next     = [];
	if ($next_ids) {
		$placeholders = implode(',', array_fill(0, count($next_ids), '%d'));
		$next_rows    = $wpdb->get_results($wpdb->prepare("SELECT id, client_id, starts_at, status FROM {$appointments} WHERE id IN ({$placeholders})", ...array_values($next_ids)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ((array) $next_rows as $nr) {
			$next[(int) $nr['id']] = ['id' => (int) $nr['id'], 'starts_at' => $nr['starts_at'], 'status' => $nr['status']];
		}
	}
	foreach ($rows as &$r) {
		$nid                   = (int) $r['next_appointment_id'];
		$r['next_appointment'] = ($nid && isset($next[$nid])) ? $next[$nid] : null;
		$r['visits_count']     = (int) $r['visits_count'];
		$r['last_visit']       = mjto_dt_or_null($r['last_visit']);
		unset($r['next_appointment_id']);
	}
	unset($r);

	return ['items' => $rows, 'total' => $total];
}

/* -------------------------------------------------------------------------
 * Turnos
 * ---------------------------------------------------------------------- */

function mjto_appointment_columns() {
	return [
		'client_id'           => 'i',
		'service_id'          => 'i',
		'service_name'        => 's',
		'starts_at'           => 'ndt',
		'ends_at'             => 'ndt',
		'duration_min'        => 'i',
		'status'              => 's',
		'source'              => 's',
		'price'               => 'f',
		'deposit_required'    => 'f',
		'deposit_paid'        => 'f',
		'deposit_method'      => 's',
		'deposit_notified_at' => 'ndt',
		'deposit_deadline'    => 'ndt',
		'token'               => 's',
		'client_notes'        => 'ntext',
		'internal_notes'      => 'ntext',
		'reminder_sent_at'    => 'ndt',
		'reminder2_sent_at'   => 'ndt',
		'cancelled_at'        => 'ndt',
		'cancel_reason'       => 's',
		'cancelled_by'        => 's',
		'credit_applied'      => 'f',
		'deposit_forfeited'   => 'f',
		'owner_offer'         => 's',
		'rescheduled_from'    => 'ni',
		'reschedule_count'    => 'i',
		'completed_at'        => 'ndt',
		'review_requested_at' => 'ndt',
	];
}

/**
 * Qué pasa con un turno que canceló la dueña y le ofreció opciones a la
 * clienta: '' (nada que ofrecer), 'open' (esperando que elija), 'rescheduled',
 * 'credited' (seña pasada a saldo a favor), 'refund_requested' y 'refunded'.
 */
function mjto_owner_offer_states() {
	return ['', 'open', 'rescheduled', 'credited', 'refund_requested', 'refunded'];
}

/** Estados válidos de un turno. */
function mjto_valid_statuses() {
	return ['pending', 'awaiting_deposit', 'confirmed', 'completed', 'cancelled', 'no_show'];
}

/** SELECT común de turnos: la fila + datos de la clienta + id de la visita asociada. */
function mjto_appointment_select() {
	$visits = mjto_table('visits');
	return "SELECT a.*,
		c.first_name AS client_first_name, c.last_name AS client_last_name, c.phone AS client_phone,
		c.phone_raw AS client_phone_raw, c.email AS client_email, c.locality AS client_locality, c.user_id AS client_user_id,
		(SELECT v.id FROM {$visits} v WHERE v.appointment_id = a.id ORDER BY v.id ASC LIMIT 1) AS visit_id
		FROM " . mjto_table('appointments') . ' a
		LEFT JOIN ' . mjto_table('clients') . ' c ON c.id = a.client_id';
}

function mjto_get_appointment($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(mjto_appointment_select() . ' WHERE a.id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

function mjto_get_appointment_by_token($token) {
	global $wpdb;
	$token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
	if ('' === $token) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(mjto_appointment_select() . ' WHERE a.token = %s', $token), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Valida estado/origen/quién canceló antes de guardar. */
function mjto_prepare_appointment_data(array $data) {
	if (isset($data['status']) && !in_array((string) $data['status'], mjto_valid_statuses(), true)) {
		$data['status'] = 'pending';
	}
	if (isset($data['source']) && !in_array((string) $data['source'], ['web', 'panel'], true)) {
		$data['source'] = 'panel';
	}
	if (isset($data['cancelled_by']) && !in_array((string) $data['cancelled_by'], ['', 'client', 'owner', 'system'], true)) {
		$data['cancelled_by'] = 'owner';
	}
	if (isset($data['token'])) {
		$data['token'] = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $data['token']), 0, 40);
	}
	if (isset($data['owner_offer']) && !in_array((string) $data['owner_offer'], mjto_owner_offer_states(), true)) {
		$data['owner_offer'] = '';
	}
	return $data;
}

/** Alta → id o false. Si no viene ends_at se calcula con starts_at + duration_min. */
function mjto_insert_appointment(array $data) {
	global $wpdb;
	$data = mjto_prepare_appointment_data($data);
	if (empty($data['ends_at']) && !empty($data['starts_at'])) {
		$start = mjto_valid_datetime($data['starts_at']);
		if ('' !== $start) {
			$data['ends_at'] = (new DateTimeImmutable($start, wp_timezone()))->modify('+' . max(0, (int) ($data['duration_min'] ?? 0)) . ' minutes')->format('Y-m-d H:i:s');
		}
	}
	[$row, $formats] = mjto_prepare_row($data, mjto_appointment_columns());
	if (empty($row['starts_at']) || empty($row['ends_at']) || empty($row['client_id'])) {
		return false;
	}
	$now               = mjto_now();
	$row['created_at'] = $now;
	$row['updated_at'] = $now;
	$ok = $wpdb->insert(mjto_table('appointments'), $row, mjto_row_formats($row, $formats));
	return $ok ? (int) $wpdb->insert_id : false;
}

/** Modificación → bool. */
function mjto_update_appointment($id, array $data) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	$data = mjto_prepare_appointment_data($data);
	[$row, $formats] = mjto_prepare_row($data, mjto_appointment_columns());
	$row['updated_at'] = mjto_now();
	$ok = $wpdb->update(mjto_table('appointments'), $row, ['id' => $id], mjto_row_formats($row, $formats), ['%d']);
	return false !== $ok;
}

/** Baja: la visita asociada (si existe) queda sin turno, no se borra. */
function mjto_delete_appointment($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	$wpdb->query($wpdb->prepare('UPDATE ' . mjto_table('visits') . ' SET appointment_id = NULL WHERE appointment_id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL
	return false !== $wpdb->delete(mjto_table('appointments'), ['id' => $id], ['%d']);
}

/**
 * Turnos que tocan el rango [from, to) (solapan), ordenados por inicio.
 * $statuses: array de estados a incluir (null = todos).
 */
function mjto_appointments_between($from_datetime, $to_datetime, $statuses = null) {
	global $wpdb;
	$from = mjto_valid_datetime($from_datetime);
	$to   = mjto_valid_datetime($to_datetime);
	if ('' === $from || '' === $to) {
		return [];
	}
	$sql = $wpdb->prepare(mjto_appointment_select() . ' WHERE a.starts_at < %s AND a.ends_at > %s', $to, $from); // phpcs:ignore WordPress.DB.PreparedSQL
	if (is_array($statuses) && $statuses) {
		$statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), mjto_valid_statuses()));
		if ($statuses) {
			$sql .= $wpdb->prepare(' AND a.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')', ...$statuses); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
	$sql .= ' ORDER BY a.starts_at ASC, a.id ASC';
	$rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array($rows) ? $rows : [];
}

/**
 * Listado paginado para el panel.
 * args: from, to (YYYY-MM-DD inclusive), status (array), q, client_id,
 * owner_offer (uno de mjto_owner_offer_states(), sin vacío), order (asc|desc), page, per_page.
 */
function mjto_query_appointments(array $args = []) {
	global $wpdb;
	$args = wp_parse_args($args, [
		'from'      => '',
		'to'        => '',
		'status'    => [],
		'q'         => '',
		'client_id' => 0,
		'owner_offer' => '',
		'order'     => 'asc',
		'page'      => 1,
		'per_page'  => 50,
	]);
	$per_page = max(1, min(500, (int) $args['per_page']));
	$page     = max(1, (int) $args['page']);
	$offset   = ($page - 1) * $per_page;

	$where = ['1=1'];
	if (mjto_valid_date($args['from'])) {
		$where[] = $wpdb->prepare('a.starts_at >= %s', $args['from'] . ' 00:00:00');
	}
	if (mjto_valid_date($args['to'])) {
		$where[] = $wpdb->prepare('a.starts_at <= %s', $args['to'] . ' 23:59:59');
	}
	$statuses = is_array($args['status']) ? $args['status'] : (('' !== (string) $args['status']) ? [(string) $args['status']] : []);
	$statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), mjto_valid_statuses()));
	if ($statuses) {
		$where[] = $wpdb->prepare('a.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')', ...$statuses); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	if ((int) $args['client_id'] > 0) {
		$where[] = $wpdb->prepare('a.client_id = %d', (int) $args['client_id']);
	}
	if ('' !== (string) $args['owner_offer'] && in_array((string) $args['owner_offer'], mjto_owner_offer_states(), true)) {
		$where[] = $wpdb->prepare('a.owner_offer = %s', (string) $args['owner_offer']);
	}
	$q = trim((string) $args['q']);
	if ('' !== $q) {
		$search  = mjto_client_search_where($q, 'c');
		$where[] = '(' . $search . ' OR ' . $wpdb->prepare('a.service_name LIKE %s', '%' . $wpdb->esc_like($q) . '%') . ')';
	}
	$where_sql = implode(' AND ', $where);
	$dir       = ('desc' === strtolower((string) $args['order'])) ? 'DESC' : 'ASC';

	$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . mjto_table('appointments') . ' a LEFT JOIN ' . mjto_table('clients') . " c ON c.id = a.client_id WHERE {$where_sql}"); // phpcs:ignore WordPress.DB.PreparedSQL
	$limit = $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset); // $where_sql ya está preparado: no puede volver a pasar por prepare.
	$rows  = $wpdb->get_results(mjto_appointment_select() . " WHERE {$where_sql} ORDER BY a.starts_at {$dir}, a.id {$dir} {$limit}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

	return ['items' => is_array($rows) ? $rows : [], 'total' => $total];
}

/* -------------------------------------------------------------------------
 * Visitas (ficha técnica de una visita)
 * ---------------------------------------------------------------------- */

function mjto_visit_columns() {
	return [
		'client_id'      => 'i',
		'appointment_id' => 'ni',
		'visited_at'     => 'nd',
		'service_id'     => 'i',
		'service_name'   => 's',
		'data'           => 'json',
		'warnings'       => 'json',
		'total'          => 'f',
		'deposit'        => 'f',
		'payment_method' => 's',
		'control_date'        => 'nd',
		'retouch_date'        => 'nd',
		'maintenance_sent_at' => 'ndt',
	];
}

/** SELECT común de visitas: la fila + nombre de la clienta. */
function mjto_visit_select() {
	return 'SELECT v.*, c.first_name AS client_first_name, c.last_name AS client_last_name
		FROM ' . mjto_table('visits') . ' v
		LEFT JOIN ' . mjto_table('clients') . ' c ON c.id = v.client_id';
}

function mjto_get_visit($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(mjto_visit_select() . ' WHERE v.id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Visitas de una clienta, la más reciente primero. */
function mjto_visits_of_client($client_id) {
	global $wpdb;
	$client_id = (int) $client_id;
	if ($client_id <= 0) {
		return [];
	}
	$rows = $wpdb->get_results($wpdb->prepare(mjto_visit_select() . ' WHERE v.client_id = %d ORDER BY v.visited_at DESC, v.id DESC', $client_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array($rows) ? $rows : [];
}

/** Última visita de una clienta (o null). */
function mjto_last_visit_of_client($client_id) {
	global $wpdb;
	$client_id = (int) $client_id;
	if ($client_id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(mjto_visit_select() . ' WHERE v.client_id = %d ORDER BY v.visited_at DESC, v.id DESC LIMIT 1', $client_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Visita asociada a un turno (o null). */
function mjto_visit_of_appointment($appointment_id) {
	global $wpdb;
	$appointment_id = (int) $appointment_id;
	if ($appointment_id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare(mjto_visit_select() . ' WHERE v.appointment_id = %d ORDER BY v.id ASC LIMIT 1', $appointment_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Alta → id o false. visited_at por defecto: hoy. */
function mjto_insert_visit(array $data) {
	global $wpdb;
	if (empty($data['visited_at']) || !mjto_valid_date($data['visited_at'])) {
		$data['visited_at'] = mjto_today();
	}
	[$row, $formats] = mjto_prepare_row($data, mjto_visit_columns());
	if (empty($row['client_id'])) {
		return false;
	}
	$now               = mjto_now();
	$row['created_at'] = $now;
	$row['updated_at'] = $now;
	$ok = $wpdb->insert(mjto_table('visits'), $row, mjto_row_formats($row, $formats));
	return $ok ? (int) $wpdb->insert_id : false;
}

/** Modificación → bool. */
function mjto_update_visit($id, array $data) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	if (array_key_exists('visited_at', $data) && !mjto_valid_date($data['visited_at'])) {
		unset($data['visited_at']); // La fecha de la visita no puede quedar vacía.
	}
	[$row, $formats] = mjto_prepare_row($data, mjto_visit_columns());
	$row['updated_at'] = mjto_now();
	$ok = $wpdb->update(mjto_table('visits'), $row, ['id' => $id], mjto_row_formats($row, $formats), ['%d']);
	return false !== $ok;
}

function mjto_delete_visit($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	return false !== $wpdb->delete(mjto_table('visits'), ['id' => $id], ['%d']);
}

/** Visitas por fecha de visita en [from, to] (fechas YYYY-MM-DD inclusive), ascendente. Para la caja y el export. */
function mjto_visits_between($from_date, $to_date) {
	global $wpdb;
	if (!mjto_valid_date($from_date) || !mjto_valid_date($to_date)) {
		return [];
	}
	$rows = $wpdb->get_results($wpdb->prepare(mjto_visit_select() . ' WHERE v.visited_at BETWEEN %s AND %s ORDER BY v.visited_at ASC, v.id ASC', $from_date, $to_date), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array($rows) ? $rows : [];
}

/* -------------------------------------------------------------------------
 * Bloqueos de agenda
 * ---------------------------------------------------------------------- */

function mjto_get_block($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return null;
	}
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('blocks') . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Bloqueos que tocan el rango [from, to) (solapan), ordenados por inicio. */
function mjto_get_blocks_between($from_dt, $to_dt) {
	global $wpdb;
	$from = mjto_valid_datetime($from_dt);
	$to   = mjto_valid_datetime($to_dt);
	if ('' === $from || '' === $to) {
		return [];
	}
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . mjto_table('blocks') . ' WHERE starts_at < %s AND ends_at > %s ORDER BY starts_at ASC, id ASC', $to, $from), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array($rows) ? $rows : [];
}

/** Alta → id o false. Requiere starts_at < ends_at. */
function mjto_insert_block(array $data) {
	global $wpdb;
	$start  = mjto_valid_datetime($data['starts_at'] ?? '');
	$end    = mjto_valid_datetime($data['ends_at'] ?? '');
	$reason = sanitize_text_field((string) ($data['reason'] ?? ''));
	if ('' === $start || '' === $end || $end <= $start) {
		return false;
	}
	$ok = $wpdb->insert(mjto_table('blocks'), [
		'starts_at'  => $start,
		'ends_at'    => $end,
		'reason'     => mb_substr($reason, 0, 190),
		'created_at' => mjto_now(),
	], ['%s', '%s', '%s', '%s']);
	return $ok ? (int) $wpdb->insert_id : false;
}

function mjto_delete_block($id) {
	global $wpdb;
	$id = (int) $id;
	if ($id <= 0) {
		return false;
	}
	return false !== $wpdb->delete(mjto_table('blocks'), ['id' => $id], ['%d']);
}

/* -------------------------------------------------------------------------
 * Saldo a favor (movimientos)
 * ---------------------------------------------------------------------- */

/**
 * Tipos de movimiento: 'deposit' (seña que pasó a saldo), 'applied' (saldo
 * usado en una seña), 'returned' (saldo que vuelve porque la reserva no
 * siguió), 'manual' (lo cargó o descontó la dueña).
 */
function mjto_credit_kinds() {
	return ['deposit', 'applied', 'returned', 'manual'];
}

/** Saldo a favor de una clienta (suma de sus movimientos), redondeado a 2. */
function mjto_credit_balance($client_id) {
	global $wpdb;
	$client_id = (int) $client_id;
	if ($client_id <= 0) {
		return 0.0;
	}
	$sum = $wpdb->get_var($wpdb->prepare('SELECT SUM(amount) FROM ' . mjto_table('credits') . ' WHERE client_id = %d', $client_id)); // phpcs:ignore WordPress.DB.PreparedSQL
	return round(max(0.0, (float) $sum), 2);
}

/** Movimientos de una clienta, el más nuevo primero. */
function mjto_credit_movements($client_id, $limit = 100) {
	global $wpdb;
	$client_id = (int) $client_id;
	if ($client_id <= 0) {
		return [];
	}
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . mjto_table('credits') . ' WHERE client_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $client_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array($rows) ? $rows : [];
}

/**
 * Suma un movimiento (positivo = entra saldo, negativo = se usa). Un monto
 * negativo nunca deja el saldo por debajo de cero: se recorta a lo que hay.
 * → id del movimiento o false.
 */
function mjto_add_credit($client_id, $amount, $kind = 'manual', $reason = '', $appointment_id = null) {
	global $wpdb;
	$client_id = (int) $client_id;
	$amount    = round((float) $amount, 2);
	if ($client_id <= 0 || 0.0 === $amount) {
		return false;
	}
	if ($amount < 0) {
		$amount = -min(abs($amount), mjto_credit_balance($client_id));
		if (0.0 === $amount) {
			return false;
		}
	}
	$kind = in_array((string) $kind, mjto_credit_kinds(), true) ? (string) $kind : 'manual';
	$ok   = $wpdb->insert(mjto_table('credits'), [
		'client_id'      => $client_id,
		'amount'         => $amount,
		'kind'           => $kind,
		'reason'         => mb_substr(sanitize_text_field((string) $reason), 0, 255),
		'appointment_id' => $appointment_id ? (int) $appointment_id : null,
		'created_by'     => (int) get_current_user_id(),
		'created_at'     => mjto_now(),
	], ['%d', '%f', '%s', '%s', '%d', '%d', '%s']);
	if (!$ok) {
		return false;
	}
	$id = (int) $wpdb->insert_id;
	do_action('mjto_credit_added', $id, $client_id, $amount, $kind, $appointment_id);
	return $id;
}

/** Movimiento → shape público. */
function mjto_credit_public($row) {
	if (!is_array($row)) {
		return null;
	}
	return [
		'id'            => (int) $row['id'],
		'amount'        => round((float) $row['amount'], 2),
		'kind'          => (string) $row['kind'],
		'reason'        => (string) $row['reason'],
		'appointmentId' => !empty($row['appointment_id']) ? (int) $row['appointment_id'] : null,
		'createdAt'     => (string) $row['created_at'],
		'dateLabel'     => mjto_date_label($row['created_at']),
	];
}

/* -------------------------------------------------------------------------
 * Opiniones
 * ---------------------------------------------------------------------- */

/** Estados de una opinión. */
function mjto_review_statuses() {
	return ['pending', 'approved', 'hidden'];
}

function mjto_get_review($id) {
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('reviews') . ' WHERE id = %d', (int) $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

function mjto_review_of_appointment($appointment_id) {
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjto_table('reviews') . ' WHERE appointment_id = %d', (int) $appointment_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? $row : null;
}

/** Alta → id o false (una sola opinión por turno: el índice único lo garantiza). */
function mjto_insert_review(array $data) {
	global $wpdb;
	$now = mjto_now();
	$ok  = $wpdb->insert(mjto_table('reviews'), [
		'appointment_id' => (int) $data['appointment_id'],
		'client_id'      => (int) $data['client_id'],
		'service_id'     => (int) ($data['service_id'] ?? 0),
		'service_name'   => mb_substr(sanitize_text_field((string) ($data['service_name'] ?? '')), 0, 160),
		'author_name'    => mb_substr(sanitize_text_field((string) ($data['author_name'] ?? '')), 0, 160),
		'rating'         => max(1, min(5, (int) ($data['rating'] ?? 5))),
		'body'           => mb_substr(sanitize_textarea_field((string) ($data['body'] ?? '')), 0, 2000),
		'status'         => in_array((string) ($data['status'] ?? ''), mjto_review_statuses(), true) ? (string) $data['status'] : 'pending',
		'created_at'     => $now,
		'updated_at'     => $now,
	], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
	return $ok ? (int) $wpdb->insert_id : false;
}

/** Cambia estado, nombre visible o texto (lo que venga). → bool. */
function mjto_update_review($id, array $data) {
	global $wpdb;
	$row = [];
	$fmt = [];
	if (isset($data['status']) && in_array((string) $data['status'], mjto_review_statuses(), true)) {
		$row['status'] = (string) $data['status'];
		$fmt[]         = '%s';
	}
	if (isset($data['author_name'])) {
		$row['author_name'] = mb_substr(sanitize_text_field((string) $data['author_name']), 0, 160);
		$fmt[]              = '%s';
	}
	if (isset($data['body'])) {
		$row['body'] = mb_substr(sanitize_textarea_field((string) $data['body']), 0, 2000);
		$fmt[]       = '%s';
	}
	if (!$row) {
		return true;
	}
	$row['updated_at'] = mjto_now();
	$fmt[]             = '%s';
	return false !== $wpdb->update(mjto_table('reviews'), $row, ['id' => (int) $id], $fmt, ['%d']);
}

function mjto_delete_review($id) {
	global $wpdb;
	return false !== $wpdb->delete(mjto_table('reviews'), ['id' => (int) $id], ['%d']);
}

/**
 * Listado de opiniones. args: status ('' = todas), service_id, page, per_page.
 * → ['items' => filas, 'total' => int].
 */
function mjto_query_reviews(array $args = []) {
	global $wpdb;
	$args     = wp_parse_args($args, ['status' => '', 'service_id' => 0, 'page' => 1, 'per_page' => 50]);
	$per_page = max(1, min(200, (int) $args['per_page']));
	$offset   = (max(1, (int) $args['page']) - 1) * $per_page;
	$where    = ['1=1'];
	if (in_array((string) $args['status'], mjto_review_statuses(), true)) {
		$where[] = $wpdb->prepare('status = %s', (string) $args['status']);
	}
	if ((int) $args['service_id'] > 0) {
		$where[] = $wpdb->prepare('service_id = %d', (int) $args['service_id']);
	}
	$where_sql = implode(' AND ', $where);
	$table     = mjto_table('reviews');
	$total     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"); // phpcs:ignore WordPress.DB.PreparedSQL
	$rows      = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC " . $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	return ['items' => is_array($rows) ? $rows : [], 'total' => $total];
}

/* -------------------------------------------------------------------------
 * Shapes públicos (camelCase, para REST y panel)
 * ---------------------------------------------------------------------- */

/** Etiqueta corta de fecha en español: "sáb 12 sep". */
function mjto_date_label($datetime) {
	$ts = mjto_local_ts($datetime);
	if (null === $ts) {
		return '';
	}
	$label = wp_date('D j M', $ts, wp_timezone());
	$label = str_replace('.', '', (string) $label);
	return function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
}

/** Nombre completo (nombre + apellido) sin espacios de más. */
function mjto_full_name($first, $last) {
	return trim(trim((string) $first) . ' ' . trim((string) $last));
}

function mjto_client_public($row, $with_stats = false) {
	if (!is_array($row)) {
		return null;
	}
	$phone     = (string) ($row['phone'] ?? '');
	$wa        = mjto_whatsapp_number($phone);
	$birthdate = mjto_dt_or_null($row['birthdate'] ?? '');
	$age       = $birthdate ? mjto_age_from_birthdate($birthdate) : null;
	if (null === $age && isset($row['age']) && '' !== $row['age'] && null !== $row['age']) {
		$age = (int) $row['age'];
	}
	$public = [
		'id'        => (int) ($row['id'] ?? 0),
		'firstName' => (string) ($row['first_name'] ?? ''),
		'lastName'  => (string) ($row['last_name'] ?? ''),
		'name'      => mjto_full_name($row['first_name'] ?? '', $row['last_name'] ?? ''),
		'phone'     => $phone,
		'phoneRaw'  => (string) ($row['phone_raw'] ?? ''),
		'email'     => (string) ($row['email'] ?? ''),
		'locality'  => (string) ($row['locality'] ?? ''),
		'age'       => $age,
		'birthdate' => $birthdate,
		'userId'    => !empty($row['user_id']) ? (int) $row['user_id'] : null,
		'notes'     => (string) ($row['notes'] ?? ''),
		'extra'     => (object) mjto_json_to_array($row['extra'] ?? ''),
		'whatsapp'  => [
			'number' => $wa,
			'url'    => '' !== $wa ? 'https://wa.me/' . $wa : '',
		],
		'createdAt' => mjto_dt_or_null($row['created_at'] ?? ''),
		'updatedAt' => mjto_dt_or_null($row['updated_at'] ?? ''),
	];
	if ($with_stats) {
		$next = isset($row['next_appointment']) && is_array($row['next_appointment']) ? $row['next_appointment'] : null;
		$public['credit']          = (int) mjto_opt('credit_enabled') ? mjto_credit_balance((int) $public['id']) : 0.0;
		$public['visitsCount']     = (int) ($row['visits_count'] ?? 0);
		$public['lastVisit']       = mjto_dt_or_null($row['last_visit'] ?? '');
		$public['nextAppointment'] = $next ? [
			'id'          => (int) $next['id'],
			'startsAt'    => (string) $next['starts_at'],
			'date'        => substr((string) $next['starts_at'], 0, 10),
			'time'        => substr((string) $next['starts_at'], 11, 5),
			'dateLabel'   => mjto_date_label($next['starts_at']),
			'status'      => (string) $next['status'],
			'statusLabel' => mjto_status_label($next['status']),
		] : null;
	}
	return $public;
}

/**
 * Turno → shape público. Con $with_client incluye los datos básicos de la
 * clienta (de la fila joineada o buscándola). Los links de WhatsApp los arma
 * mjto_whatsapp_links() (inc/notifications.php); si no está, van vacíos.
 */
function mjto_appointment_public($row, $with_client = true) {
	if (!is_array($row)) {
		return null;
	}
	$starts = (string) ($row['starts_at'] ?? '');
	$ends   = (string) ($row['ends_at'] ?? '');
	$status = (string) ($row['status'] ?? 'pending');

	$client = null;
	if ($with_client) {
		if (array_key_exists('client_first_name', $row)) {
			$client = [
				'id'        => (int) ($row['client_id'] ?? 0),
				'firstName' => (string) ($row['client_first_name'] ?? ''),
				'lastName'  => (string) ($row['client_last_name'] ?? ''),
				'name'      => mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? ''),
				'phone'     => (string) ($row['client_phone'] ?? ''),
				'phoneRaw'  => (string) ($row['client_phone_raw'] ?? ''),
				'email'     => (string) ($row['client_email'] ?? ''),
				'locality'  => (string) ($row['client_locality'] ?? ''),
			];
		} else {
			$c = mjto_get_client($row['client_id'] ?? 0);
			if ($c) {
				$client = [
					'id'        => (int) $c['id'],
					'firstName' => (string) $c['first_name'],
					'lastName'  => (string) $c['last_name'],
					'name'      => mjto_full_name($c['first_name'], $c['last_name']),
					'phone'     => (string) $c['phone'],
					'phoneRaw'  => (string) $c['phone_raw'],
					'email'     => (string) $c['email'],
					'locality'  => (string) $c['locality'],
				];
			}
		}
	}
	$client_phone = $client ? $client['phone'] : (string) ($row['client_phone'] ?? '');
	$wa_number    = mjto_whatsapp_number($client_phone);
	$links        = ['confirm' => '', 'deposit' => '', 'reminder' => '', 'control' => '', 'retouch' => '', 'generic' => ''];
	if (function_exists('mjto_whatsapp_links')) {
		$built = mjto_whatsapp_links($row);
		if (is_array($built)) {
			$links = array_merge($links, $built);
		}
	}

	$visit_id = null;
	if (array_key_exists('visit_id', $row)) {
		$visit_id = $row['visit_id'] ? (int) $row['visit_id'] : null;
	} elseif (!empty($row['id'])) {
		$v        = mjto_visit_of_appointment($row['id']);
		$visit_id = $v ? (int) $v['id'] : null;
	}

	$out = [
		'id'                => (int) ($row['id'] ?? 0),
		'clientId'          => (int) ($row['client_id'] ?? 0),
		'client'            => $client,
		'serviceId'         => (int) ($row['service_id'] ?? 0),
		'serviceName'       => (string) ($row['service_name'] ?? ''),
		'startsAt'          => $starts,
		'endsAt'            => $ends,
		'date'              => substr($starts, 0, 10),
		'time'              => substr($starts, 11, 5),
		'endTime'           => substr($ends, 11, 5),
		'dateLabel'         => mjto_date_label($starts),
		'durationMin'       => (int) ($row['duration_min'] ?? 0),
		'status'            => $status,
		'statusLabel'       => mjto_status_label($status),
		'source'            => (string) ($row['source'] ?? 'web'),
		'price'             => (float) ($row['price'] ?? 0),
		'depositRequired'   => (float) ($row['deposit_required'] ?? 0),
		'depositPaid'       => (float) ($row['deposit_paid'] ?? 0),
		'depositMethod'     => (string) ($row['deposit_method'] ?? ''),
		'depositNotifiedAt' => mjto_dt_or_null($row['deposit_notified_at'] ?? ''),
		'depositDeadline'   => mjto_dt_or_null($row['deposit_deadline'] ?? ''),
		'clientNotes'       => (string) ($row['client_notes'] ?? ''),
		'internalNotes'     => (string) ($row['internal_notes'] ?? ''),
		'visitId'           => $visit_id,
		'token'             => (string) ($row['token'] ?? ''),
		'whatsapp'          => [
			'number' => $wa_number,
			'links'  => $links,
		],
		'reminderSentAt'    => mjto_dt_or_null($row['reminder_sent_at'] ?? ''),
		'reminder2SentAt'   => mjto_dt_or_null($row['reminder2_sent_at'] ?? ''),
		'cancelledAt'       => mjto_dt_or_null($row['cancelled_at'] ?? ''),
		'cancelReason'      => (string) ($row['cancel_reason'] ?? ''),
		'cancelledBy'       => (string) ($row['cancelled_by'] ?? ''),
		'creditApplied'     => (float) ($row['credit_applied'] ?? 0),
		'depositForfeited'  => (float) ($row['deposit_forfeited'] ?? 0),
		'ownerOffer'        => (string) ($row['owner_offer'] ?? ''),
		'rescheduledFrom'   => !empty($row['rescheduled_from']) ? (int) $row['rescheduled_from'] : null,
		'rescheduleCount'   => (int) ($row['reschedule_count'] ?? 0),
		'completedAt'       => mjto_dt_or_null($row['completed_at'] ?? ''),
		'reviewRequestedAt' => mjto_dt_or_null($row['review_requested_at'] ?? ''),
		'createdAt'         => mjto_dt_or_null($row['created_at'] ?? ''),
		'updatedAt'         => mjto_dt_or_null($row['updated_at'] ?? ''),
	];

	/*
	 * Comprobante de la seña, solo para el panel: la vista pública de la clienta
	 * arma su propio bloque (con token de subida) en mjto_rest_booking_view().
	 */
	if (function_exists('mjto_receipt_panel_payload') && current_user_can('mjto_manage')) {
		$out['receipt'] = mjto_receipt_panel_payload((int) $out['id']);
	}

	return $out;
}

function mjto_visit_public($row) {
	if (!is_array($row)) {
		return null;
	}
	$warnings = mjto_json_to_array($row['warnings'] ?? '');
	$public   = [
		'id'            => (int) ($row['id'] ?? 0),
		'clientId'      => (int) ($row['client_id'] ?? 0),
		'appointmentId' => !empty($row['appointment_id']) ? (int) $row['appointment_id'] : null,
		'visitedAt'     => (string) ($row['visited_at'] ?? ''),
		'serviceId'     => (int) ($row['service_id'] ?? 0),
		'serviceName'   => (string) ($row['service_name'] ?? ''),
		'data'          => (object) mjto_json_to_array($row['data'] ?? ''),
		'warnings'      => array_values($warnings),
		'total'         => (float) ($row['total'] ?? 0),
		'deposit'       => (float) ($row['deposit'] ?? 0),
		'paymentMethod' => (string) ($row['payment_method'] ?? ''),
		'controlDate'   => mjto_dt_or_null($row['control_date'] ?? ''),
		'retouchDate'   => mjto_dt_or_null($row['retouch_date'] ?? ''),
		'maintenanceSentAt' => mjto_dt_or_null($row['maintenance_sent_at'] ?? ''),
		'createdAt'     => mjto_dt_or_null($row['created_at'] ?? ''),
		'updatedAt'     => mjto_dt_or_null($row['updated_at'] ?? ''),
	];
	if (array_key_exists('client_first_name', $row)) {
		$public['clientName'] = mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? '');
	}
	return $public;
}

/** Bloqueo → shape público ({id, startsAt, endsAt, reason, allDay}). */
function mjto_block_public($row) {
	if (!is_array($row)) {
		return null;
	}
	$starts  = (string) ($row['starts_at'] ?? '');
	$ends    = (string) ($row['ends_at'] ?? '');
	$all_day = '00:00:00' === substr($starts, 11, 8) && ('23:59:59' === substr($ends, 11, 8) || '00:00:00' === substr($ends, 11, 8));
	return [
		'id'       => (int) ($row['id'] ?? 0),
		'startsAt' => $starts,
		'endsAt'   => $ends,
		'date'     => substr($starts, 0, 10),
		'reason'   => (string) ($row['reason'] ?? ''),
		'allDay'   => $all_day,
	];
}
