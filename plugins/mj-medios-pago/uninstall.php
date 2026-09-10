<?php
/**
 * Se ejecuta al BORRAR el plugin desde Plugins.
 *
 * Borra la configuración, los métodos propios, sus opciones de gateway en
 * WooCommerce, las equivalencias con el ERP, la tabla de comprobantes y los
 * archivos de la bóveda. NO toca pedidos (conservan método y ajuste como
 * historial), ni la exclusión marcada en productos y categorías.
 *
 * Los comprobantes sí se borran, y a propósito: son datos bancarios de tus
 * clientes. Si los necesitás como respaldo, descargalos antes de borrar el
 * plugin (desactivarlo no borra nada).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

/* ------------------------------- Opciones -------------------------------- */

foreach (['mjpm_settings', 'mjpm_methods', 'mjpm_custom', 'mjpm_receipts', 'mjpm_mapping', 'mjpm_db_version'] as $option) {
	delete_option($option);
}
delete_transient('mjpm_activated');

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\_mjpm\_%\_settings'");

/* -------------------------------- Tareas --------------------------------- */

foreach (['mjpm_purge_receipts', 'mjpm_remind_receipts'] as $hook) {
	wp_clear_scheduled_hook($hook);
}

/* ------------------------- Archivos de la bóveda -------------------------- */

$uploads = wp_get_upload_dir();
$root    = isset($uploads['basedir']) ? untrailingslashit($uploads['basedir']) . '/mj-comprobantes' : '';

if ('' !== $root && is_dir($root)) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		if ($item->isDir()) {
			@rmdir($item->getRealPath()); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			@unlink($item->getRealPath()); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir($root); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/* --------------------------------- Tabla ---------------------------------- */

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mjpm_receipts'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/* ------------------------- Meta de revisión en pedidos -------------------- */

foreach (['_mjpm_receipt_state', '_mjpm_receipt_reason', '_mjpm_receipt_by', '_mjpm_receipt_at', '_mjpm_receipt_asked', '_mjpm_receipt_reminded'] as $meta) {
	$wpdb->delete($wpdb->postmeta, ['meta_key' => $meta]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
	$orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ($orders_meta === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($orders_meta)))) {
		$wpdb->delete($orders_meta, ['meta_key' => $meta]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
	}
}
