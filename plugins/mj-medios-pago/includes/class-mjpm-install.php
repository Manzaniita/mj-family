<?php
/**
 * Instalación: la tabla de comprobantes, la carpeta de la bóveda, las opciones
 * que faltan y las dos tareas programadas (retención y recordatorios).
 *
 * Se corre en la activación y también en cada carga cuando la versión guardada
 * quedó atrás, así subir el zip nuevo encima del viejo alcanza: no hace falta
 * desactivar y reactivar para que aparezca una columna nueva.
 */

defined('ABSPATH') || exit;

class MJPM_Install {

	const OPTION_DB_VERSION = 'mjpm_db_version';

	/** Subir este número cuando cambie el esquema. */
	const DB_VERSION = '2';

	const CRON_PURGE  = 'mjpm_purge_receipts';
	const CRON_REMIND = 'mjpm_remind_receipts';

	/** Nombre completo de la tabla de comprobantes. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mjpm_receipts';
	}

	public static function init() {
		/*
		 * Directo y no por hook: esto ya corre dentro de plugins_loaded, así que
		 * engancharse a plugins_loaded otra vez llegaría tarde y nunca se ejecutaría.
		 */
		self::maybe_upgrade();
	}

	/** Activación del plugin. */
	public static function activate() {
		self::install();
	}

	/** Idempotente: se puede llamar todas las veces que haga falta. */
	public static function install() {
		self::create_table();
		MJPM_Vault::ensure();
		self::schedule();
		update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
	}

	/** Si el zip nuevo trae un esquema más nuevo, lo aplica sin pedir nada. */
	public static function maybe_upgrade() {
		if ((string) get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
			return;
		}
		self::install();
	}

	/**
	 * Tabla de comprobantes.
	 *
	 * Una fila por archivo, no por compra: una transferencia partida en dos se
	 * sube como dos archivos y cada uno se revisa por separado. Que la compra
	 * quede aprobada o no es una decisión aparte, y vive en el objeto de cada
	 * contexto (el estado del pedido, el pago del ERP, el turno).
	 *
	 * `context` + `object_id` es a qué está pegado el archivo; el contexto
	 * 'order' son pedidos de WooCommerce y los demás los registran otros
	 * plugins de la familia (ver MJPM_Receipts::contexts()).
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	context VARCHAR(30) NOT NULL DEFAULT 'order',
	object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	method VARCHAR(60) NULL,
	amount DECIMAL(18,4) NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	reference VARCHAR(190) NULL,
	note VARCHAR(255) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
	reason VARCHAR(255) NULL,
	file_name VARCHAR(255) NULL,
	file_path VARCHAR(255) NULL,
	file_type VARCHAR(100) NULL,
	file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
	file_hash VARCHAR(64) NULL,
	purged TINYINT(1) NOT NULL DEFAULT 0,
	uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
	uploaded_ip VARCHAR(100) NULL,
	reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
	reviewed_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY object (context,object_id),
	KEY status (status),
	KEY created_at (created_at),
	KEY file_hash (file_hash)
) {$charset_collate};";

		dbDelta($sql);
	}

	/**
	 * ¿Existe la tabla?
	 *
	 * Se consulta una sola vez por carga: MJPM_Receipts::enabled() pregunta esto
	 * en cada página del front y en cada fila de la lista de pedidos, y no tiene
	 * sentido mandar un SHOW TABLES por cada una.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		static $exists = null;
		if (null !== $exists) {
			return $exists;
		}
		global $wpdb;
		$table  = self::table();
		$exists = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $exists;
	}

	/* ----------------------------- Tareas --------------------------------- */

	public static function schedule() {
		if (!wp_next_scheduled(self::CRON_PURGE)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE);
		}
		if (!wp_next_scheduled(self::CRON_REMIND)) {
			wp_schedule_event(time() + (2 * HOUR_IN_SECONDS), 'hourly', self::CRON_REMIND);
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook(self::CRON_PURGE);
		wp_clear_scheduled_hook(self::CRON_REMIND);
	}

	/** Desactivación: se van las tareas, nunca los datos. */
	public static function deactivate() {
		self::unschedule();
	}
}
