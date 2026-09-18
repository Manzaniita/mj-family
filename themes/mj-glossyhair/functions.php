<?php
/**
 * GlossyHair — tema hijo de MJ Appearance (skin "glossy": salón de alisados
 * que también vende productos de cuidado capilar).
 *
 * No toca PHP del padre ni el build de React. Se limita a DESCRIBIRSE con el
 * filtro `mj_skins` del padre (ver inc/skins.php): paleta, tipografías, textos
 * de cada pantalla, orden de la portada y paletas alternativas. El padre se
 * ocupa del resto — proponer esos valores donde el admin no eligió nada,
 * mostrarlos en Apariencia → MJ Appearance → Skin y dejar aplicarlos con un
 * botón para que queden editables campo por campo.
 *
 * Lo único que este archivo hace por su cuenta es lo que sí es lógica y no
 * configuración:
 *   1. declarar la skin (filtro mj_skin → <html data-skin="glossyhair">),
 *   2. aclarar el acento para el modo noche (recalculado en HSL a partir del
 *      color que el admin haya elegido, sea cual sea),
 *   3. encolar glossyhair.css después del CSS del padre.
 */

defined('ABSPATH') || exit;

define('MJ_GLOSSYHAIR_VERSION', '1.1.0');

/* Versión mínima del tema padre (la que trae la API de skins). */
define('MJ_GLOSSYHAIR_REQUIRES', '3.19.0');

/* Identidad de la skin: <html data-skin="glossyhair"> y window.MJ.skin. */
add_filter('mj_skin', fn () => 'glossyhair');

/* El padre avisa en wp-admin si está instalada una versión más vieja. */
add_filter('mj_skin_requires', fn () => MJ_GLOSSYHAIR_REQUIRES);

/* -------------------------------------------------------------------------
 * Plugin MJ Turnos Online: ¿está activo? Los botones del hero y el CTA de
 * "Sobre mí" apuntan a la reserva solo si hay a dónde apuntar; sin el
 * plugin, la skin propone la tienda (así no queda un enlace roto).
 * ---------------------------------------------------------------------- */
function mj_glossyhair_turnos_active() {
	return defined('MJTO_VERSION') || function_exists('mjto_public_config');
}

/*
 * Ruta pública de la reserva: '/' + slug configurado en Turnos → Ajustes
 * (default '/turnos'). Se resuelve una sola vez; el valor provisorio se fija
 * ANTES de consultar al plugin, así una reentrada (si el plugin leyera las
 * opciones del tema al armar sus defaults) no entra en bucle.
 */
function mj_glossyhair_booking_path() {
	static $path = null;
	if (null !== $path) {
		return $path;
	}
	$path = '/turnos';
	if (function_exists('mjto_page_slug')) {
		$slug = sanitize_title((string) mjto_page_slug());
		if ('' !== $slug) {
			$path = '/' . $slug;
		}
	}
	return $path;
}

/* Ruta de la tienda según el padre (configurable con el filtro `mj_shop_path`). */
function mj_glossyhair_shop_path() {
	return function_exists('mj_shop_path') ? mj_shop_path() : '/tienda';
}

/* -------------------------------------------------------------------------
 * Descripción de la skin
 *
 * Todo lo de acá son PROPUESTAS: el padre las usa sólo donde el admin no
 * configuró nada, y cualquier valor escrito en Apariencia → MJ Appearance
 * les gana. Con el ajuste "Propuestas de la skin" desmarcado no se aplica
 * ninguna y queda sólo el CSS.
 * ---------------------------------------------------------------------- */
