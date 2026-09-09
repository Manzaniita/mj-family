<?php
/**
 * Integración con el tema MJ Appearance (SPA React) y shortcode [mj_turnos].
 *
 *  - window.MJ.turnos (filtro mj_app_config): todo lo que la SPA necesita para
 *    el menú, la portada, la página de servicios y el wizard de reserva.
 *  - Rutas de la SPA: /{slug}, /{servicios} y /{slug}/t/{token} responden 200.
 *  - Secciones de la portada: 'services' y 'booking' (se ordenan/ocultan desde
 *    Apariencia → MJ Appearance).
 *  - Shortcode [mj_turnos servicio=""]: el mismo wizard, standalone, para
 *    cualquier otro tema (dist/app.js en modo 'booking').
 *
 * Sin el tema MJ Appearance los filtros mj_* no se disparan y no molestan.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Config pública (window.MJ.turnos y GET /config)
 * ---------------------------------------------------------------------- */

/** "09:00-13:00, 15:00-20:00" → [['09:00','13:00'], …] (usa el motor si está cargado). */
function mjto_frontend_ranges($string) {
	if (function_exists('mjto_parse_ranges')) {
		$ranges = mjto_parse_ranges($string);
		return is_array($ranges) ? array_values($ranges) : [];
	}
	$out = [];
	foreach (preg_split('/[,;\n]+/', (string) $string) as $part) {
		$part = trim($part);
		if (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $m)) {
			$out[] = [sprintf('%02d:%02d', (int) $m[1], (int) $m[2]), sprintf('%02d:%02d', (int) $m[3], (int) $m[4])];
		}
	}
	return $out;
}

/** URL "large" de un adjunto o ''. */
function mjto_frontend_image_url($id) {
	$id = absint($id);
	if (!$id) {
		return '';
	}
	$url = wp_get_attachment_image_url($id, 'large');
	return $url ? $url : '';
}

/** Configuración pública: mismo shape para window.MJ.turnos, GET /config y el shortcode. */
function mjto_public_config() {
	$o = mjto_get_options();

	$fields = [];
	foreach (mjto_form_fields() as $field) {
		if (empty($field['enabled'])) {
			continue;
		}
		$fields[] = [
			'key'         => $field['key'],
			'label'       => $field['label'],
			'placeholder' => $field['placeholder'],
			'type'        => $field['type'],
			'required'    => (bool) $field['required'],
			'options'     => array_values(array_map(fn ($opt) => ['value' => $opt['value'], 'label' => $opt['label']], (array) $field['options'])),
			'system'      => (bool) $field['system'],
		];
	}

	$open_days = [];
	foreach (mjto_weekly_hours() as $dow => $ranges) {
		$open_days[(string) $dow] = mjto_frontend_ranges($ranges);
	}

	return [
		'enabled'      => true,
		'slug'         => mjto_page_slug(),
		'servicesSlug' => mjto_services_slug(),
		'canManage'    => current_user_can(MJTO_CAP),
		'business'     => [
			'name'      => mjto_business_name(),
			'address'   => mjto_business_address(),
			'whatsapp'  => mjto_business_whatsapp(),
			'instagram' => mjto_business_instagram(),
		],
		'menu'         => [
			'label'               => (string) $o['menu_label'],
			'showInMenu'          => (bool) $o['show_in_menu'],
			'showBottomNav'       => (bool) $o['show_bottom_nav'],
			'headerCtaEnabled'    => (bool) $o['header_cta_enabled'],
			'headerCta'           => (string) $o['header_cta'],
			'servicesLabel'       => (string) $o['services_menu_label'],
			'servicesInMenu'      => (bool) $o['services_in_menu'],
			'servicesPageEnabled' => (bool) $o['services_page_enabled'],
		],
		'home'         => [
			'services' => [
				'enabled'  => (bool) $o['home_services_enabled'],
				'eyebrow'  => (string) $o['home_services_eyebrow'],
				'title'    => (string) $o['home_services_title'],
				'subtitle' => (string) $o['home_services_subtitle'],
				'link'     => (string) $o['home_services_link'],
				'count'    => max(1, (int) $o['home_services_count']),
			],
			'booking'  => [
				'enabled'  => (bool) $o['home_booking_enabled'],
				'eyebrow'  => (string) $o['home_booking_eyebrow'],
				'title'    => (string) $o['home_booking_title'],
				'subtitle' => (string) $o['home_booking_subtitle'],
				'button'   => (string) $o['home_booking_button'],
				'image'    => mjto_frontend_image_url($o['home_booking_image']),
			],
		],
		'copy'         => mjto_public_copy(),
		'form'         => [
			'fields' => $fields,
			'policy' => (string) $o['booking_policy'],
		],
		'services'     => mjto_services(true, true),
		'schedule'     => [
			'slotStep'       => (int) $o['slot_step'],
			'minNoticeHours' => (int) $o['min_notice_hours'],
			'maxDaysAhead'   => (int) $o['max_days_ahead'],
			'weekStart'      => (7 === (int) $o['week_start']) ? 7 : 1,
			'openDays'       => (object) $open_days,
		],
		'confirmMode'  => (string) $o['confirm_mode'],
		'deposit'      => [
			'type'         => (string) $o['deposit_type'],
			'amount'       => (float) $o['deposit_amount'],
			'percent'      => (float) $o['deposit_percent'],
			'instructions' => (string) $o['deposit_instructions'],
			'link'         => (string) $o['deposit_link'],
			'hours'        => (int) $o['deposit_hours'],
		],
		'cancel'       => [
			'allowed'     => (bool) $o['client_can_cancel'],
			'hoursBefore' => (int) $o['cancel_hours_before'],
		],
		'priceVisible' => (bool) $o['price_visible'],
		'currency'     => mjto_currency(),
		'restUrl'      => esc_url_raw(rest_url('mjto/v1/')),
		'homeUrl'      => home_url('/'),
		'loginUrl'     => wp_login_url(home_url('/' . mjto_page_slug())), // Para la dueña sin sesión: vuelve al panel.
	];
}

