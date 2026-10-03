<?php
/**
 * Desinstalación de MJ Instagram.
 *
 * Borra opciones y cachés transitorias al eliminar el plugin.
 *
 * @package MJ_Instagram
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mj_instagram_settings');
wp_clear_scheduled_hook('mj_instagram_weekly_cron');

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_mj_ig_cache_%',
		'_transient_timeout_mj_ig_cache_%'
	)
);
