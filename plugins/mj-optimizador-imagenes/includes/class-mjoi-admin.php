<?php
/**
 * Administración: página de ajustes, estadísticas, optimización masiva, columna en Medios y avisos.
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_Admin {

	const PAGE  = 'mjoi-optimizer';
	const GROUP = 'mjoi_group';

	/**
	 * Registra hooks de administración.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( MJOI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menú: adentro del menú MJ, junto al resto de la familia.
	 */
	public static function menu() {
		add_submenu_page(
			MJ_Menu::slug(),
			__( 'MJ Optimizador de Imágenes', 'mj-optimizador-imagenes' ),
			__( 'Imágenes', 'mj-optimizador-imagenes' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * URL de la página de ajustes.
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Enlace "Ajustes" en la lista de plugins.
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Ajustes', 'mj-optimizador-imagenes' ) . '</a>' );
		return $links;
	}

	/**
	 * Registro de la opción con saneado.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			MJOI_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'MJOI_Settings', 'sanitize' ),
				'default'           => MJOI_Settings::defaults(),
			)
		);
	}

	/**
	 * Scripts y estilos solo donde hacen falta.
	 */
	public static function assets( $hook ) {
		$is_page  = ( false !== strpos( (string) $hook, self::PAGE ) );
		$is_media = ( 'upload.php' === $hook );
		if ( ! $is_page && ! $is_media ) {
			return;
		}
		wp_enqueue_style( 'mjoi-admin', MJOI_URL . 'assets/admin.css', array(), MJOI_VERSION );
		wp_enqueue_script( 'mjoi-admin', MJOI_URL . 'assets/admin.js', array(), MJOI_VERSION, true );
		wp_localize_script(
			'mjoi-admin',
			'MJOI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( MJOI_Bulk::NONCE ),
				'i18n'    => array(
					'working'      => __( 'Procesando…', 'mj-optimizador-imagenes' ),
					'done'         => __( 'Proceso terminado.', 'mj-optimizador-imagenes' ),
					'stopped'      => __( 'Proceso detenido.', 'mj-optimizador-imagenes' ),
					'error'        => __( 'Error', 'mj-optimizador-imagenes' ),
					'netError'     => __( 'Error de conexión con el servidor. Reintentando…', 'mj-optimizador-imagenes' ),
					'confirmReset' => __( '¿Marcar TODAS las imágenes como pendientes para volver a optimizarlas con los ajustes actuales?', 'mj-optimizador-imagenes' ),
					'confirmRest'  => __( '¿Restaurar la imagen original desde la copia de seguridad? Se regenerarán las miniaturas.', 'mj-optimizador-imagenes' ),
					'saved'        => __( 'ahorro', 'mj-optimizador-imagenes' ),
				),
			)
		);
	}

	/**
	 * Avisos de administración.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( 'mjoi_activated' ) ) {
			delete_transient( 'mjoi_activated' );
			$counts = MJOI_Bulk::counts();
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'MJ Optimizador de Imágenes está activo.', 'mj-optimizador-imagenes' ) . '</strong> ';
			echo esc_html__( 'Cada imagen nueva se optimizará automáticamente al subirla.', 'mj-optimizador-imagenes' ) . ' ';
			if ( $counts['pending'] > 0 ) {
				printf(
					/* translators: 1: número de imágenes, 2: enlace */
					esc_html__( 'Hay %1$s imágenes existentes pendientes: se irán optimizando en segundo plano, o las %2$s.', 'mj-optimizador-imagenes' ),
					esc_html( number_format_i18n( $counts['pending'] ) ),
					'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'optimizás ahora', 'mj-optimizador-imagenes' ) . '</a>'
				);
			}
			echo '</p></div>';
		}

		if ( get_transient( 'mjoi_migrated_notice' ) ) {
			delete_transient( 'mjoi_migrated_notice' );
			echo '<div class="notice notice-info is-dismissible"><p><strong>' . esc_html__( 'MJ Optimizador de Imágenes se quedó con todo lo del optimizador anterior.', 'mj-optimizador-imagenes' ) . '</strong> ';
			echo esc_html__( 'Tus ajustes, las estadísticas y las imágenes que ya estaban optimizadas siguen igual: no se vuelve a procesar nada. Ya podés borrar el plugin viejo "WC Image Optimizer Ilimitado" desde Plugins.', 'mj-optimizador-imagenes' );
			echo '</p></div>';
		}

		if ( '' === MJOI_Optimizer::library() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MJ Optimizador de Imágenes: el servidor no tiene ni Imagick ni GD. Pedile a tu hosting que active una de las dos extensiones de PHP.', 'mj-optimizador-imagenes' ) . '</p></div>';
		}

		if ( isset( $_GET['mjoi_bulk_done'] ) && isset( $_GET['mjoi_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['mjoi_nonce'] ), 'mjoi_bulk_done' ) ) {
			$n = absint( $_GET['mjoi_bulk_done'] );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: número */ _n( '%s imagen optimizada.', '%s imágenes optimizadas.', $n, 'mj-optimizador-imagenes' ), number_format_i18n( $n ) ) ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Biblioteca de medios
	 * ------------------------------------------------------------------ */

	/**
	 * Columna "Optimización".
	 */
	public static function media_column( $columns ) {
		$columns['mjoi'] = __( 'Optimización', 'mj-optimizador-imagenes' );
		return $columns;
	}

	/**
	 * Contenido de la columna.
	 */
	public static function media_column_content( $column, $post_id ) {
		if ( 'mjoi' !== $column ) {
			return;
		}
		echo wp_kses_post( self::column_html( $post_id ) );
	}

	/**
	 * HTML de la celda de una imagen (también usado por AJAX).
	 *
	 * @param int $id ID.
	 * @return string
	 */
	public static function column_html( $id ) {
		$id   = (int) $id;
		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, MJOI_Processor::allowed_mimes(), true ) ) {
			return '<span class="mjoi-muted">&mdash;</span>';
		}
		$status = (string) get_post_meta( $id, MJOI_Processor::META_STATUS, true );
		$data   = get_post_meta( $id, MJOI_Processor::META_DATA, true );
		$backup = get_post_meta( $id, MJOI_Processor::META_BACKUP, true );
		$can    = current_user_can( 'edit_post', $id );

		$btn = function ( $action, $label ) {
			return '<button type="button" class="button-link mjoi-btn" data-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
		};

		$out = '<div class="mjoi-cell" data-id="' . esc_attr( $id ) . '">';
		if ( 'done' === $status && is_array( $data ) ) {
			$out .= '<span class="mjoi-badge mjoi-ok">' . esc_html( sprintf( /* translators: porcentaje */ __( 'Ahorro %s%%', 'mj-optimizador-imagenes' ), $data['percent'] ) ) . '</span> ';
			$out .= '<span class="mjoi-muted">' . esc_html( size_format( (int) $data['before'] ) . ' → ' . size_format( (int) $data['after'] ) ) . '</span>';
			if ( ! empty( $data['webp'] ) ) {
				$out .= ' <span class="mjoi-badge mjoi-webp">WebP</span>';
			}
			if ( ! empty( $data['errors'] ) ) {
				$out .= '<div class="mjoi-muted mjoi-small">' . esc_html( implode( ' | ', (array) $data['errors'] ) ) . '</div>';
			}
			if ( $can ) {
				$out .= '<div class="mjoi-actions">' . $btn( 'optimize', __( 'Reoptimizar', 'mj-optimizador-imagenes' ) );
				if ( ! empty( $backup['file'] ) ) {
					$out .= ' · ' . $btn( 'restore', __( 'Restaurar original', 'mj-optimizador-imagenes' ) );
				}
				$out .= '</div>';
			}
		} elseif ( 'error' === $status ) {
			$out .= '<span class="mjoi-badge mjoi-err">' . esc_html__( 'Error', 'mj-optimizador-imagenes' ) . '</span>';
			if ( is_array( $data ) && ! empty( $data['errors'] ) ) {
				$out .= '<div class="mjoi-muted mjoi-small">' . esc_html( implode( ' | ', (array) $data['errors'] ) ) . '</div>';
			}
			if ( $can ) {
				$out .= '<div class="mjoi-actions">' . $btn( 'optimize', __( 'Reintentar', 'mj-optimizador-imagenes' ) ) . '</div>';
			}
		} elseif ( 'processing' === $status ) {
			$out .= '<span class="mjoi-badge">' . esc_html__( 'Procesando…', 'mj-optimizador-imagenes' ) . '</span>';
		} elseif ( 'restored' === $status ) {
			$out .= '<span class="mjoi-badge">' . esc_html__( 'Original restaurado', 'mj-optimizador-imagenes' ) . '</span>';
			if ( $can ) {
				$out .= '<div class="mjoi-actions">' . $btn( 'optimize', __( 'Optimizar', 'mj-optimizador-imagenes' ) ) . '</div>';
			}
		} elseif ( 'skipped' === $status ) {
			$out .= '<span class="mjoi-badge">' . esc_html__( 'Omitida', 'mj-optimizador-imagenes' ) . '</span>';
		} else {
			$out .= '<span class="mjoi-badge mjoi-pending">' . esc_html__( 'Pendiente', 'mj-optimizador-imagenes' ) . '</span>';
			if ( $can ) {
				$out .= '<div class="mjoi-actions">' . $btn( 'optimize', __( 'Optimizar ahora', 'mj-optimizador-imagenes' ) ) . '</div>';
			}
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Acción masiva en la biblioteca.
	 */
	public static function bulk_actions( $actions ) {
		$actions['mjoi_optimize'] = __( 'Optimizar imágenes (MJOI)', 'mj-optimizador-imagenes' );
		return $actions;
	}

	/**
	 * Ejecuta la acción masiva (máximo 50 por petición para no agotar el tiempo).
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'mjoi_optimize' !== $action || ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$n = 0;
		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 50 ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$row = MJOI_Bulk::process_one( $id );
			if ( 'done' === $row['status'] ) {
				$n++;
			}
		}
		return add_query_arg(
			array(
				'mjoi_bulk_done' => $n,
				'mjoi_nonce'     => wp_create_nonce( 'mjoi_bulk_done' ),
			),
			$redirect
		);
	}

	/* ---------------------------------------------------------------------
	 * Página de ajustes
	 * ------------------------------------------------------------------ */

	/**
	 * Renderiza la página.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = MJOI_Settings::get();
		$stats  = MJOI_Bulk::stats_payload();
		$counts = MJOI_Bulk::counts();
		$log    = get_option( MJOI_Processor::LOG_OPTION, array() );
		$log    = is_array( $log ) ? $log : array();
		$webp   = MJOI_Optimizer::supports_webp();
		?>
		<div class="wrap mjoi-wrap">
			<h1><?php esc_html_e( 'Optimizador de imágenes ilimitado', 'mj-optimizador-imagenes' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Cada imagen que subas a la tienda se optimiza automáticamente en tu propio servidor: sin límites, sin créditos y sin enviar nada fuera.', 'mj-optimizador-imagenes' ); ?></p>

			<?php if ( ! $s['enabled'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'La optimización automática está desactivada. Actívala en los ajustes de abajo.', 'mj-optimizador-imagenes' ); ?></p></div>
			<?php endif; ?>

			<div class="mjoi-cards">
				<div class="mjoi-card"><span class="mjoi-card-label"><?php esc_html_e( 'Imágenes optimizadas', 'mj-optimizador-imagenes' ); ?></span><span class="mjoi-card-value" data-mjoi-stat="images_human"><?php echo esc_html( $stats['images_human'] ); ?></span></div>
				<div class="mjoi-card"><span class="mjoi-card-label"><?php esc_html_e( 'Espacio antes', 'mj-optimizador-imagenes' ); ?></span><span class="mjoi-card-value" data-mjoi-stat="before_human"><?php echo esc_html( $stats['before_human'] ); ?></span></div>
				<div class="mjoi-card"><span class="mjoi-card-label"><?php esc_html_e( 'Espacio ahora', 'mj-optimizador-imagenes' ); ?></span><span class="mjoi-card-value" data-mjoi-stat="after_human"><?php echo esc_html( $stats['after_human'] ); ?></span></div>
				<div class="mjoi-card mjoi-card-accent"><span class="mjoi-card-label"><?php esc_html_e( 'Espacio ahorrado', 'mj-optimizador-imagenes' ); ?></span><span class="mjoi-card-value"><span data-mjoi-stat="saved_human"><?php echo esc_html( $stats['saved_human'] ); ?></span> <small>(<span data-mjoi-stat="percent"><?php echo esc_html( $stats['percent'] ); ?></span>%)</small></span></div>
			</div>

			<h2><?php esc_html_e( 'Optimizar la biblioteca existente', 'mj-optimizador-imagenes' ); ?></h2>
			<div class="mjoi-box">
				<p>
					<?php esc_html_e( 'Total de imágenes:', 'mj-optimizador-imagenes' ); ?> <strong data-mjoi-count="total"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong> ·
					<?php esc_html_e( 'Optimizadas:', 'mj-optimizador-imagenes' ); ?> <strong data-mjoi-count="done"><?php echo esc_html( number_format_i18n( $counts['done'] ) ); ?></strong> ·
					<?php esc_html_e( 'Pendientes:', 'mj-optimizador-imagenes' ); ?> <strong data-mjoi-count="pending"><?php echo esc_html( number_format_i18n( $counts['pending'] ) ); ?></strong> ·
					<?php esc_html_e( 'Con error:', 'mj-optimizador-imagenes' ); ?> <strong data-mjoi-count="error"><?php echo esc_html( number_format_i18n( $counts['error'] ) ); ?></strong> ·
					<?php esc_html_e( 'Omitidas:', 'mj-optimizador-imagenes' ); ?> <strong data-mjoi-count="skipped"><?php echo esc_html( number_format_i18n( $counts['skipped'] ) ); ?></strong>
				</p>
				<p>
					<button type="button" class="button button-primary" id="mjoi-bulk-start"><?php esc_html_e( 'Optimizar pendientes ahora', 'mj-optimizador-imagenes' ); ?></button>
					<button type="button" class="button" id="mjoi-bulk-errors"><?php esc_html_e( 'Reintentar las que fallaron', 'mj-optimizador-imagenes' ); ?></button>
					<button type="button" class="button" id="mjoi-bulk-stop" disabled><?php esc_html_e( 'Detener', 'mj-optimizador-imagenes' ); ?></button>
					<button type="button" class="button-link mjoi-danger" id="mjoi-reset-all"><?php esc_html_e( 'Volver a optimizar todas (marcar como pendientes)', 'mj-optimizador-imagenes' ); ?></button>
				</p>
				<div class="mjoi-progress" id="mjoi-progress" hidden><div class="mjoi-progress-bar" id="mjoi-progress-bar" style="width:0%"></div><span class="mjoi-progress-text" id="mjoi-progress-text">0%</span></div>
				<div class="mjoi-log" id="mjoi-log" hidden></div>
				<p class="description">
					<?php
					if ( $s['background'] && $s['enabled'] ) {
						$next = wp_next_scheduled( MJOI_Bulk::CRON_HOOK );
						printf(
							/* translators: fecha */
							esc_html__( 'Las pendientes también se procesan solas en segundo plano cada 5 minutos (próxima pasada: %s). Mantén esta pestaña abierta para ir más rápido.', 'mj-optimizador-imagenes' ),
							esc_html( $next ? wp_date( 'H:i', $next ) : '—' )
						);
					} else {
						esc_html_e( 'El proceso en segundo plano está desactivado: solo se optimizará lo que lances desde aquí o al subir imágenes nuevas.', 'mj-optimizador-imagenes' );
					}
					?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Ajustes', 'mj-optimizador-imagenes' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Optimización automática', 'mj-optimizador-imagenes' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Optimizar cada imagen nada más subirla (productos, galerías, entradas, importaciones CSV y API).', 'mj-optimizador-imagenes' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="mjoi-quality"><?php esc_html_e( 'Calidad JPEG / WebP', 'mj-optimizador-imagenes' ); ?></label></th>
						<td>
							<input type="range" id="mjoi-quality" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[quality]" min="50" max="100" step="1" value="<?php echo esc_attr( $s['quality'] ); ?>" oninput="document.getElementById('mjoi-quality-out').textContent=this.value"> <output id="mjoi-quality-out"><?php echo esc_html( $s['quality'] ); ?></output>
							<p class="description"><?php esc_html_e( '85 es el punto recomendado: a simple vista es idéntico al original y ahorra mucho espacio. Subila a 90-92 si vendés fotografía o arte; nunca se sube la calidad por encima de la que ya tenía el archivo.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Convertir a WebP', 'mj-optimizador-imagenes' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[webp]" value="1" <?php checked( $s['webp'] ); ?> <?php disabled( ! $webp ); ?>> <?php esc_html_e( 'Guardar las imágenes nuevas (y sus miniaturas) en formato WebP: entre un 25 y un 50 % más pequeñas con la misma calidad visual.', 'mj-optimizador-imagenes' ); ?></label>
							<?php if ( ! $webp ) : ?>
								<p class="description mjoi-danger"><?php esc_html_e( 'Tu servidor no puede generar WebP. Pide a tu hosting que active el soporte WebP en GD o Imagick.', 'mj-optimizador-imagenes' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Todos los navegadores actuales muestran WebP. Si envías catálogos a servicios externos que exijan JPEG, desactiva esta opción.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjoi-png-mode"><?php esc_html_e( 'Imágenes PNG', 'mj-optimizador-imagenes' ); ?></label></th>
						<td>
							<select id="mjoi-png-mode" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[png_mode]">
								<option value="lossless" <?php selected( $s['png_mode'], 'lossless' ); ?>><?php esc_html_e( 'WebP sin pérdida (recomendado: calidad idéntica, menos peso)', 'mj-optimizador-imagenes' ); ?></option>
								<option value="lossy" <?php selected( $s['png_mode'], 'lossy' ); ?>><?php esc_html_e( 'WebP con pérdida (máximo ahorro)', 'mj-optimizador-imagenes' ); ?></option>
								<option value="keep" <?php selected( $s['png_mode'], 'keep' ); ?>><?php esc_html_e( 'Mantener PNG (solo recomprimir sin pérdida)', 'mj-optimizador-imagenes' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Los PNG (logotipos, fondos transparentes) nunca pierden calidad salvo que elijas el modo con pérdida.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjoi-max"><?php esc_html_e( 'Tamaño máximo (px)', 'mj-optimizador-imagenes' ); ?></label></th>
						<td>
							<input type="number" id="mjoi-max" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[max_dimension]" min="0" max="20000" step="10" value="<?php echo esc_attr( $s['max_dimension'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Las fotos más grandes que este lado máximo se reducen (2560 px cubre pantallas 4K y zoom de producto). Además se elimina el original enorme que WordPress guarda aparte. 0 = no redimensionar.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Metadatos', 'mj-optimizador-imagenes' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[strip_meta]" value="1" <?php checked( $s['strip_meta'] ); ?>> <?php esc_html_e( 'Eliminar datos EXIF (cámara, GPS, fecha…). La orientación se aplica antes de borrarla.', 'mj-optimizador-imagenes' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[keep_icc]" value="1" <?php checked( $s['keep_icc'] ); ?>> <?php esc_html_e( 'Conservar el perfil de color ICC (evita cambios de tono en fotos de iPhone o cámaras profesionales; solo con Imagick).', 'mj-optimizador-imagenes' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjoi-min-saving"><?php esc_html_e( 'Ahorro mínimo (%)', 'mj-optimizador-imagenes' ); ?></label></th>
						<td>
							<input type="number" id="mjoi-min-saving" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[min_saving]" min="0" max="50" value="<?php echo esc_attr( $s['min_saving'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Si recomprimir en el mismo formato no ahorra al menos este porcentaje, el archivo se deja intacto (evita recomprimir sin ganancia).', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Biblioteca existente', 'mj-optimizador-imagenes' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[background]" value="1" <?php checked( $s['background'] ); ?>> <?php esc_html_e( 'Optimizar en segundo plano las imágenes que ya estaban subidas (poco a poco, sin frenar la tienda).', 'mj-optimizador-imagenes' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[webp_existing]" value="1" <?php checked( $s['webp_existing'] ); ?> <?php disabled( ! $webp ); ?>> <?php esc_html_e( 'Convertir también las existentes a WebP (cambia la extensión de los archivos).', 'mj-optimizador-imagenes' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[replace_urls]" value="1" <?php checked( $s['replace_urls'] ); ?>> <?php esc_html_e( 'Al convertir existentes, actualizar las URLs antiguas en productos, entradas y páginas.', 'mj-optimizador-imagenes' ); ?></label>
							<p class="description"><?php esc_html_e( 'Sin conversión, las existentes se optimizan en su mismo formato y con la misma URL (100 % seguro). La conversión a WebP de las existentes ahorra más, pero si algún constructor visual guarda URLs en formatos raros podrían quedar enlaces antiguos: activa las copias de seguridad antes.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Copias de seguridad', 'mj-optimizador-imagenes' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[backup]" value="1" <?php checked( $s['backup'] ); ?>> <?php esc_html_e( 'Guardar el original intacto en wp-content/uploads/mjoi-backups para poder restaurarlo desde la biblioteca.', 'mj-optimizador-imagenes' ); ?></label>
							<p class="description"><?php esc_html_e( 'Ocupa espacio extra (el original completo). Desactivado por defecto para ahorrar al máximo.', 'mj-optimizador-imagenes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rendimiento', 'mj-optimizador-imagenes' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MJOI_Settings::OPTION ); ?>[defer_upload]" value="1" <?php checked( $s['defer_upload'] ); ?>> <?php esc_html_e( 'Terminar la optimización en segundo plano tras la subida (subidas más rápidas; la imagen queda optimizada segundos después).', 'mj-optimizador-imagenes' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar ajustes', 'mj-optimizador-imagenes' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Estado del servidor', 'mj-optimizador-imagenes' ); ?></h2>
			<table class="widefat striped mjoi-status">
				<tbody>
					<?php foreach ( self::server_status() as $row ) : ?>
						<tr>
							<td class="mjoi-status-label"><?php echo esc_html( $row[0] ); ?></td>
							<td><span class="mjoi-dot mjoi-dot-<?php echo esc_attr( $row[2] ); ?>"></span> <?php echo esc_html( $row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Registro reciente', 'mj-optimizador-imagenes' ); ?></h2>
			<div class="mjoi-box mjoi-log-static">
				<?php if ( empty( $log ) ) : ?>
					<p class="mjoi-muted"><?php esc_html_e( 'Todavía no hay actividad.', 'mj-optimizador-imagenes' ); ?></p>
				<?php else : ?>
					<ul>
						<?php foreach ( array_slice( $log, 0, 30 ) as $entry ) : ?>
							<li>
								<span class="mjoi-muted"><?php echo esc_html( wp_date( 'd/m H:i', (int) $entry['t'] ) ); ?></span>
								<?php if ( ! empty( $entry['id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $entry['id'] ) ); ?>">#<?php echo esc_html( $entry['id'] ); ?></a>
								<?php endif; ?>
								<?php echo esc_html( $entry['m'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Filas de estado del servidor: [etiqueta, valor, ok|warn|bad].
	 *
	 * @return array
	 */
	private static function server_status() {
		$lib  = MJOI_Optimizer::library();
		$rows = array();

		$rows[] = array(
			__( 'Motor de imágenes', 'mj-optimizador-imagenes' ),
			MJOI_Optimizer::library_label() . ( 'gd' === $lib ? ' — ' . __( 'funciona bien; Imagick daría mejor color y velocidad', 'mj-optimizador-imagenes' ) : '' ),
			'' === $lib ? 'bad' : ( 'gd' === $lib ? 'warn' : 'ok' ),
		);

		$webp   = MJOI_Optimizer::supports_webp();
		$rows[] = array(
			__( 'Soporte WebP', 'mj-optimizador-imagenes' ),
			$webp ? __( 'Sí', 'mj-optimizador-imagenes' ) . ( MJOI_Optimizer::supports_webp_lossless() ? ' (' . __( 'con modo sin pérdida', 'mj-optimizador-imagenes' ) . ')' : ' (' . __( 'sin modo sin pérdida: los PNG se mantendrán como PNG', 'mj-optimizador-imagenes' ) . ')' ) : __( 'No', 'mj-optimizador-imagenes' ),
			$webp ? 'ok' : 'bad',
		);

		$editor_webp = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$rows[]      = array(
			__( 'Miniaturas de WordPress en WebP', 'mj-optimizador-imagenes' ),
			$editor_webp ? __( 'Sí', 'mj-optimizador-imagenes' ) : __( 'No (las miniaturas se generarán en JPEG/PNG)', 'mj-optimizador-imagenes' ),
			$editor_webp ? 'ok' : 'warn',
		);

		$exif   = function_exists( 'exif_read_data' );
		$rows[] = array(
			__( 'Lectura de orientación EXIF', 'mj-optimizador-imagenes' ),
			$exif ? __( 'Extensión exif de PHP', 'mj-optimizador-imagenes' ) : __( 'Lector interno del plugin (sin extensión exif)', 'mj-optimizador-imagenes' ),
			'ok',
		);

		$mem    = (string) ini_get( 'memory_limit' );
		$memb   = wp_convert_hr_to_bytes( $mem );
		$rows[] = array(
			__( 'Memoria PHP', 'mj-optimizador-imagenes' ),
			$mem . ( 'gd' === $lib && $memb > 0 && $memb < 256 * MB_IN_BYTES ? ' — ' . __( 'con GD se recomiendan 256M para fotos grandes', 'mj-optimizador-imagenes' ) : '' ),
			( $memb > 0 && $memb < 128 * MB_IN_BYTES ) ? 'warn' : 'ok',
		);

		$rows[] = array(
			__( 'Tiempo máximo de ejecución', 'mj-optimizador-imagenes' ),
			ini_get( 'max_execution_time' ) . ' s',
			'ok',
		);

		$u        = wp_get_upload_dir();
		$writable = wp_is_writable( $u['basedir'] );
		$rows[]   = array(
			__( 'Carpeta de subidas escribible', 'mj-optimizador-imagenes' ),
			$writable ? __( 'Sí', 'mj-optimizador-imagenes' ) : __( 'No: revisá los permisos de wp-content/uploads', 'mj-optimizador-imagenes' ),
			$writable ? 'ok' : 'bad',
		);

		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$rows[]   = array(
			__( 'Proceso en segundo plano (WP-Cron)', 'mj-optimizador-imagenes' ),
			$cron_off ? __( 'WP-Cron desactivado en wp-config.php: asegúrate de tener un cron del sistema, o usa el botón de optimización manual', 'mj-optimizador-imagenes' ) : __( 'Disponible', 'mj-optimizador-imagenes' ),
			$cron_off ? 'warn' : 'ok',
		);

		$rows[] = array(
			__( 'Action Scheduler (WooCommerce)', 'mj-optimizador-imagenes' ),
			function_exists( 'as_enqueue_async_action' ) ? __( 'Disponible para el procesado diferido', 'mj-optimizador-imagenes' ) : __( 'No disponible (se usará WP-Cron)', 'mj-optimizador-imagenes' ),
			'ok',
		);

		return $rows;
	}
}
