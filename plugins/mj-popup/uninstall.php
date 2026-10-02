<?php
/**
 * Desinstalación de MJ Popup.
 *
 * Al borrar el plugin desde wp-admin, limpia las opciones guardadas en la base
 * de datos. Si solo se desactiva, no borra nada.
 *
 * @package MJ_Popup
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mj_popup_opciones');
