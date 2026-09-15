<?php
/**
 * Plugin Name:       MJ ERP
 * Plugin URI:        https://mjimportaciones.com.ar
 * Description:       ERP para WooCommerce: ventas propias y de la tienda con imputación automática a la consignación de cada revendedor, compras e importaciones con costeo en vivo, cuenta corriente, proveedores, cajas, actualización de stock y costos, y publicación del precio de venta en dólares a través de MJ Dólar (compatible con el tema MJ Appearance y sus temas hijo).
 * Version:           1.18.0
 * Author:            MJ Importaciones
 * Author URI:        https://mjimportaciones.com.ar
 * Text Domain:       mj-erp
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MJERP_VERSION' ) ) {
	define( 'MJERP_VERSION', '1.18.0' );
}
if ( ! defined( 'MJERP_DB_VERSION' ) ) {
	define( 'MJERP_DB_VERSION', '1.12.0' );
}
if ( ! defined( 'MJERP_FILE' ) ) {
	define( 'MJERP_FILE', __FILE__ );
}
if ( ! defined( 'MJERP_DIR' ) ) {
	define( 'MJERP_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MJERP_URL' ) ) {
	define( 'MJERP_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'MJERP_BASENAME' ) ) {
	define( 'MJERP_BASENAME', plugin_basename( __FILE__ ) );
}

require_once MJERP_DIR . 'includes/class-mjerp-plugin.php';

/**
 * Activation callback.
 *
 * The activation hook runs after `plugins_loaded`, so the plugin singleton is
 * not booted in this request; load the installer directly.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 * @return void
 */
function mjerp_activate( $network_wide = false ) {
	require_once MJERP_DIR . 'includes/class-mjerp-install.php';
	MJERP_Install::activate( (bool) $network_wide );
}

/**
 * Deactivation callback.
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 * @return void
 */
function mjerp_deactivate( $network_wide = false ) {
	require_once MJERP_DIR . 'includes/class-mjerp-install.php';
	MJERP_Install::deactivate( (bool) $network_wide );
}

register_activation_hook( __FILE__, 'mjerp_activate' );
register_deactivation_hook( __FILE__, 'mjerp_deactivate' );

/**
 * Returns the main plugin instance.
 *
 * @return MJERP_Plugin
 */
function mjerp() {
	return MJERP_Plugin::instance();
}

add_action( 'plugins_loaded', 'mjerp', 20 );
