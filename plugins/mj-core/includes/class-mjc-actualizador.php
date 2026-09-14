<?php
/**
 * El actualizador: le dice a WordPress qué paquetes MJ tienen versión nueva y
 * baja el zip cuando se aprieta "Actualizar".
 *
 * No inventa una pantalla propia: se engancha en el mecanismo de siempre de
 * WordPress. La versión nueva aparece en Escritorio → Actualizaciones, en la
 * lista de plugins y en Apariencia → Temas, con su botón, y también funciona
 * el "Activar actualizaciones automáticas" de cada plugin.
 *
 * ---------------------------------------------------------------------------
 * QUÉ PAQUETES MANEJA
 *
 * Todo plugin o tema instalado cuya carpeta coincida con un zip publicado. Se
 * decide por la carpeta y no por el nombre porque es lo que WordPress usa para
 * reemplazar: el zip de mj-erp trae la carpeta mj-erp/ y pisa esa.
 *
 * Si WordPress.org llegara a tener un plugin con la misma carpeta, lo que diga
 * WordPress.org se descarta: para esas carpetas manda lo publicado por MJ.
 *
 * @package MJ_Core
 */

defined('ABSPATH') || exit;

/**
 * Enganches con el sistema de actualizaciones de WordPress.
 */
class MJC_Actualizador {

