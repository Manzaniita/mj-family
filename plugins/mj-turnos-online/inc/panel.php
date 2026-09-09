<?php
/**
 * El panel de la dueña.
 *
 * Vive en la misma URL que la reserva pública (/{page_slug}, default /turnos):
 * si entra alguien con la capability mjto_manage, en template_redirect
 * (prioridad 1, antes que el tema) se imprime una página propia con la app
 * React del plugin (dist/app.js en modo 'panel') y se corta. Para cualquier
 * otra persona no se interviene: sigue la SPA del tema o la página con el
 * shortcode. Con ?vista=publica la administradora ve la reserva como clienta,
 * y /{slug}/t/{token} (estado de una reserva) es siempre público.
 *
 * También acá: el export CSV (admin-post.php?action=mjto_export) con nonce y
 * capability, separador ";" y BOM para que Excel en español lo abra bien.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Intercepción de /{slug}
 * ---------------------------------------------------------------------- */

/** Path pedido, relativo a la home (soporta WordPress en subcarpeta). Copia independiente de mj_request_path(). */
function mjto_request_path() {
	$uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se parsea y recorta abajo.
	$path = (string) wp_parse_url($uri, PHP_URL_PATH);
	$home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
	if ('' !== $home && '/' !== $home && 0 === strpos($path, $home)) {
		$path = substr($path, strlen($home));
	}
	return trim(rawurldecode($path), '/');
}

/** ¿El path es la página de turnos o una subruta del panel? (/{slug}/t/… es público y no cuenta). */
function mjto_is_panel_path($path) {
	$slug = mjto_page_slug();
	$path = trim((string) $path, '/');
	if ($path !== $slug && 0 !== strpos($path, $slug . '/')) {
		return false;
	}
	if (0 === strpos($path, $slug . '/t/')) {
		return false;
	}
	return true;
}

/*
 * Prioridad 1: antes de redirect_canonical (10), del 200 del tema (20) y de
 * template_include. Solo para administradoras con sesión; el resto no se toca.
 */
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
		return;
	}
	if (isset($_GET['vista'])) { // phpcs:ignore WordPress.Security.NonceVerification -- solo decide qué vista mostrar.
		return;
	}
	if (!mjto_is_panel_path(mjto_request_path())) {
		return;
	}
	if (!is_user_logged_in() || !current_user_can(MJTO_CAP)) {
		return;
	}
	global $wp_query;
	if ($wp_query) {
		$wp_query->is_404 = false;
	}
	status_header(200);
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow', true);
	mjto_render_panel();
	exit;
}, 1);

/* -------------------------------------------------------------------------
 * Tema visual del panel (colores, fuentes y logo del tema MJ Appearance si está)
 * ---------------------------------------------------------------------- */

/** 'light' | 'dark' según panel_theme (con 'theme' sigue design_theme del tema; sin tema, claro). */
function mjto_panel_theme_mode() {
	$mode = (string) mjto_opt('panel_theme');
	if ('light' === $mode || 'dark' === $mode) {
		return $mode;
	}
	if (function_exists('mj_theme_default')) {
		return 'dark' === mj_theme_default() ? 'dark' : 'light';
	}
	if (function_exists('mj_get_options')) {
		$theme = mj_get_options();
		return ('dark' === (string) ($theme['design_theme'] ?? '')) ? 'dark' : 'light';
	}
	return 'light';
}

/** Familia de fuente válida para CSS/Google Fonts (letras, números, espacios) o ''. */
function mjto_panel_font_family($family) {
	$family = trim((string) $family);
	return preg_match('/^[A-Za-z0-9 ]{2,60}$/', $family) ? $family : '';
}

/** {mode, accent, bg, ink, fontDisplay, fontSans, logo} para window.MJTO.theme y el <style> inicial. */
function mjto_panel_theme() {
	$mode  = mjto_panel_theme_mode();
	$theme = function_exists('mj_get_options') ? mj_get_options() : [];
	$base  = function_exists('mj_color_defaults') ? mj_color_defaults() : [];
	$pick  = function ($key, $fallback) use ($theme, $base) {
		$hex = sanitize_hex_color((string) ($theme[$key] ?? ''));
		if (!$hex) {
			$hex = sanitize_hex_color((string) ($base[$key] ?? ''));
		}
		return $hex ? $hex : $fallback;
	};

	if ('dark' === $mode) {
		$bg  = $pick('color_bg_dark', '#150a10');
		$ink = $pick('color_ink_dark', '#fdf4f8');
	} else {
		$bg  = $pick('color_bg_light', '#ffffff');
		$ink = $pick('color_ink_light', '#1c0f16');
	}

	$display = mjto_panel_font_family($theme['font_display'] ?? '');
	$sans    = mjto_panel_font_family($theme['font_sans'] ?? '');
	if ('' === $sans) {
		$sans = 'Manrope';
	}
	if ('' === $display) {
		$display = $sans;
	}

	$logo = '';
	if (function_exists('mj_logo_config')) {
		$cfg  = mj_logo_config();
		$logo = (string) ($cfg['url'] ?? '');
		if ('dark' === $mode && !empty($cfg['dark'])) {
			$logo = (string) $cfg['dark'];
		}
	}

	return [
		'mode'        => $mode,
		'accent'      => mjto_panel_accent(),
		'bg'          => $bg,
		'ink'         => $ink,
		'fontDisplay' => $display,
		'fontSans'    => $sans,
		'logo'        => $logo,
	];
}

