<?php
/**
 * Limpieza al desinstalar: opciones, metadatos y tareas programadas.
 * Las imágenes optimizadas y las copias de seguridad (uploads/mjoi-backups) se conservan.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'mjoi_settings' );
delete_option( 'mjoi_stats' );
delete_option( 'mjoi_log' );
delete_option( 'mjoi_migrated_from_wcio' );
delete_transient( 'mjoi_bg_lock' );
delete_transient( 'mjoi_activated' );
delete_transient( 'mjoi_migrating' );
delete_transient( 'mjoi_migrated_notice' );

wp_clear_scheduled_hook( 'mjoi_background_run' );
wp_clear_scheduled_hook( 'mjoi_process_attachment' );

foreach ( array( '_mjoi_data', '_mjoi_status', '_mjoi_backup', '_mjoi_queued' ) as $key ) {
	delete_post_meta_by_key( $key );
}

// Transientes de subida que hayan quedado.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mjoi\_up\_%' OR option_name LIKE '\_transient\_timeout\_mjoi\_up\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
