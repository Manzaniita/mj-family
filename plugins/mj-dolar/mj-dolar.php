<?php
/**
 * Plugin Name: MJ Dólar
 * Description: Precios en dólares que se convierten solos a pesos con el tipo de cambio que cargás vos desde wp-admin. Campos USD en productos, variaciones, edición rápida y en lote; columnas "Precio USD" en el importador y exportador CSV de WooCommerce; TC visible en el header de la tienda y guardado en cada pedido. Expone funciones, filtros y REST para que otros plugins MJ se enganchen.
 * Version: 1.1.0
 * Author: MJ Importaciones
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Text Domain: mj-dolar
 *
 * CÓMO FUNCIONA
 *   - El precio en dólares es la fuente de verdad (meta _mjd_usd_regular y
 *     _mjd_usd_sale en productos y variaciones).
 *   - Precio en pesos = USD × TC, redondeado según el ajuste. Se escribe en
 *     los campos normales de WooCommerce (precio normal / oferta) para que
 *     ordenamientos, filtros, informes y cualquier otro plugin vean pesos reales.
 *   - Además, un filtro en tiempo real recalcula desde el USD al leer el
 *     precio: apenas cambiás el TC la tienda muestra el valor nuevo, aunque el
 *     recálculo masivo (que corre en segundo plano) todavía no haya terminado.
 *   - Los productos SIN precio USD siguen en pesos fijos, como siempre.
 *
 * INTEGRACIÓN CON OTROS PLUGINS (ver WooCommerce → Dólar → "Para desarrolladores")
 *   MJD_Rate::get()                     TC vigente (float, 0 si no está cargado)
 *   MJD_Rate::convert($usd)             pesos redondeados según el ajuste
 *   MJD_Product::usd_effective($p)      [usd_normal, usd_oferta|null] de un producto
 *   filtro  mjd_rate                    ajustar el TC leído
 *   filtro  mjd_convert_raw / mjd_convert   antes / después del redondeo
 *   acción  mjd_rate_updated ($nuevo, $viejo, $nota)
 *   acción  mjd_sync_finished ($estado)
 *   REST    GET /wp-json/mjd/v1/rate · POST (admin) · GET /wp-json/mjd/v1/convert?usd=100
 *
 * ACTUALIZACIÓN
 *   Plugins > MJ Dólar > Desactivar y Borrar, después subís el zip nuevo.
 *   (No se pierde nada: los precios USD, el TC y el historial quedan en la base.)
 */

defined('ABSPATH') || exit;

define('MJD_VERSION', '1.1.0');
define('MJD_FILE', __FILE__);
define('MJD_DIR', plugin_dir_path(__FILE__));
define('MJD_URL', plugin_dir_url(__FILE__));

/* Cuaderno de errores compartido de la familia MJ. La copia maestra está en
   shared/class-mj-log.php; esta es una copia. Ver tools/sincronizar-compartido.ps1. */
require_once MJD_DIR . 'includes/compartido/class-mj-log.php';
MJ_Log::init();

require_once MJD_DIR . 'includes/class-mjd-settings.php';
require_once MJD_DIR . 'includes/class-mjd-rate.php';
require_once MJD_DIR . 'includes/class-mjd-product.php';
require_once MJD_DIR . 'includes/class-mjd-sync.php';
require_once MJD_DIR . 'includes/class-mjd-product-fields.php';
require_once MJD_DIR . 'includes/class-mjd-csv.php';
require_once MJD_DIR . 'includes/class-mjd-orders.php';
require_once MJD_DIR . 'includes/class-mjd-rest.php';
require_once MJD_DIR . 'includes/class-mjd-suggest.php';
require_once MJD_DIR . 'includes/class-mjd-admin.php';

/**
 * Arranque, activación y desactivación.
 */
final class MJD_Plugin {

	public static function init() {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', [__CLASS__, 'notice_no_woo']);
			return;
		}

		MJD_Product::init();
		MJD_Sync::init();
		MJD_Product_Fields::init();
		MJD_CSV::init();
		MJD_Orders::init();
		MJD_REST::init();
		MJD_Admin::init_global(); // Barra de admin: también en el frontend para administradores.

		if (is_admin()) {
			MJD_Admin::init();
		}

		if (defined('WP_CLI') && WP_CLI) {
			require_once MJD_DIR . 'includes/class-mjd-cli.php';
			WP_CLI::add_command('mjd', 'MJD_CLI');
		}
	}

	public static function activate() {
		if (false === get_option(MJD_Settings::OPTION)) {
			add_option(MJD_Settings::OPTION, MJD_Settings::defaults());
		}
		set_transient('mjd_activated', 1, 5 * MINUTE_IN_SECONDS);
	}

	public static function deactivate() {
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(MJD_Sync::ACTION, [], MJD_Sync::GROUP);
		}
		wp_clear_scheduled_hook(MJD_Sync::ACTION);
		delete_transient(MJD_Sync::LOCK);
	}

	public static function notice_no_woo() {
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MJ Dólar:</strong> necesita WooCommerce activo para funcionar.</p></div>';
	}
}

/* Compatibilidad con el almacenamiento de pedidos HPOS (el plugin usa la API de metadatos de pedidos). */
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MJD_FILE, true);
	}
});

register_activation_hook(MJD_FILE, ['MJD_Plugin', 'activate']);
register_deactivation_hook(MJD_FILE, ['MJD_Plugin', 'deactivate']);
add_action('plugins_loaded', ['MJD_Plugin', 'init']);
