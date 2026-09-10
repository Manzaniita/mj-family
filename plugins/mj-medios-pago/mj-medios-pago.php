<?php
/**
 * Plugin Name: MJ Medios de Pago
 * Description: Descuentos y recargos por medio de pago con precio distinto en tarjetas, ficha, carrito y checkout, y comprobantes de pago: elegís en qué medios se pide, el cliente sube la foto o el PDF y vos aprobás o rechazás cada compra. Funciona con cualquier método de pago de la tienda, te deja crear métodos propios (por ejemplo "Pago en dólares en el local") como medios de pago reales de WooCommerce, y comparte los comprobantes con MJ ERP (reventas) y MJ Turnos Online (señas).
 * Version: 1.2.0
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
 * COMPROBANTES DE PAGO
 *   - Elegís medio por medio si pide comprobante (no / opcional / obligatorio).
 *     Lo que quede en "Heredar" sigue la regla general, así subir un default
 *     después alcanza a todos los métodos que nunca tocaste a mano.
 *   - El cliente lo sube DESPUÉS de comprar, con el pedido en el estado que
 *     elegiste: desde la página de gracias, desde Mi cuenta, o con un link
 *     firmado si compró sin cuenta.
 *   - Vos aprobás o rechazás cada compra desde WooCommerce → Comprobantes (con
 *     contador en el menú) o desde la pantalla del pedido. Al rechazar, el
 *     motivo es lo que ve el cliente.
 *   - Los archivos NO van a la Biblioteca de Medios: viven en una carpeta
 *     privada con nombre aleatorio y se leen solo por un endpoint que valida
 *     permiso (ver class-mjpm-vault.php).
 *
 * INTEGRACIÓN CON OTROS PLUGINS
 *   MJPM_Methods::price_for($pesos, MJPM_Methods::config($id))   precio con un método
 *   MJPM_Methods::public_payload()                                 config pública (la misma que ve la SPA)
 *   MJPM_Cart::effective_method_id()                               método vigente en la sesión
 *   filtros mjpm_product_eligible ($ok, $product) · mjpm_cart_adjustment ($calc, $cart, $cfg)
 *   REST    GET /wp-json/mjpm/v1/methods · GET /wp-json/mjpm/v1/price?amount=100000&method=bacs
 *
 *   Comprobantes (los usan MJ ERP y MJ Turnos Online):
 *   filtro  mjpm_receipt_contexts                      registrar un contexto propio
 *   MJPM_Receipts_Front::widget($contexto, $id)        el widget de subida, igual en todas partes
 *   MJPM_Receipts::for_object($contexto, $id)          lo que ya subió
 *   MJPM_Receipts::state($contexto, $id)               pendiente | aprobado | rechazado
 *   MJPM_Mapping::requirement_for_erp($clave)          la regla del gateway equivalente
 *   acciones mjpm_receipt_created · mjpm_receipt_reviewed · mjpm_receipt_object_reviewed
 *   REST    /wp-json/mjpm/v1/comprobantes (ver class-mjpm-receipts-rest.php)
 *
 * ACTUALIZACIÓN
 *   Plugins > MJ Medios de Pago > Desactivar y Borrar, después subís el zip nuevo.
 *   (No se pierde nada: la configuración, los métodos propios y los comprobantes
 *   quedan en la base. Borrar el plugin sí borra los archivos: ver uninstall.php.)
 */

defined('ABSPATH') || exit;

define('MJPM_VERSION', '1.2.0');
define('MJPM_FILE', __FILE__);
define('MJPM_DIR', plugin_dir_path(__FILE__));
define('MJPM_URL', plugin_dir_url(__FILE__));

require_once MJPM_DIR . 'includes/class-mjpm-settings.php';
require_once MJPM_DIR . 'includes/class-mjpm-methods.php';
require_once MJPM_DIR . 'includes/class-mjpm-cart.php';
require_once MJPM_DIR . 'includes/class-mjpm-rest.php';
require_once MJPM_DIR . 'includes/class-mjpm-orders.php';
require_once MJPM_DIR . 'includes/class-mjpm-admin.php';

/* Comprobantes de pago. */
require_once MJPM_DIR . 'includes/class-mjpm-install.php';
require_once MJPM_DIR . 'includes/class-mjpm-vault.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts.php';
require_once MJPM_DIR . 'includes/class-mjpm-mapping.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts-mail.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts-rest.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts-front.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts-admin.php';
require_once MJPM_DIR . 'includes/class-mjpm-receipts-settings-screen.php';

final class MJPM_Plugin {

	public static function init() {
		/*
		 * Los comprobantes arrancan ANTES del chequeo de WooCommerce: la bóveda y
		 * la bandeja las usan también MJ Turnos Online y MJ ERP, y una tienda de
		 * turnos puede no tener WooCommerce instalado.
		 */
		MJPM_Install::init();
		MJPM_Receipts::init();
		MJPM_Mapping::init();
		MJPM_Receipts_Mail::init();
		MJPM_Receipts_REST::init();
		MJPM_Receipts_Front::init();
		if (is_admin()) {
			MJPM_Receipts_Admin::init();
		}

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
		if (false === get_option(MJPM_Settings::RECEIPTS)) {
			add_option(MJPM_Settings::RECEIPTS, MJPM_Settings::receipt_defaults());
		}
		if (false === get_option(MJPM_Settings::MAPPING)) {
			add_option(MJPM_Settings::MAPPING, []);
		}
		MJPM_Install::activate();
		set_transient('mjpm_activated', 1, 5 * MINUTE_IN_SECONDS);
	}

	public static function deactivate() {
		MJPM_Install::deactivate();
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
register_deactivation_hook(MJPM_FILE, ['MJPM_Plugin', 'deactivate']);
add_action('plugins_loaded', ['MJPM_Plugin', 'init']);
