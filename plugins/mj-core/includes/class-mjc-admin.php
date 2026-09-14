<?php
/**
 * Pantalla de Ajustes → MJ · Actualizaciones.
 *
 * Muestra qué versión de cada paquete MJ corre en esta tienda y cuál está
 * publicada, y guarda de dónde leer las versiones.
 *
 * @package MJ_Core
 */

defined('ABSPATH') || exit;

/**
 * Panel de MJ Core.
 */
class MJC_Admin {

	/**
	 * Slug de la pantalla.
	 */
	const PAGINA = 'mj-actualizaciones';

	/**
	 * Enganches.
	 */
	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_post_mjc_guardar', [__CLASS__, 'guardar']);
		add_action('admin_post_mjc_buscar', [__CLASS__, 'buscar']);
		add_action('admin_notices', [__CLASS__, 'aviso_sin_configurar']);
		add_filter('plugin_action_links_' . plugin_basename(MJC_FILE), [__CLASS__, 'enlace_ajustes']);
	}

	/**
	 * Agrega la pantalla.
	 */
	public static function menu() {
		add_options_page(
			'MJ · Actualizaciones',
			'MJ · Actualizaciones',
			'manage_options',
			self::PAGINA,
			[__CLASS__, 'pantalla']
		);
	}

	/**
	 * Dirección de la pantalla.
	 *
	 * @param array $extra Parámetros de más.
	 * @return string
	 */
	private static function url($extra = []) {
		return add_query_arg(array_merge(['page' => self::PAGINA], $extra), admin_url('options-general.php'));
	}

	/**
	 * "Ajustes" al lado de "Desactivar" en la lista de plugins.
	 *
	 * @param array $enlaces Enlaces que ya tiene.
	 * @return array
	 */
	public static function enlace_ajustes($enlaces) {
		array_unshift($enlaces, '<a href="' . esc_url(self::url()) . '">Ajustes</a>');
		return $enlaces;
	}

	/**
	 * Aviso en la lista de plugins mientras falte el repositorio.
	 */
	public static function aviso_sin_configurar() {
		$pantalla = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$pantalla || 'plugins' !== $pantalla->id || !current_user_can('manage_options')) {
			return;
		}
		if ('' !== MJC_Ajustes::repo()) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong>MJ Core</strong> todavía no sabe de dónde leer las versiones nuevas.
				<a href="<?php echo esc_url(self::url()); ?>">Configurarlo</a>.
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Acciones                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Guarda los ajustes.
	 */
	public static function guardar() {
		if (!current_user_can('manage_options')) {
			wp_die('No tenés permiso para cambiar esto.', '', ['response' => 403]);
		}
		check_admin_referer('mjc_guardar');

		$ajustes = [];

		if (isset($_POST['repo'])) {
			$repo = MJC_Ajustes::normalizar_repo(sanitize_text_field(wp_unslash($_POST['repo'])));
			if ('' !== $repo && !MJC_Ajustes::repo_valido($repo)) {
				wp_safe_redirect(self::url(['aviso' => 'repo']));
				exit;
			}
			$ajustes['repo'] = $repo;
		}

		if (isset($_POST['horas'])) {
			$ajustes['horas'] = absint(wp_unslash($_POST['horas']));
		}

		// El campo del token llega vacío salvo que se escriba uno nuevo: vacío
		// quiere decir "dejá el que está". Para sacarlo está la casilla.
		if (!empty($_POST['borrar_token'])) {
			$ajustes['token'] = '';
		} elseif (isset($_POST['token'])) {
			$token = trim(sanitize_text_field(wp_unslash($_POST['token'])));
			if ('' !== $token) {
				$ajustes['token'] = $token;
			}
		}

		MJC_Ajustes::guardar($ajustes);
		MJC_Catalogo::olvidar();

		wp_safe_redirect(self::url(['aviso' => 'guardado']));
		exit;
	}

	/**
	 * Consulta GitHub ya, y hace que WordPress vuelva a revisar.
	 */
	public static function buscar() {
		if (!current_user_can('manage_options')) {
			wp_die('No tenés permiso para hacer esto.', '', ['response' => 403]);
		}
		check_admin_referer('mjc_buscar');

		MJC_Catalogo::obtener(true);

		// Sin lo guardado, WordPress no espera sus doce horas: revisa ahora, y
		// al guardar pasa por MJC_Actualizador con el catálogo recién leído.
		delete_site_transient('update_plugins');
		delete_site_transient('update_themes');
		wp_update_plugins();
		wp_update_themes();

		wp_safe_redirect(self::url(['aviso' => 'buscado']));
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Pantalla                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Los paquetes publicados que están instalados en esta tienda.
	 *
	 * @param array $paquetes Catálogo, por carpeta.
	 * @return array Filas: nombre, tipo, instalada, publicada, hay_nueva.
	 */
	private static function instalados($paquetes) {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$filas = [];

		foreach (wp_get_themes() as $carpeta => $tema) {
			if (isset($paquetes[$carpeta])) {
				$filas[] = self::fila($tema->get('Name'), 'Tema', (string) $tema->get('Version'), $paquetes[$carpeta]['version']);
			}
		}
		foreach (get_plugins() as $archivo => $datos) {
			$carpeta = dirname($archivo);
			if ('.' !== $carpeta && isset($paquetes[$carpeta])) {
				$filas[] = self::fila($datos['Name'], 'Plugin', (string) $datos['Version'], $paquetes[$carpeta]['version']);
			}
		}

		return $filas;
	}

	/**
	 * Una fila de la tabla.
	 *
	 * @param string $nombre    Nombre del paquete.
	 * @param string $tipo      Tema o Plugin.
	 * @param string $instalada Versión instalada.
	 * @param string $publicada Versión publicada.
	 * @return array
	 */
	private static function fila($nombre, $tipo, $instalada, $publicada) {
		return [
			'nombre'    => $nombre,
			'tipo'      => $tipo,
			'instalada' => $instalada,
			'publicada' => $publicada,
			'hay_nueva' => version_compare($publicada, $instalada, '>'),
		];
	}

	/**
	 * Dibuja la pantalla.
	 */
	public static function pantalla() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$ajustes  = MJC_Ajustes::todos();
		$repo     = MJC_Ajustes::repo();
		$catalogo = MJC_Catalogo::obtener();
		$paquetes = isset($catalogo['paquetes']) && is_array($catalogo['paquetes']) ? $catalogo['paquetes'] : [];
		$error    = MJC_Catalogo::ultimo_error();
		$filas    = self::instalados($paquetes);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo elige qué cartel mostrar después de guardar.
		$aviso  = isset($_GET['aviso']) ? sanitize_key(wp_unslash($_GET['aviso'])) : '';
		$textos = [
			'guardado' => ['success', 'Ajustes guardados.'],
			'buscado'  => ['success', 'Listo, se buscaron versiones nuevas.'],
			'repo'     => ['error', 'El repositorio tiene que tener la forma usuario/repositorio. No se guardó nada.'],
		];
		?>
		<div class="wrap">
			<h1>MJ · Actualizaciones</h1>

			<?php if (isset($textos[$aviso])) : ?>
				<div class="notice notice-<?php echo esc_attr($textos[$aviso][0]); ?> is-dismissible">
					<p><?php echo esc_html($textos[$aviso][1]); ?></p>
				</div>
			<?php endif; ?>

			<p style="max-width:46em;">
				Cuando se publica una versión nueva de un tema o plugin MJ, esta tienda
				la ofrece como cualquier otra actualización de WordPress: en
				<a href="<?php echo esc_url(admin_url('update-core.php')); ?>">Escritorio → Actualizaciones</a>,
				en la lista de plugins y en Apariencia → Temas.
			</p>

			<?php if ($error) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong>La última consulta falló</strong>
						(<?php echo esc_html(sprintf('hace %s', human_time_diff((int) $error['cuando']))); ?>):
						<?php echo esc_html($error['mensaje']); ?>
						<?php if (!empty($catalogo)) : ?>
							<br />Mientras tanto se usa lo que se había leído antes.
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<h2>En esta tienda</h2>

			<?php if ('' === $repo) : ?>
				<p>Falta decir de qué repositorio salen las versiones (más abajo).</p>
			<?php elseif (empty($catalogo)) : ?>
				<p>Todavía no se pudo leer ninguna versión publicada.</p>
			<?php elseif (empty($filas)) : ?>
				<p>
					Hay <?php echo esc_html((string) count($paquetes)); ?> paquetes publicados, pero ninguno
					está instalado en esta tienda con la misma carpeta.
				</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:52em;">
					<thead>
						<tr>
							<th>Paquete</th>
							<th>Tipo</th>
							<th>Instalada</th>
							<th>Publicada</th>
							<th>Estado</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($filas as $fila) : ?>
							<tr>
								<td><strong><?php echo esc_html($fila['nombre']); ?></strong></td>
								<td><?php echo esc_html($fila['tipo']); ?></td>
								<td><?php echo esc_html($fila['instalada']); ?></td>
								<td><?php echo esc_html($fila['publicada']); ?></td>
								<td>
									<?php if ($fila['hay_nueva']) : ?>
										<a href="<?php echo esc_url(admin_url('update-core.php')); ?>"><strong>Hay versión nueva</strong></a>
									<?php else : ?>
										Al día
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if (!empty($catalogo['consultado'])) : ?>
				<p class="description">
					Última consulta a GitHub: hace <?php echo esc_html(human_time_diff((int) $catalogo['consultado'])); ?>.
					Se vuelve a consultar cada <?php echo esc_html((string) $ajustes['horas']); ?> horas.
				</p>
			<?php endif; ?>

			<?php if ('' !== $repo) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="mjc_buscar" />
					<?php wp_nonce_field('mjc_buscar'); ?>
					<?php submit_button('Buscar versiones nuevas ahora', 'secondary', 'submit', false); ?>
				</form>
			<?php endif; ?>

			<hr style="margin:28px 0;" />

			<h2>De dónde salen las versiones</h2>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="mjc_guardar" />
				<?php wp_nonce_field('mjc_guardar'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mjc-repo">Repositorio de GitHub</label></th>
						<td>
							<input
								type="text"
								id="mjc-repo"
								name="repo"
								class="regular-text code"
								placeholder="usuario/repositorio"
								value="<?php echo esc_attr($ajustes['repo']); ?>"
								<?php disabled($ajustes['repo_fijado']); ?>
							/>
							<p class="description">
								Donde <code>tools/publicar.ps1</code> publica las Releases con los zip.
								Se puede pegar la dirección entera.
								<?php if ($ajustes['repo_fijado']) : ?>
									<br /><strong>Fijado en wp-config.php</strong> con <code>MJ_CORE_REPO</code>.
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjc-token">Token de GitHub</label></th>
						<td>
							<?php if ($ajustes['token_fijado']) : ?>
								<p><strong>Fijado en wp-config.php</strong> con <code>MJ_CORE_TOKEN</code>.</p>
							<?php else : ?>
								<input
									type="password"
									id="mjc-token"
									name="token"
									class="regular-text code"
									autocomplete="off"
									placeholder="<?php echo esc_attr('' !== $ajustes['token'] ? 'Hay uno guardado. Escribí otro para cambiarlo.' : 'github_pat_…'); ?>"
								/>
								<?php if ('' !== $ajustes['token']) : ?>
									<label style="display:block; margin-top:6px;">
										<input type="checkbox" name="borrar_token" value="1" />
										Borrar el token guardado
									</label>
								<?php endif; ?>
								<p class="description" style="max-width:46em;">
									Hace falta si el repositorio es privado. En GitHub: Settings → Developer settings →
									Personal access tokens → <em>Fine-grained tokens</em>. Solo ese repositorio, y en
									permisos únicamente <em>Contents: Read-only</em>. Así, aunque alguien lo viera, no
									puede cambiar nada. Cuando vence, esta pantalla lo avisa.
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjc-horas">Cada cuántas horas consultar</label></th>
						<td>
							<input
								type="number"
								id="mjc-horas"
								name="horas"
								class="small-text"
								min="<?php echo esc_attr((string) MJC_Ajustes::HORAS_MIN); ?>"
								max="<?php echo esc_attr((string) MJC_Ajustes::HORAS_MAX); ?>"
								value="<?php echo esc_attr((string) $ajustes['horas']); ?>"
							/>
							<p class="description">
								Entre una consulta y otra se usa lo último que se leyó. Para no esperar, está el
								botón "Buscar versiones nuevas ahora".
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button('Guardar'); ?>
			</form>

			<hr />
			<p class="description">MJ Core <?php echo esc_html(MJC_VERSION); ?>.</p>
		</div>
		<?php
	}
}