add_filter('mj_skins', function ($skins) {
	$turnos  = mj_glossyhair_turnos_active();
	$booking = mj_glossyhair_booking_path();
	$shop    = mj_glossyhair_shop_path();

	$skins['glossyhair'] = [
		'name' => 'GlossyHair',

		/* Blanco de día, ciruela profunda de noche, acento fucsia. */
		'palette' => [
			'color_accent'    => '#e11d84', // fucsia
			'color_bg_dark'   => '#150a10', // ciruela profunda (noche)
			'color_ink_dark'  => '#fdf4f8', // rosa muy claro (tinta de noche)
			'color_bg_light'  => '#ffffff', // blanco puro (día)
			'color_ink_light' => '#1c0f16', // ciruela tinta (día)
		],

		'fonts' => [
			'font_display' => 'Urbanist',
			'font_sans'    => 'Manrope',
		],

		/* Urbanist con itálicas y pesos 400–800; Manrope 400–800. */
		'fonts_url' => 'https://fonts.googleapis.com/css2?family=Urbanist:ital,wght@0,400..800;1,400..800&family=Manrope:wght@400..800&display=swap',

		/* Portada: texto y botones del hero. */
		'options' => [
			'hero_badge'              => 'Alisados profesionales',
			'hero_title'              => 'Pelo liso, con brillo',
			'hero_title_highlight'    => 'y sano de verdad.',
			'hero_cta_primary_text'   => $turnos ? 'Reservar turno' : 'Ver productos',
			'hero_cta_primary_url'    => $turnos ? $booking : $shop,
			'hero_cta_secondary_text' => $turnos ? 'Ver productos' : '',
			'hero_cta_secondary_url'  => $turnos ? $shop : '',
		],

		/*
		 * Arranque al activar el tema por primera vez: día, animación media,
		 * sin cortina de entrada, y una portada pensada para el salón
		 * (tratamientos y reserva primero, después "Sobre mí" y los productos).
		 */
		'home' => [
			'design_theme'  => 'light',
			'motion_level'  => 'medium',
			'intro_enabled' => 0,
			'order'         => 'services,booking,about,featured,categories,latest,testimonials,instagram,recommended,colegas,banners,brands',
			'hidden'        => 'banners,brands,recommended,colegas',
		],

		/*
		 * Textos de cada pantalla para un salón de alisados que vende productos
		 * de cuidado capilar. Los vacíos siguen la regla del padre
		 * (vacío = automático / no se muestra).
		 */
		'copy' => [
			// Portada
			'home_featured_eyebrow'     => 'Selección',
			'home_featured_title'       => 'Productos destacados',
			'home_featured_subtitle'    => 'Lo que uso en el salón y recomiendo para seguir el cuidado en casa.',
			'home_categories_eyebrow'   => 'Cuidado capilar',
			'home_categories_title'     => 'Elegí por tipo de producto',
			'home_categories_link'      => 'Ver todos los productos',
			'home_latest_eyebrow'       => 'Recién llegados',
			'home_latest_title'         => 'Novedades',
			'home_recommended_eyebrow'  => 'Mis favoritos',
			'home_recommended_title'    => 'Los que más recomiendo',
			'home_recommended_subtitle' => 'Los productos que mis clientas eligen para mantener el alisado y el brillo.',
			'home_brands_label'         => 'Trabajo con marcas profesionales',
			'home_testimonials_eyebrow' => 'Clientas',
			'home_testimonials_title'   => 'Lo que cuentan de su pelo',
			'home_instagram_title'      => 'Antes y después, en Instagram',
			'home_instagram_link'       => 'Seguime',
			'home_about_eyebrow'        => 'Sobre mí',
			'home_about_title'          => 'Quién está detrás de GlossyHair',
			'home_about_text'           => '', // Vacío = primer párrafo del texto "Nosotros" (Contenido).
			'home_about_link'           => 'Conocé mi historia',
			// Menú y navegación
			'nav_home'                  => 'Inicio',
			'nav_shop'                  => 'Productos',
			'nav_sale'                  => 'Ofertas',
			'nav_new'                   => 'Novedades',
			'nav_contact'               => 'Contacto',
			'nav_about'                 => 'Sobre mí',
			'nav_search'                => 'Buscar productos',
			'nav_cart'                  => 'Carrito',
			'nav_account'               => 'Mi cuenta',
			'nav_home_mobile'           => 'Inicio',
			// Tienda
			'shop_eyebrow'              => 'Cuidado capilar',
			'shop_title'                => 'Productos',
			'shop_sale_title'           => 'Ofertas',
			'shop_new_title'            => 'Novedades',
			'shop_empty_title'          => 'No encontré nada con eso',
			'shop_empty_text'           => 'Probá con otra búsqueda o mirá todos los productos.',
			'brands_eyebrow'            => 'Marcas',
			'brands_subtitle'           => 'Las marcas profesionales que uso en el salón y vendo para el cuidado en casa.',
			// Producto
			'product_related_eyebrow'   => 'Seguí mirando',
			'product_related_title'     => 'Se complementa con',
			'product_recent_eyebrow'    => 'Tu recorrido',
			'product_recent_title'      => 'Vistos recientemente',
			// Pie de página
			'footer_newsletter_title'   => 'Tips de cuidado y novedades, en tu correo.',
			'footer_newsletter_text'    => 'Consejos para mantener el alisado, productos nuevos y promos. Sin spam.',
			'footer_tagline'            => '', // Vacío = descripción corta del sitio (Ajustes → Generales).
			'footer_rights'             => 'Pelo sano, brillo real. Todos los derechos reservados.',
			'footer_group_shop'         => 'Productos',
			'footer_group_help'         => 'Ayuda',
			'footer_group_account'      => 'Tu cuenta',
			'footer_link_contact'       => 'Contacto',
			'footer_link_faq'           => 'Preguntas frecuentes',
			'footer_link_terms'         => 'Términos y condiciones',
			'footer_link_privacy'       => 'Privacidad',
			'footer_link_account'       => 'Mi cuenta',
			'footer_link_orders'        => 'Mis pedidos',
			'footer_link_wishlist'      => 'Favoritos',
			// Páginas
			'about_eyebrow'             => 'Sobre mí',
			'about_title'               => 'Alisados hechos con técnica, paciencia y productos que cuidan tu pelo.',
			'about_highlights_title'    => 'Cómo trabajo',
			'about_cta_text'            => $turnos ? 'Reservar un turno' : '',
			'about_cta_url'             => $turnos ? $booking : '',
			'contact_eyebrow'           => 'Hablemos',
			'contact_title'             => 'Contacto',
			'contact_subtitle'          => 'Dudas sobre un tratamiento, turnos o productos: escribime.',
			'faq_subtitle'              => 'Lo que suelen preguntarme antes de un alisado o de comprar un producto.',
		],

		/*
		 * Paletas alternativas: se aplican con un botón desde los ajustes y
		 * quedan escritas en los campos de "Diseño y marca".
		 */
		'presets' => [
			'nude' => [
				'label'   => 'Nude — durazno y arena',
				'palette' => [
					'color_accent'    => '#c9765b',
					'color_bg_dark'   => '#17100d',
					'color_ink_dark'  => '#fbf3ee',
					'color_bg_light'  => '#fffaf6',
					'color_ink_light' => '#1d1310',
				],
				'fonts'   => ['font_display' => 'Urbanist', 'font_sans' => 'Manrope'],
			],
			'platino' => [
				'label'   => 'Platino — gris frío y violeta',
				'palette' => [
					'color_accent'    => '#8b7cf6',
					'color_bg_dark'   => '#0f0f14',
					'color_ink_dark'  => '#f4f4f8',
					'color_bg_light'  => '#fbfbfd',
					'color_ink_light' => '#14141a',
				],
				'fonts'   => ['font_display' => 'Urbanist', 'font_sans' => 'Manrope'],
			],
		],
	];

	return $skins;
});

