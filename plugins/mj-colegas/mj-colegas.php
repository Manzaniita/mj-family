<?php
/**
 * Plugin Name: MJ Colegas
 * Description: Productos de colegas dentro de la tienda. Cada producto lleva quién lo hizo; la tienda cobra y después liquida a cada colega descontando su comisión (10% por defecto, configurable por colega). En el tema MJ Appearance agrega el chip "Hecho por", el filtro en la tienda, la sección en la portada y la página del proyecto con una página por colega.
 * Version: 1.0.1
 * Author: MJ
 * Requires Plugins: woocommerce
 * Text Domain: mj-colegas
 */

defined('ABSPATH') || exit;

define('MJC_VERSION', '1.0.1');
define('MJC_FILE', __FILE__);
define('MJC_DIR', plugin_dir_path(__FILE__));
define('MJC_TAX', 'mj_colega');

require_once MJC_DIR . 'inc/options.php';   // Opciones, página de ajustes y helpers.
require_once MJC_DIR . 'inc/taxonomy.php';  // Taxonomía "colega" en productos + ficha de cada colega.
require_once MJC_DIR . 'inc/orders.php';    // Sella en cada línea de pedido quién la hizo y la comisión.
require_once MJC_DIR . 'inc/frontend.php';  // Store API + window.MJ.colegas + rutas de la SPA.
require_once MJC_DIR . 'inc/report.php';    // Liquidaciones (reporte, marcar como pagado, CSV).

/* Compatible con el almacenamiento de pedidos en tablas propias (HPOS). */
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/* Aviso en wp-admin si WooCommerce está inactivo. */
add_action('admin_notices', function () {
	if (class_exists('WooCommerce') || !current_user_can('activate_plugins')) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>MJ Colegas:</strong> necesita WooCommerce activo para asignar colegas a los productos y liquidar comisiones.</p></div>';
});

/* Link "Ajustes" en la lista de plugins. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=mjc')) . '">Ajustes</a>');
	return $links;
});
