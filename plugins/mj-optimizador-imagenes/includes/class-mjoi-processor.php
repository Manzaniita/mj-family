<?php
/**
 * Procesa un adjunto completo: imagen principal, original sin escalar y miniaturas.
 *
 * Funciones principales:
 *  - process_attachment( $id, $opts ): optimiza todos los archivos del adjunto, actualiza
 *    metadatos/URLs y guarda estadísticas. $opts['mode'] = 'upload' (recién subida) | 'bulk'.
 *  - restore( $id ): restaura la imagen desde la copia de seguridad y regenera miniaturas.
 *  - replace_urls( $map ): sustituye URLs antiguas por nuevas en contenido y metadatos.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Processor {

	const META_DATA    = '_mjoi_data';
	const META_STATUS  = '_mjoi_status';
	const META_BACKUP  = '_mjoi_backup';
	const META_QUEUED  = '_mjoi_queued';
	const STATS_OPTION = 'mjoi_stats';
	const LOG_OPTION   = 'mjoi_log';
	const BACKUP_DIR   = 'mjoi-backups';

	/** @var bool Cuando es true los hooks del plugin no actúan (p. ej. al restaurar). */
	public static $bypass = false;

	/** @var array Tamaños de archivo en el momento de la subida, por ruta. */
	private static $upload_sizes = array();

	/**
	 * Estructura vacía de estadísticas globales.
	 *
	 * @return array
	 */
	public static function empty_stats() {
		return array(
			'images' => 0,
			'before' => 0,
			'after'  => 0,
		);
	}

	/**
	 * Mimes procesables.
	 *
	 * @return string[]
	 */
	public static function allowed_mimes() {
		return MJOI_Optimizer::supported_mimes();
	}

	/* ---------------------------------------------------------------------
	 * Información del paso de subida
	 * ------------------------------------------------------------------ */

	/**
	 * Guarda el tamaño del archivo tal como se subió (antes de tocarlo).
	 *
	 * @param string $path   Ruta.
	 * @param int    $before Bytes.
	 */
	public static function remember_upload( $path, $before ) {
		$key                        = md5( wp_normalize_path( $path ) );
		self::$upload_sizes[ $key ] = (int) $before;
		set_transient( 'mjoi_up_' . $key, (int) $before, HOUR_IN_SECONDS );
	}

	/**
	 * Recupera (y olvida) el tamaño del archivo subido.
	 *
	 * @param string $path Ruta.
	 * @return int
	 */
	private static function take_upload_size( $path ) {
		$key = md5( wp_normalize_path( $path ) );
		if ( isset( self::$upload_sizes[ $key ] ) ) {
			$v = self::$upload_sizes[ $key ];
			unset( self::$upload_sizes[ $key ] );
			delete_transient( 'mjoi_up_' . $key );
			return (int) $v;
		}
		$v = get_transient( 'mjoi_up_' . $key );
		if ( false !== $v ) {
			delete_transient( 'mjoi_up_' . $key );
			return (int) $v;
		}
		return 0;
	}

	/* ---------------------------------------------------------------------
	 * Procesado principal
	 * ------------------------------------------------------------------ */

	/**
	 * Optimiza todos los archivos de un adjunto.
	 *
	 * @param int   $id   ID del adjunto.
	 * @param array $opts {
	 *     @type string     $mode 'upload' (recién subida: las miniaturas JPEG/WebP ya vienen optimizadas) o 'bulk'.
	 *     @type array|null $meta Metadatos a usar en lugar de leerlos de la BD.
	 *     @type bool       $save Guardar metadatos al terminar.
	 *     @type bool|null  $webp Forzar (o no) conversión a WebP; null = según ajustes.
	 * }
	 * @return array|WP_Error
	 */
	public static function process_attachment( $id, array $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'mode' => 'bulk',
				'meta' => null,
				'save' => true,
				'webp' => null,
			)
		);
		$id   = (int) $id;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'mjoi_not_attachment', __( 'El ID no corresponde a un archivo adjunto.', 'mj-optimizador-imagenes' ) );
		}
		if ( ! in_array( $post->post_mime_type, self::allowed_mimes(), true ) ) {
			self::set_status( $id, 'skipped' );
			return new WP_Error( 'mjoi_unsupported', __( 'Formato no soportado (solo JPEG, PNG y WebP).', 'mj-optimizador-imagenes' ) );
		}

		$s    = MJOI_Settings::get();
		$meta = is_array( $opts['meta'] ) ? $opts['meta'] : wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$full = get_attached_file( $id, true );
		if ( ! $full || ! file_exists( $full ) ) {
			self::set_status( $id, 'error' );
			return new WP_Error( 'mjoi_file_missing', __( 'No se encuentra el archivo de la imagen en el servidor.', 'mj-optimizador-imagenes' ) );
		}
		$full = wp_normalize_path( $full );
		if ( ! self::in_uploads( $full ) ) {
			self::set_status( $id, 'skipped' );
			return new WP_Error( 'mjoi_outside_uploads', __( 'El archivo está fuera de la carpeta de subidas; se omite por seguridad.', 'mj-optimizador-imagenes' ) );
		}

		$orig_full = $full;
		$dir       = dirname( $full );
		$is_upload = ( 'upload' === $opts['mode'] );
		$prev      = get_post_meta( $id, self::META_DATA, true );
		$prev      = is_array( $prev ) ? $prev : null;

		self::set_status( $id, 'processing' );

		if ( null !== $opts['webp'] ) {
			$convert_webp = (bool) $opts['webp'];
		} elseif ( $is_upload ) {
			$convert_webp = (bool) $s['webp'];
		} else {
			$convert_webp = (bool) $s['webp'] && (bool) $s['webp_existing'];
		}

		$engine = array(
			'quality'       => (int) $s['quality'],
			'max_dimension' => (int) $s['max_dimension'],
			'convert_webp'  => $convert_webp,
			'png_mode'      => $s['png_mode'],
			'strip_meta'    => (bool) $s['strip_meta'],
			'keep_icc'      => (bool) $s['keep_icc'],
			'min_saving'    => max( 0, (int) $s['min_saving'] ) / 100,
		);

		if ( $s['backup'] && ! get_post_meta( $id, self::META_BACKUP, true ) ) {
			self::create_backup( $id, $meta, $full, $dir );
		}

		$before  = 0;
		$after   = 0;
		$files   = 0;
		$changed = 0;
		$errors  = array();
		$url_map = array();

		// 1) Original sin escalar que WordPress guarda aparte (ocupa mucho y no se usa en la web).
		if ( ! empty( $meta['original_image'] ) && (int) $s['max_dimension'] > 0 ) {
			$orig = wp_normalize_path( $dir . '/' . $meta['original_image'] );
			if ( $orig !== $full && file_exists( $orig ) && self::in_uploads( $orig ) ) {
				$sz = (int) filesize( $orig );
				if ( @unlink( $orig ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$before += $sz;
					$changed++;
					unset( $meta['original_image'] );
				}
			} elseif ( ! file_exists( $orig ) ) {
				unset( $meta['original_image'] );
			}
		}

		// 2) Imagen principal.
		$files++;
		$final_mime  = $post->post_mime_type;
		$full_before = 0;
		$r           = MJOI_Optimizer::optimize( $full, $engine );
		if ( is_wp_error( $r ) ) {
			$errors[]    = __( 'principal', 'mj-optimizador-imagenes' ) . ': ' . $r->get_error_message();
			$sz          = (int) @filesize( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$before     += $sz;
			$after      += $sz;
			$full_before = $sz;
		} else {
			$before     += $r['before'];
			$after      += $r['after'];
			$full_before = $r['before'];
			$final_mime  = $r['mime'];
			if ( $r['changed'] ) {
				$changed++;
				$meta['width']    = $r['width'];
				$meta['height']   = $r['height'];
				$meta['filesize'] = $r['after'];
				$new_full         = wp_normalize_path( $r['path'] );
				if ( $new_full !== $full ) {
					$url_map[ self::path_to_url( $full ) ] = self::path_to_url( $new_full );
					$meta['file']                          = self::to_relative( $new_full );
					update_attached_file( $id, self::denormalize( $new_full ) );
					self::update_post_file( $id, $r['mime'], self::path_to_url( $new_full ) );
					$full = $new_full;
				}
			}
		}

		// Tamaño real con el que se subió (antes de rotar/redimensionar en el paso previo).
		$uploaded = self::take_upload_size( $orig_full );
		if ( $uploaded > $full_before ) {
			$before += ( $uploaded - $full_before );
		}

		// 3) Miniaturas.
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$done = array();
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$p = wp_normalize_path( $dir . '/' . $size['file'] );
				if ( $p === $full || $p === $orig_full ) {
					continue;
				}
				if ( ! array_key_exists( $p, $done ) ) {
					$done[ $p ] = null;
					if ( ! file_exists( $p ) || ! self::in_uploads( $p ) ) {
						continue;
					}
					$mime = ! empty( $size['mime-type'] ) ? (string) $size['mime-type'] : '';
					if ( '' === $mime ) {
						$ft   = wp_check_filetype( $p );
						$mime = (string) $ft['type'];
					}
					$files++;
					if ( $is_upload && MJOI_Optimizer::MIME_PNG !== $mime ) {
						// WordPress acaba de generar esta miniatura con la calidad y formato configurados.
						$sz      = (int) filesize( $p );
						$before += $sz;
						$after  += $sz;
						continue;
					}
					$sargs                  = $engine;
					$sargs['max_dimension'] = 0;
					$res                    = MJOI_Optimizer::optimize( $p, $sargs );
					if ( is_wp_error( $res ) ) {
						$errors[] = $name . ': ' . $res->get_error_message();
						$sz       = (int) @filesize( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$before  += $sz;
						$after   += $sz;
						continue;
					}
					$before += $res['before'];
					$after  += $res['after'];
					if ( $res['changed'] ) {
						$changed++;
					}
					$done[ $p ] = $res;
				}
				$res = $done[ $p ];
				if ( ! $res || empty( $res['changed'] ) ) {
					continue;
				}
				$meta['sizes'][ $name ]['mime-type'] = $res['mime'];
				$meta['sizes'][ $name ]['filesize']  = $res['after'];
				$np                                  = wp_normalize_path( $res['path'] );
				if ( $np !== $p ) {
					$meta['sizes'][ $name ]['file']   = wp_basename( $np );
					$url_map[ self::path_to_url( $p ) ] = self::path_to_url( $np );
				}
			}
		}

		if ( $opts['save'] ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		if ( $url_map && ! $is_upload && $s['replace_urls'] ) {
			self::replace_urls( $url_map );
		}

		$saved = max( 0, $before - $after );
		$data  = array(
			'before'  => $before,
			'after'   => $after,
			'saved'   => $saved,
			'percent' => $before > 0 ? round( $saved * 100 / $before, 1 ) : 0,
			'files'   => $files,
			'changed' => $changed,
			'mime'    => $final_mime,
			'webp'    => ( MJOI_Optimizer::MIME_WEBP === $final_mime ),
			'date'    => time(),
			'version' => MJOI_VERSION,
			'library' => MJOI_Optimizer::library(),
			'mode'    => $opts['mode'],
			'errors'  => $errors,
		);
		update_post_meta( $id, self::META_DATA, $data );
		delete_post_meta( $id, self::META_QUEUED );

		$status = ( $errors && 0 === $changed ) ? 'error' : 'done';
		self::set_status( $id, $status );
		self::add_stats( $before, $after, $prev );

		self::log(
			sprintf(
				/* translators: 1: ahorro, 2: porcentaje, 3: archivos, 4: errores */
				__( 'Optimizada: ahorro %1$s (%2$s%%), %3$d archivos%4$s', 'mj-optimizador-imagenes' ),
				size_format( $saved ),
				$data['percent'],
				$files,
				$errors ? ' · ' . implode( ' | ', $errors ) : ''
			),
			$id
		);

		return array(
			'id'     => $id,
			'meta'   => $meta,
			'data'   => $data,
			'status' => $status,
		);
	}

	/* ---------------------------------------------------------------------
	 * Copias de seguridad y restauración
	 * ------------------------------------------------------------------ */

	/**
	 * Carpeta de copias de seguridad (uploads/mjoi-backups).
	 *
	 * @return string
	 */
	public static function backup_dir() {
		$u   = wp_get_upload_dir();
		$dir = trailingslashit( wp_normalize_path( $u['basedir'] ) ) . self::BACKUP_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Copia el archivo recién subido (aún sin adjunto) para conservar el original intacto.
	 *
	 * @param string $path Ruta del archivo subido.
	 * @return bool
	 */
	public static function create_pending_backup( $path ) {
		$dest = self::pending_backup_path( $path );
		return (bool) @copy( $path, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Ruta de la copia pendiente asociada a una ruta de subida.
	 */
	private static function pending_backup_path( $path ) {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return self::backup_dir() . '/pending-' . md5( wp_normalize_path( $path ) ) . ( $ext ? '.' . $ext : '' );
	}

	/**
	 * Crea la copia de seguridad definitiva de un adjunto.
	 */
	private static function create_backup( $id, array $meta, $full, $dir ) {
		$bdir = self::backup_dir();
		$src  = $full;
		if ( ! empty( $meta['original_image'] ) && file_exists( $dir . '/' . $meta['original_image'] ) ) {
			$src = wp_normalize_path( $dir . '/' . $meta['original_image'] );
		}
		$pending = self::pending_backup_path( $full );
		$dest    = $bdir . '/' . $id . '-' . wp_basename( $src );
		$ok      = false;

		if ( file_exists( $pending ) ) {
			$ok = @rename( $pending, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $ok ) {
				$ok = @copy( $pending, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $pending ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( ! $ok ) {
			$ok = @copy( $src, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $ok ) {
			update_post_meta(
				$id,
				self::META_BACKUP,
				array(
					'file'      => wp_basename( $dest ),
					'orig_file' => self::to_relative( $src ),
					'mime'      => (string) get_post_mime_type( $id ),
					'size'      => (int) filesize( $dest ),
					'date'      => time(),
				)
			);
		}
		return $ok;
	}

	/**
	 * Elimina la copia de seguridad de un adjunto (al borrarlo).
	 *
	 * @param int $id ID.
	 */
	public static function delete_backup( $id ) {
		$b = get_post_meta( $id, self::META_BACKUP, true );
		if ( ! empty( $b['file'] ) ) {
			$p = self::backup_dir() . '/' . wp_basename( $b['file'] );
			if ( file_exists( $p ) ) {
				@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Restaura el original desde la copia de seguridad y regenera las miniaturas.
	 *
	 * @param int $id ID.
	 * @return true|WP_Error
	 */
	public static function restore( $id ) {
		$id = (int) $id;
		$b  = get_post_meta( $id, self::META_BACKUP, true );
		if ( empty( $b['file'] ) || empty( $b['orig_file'] ) ) {
			return new WP_Error( 'mjoi_no_backup', __( 'Esta imagen no tiene copia de seguridad.', 'mj-optimizador-imagenes' ) );
		}
		$src = self::backup_dir() . '/' . wp_basename( $b['file'] );
		if ( ! file_exists( $src ) ) {
			return new WP_Error( 'mjoi_backup_missing', __( 'El archivo de copia de seguridad ya no existe.', 'mj-optimizador-imagenes' ) );
		}

		$u      = wp_get_upload_dir();
		$target = wp_normalize_path( trailingslashit( $u['basedir'] ) . ltrim( (string) $b['orig_file'], '/' ) );
		wp_mkdir_p( dirname( $target ) );
		if ( ! self::in_uploads( $target ) ) {
			return new WP_Error( 'mjoi_restore_path', __( 'Ruta de restauración no válida.', 'mj-optimizador-imagenes' ) );
		}

		// Eliminar los archivos actuales (principal, original y miniaturas).
		$meta = wp_get_attachment_metadata( $id );
		$full = get_attached_file( $id, true );
		if ( $full ) {
			$full = wp_normalize_path( $full );
			$dir  = dirname( $full );
			$list = array();
			if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $sz ) {
					if ( ! empty( $sz['file'] ) ) {
						$list[] = wp_normalize_path( $dir . '/' . $sz['file'] );
					}
				}
			}
			if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
				$list[] = wp_normalize_path( $dir . '/' . $meta['original_image'] );
			}
			$list[] = $full;
			foreach ( array_unique( $list ) as $p ) {
				if ( $p !== $target && file_exists( $p ) && self::in_uploads( $p ) ) {
					@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		if ( ! @copy( $src, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mjoi_restore_copy', __( 'No se pudo copiar la copia de seguridad a su ubicación original.', 'mj-optimizador-imagenes' ) );
		}

		$mime = ! empty( $b['mime'] ) ? (string) $b['mime'] : '';
		if ( '' === $mime ) {
			$ft   = wp_check_filetype( $target );
			$mime = (string) $ft['type'];
		}
		update_attached_file( $id, self::denormalize( $target ) );
		self::update_post_file( $id, $mime, self::path_to_url( $target ) );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		self::$bypass = true;
		try {
			$new_meta = wp_generate_attachment_metadata( $id, $target );
		} finally {
			self::$bypass = false;
		}
		if ( is_array( $new_meta ) ) {
			wp_update_attachment_metadata( $id, $new_meta );
		}

		$prev = get_post_meta( $id, self::META_DATA, true );
		if ( is_array( $prev ) ) {
			self::remove_stats( $prev );
		}
		delete_post_meta( $id, self::META_DATA );
		self::set_status( $id, 'restored' );
		self::log( __( 'Restaurada desde la copia de seguridad.', 'mj-optimizador-imagenes' ), $id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * Sustituye rutas de imagen antiguas por nuevas en contenido, extractos y metadatos no serializados.
	 * Se usa la parte de ruta (/wp-content/uploads/...) para cubrir http, https y JSON escapado.
	 *
	 * @param array $map old_url => new_url.
	 * @return int Filas afectadas.
	 */
	public static function replace_urls( array $map ) {
		global $wpdb;
		$n = 0;
		foreach ( $map as $old => $new ) {
			if ( ! $old || ! $new || $old === $new ) {
				continue;
			}
			$old_p = wp_parse_url( $old, PHP_URL_PATH );
			$new_p = wp_parse_url( $new, PHP_URL_PATH );
			if ( ! $old_p || ! $new_p || $old_p === $new_p ) {
				continue;
			}
			$pairs = array(
				array( $old_p, $new_p ),
				array( str_replace( '/', '\\/', $old_p ), str_replace( '/', '\\/', $new_p ) ),
			);
			foreach ( $pairs as $pair ) {
				$like = '%' . $wpdb->esc_like( $pair[0] ) . '%';
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$n += (int) $wpdb->query(
					$wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s", $pair[0], $pair[1], $like )
				);
				$n += (int) $wpdb->query(
					$wpdb->prepare( "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE( post_excerpt, %s, %s ) WHERE post_excerpt LIKE %s", $pair[0], $pair[1], $like )
				);
				$n += (int) $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE( meta_value, %s, %s ) WHERE meta_value LIKE %s AND meta_value NOT LIKE 'a:%%' AND meta_value NOT LIKE 'O:%%' AND meta_value NOT LIKE 's:%%'",
						$pair[0],
						$pair[1],
						$like
					)
				);
				// phpcs:enable
			}
		}
		if ( $n > 0 ) {
			wp_cache_flush();
		}
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Estado, estadísticas y registro
	 * ------------------------------------------------------------------ */

	/**
	 * Marca el estado de un adjunto: done | error | skipped | restored | processing | pending.
	 */
	public static function set_status( $id, $status ) {
		update_post_meta( (int) $id, self::META_STATUS, sanitize_key( $status ) );
	}

	/**
	 * Vuelve a poner como pendientes todas las imágenes (o solo las que fallaron).
	 *
	 * @param bool $only_errors Solo errores.
	 * @return int
	 */
	public static function reset_statuses( $only_errors = false ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $only_errors ) {
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ('error','processing')", self::META_STATUS ) );
		} else {
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_STATUS ) );
		}
		// phpcs:enable
		wp_cache_flush();
		return (int) $n;
	}

	/**
	 * Estadísticas globales.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$st = get_option( self::STATS_OPTION );
		return wp_parse_args( is_array( $st ) ? $st : array(), self::empty_stats() );
	}

	/**
	 * Suma una imagen a las estadísticas (restando la pasada anterior si se reoptimiza).
	 */
	public static function add_stats( $before, $after, $prev = null ) {
		$st = self::get_stats();
		if ( is_array( $prev ) ) {
			$st['images'] = max( 0, (int) $st['images'] - 1 );
			$st['before'] = max( 0, (int) $st['before'] - (int) ( isset( $prev['before'] ) ? $prev['before'] : 0 ) );
			$st['after']  = max( 0, (int) $st['after'] - (int) ( isset( $prev['after'] ) ? $prev['after'] : 0 ) );
		}
		$st['images'] = (int) $st['images'] + 1;
		$st['before'] = (int) $st['before'] + (int) $before;
		$st['after']  = (int) $st['after'] + (int) $after;
		update_option( self::STATS_OPTION, $st );
	}

	/**
	 * Resta una imagen de las estadísticas (al restaurar).
	 */
	public static function remove_stats( array $prev ) {
		$st           = self::get_stats();
		$st['images'] = max( 0, (int) $st['images'] - 1 );
		$st['before'] = max( 0, (int) $st['before'] - (int) ( isset( $prev['before'] ) ? $prev['before'] : 0 ) );
		$st['after']  = max( 0, (int) $st['after'] - (int) ( isset( $prev['after'] ) ? $prev['after'] : 0 ) );
		update_option( self::STATS_OPTION, $st );
	}

	/**
	 * Añade una línea al registro (se guardan las últimas 40).
	 */
	public static function log( $message, $id = 0 ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				't'  => time(),
				'id' => (int) $id,
				'm'  => wp_strip_all_tags( (string) $message ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 40 ), false );
	}

	/* ---------------------------------------------------------------------
	 * Utilidades de rutas
	 * ------------------------------------------------------------------ */

	/**
	 * ¿La ruta está dentro de la carpeta de subidas? (protección contra rutas manipuladas).
	 */
	public static function in_uploads( $path ) {
		static $base = null;
		if ( null === $base ) {
			$u    = wp_get_upload_dir();
			$real = realpath( $u['basedir'] );
			$base = trailingslashit( wp_normalize_path( $real ? $real : $u['basedir'] ) );
		}
		$dir = realpath( dirname( (string) $path ) );
		if ( ! $dir ) {
			return false;
		}
		$dir = trailingslashit( wp_normalize_path( $dir ) );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 0 === strncasecmp( $dir, $base, strlen( $base ) );
		}
		return 0 === strncmp( $dir, $base, strlen( $base ) );
	}

	/**
	 * Ruta relativa a la carpeta de subidas (p. ej. 2026/09/foto.webp), independiente del separador.
	 */
	public static function to_relative( $path ) {
		$u    = wp_get_upload_dir();
		$base = trailingslashit( wp_normalize_path( $u['basedir'] ) );
		$p    = wp_normalize_path( (string) $path );
		if ( 0 === strncasecmp( $p, $base, strlen( $base ) ) ) {
			return ltrim( substr( $p, strlen( $base ) ), '/' );
		}
		return _wp_relative_upload_path( $path );
	}

	/**
	 * Devuelve la ruta con el mismo prefijo literal que usa WordPress para basedir
	 * (necesario en servidores Windows para que las funciones de WP reconozcan la ruta).
	 */
	public static function denormalize( $path ) {
		$u    = wp_get_upload_dir();
		$base = trailingslashit( wp_normalize_path( $u['basedir'] ) );
		$p    = wp_normalize_path( (string) $path );
		if ( 0 === strncasecmp( $p, $base, strlen( $base ) ) ) {
			return trailingslashit( $u['basedir'] ) . substr( $p, strlen( $base ) );
		}
		return $path;
	}

	/**
	 * Convierte una ruta dentro de uploads en su URL pública.
	 */
	public static function path_to_url( $path ) {
		$u    = wp_get_upload_dir();
		$base = trailingslashit( wp_normalize_path( $u['basedir'] ) );
		$p    = wp_normalize_path( (string) $path );
		if ( 0 === strncasecmp( $p, $base, strlen( $base ) ) ) {
			return trailingslashit( $u['baseurl'] ) . substr( $p, strlen( $base ) );
		}
		return '';
	}

	/**
	 * Actualiza mime y guid del adjunto sin disparar hooks de guardado.
	 */
	private static function update_post_file( $id, $mime, $url ) {
		global $wpdb;
		$data = array( 'post_mime_type' => (string) $mime );
		if ( $url ) {
			$data['guid'] = $url;
		}
		$wpdb->update( $wpdb->posts, $data, array( 'ID' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( (int) $id );
	}
}
