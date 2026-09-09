<?php
/**
 * Sesión, nonces y barra de administración.
 *
 * La SPA inicia sesión por REST (mj/v1/auth/login) sin recargar la página.
 * Eso trae dos problemas que este módulo resuelve:
 *
 *   1. NONCES. `window.MJ.wpNonce` y `window.MJ.nonce` se generan para el
 *      visitante que pidió la página (anónimo). Un nonce de WordPress depende
 *      del ID de usuario Y del token de sesión, así que apenas se crea la
 *      cookie de login los dos quedan inválidos: el resto de la navegación
 *      (cuenta, pedidos, carrito) empieza a fallar con 401/403 y parece que
 *      "no recuerda la sesión". Acá se sincroniza $_COOKIE apenas WordPress
 *      emite la cookie, para poder devolverle a la SPA nonces ya válidos para
 *      la sesión nueva, y se expone GET /session para revalidarlos cuando haga
 *      falta.
 *
 *   2. BARRA DE ADMIN. `is_admin_bar_showing()` se evalúa cuando PHP arma la
 *      página; si la sesión se abre después por fetch, la barra no puede
 *      aparecer hasta la próxima carga. La SPA recarga tras iniciar sesión
 *      (ajustable) y acá se decide, de forma configurable, quién la ve.
 *
 * Nada de esto está fijo en el código: duración de la sesión, "recordarme",
 * recarga tras el login y visibilidad de la barra salen de los ajustes
 * (Apariencia → MJ Appearance → Sesión y acceso) y de filtros.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Capacidad para ver y guardar los ajustes del tema
 *
 * `edit_theme_options` es la capacidad estándar de las páginas que cuelgan de
 * Apariencia: es la que WordPress ya pide para mostrar ese menú. Usar
 * `manage_options` (más restrictiva) escondía la página en instalaciones donde
 * el dueño del sitio administra la apariencia pero no los ajustes generales
 * — por eso "MJ Appearance" no aparecía en algunas instalaciones.
 *
 * Se puede cambiar sin tocar el código con la constante MJ_SETTINGS_CAPABILITY
 * (wp-config.php) o con el filtro `mj_settings_capability`.
 * ---------------------------------------------------------------------- */
function mj_settings_capability() {
	$capability = defined('MJ_SETTINGS_CAPABILITY') && is_string(MJ_SETTINGS_CAPABILITY) && '' !== MJ_SETTINGS_CAPABILITY
		? MJ_SETTINGS_CAPABILITY
		: 'edit_theme_options';
	$capability = (string) apply_filters('mj_settings_capability', $capability);
	return '' !== $capability ? $capability : 'edit_theme_options';
}

/* -------------------------------------------------------------------------
 * Identidad de la página de ajustes
 *
 * Slug y títulos salen de filtros para que un tema hijo o una instalación
 * puedan renombrar la página ("Apariencia → Mi Salón", por ejemplo) sin tocar
 * el código del padre.
 * ---------------------------------------------------------------------- */
function mj_settings_page_slug() {
	$slug = sanitize_key((string) apply_filters('mj_settings_page_slug', 'mj-settings'));
	return '' !== $slug ? $slug : 'mj-settings';
}

function mj_settings_menu_title() {
	return (string) apply_filters('mj_settings_menu_title', 'MJ Appearance');
}

function mj_settings_page_title() {
	return (string) apply_filters(
		'mj_settings_page_title',
		mj_settings_menu_title() . ' — Ajustes del tema'
	);
}

/* URL de la página de ajustes (para enlaces desde avisos, la barra, etc.). */
function mj_settings_page_url() {
	return admin_url('themes.php?page=' . mj_settings_page_slug());
}

/* -------------------------------------------------------------------------
 * Duración de la sesión
 *
 * WordPress usa 2 días (48 h) y 14 días con "Recordarme". Los dos valores son
 * configurables en días; 0 o vacío = el default de WordPress.
 * ---------------------------------------------------------------------- */

/* Días configurados para cada tipo de sesión (0 = default de WordPress). */
function mj_session_days($remember = false) {
	$options = mj_get_options();
	$days    = $remember ? (int) $options['session_days_remember'] : (int) $options['session_days'];
	$days    = (int) apply_filters('mj_session_days', $days, $remember);
	return max(0, $days);
}

add_filter('auth_cookie_expiration', function ($length, $user_id, $remember) {
	$days = mj_session_days((bool) $remember);
	return $days > 0 ? $days * DAY_IN_SECONDS : $length;
}, 10, 3);

/* -------------------------------------------------------------------------
 * Sincronización de $_COOKIE
 *
 * `wp_set_auth_cookie()` manda las cookies por cabecera pero NO toca
 * $_COOKIE, así que durante el mismo request `wp_get_session_token()` sigue
 * leyendo el token viejo (o ninguno) y cualquier nonce que generemos después
 * del login nace inválido. Espejar la cookie acá es lo que permite devolverle
 * a la SPA nonces que ya sirven para la sesión recién abierta.
 * ---------------------------------------------------------------------- */
