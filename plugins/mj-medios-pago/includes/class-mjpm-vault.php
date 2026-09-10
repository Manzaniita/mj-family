<?php
/**
 * Bóveda de archivos de comprobantes.
 *
 * Un comprobante de transferencia lleva nombre, CBU y monto: no puede vivir en
 * la Biblioteca de Medios, porque todo lo que entra ahí queda con una URL
 * pública y adivinable. Así que los archivos van a una carpeta propia dentro de
 * uploads, con nombre aleatorio, bloqueo a nivel servidor y un único camino de
 * lectura: el endpoint que valida permiso antes de mandar un byte.
 *
 *   uploads/mj-comprobantes/2026/09/{40 hex}.jpg
 *   uploads/mj-comprobantes/.htaccess   (Apache: deny from all)
 *   uploads/mj-comprobantes/web.config  (IIS: deny)
 *   uploads/mj-comprobantes/index.php   (por si el servidor lista carpetas)
 *
 * En Nginx el .htaccess se ignora: ahí la defensa real es el nombre aleatorio
 * de 40 caracteres más que la única URL publicada sea la del endpoint.
 *
 * La validación es propia en lugar de wp_handle_upload() a propósito: así el
 * paso por MJ Optimizador de Imágenes es una decisión explícita del admin
 * (ajuste "Optimizar las imágenes") y no un efecto secundario de los filtros
 * que ese plugin engancha al flujo de subida de WordPress.
 */

defined('ABSPATH') || exit;

class MJPM_Vault {

	/** Carpeta dentro de wp-content/uploads. */
	const DIR = 'mj-comprobantes';

	/* ------------------------------- Tipos -------------------------------- */

	/** Todo lo que el plugin sabe manejar: extensión => mime real esperado. */
	public static function known_types() {
		return [
			'jpg'  => ['image/jpeg'],
			'jpeg' => ['image/jpeg'],
			'png'  => ['image/png'],
			'webp' => ['image/webp'],
			'gif'  => ['image/gif'],
			'heic' => ['image/heic', 'image/heif'],
			'heif' => ['image/heic', 'image/heif'],
			'pdf'  => ['application/pdf'],
		];
	}

	/** Etiquetas para la pantalla de ajustes. */
	public static function type_labels() {
		return [
			'jpg'  => 'JPG',
			'jpeg' => 'JPEG',
			'png'  => 'PNG',
			'webp' => 'WebP',
			'gif'  => 'GIF',
			'heic' => 'HEIC (iPhone)',
			'heif' => 'HEIF',
			'pdf'  => 'PDF',
		];
	}

	/** Extensiones habilitadas en los ajustes. */
	public static function allowed_types() {
		$raw   = (string) MJPM_Settings::receipts('types');
		$known = self::known_types();
		$out   = [];
		foreach (preg_split('/[^a-z0-9]+/', strtolower($raw)) as $ext) {
			if ('' !== $ext && isset($known[$ext])) {
				$out[] = $ext;
			}
		}
		return array_values(array_unique($out));
	}

	/** "jpg, png o pdf" para los textos de la interfaz. */
	public static function types_text() {
		$labels = self::type_labels();
		$names  = [];
		foreach (self::allowed_types() as $ext) {
			$names[] = isset($labels[$ext]) ? $labels[$ext] : strtoupper($ext);
		}
		if (!$names) {
			return '';
		}
		if (1 === count($names)) {
			return $names[0];
		}
		$last = array_pop($names);
		return implode(', ', $names) . ' o ' . $last;
	}

	/** accept="" para el input file. */
	public static function accept_attr() {
		$known = self::known_types();
		$out   = [];
		foreach (self::allowed_types() as $ext) {
			$out[] = '.' . $ext;
			foreach ($known[$ext] as $mime) {
				$out[] = $mime;
			}
		}
		return implode(',', array_unique($out));
	}

	/* ------------------------------ Tamaños ------------------------------- */

