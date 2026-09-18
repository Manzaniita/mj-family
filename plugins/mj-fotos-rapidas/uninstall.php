<?php
/**
 * Borrar MJ Fotos Rápidas: se lleva su único ajuste.
 *
 * Las fotos ya subidas son adjuntos de la biblioteca de medios y siguen en los
 * productos: son de la tienda, no del plugin.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mj_fotos_rapidas');
