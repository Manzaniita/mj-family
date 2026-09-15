<?php
/**
 * El catálogo: qué versión está publicada de cada paquete.
 *
 * Sale de las Releases de GitHub. Cada zip publicado se llama
 * <carpeta>-<versión>.zip (lo arma tools/build-releases.ps1), así que con el
 * nombre del archivo alcanza para saber de qué paquete es y qué versión trae.
 *
 * Se miran varias Releases y no solo la última: si alguna vez se publica un
 * paquete suelto, los demás siguen estando en la anterior. De cada paquete
 * queda la versión más alta que aparezca.
 *
 * ---------------------------------------------------------------------------
 * CUÁNDO SE CONSULTA
 *
 * Una vez cada tantas horas (Ajustes → MJ · Actualizaciones). Entre consulta y
 * consulta se usa lo último que se leyó. Si GitHub no responde, también: la
 * tienda sigue ofreciendo lo que ya sabía, se anota el error, y se reintenta
 * dentro de una hora en vez de insistir en cada página.
 *
 * @package MJ_Core
 */

defined('ABSPATH') || exit;

/**
 * Lectura del catálogo de versiones publicadas.
 */
class MJC_Catalogo {

	/**
	 * Último catálogo que se leyó bien.
	 */
	const OPCION = 'mjc_catalogo';

	/**
	 * Marca de "ya se consultó hace poco". Cuando vence, se vuelve a consultar.
	 */
	const MARCA = 'mjc_consultado';

	/**
	 * Último error al consultar, para mostrarlo en el panel.
	 */
	const ERROR = 'mjc_ultimo_error';

	/**
	 * Cuántas Releases se miran hacia atrás.
	 */
	const POR_PAGINA = 20;

	/**
	 * Nombre de un zip publicado: <carpeta>-<versión>.zip.
	 *
	 * La versión empieza con un número, así "mj-appearance-3.13.0.zip" no se
	 * confunde con un "mj-appearance-pro".
	 */
	const PATRON_ZIP = '/^(.+?)-(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?)\.zip$/';

	/**
	 * Los paquetes publicados, por carpeta.
	 *
	 * @param bool $forzar Consultar GitHub aunque no haya vencido el plazo.
	 * @return array<string, array> carpeta => datos de la versión publicada.
	 */
	public static function paquetes($forzar = false) {
		$catalogo = self::obtener($forzar);
		return isset($catalogo['paquetes']) && is_array($catalogo['paquetes']) ? $catalogo['paquetes'] : [];
	}

	/**
	 * El catálogo, consultando GitHub solo si hace falta.
	 *
	 * @param bool $forzar Consultar aunque no haya vencido el plazo.
	 * @return array repo, consultado, paquetes. Vacío si nunca se pudo leer.
	 */
	public static function obtener($forzar = false) {
		$repo = MJC_Ajustes::repo();
		if ('' === $repo) {
			return [];
		}

		$guardado = self::guardado();
		// Si se cambió de repositorio, lo guardado es de otro y no sirve.
		$vigente = isset($guardado['repo']) && $guardado['repo'] === $repo;

		if (!$forzar && $vigente && get_site_transient(self::MARCA)) {
			return $guardado;
		}

		$nuevo = self::consultar($repo);

		if (is_wp_error($nuevo)) {
			update_option(
				self::ERROR,
				[
					'mensaje' => $nuevo->get_error_message(),
					'cuando'  => time(),
				],
				false
			);
			MJ_Log::error('core', 'No se pudo leer el catálogo de versiones', ['repo' => $repo, 'motivo' => $nuevo->get_error_message()]);
			set_site_transient(self::MARCA, 1, min(HOUR_IN_SECONDS, MJC_Ajustes::segundos()));
			return $vigente ? $guardado : [];
		}

		update_option(self::OPCION, $nuevo, false);
		delete_option(self::ERROR);
		set_site_transient(self::MARCA, 1, MJC_Ajustes::segundos());
		MJ_Log::info('core', 'Catálogo de versiones leído', ['repo' => $repo, 'paquetes' => count($nuevo['paquetes'])]);

		return $nuevo;
	}

	/**
	 * Lo último que se leyó, sin consultar nada.
	 *
	 * @return array
	 */
	public static function guardado() {
		$guardado = get_option(self::OPCION, []);
		return is_array($guardado) ? $guardado : [];
	}

	/**
	 * Hace que la próxima lectura consulte GitHub.
	 */
	public static function olvidar() {
		delete_site_transient(self::MARCA);
	}

	/**
	 * El último error, si lo hubo.
	 *
	 * @return array{mensaje:string, cuando:int}|null
	 */
	public static function ultimo_error() {
		$error = get_option(self::ERROR, null);
		return is_array($error) && isset($error['mensaje']) ? $error : null;
	}

	/**
	 * El paquete al que corresponde una dirección de descarga, sin consultar.
	 *
	 * @param string $url Dirección que WordPress va a bajar.
	 * @return array|null
	 */
	public static function por_descarga($url) {
		$guardado = self::guardado();
		if (empty($guardado['paquetes']) || !is_array($guardado['paquetes'])) {
			return null;
		}
		foreach ($guardado['paquetes'] as $paquete) {
			if (isset($paquete['descarga']) && $paquete['descarga'] === $url) {
				return $paquete;
			}
		}
		return null;
	}