	/** Límite real del hosting (el menor entre upload_max_filesize y post_max_size). */
	public static function server_limit() {
		$limits = [];
		foreach (['upload_max_filesize', 'post_max_size'] as $key) {
			$value = wp_convert_hr_to_bytes((string) ini_get($key));
			if ($value > 0) {
				$limits[] = $value;
			}
		}
		return $limits ? min($limits) : 0;
	}

	/** Tamaño máximo efectivo por archivo: lo configurado, topado por el hosting. */
	public static function max_bytes() {
		$configured = max(1, (int) MJPM_Settings::receipts('max_mb')) * MB_IN_BYTES;
		$server     = self::server_limit();
		return $server > 0 ? min($configured, $server) : $configured;
	}

	public static function size_text($bytes = null) {
		$bytes = null === $bytes ? self::max_bytes() : (int) $bytes;
		return size_format($bytes, $bytes >= MB_IN_BYTES ? 0 : 1);
	}

	/* ------------------------------ Carpeta ------------------------------- */

	/** Raíz absoluta de la bóveda. */
	public static function root() {
		$uploads = wp_get_upload_dir();
		$base    = isset($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return untrailingslashit($base) . '/' . self::DIR;
	}

	/**
	 * Crea la carpeta y sus guardas. Idempotente: se puede llamar en cada subida.
	 *
	 * @return bool|WP_Error
	 */
	public static function ensure() {
		$root = self::root();
		if (!wp_mkdir_p($root)) {
			return new WP_Error('mjpm_vault_mkdir', 'No se pudo crear la carpeta de comprobantes. Revisá los permisos de wp-content/uploads.');
		}

		$guards = [
			'.htaccess'  => "# MJ Medios de Pago — los comprobantes solo se leen por el endpoint del plugin.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silencio.\n",
		];
		foreach ($guards as $name => $contents) {
			$file = $root . '/' . $name;
			if (!file_exists($file)) {
				@file_put_contents($file, $contents); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return true;
	}

	/** Ruta absoluta de un archivo guardado, o '' si la relativa no es válida. */
	public static function absolute($relative) {
		$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
		if ('' === $relative || false !== strpos($relative, '..') || !preg_match('#^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.[a-z0-9]{2,5}$#', $relative)) {
			return '';
		}
		return self::root() . '/' . $relative;
	}

	public static function exists($relative) {
		$path = self::absolute($relative);
		return '' !== $path && file_exists($path);
	}

	/* ------------------------------ Guardar ------------------------------- */

	/**
	 * Valida y guarda un archivo subido.
	 *
	 * @param array $file Entrada de $_FILES.
	 * @return array|WP_Error { path, name, mime, size, hash }
	 */
	public static function store($file) {
		if (!is_array($file) || !isset($file['tmp_name'])) {
			return new WP_Error('mjpm_no_file', 'No llegó ningún archivo.');
		}
		$error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if (UPLOAD_ERR_OK !== $error) {
			return new WP_Error('mjpm_upload_error', self::upload_error_text($error));
		}
		if (!is_uploaded_file($file['tmp_name'])) {
			return new WP_Error('mjpm_not_uploaded', 'El archivo no llegó por una subida válida.');
		}

		$size = (int) (isset($file['size']) ? $file['size'] : filesize($file['tmp_name']));
		if ($size <= 0) {
			return new WP_Error('mjpm_empty_file', 'El archivo llegó vacío. Probá de nuevo.');
		}
		$max = self::max_bytes();
		if ($size > $max) {
			return new WP_Error('mjpm_too_big', sprintf('El archivo pesa %s y el máximo es %s.', self::size_text($size), self::size_text($max)));
		}

		$original = isset($file['name']) ? (string) $file['name'] : 'archivo';
		$ext      = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
		$allowed  = self::allowed_types();
		if (!in_array($ext, $allowed, true)) {
			return new WP_Error('mjpm_bad_type', sprintf('Ese tipo de archivo no se acepta. Mandá %s.', self::types_text()));
		}

		/* Mime real del contenido, no el que declara el navegador. */
		$mime = self::sniff($file['tmp_name'], $ext);
		if ('' === $mime) {
			return new WP_Error('mjpm_bad_content', 'El archivo está dañado o no es del tipo que dice su nombre.');
		}

		$result = self::ensure();
		if (is_wp_error($result)) {
			return $result;
		}

		$dir = self::root() . '/' . gmdate('Y') . '/' . gmdate('m');
		if (!wp_mkdir_p($dir)) {
			return new WP_Error('mjpm_vault_mkdir', 'No se pudo crear la carpeta del mes en la bóveda de comprobantes.');
		}

		$name = '';
		do {
			$name = bin2hex(random_bytes(20)) . '.' . $ext;
		} while (file_exists($dir . '/' . $name));

		$target = $dir . '/' . $name;
		if (!@move_uploaded_file($file['tmp_name'], $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error('mjpm_move_failed', 'No se pudo guardar el archivo en el servidor.');
		}
		@chmod($target, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		/* HEIC de iPhone: lo pasamos a JPEG si el servidor puede, para que se vea en cualquier navegador. */
		if (in_array($ext, ['heic', 'heif'], true)) {
			$converted = self::heic_to_jpeg($target);
			if (is_wp_error($converted)) {
				@unlink($target); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $converted;
			}
			$target = $converted;
			$ext    = 'jpg';
			$mime   = 'image/jpeg';
		}

		/* Optimización: solo si el admin la pidió (ver class-mjpm-receipts.php). */
		self::maybe_optimize($target, $mime);

		clearstatcache(true, $target);

		return [
			'path' => self::relative($target),
			'name' => self::clean_name($original, $ext),
			'mime' => $mime,
			'size' => (int) filesize($target),
			'hash' => (string) hash_file('sha256', $target),
		];
	}

	/** Ruta relativa a la raíz de la bóveda. */
	private static function relative($absolute) {
		$root = trailingslashit(str_replace('\\', '/', self::root()));
		return ltrim(str_replace($root, '', str_replace('\\', '/', (string) $absolute)), '/');
	}

	/** Mime real del archivo, '' si no coincide con lo que promete la extensión. */
	private static function sniff($path, $ext) {
		$expected = self::known_types();
		$expected = isset($expected[$ext]) ? $expected[$ext] : [];
		$real     = '';

		if (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo) {
				$real = (string) finfo_file($finfo, $path);
				finfo_close($finfo);
			}
		}
		if ('' === $real && 'pdf' !== $ext) {
			$info = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$real = $info && !empty($info['mime']) ? (string) $info['mime'] : '';
		}
		if ('' === $real && 'pdf' === $ext) {
			/* Sin finfo: el PDF se reconoce por su cabecera. */
			$head = (string) file_get_contents($path, false, null, 0, 5); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$real = 0 === strpos($head, '%PDF-') ? 'application/pdf' : '';
		}
		if ('' === $real) {
			/* El servidor no sabe decirnos: confiamos en la extensión ya validada. */
			return $expected ? $expected[0] : '';
		}
		return in_array($real, $expected, true) ? $real : '';
	}

	/** Nombre original limpio, solo para mostrar (el archivo en disco tiene otro). */
	private static function clean_name($original, $ext) {
		$base = pathinfo((string) $original, PATHINFO_FILENAME);
		$base = sanitize_file_name((string) $base);
		$base = '' !== $base ? mb_substr($base, 0, 80) : 'comprobante';
		return $base . '.' . $ext;
	}

	/** HEIC/HEIF → JPEG. Devuelve la ruta nueva o un WP_Error explicando qué falta. */
	private static function heic_to_jpeg($path) {
		if (!class_exists('Imagick')) {
			return new WP_Error('mjpm_heic_unsupported', 'Ese archivo es HEIC (el formato del iPhone) y este servidor no puede abrirlo. En el iPhone: Ajustes → Cámara → Formatos → "Más compatible", o mandá el comprobante como JPG o PDF.');
		}
		try {
			$image = new Imagick();
			$image->readImage($path);
			$image->setImageFormat('jpeg');
			$image->setImageCompressionQuality(92);
			$target = preg_replace('/\.(heic|heif)$/i', '.jpg', $path);
			if ($target === $path) {
				$target = $path . '.jpg';
			}
			$image->writeImage($target);
			$image->clear();
			$image->destroy();
		} catch (Exception $e) {
			return new WP_Error('mjpm_heic_failed', 'No pudimos abrir ese archivo HEIC. Mandalo como JPG o PDF.');
		}
		if (!file_exists($target)) {
			return new WP_Error('mjpm_heic_failed', 'No pudimos convertir ese archivo HEIC. Mandalo como JPG o PDF.');
		}
		@unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $target;
	}

	/**
	 * Pasa la imagen por MJ Optimizador de Imágenes, si el admin lo habilitó.
	 *
	 * Nunca cambia el formato: un comprobante se guarda como llegó (un JPG sigue
	 * siendo JPG), solo se corrige la rotación y se recorta el tamaño según la
	 * configuración del optimizador. Si el plugin no está o falla, el archivo
	 * queda tal cual: la subida no se pierde por esto.
	 */
	private static function maybe_optimize($path, $mime) {
		if (!MJPM_Settings::receipts('optimize') || !self::optimizer_available()) {
			return;
		}
		if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
			return;
		}
		$args = [
			'convert_webp'  => false,
			'quality'       => 85,
			'max_dimension' => 0,
			'strip_meta'    => true,
		];
		if (class_exists('MJOI_Settings')) {
			$args['quality']       = max(50, min(100, (int) MJOI_Settings::get('quality')));
			$args['max_dimension'] = max(0, (int) MJOI_Settings::get('max_dimension'));
			$args['strip_meta']    = (bool) MJOI_Settings::get('strip_meta');
		}
		MJOI_Optimizer::optimize($path, $args);
	}

	/** ¿Está MJ Optimizador de Imágenes disponible para comprimir comprobantes? */
	public static function optimizer_available() {
		return class_exists('MJOI_Optimizer') && is_callable(['MJOI_Optimizer', 'optimize']);
	}

	/* ------------------------------ Leer ---------------------------------- */

	/** Borra el archivo (el registro en la base lo maneja MJPM_Receipts). */
	public static function delete($relative) {
		$path = self::absolute($relative);
		if ('' === $path || !file_exists($path)) {
			return false;
		}
		return (bool) @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Manda el archivo al navegador. Quien llama ya validó el permiso.
	 *
	 * @param string $relative Ruta relativa.
	 * @param string $name     Nombre con el que se descarga.
	 * @param string $mime     Tipo.
	 * @param bool   $download true = descargar, false = ver en el navegador.
	 * @return void
	 */
	public static function stream($relative, $name, $mime, $download = false) {
		$path = self::absolute($relative);
		if ('' === $path || !file_exists($path)) {
			status_header(404);
			nocache_headers();
			exit;
		}
		$name = '' !== (string) $name ? $name : basename($path);
		$mime = '' !== (string) $mime ? $mime : 'application/octet-stream';

		nocache_headers();
		header('Content-Type: ' . $mime);
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($name) . '"');
		header('X-Content-Type-Options: nosniff');
		header('X-Robots-Tag: noindex, nofollow');
		header('Referrer-Policy: no-referrer');
		if (function_exists('wp_ob_end_flush_all')) {
			wp_ob_end_flush_all();
		}
		readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ------------------------------ Textos -------------------------------- */

	public static function upload_error_text($code) {
		switch ((int) $code) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$server = self::server_limit();
				return $server > 0
					? sprintf('El archivo es más grande de lo que acepta el servidor (%s).', self::size_text($server))
					: 'El archivo es más grande de lo que acepta el servidor.';
			case UPLOAD_ERR_PARTIAL:
				return 'La subida se cortó por la mitad. Probá de nuevo.';
			case UPLOAD_ERR_NO_FILE:
				return 'No elegiste ningún archivo.';
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return 'El servidor no pudo guardar el archivo temporal. Avisale a quien administra el sitio.';
			case UPLOAD_ERR_EXTENSION:
				return 'Una extensión de PHP bloqueó la subida.';
		}
		return 'No se pudo subir el archivo. Probá de nuevo.';
	}

	/** ¿Es una imagen que el navegador puede mostrar como miniatura? */
	public static function is_viewable_image($mime) {
		return in_array((string) $mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
	}
}
