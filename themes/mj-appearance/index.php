<?php
/**
 * Punto de montaje de la SPA — sirve todas las rutas del frontend.
 *
 * <html data-theme> sale del ajuste "Tema por defecto" para el primer paint
 * del servidor; el script anti-flash (mj_theme_boot_script) lo corrige con la
 * elección guardada del visitante antes de que cargue ningún CSS.
 * <html data-skin> identifica el tema hijo activo (filtro mj_skin).
 */
defined('ABSPATH') || exit;

$mj_options = function_exists('mj_get_options') ? mj_get_options() : [];
$mj_default = function_exists('mj_theme_default') ? mj_theme_default() : 'dark';
$mj_skin    = function_exists('mj_skin') ? mj_skin() : '';
$mj_ssr     = 'light' === $mj_default ? 'light' : 'dark';
/* Animación "Ninguna": el CSS apaga transiciones y hovers desde el primer paint (la SPA lo repite con ?motion=). */
$mj_still   = isset($mj_options['motion_level']) && 'none' === $mj_options['motion_level'];

/* Color de la barra del navegador (mobile) coherente con el fondo del tema. */
$mj_bg_key   = 'light' === $mj_ssr ? 'color_bg_light' : 'color_bg_dark';
$mj_bg_color = isset($mj_options[$mj_bg_key]) ? (string) $mj_options[$mj_bg_key] : '';
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $mj_bg_color)) {
	$mj_bg_color = 'light' === $mj_ssr ? '#fafafa' : '#0a0a0c';
}
?><!doctype html>
<html <?php language_attributes(); ?> data-theme="<?php echo esc_attr($mj_ssr); ?>"<?php echo '' !== $mj_skin ? ' data-skin="' . esc_attr($mj_skin) . '"' : ''; ?><?php echo $mj_still ? ' data-motion="none"' : ''; ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<meta name="color-scheme" content="dark light" />
	<meta name="theme-color" content="<?php echo esc_attr($mj_bg_color); ?>" />
	<?php if (function_exists('mj_theme_boot_script')) { mj_theme_boot_script(); } ?>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
	<?php if (function_exists('mj_favicon_tag')) { echo mj_favicon_tag(); } ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class(is_admin_bar_showing() ? 'admin-bar' : ''); ?>>
	<div id="root"></div>
	<?php wp_footer(); ?>
</body>
</html>