	/**
	 * Encabezados para hablar con la API de GitHub.
	 *
	 * @param string $acepta Qué se espera de vuelta.
	 * @return array
	 */
	public static function encabezados($acepta) {
		$encabezados = [
			'Accept'               => $acepta,
			'User-Agent'           => 'MJ-Core/' . MJCORE_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		$token = MJC_Ajustes::token();
		if ('' !== $token) {
			$encabezados['Authorization'] = 'Bearer ' . $token;
		}
		return $encabezados;
	}

	/**
	 * Dirección base de la API.
	 *
	 * @return string
	 */
	public static function api() {
		/**
		 * Dirección base de la API de GitHub. Para GitHub Enterprise.
		 *
		 * @param string $api Sin barra final.
		 */
		return untrailingslashit((string) apply_filters('mjc_api_base', 'https://api.github.com'));
	}

	/**
	 * Lee las Releases del repositorio y arma el catálogo.
	 *
	 * @param string $repo "usuario/repositorio".
	 * @return array|WP_Error
	 */
	private static function consultar($repo) {
		$url       = self::api() . '/repos/' . $repo . '/releases?per_page=' . self::POR_PAGINA;
		$respuesta = wp_safe_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => self::encabezados('application/vnd.github+json'),
			]
		);

		if (is_wp_error($respuesta)) {
			return new WP_Error('mjc_red', 'No se pudo hablar con GitHub: ' . $respuesta->get_error_message());
		}

		$codigo = (int) wp_remote_retrieve_response_code($respuesta);
		if (200 !== $codigo) {
			return new WP_Error('mjc_http', self::explicar_codigo($codigo));
		}

		$releases = json_decode((string) wp_remote_retrieve_body($respuesta), true);
		if (!is_array($releases)) {
			return new WP_Error('mjc_json', 'GitHub respondió algo que no se entiende como lista de Releases.');
		}

		$paquetes = [];
		foreach ($releases as $release) {
			if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
				continue;
			}
			$assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];

			foreach ($assets as $asset) {
				$paquete = self::leer_asset($asset, $release);
				if (null === $paquete) {
					continue;
				}
				$carpeta = $paquete['carpeta'];
				if (isset($paquetes[$carpeta]) && version_compare($paquetes[$carpeta]['version'], $paquete['version'], '>=')) {
					continue;
				}
				$paquetes[$carpeta] = $paquete;
			}
		}

		ksort($paquetes);

		return [
			'repo'       => $repo,
			'consultado' => time(),
			'paquetes'   => $paquetes,
		];
	}

	/**
	 * Un archivo de una Release, si es un zip de paquete.
	 *
	 * @param mixed $asset   Archivo, como lo devuelve la API.
	 * @param array $release La Release que lo contiene.
	 * @return array|null
	 */
	private static function leer_asset($asset, $release) {
		if (!is_array($asset) || empty($asset['name']) || empty($asset['url'])) {
			return null;
		}
		if (!preg_match(self::PATRON_ZIP, (string) $asset['name'], $partes)) {
			return null;
		}
		// Solo se baja de la API: nunca de una dirección que venga de otro lado.
		if (0 !== strpos((string) $asset['url'], self::api() . '/')) {
			return null;
		}

		// GitHub publica el SHA-256 de cada archivo ("sha256:…"). Las Releases
		// viejas pueden no tenerlo: en ese caso se baja igual, sin comparar.
		$sha256 = '';
		if (isset($asset['digest']) && preg_match('/^sha256:([a-f0-9]{64})$/i', (string) $asset['digest'], $digest)) {
			$sha256 = strtolower($digest[1]);
		}

		return [
			'carpeta'  => $partes[1],
			'version'  => $partes[2],
			'zip'      => (string) $asset['name'],
			'descarga' => (string) $asset['url'],
			'tamano'   => isset($asset['size']) ? (int) $asset['size'] : 0,
			'sha256'   => $sha256,
			'etiqueta' => isset($release['tag_name']) ? (string) $release['tag_name'] : '',
			'pagina'   => isset($release['html_url']) ? (string) $release['html_url'] : '',
			'notas'    => isset($release['body']) ? (string) $release['body'] : '',
			'fecha'    => isset($release['published_at']) ? (string) $release['published_at'] : '',
		];
	}

	/**
	 * Qué quiere decir un código de GitHub, en castellano y con qué hacer.
	 *
	 * @param int $codigo Código HTTP.
	 * @return string
	 */
	private static function explicar_codigo($codigo) {
		switch ($codigo) {
			case 401:
				return 'GitHub rechazó el token (401). Puede estar vencido o mal copiado.';
			case 404:
				return 'GitHub no encuentra el repositorio (404). Revisá el nombre, y que el token tenga acceso a ese repositorio: si es privado, sin token GitHub responde como si no existiera.';
			case 403:
			case 429:
				return 'GitHub frenó la consulta (' . $codigo . '). Sin token el límite es de 60 consultas por hora; con token, de 5.000.';
			default:
				return 'GitHub respondió con el código ' . $codigo . '.';
		}
	}
}
