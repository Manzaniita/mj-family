<?php
/**
 * Ajustes del tema — página "MJ Appearance" bajo Apariencia.
 *
 * Toda la data de negocio (anuncios, contacto, redes, FAQs, umbral de envío
 * gratis manual) vive en UNA opción array: mj_options.
 *
 * 3.4: logo, etiquetas de "marca", textos de la tienda (copy), secciones de
 * la portada y textos legales también son configurables. Un tema hijo
 * propone sus propios defaults con los filtros `mj_copy_defaults` y
 * `option_mj_options`; lo que el admin escriba a mano siempre gana.
 *
 * 3.5: las secciones de la portada además se ORDENAN (arrastrar y soltar,
 * `home_order`); hay una sección "Nosotros" en la portada y la página
 * Nosotros lleva foto, puntos destacados y cifras propias; los textos del
 * menú y del pie de página también salen del copy. Los plugins (MJ Colegas,
 * MJ Turnos Online) suman sus propias secciones con `mj_home_sections`.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Defaults y lectura de opciones
 * ---------------------------------------------------------------------- */
function mj_option_defaults() {
	return [
		'announcements'           => '',
		'product_badges'          => '',
		'whatsapp_numbers'        => '',
		'quote_info'              => '',
		'hero_badge'              => '',
		'hero_title'              => 'Todo lo que buscás,',
		'hero_title_highlight'    => 'en un solo lugar.',
		'hero_subtitle'           => '',
		'hero_cta_primary_text'   => 'Explorar la tienda',
		'hero_cta_primary_url'    => '/tienda',
		'hero_cta_secondary_text' => '',
		'hero_cta_secondary_url'  => '',
		'hero_bg_images'          => '',
		'promo_banners'           => '',
		'contact_email'           => get_option('admin_email'),
		'contact_phone'           => '',
		'contact_whatsapp'        => '',
		'contact_address'         => '',
		'contact_map'             => '',
		'social_instagram'        => '',
		'social_facebook'         => '',
		'social_twitter'          => '',
		'social_youtube'          => '',
		'social_tiktok'           => '',
		'about_text'              => '',
		'faqs'                    => '',
		'free_shipping_override'  => 0,
		'menu_categories'         => '', // Slugs CSV (máx. 4). Vacío = automático.
		'design_theme'            => 'dark', // dark | light | system
		'motion_level'            => 'medium', // subtle | medium | cinematic
		'intro_enabled'           => 0,
		'color_accent'            => '',
		'color_bg_dark'           => '',
		'color_ink_dark'          => '',
		'color_bg_light'          => '',
		'color_ink_light'         => '',
		'font_display'            => '',
		'font_sans'               => '',
		/* 3.4 — logo, marca, textos, portada y legales. */
		'logo_id'                 => 0,  // Adjunto del logo (vacío = logo del Personalizador, si hay; si no, inicial + nombre).
		'logo_dark_id'            => 0,  // Variante para el modo noche (vacío = el mismo logo).
		'logo_show_name'          => 1,  // Mostrar el nombre del sitio junto al logo.
		'brand_label'             => '', // Vacío = "Marca".
		'brand_label_plural'      => '', // Vacío = "Marcas".
		'copy'                    => [], // key => texto (vacío = default del tema/skin).
		'home_hidden'             => '', // CSV de secciones ocultas de la portada.
		'legal_terms'             => '',
		'legal_privacy'           => '',
		/* 3.5 — orden de la portada y página "Nosotros". */
		'home_order'              => '', // CSV con el orden de las secciones (vacío = orden por defecto).
		'about_image'             => 0,  // Adjunto: foto para la página Nosotros y la portada.
		'about_highlights'        => '', // "Texto | ícono" por línea (puntos destacados de Nosotros).
		'about_stats'             => '', // "Número | Etiqueta" por línea (vacío = cifras automáticas del catálogo).
		/* 3.6 — sesión, acceso y skins. */
		'admin_bar'               => 'profile', // profile | admins | all | off
		'session_days'            => 2,  // Duración de la sesión normal, en días (0 = default de WordPress).
		'session_days_remember'   => 14, // Duración con "Recordarme" marcado (0 = default de WordPress).
		'login_remember_default'  => 1,  // "Recordarme" viene marcado.
		'login_reload'            => 1,  // Recargar la tienda al iniciar/cerrar sesión.
		'account_path'            => '', // Ruta de "mi cuenta" en la SPA (vacío = /cuenta).
		'skin_defaults'           => 1,  // Aplicar las propuestas de la skin (paleta, tipografías, textos).
		'icons_cdn'               => '', // URL del script de íconos del admin (vacío = el CDN por defecto).
		'maps_embed_base'         => '', // Base para incrustar mapas (vacío = Google Maps).
	];
}

/* -------------------------------------------------------------------------
 * Valores por defecto que NO son texto libre: se validan contra su lista.
 * Tener las listas en funciones (y no en el sanitizador) permite que un tema
 * hijo o un plugin agreguen opciones sin tocar este archivo.
 * ---------------------------------------------------------------------- */

/* Modos de visibilidad de la barra de administración. */
function mj_admin_bar_modes() {
	return (array) apply_filters('mj_admin_bar_modes', [
		'profile' => 'Según el perfil de cada usuario (como WordPress)',
		'admins'  => 'Solo quienes administran el tema',
		'all'     => 'Todos los usuarios con la sesión iniciada',
		'off'     => 'Nunca',
	]);
}

/* Temas de arranque disponibles. */
function mj_theme_choices() {
	return (array) apply_filters('mj_theme_choices', [
		'dark'   => 'Oscuro (noche)',
		'light'  => 'Claro (día)',
		'system' => 'Según el dispositivo del visitante',
	]);
}

/* Niveles de animación disponibles. */
function mj_motion_choices() {
	return (array) apply_filters('mj_motion_choices', [
		'subtle'    => 'Sutil — solo fundidos y hovers',
		'medium'    => 'Media — transiciones de página y reveals al scroll',
		'cinematic' => 'Cinematográfica — además parallax, reveals por máscara, botones magnéticos y contadores',
	]);
}

/* Colores por defecto del design system (globals.css) — para prefills y reset. */
function mj_color_defaults() {
	return [
		'color_accent'    => '#6e6bff',
		'color_bg_dark'   => '#0a0a0c',
		'color_ink_dark'  => '#f5f5f7',
		'color_bg_light'  => '#fafafa',
		'color_ink_light' => '#0a0a0c',
	];
}

/* Tipografías disponibles (Google Fonts con pesos 400–700). */
function mj_font_choices() {
	$fonts = [
		'Inter', 'Space Grotesk', 'Manrope', 'Poppins', 'Montserrat', 'DM Sans',
		'Sora', 'Outfit', 'Urbanist', 'Plus Jakarta Sans', 'Raleway',
		'Playfair Display', 'Archivo', 'Rubik', 'Work Sans', 'Nunito', 'Nunito Sans', 'Karla',
		'IBM Plex Sans', 'Barlow', 'Figtree', 'Lexend', 'Oswald', 'Chakra Petch',
		'Orbitron', 'Rajdhani', 'Exo 2', 'Syne', 'Quicksand', 'Josefin Sans',
		'Fraunces', 'Instrument Serif', 'Cormorant Garamond', 'DM Serif Display', 'Libre Caslon Text', 'Lora', 'Instrument Sans',
	];
	/* Un tema hijo o un plugin puede sumar (o sacar) familias sin tocar el tema. */
	return array_values(array_unique(array_filter((array) apply_filters('mj_font_choices', $fonts))));
}

function mj_get_options() {
	$saved = get_option('mj_options', []);
	if (!is_array($saved)) {
		$saved = [];
	}
	$options = wp_parse_args($saved, mj_option_defaults());
	if (!is_array($options['copy'])) {
		$options['copy'] = [];
	}
	return $options;
}

/* -------------------------------------------------------------------------
 * Textos de la tienda (copy)
 *
 * Cada texto que la SPA mostraba fijo ahora tiene una clave. El default es
 * el del tema; un tema hijo lo cambia con `mj_copy_defaults`; el admin lo
 * pisa desde Apariencia → MJ Appearance → Textos de la tienda.
 * ---------------------------------------------------------------------- */

/*
 * Definición: key => [grupo, etiqueta, default, tipo, (descripción opcional)].
 * La descripción se muestra debajo del campo; sirve sobre todo para las claves
 * cuyo default es vacío (vacío = "no se muestra" o "automático").
 */
