<?php
/**
 * Plugin Name: MJ Core
 * Description: Actualizaciones de los temas y plugins MJ desde wp-admin. Cuando se publica una versión nueva, la tienda la ofrece como cualquier otra actualización de WordPress, con su botón de "Actualizar ahora".
 * Version: 1.1.0
 * Author: MJ Importaciones
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mj-core
 *
 * CÓMO FUNCIONA
 *   - Las versiones publicadas son las Releases de GitHub que arma
 *     tools/publicar.ps1. Cada zip se llama <carpeta>-<versión>.zip.
 *   - Cada tanto (MJ → Actualizaciones) se lee la lista de Releases,
 *     y cuando WordPress busca actualizaciones se le agregan las de MJ: todo
 *     plugin o tema instalado cuya carpeta coincida con un zip publicado.
 *   - Al apretar "Actualizar", el zip se baja con el token de la tienda y se
 *     compara su SHA-256 con el que publica GitHub antes de instalarlo.
 *
 * QUÉ NO HACE
 *   Los demás plugins no dependen de este. Si MJ Core no está, todo sigue
 *   funcionando igual: solo que las actualizaciones vuelven a subirse a mano.
 */

defined('ABSPATH') || exit;

/* MJCORE_ y no MJC_: MJ Colegas ya usa MJC_VERSION, MJC_FILE y MJC_DIR, y como
   carga antes (orden alfabético) sus valores ganaban y MJ Core buscaba sus
   archivos adentro de mj-colegas/. */
define('MJCORE_VERSION', '1.1.0');
define('MJCORE_FILE', __FILE__);
define('MJCORE_DIR', plugin_dir_path(__FILE__));

/* Cuaderno de errores compartido de la familia MJ. La copia maestra está en
   shared/class-mj-log.php; esta es una copia. Ver tools/sincronizar-compartido.ps1. */
require_once MJCORE_DIR . 'includes/compartido/class-mj-log.php';
MJ_Log::init();

/* El menú único de la familia MJ en wp-admin, para que las pantallas de todos
   los plugins cuelguen del mismo lado. Copia maestra en shared/class-mj-menu.php.
   Viaja adentro del plugin: si MJ Core está solo, arma igual su menú. */
require_once MJCORE_DIR . 'includes/compartido/class-mj-menu.php';
MJ_Menu::init();

require_once MJCORE_DIR . 'includes/class-mjc-ajustes.php';
require_once MJCORE_DIR . 'includes/class-mjc-catalogo.php';
require_once MJCORE_DIR . 'includes/class-mjc-actualizador.php';
require_once MJCORE_DIR . 'includes/class-mjc-admin.php';

MJC_Actualizador::init();

if (is_admin()) {
	MJC_Admin::init();
}
