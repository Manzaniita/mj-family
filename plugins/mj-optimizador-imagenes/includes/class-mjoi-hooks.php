<?php
/**
 * Integración con el flujo de subida de WordPress/WooCommerce.
 *
 * Hooks usados:
 *  - wp_handle_upload / wp_handle_sideload: corrige rotación EXIF y redimensiona el original
 *    antes de que WordPress genere miniaturas (evita el duplicado "-scaled").
 *  - image_editor_output_format: WordPress genera las miniaturas directamente en WebP.
 *  - wp_editor_set_quality: calidad configurada para las miniaturas.
 *  - big_image_size_threshold: alinea el umbral de WordPress con el tamaño máximo configurado.
 *  - wp_generate_attachment_metadata: optimiza la imagen principal y guarda estadísticas.
 *  - delete_attachment: elimina la copia de seguridad.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Hooks {

	const DEFERRED_ACTION = 'mjoi_process_attachment';

	/**
	 * Registra los hooks.
	 */
	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_upload' ), 10, 2 );
		add_filter( 'wp_handle_sideload', array( __CLASS__, 'handle_upload' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'after_metadata' ), 999, 3 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'editor_quality' ), 10, 2 );
		add_filter( 'image_editor_output_format', array( __CLASS__, 'output_format' ), 10, 3 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'big_image_threshold' ), 10, 1 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ), 10, 1 );
		add_action( self::DEFERRED_ACTION, array( __CLASS__, 'process_deferred' ), 10, 1 );
	}

	/**
	 * ¿Está activa la optimización automática?
	 */
	private static function active() {
		return ! MJOI_Processor::$bypass && (bool) MJOI_Settings::get( 'enabled' );
	}

	/**
	 * Paso previo: nada más subir el archivo, corrige orientación y tamaño máximo.
	 *
	 * @param array  $upload  Datos del archivo (file, url, type).
	 * @param string $context 'upload' | 'sideload'.
	 * @return array
	 */
	public static function handle_upload( $upload, $context = 'upload' ) {
		if ( ! is_array( $upload ) || isset( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! self::active() || ! in_array( $upload['type'], MJOI_Processor::allowed_mimes(), true ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}

		$s = MJOI_Settings::get();

		try {
			MJOI_Processor::remember_upload( $upload['file'], (int) filesize( $upload['file'] ) );

			if ( $s['backup'] ) {
				MJOI_Processor::create_pending_backup( $upload['file'] );
			}

			$r = MJOI_Optimizer::optimize(
				$upload['file'],
				array(
					'quality'       => max( 92, (int) $s['quality'] ),
					'max_dimension' => (int) $s['max_dimension'],
					'convert_webp'  => false,
					'strip_meta'    => (bool) $s['strip_meta'],
					'keep_icc'      => (bool) $s['keep_icc'],
					'geometry_only' => true,
					'min_saving'    => 0,
				)
			);
			if ( is_wp_error( $r ) ) {
				MJOI_Processor::log( __( 'Paso previo de subida: ', 'mj-optimizador-imagenes' ) . $r->get_error_message() . ' (' . wp_basename( $upload['file'] ) . ')' );
			}
		} catch ( Throwable $e ) {
			MJOI_Processor::log( __( 'Paso previo de subida: ', 'mj-optimizador-imagenes' ) . $e->getMessage() );
		}

		return $upload;
	}

	/**
	 * Tras generar las miniaturas: optimiza la imagen principal, limpia originales y guarda datos.
	 *
	 * @param array  $metadata      Metadatos.
	 * @param int    $attachment_id ID.
	 * @param string $context       'create' | 'update'.
	 * @return array
	 */
	public static function after_metadata( $metadata, $attachment_id, $context = 'create' ) {
		if ( ! is_array( $metadata ) || ! self::active() ) {
			return $metadata;
		}
		$attachment_id = (int) $attachment_id;
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}
		if ( 'update' === $context && 'done' === get_post_meta( $attachment_id, MJOI_Processor::META_STATUS, true ) ) {
			return $metadata;
		}

		if ( MJOI_Settings::get( 'defer_upload' ) ) {
			self::schedule_deferred( $attachment_id );
			return $metadata;
		}

		try {
			$res = MJOI_Processor::process_attachment(
				$attachment_id,
				array(
					'mode' => 'upload',
					'meta' => $metadata,
					'save' => false,
				)
			);
		} catch ( Throwable $e ) {
			MJOI_Processor::log( __( 'Error al optimizar: ', 'mj-optimizador-imagenes' ) . $e->getMessage(), $attachment_id );
			MJOI_Processor::set_status( $attachment_id, 'error' );
			return $metadata;
		}
		if ( is_wp_error( $res ) ) {
			MJOI_Processor::log( __( 'Error al optimizar: ', 'mj-optimizador-imagenes' ) . $res->get_error_message(), $attachment_id );
			return $metadata;
		}
		return $res['meta'];
	}

	/**
	 * Programa el procesado en segundo plano (Action Scheduler de WooCommerce si existe, si no WP-Cron).
	 */
	public static function schedule_deferred( $attachment_id ) {
		MJOI_Processor::set_status( $attachment_id, 'pending' );
		update_post_meta( (int) $attachment_id, MJOI_Processor::META_QUEUED, 1 );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::DEFERRED_ACTION, array( (int) $attachment_id ), 'mjoi' );
			return;
		}
		if ( ! wp_next_scheduled( self::DEFERRED_ACTION, array( (int) $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 15, self::DEFERRED_ACTION, array( (int) $attachment_id ) );
		}
	}

	/**
	 * Ejecuta el procesado en segundo plano.
	 */
	public static function process_deferred( $attachment_id ) {
		if ( ! self::active() ) {
			return;
		}
		try {
			MJOI_Processor::process_attachment( (int) $attachment_id, array( 'mode' => 'upload' ) );
		} catch ( Throwable $e ) {
			MJOI_Processor::log( __( 'Error en segundo plano: ', 'mj-optimizador-imagenes' ) . $e->getMessage(), $attachment_id );
			MJOI_Processor::set_status( $attachment_id, 'error' );
		}
	}

	/**
	 * Calidad para las miniaturas que genera WordPress.
	 */
	public static function editor_quality( $quality, $mime_type ) {
		if ( ! self::active() ) {
			return $quality;
		}
		if ( in_array( $mime_type, array( 'image/jpeg', 'image/webp' ), true ) ) {
			return (int) MJOI_Settings::get( 'quality' );
		}
		return $quality;
	}

	/**
	 * Formato de salida de las miniaturas: JPEG (y PNG en modo con pérdida) → WebP.
	 */
	public static function output_format( $formats, $filename = '', $mime_type = '' ) {
		if ( ! is_array( $formats ) ) {
			$formats = array();
		}
		if ( ! self::active() || ! MJOI_Settings::get( 'webp' ) ) {
			return $formats;
		}
		static $editor_webp = null;
		if ( null === $editor_webp ) {
			$editor_webp = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		}
		if ( ! $editor_webp ) {
			return $formats;
		}
		$formats['image/jpeg'] = 'image/webp';
		if ( 'lossy' === MJOI_Settings::get( 'png_mode' ) ) {
			$formats['image/png'] = 'image/webp';
		}
		return $formats;
	}

	/**
	 * Umbral de "imagen grande" de WordPress alineado con el tamaño máximo configurado.
	 */
	public static function big_image_threshold( $threshold ) {
		if ( ! self::active() ) {
			return $threshold;
		}
		$max = (int) MJOI_Settings::get( 'max_dimension' );
		return $max > 0 ? $max : $threshold;
	}

	/**
	 * Al borrar un adjunto, borrar también su copia de seguridad.
	 */
	public static function on_delete( $attachment_id ) {
		MJOI_Processor::delete_backup( (int) $attachment_id );
	}
}
