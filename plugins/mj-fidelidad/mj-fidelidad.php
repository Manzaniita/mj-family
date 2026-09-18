<?php
/**
 * Plugin Name: MJ Fidelidad
 * Description: Tarjeta de sellos para las clientas. Cada turno atendido suma sellos solo (con MJ Turnos Online) y, si se quiere, cada compra de la tienda (con WooCommerce). Al completar la tarjeta se gana el premio que elijas: un texto que canjeás a mano o saldo a favor para el próximo turno. Cuántos sellos, cuántos da cada servicio, el premio y todos los textos se configuran en Fidelidad → Ajustes.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: MJ Importaciones
 * Text Domain: mj-fidelidad
 */

defined('ABSPATH') || exit;

define('MJFID_VERSION', '1.0.0');
define('MJFID_FILE', __FILE__);
define('MJFID_DIR', plugin_dir_path(__FILE__));
define('MJFID_DB_VERSION', '1');

require_once MJFID_DIR . 'inc/opciones.php'; // Opciones, página Fidelidad → Ajustes y helpers.
require_once MJFID_DIR . 'inc/datos.php';    // Tablas, sellos, premios y la cuenta de cada tarjeta.
require_once MJFID_DIR . 'inc/fuentes.php';  // De dónde salen los sellos: turnos atendidos y compras.
require_once MJFID_DIR . 'inc/avisos.php';   // Mails de tarjeta completa.
require_once MJFID_DIR . 'inc/admin.php';    // Fidelidad → Clientas: sellos a mano y premios canjeados.
require_once MJFID_DIR . 'inc/frontend.php'; // REST /me, shortcode [mj_fidelidad] y "Mi cuenta" del tema.

register_activation_hook(__FILE__, 'mjfid_install_tables');

/* Tablas: se crean o actualizan cuando cambia MJFID_DB_VERSION (por si se actualizó sin reactivar). */
add_action('plugins_loaded', function () {
	if ((string) get_option('mjfid_db_version', '') !== (string) MJFID_DB_VERSION) {
		mjfid_install_tables();
	}
}, 5);

/* Compatible con el almacenamiento de pedidos en tablas propias (HPOS). */
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/* Link "Ajustes" en la lista de plugins. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=mjfid-ajustes')) . '">Ajustes</a>');
	return $links;
});
