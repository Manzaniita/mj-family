<?php
/**
 * Plugin Name: MJ Medios de Pago
 * Description: Descuentos y recargos por medio de pago con precio distinto en tarjetas, ficha, carrito y checkout. Funciona con cualquier método de pago de la tienda y te deja crear métodos propios (por ejemplo "Pago en dólares en el local") como medios de pago reales de WooCommerce. El ajuste va como línea aparte del pedido, con su justificación.
 * Version: 1.0.0
 * Author: MJ Importaciones
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Text Domain: mj-medios-pago
 *
 * CÓMO FUNCIONA
 *   - Elegís qué medio de pago tiene el PRECIO BASE (el precio cargado en
 *     WooCommerce). Los demás llevan descuento o recargo: en porcentaje (cambia
 *     el precio unitario, se ve en tarjetas y ficha) o monto fijo por pedido
 *     (solo en el carrito).
 *   - El ajuste entra al pedido como una línea aparte ("Descuento por pago con
 *     transferencia (10%)") calculada ítem por ítem con el mismo redondeo que
 *     ven las tarjetas: el total coincide exacto con lo que vio el cliente.
 *   - El visitante elige con qué medio ver los precios (selector en el header y
 *     en el carrito, tema MJ Appearance 3.3+). El carrito del servidor se
 *     sincroniza por la Store API (cart/extensions, namespace "mjpm").
 *   - Métodos propios: gateways offline reales (checkout, pedidos, emails,
 *     informes). Pueden marcarse "se cobra en dólares": con MJ Dólar activo el
 *     checkout, el email y el admin muestran el total en USD.
 *   - Productos y categorías pueden excluirse del ajuste.
 *
 * INTEGRACIÓN CON OTROS PLUGINS
 *   MJPM_Methods::price_for($pesos, MJPM_Methods::config($id))   precio con un método
 *   MJPM_Methods::public_payload()                                 config pública (la misma que ve la SPA)
 *   MJPM_Cart::effective_method_id()                               método vigente en la sesión
 *   filtros mjpm_product_eligible ($ok, $product) · mjpm_cart_adjustment ($calc, $cart, $cfg)
 *   REST    GET /wp-json/mjpm/v1/methods · GET /wp-json/mjpm/v1/price?amount=100000&method=bacs
 *
 * ACTUALIZACIÓN
 *   Plugins > MJ Medios de Pago > Desactivar y Borrar, después subís el zip nuevo.
 *   (No se pierde nada: la configuración y los métodos propios quedan en la base.)
 */

defined('ABSPATH') || exit;

define('MJPM_VERSION', '1.0.0');
define('MJPM_FILE', __FILE__);
define('MJPM_DIR', plugin_dir_path(__FILE__));
define('MJPM_URL', plugin_dir_url(__FILE__));

require_once MJPM_DIR . 'includes/class-mjpm-settings.php';
require_once MJPM_DIR . 'includes/class-mjpm-methods.php';
require_once MJPM_DIR . 'includes/class-mjpm-cart.php';
require_once MJPM_DIR . 'includes/class-mjpm-rest.php';
require_once MJPM_DIR . 'includes/class-mjpm-orders.php';
require_once MJPM_DIR . 'includes/class-mjpm-admin.php';

final class MJPM_Plugin {

	public static function init() {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', [__CLASS__, 'notice_no_woo']);
			return;
		}
		MJPM_Methods::init();
		MJPM_Cart::init();
		MJPM_REST::init();
		MJPM_Orders::init();
		if (is_admin()) {
			MJPM_Admin::init();
		}
	}

	public static function activate() {
		if (false === get_option(MJPM_Settings::OPTION)) {
			add_option(MJPM_Settings::OPTION, MJPM_Settings::defaults());
		}
		if (false === get_option(MJPM_Settings::METHODS)) {
			add_option(MJPM_Settings::METHODS, []);
		}
		if (false === get_option(MJPM_Settings::CUSTOM)) {
			add_option(MJPM_Settings::CUSTOM, []);
		}
		set_transient('mjpm_activated', 1, 5 * MINUTE_IN_SECONDS);
	}

	public static function notice_no_woo() {
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MJ Medios de Pago:</strong> necesita WooCommerce activo para funcionar.</p></div>';
	}
}

add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MJPM_FILE, true);
	}
});

register_activation_hook(MJPM_FILE, ['MJPM_Plugin', 'activate']);
add_action('plugins_loaded', ['MJPM_Plugin', 'init']);
