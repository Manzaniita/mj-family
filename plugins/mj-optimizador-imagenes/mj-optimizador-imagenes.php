<?php
/**
 * Plugin Name:       MJ Optimizador de Imágenes
 * Plugin URI:        https://mjimportaciones.com.ar
 * Description:       Achica y convierte a WebP todas las imágenes de la tienda usando tu propio servidor: sin límites, sin créditos ni servicios externos. Optimiza cada foto nueva al subirla y va poniendo al día la biblioteca que ya tenías, en segundo plano.
 * Version:           1.1.1
 * Author:            MJ Importaciones
 * Author URI:        https://mjimportaciones.com.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mj-optimizador-imagenes
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 *
 * CÓMO FUNCIONA
 *   - Cada imagen que subís se corrige (rotación EXIF), se achica al lado máximo
 *     configurado, se convierte a WebP y se le limpian los metadatos, antes de que
 *     WordPress genere las miniaturas. Nunca reemplaza un archivo si el resultado
 *     no pesa menos.
 *   - La biblioteca vieja se va optimizando sola en segundo plano, o de una desde
 *     WooCommerce → Imágenes.
 *
 * INTEGRACIÓN CON OTROS PLUGINS MJ
 *   No pisa datos de nadie: sólo toca archivos de la biblioteca de medios y su
 *   propia meta (_mjoi_*). MJ Fotos Rápidas, MJ ERP y el tema suben imágenes por
 *   el flujo estándar de WordPress, así que quedan optimizadas sin configurar nada.
 *
 *   MJOI_Processor::process_attachment($id)   optimiza un adjunto
 *   MJOI_Processor::restore($id)              vuelve a la copia de seguridad
 *   MJOI_Settings::get('quality')             ajustes vigentes
 *   WP-CLI  wp mjoi optimize --all · wp mjoi stats
 *
 * ACTUALIZACIÓN
 *   Plugins > MJ Optimizador de Imágenes > Desactivar y Borrar, después subís el
 *   zip nuevo. (No se pierde nada: las imágenes ya optimizadas quedan como están.)
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MJOI_VERSION' ) ) {
	define( 'MJOI_VERSION', '1.1.1' );
}
if ( ! defined( 'MJOI_FILE' ) ) {
	define( 'MJOI_FILE', __FILE__ );
}
if ( ! defined( 'MJOI_DIR' ) ) {
	define( 'MJOI_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MJOI_URL' ) ) {
	define( 'MJOI_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'MJOI_BASENAME' ) ) {
	define( 'MJOI_BASENAME', plugin_basename( __FILE__ ) );
}

require_once MJOI_DIR . 'includes/class-mjoi-settings.php';
require_once MJOI_DIR . 'includes/class-mjoi-optimizer.php';
require_once MJOI_DIR . 'includes/class-mjoi-processor.php';
require_once MJOI_DIR . 'includes/class-mjoi-hooks.php';
require_once MJOI_DIR . 'includes/class-mjoi-bulk.php';
require_once MJOI_DIR . 'includes/class-mjoi-admin.php';
require_once MJOI_DIR . 'includes/class-mjoi-migrate.php';

/**
 * Clase principal: arranque, activación y desactivación.
 */
final class MJOI_Plugin {

	/**
	 * Inicializa el plugin (hook plugins_loaded).
	 */
	public static function init() {
		MJOI_Migrate::maybe_run();

		MJOI_Hooks::init();
		MJOI_Bulk::init();

		if ( is_admin() ) {
			MJOI_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once MJOI_DIR . 'includes/class-mjoi-cli.php';
			WP_CLI::add_command( 'mjoi', 'MJOI_CLI' );
		}
	}

	/**
	 * Activación: comprueba requisitos, crea opciones y programa el proceso en segundo plano.
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) || version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
			deactivate_plugins( MJOI_BASENAME );
			wp_die(
				esc_html__( 'MJ Optimizador de Imágenes necesita PHP 7.4 o superior y WordPress 6.0 o superior.', 'mj-optimizador-imagenes' ),
				esc_html__( 'Requisitos no cumplidos', 'mj-optimizador-imagenes' ),
				array( 'back_link' => true )
			);
		}

		// Si venía instalado como "WC Image Optimizer Ilimitado", se trae sus ajustes y su historial.
		MJOI_Migrate::maybe_run();

		if ( false === get_option( MJOI_Settings::OPTION ) ) {
			add_option( MJOI_Settings::OPTION, MJOI_Settings::defaults() );
		}
		if ( false === get_option( MJOI_Processor::STATS_OPTION ) ) {
			add_option( MJOI_Processor::STATS_OPTION, MJOI_Processor::empty_stats() );
		}

		MJOI_Bulk::schedule();
		set_transient( 'mjoi_activated', 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Desactivación: limpia tareas programadas.
	 */
	public static function deactivate() {
		MJOI_Bulk::unschedule();
		delete_transient( MJOI_Bulk::LOCK );
	}
}

// Compatibilidad con el almacenamiento de pedidos HPOS de WooCommerce (el plugin no toca pedidos).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MJOI_FILE, true );
		}
	}
);

register_activation_hook( MJOI_FILE, array( 'MJOI_Plugin', 'activate' ) );
register_deactivation_hook( MJOI_FILE, array( 'MJOI_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'MJOI_Plugin', 'init' ) );
