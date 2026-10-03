<?php
/**
 * Plugin Name: MJ Instagram
 * Plugin URI:  https://github.com/Manzaniita/mj-family
 * Description: Conexión con Instagram y bloque para mostrar publicaciones, historias, destacados y perfil en cualquier página de tu tienda.
 * Version:     1.0.0
 * Author:      MJ Importaciones
 * Text Domain: mj-instagram
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package MJ_Instagram
 */

defined('ABSPATH') || exit;

define('MJ_INSTAGRAM_VERSION', '1.0.0');
define('MJ_INSTAGRAM_FILE', __FILE__);
define('MJ_INSTAGRAM_DIR', plugin_dir_path(__FILE__));
define('MJ_INSTAGRAM_URL', plugin_dir_url(__FILE__));

// Código compartido
require_once __DIR__ . '/includes/compartido/class-mj-menu.php';
require_once __DIR__ . '/includes/compartido/class-mj-log.php';

MJ_Menu::init();
MJ_Log::init();

// Módulos del plugin
require_once __DIR__ . '/includes/class-mj-instagram-api.php';
require_once __DIR__ . '/includes/class-mj-instagram-admin.php';
require_once __DIR__ . '/includes/class-mj-instagram-block.php';

/**
 * Inicialización del plugin.
 */
function mj_instagram_init() {
	$api   = new MJ_Instagram_API();
	$admin = new MJ_Instagram_Admin($api);
	$block = new MJ_Instagram_Block($api);

	// Shortcode alternativo [mj_instagram]
	add_shortcode('mj_instagram', function ($atts) use ($block) {
		$attrs = shortcode_atts([
			'display' => 'posts',
			'layout'  => 'grid',
			'columns' => 4,
			'limit'   => 8,
			'title'   => '',
		], $atts, 'mj_instagram');

		return $block->render_block([
			'displayType'    => $attrs['display'],
			'layout'         => $attrs['layout'],
			'columnsDesktop' => absint($attrs['columns']),
			'limit'          => absint($attrs['limit']),
			'customTitle'    => sanitize_text_field($attrs['title']),
		]);
	});
}
add_action('plugins_loaded', 'mj_instagram_init');

/**
 * Cron semanal para renovar tokens de larga duración antes de que expiren.
 */
add_action('mj_instagram_weekly_cron', function () {
	$api = new MJ_Instagram_API();
	$api->refresh_long_lived_token();
});

register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled('mj_instagram_weekly_cron')) {
		wp_schedule_event(time(), 'weekly', 'mj_instagram_weekly_cron');
	}
});

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('mj_instagram_weekly_cron');
});
