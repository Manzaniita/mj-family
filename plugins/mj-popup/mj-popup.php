<?php
/**
 * Plugin Name: MJ Popup
 * Description: Popup de avisos con imagen emergente para visitantes. Responsive, no invasor, con control de frecuencia y compatible con cualquier tema de WordPress.
 * Version: 1.0.0
 * Author: MJ Importaciones
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mj-popup
 */

defined('ABSPATH') || exit;

define('MJPOPUP_VERSION', '1.0.0');
define('MJPOPUP_DIR', plugin_dir_path(__FILE__));
define('MJPOPUP_URL', plugin_dir_url(__FILE__));
define('MJPOPUP_OPCION', 'mj_popup_opciones');

/*
 * Menú único de la familia MJ en wp-admin. La clase viaja adentro del plugin
 * para funcionar de forma autónoma con cualquier tema o configuración.
 */
require_once __DIR__ . '/compartido/class-mj-menu.php';
MJ_Menu::init();

class MJ_Popup {

	const SLUG  = 'mj-popup';
	const NONCE = 'mj_popup_guardar';

	/**
	 * Capacidad necesaria para editar el popup y sus opciones.
	 *
	 * @return string
	 */
	public static function capacidad_ajustes() {
		return (string) apply_filters('mj_popup_capacidad_ajustes', 'manage_options');
	}

	/**
	 * Valores por defecto de las opciones del popup.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'activo'                => 0,
			'imagen_id'             => 0,
			'imagen_url'            => '',
			'imagen_alt'            => 'Aviso importante',
			'enlace_url'            => '',
			'enlace_nueva_pestana'  => 0,
			'mostrar_boton_cerrar'  => 1,
			'texto_boton_cerrar'    => 'Entendido, continuar',
			'frecuencia'            => 'session',
			'retardo_ms'            => 400,
			'aviso_id'              => '1',
		];
	}

	/**
	 * Obtiene las opciones guardadas del popup fusionadas con los valores por defecto.
	 *
	 * @return array
	 */
	public static function opciones() {
		$guardado = get_option(MJPOPUP_OPCION, []);
		if (!is_array($guardado)) {
			$guardado = [];
		}
		return wp_parse_args($guardado, self::defaults());
	}

