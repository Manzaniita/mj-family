<?php
/**
 * Plugin Name: MJ Envíos
 * Description: Métodos de envío propios, creados y editados desde el panel: a domicilio, a sucursal del correo, retiro en el local, zona propia por código postal, envío gratis o "a coordinar". Cada método decide con qué condiciones aparece (destino, CP, provincia, monto, peso, productos, usuario), con qué medios de pago se combina, qué datos le pide al cliente y de dónde sale su precio: tabla propia, precio fijo o cotización en vivo con Vía Cargo, Andreani o Correo Argentino. El checkout de la tienda lo muestra paso a paso, valida el código postal y completa provincia y localidad solo.
 * Version: 1.7.0
 * Author: MJ
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Text Domain: mj-envios
 *
 * CÓMO FUNCIONA
 *   - Los métodos de envío son tuyos: se crean, se editan, se ordenan, se
 *     activan, se duplican y se borran desde WooCommerce → Envíos. Ninguno
 *     viene fijo en el código; lo que trae la activación son ejemplos que se
 *     editan o se borran como cualquier otro.
 *   - El TIPO del método es lo único que cambia de dónde sale el precio: tabla
 *     propia (por CP, provincia o localidad, y por peso), precio fijo,
 *     cotización en vivo con un correo, gratis, retiro en el local o "a
 *     coordinar" (sin precio, se arregla después de la compra).
 *   - Cada método tiene sus CONDICIONES: a qué destinos aparece (país,
 *     provincia, rangos de CP, localidad), con qué monto, peso, cantidad,
 *     productos o categorías, para qué usuarios y con qué medios de pago. Si no
 *     cumple, no se ofrece. La regla de medios de pago filtra en los dos
 *     sentidos: el método de envío elegido puede esconder gateways del checkout.
 *   - Cada método define QUÉ DATOS pide (DNI, piso, entre calles, quién recibe,
 *     horario, sucursal, o campos propios que agregás vos), cuáles son
 *     obligatorios y con qué texto se muestran. Lo que quede en "heredar" sigue
 *     el ajuste general, así cambiar la regla general alcanza a todos los
 *     métodos que nunca tocaste a mano.
 *   - Las tarifas entran al carrito como tarifas reales de WooCommerce (filtro
 *     woocommerce_package_rates), así que valen igual en la tienda React (Store
 *     API), en el checkout clásico, en los emails y en los informes. No hace
 *     falta configurar ninguna zona de envío para los métodos propios.
 *
 * CORREOS CON SU PROPIO PLUGIN
 *   - Cuando el correo ya tiene su plugin oficial instalado y conectado
 *     (Andreani, Correo Argentino), MJ Envíos NO le pide las credenciales de
 *     nuevo ni rehace su API: lee la configuración que ya está cargada, se
 *     queda con la tarifa que ese plugin calculó y le aplica encima las reglas
 *     de la casa (envío gratis, excluidos, margen, redondeo, nombre, orden).
 *   - Cada servicio suyo que cotiza queda como un método propio, editable, sin
 *     cargarlo a mano (ver MJE_Native y MJE_Rates::adopt_native).
 *   - Todo esto es opcional y sin dependencia en ninguna dirección: si el
 *     plugin del correo no está, MJ Envíos no lo nombra; y ese plugin nunca se
 *     entera de que MJ Envíos existe.
 *
 * CÓDIGO POSTAL, PROVINCIA Y LOCALIDAD
 *   - El CP se valida y completa provincia y localidad con los proveedores que
 *     elijas y en el orden que elijas: la tabla propia del plugin (importable
 *     por CSV y que se va llenando sola), la API pública del Estado (Georef,
 *     apis.datos.gob.ar) y el correo conectado. Todo lo resuelto se guarda en la
 *     tabla propia, así la segunda vez es instantáneo y no depende de nadie.
 *   - Las provincias salen de WooCommerce (país base de la tienda), no de una
 *     lista escrita acá: la tienda puede cambiar de país sin tocar el plugin.
 *
 * CORREOS
 *   - Vía Cargo, Andreani y Correo Argentino se configuran con sus credenciales
 *     y sus URLs desde el panel (endpoint, cliente, contrato, servicio). Sin
 *     credenciales el correo queda apagado y sus métodos cotizan con la tabla
 *     propia, que es también el respaldo cuando la API no responde. Esto es
 *     para los correos SIN plugin propio: los que lo tienen entran por el
 *     puente y no se configuran acá.
 *   - Agregar otro correo es registrar una clase con el filtro mje_carriers; si
 *     ese correo tiene plugin oficial, el filtro es mje_native_plugins.
 *
 * INTEGRACIÓN CON LA FAMILIA MJ
 *   MJ Appearance      paso de envío en el checkout, autocompletado del CP y
 *                      seguimiento en Mi cuenta (mj_app_config, mj_rest_order_detail).
 *   MJ Medios de Pago  la condición por medio de pago usa los gateways reales,
 *                      los propios del plugin de pagos incluidos.
 *   MJ ERP             la venta capturada ya trae el envío cobrado; el costo
 *                      real del correo queda en el pedido y en la nota.
 *   MJ Dólar           las tablas se cargan en pesos; el total en dólares lo
 *                      sigue resolviendo el medio de pago.
 *
 * PARA OTROS PLUGINS
 *   MJE_Methods::available($package)       métodos que cumplen condiciones
 *   MJE_Rates::quote($def, $package)       precio de un método para un paquete
 *   MJE_Geo::resolve_cp($cp)               provincia y localidades de un CP
 *   MJE_Orders::shipment($order)           el envío guardado en un pedido
 *   filtros mje_carriers · mje_method_available · mje_quote · mje_fields ·
 *           mje_package_weight · mje_geo_cp
 *   REST    /wp-json/mje/v1/config · /geo/cp · /geo/localidades · /provincias ·
 *           /sucursales · /cotizar · /seguimiento
 *
 * ACTUALIZACIÓN
 *   Plugins → MJ Envíos → Desactivar y Borrar, después subís el zip nuevo. No se
 *   pierde nada: métodos, condiciones, campos, tablas de precios y códigos
 *   postales quedan en la base (borrar el plugin sí borra: ver uninstall.php).
 */