/* -------------------------------------------------------------------------
 * Plugin MJ Turnos Online: los servicios son "tratamientos" y la dueña habla
 * en primera persona. Solo propone defaults; todo se cambia desde
 * Turnos → Ajustes. Las plantillas de mail/WhatsApp (tpl_*) quedan las del
 * plugin, que ya son genéricas.
 * ---------------------------------------------------------------------- */
add_filter('mjto_option_defaults', function ($defaults) {
	return array_merge($defaults, [
		'booking_title'         => 'Reservá tu alisado',
		'booking_subtitle'      => 'Elegí el tratamiento, el día y el horario. Te confirmo enseguida.',
		'booking_step_service'  => 'Tratamiento',
		'home_services_eyebrow' => 'Servicios',
		'home_services_title'   => 'Tratamientos',
		'home_services_link'    => 'Ver todos los tratamientos',
		'home_booking_title'    => 'Reservá tu turno online',
		'home_booking_subtitle' => 'Elegí el tratamiento, el día y la hora. Te confirmo por WhatsApp.',
		'services_title'        => 'Tratamientos',
		'services_subtitle'     => 'Cada pelo es distinto: antes de cada alisado hago un diagnóstico y una prueba de mechón.',
		'menu_label'            => 'Turnos',
		'services_menu_label'   => 'Tratamientos',
		'header_cta'            => 'Reservar',
		'booking_policy'        => "Para reservar el turno se pide una seña; el resto se abona el día del tratamiento.\n"
			. "Vení con el pelo lavado y sin productos (sin cremas, aceites ni fijadores).\n"
			. 'Si necesitás cambiar o cancelar, avisame con anticipación así le doy el lugar a otra clienta.',
	]);
});

/* -------------------------------------------------------------------------
 * Acento en modo noche: el fucsia del día pierde contraste sobre ciruela.
 * El padre lo recalcula en HSL a partir del acento CONFIGURADO — si el admin
 * cambia el color, la versión de noche lo acompaña.
 *
 * Los dos números del ajuste (luz mínima y refuerzo de saturación) salen de
 * filtros: una instalación con otra paleta puede afinarlos sin editar esto.
 * ---------------------------------------------------------------------- */
add_filter('mj_design_vars', function ($vars, $options) {
	if (!function_exists('mj_dark_accent_vars')) {
		return $vars;
	}
	$vars = mj_dark_accent_vars(
		$vars,
		$options['color_accent'],
		0.16, // --mj-accent-soft
		0.30, // --mj-accent-glow
		(float) apply_filters('mj_glossyhair_dark_lightness', 0.62),
		(float) apply_filters('mj_glossyhair_dark_saturation_boost', 0.04)
	);

	/* De día el glow es un halo rosado suave, no un neón. */
	if ($day = mj_hex_rgb($options['color_accent'])) {
		$light = array_values(array_filter(
			isset($vars['light']) ? (array) $vars['light'] : [],
			fn ($line) => 0 !== strpos($line, '--mj-accent-glow')
		));
		$light[] = "--mj-accent-glow: rgb({$day[0]} {$day[1]} {$day[2]} / 0.22)";
		$vars['light'] = $light;
	}
	return $vars;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Skin — carga DESPUÉS de dist/assets/app.css del padre y de su CSS inline
 * de diseño (wp_add_inline_style sobre 'mj-app'), así glossyhair.css gana
 * la cascada a igual especificidad. Prioridad 30 > 20 (padre).
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
	$path = get_stylesheet_directory() . '/glossyhair.css';
	if (!file_exists($path)) {
		return;
	}
	wp_enqueue_style(
		'mj-glossyhair',
		get_stylesheet_directory_uri() . '/glossyhair.css',
		['mj-app'],
		(string) filemtime($path)
	);
}, 30);
