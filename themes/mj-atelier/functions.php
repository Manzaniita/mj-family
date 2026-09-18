<?php
/**
 * MJ Atelier — tema hijo (skin editorial de lujo de MJ Appearance).
 *
 * No toca PHP del padre ni el build de React. Se limita a DESCRIBIRSE con el
 * filtro `mj_skins` del padre (ver inc/skins.php): paleta, tipografías y
 * preferencias de arranque. El padre se ocupa del resto — proponer esos
 * valores donde el admin no eligió nada, mostrarlos en Apariencia → MJ
 * Appearance → Skin y dejar aplicarlos con un botón para que queden editables
 * campo por campo.
 *
 * Lo único que hace por su cuenta es lógica, no configuración:
 *   1. declarar la skin (filtro mj_skin → <html data-skin="atelier">),
 *   2. aclarar el acento para el modo noche (bronce sobre carbón), a partir
 *      del color que el admin haya elegido,
 *   3. encolar atelier.css después del CSS del padre.
 */

defined('ABSPATH') || exit;

define('MJ_ATELIER_VERSION', '1.1.0');

/* Versión mínima del tema padre (la que trae la API de skins). */
define('MJ_ATELIER_REQUIRES', '3.19.0');

/* Identidad de la skin: <html data-skin="atelier"> y window.MJ.skin. */
add_filter('mj_skin', fn () => 'atelier');

/* El padre avisa en wp-admin si está instalada una versión más vieja. */
add_filter('mj_skin_requires', fn () => MJ_ATELIER_REQUIRES);

/* -------------------------------------------------------------------------
 * Descripción de la skin
 *
 * Todo lo de acá son PROPUESTAS: el padre las usa sólo donde el admin no
 * configuró nada, y cualquier valor escrito en Apariencia → MJ Appearance
 * les gana. Con el ajuste "Propuestas de la skin" desmarcado no se aplica
 * ninguna y queda sólo el CSS.
 * ---------------------------------------------------------------------- */
add_filter('mj_skins', function ($skins) {
	$skins['atelier'] = [
		'name' => 'MJ Atelier',

		/* Marfil papel de día, carbón cálido de noche, acento bronce. */
		'palette' => [
			'color_accent'    => '#8b5e34', // bronce
			'color_bg_dark'   => '#141210', // carbón cálido
			'color_ink_dark'  => '#efe8de', // marfil
			'color_bg_light'  => '#f7f3ee', // marfil papel
			'color_ink_light' => '#1c1917', // tinta cálida
		],

		'fonts' => [
			'font_display' => 'Fraunces',
			'font_sans'    => 'Manrope',
		],

		/*
		 * Fraunces necesita itálicas y el eje óptico (opsz) para el título del
		 * hero; el padre sólo pide pesos.
		 */
		'fonts_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..700&family=Manrope:wght@300..800&display=swap',

		/* Arranque al activar el tema por primera vez. */
		'home' => [
			'design_theme'  => 'light',     // día por defecto (el visitante alterna)
			'motion_level'  => 'cinematic', // parallax, máscaras, magnéticos, contadores
			'intro_enabled' => 1,           // cortina con el logo, una vez por sesión
		],

		/* Paletas alternativas, aplicables con un botón desde los ajustes. */
		'presets' => [
			'tinta' => [
				'label'   => 'Tinta — azul profundo y latón',
				'palette' => [
					'color_accent'    => '#a8823c',
					'color_bg_dark'   => '#101418',
					'color_ink_dark'  => '#e9e6df',
					'color_bg_light'  => '#f4f2ed',
					'color_ink_light' => '#161b21',
				],
				'fonts'   => ['font_display' => 'Fraunces', 'font_sans' => 'Manrope'],
			],
			'galeria' => [
				'label'   => 'Galería — blanco museo y grafito',
				'palette' => [
					'color_accent'    => '#5c5c5c',
					'color_bg_dark'   => '#131313',
					'color_ink_dark'  => '#efefef',
					'color_bg_light'  => '#ffffff',
					'color_ink_light' => '#111111',
				],
				'fonts'   => ['font_display' => 'Fraunces', 'font_sans' => 'Manrope'],
			],
		],
	];

	return $skins;
});

/* -------------------------------------------------------------------------
 * Acento en modo noche: el bronce del día es demasiado oscuro sobre carbón.
 * El padre lo recalcula en HSL a partir del acento CONFIGURADO — si el admin
 * cambia el color, la versión de noche lo acompaña.
 * ---------------------------------------------------------------------- */
add_filter('mj_design_vars', function ($vars, $options) {
	if (!function_exists('mj_dark_accent_vars')) {
		return $vars;
	}
	return mj_dark_accent_vars(
		$vars,
		$options['color_accent'],
		0.14, // --mj-accent-soft
		0.30, // --mj-accent-glow
		(float) apply_filters('mj_atelier_dark_lightness', 0.62),
		(float) apply_filters('mj_atelier_dark_saturation_boost', 0.05)
	);
}, 10, 2);

/* -------------------------------------------------------------------------
 * Skin — carga DESPUÉS de dist/assets/app.css del padre y de su CSS inline
 * de diseño (wp_add_inline_style sobre 'mj-app'), así atelier.css gana la
 * cascada a igual especificidad. Prioridad 30 > 20 (padre).
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
	$path = get_stylesheet_directory() . '/atelier.css';
	if (!file_exists($path)) {
		return;
	}
	wp_enqueue_style(
		'mj-atelier',
		get_stylesheet_directory_uri() . '/atelier.css',
		['mj-app'],
		(string) filemtime($path)
	);
}, 30);
