<?php
/**
 * Yamana — tema hijo de MJ Appearance (skin cálida artesanal: tejidos, cursos).
 *
 * No toca PHP del padre ni el build de React. Se limita a DESCRIBIRSE con el
 * filtro `mj_skins` del padre (ver inc/skins.php): paleta, tipografías, textos
 * de cada pantalla y preferencias de arranque. El padre se ocupa del resto —
 * proponer esos valores donde el admin no eligió nada, mostrarlos en
 * Apariencia → MJ Appearance → Skin y dejar aplicarlos con un botón para que
 * queden editables campo por campo.
 *
 * Lo que hace por su cuenta es lógica, no configuración:
 *   1. declarar la skin (filtro mj_skin → <html data-skin="yamana">),
 *   2. proponerle al plugin MJ Colegas los textos del proyecto colectivo
 *      "Entre Hilos" (se cambian desde Colegas → Ajustes),
 *   3. aclarar el acento para el modo noche (terracota sobre chocolate),
 *   4. encolar yamana.css después del CSS del padre.
 */

defined('ABSPATH') || exit;

define('MJ_YAMANA_VERSION', '1.1.0');

/* Versión mínima del tema padre (la que trae la API de skins). */
define('MJ_YAMANA_REQUIRES', '3.6.0');

/* Identidad de la skin: <html data-skin="yamana"> y window.MJ.skin. */
add_filter('mj_skin', fn () => 'yamana');

/* El padre avisa en wp-admin si está instalada una versión más vieja. */
add_filter('mj_skin_requires', fn () => MJ_YAMANA_REQUIRES);

/* -------------------------------------------------------------------------
 * Descripción de la skin
 *
 * Todo lo de acá son PROPUESTAS: el padre las usa sólo donde el admin no
 * configuró nada, y cualquier valor escrito en Apariencia → MJ Appearance
 * les gana. Con el ajuste "Propuestas de la skin" desmarcado no se aplica
 * ninguna y queda sólo el CSS.
 * ---------------------------------------------------------------------- */
add_filter('mj_skins', function ($skins) {
	$skins['yamana'] = [
		'name' => 'Yamana',

		/* Lino de día, chocolate de noche, acento terracota. */
		'palette' => [
			'color_accent'    => '#b85c38', // terracota
			'color_bg_dark'   => '#1a1512', // chocolate
			'color_ink_dark'  => '#f3ebe2', // lino claro
			'color_bg_light'  => '#f6f1ea', // lino
			'color_ink_light' => '#2b2119', // marrón tinta
		],

		'fonts' => [
			'font_display' => 'Fraunces',
			'font_sans'    => 'Nunito',
		],

		/*
		 * Fraunces necesita itálicas y el eje óptico (opsz) para los títulos;
		 * Nunito, itálicas y pesos 300–800.
		 */
		'fonts_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..700&family=Nunito:ital,wght@0,300..800;1,300..800&display=swap',

		/* Portada: texto del hero. */
		'options' => [
			'hero_title'            => 'Tejido a mano,',
			'hero_title_highlight'  => 'con tiempo y con cariño.',
			'hero_cta_primary_text' => 'Ver la tienda',
		],

		/* Arranque al activar el tema por primera vez. */
		'home' => [
			'design_theme'  => 'light',  // día por defecto (el visitante alterna)
			'motion_level'  => 'medium', // reveals al scroll y transiciones, sin parallax
			'intro_enabled' => 0,        // sin cortina de entrada
		],

		/* Textos de cada pantalla para una tienda de tejidos y cursos. */
		'copy' => [
			'home_featured_eyebrow'     => 'Selección',
			'home_featured_title'       => 'Piezas destacadas',
			'home_featured_subtitle'    => 'Tejidos a mano, elegidos con cariño esta semana.',
			'home_categories_eyebrow'   => 'Explorá',
			'home_categories_title'     => 'Elegí por dónde empezar',
			'home_categories_link'      => 'Ver toda la tienda',
			'home_latest_eyebrow'       => 'Recién salidos de las agujas',
			'home_latest_title'         => 'Novedades',
			'home_recommended_eyebrow'  => 'Favoritos',
			'home_recommended_title'    => 'Los más queridos',
			'home_recommended_subtitle' => 'Lo que más eligen quienes ya compraron.',
			'home_brands_label'         => 'Hilados y marcas con las que trabajamos',
			'home_testimonials_eyebrow' => 'Comunidad',
			'home_testimonials_title'   => 'Lo que cuentan de sus piezas',
			'home_instagram_title'      => 'Seguinos en Instagram',
			'home_instagram_link'       => 'Ver el perfil',
			'shop_eyebrow'              => 'Catálogo',
			'shop_title'                => 'Tienda',
			'shop_sale_title'           => 'Ofertas',
			'shop_new_title'            => 'Novedades',
			'shop_empty_title'          => 'No encontramos nada por acá',
			'shop_empty_text'           => 'Probá con otra búsqueda o mirá toda la tienda.',
			'brands_eyebrow'            => 'Materiales',
			'brands_subtitle'           => 'Los hilados y marcas que usamos en cada pieza.',
			'product_related_eyebrow'   => 'Seguí mirando',
			'product_related_title'     => 'Te puede gustar también',
			'product_recent_eyebrow'    => 'Tu recorrido',
			'product_recent_title'      => 'Vistos recientemente',
			'footer_newsletter_title'   => 'Novedades del taller, en tu correo.',
			'footer_newsletter_text'    => 'Piezas nuevas, fechas de cursos y ofertas. Sin spam.',
			'footer_rights'             => 'Hecho a mano, con paciencia. Todos los derechos reservados.',
			'about_eyebrow'             => 'Nosotros',
			'about_title'               => 'Cada pieza lleva horas, manos y una historia.',
			'contact_eyebrow'           => 'Hablemos',
			'contact_title'             => 'Contacto',
			'contact_subtitle'          => 'Consultas, pedidos a medida o cursos: escribinos.',
			'faq_subtitle'              => 'Lo que suelen preguntarnos antes de comprar o anotarse a un curso.',
		],

		/* Paletas alternativas, aplicables con un botón desde los ajustes. */
		'presets' => [
			'musgo' => [
				'label'   => 'Musgo — verde tejido y lino',
				'palette' => [
					'color_accent'    => '#6b7f52',
					'color_bg_dark'   => '#161a14',
					'color_ink_dark'  => '#eef0e8',
					'color_bg_light'  => '#f5f4ec',
					'color_ink_light' => '#212517',
				],
				'fonts'   => ['font_display' => 'Fraunces', 'font_sans' => 'Nunito'],
			],
			'lana-cruda' => [
				'label'   => 'Lana cruda — beige y ocre',
				'palette' => [
					'color_accent'    => '#c08a3e',
					'color_bg_dark'   => '#1b1712',
					'color_ink_dark'  => '#f4eee3',
					'color_bg_light'  => '#faf6ee',
					'color_ink_light' => '#2a2118',
				],
				'fonts'   => ['font_display' => 'Fraunces', 'font_sans' => 'Nunito'],
			],
		],
	];

	return $skins;
});