function mj_copy_fields() {
	$fields = [
		// Portada
		'home_featured_eyebrow'     => ['Portada', 'Destacados — antetítulo', 'Selección', 'text'],
		'home_featured_title'       => ['Portada', 'Destacados — título', 'Destacados de la semana', 'text'],
		'home_featured_subtitle'    => ['Portada', 'Destacados — subtítulo', 'Lo que nuestro equipo elegiría hoy.', 'text'],
		'home_categories_eyebrow'   => ['Portada', 'Categorías — antetítulo', 'Categorías', 'text'],
		'home_categories_title'     => ['Portada', 'Categorías — título', 'Explorá por universo', 'text'],
		'home_categories_link'      => ['Portada', 'Categorías — texto del enlace', 'Ver la tienda', 'text'],
		'home_latest_eyebrow'       => ['Portada', 'Novedades — antetítulo', 'Recién llegados', 'text'],
		'home_latest_title'         => ['Portada', 'Novedades — título', 'Últimos ingresos', 'text'],
		'home_recommended_eyebrow'  => ['Portada', 'Recomendados — antetítulo', 'Para vos', 'text'],
		'home_recommended_title'    => ['Portada', 'Recomendados — título', 'Recomendados', 'text'],
		'home_recommended_subtitle' => ['Portada', 'Recomendados — subtítulo', 'Los mejor valorados por nuestra comunidad.', 'text'],
		'home_brands_label'         => ['Portada', 'Cinta de marcas — leyenda', 'Trabajamos con las mejores marcas', 'text'],
		'home_testimonials_eyebrow' => ['Portada', 'Opiniones — antetítulo', 'Comunidad', 'text'],
		'home_testimonials_title'   => ['Portada', 'Opiniones — título', 'Lo que dicen de nosotros', 'text'],
		'home_instagram_title'      => ['Portada', 'Instagram — título', 'Seguinos en Instagram', 'text'],
		'home_instagram_link'       => ['Portada', 'Instagram — texto del enlace', 'Seguinos', 'text'],
		'home_about_eyebrow'        => ['Portada', 'Nosotros — antetítulo', 'Sobre nosotros', 'text'],
		'home_about_title'          => ['Portada', 'Nosotros — título', 'Quiénes somos', 'text'],
		'home_about_text'           => ['Portada', 'Nosotros — texto', '', 'textarea', 'Vacío = el primer párrafo del texto "Nosotros" (Contenido).'],
		'home_about_link'           => ['Portada', 'Nosotros — texto del enlace', 'Conocenos', 'text'],
		// Menú y navegación
		'nav_home'                  => ['Menú', 'Inicio', 'Inicio', 'text'],
		'nav_shop'                  => ['Menú', 'Tienda', 'Tienda', 'text'],
		'nav_sale'                  => ['Menú', 'Ofertas', 'Ofertas', 'text'],
		'nav_new'                   => ['Menú', 'Novedades', 'Novedades', 'text'],
		'nav_contact'               => ['Menú', 'Contacto', 'Contacto', 'text'],
		'nav_about'                 => ['Menú', 'Nosotros', '', 'text', 'Vacío = la página Nosotros no aparece en el menú. Con texto, se agrega el enlace a /nosotros.'],
		'nav_search'                => ['Menú', 'Buscador (título accesible del botón)', 'Buscador', 'text'],
		'nav_cart'                  => ['Menú', 'Carrito (título accesible del botón)', 'Carrito', 'text'],
		'nav_account'               => ['Menú', 'Cuenta (título accesible del botón)', 'Cuenta', 'text'],
		'nav_home_mobile'           => ['Menú', 'Inicio — barra inferior del celular', 'Inicio', 'text'],
		// Tienda
		'shop_eyebrow'              => ['Tienda', 'Antetítulo de la tienda', 'Catálogo', 'text'],
		'shop_title'                => ['Tienda', 'Título de la tienda', 'Tienda', 'text'],
		'shop_sale_title'           => ['Tienda', 'Título de "Ofertas"', 'Ofertas', 'text'],
		'shop_new_title'            => ['Tienda', 'Título de "Novedades"', 'Novedades', 'text'],
		'shop_empty_title'          => ['Tienda', 'Sin resultados — título', 'Sin resultados', 'text'],
		'shop_empty_text'           => ['Tienda', 'Sin resultados — texto', 'Probá ajustando los filtros o buscá otra cosa.', 'text'],
		'brands_eyebrow'            => ['Tienda', 'Página de marcas — antetítulo', 'Catálogo', 'text'],
		'brands_subtitle'           => ['Tienda', 'Página de marcas — subtítulo', 'Trabajamos con las mejores marcas del mercado.', 'text'],
		// Producto
		'product_related_eyebrow'   => ['Producto', 'Relacionados — antetítulo', 'Seguí explorando', 'text'],
		'product_related_title'     => ['Producto', 'Relacionados — título', 'También te puede gustar', 'text'],
		'product_recent_eyebrow'    => ['Producto', 'Vistos recientemente — antetítulo', 'Tu historial', 'text'],
		'product_recent_title'      => ['Producto', 'Vistos recientemente — título', 'Vistos recientemente', 'text'],
		// Pie de página
		'footer_newsletter_title'   => ['Pie de página', 'Newsletter — título', 'Lo nuevo, antes que nadie.', 'text'],
		'footer_newsletter_text'    => ['Pie de página', 'Newsletter — texto', 'Lanzamientos, ofertas exclusivas y novedades. Sin spam, prometido.', 'text'],
		'footer_tagline'            => ['Pie de página', 'Frase bajo el logo', '', 'text', 'Vacío = la descripción corta del sitio (Ajustes → Generales).'],
		'footer_rights'             => ['Pie de página', 'Leyenda legal', 'Todos los derechos reservados.', 'text'],
		'footer_group_shop'         => ['Pie de página', 'Columna "Tienda" — título', 'Tienda', 'text'],
		'footer_group_help'         => ['Pie de página', 'Columna "Ayuda" — título', 'Ayuda', 'text'],
		'footer_group_account'      => ['Pie de página', 'Columna "Cuenta" — título', 'Cuenta', 'text'],
		'footer_link_contact'       => ['Pie de página', 'Enlace: Contacto', 'Contacto', 'text'],
		'footer_link_faq'           => ['Pie de página', 'Enlace: Preguntas frecuentes', 'Preguntas frecuentes', 'text'],
		'footer_link_terms'         => ['Pie de página', 'Enlace: Términos y condiciones', 'Términos y condiciones', 'text'],
		'footer_link_privacy'       => ['Pie de página', 'Enlace: Privacidad', 'Privacidad', 'text'],
		'footer_link_account'       => ['Pie de página', 'Enlace: Mi cuenta', 'Mi cuenta', 'text'],
		'footer_link_orders'        => ['Pie de página', 'Enlace: Pedidos', 'Pedidos', 'text'],
		'footer_link_wishlist'      => ['Pie de página', 'Enlace: Favoritos', 'Favoritos', 'text'],
		// Páginas
		'about_eyebrow'             => ['Páginas', 'Nosotros — antetítulo', 'Nosotros', 'text'],
		'about_title'               => ['Páginas', 'Nosotros — título', 'No vendemos productos. Diseñamos la forma de conseguirlos.', 'text'],
		'about_highlights_title'    => ['Páginas', 'Nosotros — título de los puntos destacados', '', 'text', 'Encabezado de la grilla de puntos destacados (Contenido → Nosotros). Vacío = sin título.'],
		'about_cta_text'            => ['Páginas', 'Nosotros — texto del botón', '', 'text', 'Botón al final de la página Nosotros. Vacío = sin botón.'],
		'about_cta_url'             => ['Páginas', 'Nosotros — enlace del botón', '', 'text', 'Ruta o URL a la que lleva el botón (ej. /turnos, /contacto).'],
		'contact_eyebrow'           => ['Páginas', 'Contacto — antetítulo', 'Hablemos', 'text'],
		'contact_title'             => ['Páginas', 'Contacto — título', 'Contacto', 'text'],
		'contact_subtitle'          => ['Páginas', 'Contacto — subtítulo', 'Escribinos y te respondemos a la brevedad.', 'text'],
		'faq_subtitle'              => ['Páginas', 'Preguntas frecuentes — subtítulo', 'Todo lo que necesitás saber antes de comprar.', 'text'],
	];
	return apply_filters('mj_copy_fields', $fields);
}

/* Defaults efectivos (los del tema, retocados por la skin). */
function mj_copy_defaults() {
	$defaults = [];
	foreach (mj_copy_fields() as $key => $def) {
		$defaults[$key] = $def[2];
	}
	return (array) apply_filters('mj_copy_defaults', $defaults);
}

/* Textos efectivos: default + lo que el admin escribió (no vacío). */
function mj_copy() {
	$copy  = mj_copy_defaults();
	$saved = mj_get_options()['copy'];
	foreach ($copy as $key => $default) {
		if (isset($saved[$key]) && '' !== trim((string) $saved[$key])) {
			$copy[$key] = (string) $saved[$key];
		}
	}
	return $copy;
}

/*
 * Secciones de la portada que se pueden ocultar y ordenar (key => etiqueta).
 * Los plugins MJ suman las suyas con el filtro (MJ Colegas → 'colegas' ya
 * está prevista acá; MJ Turnos Online agrega 'services' y 'booking').
 */
function mj_home_sections() {
	return apply_filters('mj_home_sections', [
		'banners'      => 'Slider de banners',
		'featured'     => 'Destacados',
		'categories'   => 'Categorías',
		'latest'       => 'Últimos ingresos',
		'recommended'  => 'Recomendados',
		'colegas'      => 'Colegas / proyecto (si el plugin MJ Colegas está activo)',
		'brands'       => 'Cinta de marcas',
		'testimonials' => 'Opiniones',
		'instagram'    => 'Instagram',
		'about'        => 'Sobre nosotros (foto + texto de la portada)',
	]);
}

/*
 * Orden por defecto de la portada. Las secciones que un plugin registre y no
 * figuren en esta lista van al final, en el orden en que se registraron.
 * Un tema hijo puede proponer otro orden con `mj_home_default_order` (y con
 * `after_switch_theme` escribir `home_order`, como hace GlossyHair).
 */