	/**
	 * Inicializa los hooks de WordPress.
	 */
	public static function init() {
		add_action('admin_menu', [__CLASS__, 'registrar_menu']);
		add_action('admin_init', [__CLASS__, 'procesar_guardado']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'scripts_admin']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'scripts_front']);
		add_action('wp_footer', [__CLASS__, 'render_popup_front']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'enlaces_plugin']);
	}

	/**
	 * Enlace directo a los ajustes desde la lista de plugins.
	 *
	 * @param array $links
	 * @return array
	 */
	public static function enlaces_plugin($links) {
		$url = admin_url('admin.php?page=' . self::SLUG);
		$ajustes_link = '<a href="' . esc_url($url) . '">' . esc_html__('Ajustes', 'mj-popup') . '</a>';
		array_unshift($links, $ajustes_link);
		return $links;
	}

	/**
	 * Registra el submenú de MJ Popup bajo el menú unificado MJ.
	 */
	public static function registrar_menu() {
		add_submenu_page(
			MJ_Menu::slug(),
			'MJ Popup',
			'Popup de Aviso',
			self::capacidad_ajustes(),
			self::SLUG,
			[__CLASS__, 'render_admin_page']
		);
	}

	/**
	 * Encola scripts y estilos en la pantalla de administración del plugin.
	 *
	 * @param string $hook
	 */
	public static function scripts_admin($hook) {
		// Solo encolar en la pantalla del plugin
		if (strpos($hook, self::SLUG) === false) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'mj-popup-admin-css',
			MJPOPUP_URL . 'assets/css/mj-popup-admin.css',
			[],
			MJPOPUP_VERSION
		);
		wp_enqueue_script(
			'mj-popup-admin-js',
			MJPOPUP_URL . 'assets/js/mj-popup-admin.js',
			['jquery'],
			MJPOPUP_VERSION,
			true
		);
	}

	/**
	 * Procesa el formulario de ajustes y el reinicio de visualizaciones.
	 */
	public static function procesar_guardado() {
		if (!isset($_POST['mj_popup_accion'])) {
			return;
		}

		if (!check_admin_referer(self::NONCE, 'mj_popup_nonce')) {
			wp_die(esc_html__('Error de seguridad al guardar. Por favor reintentá.', 'mj-popup'));
		}

		if (!current_user_can(self::capacidad_ajustes())) {
			wp_die(esc_html__('No tenés permisos suficientes para modificar este ajuste.', 'mj-popup'));
		}

		$actuales = self::opciones();
		$accion   = sanitize_key(wp_unslash($_POST['mj_popup_accion']));

		if ('reiniciar' === $accion) {
			$actuales['aviso_id'] = (string) time();
			update_option(MJPOPUP_OPCION, $actuales);
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'reiniciado' => 1], admin_url('admin.php')));
			exit;
		}

		$frecuencias_validas = ['session', '1_day', '3_days', '7_days', '30_days', 'once', 'always'];
		$frecuencia          = isset($_POST['frecuencia']) ? sanitize_key(wp_unslash($_POST['frecuencia'])) : 'session';
		if (!in_array($frecuencia, $frecuencias_validas, true)) {
			$frecuencia = 'session';
		}

		$nueva_imagen_url = !empty($_POST['imagen_url']) ? esc_url_raw(wp_unslash($_POST['imagen_url'])) : '';
		$aviso_id         = !empty($actuales['aviso_id']) ? $actuales['aviso_id'] : '1';

		// Si se cambió la imagen por otra distinta, cambiamos el aviso_id para que vuelva a mostrarse a todos
		if ($nueva_imagen_url !== $actuales['imagen_url']) {
			$aviso_id = (string) time();
		}

		$retardo_ms = isset($_POST['retardo_ms']) ? absint($_POST['retardo_ms']) : 400;
		$retardo_ms = max(0, min(10000, $retardo_ms));

		$nuevas = [
			'activo'                => !empty($_POST['activo']) ? 1 : 0,
			'imagen_id'             => !empty($_POST['imagen_id']) ? absint($_POST['imagen_id']) : 0,
			'imagen_url'            => $nueva_imagen_url,
			'imagen_alt'            => !empty($_POST['imagen_alt']) ? sanitize_text_field(wp_unslash($_POST['imagen_alt'])) : 'Aviso importante',
			'enlace_url'            => !empty($_POST['enlace_url']) ? esc_url_raw(wp_unslash($_POST['enlace_url'])) : '',
			'enlace_nueva_pestana'  => !empty($_POST['enlace_nueva_pestana']) ? 1 : 0,
			'mostrar_boton_cerrar'  => !empty($_POST['mostrar_boton_cerrar']) ? 1 : 0,
			'texto_boton_cerrar'    => !empty($_POST['texto_boton_cerrar']) ? sanitize_text_field(wp_unslash($_POST['texto_boton_cerrar'])) : 'Entendido, continuar',
			'frecuencia'            => $frecuencia,
			'retardo_ms'            => $retardo_ms,
			'aviso_id'              => $aviso_id,
		];

		update_option(MJPOPUP_OPCION, $nuevas);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'guardado' => 1], admin_url('admin.php')));
		exit;
	}

	/**
	 * Verifica si la solicitud actual corresponde a una vista previa del administrador.
	 *
	 * @return bool
	 */
	public static function es_modo_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo lectura para previsualizar aviso; protegido por capacidad de usuario logueado.
		return is_user_logged_in() && current_user_can(self::capacidad_ajustes()) && !empty($_GET['mj_popup_preview']);
	}

	/**
	 * Encola scripts y estilos en el frontend si el popup está activo y tiene imagen.
	 */
	public static function scripts_front() {
		if (is_admin()) {
			return;
		}

		$opciones   = self::opciones();
		$es_preview = self::es_modo_preview();

		if (!$es_preview && (empty($opciones['activo']) || empty($opciones['imagen_url']))) {
			return;
		}

		wp_enqueue_style(
			'mj-popup-front-css',
			MJPOPUP_URL . 'assets/css/mj-popup.css',
			[],
			MJPOPUP_VERSION
		);

		wp_enqueue_script(
			'mj-popup-front-js',
			MJPOPUP_URL . 'assets/js/mj-popup.js',
			[],
			MJPOPUP_VERSION,
			true
		);

		$config = [
			'activo'     => $es_preview ? true : !empty($opciones['activo']),
			'aviso_id'   => !empty($opciones['aviso_id']) ? (string) $opciones['aviso_id'] : '1',
			'frecuencia' => $opciones['frecuencia'],
			'retardo_ms' => (int) $opciones['retardo_ms'],
			'es_preview' => $es_preview,
		];

		wp_localize_script('mj-popup-front-js', 'mjPopupConfig', $config);
	}

	/**
	 * Renderiza el modal del popup en el footer del frontend.
	 */
	public static function render_popup_front() {
		if (is_admin()) {
			return;
		}

		$opciones   = self::opciones();
		$es_preview = self::es_modo_preview();

		if (!$es_preview && (empty($opciones['activo']) || empty($opciones['imagen_url']))) {
			return;
		}

		$imagen_url = $opciones['imagen_url'];
		$imagen_alt = !empty($opciones['imagen_alt']) ? $opciones['imagen_alt'] : 'Aviso';
		$enlace_url = !empty($opciones['enlace_url']) ? $opciones['enlace_url'] : '';
		$target     = !empty($opciones['enlace_nueva_pestana']) ? '_blank' : '_self';
		$rel        = !empty($opciones['enlace_nueva_pestana']) ? 'noopener noreferrer' : '';
		?>
		<div id="mj-popup-overlay" class="mj-popup-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php echo esc_attr($imagen_alt); ?>">
			<div class="mj-popup-dialog" id="mj-popup-dialog">
				<div class="mj-popup-card">
					<button type="button" class="mj-popup-close-btn" aria-label="<?php esc_attr_e('Cerrar aviso', 'mj-popup'); ?>" title="<?php esc_attr_e('Cerrar aviso', 'mj-popup'); ?>">&times;</button>
					<div class="mj-popup-media">
						<?php if (!empty($enlace_url)) : ?>
							<a href="<?php echo esc_url($enlace_url); ?>" target="<?php echo esc_attr($target); ?>" <?php echo !empty($rel) ? 'rel="' . esc_attr($rel) . '"' : ''; ?>>
								<img src="<?php echo esc_url($imagen_url); ?>" class="mj-popup-img" alt="<?php echo esc_attr($imagen_alt); ?>" />
							</a>
						<?php else : ?>
							<img src="<?php echo esc_url($imagen_url); ?>" class="mj-popup-img" alt="<?php echo esc_attr($imagen_alt); ?>" />
						<?php endif; ?>
					</div>
					<?php if (!empty($opciones['mostrar_boton_cerrar'])) : ?>
						<div class="mj-popup-footer">
							<button type="button" class="mj-popup-action-btn">
								<?php echo esc_html($opciones['texto_boton_cerrar']); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Pantalla de configuración en wp-admin.
	 */
	public static function render_admin_page() {
		if (!current_user_can(self::capacidad_ajustes())) {
			wp_die(esc_html__('No tenés permisos para ver esta página.', 'mj-popup'));
		}

		$opciones   = self::opciones();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo lectura de flag de redirección tras guardar.
		$guardado   = !empty($_GET['guardado']);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo lectura de flag de redirección tras reiniciar.
		$reiniciado = !empty($_GET['reiniciado']);
		$preview_url = add_query_arg('mj_popup_preview', '1', home_url('/'));
		?>
		<div class="wrap mj-popup-admin-wrap">
			<div class="mj-popup-header">
				<h1>
					MJ Popup
					<span class="mj-popup-badge">v<?php echo esc_html(MJPOPUP_VERSION); ?></span>
				</h1>
				<p class="mj-popup-subtitle">
					<?php esc_html_e('Configurá una imagen emergente para mostrar avisos importantes a tus visitantes (por ejemplo, vacaciones, feriados o cerrado temporalmente). Funciona con cualquier tema.', 'mj-popup'); ?>
				</p>
			</div>

			<?php if ($guardado) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Ajustes guardados correctamente.', 'mj-popup'); ?></p>
				</div>
			<?php endif; ?>

			<?php if ($reiniciado) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Visualizaciones reiniciadas. El aviso volverá a saltar a todos los visitantes que ya lo hayan visto.', 'mj-popup'); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
				<?php wp_nonce_field(self::NONCE, 'mj_popup_nonce'); ?>
				<input type="hidden" name="mj_popup_accion" value="guardar" />

				<!-- Tarjeta 1: Estado e Imagen -->
				<div class="mj-popup-card">
					<h2 class="mj-popup-card-title">
						<span class="dashicons dashicons-format-image"></span>
						<?php esc_html_e('Aviso y contenido visual', 'mj-popup'); ?>
					</h2>

					<div class="mj-popup-field-row">
						<label class="mj-popup-switch-label">
							<input type="checkbox" name="activo" value="1" <?php checked($opciones['activo'], 1); ?> />
							<?php esc_html_e('Activar aviso en el sitio', 'mj-popup'); ?>
						</label>
						<p class="mj-popup-help">
							<?php esc_html_e('Cuando esté desactivado, el aviso no se mostrará a los visitantes (podés activarlo y desactivarlo cuando quieras sin perder la imagen cargada).', 'mj-popup'); ?>
						</p>
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label"><?php esc_html_e('Imagen del aviso', 'mj-popup'); ?></label>
						<div class="mj-popup-media-box">
							<div class="mj-popup-preview-container" id="mj-popup-preview-box">
								<?php if (!empty($opciones['imagen_url'])) : ?>
									<img src="<?php echo esc_url($opciones['imagen_url']); ?>" class="mj-popup-preview-img" alt="<?php esc_attr_e('Vista previa del aviso', 'mj-popup'); ?>" />
								<?php else : ?>
									<span class="mj-popup-no-image-text"><?php esc_html_e('No hay imagen seleccionada', 'mj-popup'); ?></span>
								<?php endif; ?>
							</div>
							<div class="mj-popup-media-controls">
								<input type="hidden" id="mj-popup-img-id" name="imagen_id" value="<?php echo esc_attr($opciones['imagen_id']); ?>" />
								<input type="hidden" id="mj-popup-img-url" name="imagen_url" value="<?php echo esc_url($opciones['imagen_url']); ?>" />

								<button type="button" id="mj-popup-select-img" class="button button-secondary">
									<?php echo !empty($opciones['imagen_url']) ? esc_html__('Cambiar imagen', 'mj-popup') : esc_html__('Seleccionar imagen', 'mj-popup'); ?>
								</button>
								<button type="button" id="mj-popup-remove-img" class="button button-link-delete" style="<?php echo empty($opciones['imagen_url']) ? 'display:none;' : ''; ?>">
									<?php esc_html_e('Quitar imagen', 'mj-popup'); ?>
								</button>
								<p class="mj-popup-help">
									<?php esc_html_e('Recomendado: formato JPG o WebP con texto legible. El popup la adaptará automáticamente para que no se deforme en celulares ni en pantallas grandes.', 'mj-popup'); ?>
								</p>
							</div>
						</div>
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label" for="mj-popup-img-alt"><?php esc_html_e('Texto descriptivo (Alt)', 'mj-popup'); ?></label>
						<input type="text" id="mj-popup-img-alt" name="imagen_alt" class="regular-text" value="<?php echo esc_attr($opciones['imagen_alt']); ?>" />
						<p class="mj-popup-help"><?php esc_html_e('Texto para lectores de pantalla y accesibilidad (ej. "Estamos cerrados por vacaciones hasta el 15").', 'mj-popup'); ?></p>
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label" for="mj-popup-link-url"><?php esc_html_e('Enlace al hacer clic en la imagen (opcional)', 'mj-popup'); ?></label>
						<input type="url" id="mj-popup-link-url" name="enlace_url" class="large-text" placeholder="https://" value="<?php echo esc_url($opciones['enlace_url']); ?>" />
						<p class="mj-popup-help"><?php esc_html_e('Si querés que al tocar la imagen vayan a una página, WhatsApp o red social. Dejalo vacío si solo es un aviso informativo.', 'mj-popup'); ?></p>
						<p style="margin-top: 6px;">
							<label>
								<input type="checkbox" name="enlace_nueva_pestana" value="1" <?php checked($opciones['enlace_nueva_pestana'], 1); ?> />
								<?php esc_html_e('Abrir el enlace en una pestaña nueva', 'mj-popup'); ?>
							</label>
						</p>
					</div>
				</div>

				<!-- Tarjeta 2: Botón de continuar y Frecuencia -->
				<div class="mj-popup-card">
					<h2 class="mj-popup-card-title">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e('Comportamiento y frecuencia', 'mj-popup'); ?>
					</h2>

					<div class="mj-popup-field-row">
						<label class="mj-popup-switch-label">
							<input type="checkbox" name="mostrar_boton_cerrar" value="1" <?php checked($opciones['mostrar_boton_cerrar'], 1); ?> />
							<?php esc_html_e('Mostrar botón claro para continuar debajo de la imagen', 'mj-popup'); ?>
						</label>
						<p class="mj-popup-help"><?php esc_html_e('Además de la cruz superior y el clic en el fondo, suma un botón grande y claro para que nadie tenga dudas de cómo seguir.', 'mj-popup'); ?></p>
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label" for="mj-popup-btn-text"><?php esc_html_e('Texto del botón', 'mj-popup'); ?></label>
						<input type="text" id="mj-popup-btn-text" name="texto_boton_cerrar" class="regular-text" value="<?php echo esc_attr($opciones['texto_boton_cerrar']); ?>" />
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label"><?php esc_html_e('Frecuencia con la que salta a cada usuario', 'mj-popup'); ?></label>
						<select name="frecuencia" id="mj-popup-frecuencia">
							<option value="session" <?php selected($opciones['frecuencia'], 'session'); ?>><?php esc_html_e('Una sola vez por sesión (vuelve a salir si cierra el navegador y entra otro día)', 'mj-popup'); ?></option>
							<option value="1_day" <?php selected($opciones['frecuencia'], '1_day'); ?>><?php esc_html_e('Una sola vez cada 24 horas', 'mj-popup'); ?></option>
							<option value="3_days" <?php selected($opciones['frecuencia'], '3_days'); ?>><?php esc_html_e('Una sola vez cada 3 días', 'mj-popup'); ?></option>
							<option value="7_days" <?php selected($opciones['frecuencia'], '7_days'); ?>><?php esc_html_e('Una sola vez cada 7 días', 'mj-popup'); ?></option>
							<option value="30_days" <?php selected($opciones['frecuencia'], '30_days'); ?>><?php esc_html_e('Una sola vez cada 30 días', 'mj-popup'); ?></option>
							<option value="once" <?php selected($opciones['frecuencia'], 'once'); ?>><?php esc_html_e('Una sola vez por visitante (no vuelve a salir hasta que cambies la imagen o reinicies el aviso)', 'mj-popup'); ?></option>
							<option value="always" <?php selected($opciones['frecuencia'], 'always'); ?>><?php esc_html_e('Siempre (Modo prueba: salta en cada recarga de página)', 'mj-popup'); ?></option>
						</select>
					</div>

					<div class="mj-popup-field-row">
						<label class="mj-popup-label" for="mj-popup-retardo"><?php esc_html_e('Retardo de aparición (milisegundos)', 'mj-popup'); ?></label>
						<input type="number" id="mj-popup-retardo" name="retardo_ms" min="0" max="10000" step="100" class="small-text" value="<?php echo esc_attr($opciones['retardo_ms']); ?>" /> ms
						<p class="mj-popup-help"><?php esc_html_e('Recomendado: 400 a 600 ms. Permite que el sitio dibuje su contenido antes de mostrar el aviso suavemente, haciéndolo no invasor.', 'mj-popup'); ?></p>
					</div>
				</div>

				<div class="mj-popup-actions">
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e('Guardar ajustes', 'mj-popup'); ?>
					</button>

					<?php if (!empty($opciones['imagen_url'])) : ?>
						<a href="<?php echo esc_url($preview_url); ?>" target="_blank" class="button button-secondary button-large">
							<span class="dashicons dashicons-visibility" style="vertical-align: text-top; margin-right: 4px;"></span>
							<?php esc_html_e('Probar cómo se ve en la tienda', 'mj-popup'); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>

			<!-- Tarjeta 3: Reinicio manual de visualizaciones -->
			<div class="mj-popup-card" style="margin-top: 30px;">
				<h2 class="mj-popup-card-title">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e('Reiniciar visualizaciones para todos los clientes', 'mj-popup'); ?>
				</h2>
				<p class="mj-popup-help" style="margin-bottom: 14px;">
					<?php esc_html_e('Si subiste una información urgente o cambiaste los días de cierre y querés que todos los clientes que ya vieron el popup anterior vuelvan a verlo una vez más, hacé clic en este botón.', 'mj-popup'); ?>
				</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
					<?php wp_nonce_field(self::NONCE, 'mj_popup_nonce'); ?>
					<input type="hidden" name="mj_popup_accion" value="reiniciar" />
					<button type="submit" id="mj-popup-btn-reset" class="button button-secondary">
						<span class="dashicons dashicons-image-rotate" style="vertical-align: text-top; margin-right: 4px;"></span>
						<?php esc_html_e('Reiniciar aviso (volver a mostrar a todos)', 'mj-popup'); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}
}

add_action('plugins_loaded', ['MJ_Popup', 'init']);