defined('ABSPATH') || exit;

define('MJE_VERSION', '1.7.0');
define('MJE_FILE', __FILE__);
define('MJE_DIR', plugin_dir_path(__FILE__));
define('MJE_URL', plugin_dir_url(__FILE__));

/* Cuaderno de errores compartido de la familia MJ. La copia maestra esta en
   shared/class-mj-log.php; esta es una copia. Ver tools/sincronizar-compartido.ps1. */
require_once MJE_DIR . 'includes/compartido/class-mj-log.php';
MJ_Log::init();

require_once MJE_DIR . 'includes/class-mje-install.php';
require_once MJE_DIR . 'includes/class-mje-settings.php';
require_once MJE_DIR . 'includes/class-mje-geo.php';
require_once MJE_DIR . 'includes/class-mje-fields.php';
require_once MJE_DIR . 'includes/class-mje-groups.php';
require_once MJE_DIR . 'includes/class-mje-methods.php';
require_once MJE_DIR . 'includes/carriers/class-mje-carrier.php';
require_once MJE_DIR . 'includes/carriers/class-mje-carrier-viacargo.php';
require_once MJE_DIR . 'includes/carriers/class-mje-carrier-andreani.php';
require_once MJE_DIR . 'includes/carriers/class-mje-carrier-correo.php';
require_once MJE_DIR . 'includes/carriers/class-mje-carriers.php';
require_once MJE_DIR . 'includes/nativos/class-mje-native-plugin.php';
require_once MJE_DIR . 'includes/nativos/class-mje-native-andreani.php';
require_once MJE_DIR . 'includes/nativos/class-mje-native-correo.php';
require_once MJE_DIR . 'includes/nativos/class-mje-native.php';
require_once MJE_DIR . 'includes/class-mje-rates.php';
require_once MJE_DIR . 'includes/class-mje-cart.php';
require_once MJE_DIR . 'includes/class-mje-orders.php';
require_once MJE_DIR . 'includes/class-mje-tracking.php';
require_once MJE_DIR . 'includes/class-mje-shipments.php';
require_once MJE_DIR . 'includes/class-mje-probe.php';
require_once MJE_DIR . 'includes/class-mje-ready.php';
require_once MJE_DIR . 'includes/class-mje-rest.php';
require_once MJE_DIR . 'includes/class-mje-admin.php';

final class MJE_Plugin {

	public static function init() {
		MJE_Install::init();

		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', [__CLASS__, 'notice_no_woo']);
			return;
		}

		MJE_Groups::init();
		MJE_Carriers::init();
		MJE_Native::init();
		MJE_Rates::init();
		MJE_Cart::init();
		MJE_Orders::init();
		MJE_Tracking::init();
		MJE_Shipments::init();
		MJE_Ready::init();
		MJE_REST::init();
		if (is_admin()) {
			MJE_Admin::init();
		}
	}

	/**
	 * Activación.
	 *
	 * Crea las tablas y deja los ajustes por defecto. Los métodos de ejemplo se
	 * cargan SOLO la primera vez y solo si no hay ninguno guardado: están para
	 * que la pantalla no arranque vacía y se editan o se borran como cualquier
	 * otro.
	 */
	public static function activate() {
		if (false === get_option(MJE_Settings::OPTION)) {
			add_option(MJE_Settings::OPTION, MJE_Settings::defaults());
		}
		if (false === get_option(MJE_Settings::FIELDS)) {
			add_option(MJE_Settings::FIELDS, MJE_Fields::option_defaults());
		}
		if (false === get_option(MJE_Settings::CARRIERS)) {
			add_option(MJE_Settings::CARRIERS, []);
		}
		if (false === get_option(MJE_Settings::METHODS)) {
			add_option(MJE_Settings::METHODS, MJE_Methods::examples());
		}
		if (false === get_option(MJE_Groups::OPTION)) {
			add_option(MJE_Groups::OPTION, []);
		}
		MJE_Install::install();
		set_transient('mje_activated', 1, 5 * MINUTE_IN_SECONDS);
	}

	public static function deactivate() {
		MJE_Install::deactivate();
	}

	public static function notice_no_woo() {
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MJ Envíos:</strong> necesita WooCommerce activo para funcionar.</p></div>';
	}
}

add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MJE_FILE, true);
	}
});

register_activation_hook(MJE_FILE, ['MJE_Plugin', 'activate']);
register_deactivation_hook(MJE_FILE, ['MJE_Plugin', 'deactivate']);
add_action('plugins_loaded', ['MJE_Plugin', 'init']);
