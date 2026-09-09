<?php
/**
 * Optimización masiva de la biblioteca: lotes por AJAX y proceso en segundo plano (WP-Cron).
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Bulk {

	const CRON_HOOK = 'mjoi_background_run';
	const LOCK      = 'mjoi_bg_lock';
	const NONCE     = 'mjoi_nonce';

	/**
	 * Registra hooks de cron y AJAX.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'update_option_' . MJOI_Settings::OPTION, array( __CLASS__, 'maybe_schedule' ) );

		add_action( 'wp_ajax_mjoi_bulk_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_mjoi_bulk_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_mjoi_optimize_one', array( __CLASS__, 'ajax_optimize_one' ) );
		add_action( 'wp_ajax_mjoi_restore_one', array( __CLASS__, 'ajax_restore_one' ) );
		add_action( 'wp_ajax_mjoi_reset', array( __CLASS__, 'ajax_reset' ) );
	}

	/**
	 * Intervalo de 5 minutos.
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['mjoi_5min'] ) ) {
			$schedules['mjoi_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Cada 5 minutos (MJOI)', 'mj-optimizador-imagenes' ),
			);
		}
		return $schedules;
	}

	/**
	 * Programa o cancela el cron según los ajustes.
	 */
	public static function maybe_schedule() {
		MJOI_Settings::flush();
		$s = MJOI_Settings::get();
		if ( $s['enabled'] && $s['background'] ) {
			self::schedule();
		} else {
			self::unschedule();
		}
	}

	/**
	 * Programa el cron.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'mjoi_5min', self::CRON_HOOK );
		}
	}

	/**
	 * Cancela el cron.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Ejecución periódica en segundo plano.
	 */
	public static function cron_run() {
		$s = MJOI_Settings::get();
		if ( ! $s['enabled'] || ! $s['background'] ) {
			return;
		}
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );
		try {
			self::run_batch( 25, 15, false );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Consultas
	 * ------------------------------------------------------------------ */

	/**
	 * IDs de imágenes pendientes de optimizar.
	 *
	 * @param int  $limit          Máximo.
	 * @param bool $include_errors Incluir las que fallaron.
	 * @return int[]
	 */
	public static function pending_ids( $limit = 10, $include_errors = false ) {
		$status_or = array(
			'relation' => 'OR',
			array(
				'key'     => MJOI_Processor::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => MJOI_Processor::META_STATUS,
				'value' => 'pending',
			),
		);
		if ( $include_errors ) {
			$status_or[] = array(
				'key'   => MJOI_Processor::META_STATUS,
				'value' => 'error',
			);
		}
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'post_mime_type'         => MJOI_Processor::allowed_mimes(),
				'posts_per_page'         => max( 1, (int) $limit ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $status_or, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Recuento por estado.
	 *
	 * @return array total, done, error, skipped, restored, processing, pending.
	 */
	public static function counts() {
		global $wpdb;
		$mimes        = MJOI_Processor::allowed_mimes();
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private') AND post_mime_type IN ($placeholders)",
				$mimes
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS s, COUNT(*) AS c FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status IN ('inherit','private') AND p.post_mime_type IN ($placeholders) GROUP BY pm.meta_value",
				array_merge( array( MJOI_Processor::META_STATUS ), $mimes )
			)
		);
		// phpcs:enable

		$c = array(
			'total'      => $total,
			'done'       => 0,
			'error'      => 0,
			'skipped'    => 0,
			'restored'   => 0,
			'processing' => 0,
			'pending'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $c[ $row->s ] ) ) {
				$c[ $row->s ] = (int) $row->c;
			}
		}
		$c['pending'] = max( 0, $total - $c['done'] - $c['error'] - $c['skipped'] - $c['restored'] - $c['processing'] );
		return $c;
	}

	/* ---------------------------------------------------------------------
	 * Lotes
	 * ------------------------------------------------------------------ */

	/**
	 * Procesa imágenes pendientes hasta agotar el tiempo o el límite.
	 *
	 * @param int  $max_seconds    Tiempo máximo.
	 * @param int  $max_items      Máximo de imágenes.
	 * @param bool $include_errors Reintentar errores.
	 * @return array processed, results[].
	 */
	public static function run_batch( $max_seconds = 20, $max_items = 10, $include_errors = false ) {
		$start = microtime( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $max_seconds + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids     = self::pending_ids( $max_items, $include_errors );
		$results = array();

		foreach ( $ids as $id ) {
			// Evitar que dos procesos (cron + botón) trabajen sobre la misma imagen.
			$st = (string) get_post_meta( $id, MJOI_Processor::META_STATUS, true );
			if ( in_array( $st, array( 'processing', 'done', 'skipped', 'restored' ), true ) ) {
				continue;
			}
			$results[] = self::process_one( $id );
			if ( ( microtime( true ) - $start ) >= $max_seconds ) {
				break;
			}
		}

		return array(
			'processed' => count( $results ),
			'results'   => $results,
		);
	}

	/**
	 * Procesa una imagen y devuelve un resumen apto para JSON.
	 *
	 * @param int       $id   ID.
	 * @param bool|null $webp Forzar WebP.
	 * @return array
	 */
	public static function process_one( $id, $webp = null ) {
		$id  = (int) $id;
		$row = array(
			'id'      => $id,
			'title'   => wp_basename( (string) get_attached_file( $id, true ) ),
			'status'  => 'error',
			'message' => '',
			'before'  => 0,
			'after'   => 0,
			'percent' => 0,
			'html'    => '',
		);
		// Una subida diferida se procesa como "recién subida" (miniaturas ya generadas en WebP).
		$mode = get_post_meta( $id, MJOI_Processor::META_QUEUED, true ) ? 'upload' : 'bulk';
		try {
			$res = MJOI_Processor::process_attachment(
				$id,
				array(
					'mode' => $mode,
					'webp' => $webp,
				)
			);
		} catch ( Throwable $e ) {
			$res = new WP_Error( 'mjoi_exception', $e->getMessage() );
		}
		if ( is_wp_error( $res ) ) {
			$row['message'] = $res->get_error_message();
			if ( ! get_post_meta( $id, MJOI_Processor::META_STATUS, true ) ) {
				MJOI_Processor::set_status( $id, 'error' );
			}
			MJOI_Processor::log( $row['message'], $id );
		} else {
			$row['status']  = $res['status'];
			$row['before']  = (int) $res['data']['before'];
			$row['after']   = (int) $res['data']['after'];
			$row['percent'] = (float) $res['data']['percent'];
			$row['message'] = $res['data']['errors'] ? implode( ' | ', $res['data']['errors'] ) : '';
		}
		if ( class_exists( 'MJOI_Admin' ) ) {
			$row['html'] = MJOI_Admin::column_html( $id );
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Comprueba nonce y permisos de administración.
	 */
	private static function guard( $capability = 'manage_options' ) {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'No tenés permisos para hacer esto.', 'mj-optimizador-imagenes' ) ), 403 );
		}
	}

	/**
	 * Estado y recuentos.
	 */
	public static function ajax_status() {
		self::guard();
		wp_send_json_success(
			array(
				'counts' => self::counts(),
				'stats'  => self::stats_payload(),
			)
		);
	}

	/**
	 * Procesa un lote.
	 */
	public static function ajax_run() {
		self::guard();
		$include_errors = ! empty( $_POST['include_errors'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		set_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );
		try {
			$batch = self::run_batch( 20, 8, $include_errors );
		} finally {
			delete_transient( self::LOCK );
		}
		$counts = self::counts();
		wp_send_json_success(
			array(
				'processed' => $batch['processed'],
				'results'   => $batch['results'],
				'remaining' => $counts['pending'] + ( $include_errors ? $counts['error'] : 0 ),
				'counts'    => $counts,
				'stats'     => self::stats_payload(),
			)
		);
	}

	/**
	 * Optimiza una imagen concreta (botón en la biblioteca).
	 */
	public static function ajax_optimize_one() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tenés permisos para hacer esto.', 'mj-optimizador-imagenes' ) ), 403 );
		}
		$webp = null;
		if ( isset( $_POST['webp'] ) ) {
			$webp = ! empty( $_POST['webp'] );
		}
		$row = self::process_one( $id, $webp );
		if ( 'error' === $row['status'] && $row['message'] ) {
			wp_send_json_error( array_merge( $row, array( 'stats' => self::stats_payload() ) ) );
		}
		wp_send_json_success( array_merge( $row, array( 'stats' => self::stats_payload() ) ) );
	}

	/**
	 * Restaura una imagen desde su copia de seguridad.
	 */
	public static function ajax_restore_one() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tenés permisos para hacer esto.', 'mj-optimizador-imagenes' ) ), 403 );
		}
		try {
			$r = MJOI_Processor::restore( $id );
		} catch ( Throwable $e ) {
			$r = new WP_Error( 'mjoi_exception', $e->getMessage() );
		}
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'id'    => $id,
				'html'  => class_exists( 'MJOI_Admin' ) ? MJOI_Admin::column_html( $id ) : '',
				'stats' => self::stats_payload(),
			)
		);
	}

	/**
	 * Marca imágenes como pendientes (todas o solo errores).
	 */
	public static function ajax_reset() {
		self::guard();
		$only_errors = ! empty( $_POST['only_errors'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$n           = MJOI_Processor::reset_statuses( $only_errors );
		wp_send_json_success(
			array(
				'reset'  => $n,
				'counts' => self::counts(),
			)
		);
	}

	/**
	 * Estadísticas listas para mostrar.
	 */
	public static function stats_payload() {
		$st    = MJOI_Processor::get_stats();
		$saved = max( 0, (int) $st['before'] - (int) $st['after'] );
		return array(
			'images'        => (int) $st['images'],
			'before'        => (int) $st['before'],
			'after'         => (int) $st['after'],
			'saved'         => $saved,
			'percent'       => $st['before'] > 0 ? round( $saved * 100 / $st['before'], 1 ) : 0,
			'before_human'  => size_format( (int) $st['before'] ),
			'after_human'   => size_format( (int) $st['after'] ),
			'saved_human'   => size_format( $saved ),
			'images_human'  => number_format_i18n( (int) $st['images'] ),
		);
	}
}