/** Valor para el <style> inicial: solo hex, familias validadas y separadores (sin escapes HTML, que romperían las comillas). */
function mjto_panel_css_value($value) {
	return preg_replace("/[^A-Za-z0-9#,' \-]/", '', (string) $value);
}

/** URL de Google Fonts con las familias del panel (pesos 400–800). */
function mjto_panel_fonts_url(array $theme) {
	$families = array_values(array_unique(array_filter([$theme['fontSans'], $theme['fontDisplay']])));
	if (!$families) {
		return '';
	}
	$parts = [];
	foreach ($families as $family) {
		$parts[] = 'family=' . str_replace(' ', '+', $family) . ':wght@400;500;600;700;800';
	}
	return 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
}

/** basePath del router del panel: '/turnos' o '/subcarpeta/turnos'. */
function mjto_panel_base_path() {
	$home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
	return $home . '/' . mjto_page_slug();
}

/** window.MJTO en modo panel. */
function mjto_panel_boot() {
	$user = wp_get_current_user();
	return [
		'mode'      => 'panel',
		'restUrl'   => esc_url_raw(rest_url('mjto/v1/')),
		'nonce'     => wp_create_nonce('wp_rest'),
		'basePath'  => mjto_panel_base_path(),
		'homeUrl'   => home_url('/'),
		'siteName'  => (string) get_bloginfo('name'),
		'business'  => [
			'name'      => mjto_business_name(),
			'address'   => mjto_business_address(),
			'whatsapp'  => mjto_business_whatsapp(),
			'instagram' => mjto_business_instagram(),
		],
		'currency'  => mjto_currency(),
		'locale'    => str_replace('_', '-', get_locale()),
		'theme'     => mjto_panel_theme(),
		'user'      => [
			'id'   => (int) $user->ID,
			'name' => (string) $user->display_name,
		],
		'urls'      => mjto_panel_urls(),
		'version'   => MJTO_VERSION,
		'timezone'  => wp_timezone_string(),
		'bootstrap' => mjto_panel_bootstrap(),
	];
}

/* -------------------------------------------------------------------------
 * HTML del panel
 * ---------------------------------------------------------------------- */

