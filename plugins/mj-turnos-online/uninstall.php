<?php
/**
 * Borrar MJ Turnos Online (desde Plugins → Borrar; desactivar no pasa por acá).
 *
 * Siempre: el permiso del panel, las tareas programadas, los locks de fecha y
 * los contadores anti-spam. Las clientas, los turnos, las fichas, el saldo a
 * favor, las opiniones, los servicios (con sus categorías) y los ajustes solo si en Turnos → Ajustes → El panel se marcó «Al borrar el
 * plugin»: son datos de personas y no se borran por un click de más.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$mjto_options = get_option('mjto_options', []);
$mjto_borrar  = is_array($mjto_options) && !empty($mjto_options['delete_on_uninstall']);

/* ----------------------------- Siempre ------------------------------- */

/* El permiso del panel no significa nada sin el plugin; se vuelve a dar al activar. */
$mjto_roles = wp_roles();
if ($mjto_roles instanceof WP_Roles) {
	foreach (array_keys($mjto_roles->roles) as $mjto_role_key) {
		$mjto_role = $mjto_roles->get_role($mjto_role_key);
		if ($mjto_role && $mjto_role->has_cap('mjto_manage')) {
			$mjto_role->remove_cap('mjto_manage');
		}
	}
}

wp_clear_scheduled_hook('mjto_hourly');
wp_clear_scheduled_hook('mjto_tick');

/* Locks de fecha (filas en wp_options), contadores por IP y la marca del vencimiento de señas (transients). */
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('mjto_lock_') . '%',
		$wpdb->esc_like('_transient_mjto_rl_') . '%',
		$wpdb->esc_like('_transient_timeout_mjto_rl_') . '%',
		$wpdb->esc_like('_transient_mjto_rr_') . '%',
		$wpdb->esc_like('_transient_timeout_mjto_rr_') . '%',
		$wpdb->esc_like('_transient_') . '%mjto_expire_check'
	)
);

if (!$mjto_borrar) {
	return;
}

/* -------------------- Solo si se pidió borrar todo -------------------- */

foreach (['clients', 'appointments', 'visits', 'blocks', 'credits', 'reviews'] as $mjto_table) {
	$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'mjto_' . $mjto_table . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- nombre fijo de la lista de arriba.
}

/* Los servicios son un tipo de contenido propio: sus posts y sus metas. */
$mjto_services = get_posts([
	'post_type'   => 'mjto_service',
	'post_status' => 'any',
	'numberposts' => -1,
	'fields'      => 'ids',
]);
foreach ((array) $mjto_services as $mjto_service_id) {
	wp_delete_post((int) $mjto_service_id, true);
}

/* Las categorías de servicios (la taxonomía no está registrada acá: se borran por SQL). */
$mjto_terms = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'mjto_service_cat')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ((array) $mjto_terms as $mjto_term_id) {
	$wpdb->delete($wpdb->termmeta, ['term_id' => (int) $mjto_term_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", (int) $mjto_term_id, 'mjto_service_cat'))], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete($wpdb->term_taxonomy, ['term_id' => (int) $mjto_term_id, 'taxonomy' => 'mjto_service_cat'], ['%d', '%s']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete($wpdb->terms, ['term_id' => (int) $mjto_term_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

delete_option('mjto_options');
delete_option('mjto_db_version');

wp_cache_flush();
