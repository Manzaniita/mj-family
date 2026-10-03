<?php
/**
 * Panel de administración de MJ Instagram en wp-admin.
 *
 * Configuración de credenciales de la API de Instagram, verificación
 * de cuenta, caché y destacados personalizados.
 *
 * @package MJ_Instagram
 */

defined('ABSPATH') || exit;

class MJ_Instagram_Admin {

	/**
	 * Instancia de la API.
	 *
	 * @var MJ_Instagram_API
	 */
	private $api;

	/**
	 * Hook del menú en wp-admin.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Constructor.
	 *
	 * @param MJ_Instagram_API $api
	 */
	public function __construct(MJ_Instagram_API $api) {
		$this->api = $api;

		add_action('admin_menu', [$this, 'register_menu'], 30);
		add_action('admin_init', [$this, 'handle_actions']);
	}

	/**
	 * Registra el submenú bajo el menú central MJ.
	 *
	 * @return void
	 */
	public function register_menu() {
		if (!class_exists('MJ_Menu')) {
			return;
		}

		self::$hook = add_submenu_page(
			MJ_Menu::slug(),
			'MJ Instagram',
			'Instagram',
			'manage_options',
			'mj-instagram',
			[$this, 'render_page']
		);
	}

	/**
	 * Procesa acciones del panel (guardar, limpiar caché, probar conexión).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if (!isset($_POST['mj_instagram_action'])) {
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('No tenés permisos para realizar esta acción.', 'mj-instagram'));
		}

		check_admin_referer('mj_instagram_admin_nonce', 'mj_instagram_nonce');

		$action = sanitize_text_field(wp_unslash($_POST['mj_instagram_action']));

		if ('save_settings' === $action) {
			$this->save_settings();
		} elseif ('clear_cache' === $action) {
			$this->api->clear_cache();
			wp_safe_redirect(add_query_arg(['page' => 'mj-instagram', 'mj_msg' => 'cache_cleared'], admin_url('admin.php')));
			exit;
		} elseif ('test_connection' === $action) {
			$this->run_test_connection();
		}
	}

	/**
	 * Guarda las opciones del plugin.
	 *
	 * @return void
	 */
	private function save_settings() {
		$options = get_option('mj_instagram_settings', []);
		if (!is_array($options)) {
			$options = [];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Ya validado en handle_actions().
		if (isset($_POST['access_token'])) {
			$options['access_token'] = sanitize_text_field(wp_unslash($_POST['access_token']));
		}
		if (isset($_POST['user_id'])) {
			$options['user_id'] = sanitize_text_field(wp_unslash($_POST['user_id']));
		}
		if (isset($_POST['cache_ttl'])) {
			$options['cache_ttl'] = absint(wp_unslash($_POST['cache_ttl']));
		}
		if (isset($_POST['fallback_handle'])) {
			$options['fallback_handle'] = sanitize_text_field(wp_unslash($_POST['fallback_handle']));
		}
		if (isset($_POST['fallback_name'])) {
			$options['fallback_name'] = sanitize_text_field(wp_unslash($_POST['fallback_name']));
		}
		if (isset($_POST['fallback_avatar'])) {
			$options['fallback_avatar'] = esc_url_raw(wp_unslash($_POST['fallback_avatar']));
		}
		if (isset($_POST['custom_bio'])) {
			$options['custom_bio'] = sanitize_textarea_field(wp_unslash($_POST['custom_bio']));
		}
		if (isset($_POST['custom_followers'])) {
			$options['custom_followers'] = absint(wp_unslash($_POST['custom_followers']));
		}

		// Destacados personalizados (Highlights)
		if (isset($_POST['highlights_title']) && is_array($_POST['highlights_title'])) {
			$curated = [];
			$titles  = array_map('sanitize_text_field', wp_unslash($_POST['highlights_title']));
			$covers  = isset($_POST['highlights_cover']) && is_array($_POST['highlights_cover']) ? array_map('esc_url_raw', wp_unslash($_POST['highlights_cover'])) : [];
			$links   = isset($_POST['highlights_link']) && is_array($_POST['highlights_link']) ? array_map('esc_url_raw', wp_unslash($_POST['highlights_link'])) : [];

			foreach ($titles as $i => $title) {
				if ('' === trim($title)) {
					continue;
				}
				$curated[] = [
					'id'        => 'hl_' . $i . '_' . sanitize_title($title),
					'title'     => $title,
					'cover_url' => isset($covers[$i]) ? $covers[$i] : '',
					'link'      => isset($links[$i]) ? $links[$i] : '',
				];
			}
			$options['custom_highlights'] = $curated;
		} else {
			$options['custom_highlights'] = [];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option('mj_instagram_settings', $options);
		$this->api->clear_cache();

		// Actualizar datos del perfil en caché
		if (!empty($options['access_token'])) {
			$this->api->get_profile(true);
		}

		wp_safe_redirect(add_query_arg(['page' => 'mj-instagram', 'mj_msg' => 'saved'], admin_url('admin.php')));
		exit;
	}

	/**
	 * Prueba la conexión con el token enviado.
	 *
	 * @return void
	 */
	private function run_test_connection() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Ya validado en handle_actions().
		$token   = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
		$user_id = isset($_POST['user_id']) ? sanitize_text_field(wp_unslash($_POST['user_id'])) : 'me';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$res = $this->api->test_connection($token, $user_id);

		if (is_wp_error($res)) {
			$msg = rawurlencode($res->get_error_message());
			wp_safe_redirect(add_query_arg(['page' => 'mj-instagram', 'mj_err' => $msg], admin_url('admin.php')));
			exit;
		}

		// Si fue exitoso, guardamos el perfil
		$options                   = get_option('mj_instagram_settings', []);
		$options['cached_profile'] = $res;
		update_option('mj_instagram_settings', $options);

		wp_safe_redirect(add_query_arg(['page' => 'mj-instagram', 'mj_msg' => 'connected'], admin_url('admin.php')));
		exit;
	}

	/**
	 * Renderiza la página de administración.
	 *
	 * @return void
	 */
	public function render_page() {
		$options   = get_option('mj_instagram_settings', []);
		$profile   = $this->api->get_profile();
		$connected = $this->api->is_connected() && !is_wp_error($profile);

		$token     = !empty($options['access_token']) ? $options['access_token'] : '';
		$userId    = !empty($options['user_id']) ? $options['user_id'] : '';
		$ttl       = !empty($options['cache_ttl']) ? absint($options['cache_ttl']) : 7200;
		$fbHandle  = !empty($options['fallback_handle']) ? $options['fallback_handle'] : '';
		$fbName    = !empty($options['fallback_name']) ? $options['fallback_name'] : '';
		$fbAvatar  = !empty($options['fallback_avatar']) ? $options['fallback_avatar'] : '';
		$bio       = !empty($options['custom_bio']) ? $options['custom_bio'] : '';
		$followers = !empty($options['custom_followers']) ? absint($options['custom_followers']) : 0;
		$curatedHl = !empty($options['custom_highlights']) ? (array) $options['custom_highlights'] : [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset($_GET['mj_msg']) ? sanitize_text_field(wp_unslash($_GET['mj_msg'])) : '';
		$err = isset($_GET['mj_err']) ? sanitize_text_field(wp_unslash($_GET['mj_err'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap mj-ig-admin-wrap" style="max-width:960px;">
			<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
				<span class="dashicons dashicons-instagram" style="font-size:32px;width:32px;height:32px;color:#e1306c;"></span>
				MJ Instagram
			</h1>

			<?php if ('saved' === $msg) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Ajustes guardados correctamente.', 'mj-instagram'); ?></p></div>
			<?php elseif ('cache_cleared' === $msg) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Caché de Instagram vaciada. Se actualizarán las fotos en la próxima carga.', 'mj-instagram'); ?></p></div>
			<?php elseif ('connected' === $msg) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('¡Conexión exitosa! Cuenta de Instagram verificada.', 'mj-instagram'); ?></p></div>
			<?php endif; ?>

			<?php if (!empty($err)) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html(urldecode($err)); ?></p></div>
			<?php endif; ?>

			<!-- Tarjeta de estado de conexión -->
			<div class="card" style="margin-bottom:24px;padding:18px 24px;border-left:4px solid <?php echo $connected ? '#46b450' : '#ffb900'; ?>;">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
					<div style="display:flex;align-items:center;gap:16px;">
						<?php if (!empty($profile['profile_picture'])) : ?>
							<img src="<?php echo esc_url($profile['profile_picture']); ?>" alt="Avatar" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e1306c;">
						<?php else : ?>
							<div style="width:56px;height:56px;border-radius:50%;background:#f0f0f1;display:grid;place-items:center;font-size:24px;color:#8c8f94;">
								<span class="dashicons dashicons-camera"></span>
							</div>
						<?php endif; ?>
						<div>
							<h3 style="margin:0 0 4px 0;font-size:16px;">
								<?php echo esc_html(!empty($profile['name']) ? $profile['name'] : (!empty($profile['username']) ? '@' . $profile['username'] : 'Cuenta no conectada')); ?>
								<?php if ($connected) : ?>
									<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:12px;background:#e7f7ed;color:#1e6a39;margin-left:6px;">Conectada</span>
								<?php else : ?>
									<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:12px;background:#fef7e0;color:#855d00;margin-left:6px;">Modo Demostración / Sin token</span>
								<?php endif; ?>
							</h3>
							<p style="margin:0;color:#646970;font-size:13px;">
								<?php if (!empty($profile['username'])) : ?>
									<a href="<?php echo esc_url('https://instagram.com/' . $profile['username']); ?>" target="_blank" rel="noreferrer">@<?php echo esc_html($profile['username']); ?></a>
									&nbsp;•&nbsp; <?php echo esc_html($profile['media_count']); ?> publicaciones
								<?php else : ?>
									<?php esc_html_e('Ingresá un token de Instagram Graph API abajo para sincronizar tus publicaciones en vivo.', 'mj-instagram'); ?>
								<?php endif; ?>
							</p>
						</div>
					</div>

					<form method="post" style="margin:0;">
						<?php wp_nonce_field('mj_instagram_admin_nonce', 'mj_instagram_nonce'); ?>
						<input type="hidden" name="mj_instagram_action" value="clear_cache">
						<button type="submit" class="button button-secondary">
							<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
							<?php esc_html_e('Vaciar caché y refrescar', 'mj-instagram'); ?>
						</button>
					</form>
				</div>
			</div>

			<!-- Formulario de configuración -->
			<form method="post" action="">
				<?php wp_nonce_field('mj_instagram_admin_nonce', 'mj_instagram_nonce'); ?>
				<input type="hidden" name="mj_instagram_action" value="save_settings">

				<div class="card" style="margin-bottom:20px;padding:20px 24px;">
					<h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #dcdcde;padding-bottom:10px;">
						<?php esc_html_e('1. Conexión con Instagram (Graph API)', 'mj-instagram'); ?>
					</h2>
					<p class="description" style="margin-bottom:18px;">
						<?php esc_html_e('Para conectar tu cuenta necesitás un Access Token de Instagram Graph API o Instagram Basic Display. Podés obtenerlo desde Meta for Developers con tu cuenta de Instagram / Facebook.', 'mj-instagram'); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="access_token"><?php esc_html_e('Token de acceso (Access Token)', 'mj-instagram'); ?></label></th>
							<td>
								<input type="password" id="access_token" name="access_token" value="<?php echo esc_attr($token); ?>" class="regular-text" style="width:100%;max-width:550px;" placeholder="IGQJ...">
								<button type="button" class="button" onclick="var el = document.getElementById('access_token'); el.type = (el.type==='password'?'text':'password'); this.textContent = (el.type==='password'?'Mostrar':'Ocultar');">Mostrar</button>
								<p class="description">
									<?php esc_html_e('Token de larga duración (User Token de Instagram o Token de Sistema de Meta). También podés definirlo en wp-config.php con la constante MJ_INSTAGRAM_TOKEN.', 'mj-instagram'); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="user_id"><?php esc_html_e('ID de usuario (opcional)', 'mj-instagram'); ?></label></th>
							<td>
								<input type="text" id="user_id" name="user_id" value="<?php echo esc_attr($userId); ?>" class="regular-text" placeholder="me o tu ID numérico">
								<p class="description">
									<?php esc_html_e('Por defecto "me". Si usás una página conectada a un Instagram Business, podés indicar acá el Instagram Business Account ID.', 'mj-instagram'); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="cache_ttl"><?php esc_html_e('Duración de la caché', 'mj-instagram'); ?></label></th>
							<td>
								<select id="cache_ttl" name="cache_ttl">
									<option value="1800" <?php selected($ttl, 1800); ?>>30 minutos</option>
									<option value="3600" <?php selected($ttl, 3600); ?>>1 hora</option>
									<option value="7200" <?php selected($ttl, 7200); ?>>2 horas (Recomendado)</option>
									<option value="21600" <?php selected($ttl, 21600); ?>>6 horas</option>
									<option value="86400" <?php selected($ttl, 86400); ?>>24 horas</option>
								</select>
								<p class="description">
									<?php esc_html_e('Evita llamadas innecesarias a la API y acelera la carga para las clientas.', 'mj-instagram'); ?>
								</p>
							</td>
						</tr>
					</table>

					<div style="margin-top:15px;display:flex;gap:10px;">
						<button type="submit" class="button button-primary"><?php esc_html_e('Guardar credenciales', 'mj-instagram'); ?></button>
						<button type="submit" name="mj_instagram_action" value="test_connection" class="button button-secondary"><?php esc_html_e('Probar conexión ahora', 'mj-instagram'); ?></button>
					</div>
				</div>

				<!-- Datos del perfil y personalización -->
				<div class="card" style="margin-bottom:20px;padding:20px 24px;">
					<h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #dcdcde;padding-bottom:10px;">
						<?php esc_html_e('2. Perfil y datos visibles en la tienda', 'mj-instagram'); ?>
					</h2>
					<p class="description" style="margin-bottom:18px;">
						<?php esc_html_e('Personalizá los datos que se mostrarán en la cabecera del bloque cuando elijas mostrar el perfil.', 'mj-instagram'); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fallback_handle"><?php esc_html_e('Usuario de Instagram (@handle)', 'mj-instagram'); ?></label></th>
							<td>
								<input type="text" id="fallback_handle" name="fallback_handle" value="<?php echo esc_attr($fbHandle); ?>" class="regular-text" placeholder="@mitienda">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fallback_name"><?php esc_html_e('Nombre de la tienda / perfil', 'mj-instagram'); ?></label></th>
							<td>
								<input type="text" id="fallback_name" name="fallback_name" value="<?php echo esc_attr($fbName); ?>" class="regular-text" placeholder="Mi Tienda Oficial">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fallback_avatar"><?php esc_html_e('URL de foto de perfil / Avatar', 'mj-instagram'); ?></label></th>
							<td>
								<input type="url" id="fallback_avatar" name="fallback_avatar" value="<?php echo esc_attr($fbAvatar); ?>" class="regular-text" placeholder="https://...">
								<p class="description"><?php esc_html_e('Opcional. Si el token no devuelve foto de perfil, se utilizará esta imagen.', 'mj-instagram'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="custom_bio"><?php esc_html_e('Biografía / Descripción', 'mj-instagram'); ?></label></th>
							<td>
								<textarea id="custom_bio" name="custom_bio" rows="3" class="large-text"><?php echo esc_textarea($bio); ?></textarea>
								<p class="description"><?php esc_html_e('Texto que describe tu cuenta bajo el nombre en la tienda.', 'mj-instagram'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="custom_followers"><?php esc_html_e('Cantidad de seguidores (opcional)', 'mj-instagram'); ?></label></th>
							<td>
								<input type="number" id="custom_followers" name="custom_followers" value="<?php echo esc_attr($followers); ?>" class="small-text">
								<p class="description"><?php esc_html_e('Si querés mostrar una cifra fija de seguidores en el perfil.', 'mj-instagram'); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Destacados personalizados (Highlights) -->
				<div class="card" style="margin-bottom:20px;padding:20px 24px;">
					<h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #dcdcde;padding-bottom:10px;">
						<?php esc_html_e('3. Historias destacadas (Highlights)', 'mj-instagram'); ?>
					</h2>
					<p class="description" style="margin-bottom:18px;">
						<?php esc_html_e('Configurá las burbujas de historias destacadas que podés mostrar en cualquier página (por ejemplo: "Clientas", "Novedades", "Envíos", "Antes y Después").', 'mj-instagram'); ?>
					</p>

					<div id="mj-highlights-container">
						<?php
						if (empty($curatedHl)) {
							$curatedHl = [
								['title' => 'Novedades', 'cover_url' => '', 'link' => ''],
								['title' => 'Clientas', 'cover_url' => '', 'link' => ''],
								['title' => 'Envíos', 'cover_url' => '', 'link' => ''],
							];
						}
						foreach ($curatedHl as $idx => $hl) :
							?>
							<div class="mj-hl-row" style="display:flex;gap:12px;align-items:center;margin-bottom:10px;background:#f9f9f9;padding:10px;border-radius:6px;border:1px solid #e2e4e7;">
								<span class="dashicons dashicons-menu-alt3" style="color:#a7aaad;cursor:grab;"></span>
								<input type="text" name="highlights_title[]" value="<?php echo esc_attr(!empty($hl['title']) ? $hl['title'] : ''); ?>" placeholder="Título (ej: Novedades)" style="width:160px;">
								<input type="url" name="highlights_cover[]" value="<?php echo esc_attr(!empty($hl['cover_url']) ? $hl['cover_url'] : ''); ?>" placeholder="URL portada o foto" style="flex:1;">
								<input type="url" name="highlights_link[]" value="<?php echo esc_attr(!empty($hl['link']) ? $hl['link'] : ''); ?>" placeholder="Enlace opcional" style="flex:1;">
								<button type="button" class="button button-link-delete" onclick="this.closest('.mj-hl-row').remove();" title="Eliminar">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>

					<button type="button" class="button" onclick="agregarDestacado();" style="margin-top:8px;">
						+ <?php esc_html_e('Agregar historia destacada', 'mj-instagram'); ?>
					</button>

					<script>
					function agregarDestacado() {
						var c = document.getElementById('mj-highlights-container');
						var div = document.createElement('div');
						div.className = 'mj-hl-row';
						div.style = 'display:flex;gap:12px;align-items:center;margin-bottom:10px;background:#f9f9f9;padding:10px;border-radius:6px;border:1px solid #e2e4e7;';
						div.innerHTML = '<span class="dashicons dashicons-menu-alt3" style="color:#a7aaad;cursor:grab;"></span>' +
							'<input type="text" name="highlights_title[]" value="" placeholder="Título" style="width:160px;">' +
							'<input type="url" name="highlights_cover[]" value="" placeholder="URL portada" style="flex:1;">' +
							'<input type="url" name="highlights_link[]" value="" placeholder="Enlace opcional" style="flex:1;">' +
							'<button type="button" class="button button-link-delete" onclick="this.closest(\'.mj-hl-row\').remove();">&times;</button>';
						c.appendChild(div);
					}
					</script>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large"><?php esc_html_e('Guardar todos los cambios', 'mj-instagram'); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
