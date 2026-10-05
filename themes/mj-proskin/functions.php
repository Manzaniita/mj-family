<?php
/**
 * MJ ProSkin — Tema hijo de ultralujo para MJ Appearance.
 *
 * Estética cyber luxury, animaciones fluidas, microinteracciones y
 * personalización nativa completa desde WordPress y Gutenberg.
 *
 * @package MJ_ProSkin
 */

defined('ABSPATH') || exit;

define('MJ_PROSKIN_VERSION', '1.0.0');
define('MJ_PROSKIN_REQUIRES', '3.35.0');

require_once __DIR__ . '/inc/textos.php';
require_once __DIR__ . '/inc/contenido.php';

/* Identificador de la skin activa: <html data-skin="proskin"> y window.MJ.skin. */
add_filter('mj_skin', function () {
	return 'proskin';
});

/* Versión mínima requerida del tema padre. */
add_filter('mj_skin_requires', function () {
	return MJ_PROSKIN_REQUIRES;
});

/* -------------------------------------------------------------------------
 * Definición de la Skin (Filtro mj_skins del tema padre)
 * ---------------------------------------------------------------------- */
add_filter('mj_skins', function ($skins) {
	$skins['proskin'] = [
		'name'      => 'MJ ProSkin',

		/* Paleta principal: Ultravioleta neón, Obsidiana profunda y Blanco diamante. */
		'palette'   => [
			'color_accent'    => '#7c3aed', // Ultravioleta Neón
			'color_bg_dark'   => '#07070a', // Obsidiana Profunda (modo noche)
			'color_ink_dark'  => '#f8fafc', // Blanco Diamante (tinta de noche)
			'color_bg_light'  => '#fbfbfe', // Blanco Platino (modo día)
			'color_ink_light' => '#09090b', // Negro Carbono (tinta de día)
		],

		'fonts'     => [
			'font_display' => 'Syne',
			'font_sans'    => 'Plus Jakarta Sans',
		],

		'fonts_url' => 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Syne:wght@500;600;700;800&display=swap',

		/* Propuestas para Apariencia → MJ Appearance y el Personalizador. */
		'options'   => [
			'hero_badge'              => 'Colección Limitada ⚡ Exclusividad Total',
			'hero_title'              => 'Vanguardia & Distinción',
			'hero_title_highlight'    => 'Diseño sin límites para tu estilo.',
			'hero_subtitle'           => 'Piezas de alta gama, tecnología de punta y estética innovadora. Viví la experiencia de una tienda que no se parece a ninguna otra.',
			'hero_cta_primary_text'   => 'Descubrir Novedades',
			'hero_cta_primary_url'    => '/tienda',
			'hero_cta_secondary_text' => 'Colección VIP ✨',
			'hero_cta_secondary_url'  => '/tienda?filtro=destacados',
			'header_sticky'           => 'sticky',
			'header_style'            => 'glass',
			'header_border'           => 'line',
			'header_search_pos'       => 'center',
			'header_menu_pos'         => 'inline',
			'social_instagram'        => 'https://instagram.com/',
		],

		/* Configuración de arranque inicial al activar la skin por primera vez. */
		'home'      => [
			'design_theme'  => 'dark',
			'motion_level'  => 'full',
			'intro_enabled' => 1,
			'order'         => 'banners,featured,categories,latest,brands,testimonials,instagram,about',
			'hidden'        => '',
		],

		/* Textos de la tienda propuestos (editables desde el panel). */
		'copy'      => [
			// Portada
			'home_featured_eyebrow'     => 'Selección Exclusiva',
			'home_featured_title'       => 'Piezas Destacadas',
			'home_featured_subtitle'    => 'Lo más buscado de nuestra colección de temporada.',
			'home_categories_eyebrow'   => 'Colecciones',
			'home_categories_title'     => 'Explorá por Categoría',
			'home_categories_link'      => 'Ver todo el catálogo',
			'home_latest_eyebrow'       => 'Lanzamientos',
			'home_latest_title'         => 'Últimos Ingresos',
			'home_brands_label'         => 'Trabajamos con firmas líderes a nivel internacional',
			'home_testimonials_eyebrow' => 'Clientes VIP',
			'home_testimonials_title'   => 'Experiencias & Opiniones',
			'home_instagram_title'      => 'Viví el estilo ProSkin en Instagram',
			'home_instagram_link'       => 'Seguinos en Instagram',
			'home_about_eyebrow'        => 'Nuestra Esencia',
			'home_about_title'          => 'Diseño sin concesiones',
			'home_about_link'           => 'Conocé nuestra historia',

			// Navegación
			'nav_home'                  => 'Inicio',
			'nav_shop'                  => 'Catálogo',
			'nav_about'                 => 'Nosotros',
			'nav_contact'               => 'Concierge',
			'nav_account'               => 'Mi Cuenta VIP',

			// Tienda y Catálogo
			'shop_eyebrow'              => 'Colección Oficial',
			'shop_title'                => 'Tienda Exclusiva',
			'shop_empty_title'          => 'No encontramos productos con ese criterio',
			'shop_empty_text'           => 'Explorá toda nuestra colección de temporada disponible en stock.',

			// Pie de página
			'footer_tagline'            => 'Innovación, exclusividad y vanguardia en cada detalle.',
			'footer_rights'             => 'MJ ProSkin. Todos los derechos reservados.',
		],

		/* Presets de paletas de ultralujo listas para aplicar con 1 clic. */
		'presets'   => [
			'cyber-luxury'     => [
				'label'   => 'Cyber Luxury (Ultravioleta & Cian)',
				'palette' => [
					'color_accent'    => '#7c3aed',
					'color_bg_dark'   => '#07070a',
					'color_ink_dark'  => '#f8fafc',
					'color_bg_light'  => '#fbfbfe',
					'color_ink_light' => '#09090b',
				],
				'fonts'   => [
					'font_display' => 'Syne',
					'font_sans'    => 'Plus Jakarta Sans',
				],
			],
			'royal-gold'       => [
				'label'   => 'Royal Gold & Onyx (Dorado Imperial & Negro)',
				'palette' => [
					'color_accent'    => '#d4af37',
					'color_bg_dark'   => '#09090b',
					'color_ink_dark'  => '#fef3c7',
					'color_bg_light'  => '#fffdfa',
					'color_ink_light' => '#18181b',
				],
				'fonts'   => [
					'font_display' => 'Syne',
					'font_sans'    => 'Plus Jakarta Sans',
				],
			],
			'emerald-prestige' => [
				'label'   => 'Emerald Prestige (Esmeralda Neón & Bosque)',
				'palette' => [
					'color_accent'    => '#10b981',
					'color_bg_dark'   => '#041611',
					'color_ink_dark'  => '#ecfdf5',
					'color_bg_light'  => '#f7fdfb',
					'color_ink_light' => '#062018',
				],
				'fonts'   => [
					'font_display' => 'Syne',
					'font_sans'    => 'Plus Jakarta Sans',
				],
			],
			'sunset-glam'      => [
				'label'   => 'Sunset Glamour (Rosa Eléctrico & Ciruela)',
				'palette' => [
					'color_accent'    => '#f43f5e',
					'color_bg_dark'   => '#150612',
					'color_ink_dark'  => '#fff1f2',
					'color_bg_light'  => '#fffafa',
					'color_ink_light' => '#1c0717',
				],
				'fonts'   => [
					'font_display' => 'Syne',
					'font_sans'    => 'Plus Jakarta Sans',
				],
			],
		],

		'patterns'  => mj_proskin_patterns(),
	];

	return $skins;
});

