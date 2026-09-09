<?php
/**
 * Ajustes del plugin: valores por defecto, lectura y saneado.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Settings {

	const OPTION = 'mjoi_settings';

	/** @var array|null Caché por petición. */
	private static $cache = null;

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'       => 1,          // Optimización automática al subir.
			'quality'       => 85,         // Calidad JPEG/WebP (85 = visualmente sin pérdida).
			'webp'          => 1,          // Convertir nuevas subidas a WebP.
			'png_mode'      => 'lossless', // lossless | lossy | keep.
			'max_dimension' => 2560,       // Lado máximo en píxeles (0 = sin límite).
			'strip_meta'    => 1,          // Eliminar EXIF y otros metadatos.
			'keep_icc'      => 1,          // Conservar perfil de color ICC.
			'min_saving'    => 3,          // % mínimo de ahorro para reemplazar (mismo formato).
			'background'    => 1,          // Optimizar la biblioteca existente en segundo plano.
			'webp_existing' => 0,          // Convertir también las imágenes existentes a WebP.
			'replace_urls'  => 1,          // Actualizar URLs en contenido al convertir existentes.
			'backup'        => 0,          // Guardar copia del original para poder restaurar.
			'defer_upload'  => 0,          // Procesar las subidas en segundo plano.
		);
	}

	/**
	 * Devuelve todos los ajustes o uno concreto.
	 *
	 * @param string|null $key Clave opcional.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Guarda ajustes (saneándolos) y limpia la caché.
	 *
	 * @param array $values Valores parciales o completos.
	 */
	public static function update( array $values ) {
		$clean = self::sanitize( wp_parse_args( $values, self::get() ) );
		update_option( self::OPTION, $clean );
		self::$cache = null;
	}

	/**
	 * Limpia la caché por petición.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanea el array recibido del formulario.
	 *
	 * @param mixed $input Entrada.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		foreach ( array( 'enabled', 'webp', 'strip_meta', 'keep_icc', 'background', 'webp_existing', 'replace_urls', 'backup', 'defer_upload' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		$quality        = isset( $in['quality'] ) ? absint( $in['quality'] ) : $d['quality'];
		$out['quality'] = max( 50, min( 100, $quality ) );

		$png_mode        = isset( $in['png_mode'] ) ? sanitize_key( $in['png_mode'] ) : $d['png_mode'];
		$out['png_mode'] = in_array( $png_mode, array( 'lossless', 'lossy', 'keep' ), true ) ? $png_mode : $d['png_mode'];

		$max = isset( $in['max_dimension'] ) ? absint( $in['max_dimension'] ) : $d['max_dimension'];
		if ( $max > 0 && $max < 500 ) {
			$max = 500;
		}
		$out['max_dimension'] = min( $max, 20000 );

		$min_saving        = isset( $in['min_saving'] ) ? absint( $in['min_saving'] ) : $d['min_saving'];
		$out['min_saving'] = min( 50, $min_saving );

		self::$cache = null;
		return $out;
	}
}
