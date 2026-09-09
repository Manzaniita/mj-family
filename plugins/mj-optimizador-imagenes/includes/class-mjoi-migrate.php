<?php
/**
 * Migración desde "WC Image Optimizer Ilimitado" (el mismo plugin antes de entrar
 * en la familia MJ).
 *
 * Corre una sola vez y se trae todo lo que había: ajustes, estadísticas, historial,
 * la marca de optimizado de cada imagen y las copias de seguridad. Así, al subir el
 * zip nuevo, la biblioteca no vuelve a empezar de cero ni se reoptimiza lo que ya
 * estaba hecho.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Migrate {

	/** Marca de que la migración ya se hizo. */
	const DONE_OPTION = 'mjoi_migrated_from_wcio';

	/** Candado para que dos peticiones a la vez no la corran dos veces. */
	const LOCK = 'mjoi_migrating';

	/** Carpeta de copias de seguridad del plugin viejo, dentro de uploads. */
	const OLD_BACKUP_DIR = 'wcio-backups';

	/** Ruta del plugin viejo dentro de wp-content/plugins. */
	const OLD_PLUGIN = 'wc-image-optimizer-ilimitado/wc-image-optimizer-ilimitado.php';

	/**
	 * Corre la migración si hace falta (una sola vez por instalación).
	 */
	public static function maybe_run() {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );

		self::deactivate_old_plugin();
		$moved = self::move_options();
		$moved = self::move_post_meta() || $moved;
		self::move_backups();
		self::clear_old_schedules();
		self::clear_old_transients();

		update_option( self::DONE_OPTION, $moved ? 1 : 'clean', true );
		delete_transient( self::LOCK );

		if ( $moved ) {
			set_transient( 'mjoi_migrated_notice', 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Desactiva el plugin viejo si sigue activo: los dos hacen lo mismo y
	 * procesarían cada imagen dos veces.
	 */
	private static function deactivate_old_plugin() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_readable( $file ) ) {
				return;
			}
			require_once $file;
		}
		if ( is_plugin_active( self::OLD_PLUGIN ) ) {
			deactivate_plugins( self::OLD_PLUGIN, true );
		}
	}

	/**
	 * Trae ajustes, estadísticas e historial. No pisa nada que ya exista.
	 *
	 * @return bool Si encontró algo del plugin viejo.
	 */
	private static function move_options() {
		$found = false;
		$map   = array(
			'wcio_settings' => MJOI_Settings::OPTION,
			'wcio_stats'    => MJOI_Processor::STATS_OPTION,
			'wcio_log'      => MJOI_Processor::LOG_OPTION,
		);

		foreach ( $map as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value || false === $value ) {
				continue;
			}
			$found = true;
			if ( false === get_option( $new, false ) ) {
				update_option( $new, $value, false );
			}
			delete_option( $old );
		}

		MJOI_Settings::flush();
		return $found;
	}

	/**
	 * Renombra la meta de cada imagen (_wcio_* → _mjoi_*).
	 *
	 * Se salta las claves que ya tengan datos nuevos, para no dejar filas duplicadas
	 * si la migración se corriera sobre una instalación mezclada.
	 *
	 * @return bool Si movió alguna fila.
	 */
	private static function move_post_meta() {
		global $wpdb;
		$moved = false;

		$map = array(
			'_wcio_data'   => MJOI_Processor::META_DATA,
			'_wcio_status' => MJOI_Processor::META_STATUS,
			'_wcio_backup' => MJOI_Processor::META_BACKUP,
			'_wcio_queued' => MJOI_Processor::META_QUEUED,
		);

		foreach ( $map as $old => $new ) {
			$old_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $old_rows ) {
				continue;
			}
			$new_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $new_rows ) {
				continue;
			}
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
			$moved = true;
		}

		if ( $moved ) {
			wp_cache_flush();
		}
		return $moved;
	}

	/**
	 * Mueve las copias de seguridad de uploads/wcio-backups a uploads/mjoi-backups.
	 *
	 * La meta guarda sólo el nombre del archivo, así que con mover la carpeta alcanza
	 * para que el botón "Restaurar" siga andando.
	 */
	private static function move_backups() {
		$u   = wp_get_upload_dir();
		$old = trailingslashit( wp_normalize_path( $u['basedir'] ) ) . self::OLD_BACKUP_DIR;
		if ( ! is_dir( $old ) ) {
			return;
		}

		$new = MJOI_Processor::backup_dir();

		$files = glob( $old . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}
				$dest = $new . '/' . wp_basename( $file );
				if ( file_exists( $dest ) ) {
					continue;
				}
				if ( ! @rename( $file, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@copy( $file, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		@unlink( $old . '/index.html' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Borra las tareas programadas del plugin viejo.
	 */
	private static function clear_old_schedules() {
		wp_clear_scheduled_hook( 'wcio_background_run' );
		wp_clear_scheduled_hook( 'wcio_process_attachment' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wcio_process_attachment', array(), 'wcio' );
		}
	}

	/**
	 * Borra los transitorios del plugin viejo (candados y tamaños de subida en curso).
	 */
	private static function clear_old_transients() {
		global $wpdb;

		delete_transient( 'wcio_bg_lock' );
		delete_transient( 'wcio_activated' );

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wcio\_up\_%' OR option_name LIKE '\_transient\_timeout\_wcio\_up\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}
}
