<?php
/**
 * Los ajustes de MJ Core: de qué repositorio leer, con qué token y cada
 * cuánto consultar.
 *
 * Cada uno se puede fijar también desde wp-config.php, que manda sobre lo que
 * se guarde en el panel:
 *
 *     define('MJ_CORE_REPO', 'usuario/repositorio');
 *     define('MJ_CORE_TOKEN', 'github_pat_...');
 *
 * El token en wp-config.php es lo más prolijo: no queda en la base de datos,
 * así que no viaja en una copia de seguridad ni en un volcado.
 *
 * @package MJ_Core
 */

defined('ABSPATH') || exit;

/**
 * Lectura y guardado de los ajustes.
 */
class MJC_Ajustes {

	/**
	 * Opción donde se guardan.
	 */
	const OPCION = 'mjc_ajustes';

	/**
	 * Cada cuántas horas se consulta GitHub, si no se eligió otra cosa.
	 */
	const HORAS = 6;

	/**
	 * Rango de horas que se acepta: menos de una es gastar consultas, más de
	 * dos días es enterarse tarde.
	 */
	const HORAS_MIN = 1;
	const HORAS_MAX = 48;

	/**
	 * Todos los ajustes ya resueltos.
	 *
	 * @return array{repo:string, token:string, horas:int, repo_fijado:bool, token_fijado:bool}
	 */
	public static function todos() {
		$guardado = get_option(self::OPCION, []);
		if (!is_array($guardado)) {
			$guardado = [];
		}

		$repo  = isset($guardado['repo']) ? (string) $guardado['repo'] : '';
		$token = isset($guardado['token']) ? (string) $guardado['token'] : '';
		$horas = isset($guardado['horas']) ? (int) $guardado['horas'] : self::HORAS;

		$repo_fijado = defined('MJ_CORE_REPO') && '' !== trim((string) MJ_CORE_REPO);
		if ($repo_fijado) {
			$repo = (string) MJ_CORE_REPO;
		}

		$token_fijado = defined('MJ_CORE_TOKEN') && '' !== trim((string) MJ_CORE_TOKEN);
		if ($token_fijado) {
			$token = (string) MJ_CORE_TOKEN;
		}

		$ajustes = [
			'repo'         => self::normalizar_repo($repo),
			'token'        => trim($token),
			'horas'        => max(self::HORAS_MIN, min(self::HORAS_MAX, $horas)),
			'repo_fijado'  => $repo_fijado,
			'token_fijado' => $token_fijado,
		];

		/**
		 * Ajustes de MJ Core, ya resueltos.
		 *
		 * @param array $ajustes repo, token, horas, repo_fijado, token_fijado.
		 */
		return (array) apply_filters('mjc_ajustes', $ajustes);
	}

	/**
	 * Repositorio de donde salen las versiones, como "usuario/repositorio".
	 * Vacío si no está configurado o no tiene forma de repositorio.
	 *
	 * @return string
	 */
	public static function repo() {
		$ajustes = self::todos();
		$repo    = isset($ajustes['repo']) ? (string) $ajustes['repo'] : '';
		return self::repo_valido($repo) ? $repo : '';
	}

	/**
	 * Token de GitHub. Vacío si no hay: sirve para un repositorio público.
	 *
	 * @return string
	 */
	public static function token() {
		$ajustes = self::todos();
		return isset($ajustes['token']) ? (string) $ajustes['token'] : '';
	}

	/**
	 * Cada cuántos segundos se vuelve a consultar GitHub.
	 *
	 * @return int
	 */
	public static function segundos() {
		$ajustes = self::todos();
		$horas   = isset($ajustes['horas']) ? (int) $ajustes['horas'] : self::HORAS;
		return max(self::HORAS_MIN, $horas) * HOUR_IN_SECONDS;
	}

	/**
	 * Guarda lo que llegó del panel, encima de lo que ya había.
	 *
	 * Lo que no viene en $nuevos queda como estaba: así el token no se borra
	 * cada vez que se guarda el formulario con el campo vacío.
	 *
	 * @param array $nuevos repo, token, horas.
	 */
	public static function guardar($nuevos) {
		$guardado = get_option(self::OPCION, []);
		if (!is_array($guardado)) {
			$guardado = [];
		}

		if (array_key_exists('repo', $nuevos)) {
			$guardado['repo'] = self::normalizar_repo((string) $nuevos['repo']);
		}
		if (array_key_exists('token', $nuevos)) {
			$guardado['token'] = trim((string) $nuevos['token']);
		}
		if (array_key_exists('horas', $nuevos)) {
			$guardado['horas'] = max(self::HORAS_MIN, min(self::HORAS_MAX, (int) $nuevos['horas']));
		}

		// Sin autoload: el token no tiene por qué cargarse en cada página.
		update_option(self::OPCION, $guardado, false);
	}

	/**
	 * Acepta el repositorio escrito como se copie: "usuario/repo", la dirección
	 * de la página o la de clonar.
	 *
	 * @param string $texto Lo que se escribió.
	 * @return string
	 */
	public static function normalizar_repo($texto) {
		$repo = trim((string) $texto);
		$repo = preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $repo);
		$repo = preg_replace('#\.git$#i', '', (string) $repo);
		return trim((string) $repo, '/ ');
	}

	/**
	 * Si el texto tiene forma de "usuario/repositorio".
	 *
	 * @param string $repo Repositorio ya normalizado.
	 * @return bool
	 */
	public static function repo_valido($repo) {
		return (bool) preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $repo);
	}
}