/* -------------------------------------------------------------------------
 * Plugin MJ Colegas: el proyecto colectivo se llama "Entre Hilos".
 * Solo propone defaults; todo se cambia desde Colegas → Ajustes.
 * ---------------------------------------------------------------------- */
add_filter('mjc_option_defaults', function ($defaults) {
	return array_merge($defaults, [
		'label_singular'           => 'Colega',
		'label_plural'             => 'Colegas',
		'label_made_by'            => 'Tejido por',
		'project_name'             => 'Entre Hilos',
		'project_slug'             => 'entre-hilos',
		'project_eyebrow'          => 'Proyecto colectivo',
		'project_subtitle'         => 'Un espacio compartido con otras emprendedoras del tejido: cada pieza lleva el nombre de quien la hizo.',
		'project_all_label'        => 'Todo',
		'project_makers_eyebrow'   => 'Quiénes tejen',
		'project_makers_title'     => 'Las manos detrás de cada pieza',
		'project_products_eyebrow' => 'Piezas',
		'project_products_title'   => 'Todo lo que se teje en Entre Hilos',
		'project_shop_link'        => 'Ver en la tienda',
		'home_eyebrow'             => 'Entre Hilos',
		'home_title'               => 'Tejemos en red',
		'home_subtitle'            => 'Colegas que venden sus piezas acá, con su nombre y su estilo.',
		'home_link'                => 'Conocer Entre Hilos',
	]);
});

/* -------------------------------------------------------------------------
 * Acento en modo noche: la terracota del día es demasiado oscura sobre
 * chocolate. El padre lo recalcula en HSL a partir del acento CONFIGURADO —
 * si el admin cambia el color, la versión de noche lo acompaña.
 * ---------------------------------------------------------------------- */
add_filter('mj_design_vars', function ($vars, $options) {
	if (!function_exists('mj_dark_accent_vars')) {
		return $vars;
	}
	$vars = mj_dark_accent_vars(
		$vars,
		$options['color_accent'],
		0.14, // --mj-accent-soft
		0.22, // --mj-accent-glow
		(float) apply_filters('mj_yamana_dark_lightness', 0.64),
		(float) apply_filters('mj_yamana_dark_saturation_boost', 0.04)
	);

	/* De día el glow es apenas una sombra cálida, no un halo. */
	if ($day = mj_hex_rgb($options['color_accent'])) {
		$light = array_values(array_filter(
			isset($vars['light']) ? (array) $vars['light'] : [],
			fn ($line) => 0 !== strpos($line, '--mj-accent-glow')
		));
		$light[] = "--mj-accent-glow: rgb({$day[0]} {$day[1]} {$day[2]} / 0.18)";
		$vars['light'] = $light;
	}
	return $vars;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Skin — carga DESPUÉS de dist/assets/app.css del padre y de su CSS inline
 * de diseño (wp_add_inline_style sobre 'mj-app'), así yamana.css gana la
 * cascada a igual especificidad. Prioridad 30 > 20 (padre).
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
	$path = get_stylesheet_directory() . '/yamana.css';
	if (!file_exists($path)) {
		return;
	}
	wp_enqueue_style(
		'mj-yamana',
		get_stylesheet_directory_uri() . '/yamana.css',
		['mj-app'],
		(string) filemtime($path)
	);
}, 30);