function mj_home_default_order() {
	$base  = ['banners', 'featured', 'categories', 'latest', 'colegas', 'recommended', 'brands', 'testimonials', 'instagram', 'services', 'booking', 'about'];
	$all   = array_keys(mj_home_sections());
	$order = array_merge(array_values(array_intersect($base, $all)), array_values(array_diff($all, $base)));
	$order = (array) apply_filters('mj_home_default_order', $order, $all);
	return array_values(array_unique(array_intersect(array_map('sanitize_key', $order), $all)));
}

/*
 * Orden efectivo de la portada → array de keys válidas: primero las que el
 * admin ordenó (home_order), después las nuevas que todavía no listó (en su
 * orden por defecto). Siempre incluye TODAS las secciones registradas; la
 * SPA decide cuáles renderiza según mj_home_hidden() y los datos que haya.
 */
function mj_home_order() {
	$default = mj_home_default_order();
	$saved   = array_filter(array_map('sanitize_key', explode(',', (string) mj_get_options()['home_order'])));
	$saved   = array_values(array_unique(array_intersect($saved, $default)));
	return array_values(array_merge($saved, array_diff($default, $saved)));
}

/* Secciones ocultas → array de keys válidas. */
function mj_home_hidden() {
	$valid = array_keys(mj_home_sections());
	$keys  = array_filter(array_map('sanitize_key', explode(',', (string) mj_get_options()['home_hidden'])));
	return array_values(array_intersect($keys, $valid));
}

/*
 * Textarea legal → [{h, body}]. Formato:
 *   # Título de la sección
 *   Párrafo (una o más líneas).
 *
 *   # Otra sección
 *   ...
 * Texto sin ningún "#" → una sola sección sin título.
 */
