<?php
/**
 * Plugin Name: MJ Core
 * Description: Actualizaciones de los temas y plugins MJ desde wp-admin. Cuando se publica una versión nueva, la tienda la ofrece como cualquier otra actualización de WordPress, con su botón de "Actualizar ahora".
 * Version: 1.0.0
 * Author: MJ Importaciones
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mj-core
 *
 * CÓMO FUNCIONA
 *   - Las versiones publicadas son las Releases de GitHub que arma
 *     tools/publicar.ps1. Cada zip se llama <carpeta>-<versión>.zip.
 *   - Cada tanto (Ajustes → MJ · Actualizaciones) se lee la lista de Releases,
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

define('MJC_VERSION', '1.0.0');
define('MJC_FILE', __FILE__);
define('MJC_DIR', plugin_dir_path(__FILE__));

/* Cuaderno de errores compartido de la familia MJ. La copia maestra está en
   shared/class-mj-log.php; esta es una copia. Ver tools/sincronizar-compartido.ps1. */
require_once MJC_DIR . 'includes/compartido/class-mj-log.php';
MJ_Log::init();

require_once MJC_DIR . 'includes/class-mjc-ajustes.php';
require_once MJC_DIR . 'includes/class-mjc-catalogo.php';
require_once MJC_DIR . 'includes/class-mjc-actualizador.php';
require_once MJC_DIR . 'includes/class-mjc-admin.php';

MJC_Actualizador::init();

if (is_admin()) {
	MJC_Admin::init();
}
