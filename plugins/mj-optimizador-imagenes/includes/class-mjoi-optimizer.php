<?php
/**
 * Motor de optimización de imágenes (Imagick o GD).
 *
 * Funciones principales:
 *  - library():                 detecta la librería disponible ('imagick' | 'gd' | '').
 *  - supports_webp():           indica si el servidor puede generar WebP.
 *  - optimize( $path, $args ):  optimiza un archivo en disco (rotación EXIF, redimensionado,
 *                               limpieza de metadatos, recompresión o conversión a WebP).
 *                               Solo reemplaza el archivo si el resultado es más pequeño
 *                               o si cambia la geometría (rotación/redimensionado).
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Optimizer {

	const MIME_JPEG = 'image/jpeg';
	const MIME_PNG  = 'image/png';
	const MIME_WEBP = 'image/webp';

	/** @var string|null */
	private static $lib = null;

	/** @var array */
	private static $caps = array();

	/**
	 * Formatos que el motor sabe procesar.
	 *
	 * @return string[]
	 */
	public static function supported_mimes() {
		return array( self::MIME_JPEG, self::MIME_PNG, self::MIME_WEBP );
	}

	/**
	 * Librería de imágenes disponible.
	 *
	 * @return string 'imagick', 'gd' o '' si no hay ninguna.
	 */
	public static function library() {
		if ( null !== self::$lib ) {
			return self::$lib;
		}
		$lib = '';
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				if ( Imagick::queryFormats( 'JPEG' ) ) {
					$lib = 'imagick';
				}
			} catch ( Throwable $e ) {
				$lib = '';
			}
		}
		if ( '' === $lib && function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ) ) {
			$lib = 'gd';
		}
		/**
		 * Permite forzar la librería ('imagick' o 'gd').
		 *
		 * @param string $lib Librería detectada.
		 */
		$lib       = apply_filters( 'mjoi_image_library', $lib );
		self::$lib = in_array( $lib, array( 'imagick', 'gd' ), true ) ? $lib : '';
		return self::$lib;
	}

	/**
	 * Etiqueta legible de la librería (para la pantalla de estado).
	 *
	 * @return string
	 */
	public static function library_label() {
		$lib = self::library();
		if ( 'imagick' === $lib ) {
			$v = '';
			try {
				$ver = Imagick::getVersion();
				if ( ! empty( $ver['versionString'] ) && preg_match( '/ImageMagick\s+([\d\.\-]+)/', $ver['versionString'], $m ) ) {
					$v = $m[1];
				}
			} catch ( Throwable $e ) {
				$v = '';
			}
			return 'Imagick' . ( $v ? ' (ImageMagick ' . $v . ')' : '' );
		}
		if ( 'gd' === $lib ) {
			$info = function_exists( 'gd_info' ) ? gd_info() : array();
			return 'GD' . ( ! empty( $info['GD Version'] ) ? ' ' . $info['GD Version'] : '' );
		}
		return __( 'Ninguna (no se puede optimizar)', 'mj-optimizador-imagenes' );
	}

	/**
	 * ¿El servidor puede generar WebP?
	 *
	 * @return bool
	 */
	public static function supports_webp() {
		if ( isset( self::$caps['webp'] ) ) {
			return self::$caps['webp'];
		}
		$lib = self::library();
		$ok  = false;
		if ( 'imagick' === $lib ) {
			try {
				$ok = (bool) Imagick::queryFormats( 'WEBP' );
			} catch ( Throwable $e ) {
				$ok = false;
			}
		} elseif ( 'gd' === $lib ) {
			$ok = function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' );
		}
		self::$caps['webp'] = $ok;
		return $ok;
	}

	/**
	 * ¿El servidor puede generar WebP sin pérdida?
	 *
	 * @return bool
	 */
	public static function supports_webp_lossless() {
		if ( ! self::supports_webp() ) {
			return false;
		}
		if ( 'imagick' === self::library() ) {
			return true;
		}
		return defined( 'IMG_WEBP_LOSSLESS' );
	}

	/**
	 * Optimiza un archivo de imagen en disco.
	 *
	 * @param string $path Ruta absoluta del archivo.
	 * @param array  $args {
	 *     @type int    $quality       Calidad JPEG/WebP (1-100).
	 *     @type int    $max_dimension Lado máximo en px (0 = sin límite).
	 *     @type bool   $convert_webp  Convertir JPEG/PNG a WebP.
	 *     @type string $png_mode      'lossless' | 'lossy' | 'keep' (solo si convert_webp).
	 *     @type bool   $strip_meta    Eliminar metadatos.
	 *     @type bool   $keep_icc      Conservar perfil ICC (solo Imagick).
	 *     @type float  $min_saving    Ahorro mínimo (0-1) para reemplazar en el mismo formato.
	 *     @type bool   $geometry_only Solo re-guardar si hay que rotar o redimensionar.
	 * }
	 * @return array|WP_Error Resultado con claves: path, mime, before, after, width, height,
	 *                        changed, converted, resized, rotated, action, reason.
	 */
	public static function optimize( $path, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'quality'       => 85,
				'max_dimension' => 0,
				'convert_webp'  => false,
				'png_mode'      => 'lossless',
				'strip_meta'    => true,
				'keep_icc'      => true,
				'min_saving'    => 0.03,
				'geometry_only' => false,
			)
		);

		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'mjoi_missing', __( 'El archivo no existe o no se puede leer.', 'mj-optimizador-imagenes' ) );
		}
		if ( ! is_writable( $path ) || ! wp_is_writable( dirname( $path ) ) ) {
			return new WP_Error( 'mjoi_not_writable', __( 'El archivo o su carpeta no tienen permisos de escritura.', 'mj-optimizador-imagenes' ) );
		}

		$lib = self::library();
		if ( '' === $lib ) {
			return new WP_Error( 'mjoi_no_library', __( 'El servidor no tiene Imagick ni GD disponibles.', 'mj-optimizador-imagenes' ) );
		}

		clearstatcache( true, $path );
		$before = (int) filesize( $path );
		$info   = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info['mime'] ) ) {
			return new WP_Error( 'mjoi_invalid', __( 'El archivo no es una imagen válida.', 'mj-optimizador-imagenes' ) );
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];
		$mime   = (string) $info['mime'];

		$result = array(
			'path'      => $path,
			'mime'      => $mime,
			'before'    => $before,
			'after'     => $before,
			'width'     => $width,
			'height'    => $height,
			'changed'   => false,
			'converted' => false,
			'resized'   => false,
			'rotated'   => false,
			'action'    => 'skipped',
			'reason'    => '',
		);

		if ( ! in_array( $mime, self::supported_mimes(), true ) ) {
			$result['reason'] = 'formato_no_soportado';
			return $result;
		}

		$quality      = max( 1, min( 100, (int) $args['quality'] ) );
		$max          = max( 0, (int) $args['max_dimension'] );
		$needs_resize = ( $max > 0 && ( $width > $max || $height > $max ) );

		// Formato de salida.
		$target   = $mime;
		$lossless = false;
		if ( $args['convert_webp'] && self::supports_webp() ) {
			if ( self::MIME_JPEG === $mime ) {
				$target = self::MIME_WEBP;
			} elseif ( self::MIME_PNG === $mime ) {
				if ( 'lossy' === $args['png_mode'] ) {
					$target = self::MIME_WEBP;
				} elseif ( 'lossless' === $args['png_mode'] && self::supports_webp_lossless() ) {
					$target   = self::MIME_WEBP;
					$lossless = true;
				}
			}
		}
		if ( self::MIME_PNG === $target ) {
			$lossless = true;
		}

		// Orientación EXIF (solo JPEG).
		$orientation  = ( self::MIME_JPEG === $mime ) ? self::read_orientation( $path ) : 1;
		$needs_rotate = $orientation > 1;

		if ( $args['geometry_only'] && ! $needs_resize && ! $needs_rotate ) {
			$result['reason'] = 'geometria_correcta';
			return $result;
		}

		// Un WebP de origen solo se toca si hay que redimensionarlo (evita recomprimir con pérdida).
		if ( self::MIME_WEBP === $mime && ! $needs_resize ) {
			$result['reason'] = 'webp_ya_optimizado';
			return $result;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		if ( 'gd' === $lib && ! self::gd_memory_ok( $width, $height, $needs_rotate, $needs_resize ? $max : 0 ) ) {
			return new WP_Error( 'mjoi_memory', __( 'Memoria PHP insuficiente para procesar esta imagen con GD.', 'mj-optimizador-imagenes' ) );
		}

		$encode = array(
			'quality'    => $quality,
			'lossless'   => $lossless,
			'keep_icc'   => (bool) $args['keep_icc'],
			'strip_meta' => (bool) $args['strip_meta'],
		);
		$geom   = array(
			'max'         => $max,
			'orientation' => $orientation,
		);

		try {
			$out = self::render( $lib, $path, $mime, $target, $encode, $geom );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mjoi_exception', $e->getMessage() );
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$after            = strlen( $out['blob'] );
		$geometry_changed = ( $out['resized'] || $out['rotated'] );
		$converted        = ( $out['mime'] !== $mime );

		// Si convertir a WebP no reduce el tamaño, reintentar en el formato original.
		if ( $converted && $after >= $before && ! $geometry_changed ) {
			try {
				$out2 = self::render( $lib, $path, $mime, $mime, $encode, $geom );
			} catch ( Throwable $e ) {
				$out2 = null;
			}
			if ( $out2 && ! is_wp_error( $out2 ) ) {
				$out       = $out2;
				$after     = strlen( $out['blob'] );
				$converted = false;
			}
		}

		$min_saving = max( 0, (float) $args['min_saving'] );
		$accept     = $geometry_changed
			|| ( $converted && $after < $before )
			|| ( ! $converted && $after < $before * ( 1 - $min_saving ) );

		if ( ! $accept ) {
			$result['reason'] = 'sin_ahorro';
			return $result;
		}

		$new_path = $converted ? self::unique_target_path( $path, $out['mime'] ) : $path;
		$written  = self::write_atomic( $new_path, $out['blob'], $path );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		if ( $converted && wp_normalize_path( $new_path ) !== wp_normalize_path( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		clearstatcache( true, $new_path );

		return array_merge(
			$result,
			array(
				'path'      => $new_path,
				'mime'      => $out['mime'],
				'after'     => (int) filesize( $new_path ),
				'width'     => (int) $out['width'],
				'height'    => (int) $out['height'],
				'changed'   => true,
				'converted' => $converted,
				'resized'   => (bool) $out['resized'],
				'rotated'   => (bool) $out['rotated'],
				'action'    => $converted ? 'converted' : 'optimized',
				'reason'    => '',
			)
		);
	}

	/**
	 * Enruta al motor concreto.
	 *
	 * @return array|WP_Error
	 */
	private static function render( $lib, $path, $mime, $target, array $encode, array $geom ) {
		if ( 'imagick' === $lib ) {
			return self::process_imagick( $path, $mime, $target, $encode, $geom );
		}
		return self::process_gd( $path, $mime, $target, $encode, $geom );
	}

	/* ---------------------------------------------------------------------
	 * Imagick
	 * ------------------------------------------------------------------ */

	/**
	 * Procesa con Imagick y devuelve el binario resultante.
	 *
	 * @return array|WP_Error
	 */
	private static function process_imagick( $path, $mime, $target, array $encode, array $geom ) {
		$im = new Imagick();
		$im->readImage( $path );

		if ( $im->getNumberImages() > 1 ) {
			$im->clear();
			return new WP_Error( 'mjoi_animated', __( 'Imagen animada: se omite.', 'mj-optimizador-imagenes' ) );
		}

		// Primero redimensionar (más rápido), después aplicar la orientación EXIF.
		$w       = $im->getImageWidth();
		$h       = $im->getImageHeight();
		$resized = false;
		if ( $geom['max'] > 0 && ( $w > $geom['max'] || $h > $geom['max'] ) ) {
			$dims = wp_constrain_dimensions( $w, $h, $geom['max'], $geom['max'] );
			$im->resizeImage( $dims[0], $dims[1], Imagick::FILTER_LANCZOS, 1 );
			try {
				$im->unsharpMaskImage( 0.25, 0.25, 8, 0.065 );
			} catch ( Throwable $e ) {
				// Opcional.
			}
			$w       = $dims[0];
			$h       = $dims[1];
			$resized = true;
		}

		$rotated     = false;
		$orientation = (int) $geom['orientation'];
		if ( $orientation <= 1 ) {
			try {
				$orientation = (int) $im->getImageOrientation();
			} catch ( Throwable $e ) {
				$orientation = 1;
			}
		}
		if ( $orientation > 1 && $orientation <= 8 ) {
			self::imagick_orient( $im, $orientation );
			$rotated = true;
			$w       = $im->getImageWidth();
			$h       = $im->getImageHeight();
		}

		// CMYK y otros espacios de color no aptos para web → sRGB.
		try {
			if ( Imagick::COLORSPACE_CMYK === $im->getImageColorspace() ) {
				$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}
		} catch ( Throwable $e ) {
			// Ignorar.
		}

		if ( $encode['strip_meta'] ) {
			$icc = null;
			if ( $encode['keep_icc'] ) {
				try {
					$profiles = $im->getImageProfiles( 'icc', true );
					if ( ! empty( $profiles['icc'] ) ) {
						$icc = $profiles['icc'];
					}
				} catch ( Throwable $e ) {
					$icc = null;
				}
			}
			$im->stripImage();
			if ( $icc ) {
				try {
					$im->profileImage( 'icc', $icc );
				} catch ( Throwable $e ) {
					// Ignorar.
				}
			}
		}

		$q = (int) $encode['quality'];
		switch ( $target ) {
			case self::MIME_JPEG:
				$im->setImageFormat( 'jpeg' );
				if ( self::MIME_JPEG === $mime ) {
					// No subir la calidad por encima de la original: solo produce archivos más grandes.
					$oq = (int) $im->getImageCompressionQuality();
					if ( $oq > 0 && $oq < $q ) {
						$q = $oq;
					}
				}
				$im->setImageCompression( Imagick::COMPRESSION_JPEG );
				$im->setImageCompressionQuality( $q );
				$im->setInterlaceScheme( Imagick::INTERLACE_PLANE );
				if ( $q < 90 ) {
					$im->setSamplingFactors( array( '2x2', '1x1', '1x1' ) );
				}
				break;

			case self::MIME_PNG:
				$im->setImageFormat( 'png' );
				$im->setOption( 'png:compression-level', '9' );
				$im->setOption( 'png:compression-filter', '5' );
				$im->setOption( 'png:compression-strategy', '1' );
				$im->setOption( 'png:exclude-chunk', 'date,tEXt,zTXt,iTXt,tIME' );
				break;

			case self::MIME_WEBP:
				$im->setImageFormat( 'webp' );
				$im->setOption( 'webp:method', '6' );
				if ( $encode['lossless'] ) {
					$im->setOption( 'webp:lossless', 'true' );
					$im->setImageCompressionQuality( 100 );
				} else {
					$im->setOption( 'webp:lossless', 'false' );
					$im->setOption( 'webp:alpha-quality', '100' );
					$im->setImageCompressionQuality( $q );
				}
				break;
		}

		$blob = '';
		try {
			$blob = $im->getImageBlob();
		} catch ( Throwable $e ) {
			$blob = '';
		}
		if ( ! is_string( $blob ) || '' === $blob ) {
			// Algunas versiones fallan con getImageBlob() en WebP: escribir a un temporal.
			$tmp = tempnam( get_temp_dir(), 'mjoi' );
			if ( $tmp ) {
				$im->writeImage( strtoupper( self::ext_for_mime( $target ) ) . ':' . $tmp );
				$blob = (string) file_get_contents( $tmp );
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		$im->clear();

		if ( '' === $blob ) {
			return new WP_Error( 'mjoi_imagick_encode', __( 'Imagick no pudo generar la imagen.', 'mj-optimizador-imagenes' ) );
		}

		return array(
			'blob'    => $blob,
			'mime'    => $target,
			'width'   => $w,
			'height'  => $h,
			'resized' => $resized,
			'rotated' => $rotated,
		);
	}

	/**
	 * Aplica la orientación EXIF (equivalente a -auto-orient de ImageMagick).
	 */
	private static function imagick_orient( Imagick $im, $o ) {
		$bg = new ImagickPixel( 'none' );
		switch ( (int) $o ) {
			case 2:
				$im->flopImage();
				break;
			case 3:
				$im->rotateImage( $bg, 180 );
				break;
			case 4:
				$im->flipImage();
				break;
			case 5:
				$im->transposeImage();
				break;
			case 6:
				$im->rotateImage( $bg, 90 );
				break;
			case 7:
				$im->transverseImage();
				break;
			case 8:
				$im->rotateImage( $bg, -90 );
				break;
		}
		$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
		try {
			$im->setImageProperty( 'exif:Orientation', '1' );
		} catch ( Throwable $e ) {
			// Ignorar.
		}
	}

	/* ---------------------------------------------------------------------
	 * GD
	 * ------------------------------------------------------------------ */

	/**
	 * Procesa con GD y devuelve el binario resultante.
	 *
	 * @return array|WP_Error
	 */
	private static function process_gd( $path, $mime, $target, array $encode, array $geom ) {
		switch ( $mime ) {
			case self::MIME_JPEG:
				$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case self::MIME_PNG:
				$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case self::MIME_WEBP:
				$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			default:
				$img = false;
		}
		if ( ! $img ) {
			return new WP_Error( 'mjoi_gd_load', __( 'GD no pudo leer la imagen (¿animada o dañada?).', 'mj-optimizador-imagenes' ) );
		}

		$has_alpha = ( self::MIME_PNG === $mime || self::MIME_WEBP === $mime );
		if ( $has_alpha ) {
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
		}

		// Primero redimensionar (menos memoria), después aplicar la orientación EXIF.
		$w       = imagesx( $img );
		$h       = imagesy( $img );
		$resized = false;
		if ( $geom['max'] > 0 && ( $w > $geom['max'] || $h > $geom['max'] ) ) {
			$dims = wp_constrain_dimensions( $w, $h, $geom['max'], $geom['max'] );
			$dst  = imagecreatetruecolor( $dims[0], $dims[1] );
			if ( ! $dst ) {
				imagedestroy( $img );
				return new WP_Error( 'mjoi_gd_resize', __( 'GD no pudo crear el lienzo redimensionado.', 'mj-optimizador-imagenes' ) );
			}
			if ( $has_alpha ) {
				imagealphablending( $dst, false );
				imagesavealpha( $dst, true );
				$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
				imagefilledrectangle( $dst, 0, 0, $dims[0], $dims[1], $transparent );
			}
			imagecopyresampled( $dst, $img, 0, 0, 0, 0, $dims[0], $dims[1], $w, $h );
			imagedestroy( $img );
			$img     = $dst;
			$w       = $dims[0];
			$h       = $dims[1];
			$resized = true;
		}

		$rotated     = false;
		$orientation = (int) $geom['orientation'];
		if ( $orientation > 1 && $orientation <= 8 ) {
			$img     = self::gd_orient( $img, $orientation, $has_alpha );
			$rotated = true;
			$w       = imagesx( $img );
			$h       = imagesy( $img );
		}

		// El codificador WebP de GD necesita imágenes truecolor.
		if ( self::MIME_WEBP === $target && ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
		}

		$q = (int) $encode['quality'];
		ob_start();
		$ok = false;
		switch ( $target ) {
			case self::MIME_JPEG:
				imageinterlace( $img, true );
				$ok = imagejpeg( $img, null, $q );
				break;
			case self::MIME_PNG:
				$ok = imagepng( $img, null, 9 );
				break;
			case self::MIME_WEBP:
				if ( $encode['lossless'] && defined( 'IMG_WEBP_LOSSLESS' ) ) {
					$ok = imagewebp( $img, null, IMG_WEBP_LOSSLESS );
				} else {
					$ok = imagewebp( $img, null, $q );
				}
				break;
		}
		$blob = (string) ob_get_clean();
		imagedestroy( $img );

		if ( ! $ok || '' === $blob ) {
			return new WP_Error( 'mjoi_gd_encode', __( 'GD no pudo generar la imagen.', 'mj-optimizador-imagenes' ) );
		}

		return array(
			'blob'    => $blob,
			'mime'    => $target,
			'width'   => $w,
			'height'  => $h,
			'resized' => $resized,
			'rotated' => $rotated,
		);
	}

	/**
	 * Aplica la orientación EXIF con GD.
	 *
	 * @param resource|GdImage $img       Imagen.
	 * @param int              $o         Orientación EXIF (2-8).
	 * @param bool             $has_alpha Conservar transparencia.
	 * @return resource|GdImage
	 */
	private static function gd_orient( $img, $o, $has_alpha ) {
		$rotate = function ( $image, $deg ) use ( $has_alpha ) {
			$out = imagerotate( $image, $deg, 0 );
			if ( ! $out ) {
				return $image;
			}
			imagedestroy( $image );
			if ( $has_alpha ) {
				imagealphablending( $out, false );
				imagesavealpha( $out, true );
			}
			return $out;
		};
		switch ( (int) $o ) {
			case 2:
				imageflip( $img, IMG_FLIP_HORIZONTAL );
				break;
			case 3:
				$img = $rotate( $img, 180 );
				break;
			case 4:
				imageflip( $img, IMG_FLIP_VERTICAL );
				break;
			case 5:
				imageflip( $img, IMG_FLIP_VERTICAL );
				$img = $rotate( $img, -90 );
				break;
			case 6:
				$img = $rotate( $img, -90 );
				break;
			case 7:
				imageflip( $img, IMG_FLIP_HORIZONTAL );
				$img = $rotate( $img, -90 );
				break;
			case 8:
				$img = $rotate( $img, 90 );
				break;
		}
		return $img;
	}

	/**
	 * Comprueba de forma aproximada si GD tendrá memoria suficiente
	 * (GD usa 4 bytes por píxel; redimensionar o rotar crea una segunda imagen).
	 *
	 * @param int  $w      Ancho.
	 * @param int  $h      Alto.
	 * @param bool $rotate Hará falta rotar.
	 * @param int  $max    Lado máximo si hay que redimensionar (0 = no).
	 * @return bool
	 */
	private static function gd_memory_ok( $w, $h, $rotate = false, $max = 0 ) {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		$px = $w * $h * 4;
		if ( $max > 0 ) {
			$d      = wp_constrain_dimensions( $w, $h, $max, $max );
			$needed = $px + ( $d[0] * $d[1] * 4 );
		} elseif ( $rotate ) {
			$needed = 2 * $px;
		} else {
			$needed = $px;
		}
		$needed = (int) ( $needed * 1.15 ) + 16 * MB_IN_BYTES;
		return ( memory_get_usage( true ) + $needed ) <= $limit;
	}

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	/**
	 * Lee la orientación EXIF de un JPEG (1 si no hay).
	 *
	 * @param string $path Ruta.
	 * @return int
	 */
	public static function read_orientation( $path ) {
		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $exif ) && isset( $exif['Orientation'] ) ) {
				$o = (int) $exif['Orientation'];
				return ( $o >= 1 && $o <= 8 ) ? $o : 1;
			}
		}
		return self::parse_jpeg_orientation( $path );
	}

	/**
	 * Lector mínimo de la etiqueta Orientation (0x0112) del segmento APP1/EXIF.
	 * Se usa cuando la extensión exif de PHP no está disponible.
	 *
	 * @param string $path Ruta.
	 * @return int
	 */
	public static function parse_jpeg_orientation( $path ) {
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return 1;
		}
		$orientation = 1;
		try {
			if ( fread( $fh, 2 ) !== "\xFF\xD8" ) {
				return 1;
			}
			$guard = 0;
			while ( ! feof( $fh ) && $guard++ < 64 ) {
				$byte = fread( $fh, 1 );
				if ( "\xFF" !== $byte ) {
					break;
				}
				// Saltar bytes de relleno 0xFF.
				$type = fread( $fh, 1 );
				while ( "\xFF" === $type && ! feof( $fh ) ) {
					$type = fread( $fh, 1 );
				}
				if ( '' === $type ) {
					break;
				}
				$marker = ord( $type );
				if ( 0xD8 === $marker || 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
					continue; // Marcadores sin longitud.
				}
				if ( 0xDA === $marker || 0xD9 === $marker ) {
					break; // Inicio de datos / fin de imagen.
				}
				$lenb = fread( $fh, 2 );
				if ( strlen( $lenb ) < 2 ) {
					break;
				}
				$len = unpack( 'n', $lenb );
				$len = (int) $len[1];
				if ( $len < 2 ) {
					break;
				}
				if ( 0xE1 !== $marker ) {
					fseek( $fh, $len - 2, SEEK_CUR );
					continue;
				}
				$seg = (string) fread( $fh, $len - 2 );
				if ( "Exif\0\0" !== substr( $seg, 0, 6 ) ) {
					continue;
				}
				$tiff = substr( $seg, 6 );
				if ( strlen( $tiff ) < 8 ) {
					break;
				}
				$endian = substr( $tiff, 0, 2 );
				if ( 'II' === $endian ) {
					$f32 = 'V';
					$f16 = 'v';
				} elseif ( 'MM' === $endian ) {
					$f32 = 'N';
					$f16 = 'n';
				} else {
					break;
				}
				$ifd0 = unpack( $f32, substr( $tiff, 4, 4 ) );
				$ifd0 = (int) $ifd0[1];
				if ( $ifd0 + 2 > strlen( $tiff ) ) {
					break;
				}
				$count = unpack( $f16, substr( $tiff, $ifd0, 2 ) );
				$count = (int) $count[1];
				for ( $i = 0; $i < $count; $i++ ) {
					$e = $ifd0 + 2 + ( $i * 12 );
					if ( $e + 12 > strlen( $tiff ) ) {
						break 2;
					}
					$tag = unpack( $f16, substr( $tiff, $e, 2 ) );
					if ( 0x0112 === (int) $tag[1] ) {
						$val = unpack( $f16, substr( $tiff, $e + 8, 2 ) );
						$val = (int) $val[1];
						if ( $val >= 1 && $val <= 8 ) {
							$orientation = $val;
						}
						break 2;
					}
				}
				break;
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $orientation;
	}

	/**
	 * Extensión de archivo para un mime.
	 */
	public static function ext_for_mime( $mime ) {
		switch ( $mime ) {
			case self::MIME_JPEG:
				return 'jpg';
			case self::MIME_PNG:
				return 'png';
			case self::MIME_WEBP:
				return 'webp';
		}
		return 'bin';
	}

	/**
	 * Ruta destino para una conversión de formato, evitando colisiones.
	 */
	private static function unique_target_path( $path, $mime ) {
		$dir       = dirname( $path );
		$name      = preg_replace( '/\.[^.]+$/', '', wp_basename( $path ) );
		$candidate = $name . '.' . self::ext_for_mime( $mime );
		if ( file_exists( $dir . DIRECTORY_SEPARATOR . $candidate ) ) {
			$candidate = wp_unique_filename( $dir, $candidate );
		}
		return wp_normalize_path( $dir . '/' . $candidate );
	}

	/**
	 * Escribe el binario de forma atómica (temporal + rename) conservando permisos.
	 *
	 * @return true|WP_Error
	 */
	private static function write_atomic( $target, $blob, $reference ) {
		$tmp   = $target . '.mjoi-tmp';
		$bytes = @file_put_contents( $tmp, $blob, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes || $bytes !== strlen( $blob ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mjoi_write', __( 'No se pudo escribir el archivo optimizado.', 'mj-optimizador-imagenes' ) );
		}
		$perms = @fileperms( $reference ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mjoi_rename', __( 'No se pudo reemplazar el archivo original.', 'mj-optimizador-imagenes' ) );
			}
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( false !== $perms ) {
			@chmod( $target, $perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}
}
