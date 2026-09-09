<?php
/**
 * Se ejecuta al BORRAR el plugin desde Plugins.
 *
 * Borra la configuración, los métodos propios y sus opciones de gateway en
 * WooCommerce. NO toca pedidos (conservan método y ajuste como historial),
 * ni la exclusión marcada en productos y categorías.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

foreach (['mjpm_settings', 'mjpm_methods', 'mjpm_custom'] as $option) {
	delete_option($option);
}
delete_transient('mjpm_activated');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\_mjpm\_%\_settings'");