add_action('set_logged_in_cookie', function ($logged_in_cookie) {
	$_COOKIE[LOGGED_IN_COOKIE] = $logged_in_cookie;
});

add_action('set_auth_cookie', function ($auth_cookie, $expire, $expiration, $user_id, $scheme) {
	$_COOKIE[is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE] = $auth_cookie;
}, 10, 5);

add_action('clear_auth_cookie', function () {
	unset($_COOKIE[LOGGED_IN_COOKIE], $_COOKIE[AUTH_COOKIE], $_COOKIE[SECURE_AUTH_COOKIE]);
});

/* -------------------------------------------------------------------------
 * Nonces frescos para la SPA
 *
 * `nonce` es el del carrito/checkout (Store API de WooCommerce) y `wpNonce`
 * el de los endpoints propios (mj/v1). Se generan siempre juntos: los dos
 * dependen de la sesión, así que los dos caducan al iniciar o cerrar sesión.
 * ---------------------------------------------------------------------- */
function mj_session_nonces() {
	return apply_filters('mj_session_nonces', [
		'nonce'   => wp_create_nonce('wc_store_api'),
		'wpNonce' => wp_create_nonce('wp_rest'),
	]);
}

/* Estado de sesión que consume la SPA: usuario, nonces y si va la barra. */
function mj_session_payload() {
	$user = null;
	if (is_user_logged_in()) {
		$current = wp_get_current_user();
		$user    = [
			'id'    => $current->ID,
			'name'  => $current->display_name,
			'email' => $current->user_email,
		];
	}

	return apply_filters('mj_session_payload', array_merge(
		['user' => $user, 'adminBar' => mj_admin_bar_visible()],
		mj_session_nonces()
	));
}

/* -------------------------------------------------------------------------
 * Barra de administración
 *
 * Modos (Apariencia → MJ Appearance → Sesión y acceso):
 *   profile — respeta "Mostrar la barra de herramientas" del perfil de cada
 *             usuario (comportamiento de WordPress; es el default).
 *   admins  — solo quien pueda administrar el tema.
 *   all     — todos los usuarios con sesión iniciada.
 *   off     — nunca.
 * ---------------------------------------------------------------------- */

/* Capacidad que separa "admin" del resto para el modo `admins`. */
function mj_admin_bar_capability() {
	return (string) apply_filters('mj_admin_bar_capability', mj_settings_capability());
}

function mj_admin_bar_mode() {
	$mode = (string) mj_get_options()['admin_bar'];
	return isset(mj_admin_bar_modes()[$mode]) ? $mode : 'profile';
}

/*
 * Prioridad 20: corre después de los filtros de los plugins MJ que apagan la
 * barra en sus propias pantallas (p. ej. el portal de MJ ERP), así esos
 * apagados siguen ganando cuando el modo es "profile".
 */
add_filter('show_admin_bar', function ($show) {
	if (!is_user_logged_in()) {
		return false;
	}
	switch (mj_admin_bar_mode()) {
		case 'off':
			return false;
		case 'all':
			return true;
		case 'admins':
			return current_user_can(mj_admin_bar_capability()) ? true : false;
		case 'profile':
		default:
			return $show;
	}
}, 20);

/* ¿Se va a pintar la barra en esta carga? (para el <body> y el offset del CSS) */
function mj_admin_bar_visible() {
	return function_exists('is_admin_bar_showing') ? (bool) is_admin_bar_showing() : false;
}

/*
 * La barra de WordPress es fija arriba de todo y empuja <html> con un
 * margin-top; el header de la SPA es sticky top:0 y quedaría tapado. Este CSS
 * lo baja exactamente la altura de la barra en cada breakpoint (32 px en
 * escritorio, 46 px en tablet; abajo de 600 px WordPress la vuelve absolute y
 * se va con el scroll, así que ahí no hace falta offset).
 */
function mj_admin_bar_css() {
	$css = 'body.admin-bar .mj-header{top:32px;}'
		. '@media screen and (max-width:782px){body.admin-bar .mj-header{top:46px;}}'
		. '@media screen and (max-width:600px){body.admin-bar .mj-header{top:0;}}';
	return (string) apply_filters('mj_admin_bar_css', $css);
}

/* -------------------------------------------------------------------------
 * Rutas de la SPA usadas desde PHP
 * ---------------------------------------------------------------------- */

/* Ruta de la SPA para la tienda (sin dominio, con barra inicial). */
function mj_shop_path() {
	$path = (string) apply_filters('mj_shop_path', '/tienda');
	return '/' . ltrim('' !== trim($path) ? trim($path) : '/tienda', '/');
}

/* Ruta de la SPA para "mi cuenta" (sin dominio, con barra inicial). */
function mj_account_path() {
	$path = (string) mj_get_options()['account_path'];
	$path = '' !== trim($path) ? trim($path) : '/cuenta';
	return '/' . ltrim((string) apply_filters('mj_account_path', $path), '/');
}
