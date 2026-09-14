<?php
/**
 * Borrar MJ Core: se lleva sus ajustes, el token y el catálogo guardado.
 *
 * El nivel de MJ · Registros no se toca: lo comparten todos los plugins MJ.
 *
 * @package MJ_Core
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mjc_ajustes');
delete_option('mjc_catalogo');
delete_option('mjc_ultimo_error');
delete_site_transient('mjc_consultado');
