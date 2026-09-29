<?php
/**
 * Plugin Name: MJ Turnos Online
 * Description: Reserva de turnos online con agenda, ficha técnica por clienta, seña, recordatorios y panel para la dueña. La clienta elige servicio, día y hora desde /turnos; la administradora entra a la misma URL y ve el panel. Se integra con el tema MJ Appearance (menú, portada, página de servicios) y funciona con cualquier tema mediante el shortcode [mj_turnos]. No necesita WooCommerce.
 * Version: 1.4.1
 * Author: MJ
 * Text Domain: mj-turnos-online
 */

defined('ABSPATH') || exit;

define('MJTO_VERSION', '1.4.1');
define('MJTO_FILE', __FILE__);
define('MJTO_DIR', plugin_dir_path(__FILE__));
define('MJTO_URL', plugin_dir_url(__FILE__));
define('MJTO_CAP', 'mjto_manage');
define('MJTO_DB_VERSION', '2');

require_once MJTO_DIR . 'inc/compartido/class-mj-menu.php'; // El menú único MJ de wp-admin (copia compartida).
MJ_Menu::init();

require_once MJTO_DIR . 'inc/options.php';       // Opciones, pantalla MJ → Turnos, menú y helpers.
require_once MJTO_DIR . 'inc/db.php';            // Tablas, CRUD y shapes públicos.
require_once MJTO_DIR . 'inc/services.php';      // CPT mjto_service + metabox + helpers.
require_once MJTO_DIR . 'inc/availability.php';  // Motor de disponibilidad.
require_once MJTO_DIR . 'inc/bookings.php';      // Crear reserva, estados, ficha automática, seña.
require_once MJTO_DIR . 'inc/accounts.php';      // Cuenta de la clienta: alta en la reserva, "Mis turnos", precarga.
require_once MJTO_DIR . 'inc/policies.php';      // Reprogramar y cancelar según las reglas, saldo a favor, cancelación de la dueña.
require_once MJTO_DIR . 'inc/reviews.php';       // Opiniones de las clientas atendidas.
require_once MJTO_DIR . 'inc/notifications.php'; // Plantillas, mails, links de WhatsApp, cron.
require_once MJTO_DIR . 'inc/receipts.php';      // Comprobante de la seña (sobre MJ Medios de Pago).
require_once MJTO_DIR . 'inc/rest.php';          // REST mjto/v1 (público + panel).
require_once MJTO_DIR . 'inc/panel.php';         // Intercepción de /{slug} para el panel + export CSV.
require_once MJTO_DIR . 'inc/frontend.php';      // Integración con MJ Appearance + shortcode [mj_turnos].

/* -------------------------------------------------------------------------
 * Activación / desactivación
 * ---------------------------------------------------------------------- */
register_activation_hook(__FILE__, 'mjto_activate');
function mjto_activate() {
	if (function_exists('mjto_install_tables')) {
		mjto_install_tables();
	}
	add_option('mjto_db_version', MJTO_DB_VERSION, '', false);
	if (function_exists('mjto_sync_panel_caps')) {
		mjto_sync_panel_caps();
	} else {
		$role = get_role('administrator');
		if ($role) {
			$role->add_cap(MJTO_CAP);
		}
	}
	if (!wp_next_scheduled('mjto_hourly')) {
		wp_schedule_event(time(), 'hourly', 'mjto_hourly');
	}
	if (!wp_next_scheduled('mjto_tick')) {
		wp_schedule_event(time(), 'mjto_5min', 'mjto_tick');
	}
}

/* Al desactivar solo se apaga el cron: las tablas y los datos quedan (ver LEEME). */
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('mjto_hourly');
	wp_clear_scheduled_hook('mjto_tick');
});

/*
 * Tarea frecuente: la seña que vence a la hora y el recordatorio de "una hora
 * antes" no pueden esperar a la tarea horaria. Cada cuántos minutos corre es
 * un filtro (mjto_tick_minutes); WordPress la dispara cuando hay visitas, así
 * que con un cron real del hosting es exacta.
 */
add_filter('cron_schedules', function ($schedules) { // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 5 minutos por defecto: menos de 15 a propósito (la seña vence a la hora).
	$minutes = max(1, (int) apply_filters('mjto_tick_minutes', 5));
	$schedules['mjto_5min'] = [
		'interval' => $minutes * MINUTE_IN_SECONDS,
		'display'  => 'MJ Turnos: tarea frecuente',
	];
	return $schedules;
});

/* -------------------------------------------------------------------------
 * Garantías en cada carga: capability, cron y esquema de la base
 * ---------------------------------------------------------------------- */

/* Por si se actualizó el plugin sin reactivarlo: administrator siempre puede abrir el panel. */
add_action('init', function () {
	$role = get_role('administrator');
	if ($role && !$role->has_cap(MJTO_CAP)) {
		$role->add_cap(MJTO_CAP);
	}
	if (!wp_next_scheduled('mjto_hourly')) {
		wp_schedule_event(time(), 'hourly', 'mjto_hourly');
	}
	if (!wp_next_scheduled('mjto_tick')) {
		wp_schedule_event(time(), 'mjto_5min', 'mjto_tick');
	}
}, 5);

/* Tablas: se crean o actualizan cuando cambia MJTO_DB_VERSION. */
add_action('plugins_loaded', function () {
	if (function_exists('mjto_maybe_upgrade_db')) {
		mjto_maybe_upgrade_db();
	}
}, 5);

/* -------------------------------------------------------------------------
 * Lista de plugins y avisos
 * ---------------------------------------------------------------------- */

/* Link "Ajustes" en la lista de plugins. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=mjto')) . '">Ajustes</a>');
	return $links;
});

/* Aviso si falta compilar la app (panel, widget y constructor de campos). */
add_action('admin_notices', function () {
	if (!current_user_can('activate_plugins') || file_exists(MJTO_DIR . 'dist/app.js')) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>MJ Turnos Online:</strong> falta compilar la app del plugin (<code>dist/app.js</code>). En la carpeta <code>app/</code> ejecutá <code>npm install</code> y <code>npm run build</code>. Sin eso, el panel, el widget de reserva y el constructor de campos no se muestran.</p></div>';
});