/* -------------------------------------------------------------------------
 * Tema MJ Appearance: window.MJ.turnos, rutas de la SPA y secciones de la portada
 * ---------------------------------------------------------------------- */

add_filter('mj_app_config', function ($config) {
	$config['turnos'] = mjto_public_config();
	return $config;
});

/* /{slug} y /{servicios} responden 200 (la SPA las renderiza). */
add_filter('mj_spa_routes', function ($routes) {
	$routes[] = mjto_page_slug();
	if ((int) mjto_opt('services_page_enabled')) {
		$routes[] = mjto_services_slug();
	}
	return array_values(array_unique($routes));
});

/* /{slug}/t/{token}: 200 solo si la reserva existe (si no, 404 real). */
add_filter('mj_spa_handles_path', function ($handles, $path, $parts) {
	if ($handles || 3 !== count($parts) || mjto_page_slug() !== $parts[0] || 't' !== $parts[1]) {
		return $handles;
	}
	return (bool) mjto_get_appointment_by_token($parts[2]);
}, 10, 3);

/*
 * Respaldo para /{slug}/t/{token}: el tema valida los paths de detalle con dos
 * partes antes de consultar el filtro, así que la ruta de tres partes puede
 * quedar en 404 aunque la SPA la renderice. Corre después del 200 del tema
 * (20) y solo con MJ Appearance activo y una reserva real.
 */
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || !is_404() || !function_exists('mj_spa_handles_path')) {
		return;
	}
	$parts = explode('/', mjto_request_path());
	if (3 !== count($parts) || mjto_page_slug() !== $parts[0] || 't' !== $parts[1] || !mjto_get_appointment_by_token($parts[2])) {
		return;
	}
	global $wp_query;
	$wp_query->is_404 = false;
	status_header(200);
}, 25);

/* Secciones de la portada que aporta el plugin (orden y visibilidad se manejan en el tema). */
add_filter('mj_home_sections', function ($sections) {
	$sections['services'] = 'Servicios (plugin MJ Turnos)';
	$sections['booking']  = 'Reservá tu turno (plugin MJ Turnos)';
	return $sections;
});

/* -------------------------------------------------------------------------
 * Shortcode [mj_turnos servicio=""] (widget standalone para cualquier tema)
 * ---------------------------------------------------------------------- */

/** window.MJTO en modo booking. */
function mjto_booking_boot($preselect = '') {
	return [
		'mode'      => 'booking',
		'restUrl'   => esc_url_raw(rest_url('mjto/v1/')),
		'nonce'     => wp_create_nonce('wp_rest'),
		'config'    => mjto_public_config(),
		'currency'  => mjto_currency(),
		'locale'    => str_replace('_', '-', get_locale()),
		'preselect' => (string) $preselect,
		'homeUrl'   => home_url('/'),
		'loginUrl'  => wp_login_url(home_url('/' . mjto_page_slug())),
		'siteName'  => (string) get_bloginfo('name'),
	];
}

/** Encola dist/app.css + dist/app.js (módulo) e imprime window.MJTO una sola vez. Devuelve false si falta el build. */
function mjto_enqueue_booking_assets($preselect = '') {
	static $printed = false;
	$js  = MJTO_DIR . 'dist/app.js';
	$css = MJTO_DIR . 'dist/app.css';
	if (!file_exists($js)) {
		return false;
	}
	if (file_exists($css)) {
		wp_enqueue_style('mjto-app', MJTO_URL . 'dist/app.css', [], (string) filemtime($css));
	}
	wp_enqueue_script('mjto-booking', MJTO_URL . 'dist/app.js', [], (string) filemtime($js), true);
	if (!$printed) {
		$printed = true;
		wp_add_inline_script('mjto-booking', 'window.MJTO = ' . wp_json_encode(mjto_booking_boot($preselect), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
	}
	return true;
}

/* El bundle de Vite es un módulo ES: type="module" solo en el <script> con src. */
add_filter('script_loader_tag', function ($tag, $handle) {
	if ('mjto-booking' !== $handle || false !== strpos($tag, 'type="module"')) {
		return $tag;
	}
	return preg_replace_callback('/<script\b([^>]*?)\s*src=/', function ($m) {
		$attrs = preg_replace('/\stype=(["\'])[^"\']*\1/', '', $m[1]);
		return '<script type="module"' . $attrs . ' src=';
	}, $tag);
}, 10, 2);

add_shortcode('mj_turnos', function ($atts) {
	$atts      = shortcode_atts(['servicio' => ''], (array) $atts, 'mj_turnos');
	$preselect = sanitize_title((string) $atts['servicio']);
	if ('' === $preselect && isset($_GET['servicio'])) { // phpcs:ignore WordPress.Security.NonceVerification -- solo preselecciona un servicio.
		$preselect = sanitize_title(wp_unslash($_GET['servicio']));
	}
	if (!mjto_enqueue_booking_assets($preselect)) {
		if (current_user_can('manage_options')) {
			return '<div class="mjto-booking mjto-booking--missing"><p>MJ Turnos Online: falta compilar la app (<code>dist/app.js</code>). En <code>app/</code> ejecutá <code>npm install</code> y <code>npm run build</code>.</p></div>';
		}
		return '';
	}
	return '<div class="mjto-booking" data-servicio="' . esc_attr($preselect) . '"></div>';
});
