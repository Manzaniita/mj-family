<?php
/**
 * Borrado del plugin.
 *
 * Desactivar MJ Envíos no borra nada: los métodos, los campos, los códigos
 * postales y las sucursales quedan en la base, así subir el zip nuevo encima del
 * viejo no pierde la configuración.
 *
 * Esto corre solo cuando se aprieta "Borrar" en la lista de plugins, y sí se
 * lleva todo lo del plugin. Lo que NO se toca son los pedidos: su envío, su
 * sucursal y su número de seguimiento son parte del historial de la tienda.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

/* Opciones. */
foreach (['mje_settings', 'mje_methods', 'mje_fields', 'mje_carriers', 'mje_db_version', 'mje_log'] as $option) {
	delete_option($option);
}

/* Tablas propias. */
foreach (['mje_geo', 'mje_quotes', 'mje_branches'] as $table) {
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB
}

/* Tokens y cachés en transients. */
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mje_%' OR option_name LIKE '_transient_timeout_mje_%'"); // phpcs:ignore WordPress.DB

/* Peso por defecto cargado en productos y categorías. */
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_mje_weight']); // phpcs:ignore WordPress.DB
$wpdb->delete($wpdb->termmeta, ['meta_key' => 'mje_weight']); // phpcs:ignore WordPress.DB

/* Tarea programada. */
wp_clear_scheduled_hook('mje_clean');
