<?php
/**
 * Se ejecuta al BORRAR el plugin desde Plugins.
 *
 * Borra ajustes, historial, colas y transients. NO borra los precios USD de
 * los productos ni el TC guardado en los pedidos: son datos del catálogo y
 * de la contabilidad, y reaparecen intactos si volvés a instalar el plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

foreach (['mjd_settings', 'mjd_rate_history', 'mjd_sync_state', 'mjd_sync_queue'] as $option) {
	delete_option($option);
}
delete_transient('mjd_sync_lock');
delete_transient('mjd_activated');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mjd\_suggest\_%' OR option_name LIKE '\_transient\_timeout\_mjd\_suggest\_%'");
