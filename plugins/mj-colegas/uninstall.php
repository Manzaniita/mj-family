<?php
/**
 * Borrar MJ Colegas: se lleva sus ajustes, las liquidaciones, la taxonomía de
 * colegas (con los datos privados de contacto y de pago de cada una, que viven
 * en term meta) y la marca de cada línea de pedido (quién la hizo, comisión y
 * liquidación).
 *
 * Los pedidos y los productos quedan como están: son de la tienda. Lo que se
 * pierde es solo la relación "esta línea era de tal colega", que sin la
 * taxonomía ya no se puede leer.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

/* ------------------------------- Opciones -------------------------------- */

foreach (['mjc_options', 'mjc_settlements', 'mjc_settlement_seq'] as $mjc_option) {
	delete_option($mjc_option);
}

/* ------------------------------ Taxonomía -------------------------------- */

/*
 * La taxonomía no está registrada en este momento (el plugin ya no carga), así
 * que se borra a mano: relaciones con productos, metas, términos y taxonomía.
 */
$mjc_term_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'mj_colega')
);
$mjc_tt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'mj_colega')
);
foreach ((array) $mjc_tt_ids as $mjc_tt_id) {
	$wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => (int) $mjc_tt_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => (int) $mjc_tt_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
foreach ((array) $mjc_term_ids as $mjc_term_id) {
	$wpdb->delete($wpdb->termmeta, ['term_id' => (int) $mjc_term_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete($wpdb->terms, ['term_id' => (int) $mjc_term_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/* ------------------------ Meta de líneas de pedido ------------------------ */

$mjc_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
if ($mjc_itemmeta === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($mjc_itemmeta)))) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach (['_mjc_colega_id', '_mjc_colega_name', '_mjc_commission', '_mjc_settled'] as $mjc_meta) {
		$wpdb->delete($mjc_itemmeta, ['meta_key' => $mjc_meta]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
	}
}

wp_cache_flush();