function mj_parse_legal($text) {
	$out     = [];
	$current = null;
	foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
		$line = trim($line);
		if ('' === $line) {
			continue;
		}
		if ('#' === $line[0]) {
			if ($current) {
				$out[] = $current;
			}
			$current = ['h' => trim(ltrim($line, '#')), 'body' => ''];
			continue;
		}
		if (!$current) {
			$current = ['h' => '', 'body' => ''];
		}
		$current['body'] .= ('' === $current['body'] ? '' : "\n") . $line;
	}
	if ($current) {
		$out[] = $current;
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Logo
 *
 * Prioridad: adjunto elegido acá > logo del Personalizador (custom_logo) >
 * nada (la SPA muestra la inicial del sitio + el nombre).
 * ---------------------------------------------------------------------- */
function mj_logo_config() {
	$o        = mj_get_options();
	$logo_id  = absint($o['logo_id']);
	if (!$logo_id) {
		$logo_id = absint(get_theme_mod('custom_logo', 0));
	}
	$dark_id = absint($o['logo_dark_id']);

	$to = function ($id) {
		if (!$id) {
			return null;
		}
		$src = wp_get_attachment_image_src($id, 'full');
		if (!$src) {
			return null;
		}
		return [
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		];
	};

	$light = $to($logo_id);
	$dark  = $to($dark_id);

	return [
		'url'      => $light ? $light['url'] : '',
		'width'    => $light ? $light['width'] : 0,
		'height'   => $light ? $light['height'] : 0,
		'dark'     => $dark ? $dark['url'] : '',
		'showName' => (bool) $o['logo_show_name'],
		'alt'      => get_bloginfo('name'),
	];
}

/* Etiquetas de "marca" (la SPA deriva marcas del atributo Marca/Brand). */
function mj_brand_labels() {
	$o = mj_get_options();
	return [
		'singular' => '' !== trim((string) $o['brand_label']) ? $o['brand_label'] : 'Marca',
		'plural'   => '' !== trim((string) $o['brand_label_plural']) ? $o['brand_label_plural'] : 'Marcas',
	];
}

/**
 * Normaliza lo que pegue el usuario como "ubicación de Google Maps" a una URL
 * embebible en un <iframe>, sin API key. Acepta:
 *  - el código "Insertar mapa" completo (<iframe src="…"> de Google Maps),
 *  - una URL de Google Maps ya embebible (/maps/embed o output=embed),
 *  - una URL normal de Google Maps (se convierte vía ?q=…&output=embed),
 *  - texto plano (dirección o lugar; idem).
 * Cualquier otra URL → '' (no se muestra).
 */
function mj_map_embed_url($raw) {
	$raw = trim((string) $raw);
	if ('' === $raw) {
		return '';
	}

	/* Si pegaron el <iframe> completo, extraer el src. */
	if (false !== stripos($raw, '<iframe') && preg_match('/src=["\']([^"\']+)["\']/', $raw, $m)) {
		$raw = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
	}
	$raw = trim($raw);

	if (preg_match('#^https?://#i', $raw)) {
		if (false !== stripos($raw, '/maps/embed') || false !== stripos($raw, 'output=embed')) {
			return esc_url_raw($raw);
		}
		if (preg_match('#^https?://(www\.)?google\.[a-z.]+/maps#i', $raw)
			|| false !== stripos($raw, 'maps.app.goo.gl')
			|| false !== stripos($raw, 'goo.gl/maps')) {
			return mj_map_embed_query($raw);
		}
		return '';
	}

	/* Texto plano: dirección o nombre del lugar. */
	return mj_map_embed_query($raw);
}

/*
 * URL para incrustar un mapa a partir de una consulta (dirección o enlace).
 *
 * La base sale del ajuste "Servicio de mapas" y se puede apuntar a cualquier
 * proveedor que acepte la consulta por query string; vacío = Google Maps.
 * El marcador {q} se reemplaza por la consulta ya escapada; si la base no lo
 * incluye, se agrega al final.
 */
function mj_map_embed_query($query) {
	$base = trim((string) mj_get_options()['maps_embed_base']);
	if ('' === $base) {
		$base = 'https://maps.google.com/maps?q={q}&output=embed';
	}
	$base = (string) apply_filters('mj_map_embed_base', $base, $query);

	$encoded = rawurlencode($query);
	$url     = false !== strpos($base, '{q}')
		? str_replace('{q}', $encoded, $base)
		: $base . (false === strpos($base, '?') ? '?' : '&') . 'q=' . $encoded;

	return esc_url_raw($url);
}

/* -------------------------------------------------------------------------
 * Settings API
 * ---------------------------------------------------------------------- */
/*
 * Grupo de ajustes. options.php lo usa para armar el nombre del filtro que
 * decide quién puede guardar, así que vive en una función y no suelto.
 */
function mj_settings_option_group() {
	return 'mj_options_group';
}

/*
 * ESTE es el filtro que options.php consulta para autorizar el guardado
 * (por defecto exige manage_options, sin importar qué capacidad pida el
 * menú). Sin él, quien ve la página recibe "no tenés permisos" al guardar.
 */
add_filter('option_page_capability_' . mj_settings_option_group(), 'mj_settings_capability');

add_action('admin_init', function () {
	register_setting(mj_settings_option_group(), 'mj_options', [
		'sanitize_callback' => 'mj_sanitize_options',
		'default'           => mj_option_defaults(),
		/*
		 * options.php exige esta capacidad para guardar. Tiene que ser la MISMA
		 * que pide el menú (mj_settings_capability), o quien ve la página no
		 * puede guardarla.
		 */
		'capability'        => mj_settings_capability(),
	]);
});

function mj_sanitize_options($input) {
	$input = is_array($input) ? $input : [];
	$out   = [];

	$out['announcements'] = isset($input['announcements']) ? sanitize_textarea_field($input['announcements']) : '';
	$out['product_badges'] = isset($input['product_badges']) ? sanitize_textarea_field($input['product_badges']) : '';
	$out['whatsapp_numbers'] = isset($input['whatsapp_numbers']) ? sanitize_textarea_field($input['whatsapp_numbers']) : '';
	$out['quote_info'] = isset($input['quote_info']) ? sanitize_textarea_field($input['quote_info']) : '';

	$out['hero_badge']              = isset($input['hero_badge']) ? sanitize_text_field($input['hero_badge']) : '';
	$out['hero_title']              = isset($input['hero_title']) ? sanitize_text_field($input['hero_title']) : '';
	$out['hero_title_highlight']    = isset($input['hero_title_highlight']) ? sanitize_text_field($input['hero_title_highlight']) : '';
	$out['hero_subtitle']           = isset($input['hero_subtitle']) ? sanitize_text_field($input['hero_subtitle']) : '';
	$out['hero_cta_primary_text']   = isset($input['hero_cta_primary_text']) ? sanitize_text_field($input['hero_cta_primary_text']) : '';
	$out['hero_cta_primary_url']    = isset($input['hero_cta_primary_url']) ? esc_url_raw($input['hero_cta_primary_url']) : '';
	$out['hero_cta_secondary_text'] = isset($input['hero_cta_secondary_text']) ? sanitize_text_field($input['hero_cta_secondary_text']) : '';
	$out['hero_cta_secondary_url']  = isset($input['hero_cta_secondary_url']) ? esc_url_raw($input['hero_cta_secondary_url']) : '';

	// IDs de adjuntos separados por coma (selector de medios).
	$ids_csv = function ($key) use ($input) {
		return isset($input[$key])
			? implode(',', array_filter(array_map('absint', explode(',', (string) $input[$key]))))
			: '';
	};
	$out['hero_bg_images'] = $ids_csv('hero_bg_images');
	$out['promo_banners']  = $ids_csv('promo_banners');

	$out['contact_email']    = isset($input['contact_email']) ? sanitize_email($input['contact_email']) : '';
	$out['contact_phone']    = isset($input['contact_phone']) ? sanitize_text_field($input['contact_phone']) : '';
	$out['contact_whatsapp'] = isset($input['contact_whatsapp']) ? sanitize_text_field($input['contact_whatsapp']) : '';
	$out['contact_address']  = isset($input['contact_address']) ? sanitize_text_field($input['contact_address']) : '';
	$out['contact_map']      = mj_map_embed_url(isset($input['contact_map']) ? $input['contact_map'] : '');

	foreach (['instagram', 'facebook', 'twitter', 'youtube', 'tiktok'] as $net) {
		$key        = 'social_' . $net;
		$out[$key] = isset($input[$key]) ? esc_url_raw($input[$key]) : '';
	}

	$out['about_text'] = isset($input['about_text']) ? sanitize_textarea_field($input['about_text']) : '';
	$out['faqs']       = isset($input['faqs']) ? sanitize_textarea_field($input['faqs']) : '';

	// 3.5 — "Nosotros": foto (adjunto único), puntos destacados y cifras.
	$out['about_image']      = isset($input['about_image']) ? absint(strtok((string) $input['about_image'], ',')) : 0;
	$out['about_highlights'] = isset($input['about_highlights']) ? sanitize_textarea_field($input['about_highlights']) : '';
	$out['about_stats']      = isset($input['about_stats']) ? sanitize_textarea_field($input['about_stats']) : '';

	$out['free_shipping_override'] = isset($input['free_shipping_override']) ? absint($input['free_shipping_override']) : 0;

	// Categorías del menú: checkboxes → slugs CSV, máximo 4.
	$slugs = isset($input['menu_categories']) ? (array) $input['menu_categories'] : [];
	$slugs = array_values(array_filter(array_map('sanitize_title', $slugs)));
	$out['menu_categories'] = implode(',', array_slice($slugs, 0, 4));

	// Diseño: tema por defecto, paleta y tipografías.
	$theme               = isset($input['design_theme']) ? (string) $input['design_theme'] : 'dark';
	$out['design_theme'] = isset(mj_theme_choices()[$theme]) ? $theme : 'dark';

	$motion               = isset($input['motion_level']) ? (string) $input['motion_level'] : 'medium';
	$out['motion_level']  = isset(mj_motion_choices()[$motion]) ? $motion : 'medium';
	$out['intro_enabled'] = !empty($input['intro_enabled']) ? 1 : 0;

	/* Sesión y acceso. */
	$bar               = isset($input['admin_bar']) ? (string) $input['admin_bar'] : 'profile';
	$out['admin_bar']  = isset(mj_admin_bar_modes()[$bar]) ? $bar : 'profile';

	/*
	 * Duración de la sesión en días. 0 = "lo que use WordPress"; el tope evita
	 * cookies eternas por un cero de más al tipear.
	 */
	$out['session_days']           = min(365, max(0, isset($input['session_days']) ? absint($input['session_days']) : 0));
	$out['session_days_remember']  = min(365, max(0, isset($input['session_days_remember']) ? absint($input['session_days_remember']) : 0));
	$out['login_remember_default'] = !empty($input['login_remember_default']) ? 1 : 0;
	$out['login_reload']           = !empty($input['login_reload']) ? 1 : 0;

	/* Ruta de "mi cuenta" en la SPA: se guarda sin dominio y sin barras sobrantes. */
	$account_path         = isset($input['account_path']) ? (string) $input['account_path'] : '';
	$account_path         = trim(sanitize_text_field($account_path));
	$account_path         = trim((string) wp_parse_url($account_path, PHP_URL_PATH), '/');
	$out['account_path']  = '' !== $account_path ? '/' . $account_path : '';

	/* Skins y CDN de íconos del admin. */
	$out['skin_defaults'] = !empty($input['skin_defaults']) ? 1 : 0;
	$out['icons_cdn']     = isset($input['icons_cdn']) ? esc_url_raw(trim((string) $input['icons_cdn'])) : '';

	/*
	 * Base para incrustar mapas: solo se acepta una URL; el sanitizador de
	 * mj_map_embed_url() la completa con la consulta del visitante.
	 */
	$out['maps_embed_base'] = isset($input['maps_embed_base']) ? esc_url_raw(trim((string) $input['maps_embed_base'])) : '';

	foreach (array_keys(mj_color_defaults()) as $color_key) {
		$hex = isset($input[$color_key]) ? sanitize_hex_color((string) $input[$color_key]) : '';
		$out[$color_key] = $hex ? $hex : '';
	}

	foreach (['font_display', 'font_sans'] as $font_key) {
		$font = isset($input[$font_key]) ? sanitize_text_field((string) $input[$font_key]) : '';
		$out[$font_key] = in_array($font, mj_font_choices(), true) ? $font : '';
	}

	// Logo (adjunto único por campo) y nombre junto al logo.
	$out['logo_id']        = isset($input['logo_id']) ? absint(strtok((string) $input['logo_id'], ',')) : 0;
	$out['logo_dark_id']   = isset($input['logo_dark_id']) ? absint(strtok((string) $input['logo_dark_id'], ',')) : 0;
	$out['logo_show_name'] = !empty($input['logo_show_name']) ? 1 : 0;

	// Etiquetas de marca.
	$out['brand_label']        = isset($input['brand_label']) ? sanitize_text_field($input['brand_label']) : '';
	$out['brand_label_plural'] = isset($input['brand_label_plural']) ? sanitize_text_field($input['brand_label_plural']) : '';

	// Textos de la tienda: solo claves conocidas; vacío = default.
	$copy_in  = isset($input['copy']) && is_array($input['copy']) ? $input['copy'] : [];
	$copy_out = [];
	foreach (mj_copy_fields() as $key => $def) {
		if (!isset($copy_in[$key])) {
			continue;
		}
		$value = 'textarea' === $def[3]
			? sanitize_textarea_field((string) $copy_in[$key])
			: sanitize_text_field((string) $copy_in[$key]);
		if ('' !== trim($value)) {
			$copy_out[$key] = $value;
		}
	}
	$out['copy'] = $copy_out;

	// Secciones de la portada: llegan las VISIBLES; se guardan las ocultas.
	// El formulario manda un centinela vacío antes de la lista: "ninguna tildada" llega como array vacío (se ocultan todas) en vez de no llegar.
	$all     = array_keys(mj_home_sections());
	$visible = isset($input['home_visible'])
		? array_values(array_filter(array_map('sanitize_key', array_filter((array) $input['home_visible'], 'is_string'))))
		: $all;
	$out['home_hidden'] = implode(',', array_values(array_diff($all, $visible)));

	// Orden de la portada: CSV que arma el JS al arrastrar; solo keys válidas, sin repetir.
	$order = isset($input['home_order']) ? array_filter(array_map('sanitize_key', explode(',', (string) $input['home_order']))) : [];
	$out['home_order'] = implode(',', array_values(array_unique(array_intersect($order, $all))));

	// Legales.
	$out['legal_terms']   = isset($input['legal_terms']) ? sanitize_textarea_field($input['legal_terms']) : '';
	$out['legal_privacy'] = isset($input['legal_privacy']) ? sanitize_textarea_field($input['legal_privacy']) : '';

	return $out;
}

/* -------------------------------------------------------------------------
 * Página de ajustes (Apariencia → MJ Appearance)
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
	add_theme_page(
		mj_settings_page_title(),
		mj_settings_menu_title(),
		mj_settings_capability(),
		mj_settings_page_slug(),
		'mj_render_settings_page'
	);
});

/*
 * Selector de medios, íconos Lucide y jQuery UI Sortable (orden de las
 * secciones de la portada) solo en la página de ajustes del tema.
 */
add_action('admin_enqueue_scripts', function ($hook) {
	if ('appearance_page_' . mj_settings_page_slug() === $hook) {
		wp_enqueue_media();
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('lucide', mj_icons_script_url(), [], null, true);
	}
});

/* -------------------------------------------------------------------------
 * Descubribilidad de la página de ajustes
 *
 * El menú de Apariencia se puede perder de vista (plugins que reordenan el
 * admin, roles a medida, menús colapsados). Los ajustes del tema son EL lugar
 * donde se configura toda la tienda, así que además del submenú se ofrecen
 * dos accesos más: el enlace "Ajustes" en la tarjeta del tema activo y una
 * entrada en la barra de administración. Los dos respetan la misma capacidad.
 * ---------------------------------------------------------------------- */

/* Enlace "Ajustes" en la tarjeta del tema activo (Apariencia → Temas). */
add_filter('theme_action_links', function ($actions, $theme) {
	if (!current_user_can(mj_settings_capability())) {
		return $actions;
	}
	/* Solo en el tema activo, sea el padre o cualquier hijo suyo. */
	$stylesheet = $theme instanceof WP_Theme ? $theme->get_stylesheet() : '';
	if ('' === $stylesheet || get_stylesheet() !== $stylesheet) {
		return $actions;
	}
	$actions['mj-settings'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url(mj_settings_page_url()),
		esc_html(mj_settings_menu_title())
	);
	return $actions;
}, 10, 2);

/* Acceso directo desde la barra de administración (front y escritorio). */
add_action('admin_bar_menu', function ($bar) {
	if (!current_user_can(mj_settings_capability()) || !($bar instanceof WP_Admin_Bar)) {
		return;
	}
	$bar->add_node([
		'id'     => 'mj-settings',
		'parent' => 'site-name',
		'title'  => mj_settings_menu_title(),
		'href'   => mj_settings_page_url(),
	]);
}, 80);

/*
 * Script de íconos del selector del admin (Lucide, los mismos del frontend).
 *
 * La versión va fijada a propósito: apuntar a "latest" hace que una
 * publicación de la librería pueda romper el panel sin que nadie tocara el
 * sitio. Se cambia sin editar código desde el ajuste "Íconos del panel" o con
 * el filtro `mj_icons_script_url` (por ejemplo, para servirlo desde el
 * servidor propio en instalaciones sin acceso a CDNs).
 */
function mj_icons_script_url() {
	$configured = trim((string) mj_get_options()['icons_cdn']);
	$version    = (string) apply_filters('mj_icons_version', MJ_ICONS_VERSION);
	$default    = 'https://unpkg.com/lucide@' . rawurlencode($version) . '/dist/umd/lucide.min.js';
	$url        = '' !== $configured ? $configured : $default;
	return esc_url_raw((string) apply_filters('mj_icons_script_url', $url));
}

/* -------------------------------------------------------------------------
 * Íconos disponibles (Lucide — los mismos que usa el frontend).
 * El nombre se guarda en kebab-case: "truck", "shield-check", …
 * Catálogo completo: https://lucide.dev/icons
 * ---------------------------------------------------------------------- */
function mj_icon_choices() {
	$icons = [
		'truck', 'package', 'shield-check', 'credit-card', 'banknote', 'headphones',
		'phone', 'mail', 'map-pin', 'clock', 'calendar', 'check', 'star', 'heart',
		'gift', 'percent', 'tag', 'zap', 'flame', 'award', 'crown', 'gem', 'leaf',
		'snowflake', 'sun', 'globe', 'lock', 'key', 'user', 'users', 'smile',
		'thumbs-up', 'badge-check', 'sparkles', 'scissors', 'ruler', 'palette',
		'camera', 'box', 'shopping-bag', 'shopping-cart', 'store', 'home',
		'refresh-ccw', 'rotate-ccw', 'bell', 'info', 'message-circle', 'send',
		'download', 'file-text', 'wrench', 'lightbulb', 'battery-charging', 'plug',
		'shirt', 'watch', 'glasses', 'umbrella', 'coffee', 'medal', 'rocket',
		'timer', 'coins', 'qr-code', 'barcode', 'ticket', 'megaphone', 'party-popper',
		'hand-heart', 'brush', 'pencil', 'book-open', 'graduation-cap', 'video',
	];
	/* Cualquier ícono de lucide.dev sirve: el filtro permite sumar los que falten. */
	return array_values(array_unique(array_filter(array_map('sanitize_key', (array) apply_filters('mj_icon_choices', $icons)))));
}

/** Selector visual de ícono: inserta " | nombre" en la línea del cursor del textarea. */
function mj_render_icon_picker($textarea_id) {
	?>
	<button type="button" class="button mj-icon-toggle" data-target="<?php echo esc_attr($textarea_id); ?>" style="margin-top:6px;">Elegir ícono…</button>
	<div class="mj-icon-grid" data-target="<?php echo esc_attr($textarea_id); ?>" style="display:none; margin-top:8px; padding:10px; border:1px solid #c3c4c7; border-radius:6px; background:#fff; max-width:560px;">
		<?php foreach (mj_icon_choices() as $name) : ?>
			<button type="button" class="mj-icon-pick" data-icon="<?php echo esc_attr($name); ?>" title="<?php echo esc_attr($name); ?>" style="width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #dcdcde; border-radius:6px; background:#f6f7f7; cursor:pointer; margin:2px;">
				<i data-lucide="<?php echo esc_attr($name); ?>" style="width:16px; height:16px;"></i>
			</button>
		<?php endforeach; ?>
		<p class="description" style="margin:6px 2px 0; flex-basis:100%;">Clic en un ícono para agregarlo al final de la línea donde está el cursor. Más íconos: escribí el nombre a mano desde <a href="https://lucide.dev/icons" target="_blank" rel="noreferrer">lucide.dev/icons</a>.</p>
	</div>
	<?php
}

/**
 * Selector de imágenes (media library) reutilizable: hero, banners, logo…
 * $multiple = false → una sola imagen (elegir otra la reemplaza).
 */
function mj_render_media_picker($field, $ids_csv, $button_label, $description, $multiple = true) {
	$ids = array_filter(array_map('absint', explode(',', (string) $ids_csv)));
	if (!$multiple) {
		$ids = array_slice($ids, 0, 1);
	}
	?>
	<div class="mj-media-preview" data-field="<?php echo esc_attr($field); ?>" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
		<?php foreach ($ids as $id) :
			$thumb = wp_get_attachment_image_url($id, 'medium');
			if (!$thumb) {
				continue;
			}
			?>
			<span class="mj-media-item" data-id="<?php echo esc_attr($id); ?>" style="position:relative; display:inline-block;">
				<img src="<?php echo esc_url($thumb); ?>" alt="" style="width:80px; height:80px; object-fit:contain; border-radius:6px; display:block; background:#f0f0f1;" />
				<button type="button" class="mj-media-remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>
			</span>
		<?php endforeach; ?>
	</div>
	<input type="hidden" id="mj_<?php echo esc_attr($field); ?>" name="mj_options[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr(implode(',', $ids)); ?>" />
	<button type="button" class="button mj-media-add" data-field="<?php echo esc_attr($field); ?>" data-multiple="<?php echo $multiple ? '1' : '0'; ?>"><?php echo esc_html($button_label); ?></button>
	<p class="description"><?php echo esc_html($description); ?></p>
	<?php
}

/** Campo de texto/textarea de copy con el default efectivo como placeholder (y descripción opcional). */
function mj_render_copy_field($key, $def, $saved, $default) {
	$value = isset($saved[$key]) ? (string) $saved[$key] : '';
	$id    = 'mj_copy_' . $key;
	$type  = isset($def[3]) ? $def[3] : 'text';
	$help  = isset($def[4]) ? (string) $def[4] : '';
	?>
	<tr>
		<th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($def[1]); ?></label></th>
		<td>
			<?php if ('textarea' === $type) : ?>
				<textarea id="<?php echo esc_attr($id); ?>" name="mj_options[copy][<?php echo esc_attr($key); ?>]" rows="3" class="large-text" placeholder="<?php echo esc_attr($default); ?>"><?php echo esc_textarea($value); ?></textarea>
			<?php else : ?>
				<input type="text" id="<?php echo esc_attr($id); ?>" name="mj_options[copy][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" class="large-text" placeholder="<?php echo esc_attr($default); ?>" />
			<?php endif; ?>
			<?php if ('' !== $help) : ?>
				<p class="description"><?php echo esc_html($help); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

function mj_render_settings_page() {
	if (!current_user_can(mj_settings_capability())) {
		return;
	}
	$o = mj_get_options();
	?>
	<div class="wrap">
		<h1>MJ Appearance — Ajustes del tema</h1>
		<p>Toda la información que ve el cliente en la tienda se configura acá. Nada está hardcodeado en el frontend: lo que dejes vacío usa el texto por defecto del tema (o del tema hijo activo), que se ve en gris como referencia.</p>

		<form method="post" action="options.php">
			<?php settings_fields(mj_settings_option_group()); ?>

			<h2>Diseño y marca</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Logo</th>
					<td>
						<?php mj_render_media_picker('logo_id', $o['logo_id'], 'Elegir logo…', 'Se muestra en el header, el pie de página y la intro. Ideal: PNG o SVG con fondo transparente, al menos 200 px de alto. Vacío = usa el logo del Personalizador si existe; si no, la inicial y el nombre del sitio.', false); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Logo para modo noche</th>
					<td>
						<?php mj_render_media_picker('logo_dark_id', $o['logo_dark_id'], 'Elegir variante…', 'Opcional: versión clara del logo para fondos oscuros. Vacío = se usa el mismo logo.', false); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Nombre junto al logo</th>
					<td>
						<label for="mj_logo_show_name">
							<input type="checkbox" id="mj_logo_show_name" name="mj_options[logo_show_name]" value="1" <?php checked((int) $o['logo_show_name'], 1); ?> />
							Mostrar el nombre del sitio (Ajustes → Generales) al lado del logo.
						</label>
						<p class="description">Desmarcalo si tu logo ya incluye el nombre.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_design_theme">Tema por defecto</label></th>
					<td>
						<select id="mj_design_theme" name="mj_options[design_theme]">
							<?php foreach (mj_theme_choices() as $mj_value => $mj_label) : ?>
								<option value="<?php echo esc_attr($mj_value); ?>" <?php selected($o['design_theme'], $mj_value); ?>><?php echo esc_html($mj_label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Con qué tema se abre la tienda la primera vez. El visitante puede alternarlo con el botón sol/luna del header y su elección queda guardada en su navegador.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_motion_level">Nivel de animación</label></th>
					<td>
						<select id="mj_motion_level" name="mj_options[motion_level]">
							<?php foreach (mj_motion_choices() as $mj_value => $mj_label) : ?>
								<option value="<?php echo esc_attr($mj_value); ?>" <?php selected($o['motion_level'], $mj_value); ?>><?php echo esc_html($mj_label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Si el visitante tiene activado "reducir movimiento" en su sistema, la tienda respeta esa preferencia sea cual sea este nivel.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Intro animada</th>
					<td>
						<label for="mj_intro_enabled">
							<input type="checkbox" id="mj_intro_enabled" name="mj_options[intro_enabled]" value="1" <?php checked((int) $o['intro_enabled'], 1); ?> />
							Mostrar una cortina con el logo al entrar al sitio (una vez por sesión, menos de un segundo).
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Paleta de colores</th>
					<td>
						<?php
						$color_labels = [
							'color_accent'    => 'Color de acento (botones, links, glow)',
							'color_bg_dark'   => 'Fondo — tema oscuro',
							'color_ink_dark'  => 'Texto — tema oscuro',
							'color_bg_light'  => 'Fondo — tema claro',
							'color_ink_light' => 'Texto — tema claro',
						];
						$color_defaults = mj_color_defaults();
						foreach ($color_labels as $key => $label) :
							$value = '' !== $o[$key] ? $o[$key] : $color_defaults[$key];
							?>
							<p style="display:flex; align-items:center; gap:10px; margin:6px 0;">
								<input type="color" id="mj_<?php echo esc_attr($key); ?>" name="mj_options[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" data-default="<?php echo esc_attr($color_defaults[$key]); ?>" style="width:56px; height:32px; padding:2px; cursor:pointer;" />
								<label for="mj_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
							</p>
						<?php endforeach; ?>
						<button type="button" class="button mj-color-reset" style="margin-top:6px;">Restaurar colores por defecto</button>
						<p class="description">Los tonos secundarios (fondos suaves, bordes, glow) se derivan automáticamente de estos colores. Con un tema hijo activo, "por defecto" es la paleta de ese tema hijo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_font_display">Tipografía de títulos</label></th>
					<td>
						<select id="mj_font_display" name="mj_options[font_display]">
							<option value="">Por defecto del tema</option>
							<?php foreach (mj_font_choices() as $font) : ?>
								<option value="<?php echo esc_attr($font); ?>" <?php selected($o['font_display'], $font); ?>><?php echo esc_html($font); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_font_sans">Tipografía de texto</label></th>
					<td>
						<select id="mj_font_sans" name="mj_options[font_sans]">
							<option value="">Por defecto del tema</option>
							<?php foreach (mj_font_choices() as $font) : ?>
								<option value="<?php echo esc_attr($font); ?>" <?php selected($o['font_sans'], $font); ?>><?php echo esc_html($font); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Ambas se cargan desde Google Fonts (pesos 400–700).</p>
					</td>
				</tr>
			</table>

			<h2>Sesión y acceso</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_admin_bar">Barra de administración</label></th>
					<td>
						<select id="mj_admin_bar" name="mj_options[admin_bar]">
							<?php foreach (mj_admin_bar_modes() as $mj_value => $mj_label) : ?>
								<option value="<?php echo esc_attr($mj_value); ?>" <?php selected($o['admin_bar'], $mj_value); ?>><?php echo esc_html($mj_label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Quién ve la barra negra de WordPress arriba de la tienda. "Según el perfil" respeta la casilla <em>Mostrar la barra de herramientas al ver el sitio</em> de cada usuario (Usuarios → Perfil), que es el comportamiento normal de WordPress.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Al iniciar sesión</th>
					<td>
						<label for="mj_login_reload">
							<input type="checkbox" id="mj_login_reload" name="mj_options[login_reload]" value="1" <?php checked((int) $o['login_reload'], 1); ?> />
							Recargar la tienda al iniciar y cerrar sesión.
						</label>
						<p class="description">Recomendado. La tienda es una aplicación de una sola página: sin recargar, WordPress no puede pintar la barra de administración de quien acaba de entrar. Si lo desmarcás, la sesión igual queda bien iniciada, pero la barra recién aparece en la próxima carga.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">"Recordarme"</th>
					<td>
						<label for="mj_login_remember_default">
							<input type="checkbox" id="mj_login_remember_default" name="mj_options[login_remember_default]" value="1" <?php checked((int) $o['login_remember_default'], 1); ?> />
							Viene marcado en el formulario de acceso.
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_session_days">Duración de la sesión</label></th>
					<td>
						<p style="margin:6px 0;">
							<input type="number" id="mj_session_days" name="mj_options[session_days]" value="<?php echo esc_attr((string) (int) $o['session_days']); ?>" min="0" max="365" step="1" class="small-text" />
							<label for="mj_session_days">días sin "Recordarme"</label>
						</p>
						<p style="margin:6px 0;">
							<input type="number" id="mj_session_days_remember" name="mj_options[session_days_remember]" value="<?php echo esc_attr((string) (int) $o['session_days_remember']); ?>" min="0" max="365" step="1" class="small-text" />
							<label for="mj_session_days_remember">días con "Recordarme" marcado</label>
						</p>
						<p class="description">Cuánto dura la sesión de un cliente antes de tener que volver a entrar. 0 = lo que use WordPress (2 días, o 14 con "Recordarme"). Máximo 365. Sin "Recordarme", el navegador además cierra la sesión al cerrarse; con "Recordarme" queda guardada todos los días que pongas acá.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_account_path">Ruta de "Mi cuenta"</label></th>
					<td>
						<input type="text" id="mj_account_path" name="mj_options[account_path]" value="<?php echo esc_attr($o['account_path']); ?>" class="regular-text" placeholder="/cuenta" />
						<p class="description">A dónde lleva "Mi cuenta" dentro de la tienda. Vacío = <code>/cuenta</code>.</p>
					</td>
				</tr>
			</table>

			<?php mj_render_skin_section($o); ?>

			<h2>Categorías del menú</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Categorías en el header</th>
					<td>
						<?php
						$menu_terms = taxonomy_exists('product_cat')
							? get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false])
							: [];
						if (is_wp_error($menu_terms)) {
							$menu_terms = [];
						}
						$menu_selected = array_filter(explode(',', (string) $o['menu_categories']));
						?>
						<?php if (!$menu_terms) : ?>
							<p class="description">Todavía no hay categorías de producto (o WooCommerce está inactivo).</p>
						<?php else : ?>
							<fieldset style="display:flex; flex-wrap:wrap; gap:6px 18px; max-width:640px;">
								<?php foreach ($menu_terms as $term) :
									if (in_array($term->slug, ['uncategorized', 'sin-categorizar'], true)) {
										continue;
									}
									?>
									<label style="min-width:180px;">
										<input type="checkbox" class="mj-menu-cat" name="mj_options[menu_categories][]" value="<?php echo esc_attr($term->slug); ?>" <?php checked(in_array($term->slug, $menu_selected, true)); ?> />
										<?php echo esc_html($term->name); ?> <span style="color:#787c82;">(<?php echo (int) $term->count; ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">Elegí hasta <strong>4</strong> categorías para mostrar en el menú del header. Sin selección = automático: las 4 con más productos.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Cómo llamar a las "marcas"</th>
					<td>
						<input type="text" name="mj_options[brand_label]" value="<?php echo esc_attr($o['brand_label']); ?>" class="regular-text" placeholder="Marca" />
						<input type="text" name="mj_options[brand_label_plural]" value="<?php echo esc_attr($o['brand_label_plural']); ?>" class="regular-text" placeholder="Marcas" />
						<p class="description">Singular y plural. La tienda arma las "marcas" desde el atributo de producto que se llame Marca o Brand; acá cambiás cómo se muestra esa palabra (p. ej. "Hilado / Hilados", "Autor / Autores"). Vacío = Marca / Marcas.</p>
					</td>
				</tr>
			</table>

			<h2>Anuncios y avisos</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_announcements">Barra de anuncios</label></th>
					<td>
						<textarea id="mj_announcements" name="mj_options[announcements]" rows="4" class="large-text"><?php echo esc_textarea($o['announcements']); ?></textarea>
						<?php mj_render_icon_picker('mj_announcements'); ?>
						<p class="description">
							Un anuncio por línea; se muestran rotando en la barra superior del sitio.<br>
							Opcionales al final de cada línea: <code>| #hex</code> para el color y <code>| ícono</code> (se elige con el botón de arriba). Ejemplos:<br>
							<code>Envíos a todo el país | truck</code><br>
							<code>Liquidación por stock | #f59e0b | flame</code>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_product_badges">Avisos del producto</label></th>
					<td>
						<textarea id="mj_product_badges" name="mj_options[product_badges]" rows="3" class="large-text"><?php echo esc_textarea($o['product_badges']); ?></textarea>
						<?php mj_render_icon_picker('mj_product_badges'); ?>
						<p class="description">Uno por línea, con ícono opcional: <code>Envío en 24hs | truck</code>. Se muestran como tarjetas con ícono en la página de cada producto (debajo del botón de compra). Vacío = no se muestran.</p>
					</td>
				</tr>
			</table>

			<h2>Portada (hero)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_hero_badge">Texto del sello</label></th>
					<td>
						<input type="text" id="mj_hero_badge" name="mj_options[hero_badge]" value="<?php echo esc_attr($o['hero_badge']); ?>" class="regular-text" />
						<p class="description">Pastilla pequeña sobre el título. Vacío = no se muestra.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_hero_title">Título</label></th>
					<td><input type="text" id="mj_hero_title" name="mj_options[hero_title]" value="<?php echo esc_attr($o['hero_title']); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_hero_title_highlight">Título destacado</label></th>
					<td>
						<input type="text" id="mj_hero_title_highlight" name="mj_options[hero_title_highlight]" value="<?php echo esc_attr($o['hero_title_highlight']); ?>" class="regular-text" />
						<p class="description">Segunda línea del título, con degradado de color. Vacío = no se muestra.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_hero_subtitle">Subtítulo</label></th>
					<td>
						<input type="text" id="mj_hero_subtitle" name="mj_options[hero_subtitle]" value="<?php echo esc_attr($o['hero_subtitle']); ?>" class="large-text" />
						<p class="description">Vacío = usa la descripción corta del sitio (Ajustes → Generales).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Botón principal</th>
					<td>
						<input type="text" name="mj_options[hero_cta_primary_text]" value="<?php echo esc_attr($o['hero_cta_primary_text']); ?>" class="regular-text" placeholder="Texto (vacío = oculto)" />
						<input type="text" name="mj_options[hero_cta_primary_url]" value="<?php echo esc_attr($o['hero_cta_primary_url']); ?>" class="regular-text" placeholder="/tienda" />
					</td>
				</tr>
				<tr>
					<th scope="row">Botón secundario</th>
					<td>
						<input type="text" name="mj_options[hero_cta_secondary_text]" value="<?php echo esc_attr($o['hero_cta_secondary_text']); ?>" class="regular-text" placeholder="Texto (vacío = oculto)" />
						<input type="text" name="mj_options[hero_cta_secondary_url]" value="<?php echo esc_attr($o['hero_cta_secondary_url']); ?>" class="regular-text" placeholder="/ofertas" />
					</td>
				</tr>
				<tr>
					<th scope="row">Fotos de fondo</th>
					<td>
						<?php mj_render_media_picker('hero_bg_images', $o['hero_bg_images'], 'Elegir fotos…', 'Se muestran rotando como fondo del banner de la portada. Vacío = sin fotos (fondo liso con el glow de marca).'); ?>
					</td>
				</tr>
			</table>

			<h2>Banners promocionales</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Slider de banners</th>
					<td>
						<?php mj_render_media_picker('promo_banners', $o['promo_banners'], 'Elegir banners…', 'Se muestran como un slider con flechas debajo del hero, en el orden elegido. Vacío = no se muestra.'); ?>
					</td>
				</tr>
			</table>

			<h2>Secciones de la portada</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Qué se muestra y en qué orden</th>
					<td>
						<?php
						$hidden   = mj_home_hidden();
						$sections = mj_home_sections();
						$order    = mj_home_order();
						?>
						<?php /* Centinela: si se destildan todas, la clave igual viaja y el sanitize oculta todas. */ ?>
						<input type="hidden" name="mj_options[home_visible][]" value="" />
						<ul id="mj-home-sections" style="margin:0; max-width:560px;">
							<?php foreach ($order as $key) :
								if (!isset($sections[$key])) {
									continue;
								}
								?>
								<li class="mj-home-section" data-key="<?php echo esc_attr($key); ?>" style="display:flex; align-items:center; gap:10px; margin:0 0 6px; padding:8px 10px; border:1px solid #c3c4c7; border-radius:6px; background:#fff;">
									<span class="mj-home-handle dashicons dashicons-menu" title="Arrastrá para cambiar el orden" style="cursor:move; color:#787c82;"></span>
									<label style="flex:1; display:flex; align-items:center; gap:8px; margin:0;">
										<input type="checkbox" name="mj_options[home_visible][]" value="<?php echo esc_attr($key); ?>" <?php checked(!in_array($key, $hidden, true)); ?> />
										<?php echo esc_html($sections[$key]); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
						<input type="hidden" id="mj_home_order" name="mj_options[home_order]" value="<?php echo esc_attr(implode(',', $order)); ?>" />
						<p class="description">Arrastrá para cambiar el orden. Destildá una sección para ocultarla. El hero siempre va primero. Las secciones sin datos (sin banners, sin marcas, sin opiniones, sin Instagram, sin foto ni texto de "Nosotros") se ocultan solas aunque estén marcadas.</p>
					</td>
				</tr>
			</table>

			<h2>Textos de la tienda</h2>
			<p>Títulos, antetítulos y frases fijas de cada pantalla. Vacío = el texto que ves en gris.</p>
			<?php
			$copy_saved    = $o['copy'];
			$copy_defaults = mj_copy_defaults();
			$groups        = [];
			foreach (mj_copy_fields() as $key => $def) {
				$groups[$def[0]][$key] = $def;
			}
			foreach ($groups as $group => $fields) :
				?>
				<h3 style="margin-top:1.5em;"><?php echo esc_html($group); ?></h3>
				<table class="form-table" role="presentation">
					<?php foreach ($fields as $key => $def) {
						mj_render_copy_field($key, $def, $copy_saved, isset($copy_defaults[$key]) ? $copy_defaults[$key] : '');
					} ?>
				</table>
			<?php endforeach; ?>

			<h2>WhatsApp y presupuestos</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_whatsapp_numbers">Vendedores de WhatsApp</label></th>
					<td>
						<textarea id="mj_whatsapp_numbers" name="mj_options[whatsapp_numbers]" rows="4" class="large-text"><?php echo esc_textarea($o['whatsapp_numbers']); ?></textarea>
						<p class="description">
							Uno por línea, con formato: <code>Nombre del vendedor | Número</code><br>
							El número con código de país, solo dígitos (ej. <code>María | 5491112345678</code>).<br>
							Si hay al menos uno, aparece el botón flotante de WhatsApp en toda la tienda.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_quote_info">Información del presupuesto</label></th>
					<td>
						<textarea id="mj_quote_info" name="mj_options[quote_info]" rows="4" class="large-text"><?php echo esc_textarea($o['quote_info']); ?></textarea>
						<p class="description">Se muestra en el presupuesto del carrito (modal y PDF): condiciones, validez, medios de pago, tiempos de entrega, etc.</p>
					</td>
				</tr>
			</table>

			<h2>Contacto</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_contact_email">Email de contacto</label></th>
					<td>
						<input type="email" id="mj_contact_email" name="mj_options[contact_email]" value="<?php echo esc_attr($o['contact_email']); ?>" class="regular-text" />
						<p class="description">También recibe los mensajes del formulario de contacto y las suscripciones al newsletter.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_contact_phone">Teléfono</label></th>
					<td><input type="text" id="mj_contact_phone" name="mj_options[contact_phone]" value="<?php echo esc_attr($o['contact_phone']); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_contact_whatsapp">WhatsApp</label></th>
					<td>
						<input type="text" id="mj_contact_whatsapp" name="mj_options[contact_whatsapp]" value="<?php echo esc_attr($o['contact_whatsapp']); ?>" class="regular-text" />
						<p class="description">Número con código de país, solo dígitos (ej. 5491112345678).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_contact_address">Dirección</label></th>
					<td><input type="text" id="mj_contact_address" name="mj_options[contact_address]" value="<?php echo esc_attr($o['contact_address']); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_contact_map">Ubicación en Google Maps</label></th>
					<td>
						<textarea id="mj_contact_map" name="mj_options[contact_map]" rows="2" class="large-text"><?php echo esc_textarea($o['contact_map']); ?></textarea>
						<p class="description">
							Se muestra como mapa en la página de Contacto. Podés pegar:<br>
							• la dirección a mostrar (ej. <code>Av. Corrientes 1234, CABA</code>),<br>
							• una URL de Google Maps del lugar, o<br>
							• el código de <em>Google Maps → Compartir → Insertar un mapa</em> (el <code>&lt;iframe&gt;</code> completo).<br>
							Vacío = no se muestra el mapa. Al guardar se convierte automáticamente al formato embebible.
						</p>
					</td>
				</tr>
			</table>

			<h2>Redes sociales</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_social_instagram">Instagram</label></th>
					<td><input type="url" id="mj_social_instagram" name="mj_options[social_instagram]" value="<?php echo esc_attr($o['social_instagram']); ?>" class="regular-text" placeholder="https://instagram.com/tuusuario" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_social_facebook">Facebook</label></th>
					<td><input type="url" id="mj_social_facebook" name="mj_options[social_facebook]" value="<?php echo esc_attr($o['social_facebook']); ?>" class="regular-text" placeholder="https://facebook.com/tupagina" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_social_twitter">Twitter / X</label></th>
					<td><input type="url" id="mj_social_twitter" name="mj_options[social_twitter]" value="<?php echo esc_attr($o['social_twitter']); ?>" class="regular-text" placeholder="https://x.com/tuusuario" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_social_youtube">YouTube</label></th>
					<td><input type="url" id="mj_social_youtube" name="mj_options[social_youtube]" value="<?php echo esc_attr($o['social_youtube']); ?>" class="regular-text" placeholder="https://youtube.com/@tucanal" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_social_tiktok">TikTok</label></th>
					<td><input type="url" id="mj_social_tiktok" name="mj_options[social_tiktok]" value="<?php echo esc_attr($o['social_tiktok']); ?>" class="regular-text" placeholder="https://tiktok.com/@tuusuario" /></td>
				</tr>
			</table>

			<h2>Contenido</h2>
			<h3 style="margin-top:1em;">Nosotros</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_about_text">Texto "Nosotros"</label></th>
					<td>
						<textarea id="mj_about_text" name="mj_options[about_text]" rows="5" class="large-text"><?php echo esc_textarea($o['about_text']); ?></textarea>
						<p class="description">Separá los párrafos con una línea en blanco. El título de la página se cambia en "Textos de la tienda → Páginas"; el primer párrafo también se usa en la sección "Sobre nosotros" de la portada si no escribís otro texto ahí.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Foto</th>
					<td>
						<?php mj_render_media_picker('about_image', $o['about_image'], 'Elegir foto…', 'Foto para la página Nosotros y la portada. Vacío = la página se muestra sin foto y la sección de la portada solo con el texto.', false); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_about_highlights">Puntos destacados</label></th>
					<td>
						<textarea id="mj_about_highlights" name="mj_options[about_highlights]" rows="4" class="large-text" placeholder="Atención personalizada | heart&#10;Productos que cuidan | leaf"><?php echo esc_textarea($o['about_highlights']); ?></textarea>
						<?php mj_render_icon_picker('mj_about_highlights'); ?>
						<p class="description">Puntos destacados, uno por línea, con ícono opcional: <code>Texto | ícono</code>. Se muestran como grilla en la página Nosotros. Vacío = no se muestran.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_about_stats">Cifras</label></th>
					<td>
						<textarea id="mj_about_stats" name="mj_options[about_stats]" rows="3" class="large-text" placeholder="+500 | Clientas felices&#10;10 años | De experiencia"><?php echo esc_textarea($o['about_stats']); ?></textarea>
						<p class="description">Cifras propias, una por línea: <code>Número | Etiqueta</code>. Vacío = cifras automáticas del catálogo (productos, categorías, marcas).</p>
					</td>
				</tr>
			</table>
			<h3 style="margin-top:1em;">Preguntas frecuentes</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_faqs">Preguntas frecuentes</label></th>
					<td>
						<textarea id="mj_faqs" name="mj_options[faqs]" rows="6" class="large-text"><?php echo esc_textarea($o['faqs']); ?></textarea>
						<p class="description">Una por línea, con formato: <code>Pregunta | Respuesta</code></p>
					</td>
				</tr>
			</table>

			<h2>Legales</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_legal_terms">Términos y condiciones</label></th>
					<td>
						<textarea id="mj_legal_terms" name="mj_options[legal_terms]" rows="10" class="large-text"><?php echo esc_textarea($o['legal_terms']); ?></textarea>
						<p class="description">
							Cada sección empieza con una línea <code># Título</code> y sigue con su texto. Ejemplo:<br>
							<code># 1. Productos</code><br>
							<code>Todas las piezas se hacen a mano y pueden variar levemente…</code><br>
							Vacío = texto genérico del tema (pensado para una tienda argentina; conviene escribir el tuyo).
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_legal_privacy">Política de privacidad</label></th>
					<td>
						<textarea id="mj_legal_privacy" name="mj_options[legal_privacy]" rows="10" class="large-text"><?php echo esc_textarea($o['legal_privacy']); ?></textarea>
						<p class="description">Mismo formato. Vacío = texto genérico del tema.</p>
					</td>
				</tr>
			</table>

			<h2>Envíos</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_free_shipping_override">Umbral de envío gratis</label></th>
					<td>
						<input type="number" id="mj_free_shipping_override" name="mj_options[free_shipping_override]" value="<?php echo esc_attr($o['free_shipping_override']); ?>" min="0" step="1" class="small-text" />
						<p class="description">Monto mínimo de compra para envío gratis. <code>0</code> = automático: se calcula desde los métodos de envío gratis configurados en WooCommerce.</p>
					</td>
				</tr>
			</table>

			<h2>Avanzado</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_maps_embed_base">Servicio de mapas</label></th>
					<td>
						<input type="url" id="mj_maps_embed_base" name="mj_options[maps_embed_base]" value="<?php echo esc_attr($o['maps_embed_base']); ?>" class="large-text code" placeholder="https://maps.google.com/maps?q={q}&amp;output=embed" />
						<p class="description">Con qué servicio se incrusta el mapa de la página de contacto. <code>{q}</code> se reemplaza por la dirección que cargues arriba. Vacío = Google Maps.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mj_icons_cdn">Íconos del panel</label></th>
					<td>
						<input type="url" id="mj_icons_cdn" name="mj_options[icons_cdn]" value="<?php echo esc_attr($o['icons_cdn']); ?>" class="large-text code" placeholder="<?php echo esc_attr(mj_icons_script_url()); ?>" />
						<p class="description">Desde dónde carga esta página la librería de íconos. Vacío = el CDN por defecto (versión <code><?php echo esc_html(MJ_ICONS_VERSION); ?></code>). Cambialo si tu servidor no puede salir a internet y querés servir el archivo vos mismo.</p>
					</td>
				</tr>
			</table>

			<?php submit_button('Guardar ajustes'); ?>
		</form>

		<?php mj_render_skin_preset_form(); ?>
	</div>
	<script>
	jQuery(function ($) {
		/* --------------------- Selector de imágenes --------------------- */
		var frames = {};

		function renderItem(id, url) {
			return $('<span class="mj-media-item" style="position:relative; display:inline-block;" />')
				.attr('data-id', id)
				.append($('<img alt="" style="width:80px; height:80px; object-fit:contain; border-radius:6px; display:block; background:#f0f0f1;" />').attr('src', url))
				.append($('<button type="button" class="mj-media-remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>'));
		}

		$('.mj-media-add').on('click', function (e) {
			e.preventDefault();
			var field = $(this).data('field');
			var multiple = String($(this).data('multiple')) !== '0';
			var input = $('#mj_' + field);
			var preview = $('.mj-media-preview[data-field="' + field + '"]');
			if (!frames[field]) {
				frames[field] = wp.media({
					title: multiple ? 'Elegir imágenes' : 'Elegir imagen',
					button: { text: multiple ? 'Usar estas imágenes' : 'Usar esta imagen' },
					library: { type: 'image' },
					multiple: multiple
				});
				frames[field].on('select', function () {
					var list = multiple && input.val() ? input.val().split(',').filter(Boolean) : [];
					if (!multiple) {
						preview.empty();
					}
					frames[field].state().get('selection').each(function (attachment) {
						var att = attachment.toJSON();
						if (list.indexOf(String(att.id)) !== -1) {
							return;
						}
						if (!multiple) {
							list = [];
						}
						list.push(String(att.id));
						var thumb = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
						preview.append(renderItem(att.id, thumb));
					});
					input.val(list.join(','));
				});
			}
			frames[field].open();
		});

		$(document).on('click', '.mj-media-remove', function () {
			var item = $(this).closest('.mj-media-item');
			var preview = item.closest('.mj-media-preview');
			var input = $('#mj_' + preview.data('field'));
			var id = String(item.data('id'));
			var list = input.val() ? input.val().split(',').filter(Boolean) : [];
			input.val(list.filter(function (x) { return x !== id; }).join(','));
			item.remove();
		});

		/* --------------------- Selector de íconos --------------------- */
		$('.mj-icon-toggle').on('click', function () {
			$('.mj-icon-grid[data-target="' + $(this).data('target') + '"]').toggle();
		});

		$('.mj-icon-pick').on('click', function () {
			var name = $(this).data('icon');
			var textarea = document.getElementById($(this).closest('.mj-icon-grid').data('target'));
			if (!textarea) {
				return;
			}
			var snippet = ' | ' + name;
			var val = textarea.value;
			var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : val.length;
			var lineEnd = val.indexOf('\n', start);
			if (lineEnd === -1) {
				lineEnd = val.length;
			}
			textarea.value = val.slice(0, lineEnd) + snippet + val.slice(lineEnd);
			textarea.focus();
			var pos = lineEnd + snippet.length;
			textarea.setSelectionRange(pos, pos);
		});

		/* ------------- Categorías del menú: máximo 4 seleccionadas ------------- */
		var $menuCats = $('.mj-menu-cat');
		function mjSyncMenuCats() {
			var full = $menuCats.filter(':checked').length >= 4;
			$menuCats.not(':checked').prop('disabled', full);
		}
		$menuCats.on('change', mjSyncMenuCats);
		mjSyncMenuCats();

		/* --------------------- Reset de paleta de colores --------------------- */
		$('.mj-color-reset').on('click', function () {
			$('input[type="color"][data-default]').each(function () {
				this.value = $(this).data('default');
			});
		});

		/* ------------- Secciones de la portada: orden por arrastre ------------- */
		var $home = $('#mj-home-sections');
		function mjSyncHomeOrder() {
			$('#mj_home_order').val(
				$home.children('li').map(function () { return $(this).data('key'); }).get().join(',')
			);
		}
		if ($home.length && $.fn.sortable) {
			$home.sortable({
				items: '> li',
				handle: '.mj-home-handle',
				axis: 'y',
				containment: 'parent',
				tolerance: 'pointer',
				placeholder: 'mj-home-placeholder',
				start: function (e, ui) {
					ui.placeholder.height(ui.item.outerHeight()).css({ border: '1px dashed #2271b1', borderRadius: '6px', margin: '0 0 6px', background: '#f0f6fc' });
				},
				update: mjSyncHomeOrder
			});
		}
		mjSyncHomeOrder();

		/* Render de los íconos Lucide en el picker (vía CDN). */
		if (window.lucide && window.lucide.createIcons) {
			window.lucide.createIcons();
		}
	});
	</script>
	<?php
}
