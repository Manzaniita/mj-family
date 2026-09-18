<?php
/**
 * Borrar MJ Fidelidad (desde Plugins → Borrar; desactivar no pasa por acá).
 *
 * Los sellos y los premios son historia de las clientas: solo se borran si en
 * Fidelidad → Ajustes se marcó «Al borrar el plugin». Sin eso queda todo en la
 * base por si se vuelve a instalar.
 *
 * @package MJ_Fidelidad
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$mjfid_options = get_option('mjfid_options', []);
if (!is_array($mjfid_options) || empty($mjfid_options['delete_on_uninstall'])) {
	return;
}

foreach (['stamps', 'rewards'] as $mjfid_table) {
	$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'mjfid_' . $mjfid_table . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- nombre fijo de la lista de arriba.
}

/* Los sellos que da cada servicio de MJ Turnos (meta en sus fichas). */
delete_post_meta_by_key('_mjfid_stamps');

delete_option('mjfid_options');
delete_option('mjfid_db_version');
