<?php
/**
 * Instalación: tres tablas propias y la tarea de limpieza.
 *
 *   mje_geo      códigos postales con su localidad y provincia. Se importa por
 *                CSV, se va llenando sola con lo que resuelven los proveedores
 *                y con lo que carga el cliente a mano.
 *   mje_quotes   caché de cotizaciones de los correos, para no preguntar el
 *                mismo destino y peso dos veces.
 *   mje_branches sucursales de los correos, traídas por API o cargadas a mano.
 *                Guardan latitud y longitud cuando el correo las publica, que
 *                es lo que permite ofrecerle al cliente la más cercana.
 *
 * Corre en la activación y también en cada carga cuando la versión guardada
 * quedó atrás: subir el zip nuevo encima del viejo alcanza para que aparezca
 * una columna nueva, sin desactivar ni reactivar.
 */

defined('ABSPATH') || exit;

class MJE_Install {

	const OPTION_DB_VERSION = 'mje_db_version';

	/** Subir este número cuando cambie el esquema. */
	const DB_VERSION = '2';

	const CRON_CLEAN = 'mje_clean';

	public static function table_geo() {
		global $wpdb;
		return $wpdb->prefix . 'mje_geo';
	}

	public static function table_quotes() {
		global $wpdb;
		return $wpdb->prefix . 'mje_quotes';
	}

	public static function table_branches() {
		global $wpdb;
		return $wpdb->prefix . 'mje_branches';
	}

	public static function init() {
		/* Directo: esto ya corre dentro de plugins_loaded, engancharse otra vez llegaría tarde. */
		self::maybe_upgrade();
		add_action(self::CRON_CLEAN, [__CLASS__, 'clean']);
	}

	public static function install() {
		self::create_tables();
		self::schedule();
		update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
	}

	public static function maybe_upgrade() {
		if ((string) get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
			return;
		}
		self::install();
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$geo             = self::table_geo();
		$quotes          = self::table_quotes();
		$branches        = self::table_branches();

		/*
		 * Un CP puede tener varias localidades (y una localidad, varios CP): la
		 * fila es el par, no el código. `norm` guarda el nombre comparable (sin
		 * acentos ni mayúsculas) para buscar sin depender de cómo se escribió.
		 */
		$sql = "CREATE TABLE {$geo} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	country VARCHAR(2) NOT NULL DEFAULT 'AR',
	postcode VARCHAR(12) NOT NULL,
	city VARCHAR(160) NOT NULL,
	norm VARCHAR(160) NOT NULL,
	state VARCHAR(120) NULL,
	state_code VARCHAR(10) NULL,
	source VARCHAR(20) NOT NULL DEFAULT 'manual',
	hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY place (country,postcode,norm),
	KEY postcode (country,postcode),
	KEY norm (country,norm),
	KEY state (country,state_code)
) {$charset_collate};";
		dbDelta($sql);

		$sql = "CREATE TABLE {$quotes} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	hash CHAR(40) NOT NULL,
	carrier VARCHAR(30) NOT NULL,
	method VARCHAR(60) NULL,
	cost DECIMAL(18,4) NOT NULL DEFAULT 0,
	days_min SMALLINT NOT NULL DEFAULT 0,
	days_max SMALLINT NOT NULL DEFAULT 0,
	payload LONGTEXT NULL,
	error VARCHAR(255) NULL,
	expires_at DATETIME NULL,
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY hash (hash),
	KEY carrier (carrier),
	KEY expires_at (expires_at)
) {$charset_collate};";
		dbDelta($sql);

		$sql = "CREATE TABLE {$branches} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	carrier VARCHAR(30) NOT NULL,
	code VARCHAR(60) NOT NULL,
	name VARCHAR(190) NOT NULL,
	address VARCHAR(255) NULL,
	city VARCHAR(160) NULL,
	state VARCHAR(120) NULL,
	postcode VARCHAR(12) NULL,
	hours VARCHAR(255) NULL,
	lat DECIMAL(10,7) NULL,
	lon DECIMAL(10,7) NULL,
	enabled TINYINT(1) NOT NULL DEFAULT 1,
	source VARCHAR(20) NOT NULL DEFAULT 'manual',
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY branch (carrier,code),
	KEY postcode (carrier,postcode),
	KEY enabled (enabled)
) {$charset_collate};";
		dbDelta($sql);
	}

	/**
	 * ¿Existen las tablas?
	 *
	 * Se consulta una sola vez por carga: el cálculo de tarifas pregunta esto en
	 * cada página del front y no tiene sentido un SHOW TABLES por cada una.
	 */
	public static function tables_exist() {
		static $exists = null;
		if (null !== $exists) {
			return $exists;
		}
		global $wpdb;
		$table  = self::table_geo();
		$exists = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $exists;
	}

	/* ------------------------------ Tareas ------------------------------- */

	public static function schedule() {
		if (!wp_next_scheduled(self::CRON_CLEAN)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEAN);
		}
	}

	/** Se van las cotizaciones vencidas; los CP y las sucursales quedan. */
	public static function clean() {
		if (!self::tables_exist()) {
			return;
		}
		global $wpdb;
		$table = self::table_quotes();
		$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s", current_time('mysql', true))); // phpcs:ignore WordPress.DB
	}

	/** Desactivación: se va la tarea, nunca los datos. */
	public static function deactivate() {
		wp_clear_scheduled_hook(self::CRON_CLEAN);
	}
}