	/**
	 * Enganches.
	 */
	public static function init() {
		add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'plugins']);
		add_filter('pre_set_site_transient_update_themes', [__CLASS__, 'temas']);
		add_filter('plugins_api', [__CLASS__, 'detalles_plugin'], 10, 3);
		add_filter('upgrader_pre_download', [__CLASS__, 'descargar'], 10, 2);
		add_action('wp_ajax_mjc_notas', [__CLASS__, 'notas_tema']);
		add_action('load-update-core.php', [__CLASS__, 'al_buscar_de_nuevo']);
	}

	/* ------------------------------------------------------------------ */
	/* Avisar                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Suma los plugins MJ a lo que WordPress sabe de actualizaciones.
	 *
	 * @param mixed $transient Lo que WordPress está por guardar.
	 * @return mixed
	 */
	public static function plugins($transient) {
		if (!is_object($transient)) {
			return $transient;
		}
		$paquetes = MJC_Catalogo::paquetes();
		if (empty($paquetes)) {
			return $transient;
		}

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach (get_plugins() as $archivo => $datos) {
			$carpeta = dirname($archivo);
			if ('.' === $carpeta || !isset($paquetes[$carpeta])) {
				continue;
			}
			$paquete = $paquetes[$carpeta];

			$item = (object) [
				'id'          => 'mj/' . $carpeta,
				'slug'        => $carpeta,
				'plugin'      => $archivo,
				'new_version' => $paquete['version'],
				'url'         => $paquete['pagina'],
				'package'     => $paquete['descarga'],
				'icons'       => [],
				'banners'     => [],
				'banners_rtl' => [],
			];

			$hay_nueva = version_compare($paquete['version'], (string) $datos['Version'], '>');
			self::ubicar($transient, $archivo, $item, $hay_nueva);
		}

		return $transient;
	}

	/**
	 * Suma los temas MJ a lo que WordPress sabe de actualizaciones.
	 *
	 * @param mixed $transient Lo que WordPress está por guardar.
	 * @return mixed
	 */
	public static function temas($transient) {
		if (!is_object($transient)) {
			return $transient;
		}
		$paquetes = MJC_Catalogo::paquetes();
		if (empty($paquetes)) {
			return $transient;
		}

		foreach (wp_get_themes() as $carpeta => $tema) {
			if (!isset($paquetes[$carpeta])) {
				continue;
			}
			$paquete = $paquetes[$carpeta];

			// Los temas van como array, no como objeto: así los guarda WordPress.
			$item = [
				'theme'        => $carpeta,
				'new_version'  => $paquete['version'],
				'url'          => self::url_notas($carpeta),
				'package'      => $paquete['descarga'],
				'requires'     => '',
				'requires_php' => '',
			];

			$hay_nueva = version_compare($paquete['version'], (string) $tema->get('Version'), '>');
			self::ubicar($transient, $carpeta, $item, $hay_nueva);
		}

		return $transient;
	}

	/**
	 * Pone un paquete en "hay actualización" o en "al día", y lo saca del otro.
	 *
	 * @param object       $transient Lo que WordPress está por guardar.
	 * @param string       $clave     Archivo del plugin o carpeta del tema.
	 * @param object|array $item      Datos de la versión publicada.
	 * @param bool         $hay_nueva Si la publicada es más nueva que la instalada.
	 */
	private static function ubicar($transient, $clave, $item, $hay_nueva) {
		foreach (['response', 'no_update'] as $lista) {
			if (!isset($transient->$lista) || !is_array($transient->$lista)) {
				$transient->$lista = [];
			}
		}

		unset($transient->response[$clave], $transient->no_update[$clave]);

		if ($hay_nueva) {
			$transient->response[$clave] = $item;
		} else {
			$transient->no_update[$clave] = $item;
		}
	}

	/**
	 * "Buscar de nuevo" en Escritorio → Actualizaciones también vuelve a leer
	 * GitHub, en vez de esperar a que venza el plazo.
	 */
	public static function al_buscar_de_nuevo() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo adelanta una consulta de lectura; WordPress hace lo mismo con este parámetro.
		if (isset($_GET['force-check']) && current_user_can('update_plugins')) {
			MJC_Catalogo::olvidar();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Detalles de la versión                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * La ventana de "Ver los detalles de la versión" de un plugin MJ.
	 *
	 * Sin esto, WordPress la busca en WordPress.org y muestra "no encontrado".
	 *
	 * @param mixed  $resultado Lo que haya resuelto otro.
	 * @param string $accion    Qué se pide.
	 * @param object $args      Datos del pedido; trae el slug.
	 * @return mixed
	 */
	public static function detalles_plugin($resultado, $accion, $args) {
		if ('plugin_information' !== $accion || !is_object($args) || empty($args->slug)) {
			return $resultado;
		}
		$carpeta  = (string) $args->slug;
		$paquetes = MJC_Catalogo::paquetes();
		if (!isset($paquetes[$carpeta])) {
			return $resultado;
		}
		$paquete = $paquetes[$carpeta];

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$nombre = $carpeta;
		$autor  = '';
		foreach (get_plugins() as $archivo => $datos) {
			if (dirname($archivo) === $carpeta) {
				$nombre = $datos['Name'];
				$autor  = $datos['Author'];
				break;
			}
		}

		return (object) [
			'name'          => $nombre,
			'slug'          => $carpeta,
			'version'       => $paquete['version'],
			'author'        => $autor,
			'homepage'      => $paquete['pagina'],
			'download_link' => $paquete['descarga'],
			'last_updated'  => $paquete['fecha'],
			'sections'      => ['changelog' => self::notas_html($paquete)],
			'banners'       => [],
			'icons'         => [],
		];
	}

	/**
	 * La ventana de "Ver los detalles de la versión" de un tema MJ.
	 *
	 * WordPress la abre en un marco con la dirección que se le dio en 'url'.
	 * La página de la Release no sirve: GitHub no deja mostrarse en un marco.
	 */
	public static function notas_tema() {
		if (!current_user_can('update_themes')) {
			wp_die('No tenés permiso para ver esto.', '', ['response' => 403]);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo muestra notas de una versión; no cambia nada.
		$carpeta  = isset($_GET['slug']) ? sanitize_key(wp_unslash($_GET['slug'])) : '';
		$paquetes = MJC_Catalogo::paquetes();
		if (!isset($paquetes[$carpeta])) {
			wp_die('No hay una versión publicada de ese tema.', '', ['response' => 404]);
		}

		$tema = wp_get_theme($carpeta);
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>" />
			<title><?php echo esc_html($tema->get('Name')); ?></title>
			<style>
				body { font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 24px; color: #1d2327; }
				h1 { font-size: 20px; margin: 0 0 8px; }
				code { background: #f0f0f1; padding: 1px 4px; }
			</style>
		</head>
		<body>
			<h1><?php echo esc_html($tema->get('Name')); ?></h1>
			<?php echo wp_kses_post(self::notas_html($paquetes[$carpeta])); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Dirección de las notas de un tema.
	 *
	 * @param string $carpeta Carpeta del tema.
	 * @return string
	 */
	private static function url_notas($carpeta) {
		return add_query_arg(
			[
				'action' => 'mjc_notas',
				'slug'   => $carpeta,
			],
			admin_url('admin-ajax.php')
		);
	}

	/**
	 * Las notas de la Release, listas para mostrar.
	 *
	 * @param array $paquete Datos de la versión publicada.
	 * @return string
	 */
	private static function notas_html($paquete) {
		$fecha = '';
		if ('' !== $paquete['fecha']) {
			$marca = strtotime($paquete['fecha']);
			if ($marca) {
				$fecha = wp_date(get_option('date_format'), $marca);
			}
		}

		$html  = '<p><strong>Versión ' . esc_html($paquete['version']) . '</strong>';
		$html .= '' !== $paquete['etiqueta'] ? ' — publicada con <code>' . esc_html($paquete['etiqueta']) . '</code>' : '';
		$html .= '' !== $fecha ? ' el ' . esc_html($fecha) : '';
		$html .= '.</p>';

		$notas = trim($paquete['notas']);
		$html .= '' !== $notas ? wpautop(esc_html($notas)) : '<p>Esta publicación no tiene notas.</p>';

		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Bajar                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Baja el zip de un paquete MJ, en lugar de dejar que lo baje WordPress.
	 *
	 * WordPress baja sin encabezados, y un repositorio privado no entrega nada
	 * sin el token. Además, así se puede comparar el SHA-256 antes de instalar.
	 *
	 * @param mixed  $respuesta Lo que haya resuelto otro; false si nadie.
	 * @param string $paquete   Dirección que WordPress iba a bajar.
	 * @return mixed Ruta del zip bajado, WP_Error, o lo que vino si no es de MJ.
	 */
	public static function descargar($respuesta, $paquete) {
		if (false !== $respuesta || !is_string($paquete)) {
			return $respuesta;
		}
		$datos = MJC_Catalogo::por_descarga($paquete);
		if (null === $datos) {
			return $respuesta;
		}
		return self::bajar($datos);
	}

	/**
	 * Baja un zip publicado a un archivo temporal y lo verifica.
	 *
	 * GitHub no entrega el archivo en la dirección de la API: responde con una
	 * redirección a un enlace firmado que vale unos minutos. Ese segundo pedido
	 * va sin el token, porque el enlace ya lo autoriza y GitHub rechaza los
	 * pedidos que traen las dos cosas.
	 *
	 * @param array $datos Datos de la versión publicada.
	 * @return string|WP_Error Ruta del zip.
	 */
	private static function bajar($datos) {
		$archivo = wp_tempnam($datos['zip']);
		if (!$archivo) {
			return new WP_Error('mjc_temporal', 'No se pudo crear el archivo temporal para bajar ' . $datos['zip'] . '.');
		}

		$respuesta = wp_safe_remote_get(
			$datos['descarga'],
			[
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $archivo,
				'headers'     => MJC_Catalogo::encabezados('application/octet-stream'),
			]
		);

		$codigo = is_wp_error($respuesta) ? 0 : (int) wp_remote_retrieve_response_code($respuesta);

		if (in_array($codigo, [301, 302, 303, 307, 308], true)) {
			$destino = wp_remote_retrieve_header($respuesta, 'location');
			$destino = is_array($destino) ? (string) reset($destino) : (string) $destino;

			if ('https' !== wp_parse_url($destino, PHP_URL_SCHEME)) {
				return self::fallo($archivo, $datos, 'GitHub redirigió a una dirección que no es segura.');
			}

			$respuesta = wp_safe_remote_get(
				$destino,
				[
					'timeout'  => 300,
					'stream'   => true,
					'filename' => $archivo,
					'headers'  => ['User-Agent' => 'MJ-Core/' . MJC_VERSION],
				]
			);
			$codigo = is_wp_error($respuesta) ? 0 : (int) wp_remote_retrieve_response_code($respuesta);
		}

		if (is_wp_error($respuesta)) {
			return self::fallo($archivo, $datos, $respuesta->get_error_message());
		}
		if (200 !== $codigo) {
			return self::fallo($archivo, $datos, 'GitHub respondió con el código ' . $codigo . '.');
		}

		if ('' !== $datos['sha256']) {
			$real = (string) hash_file('sha256', $archivo);
			if (!hash_equals($datos['sha256'], $real)) {
				return self::fallo($archivo, $datos, 'El archivo bajado no coincide con el publicado (SHA-256 distinto). No se instaló nada.');
			}
		}

		MJ_Log::info('core', 'Paquete bajado', ['zip' => $datos['zip'], 'sha256' => '' !== $datos['sha256'] ? 'verificado' : 'sin dato para comparar']);

		return $archivo;
	}

	/**
	 * Borra lo bajado a medias, lo anota y devuelve el error para WordPress.
	 *
	 * @param string $archivo Archivo temporal.
	 * @param array  $datos   Datos de la versión publicada.
	 * @param string $motivo  Qué pasó.
	 * @return WP_Error
	 */
	private static function fallo($archivo, $datos, $motivo) {
		wp_delete_file($archivo);
		MJ_Log::error('core', 'No se pudo bajar un paquete', ['zip' => $datos['zip'], 'motivo' => $motivo]);
		return new WP_Error('mjc_descarga', 'No se pudo bajar ' . $datos['zip'] . ': ' . $motivo);
	}
}