/* -------------------------------------------------------------------------
 * Encolado de Estilos de la Skin (Frontend y Editor Gutenberg)
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
	$path = get_stylesheet_directory() . '/proskin.css';
	if (file_exists($path)) {
		wp_enqueue_style(
			'mj-proskin',
			get_stylesheet_directory_uri() . '/proskin.css',
			['mj-app'],
			(string) filemtime($path)
		);
	}
}, 30);

add_action('enqueue_block_editor_assets', function () {
	$path = get_stylesheet_directory() . '/proskin.css';
	if (file_exists($path)) {
		wp_enqueue_style(
			'mj-proskin-editor',
			get_stylesheet_directory_uri() . '/proskin.css',
			[],
			(string) filemtime($path)
		);
	}
});

/* -------------------------------------------------------------------------
 * Registro de Patrones de Bloques ProSkin para Gutenberg
 * ---------------------------------------------------------------------- */
function mj_proskin_patterns() {
	$advantages = (array) mj_proskin_text('advantages');
	$adv_html   = '';
	foreach ($advantages as $adv) {
		$adv_html .= '<!-- wp:group {"className":"proskin-adv-card","layout":{"type":"constrained"}} -->';
		$adv_html .= '<div class="wp-block-group proskin-adv-card">';
		$adv_html .= '<span class="proskin-badge-pill">' . esc_html($adv['badge']) . '</span>';
		$adv_html .= '<h3 class="proskin-adv-title">' . esc_html($adv['title']) . '</h3>';
		$adv_html .= '<p class="proskin-adv-desc">' . esc_html($adv['description']) . '</p>';
		$adv_html .= '</div><!-- /wp:group -->';
	}

	$faq_items = [];
	foreach ((array) mj_proskin_text('faq') as $item) {
		if (is_array($item) && count($item) >= 2) {
			$faq_items[] = ['q' => $item[0], 'a' => $item[1]];
		}
	}

	return [
		// 1. Portada completa de ultralujo
		'proskin-portada' => [
			'title'       => 'Portada Completa MJ ProSkin (Cyber Luxury)',
			'description' => 'Hero holográfico, barra interactiva de ventajas VIP, vitrina de productos, categorías 3D, banner VIP Club y opiniones.',
			'categories'  => ['mj-proskin', 'featured'],
			'content'     => mj_proskin_block('hero', [
				'badge'             => 'Colección Limitada ⚡ Exclusividad Total',
				'title'             => 'Vanguardia & Distinción',
				'titleHighlight'    => 'Diseño sin límites para tu estilo.',
				'subtitle'          => 'Piezas de alta gama, tecnología de punta y estética innovadora. Viví la experiencia de una tienda que no se parece a ninguna otra.',
				'ctaPrimaryText'    => 'Descubrir Novedades',
				'ctaPrimaryUrl'     => '/tienda',
				'ctaSecondaryText'  => 'Colección VIP ✨',
				'ctaSecondaryUrl'   => '/tienda?filtro=destacados',
				'showStats'         => true,
			])
			. '<!-- wp:group {"align":"full","className":"proskin-advantages-wrap","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull proskin-advantages-wrap"><!-- wp:group {"align":"wide","className":"proskin-advantages-grid","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} --><div class="wp-block-group alignwide proskin-advantages-grid">' . $adv_html . '</div><!-- /wp:group --></div><!-- /wp:group -->' . "\n\n"
			. mj_proskin_block('productos', [
				'eyebrow' => 'Selección Exclusiva',
				'title'   => 'Piezas Destacadas',
				'source'  => 'featured',
				'limit'   => 4,
				'layout'  => 'grid',
				'cols'    => '4',
				'linkTo'  => '/tienda',
			])
			. mj_proskin_block('categorias', [
				'eyebrow' => 'Colecciones',
				'title'   => 'Explorá por Categoría',
				'limit'   => 4,
				'layout'  => 'grid',
			])
			. '<!-- wp:group {"align":"full","className":"proskin-vip-banner-wrap","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull proskin-vip-banner-wrap"><!-- wp:group {"align":"wide","className":"proskin-vip-banner-card","layout":{"type":"flex","flexWrap":"wrap","alignItems":"center","justifyContent":"space-between"}} --><div class="wp-block-group alignwide proskin-vip-banner-card"><div class="proskin-vip-banner-text"><span class="proskin-badge-pill" style="margin-bottom:8px;">CLUB PRIVADO PROSKIN</span><h2 style="margin:0 0 8px; font-size:2rem; font-weight:700;">Accedé a lanzamientos secretos antes que nadie.</h2><p style="margin:0; opacity:.85; font-size:1.05rem;">Registrate gratis hoy y recibí un 15% OFF de cortesía en tu primera orden con el código <strong>VIPFIRST</strong>.</p></div><div class="proskin-vip-banner-action"><a href="/tienda" class="proskin-magnetic-btn">Ingresar al Club VIP →</a></div></div><!-- /wp:group --></div><!-- /wp:group -->' . "\n\n"
			. mj_proskin_block('marcas', [
				'label' => 'Firmas aliadas que garantizan excelencia',
			])
			. mj_proskin_block('opiniones', [
				'eyebrow' => 'Clientes VIP',
				'title'   => 'Experiencias & Opiniones Reales',
				'limit'   => 6,
			]),
		],

		// 2. Hero VIP Interactivo
		'proskin-hero-vip' => [
			'title'       => 'Hero Holográfico VIP',
			'description' => 'Cabecera cinematográfica con badge flotante, degradado aurora y botones con reflejo luminoso.',
			'categories'  => ['mj-proskin', 'header'],
			'content'     => mj_proskin_block('hero', [
				'badge'             => 'Alta Gama ⚡ Edición Especial',
				'title'             => 'El Nuevo Estándar del Lujo',
				'titleHighlight'    => 'Creado para quienes buscan lo extraordinario.',
				'subtitle'          => 'Calidad insuperable, materiales selectos y atención personalizada en cada compra.',
				'ctaPrimaryText'    => 'Ver Colección VIP',
				'ctaPrimaryUrl'     => '/tienda',
				'ctaSecondaryText'  => 'Nuestra Historia',
				'ctaSecondaryUrl'   => '/nosotros',
				'showStats'         => true,
			]),
		],

		// 3. Barra de Ventajas VIP
		'proskin-ventajas-vip' => [
			'title'       => 'Garantías y Beneficios VIP (4 Columnas)',
			'description' => 'Tarjetas con iconos, microinteracciones hover y bordes con resplandor neón.',
			'categories'  => ['mj-proskin'],
			'content'     => '<!-- wp:group {"align":"full","className":"proskin-advantages-wrap","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull proskin-advantages-wrap"><!-- wp:group {"align":"wide","className":"proskin-advantages-grid","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} --><div class="wp-block-group alignwide proskin-advantages-grid">' . $adv_html . '</div><!-- /wp:group --></div><!-- /wp:group -->',
		],

		// 4. Banner VIP Club
		'proskin-vip-club' => [
			'title'       => 'Banner Interactivo Club VIP',
			'description' => 'Tarjeta suspendida de alto impacto con cupón dinámico y llamada a la acción.',
			'categories'  => ['mj-proskin'],
			'content'     => '<!-- wp:group {"align":"full","className":"proskin-vip-banner-wrap","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull proskin-vip-banner-wrap"><!-- wp:group {"align":"wide","className":"proskin-vip-banner-card","layout":{"type":"flex","flexWrap":"wrap","alignItems":"center","justifyContent":"space-between"}} --><div class="wp-block-group alignwide proskin-vip-banner-card"><div class="proskin-vip-banner-text"><span class="proskin-badge-pill" style="margin-bottom:8px;">CLUB PRIVADO PROSKIN</span><h2 style="margin:0 0 8px; font-size:2rem; font-weight:700;">Accedé a lanzamientos secretos antes que nadie.</h2><p style="margin:0; opacity:.85; font-size:1.05rem;">Registrate gratis hoy y recibí un 15% OFF de cortesía en tu primera orden con el código <strong>VIPFIRST</strong>.</p></div><div class="proskin-vip-banner-action"><a href="/tienda" class="proskin-magnetic-btn">Ingresar al Club VIP →</a></div></div><!-- /wp:group --></div><!-- /wp:group -->',
		],

		// 5. Preguntas Frecuentes VIP
		'proskin-faq-concierge' => [
			'title'       => 'Preguntas Frecuentes & Concierge VIP',
			'description' => 'Acordeón interactivo de dudas comunes con tarjeta de asistencia directa.',
			'categories'  => ['mj-proskin'],
			'content'     => mj_proskin_block('encabezado', [
				'eyebrow'  => 'Soporte y Asistencia VIP',
				'title'    => 'Preguntas Frecuentes',
				'subtitle' => 'Todo lo que necesitás saber sobre autenticidad, envíos blindados y compras en nuestra tienda.',
			])
			. mj_proskin_block('faq', [
				'items'           => $faq_items,
				'showContactCard' => true,
			]),
		],
	];
}

add_action('init', function () {
	if (!function_exists('register_block_pattern_category') || !function_exists('register_block_pattern')) {
		return;
	}

	register_block_pattern_category('mj-proskin', [
		'label' => 'MJ ProSkin — Cyber Luxury',
	]);

	foreach (mj_proskin_patterns() as $slug => $pattern) {
		$slug = sanitize_key((string) $slug);
		if ('' === $slug || !is_array($pattern) || empty($pattern['content'])) {
			continue;
		}
		register_block_pattern('mj-proskin/' . $slug, wp_parse_args($pattern, [
			'title'       => $slug,
			'description' => '',
			'categories'  => ['mj-proskin'],
			'postTypes'   => ['page'],
		]));
	}
}, 25);