/** Imprime la página del panel (HTML propio, sin get_header) y no vuelve. */
function mjto_render_panel() {
	$theme   = mjto_panel_theme();
	$title   = mjto_panel_title();
	$js      = MJTO_DIR . 'dist/app.js';
	$css     = MJTO_DIR . 'dist/app.css';
	$has_js  = file_exists($js);
	$has_css = file_exists($css);
	$fonts   = mjto_panel_fonts_url($theme);
	$icon    = function_exists('get_site_icon_url') ? get_site_icon_url(64) : '';
	$boot    = wp_json_encode(mjto_panel_boot(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
	$display = "'" . $theme['fontDisplay'] . "', ui-sans-serif, system-ui, sans-serif";
	$sans    = "'" . $theme['fontSans'] . "', ui-sans-serif, system-ui, sans-serif";
	?>
<!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>" data-theme="<?php echo esc_attr($theme['mode']); ?>" class="mjto-page" style="color-scheme: <?php echo esc_attr($theme['mode']); ?>;">
<head>
	<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="<?php echo esc_attr($theme['bg']); ?>">
	<title><?php echo esc_html($title); ?> — Turnos</title>
	<?php if ($icon) : ?>
	<link rel="icon" href="<?php echo esc_url($icon); ?>">
	<?php endif; ?>
	<?php if ('' !== $fonts) : ?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="<?php echo esc_url($fonts); ?>">
	<?php endif; ?>
	<?php if ($has_css) : ?>
	<link rel="stylesheet" href="<?php echo esc_url(MJTO_URL . 'dist/app.css?ver=' . filemtime($css)); ?>">
	<?php endif; ?>
	<style>
		:root{--mjto-accent:<?php echo mjto_panel_css_value($theme['accent']); // phpcs:ignore WordPress.Security.EscapeOutput -- hex validado. ?>;--mjto-bg:<?php echo mjto_panel_css_value($theme['bg']); // phpcs:ignore WordPress.Security.EscapeOutput ?>;--mjto-ink:<?php echo mjto_panel_css_value($theme['ink']); // phpcs:ignore WordPress.Security.EscapeOutput ?>;--mjto-font-display:<?php echo mjto_panel_css_value($display); // phpcs:ignore WordPress.Security.EscapeOutput -- familia validada por regex. ?>;--mjto-font-sans:<?php echo mjto_panel_css_value($sans); // phpcs:ignore WordPress.Security.EscapeOutput ?>;}
		html,body{margin:0;min-height:100%;background:var(--mjto-bg);color:var(--mjto-ink);font-family:var(--mjto-font-sans);-webkit-font-smoothing:antialiased;}
		.mjto-boot{display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;text-align:center;font-size:15px;opacity:.7;}
		.mjto-missing{max-width:560px;margin:15vh auto 0;padding:28px 24px;border:1px solid rgb(0 0 0 / .12);border-radius:20px;background:rgb(255 255 255 / .6);text-align:left;font-size:15px;line-height:1.55;}
		.mjto-missing h1{margin:0 0 10px;font-family:var(--mjto-font-display);font-size:22px;}
		.mjto-missing code{padding:2px 6px;border-radius:6px;background:rgb(0 0 0 / .08);font-size:13px;}
		.mjto-missing a{color:var(--mjto-accent);}
	</style>
	<script>window.MJTO = <?php echo $boot; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON con JSON_HEX_TAG/JSON_HEX_AMP. ?>;</script>
</head>
<body class="mjto-page">
	<?php if ($has_js) : ?>
	<div id="mjto-panel"><div class="mjto-boot">Cargando el panel…</div></div>
	<noscript><div class="mjto-missing"><h1>Hace falta JavaScript</h1><p>El panel de turnos necesita JavaScript habilitado en el navegador.</p></div></noscript>
	<script type="module" src="<?php echo esc_url(MJTO_URL . 'dist/app.js?ver=' . filemtime($js)); ?>"></script>
	<?php else : ?>
	<div class="mjto-missing">
		<h1>Falta compilar el panel</h1>
		<p>No se encontró <code>dist/app.js</code> en el plugin MJ Turnos Online. En la carpeta <code>app/</code> del plugin ejecutá <code>npm install</code> y <code>npm run build</code> (app/ → npm run build) y volvé a subir el plugin.</p>
		<p><a href="<?php echo esc_url(admin_url('admin.php?page=mjto')); ?>">Ir a Turnos → Ajustes</a> · <a href="<?php echo esc_url(add_query_arg('vista', 'publica', mjto_panel_url())); ?>">Ver la reserva pública</a></p>
	</div>
	<?php endif; ?>
</body>
</html>
	<?php
}

/* -------------------------------------------------------------------------
 * Export CSV: admin-post.php?action=mjto_export&what=clients|visits|appointments&from=&to=&_wpnonce=
 * ---------------------------------------------------------------------- */
add_action('admin_post_mjto_export', 'mjto_export_csv');

/** Celda segura para CSV: evita que Excel interprete fórmulas (=, +, -, @). */
function mjto_csv_cell($value) {
	if (is_bool($value)) {
		return $value ? 'Sí' : 'No';
	}
	if (null === $value) {
		return '';
	}
	if (is_array($value)) {
		$value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
	}
	$value = (string) $value;
	if ('' !== $value && !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
		$value = "'" . $value;
	}
	return $value;
}

/** Monto con coma decimal (Excel en español) y sin separador de miles. */
function mjto_csv_money($amount) {
	return number_format((float) $amount, (int) mjto_currency()['decimals'], ',', '');
}

/** Valor de un campo de la ficha → texto legible (etiquetas de las opciones, Sí/No, etc.). */
function mjto_csv_ficha_value(array $field, $value) {
	$labels = [];
	foreach ((array) ($field['options'] ?? []) as $opt) {
		$labels[(string) $opt['value']] = (string) $opt['label'];
	}
	switch ((string) $field['type']) {
		case 'checkbox_group':
			$out = [];
			foreach ((array) $value as $v) {
				$v     = (string) (is_scalar($v) ? $v : '');
				$out[] = $labels[$v] ?? $v;
			}
			return implode(', ', array_filter($out));
		case 'radio':
		case 'select':
			$v = (string) (is_scalar($value) ? $value : '');
			return $labels[$v] ?? $v;
		case 'toggle':
			return (null === $value || '' === $value) ? '' : (!empty($value) ? 'Sí' : 'No');
		case 'money':
			return ('' === $value || null === $value) ? '' : mjto_csv_money($value);
		case 'number':
			return ('' === $value || null === $value) ? '' : str_replace('.', ',', (string) (0 + (float) str_replace(',', '.', (string) $value)));
		default:
			return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) (is_scalar($value) ? $value : '');
	}
}

function mjto_export_csv() {
	if (!is_user_logged_in() || !current_user_can(MJTO_CAP)) {
		wp_die('No tenés permiso para exportar.', 'MJ Turnos Online', ['response' => 403]);
	}
	$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
	if (!wp_verify_nonce($nonce, 'mjto_export')) {
		wp_die('El enlace de exportación venció. Volvé al panel y probá de nuevo.', 'MJ Turnos Online', ['response' => 403]);
	}
	$what = isset($_GET['what']) ? sanitize_key(wp_unslash($_GET['what'])) : '';
	if (!in_array($what, ['clients', 'visits', 'appointments'], true)) {
		wp_die('Indicá qué exportar: clients, visits o appointments.', 'MJ Turnos Online', ['response' => 400]);
	}
	$from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
	$to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
	$from = mjto_valid_date($from) ? $from : '';
	$to   = mjto_valid_date($to) ? $to : '';
	if ('' !== $from && '' !== $to && $to < $from) {
		[$from, $to] = [$to, $from];
	}

	$name_from = '' !== $from ? $from : 'inicio';
	$name_to   = '' !== $to ? $to : mjto_today();
	$filename  = 'turnos-' . $what . '-' . $name_from . '-' . $name_to . '.csv';

	nocache_headers();
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('X-Content-Type-Options: nosniff');

	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel reconoce UTF-8.
	$put = function (array $row) use ($out) {
		fputcsv($out, array_map('mjto_csv_cell', $row), ';', '"', '\\');
	};

	switch ($what) {
		case 'clients':
			mjto_export_clients_rows($put, $from, $to);
			break;
		case 'visits':
			mjto_export_visits_rows($put, $from, $to);
			break;
		default:
			mjto_export_appointments_rows($put, $from, $to);
	}
	fclose($out);
	exit;
}

/** Clientas: una fila por clienta (+ una columna por campo custom del formulario). */
function mjto_export_clients_rows(callable $put, $from, $to) {
	$custom = array_values(array_filter(mjto_form_fields(), fn ($f) => empty($f['system'])));
	$header = ['ID', 'Nombre', 'Apellido', 'Teléfono', 'Teléfono (como lo escribió)', 'WhatsApp', 'Email', 'Localidad', 'Edad', 'Notas internas', 'Visitas', 'Última visita', 'Próximo turno', 'Estado del próximo turno', 'Alta'];
	foreach ($custom as $field) {
		$header[] = $field['label'];
	}
	$put($header);

	/* Con año/mes se filtran las que tuvieron actividad en ese período (mismo criterio que el panel). */
	$year  = 0;
	$month = 0;
	if ('' !== $from) {
		$year = (int) substr($from, 0, 4);
		if ('' !== $to && substr($from, 0, 7) === substr($to, 0, 7)) {
			$month = (int) substr($from, 5, 2);
		} elseif ('' !== $to && substr($to, 0, 4) !== substr($from, 0, 4)) {
			$year = 0; // Rango que cruza años: se exporta todo.
		}
	}

	$page = 1;
	do {
		$result = mjto_search_clients(['year' => $year, 'month' => $month, 'order' => 'name_asc', 'page' => $page, 'per_page' => 500]);
		foreach ($result['items'] as $row) {
			$extra = mjto_json_to_array($row['extra'] ?? '');
			$next  = is_array($row['next_appointment'] ?? null) ? $row['next_appointment'] : null;
			$line  = [
				(int) $row['id'],
				$row['first_name'],
				$row['last_name'],
				$row['phone'],
				$row['phone_raw'],
				mjto_whatsapp_number($row['phone']),
				$row['email'],
				$row['locality'],
				('' === (string) $row['age'] || null === $row['age']) ? '' : (int) $row['age'],
				(string) $row['notes'],
				(int) $row['visits_count'],
				(string) ($row['last_visit'] ?? ''),
				$next ? (string) $next['starts_at'] : '',
				$next ? mjto_status_label($next['status']) : '',
				(string) $row['created_at'],
			];
			foreach ($custom as $field) {
				$line[] = $extra[$field['key']] ?? '';
			}
			$put($line);
		}
		$page++;
	} while (count($result['items']) === 500 && $page <= 200);
}

/** Visitas: una fila por visita, con una columna por campo de la ficha. */
function mjto_export_visits_rows(callable $put, $from, $to) {
	if ('' === $from) {
		$from = '2000-01-01';
	}
	if ('' === $to) {
		$to = (new DateTimeImmutable(mjto_today(), wp_timezone()))->modify('+1 year')->format('Y-m-d');
	}
	$fields = [];
	foreach (mjto_ficha_blocks() as $block) {
		foreach ($block['fields'] as $field) {
			$fields[] = $field;
		}
	}
	$header = ['ID', 'Fecha', 'Clienta', 'Teléfono', 'Servicio', 'Total', 'Seña', 'Método de pago', 'Control', 'Retoque', 'Advertencias', 'ID turno'];
	foreach ($fields as $field) {
		$header[] = $field['label'];
	}
	$put($header);

	$phones = [];
	foreach (mjto_visits_between($from, $to) as $row) {
		$client_id = (int) $row['client_id'];
		if (!array_key_exists($client_id, $phones)) {
			$client             = mjto_get_client($client_id);
			$phones[$client_id] = $client ? (string) $client['phone_raw'] : '';
		}
		$data     = mjto_json_to_array($row['data'] ?? '');
		$warnings = [];
		foreach (mjto_json_to_array($row['warnings'] ?? '') as $w) {
			if (is_array($w)) {
				$warnings[] = (string) ($w['text'] ?? trim(($w['label'] ?? '') . ': ' . ($w['option'] ?? '')));
			}
		}
		$line = [
			(int) $row['id'],
			(string) $row['visited_at'],
			mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? ''),
			$phones[$client_id],
			(string) $row['service_name'],
			mjto_csv_money($row['total']),
			mjto_csv_money($row['deposit']),
			(string) $row['payment_method'],
			(string) ($row['control_date'] ?? ''),
			(string) ($row['retouch_date'] ?? ''),
			implode(' | ', array_filter($warnings)),
			!empty($row['appointment_id']) ? (int) $row['appointment_id'] : '',
		];
		foreach ($fields as $field) {
			$line[] = array_key_exists($field['key'], $data) ? mjto_csv_ficha_value($field, $data[$field['key']]) : '';
		}
		$put($line);
	}
}

/** Turnos: una fila por turno del rango (por fecha de inicio). */
function mjto_export_appointments_rows(callable $put, $from, $to) {
	$put(['ID', 'Fecha', 'Hora', 'Hasta', 'Duración (min)', 'Clienta', 'Teléfono', 'Email', 'Localidad', 'Servicio', 'Estado', 'Origen', 'Precio', 'Seña requerida', 'Seña pagada', 'Método de seña', 'Avisó la seña', 'Vence la seña', 'Notas de la clienta', 'Notas internas', 'Cancelado el', 'Motivo', 'Cancelado por', 'Recordatorio enviado', 'Creado']);
	$page = 1;
	do {
		$result = mjto_query_appointments(['from' => $from, 'to' => $to, 'order' => 'asc', 'page' => $page, 'per_page' => 500]);
		foreach ($result['items'] as $row) {
			$put([
				(int) $row['id'],
				substr((string) $row['starts_at'], 0, 10),
				substr((string) $row['starts_at'], 11, 5),
				substr((string) $row['ends_at'], 11, 5),
				(int) $row['duration_min'],
				mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? ''),
				(string) ($row['client_phone_raw'] ?? ''),
				(string) ($row['client_email'] ?? ''),
				(string) ($row['client_locality'] ?? ''),
				(string) $row['service_name'],
				mjto_status_label($row['status']),
				'panel' === (string) $row['source'] ? 'Panel' : 'Web',
				mjto_csv_money($row['price']),
				mjto_csv_money($row['deposit_required']),
				mjto_csv_money($row['deposit_paid']),
				(string) $row['deposit_method'],
				(string) (mjto_dt_or_null($row['deposit_notified_at'] ?? '') ?? ''),
				(string) (mjto_dt_or_null($row['deposit_deadline'] ?? '') ?? ''),
				(string) $row['client_notes'],
				(string) $row['internal_notes'],
				(string) (mjto_dt_or_null($row['cancelled_at'] ?? '') ?? ''),
				(string) $row['cancel_reason'],
				(string) $row['cancelled_by'],
				(string) (mjto_dt_or_null($row['reminder_sent_at'] ?? '') ?? ''),
				(string) $row['created_at'],
			]);
		}
		$page++;
	} while (count($result['items']) === 500 && $page <= 200);
}
