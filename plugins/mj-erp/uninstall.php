<?php
/**
 * Uninstall handler.
 *
 * Always removes the `manage_mjerp` capability and the rewrite rules. Tables, options and
 * product meta are only deleted when the setting `delete_data_on_uninstall` is enabled.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Uninstalls the plugin for the current site.
 *
 * @return void
 */
function mjerp_uninstall_current_site() {
	global $wpdb;

	$settings    = get_option( 'mjerp_settings', array() );
	$delete_data = false;
	if ( is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) ) {
		$flag        = $settings['delete_data_on_uninstall'];
		$delete_data = is_bool( $flag ) ? $flag : in_array( strtolower( trim( (string) $flag ) ), array( '1', 'true', 'yes', 'on', 'si', 'sí' ), true );
	}

	/*
	 * Capabilities: meaningless without the plugin; re-added on activation. The reseller ROLE is
	 * deliberately left in place — removing it would silently strip a login from real people who
	 * may still be customers of the store.
	 */
	$wp_roles = wp_roles();
	if ( $wp_roles instanceof WP_Roles ) {
		foreach ( array_keys( $wp_roles->roles ) as $role_key ) {
			$role = $wp_roles->get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			foreach ( array( 'manage_mjerp', 'mjerp_consignee' ) as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	// Short-lived receive locks (transients) are always cleaned.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_mjerp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mjerp_' ) . '%'
		)
	);

	// The app rewrite rule disappears with the plugin. Drop the cached rules instead of flushing:
	// on multisite this runs inside switch_to_blog(), where flush_rewrite_rules() would write the
	// current site's rules over the switched one. Each site rebuilds them on its next request.
	delete_option( 'mjerp_flush_rewrite' );
	delete_option( 'rewrite_rules' );

	if ( ! $delete_data ) {
		return;
	}

	/*
	 * Las tablas hijas van primero para que borrar a mano quede en un orden entendible. La lista se
	 * escribe acá y no se lee del instalador a propósito: la desinstalación corre con el plugin ya
	 * apagado y sus clases pueden no estar cargadas.
	 */
	$tables = array(
		'suppliers',
		'cashboxes',
		'purchases',
		'purchase_items',
		'purchase_expenses',
		'stock_movements',
		'sale_payments',
		'sale_items',
		'sales',
		'consignment_allocations',
		'consignment_payments',
		'consignment_returns',
		'consignment_sales',
		'consignment_items',
		'consignments',
		'consignees',
	);
	foreach ( $tables as $table ) {
		$name = $wpdb->prefix . 'mjerp_' . $table;
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	delete_option( 'mjerp_settings' );
	delete_option( 'mjerp_db_version' );
	delete_option( 'mjerp_flush_rewrite' );

	// Product meta written on receive (_mjerp_cost, _mjerp_cost_local, _mjerp_cost_currency, ...).
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_mjerp_' ) . '%'
		)
	);

	wp_cache_flush();
}

if ( is_multisite() ) {
	$mjerp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $mjerp_site_ids as $mjerp_site_id ) {
		switch_to_blog( $mjerp_site_id );
		mjerp_uninstall_current_site();
		restore_current_blog();
	}
} else {
	mjerp_uninstall_current_site();
}
