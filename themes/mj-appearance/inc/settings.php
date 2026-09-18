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
		'menu_categories'         => '', // Slugs CSV (máx. 4). Semilla del menú por defecto; lo que manda es nav_items.
		'nav_items'               => [], // Barra de navegación: filas [{key,label,href,visible,…}] (ver inc/nav.php). Vacío = el menú automático.
		'design_theme'            => 'dark', // dark | light | system
		'motion_level'            => 'medium', // none | subtle | medium | cinematic
		'theme_switch'            => 'instant', // instant | fade | circle — efecto del botón sol/luna
		'seo_description'         => '',  // Descripción para Google del sitio (vacío = la descripción corta de WP).
		'seo_share_image'         => 0,   // Adjunto: foto al compartir una URL sin foto propia (vacío = el logo).
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
		/* 3.19 — frenos de los endpoints públicos (inc/rest.php). 0 = sin freno. */
		'rate_limit_auth'         => 10, // Intentos fallidos de login, registros y recuperaciones por IP en la ventana.
		'rate_limit_contact'      => 5,  // Envíos del formulario de contacto y newsletter por IP en la ventana.
		'rate_limit_window'       => 10, // Minutos de la ventana de los contadores.
		'catalog_stock_quantity'  => 0,  // Publicar la cantidad de stock de cada producto en el buscador (la Store API no lo hace).
		'catalog_stock_cache'     => 10, // Minutos que se cachea el listado de SKU y stock del catálogo.
		/* Compra rápida: quien ya tiene sus datos guardados compra sin volver a cargarlos. */
		'express_checkout'        => 1,  // Ofrecer la compra rápida a quien ya compró antes.
		'express_auto'            => 1,  // Entrar directo en modo rápido (si no, hay que pedirlo).
		'express_label'           => '', // Texto del botón (vacío = "Comprar ahora").
		'skin_defaults'           => 1,  // Aplicar las propuestas de la skin (paleta, tipografías, textos).
		'icons_cdn'               => '', // URL del script de íconos del admin (vacío = el CDN por defecto).
		'maps_embed_base'         => '', // Base para incrustar mapas (vacío = Google Maps).
		/* 3.18 — páginas de WordPress (editor de bloques) dentro de la tienda. */
		'pages_enabled'           => 1,      // Mostrar en la tienda las páginas publicadas.
		'pages_override'          => 1,      // Una página con el mismo slug reemplaza a contacto, nosotros, faq, privacidad o terminos.
		'editor_theme'            => 'auto', // auto | light | dark — con qué tema se pinta el editor de bloques.
		/* Botón fijo abajo (llamado a la acción en el celular). Apagado = como siempre. */
		'sticky_cta_enabled'      => 0,                // Mostrar el botón fijo abajo.
		'sticky_cta_text'         => 'Reservar turno', // Texto del botón.
		'sticky_cta_url'          => '',               // Destino (vacío = la reserva de MJ Turnos si está activo; si no, la tienda).
		'sticky_cta_desktop'      => 0,                // 0 = solo en celular y tablet; 1 = también en la computadora.
		'sticky_cta_hide'         => '',               // Rutas donde NO aparece, una por línea (termina en * = esa ruta y todo lo que cuelga).
		/* Contacto: qué canales se muestran (lo de siempre por defecto). */
		'contact_area'            => '',               // Zona de atención ("Atiendo en zona Palermo"). Vacío = no se muestra.
		'contact_show_instagram'  => 0,                // Sumar Instagram (Redes sociales) a los canales de la página de contacto.
		'contact_show_form'       => 1,                // Mostrar el formulario en la página de contacto.
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
		'none'      => 'Ninguna — todo quieto: sin transiciones, reveals, hovers animados ni cortina de entrada',
		'subtle'    => 'Sutil — solo fundidos y hovers',
		'medium'    => 'Media — transiciones de página y reveals al scroll',
		'cinematic' => 'Cinematográfica — además parallax, reveals por máscara, botones magnéticos y contadores',
	]);
}

/* Efectos disponibles para el botón sol/luna (react-app/src/lib/theme.js). */
function mj_theme_switch_choices() {
	return (array) apply_filters('mj_theme_switch_choices', [
		'instant' => 'Instantáneo — cambia al clic, sin animación',
		'fade'    => 'Fundido — los colores se funden en un tercio de segundo',
		'circle'  => 'Círculo — el tema nuevo se abre desde el botón (si el navegador lo soporta)',
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
		'nav_menu'                  => ['Menú', 'Menú del celular (título accesible del botón)', 'Menú', 'text'],
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
		// SEO: título de la pestaña y descripción para Google de cada pantalla (inc/seo.php).
		'seo_title_shop'            => ['SEO', 'Tienda — título', '', 'text', 'Vacío = el título de la tienda.'],
		'seo_desc_shop'             => ['SEO', 'Tienda — descripción', 'Todo el catálogo con precios, stock y envíos a todo el país.', 'text'],
		'seo_desc_category'         => ['SEO', 'Categoría sin descripción — plantilla', 'Comprá {nombre} en {sitio}: precios, stock y envíos a todo el país.', 'text', 'Cuando la categoría no tiene descripción en WooCommerce. {nombre} y {sitio} se reemplazan.'],
		'seo_desc_brand'            => ['SEO', 'Marca — plantilla', 'Todos los productos {nombre} en {sitio}, con precios y stock al día.', 'text', '{nombre} y {sitio} se reemplazan.'],
		'seo_title_brands'          => ['SEO', 'Marcas — título', '', 'text', 'Vacío = el nombre en plural de "marcas".'],
		'seo_desc_brands'           => ['SEO', 'Marcas — descripción', 'Las marcas con las que trabajamos, con todos sus productos.', 'text'],
		'seo_title_contact'         => ['SEO', 'Contacto — título', 'Contacto', 'text'],
		'seo_desc_contact'          => ['SEO', 'Contacto — descripción', 'Escribinos por WhatsApp, mail o teléfono y te respondemos a la brevedad.', 'text'],
		'seo_title_about'           => ['SEO', 'Nosotros — título', 'Nosotros', 'text'],
		'seo_desc_about'            => ['SEO', 'Nosotros — descripción', 'Quiénes somos, cómo trabajamos y por qué comprar con nosotros.', 'text'],
		'seo_title_faq'             => ['SEO', 'Preguntas frecuentes — título', 'Preguntas frecuentes', 'text'],
		'seo_desc_faq'              => ['SEO', 'Preguntas frecuentes — descripción', 'Envíos, pagos, cambios y garantías: las respuestas a lo que más nos preguntan.', 'text'],
		'seo_title_terms'           => ['SEO', 'Términos — título', 'Términos y condiciones', 'text'],
		'seo_title_privacy'         => ['SEO', 'Privacidad — título', 'Política de privacidad', 'text'],
		'seo_title_sitemap'         => ['SEO', 'Mapa del sitio — título', 'Mapa del sitio', 'text'],
		'seo_title_search'          => ['SEO', 'Buscador — título', 'Buscar', 'text'],
		'seo_title_cart'            => ['SEO', 'Carrito — título', 'Tu carrito', 'text'],
		'seo_title_checkout'        => ['SEO', 'Checkout — título', 'Finalizar compra', 'text'],
		'seo_title_account'         => ['SEO', 'Mi cuenta — título', 'Mi cuenta', 'text'],
		'seo_title_login'           => ['SEO', 'Iniciar sesión — título', 'Iniciar sesión', 'text'],
		'seo_title_register'        => ['SEO', 'Crear cuenta — título', 'Crear cuenta', 'text'],
		'seo_title_recover'         => ['SEO', 'Recuperar contraseña — título', 'Recuperar contraseña', 'text'],
		'seo_title_wishlist'        => ['SEO', 'Favoritos — título', 'Favoritos', 'text'],
		'seo_title_compare'         => ['SEO', 'Comparar — título', 'Comparar productos', 'text'],
		'seo_title_recent'          => ['SEO', 'Vistos recientemente — título', 'Vistos recientemente', 'text'],
		'seo_title_notfound'        => ['SEO', 'Página no encontrada — título', 'Página no encontrada', 'text'],
		'faq_subtitle'              => ['Páginas', 'Preguntas frecuentes — subtítulo', 'Todo lo que necesitás saber antes de comprar.', 'text'],
		'contact_label_address'     => ['Páginas', 'Contacto — rótulo de la dirección', 'Dirección', 'text'],
		'contact_label_phone'       => ['Páginas', 'Contacto — rótulo del teléfono', 'Teléfono', 'text'],
		'contact_label_whatsapp'    => ['Páginas', 'Contacto — rótulo de WhatsApp', 'WhatsApp', 'text'],
		'contact_label_email'       => ['Páginas', 'Contacto — rótulo del email', 'Email', 'text'],
		'contact_label_instagram'   => ['Páginas', 'Contacto — rótulo de Instagram', 'Instagram', 'text', 'Se usa si marcás "Sumar Instagram a los canales" (Contacto).'],
		'contact_label_area'        => ['Páginas', 'Contacto — rótulo de la zona', 'Zona de atención', 'text', 'Se usa si cargás la zona de atención (Contacto).'],
		// Mi cuenta
		'account_nav_orders'        => ['Mi cuenta', 'Pedidos — ítem del menú de la cuenta', 'Pedidos', 'text', 'También rotula el contador de pedidos del resumen. Ej.: "Compras".'],
		'account_orders_title'      => ['Mi cuenta', 'Pedidos — título', 'Mis pedidos', 'text', 'Ej.: "Mis compras".'],
		'account_orders_empty'      => ['Mi cuenta', 'Pedidos — sin pedidos', 'Todavía no hiciste ningún pedido.', 'text'],
		'account_orders_cta'        => ['Mi cuenta', 'Pedidos — botón cuando no hay pedidos', 'Explorar la tienda', 'text'],
		'account_orders_back'       => ['Mi cuenta', 'Pedidos — volver a la lista', 'Volver a pedidos', 'text'],
		'account_order_title'       => ['Mi cuenta', 'Detalle — título (antes del número)', 'Pedido', 'text', 'Ej.: "Compra" → "Compra #123".'],
		'account_status_label'      => ['Mi cuenta', 'Rótulo del estado del pedido', 'Estado', 'text', 'Solo se muestra junto al estado del pago (plugin MJ Medios de Pago, en los pedidos que piden comprobante).'],
		'account_payment_label'     => ['Mi cuenta', 'Rótulo del estado del pago', 'Pago', 'text', '"Pago: Aprobado". Solo en los pedidos que piden comprobante (plugin MJ Medios de Pago).'],
		// Galería de trabajos (bloque mj/galeria)
		'gallery_page_eyebrow'      => ['Galería', 'Plantilla "Página de galería" — antetítulo', 'Trabajos', 'text', 'Lo que propone la plantilla al insertarla en una página (después se edita en el bloque).'],
		'gallery_page_title'        => ['Galería', 'Plantilla "Página de galería" — título', 'Galería', 'text'],
		'gallery_page_subtitle'     => ['Galería', 'Plantilla "Página de galería" — bajada', '', 'text'],
		'gallery_filter_all'        => ['Galería', 'Filtro — botón "todas"', 'Todos', 'text', 'El primer botón del filtro por leyenda del bloque "Galería de trabajos".'],
		'gallery_open'              => ['Galería', 'Abrir la foto (título accesible)', 'Ver la foto grande', 'text'],
		'gallery_prev'              => ['Galería', 'Foto anterior (título accesible)', 'Foto anterior', 'text'],
		'gallery_next'              => ['Galería', 'Foto siguiente (título accesible)', 'Foto siguiente', 'text'],
		// Servicios (plugin MJ Turnos Online)
		'services_uncategorized'    => ['Servicios', 'Servicios sin categoría — título del grupo', '', 'text', 'Con los servicios agrupados por categoría, el título del grupo de los que no tienen ninguna. Vacío = sin título.'],
		/*
		 * Textos que antes estaban escritos en la SPA. Default = el texto de siempre;
		 * {marcas} entre llaves se reemplazan en la tienda (ver la descripción).
		 */
		// Mi cuenta
		'account_eyebrow'                 => ['Mi cuenta', 'Antetítulo de la cuenta', 'Mi cuenta', 'text'],
		'account_greeting'                => ['Mi cuenta', 'Saludo', 'Hola, {nombre} 👋', 'text', '{nombre} = el nombre de quien entró.'],
		'account_guest'                   => ['Mi cuenta', 'Nombre cuando no hay uno', 'Invitado', 'text'],
		'account_nav_summary'             => ['Mi cuenta', 'Menú: Resumen', 'Resumen', 'text'],
		'account_nav_addresses'           => ['Mi cuenta', 'Menú: Direcciones', 'Direcciones', 'text'],
		'account_nav_favorites'           => ['Mi cuenta', 'Menú: Favoritos', 'Favoritos', 'text', 'También es el título de esa pestaña.'],
		'account_nav_downloads'           => ['Mi cuenta', 'Menú: Descargas', 'Descargas', 'text', 'También es el título de esa pestaña.'],
		'account_nav_settings'            => ['Mi cuenta', 'Menú: Configuración', 'Configuración', 'text', 'También es el título de esa pestaña.'],
		'account_logout'                  => ['Mi cuenta', 'Botón para cerrar sesión', 'Cerrar sesión', 'text'],
		'account_stat_favorites'          => ['Mi cuenta', 'Resumen — contador de favoritos', 'En favoritos', 'text'],
		'account_stat_spent'              => ['Mi cuenta', 'Resumen — total comprado', 'Total comprado', 'text'],
		'account_recent_title'            => ['Mi cuenta', 'Resumen — actividad reciente', 'Actividad reciente', 'text'],
		'account_credit_title'            => ['Mi cuenta', 'Saldo a favor — título', 'Saldo a favor', 'text', 'Si el plugin MJ ERP manda su propio nombre, se usa ese.'],
		'account_credit_hint'             => ['Mi cuenta', 'Saldo a favor — aclaración', 'Lo podés usar al pagar tu próximo pedido.', 'text'],
		'account_credit_empty'            => ['Mi cuenta', 'Saldo a favor — sin saldo', 'No tenés saldo disponible.', 'text'],
		'account_order_items_one'         => ['Mi cuenta', 'Pedido — cantidad de productos (uno)', '{n} producto', 'text', '{n} = la cantidad.'],
		'account_order_items_other'       => ['Mi cuenta', 'Pedido — cantidad de productos (varios)', '{n} productos', 'text', '{n} = la cantidad.'],
		'account_order_load_error'        => ['Mi cuenta', 'Detalle — error al cargar', 'No se pudo cargar el pedido.', 'text'],
		'account_order_standalone'        => ['Mi cuenta', 'Detalle — sin tienda conectada', 'El detalle de pedidos está disponible con la tienda conectada.', 'text'],
		'account_reorder'                 => ['Mi cuenta', 'Detalle — botón repetir pedido', 'Repetir pedido', 'text'],
		'account_reorder_skipped_one'     => ['Mi cuenta', 'Repetir pedido — producto que ya no está (uno)', '{n} producto del pedido ya no está disponible.', 'text', '{n} = la cantidad.'],
		'account_reorder_skipped_other'   => ['Mi cuenta', 'Repetir pedido — productos que ya no están (varios)', '{n} productos del pedido ya no están disponibles.', 'text', '{n} = la cantidad.'],
		'account_assist'                  => ['Mi cuenta', 'Detalle — botón de asistencia', 'Solicitar asistencia', 'text'],
		'account_assist_message'          => ['Mi cuenta', 'Detalle — mensaje de WhatsApp de asistencia', 'Hola! Necesito ayuda con mi pedido #{numero} de {tienda}.', 'text', '{numero} = el número de pedido, {tienda} = el nombre del sitio.'],
		'account_cancel'                  => ['Mi cuenta', 'Detalle — botón cancelar pedido', 'Cancelar pedido', 'text'],
		'account_cancel_title'            => ['Mi cuenta', 'Cancelar — título', 'Cancelar pedido #{numero}', 'text', '{numero} = el número de pedido.'],
		'account_cancel_text'             => ['Mi cuenta', 'Cancelar — texto de confirmación', '¿Seguro que querés cancelar este pedido? El stock se repone automáticamente y la operación no se puede deshacer.', 'textarea'],
		'account_cancel_back'             => ['Mi cuenta', 'Cancelar — botón volver', 'Volver', 'text'],
		'account_cancel_confirm'          => ['Mi cuenta', 'Cancelar — botón confirmar', 'Sí, cancelar pedido', 'text'],
		'account_cancel_error'            => ['Mi cuenta', 'Cancelar — error', 'No se pudo cancelar el pedido.', 'text'],
		'account_products_title'          => ['Mi cuenta', 'Detalle — título de productos', 'Productos', 'text'],
		'account_line_add'                => ['Mi cuenta', 'Detalle — agregar la línea al carrito', 'Agregar al carrito', 'text'],
		'account_line_add_aria'           => ['Mi cuenta', 'Detalle — agregar la línea (título accesible)', 'Agregar {nombre} al carrito', 'text', '{nombre} = el producto.'],
		'account_line_unavailable'        => ['Mi cuenta', 'Detalle — producto que ya no está', 'No disponible', 'text'],
		'account_payment_howto'           => ['Mi cuenta', 'Detalle — cómo pagar', 'Cómo pagar', 'text', 'Si el plugin MJ Medios de Pago manda su texto, se usa ese.'],
		'account_receipt_title'           => ['Mi cuenta', 'Detalle — título del comprobante', '{comprobante} de pago', 'text', '{comprobante} = cómo llama al comprobante el plugin MJ Medios de Pago.'],
		'account_totals_title'            => ['Mi cuenta', 'Totales — título', 'Totales', 'text'],
		'account_totals_subtotal'         => ['Mi cuenta', 'Totales — subtotal', 'Subtotal', 'text'],
		'account_totals_discount'         => ['Mi cuenta', 'Totales — descuento', 'Descuento', 'text'],
		'account_totals_shipping'         => ['Mi cuenta', 'Totales — envío', 'Envío', 'text'],
		'account_totals_free'             => ['Mi cuenta', 'Totales — envío gratis', 'Gratis', 'text'],
		'account_totals_rate'             => ['Mi cuenta', 'Totales — dólar del pedido', 'Dólar del pedido', 'text'],
		'account_totals_usd'              => ['Mi cuenta', 'Totales — total en dólares', 'Total en dólares', 'text'],
		'account_totals_total'            => ['Mi cuenta', 'Totales — total', 'Total', 'text'],
		'account_balance_paid'            => ['Mi cuenta', 'Pago parcial — pagado', 'Pagado', 'text', 'Si el plugin MJ Medios de Pago manda sus textos, se usan esos.'],
		'account_balance_due'             => ['Mi cuenta', 'Pago parcial — falta abonar', 'Falta abonar', 'text'],
		'account_balance_full'            => ['Mi cuenta', 'Pago parcial — pagado completo', 'Pagado completo', 'text'],
		'account_shipping_title'          => ['Mi cuenta', 'Envío y pago — título', 'Envío y pago', 'text'],
		'account_shipping_method'         => ['Mi cuenta', 'Envío y pago — método de envío', 'Método de envío', 'text'],
		'account_shipping_to_agree'       => ['Mi cuenta', 'Envío y pago — costo a coordinar', 'El costo se acuerda con vos.', 'text'],
		'account_pickup_at'               => ['Mi cuenta', 'Envío y pago — retiro', 'Se retira en {lugar}', 'text', '{lugar} = la dirección del punto de retiro.'],
		'account_tracking'                => ['Mi cuenta', 'Envío y pago — seguimiento', 'Seguimiento', 'text'],
		'account_tracking_link'           => ['Mi cuenta', 'Envío y pago — enlace de seguimiento', 'Ver dónde está mi paquete', 'text'],
		'account_payment_method'          => ['Mi cuenta', 'Envío y pago — medio de pago', 'Medio de pago', 'text'],
		'account_shipping_address'        => ['Mi cuenta', 'Envío y pago — dirección de envío', 'Dirección de envío', 'text'],
		'account_contact'                 => ['Mi cuenta', 'Envío y pago — contacto', 'Contacto', 'text'],
		'account_invoice_title'           => ['Mi cuenta', 'Factura — título', 'Factura', 'text'],
		'account_invoice_issued'          => ['Mi cuenta', 'Factura — emitida', 'Emitida', 'text'],
		'account_invoice_failed'          => ['Mi cuenta', 'Factura — error al emitir', 'Error al emitir', 'text'],
		'account_invoice_requested'       => ['Mi cuenta', 'Factura — solicitada', 'Solicitada', 'text'],
		'account_invoice_receiver'        => ['Mi cuenta', 'Factura — receptor', 'Receptor', 'text'],
		'account_invoice_voucher'         => ['Mi cuenta', 'Factura — comprobante', 'Comprobante', 'text'],
		'account_invoice_cae'             => ['Mi cuenta', 'Factura — CAE', 'CAE {cae}', 'text', '{cae} = el número de CAE.'],
		'account_invoice_download'        => ['Mi cuenta', 'Factura — descargar PDF', 'Descargar factura (PDF)', 'text'],
		'account_invoice_online'          => ['Mi cuenta', 'Factura — ver online', 'Ver comprobante online', 'text'],
		'account_invoice_pending'         => ['Mi cuenta', 'Factura — pendiente', 'La factura se emite cuando el pedido se completa. Te llega por email.', 'text'],
		'account_invoice_error_text'      => ['Mi cuenta', 'Factura — con error', 'Hubo un problema al emitirla; ya estamos al tanto. Si necesitás la factura urgente, escribinos.', 'textarea'],
		'account_billing_address_title'   => ['Mi cuenta', 'Direcciones — facturación', 'Dirección de facturación', 'text'],
		'account_shipping_address_title'  => ['Mi cuenta', 'Direcciones — envío', 'Dirección de envío', 'text'],
		'account_addresses_saved'         => ['Mi cuenta', 'Direcciones — guardadas', 'Direcciones guardadas', 'text'],
		'account_addresses_error'         => ['Mi cuenta', 'Direcciones — error', 'No se pudieron guardar las direcciones.', 'text'],
		'account_save'                    => ['Mi cuenta', 'Botón guardar', 'Guardar', 'text'],
		'account_fiscal_title'            => ['Mi cuenta', 'Datos de facturación — título', 'Datos de facturación', 'text'],
		'account_fiscal_hint'             => ['Mi cuenta', 'Datos de facturación — aclaración', 'Se usan para autocompletar la factura cuando la pedís a tu nombre en el checkout.', 'text'],
		'account_fiscal_saved'            => ['Mi cuenta', 'Datos de facturación — guardados', 'Datos fiscales guardados', 'text'],
		'account_data_error'              => ['Mi cuenta', 'Error al guardar datos', 'No se pudieron guardar los datos.', 'text'],
		'account_favorites_empty'         => ['Mi cuenta', 'Favoritos — vacío', 'Todavía no guardaste favoritos.', 'text'],
		'account_favorites_cta'           => ['Mi cuenta', 'Favoritos — botón', 'Explorar', 'text'],
		'account_downloads_empty'         => ['Mi cuenta', 'Descargas — vacío', 'No tenés descargas disponibles.', 'text'],
		'account_downloads_hint'          => ['Mi cuenta', 'Descargas — aclaración', 'Facturas y manuales aparecerán acá.', 'text'],
		'account_download'                => ['Mi cuenta', 'Descargas — botón', 'Descargar', 'text'],
		'account_display_name'            => ['Mi cuenta', 'Configuración — nombre visible', 'Nombre visible', 'text'],
		'account_password_title'          => ['Mi cuenta', 'Configuración — cambiar contraseña', 'Cambiar contraseña', 'text'],
		'account_password_current'        => ['Mi cuenta', 'Configuración — contraseña actual', 'Contraseña actual', 'text'],
		'account_password_new'            => ['Mi cuenta', 'Configuración — nueva contraseña', 'Nueva contraseña', 'text'],
		'account_save_changes'            => ['Mi cuenta', 'Configuración — guardar', 'Guardar cambios', 'text'],
		'account_settings_saved'          => ['Mi cuenta', 'Configuración — guardado', 'Datos guardados', 'text'],
		// Tienda
		'shop_count_one'                  => ['Tienda', 'Cantidad de productos (uno)', '{n} producto', 'text', '{n} = la cantidad. También en la página de marcas.'],
		'shop_count_other'                => ['Tienda', 'Cantidad de productos (varios)', '{n} productos', 'text', '{n} = la cantidad.'],
		'shop_search_results'             => ['Tienda', 'Buscador — título de resultados', 'Resultados para “{busqueda}”', 'text', '{busqueda} = lo que se buscó.'],
		'shop_search_in'                  => ['Tienda', 'Buscador — resultados en una categoría', '“{busqueda}” en {categoria}', 'text', '{busqueda} y {categoria} se reemplazan.'],
		'shop_filters'                    => ['Tienda', 'Filtros — botón y título', 'Filtros', 'text'],
		'shop_filters_close'              => ['Tienda', 'Filtros — cerrar (título accesible)', 'Cerrar filtros', 'text'],
		'shop_filter_category'            => ['Tienda', 'Filtros — categoría', 'Categoría', 'text'],
		'shop_filter_all'                 => ['Tienda', 'Filtros — todas', 'Todas', 'text'],
		'shop_filter_max_price'           => ['Tienda', 'Filtros — precio máximo', 'Precio máximo', 'text'],
		'shop_filter_availability'        => ['Tienda', 'Filtros — disponibilidad', 'Disponibilidad', 'text'],
		'shop_filter_in_stock'            => ['Tienda', 'Filtros — solo en stock', 'Solo en stock', 'text'],
		'shop_filter_on_sale'             => ['Tienda', 'Filtros — en oferta', 'En oferta', 'text'],
		'shop_results_one'                => ['Tienda', 'Filtros — ver resultados (uno)', 'Ver {n} resultado', 'text', '{n} = la cantidad.'],
		'shop_results_other'              => ['Tienda', 'Filtros — ver resultados (varios)', 'Ver {n} resultados', 'text', '{n} = la cantidad.'],
		'shop_sort_label'                 => ['Tienda', 'Orden — título accesible', 'Ordenar', 'text'],
		'shop_sort_relevance'             => ['Tienda', 'Orden — relevancia', 'Relevancia', 'text'],
		'shop_sort_price_asc'             => ['Tienda', 'Orden — menor precio', 'Menor precio', 'text'],
		'shop_sort_price_desc'            => ['Tienda', 'Orden — mayor precio', 'Mayor precio', 'text'],
		'shop_sort_rating'                => ['Tienda', 'Orden — mejor valorados', 'Mejor valorados', 'text'],
		'shop_sort_newest'                => ['Tienda', 'Orden — novedades', 'Novedades', 'text'],
		'shop_view_grid'                  => ['Tienda', 'Vista grilla (título accesible)', 'Vista grilla', 'text'],
		'shop_view_list'                  => ['Tienda', 'Vista lista (título accesible)', 'Vista lista', 'text'],
		'shop_empty_cta'                  => ['Tienda', 'Sin resultados — botón', 'Ver toda la tienda', 'text'],
		'shop_category_description'       => ['Tienda', 'Categoría sin descripción', 'Explorá la colección.', 'text', 'Se muestra en el menú desplegable cuando la categoría no tiene descripción en WooCommerce.'],
		// Producto
		'product_badge_new'               => ['Producto', 'Etiqueta "nuevo"', 'Nuevo', 'text', 'En la ficha, las tarjetas y la vista rápida.'],
		'product_badge_off'               => ['Producto', 'Etiqueta de descuento (ficha)', '-{n}% OFF', 'text', '{n} = el porcentaje.'],
		'product_in_stock'                => ['Producto', 'En stock', 'En stock', 'text'],
		'product_out_of_stock'            => ['Producto', 'Sin stock', 'Sin stock', 'text', 'En la ficha, las tarjetas, la vista rápida y el buscador.'],
		'product_backorder'               => ['Producto', 'Disponible bajo pedido', 'Disponible bajo pedido', 'text'],
		'product_units_one'               => ['Producto', 'Unidades disponibles (una)', '{n} unidad disponible', 'text', '{n} = la cantidad.'],
		'product_units_other'             => ['Producto', 'Unidades disponibles (varias)', '{n} unidades disponibles', 'text', '{n} = la cantidad.'],
		'product_add_to_cart'             => ['Producto', 'Botón agregar al carrito', 'Agregar al carrito', 'text', 'En la ficha y la vista rápida.'],
		'product_card_add'                => ['Producto', 'Tarjeta — botón de compra', 'Añadir al carrito', 'text', 'También en el buscador.'],
		'product_choose_options'          => ['Producto', 'Elegir opciones', 'Elegir opciones', 'text', 'Productos con variaciones.'],
		'product_bar_add'                 => ['Producto', 'Barra de compra del celular — agregar', 'Agregar', 'text'],
		'product_bar_label'               => ['Producto', 'Barra de compra del celular (título accesible)', 'Comprar', 'text'],
		'product_choose'                  => ['Producto', 'Botón sin opción elegida', 'Elegí {opcion}', 'text', '{opcion} = la opción que falta, en minúscula.'],
		'product_choose_to_continue'      => ['Producto', 'Aviso de opción que falta', 'Elegí {opcion} para continuar.', 'text', '{opcion} = la opción que falta, en minúscula.'],
		'product_combo_unavailable'       => ['Producto', 'Combinación que no existe', 'Esa combinación no está disponible.', 'text'],
		'product_combo_toast'             => ['Producto', 'Combinación que no existe (aviso)', 'Esa combinación no está disponible', 'text'],
		'product_wish'                    => ['Producto', 'Botón favorito', 'Favorito', 'text'],
		'product_wished'                  => ['Producto', 'Botón favorito — ya guardado', 'En favoritos', 'text'],
		'product_compare'                 => ['Producto', 'Botón comparar', 'Comparar', 'text', 'En la ficha y las tarjetas.'],
		'product_wish_add'                => ['Producto', 'Tarjeta — agregar a favoritos (título accesible)', 'Agregar a favoritos', 'text'],
		'product_quick_view'              => ['Producto', 'Tarjeta — vista rápida (título accesible)', 'Vista rápida', 'text'],
		'product_quick_view_aria'         => ['Producto', 'Vista rápida — título accesible', 'Vista rápida: {nombre}', 'text', '{nombre} = el producto.'],
		'product_view_detail'             => ['Producto', 'Vista rápida — ver detalle', 'Ver detalle', 'text'],
		'product_sku'                     => ['Producto', 'Rótulo del SKU', 'SKU', 'text'],
		'product_sku_copy'                => ['Producto', 'Copiar SKU', 'Copiar SKU', 'text'],
		'product_sku_copied'              => ['Producto', 'SKU copiado', 'SKU copiado', 'text'],
		'product_meta_category'           => ['Producto', 'Rótulo categoría', 'Categoría', 'text'],
		'product_meta_categories'         => ['Producto', 'Rótulo categorías', 'Categorías', 'text'],
		'product_zoom'                    => ['Producto', 'Ampliar imagen (título accesible)', 'Ampliar imagen', 'text'],
		'product_image_n'                 => ['Producto', 'Miniatura (título accesible)', 'Imagen {n}', 'text', '{n} = el número de foto.'],
		'product_tab_description'         => ['Producto', 'Pestaña descripción', 'Descripción', 'text'],
		'product_tab_specs'               => ['Producto', 'Pestaña especificaciones', 'Especificaciones', 'text'],
		'product_tab_reviews'             => ['Producto', 'Pestaña opiniones (demo)', 'Opiniones ({n})', 'text', '{n} = la cantidad.'],
		'product_load_error'              => ['Producto', 'Error al cargar', 'No pudimos cargar el producto', 'text'],
		'product_gone_title'              => ['Producto', 'Producto que ya no está — título', 'Este producto ya no está', 'text'],
		'product_gone_text'               => ['Producto', 'Producto que ya no está — texto', 'Puede que se haya agotado o que el enlace esté vencido.', 'text'],
		// Menú
		'nav_favorites'                   => ['Menú', 'Favoritos (header y menú del celular)', 'Favoritos', 'text'],
		'nav_compare'                     => ['Menú', 'Comparar (header y menú del celular)', 'Comparar', 'text'],
		'nav_theme_dark'                  => ['Menú', 'Botón de modo oscuro (título accesible)', 'Activar modo oscuro', 'text'],
		'nav_theme_light'                 => ['Menú', 'Botón de modo claro (título accesible)', 'Activar modo claro', 'text'],
		'nav_main_label'                  => ['Menú', 'Menú principal (título accesible)', 'Navegación principal', 'text'],
		'nav_mobile_label'                => ['Menú', 'Barra inferior del celular (título accesible)', 'Navegación móvil', 'text'],
		'nav_mega_eyebrow'                => ['Menú', 'Menú desplegable — antetítulo', 'Categoría', 'text'],
		'nav_mega_link'                   => ['Menú', 'Menú desplegable — enlace', 'Explorar todo', 'text'],
		// Portada
		'home_hero_stat_products'         => ['Portada', 'Hero — cifra de productos', 'productos', 'text'],
		'home_hero_stat_shipping'         => ['Portada', 'Hero — cifra de envío gratis', 'envío gratis desde', 'text'],
		'home_hero_stat_rating'           => ['Portada', 'Hero — cifra de valoración', 'rating promedio', 'text'],
		'home_categories_carousel'        => ['Portada', 'Categorías — carrusel (título accesible)', 'Categorías', 'text'],
		'home_instagram_view'             => ['Portada', 'Instagram — foto (título accesible)', 'Ver en Instagram', 'text'],
		'home_review_role'                => ['Portada', 'Opiniones — rótulo de cada reseña', 'Compra verificada', 'text'],
		// Pie de página
		'footer_newsletter_email'         => ['Pie de página', 'Newsletter — campo de email (título accesible)', 'Email para newsletter', 'text'],
		'footer_newsletter_submit'        => ['Pie de página', 'Newsletter — botón (título accesible)', 'Suscribirme', 'text'],
		'footer_newsletter_ok'            => ['Pie de página', 'Newsletter — suscripto', '¡Listo! Te avisamos de las novedades.', 'text'],
		'footer_newsletter_error'         => ['Pie de página', 'Newsletter — error', 'No pudimos registrar tu email. Intentá de nuevo.', 'text'],
		// Páginas
		'contact_submit'                  => ['Páginas', 'Contacto — botón enviar', 'Enviar mensaje', 'text'],
		'contact_message_placeholder'     => ['Páginas', 'Contacto — mensaje (placeholder)', 'Contanos…', 'text'],
		'contact_sent_title'              => ['Páginas', 'Contacto — enviado, título', 'Mensaje enviado', 'text'],
		'contact_sent_text'               => ['Páginas', 'Contacto — enviado, texto', 'Te respondemos a la brevedad.', 'text'],
		'contact_send_another'            => ['Páginas', 'Contacto — enviar otro', 'Enviar otro mensaje', 'text'],
		'contact_error'                   => ['Páginas', 'Contacto — error al enviar', 'No se pudo enviar el mensaje. Probá de nuevo en unos minutos.', 'text'],
		'contact_map_title'               => ['Páginas', 'Contacto — mapa (título accesible)', 'Ubicación en Google Maps', 'text'],
		'about_fallback_text'             => ['Páginas', 'Nosotros — texto cuando no hay uno propio', 'Cuidamos cada detalle para que comprar sea simple, transparente y seguro.', 'text', 'Va después del nombre del sitio, solo si no cargaste el texto de Nosotros.'],
		'about_stat_products'             => ['Páginas', 'Nosotros — cifra automática: productos', 'Productos en catálogo', 'text', 'Las cifras automáticas se usan si no cargaste cifras propias.'],
		'about_stat_categories'           => ['Páginas', 'Nosotros — cifra automática: categorías', 'Categorías', 'text'],
		'about_stat_rating'               => ['Páginas', 'Nosotros — cifra automática: valoración', 'Valoración promedio', 'text'],
		'faq_eyebrow'                     => ['Páginas', 'Preguntas frecuentes — antetítulo', 'Ayuda', 'text'],
		'faq_title'                       => ['Páginas', 'Preguntas frecuentes — título', 'Preguntas frecuentes', 'text'],
		'faq_empty'                       => ['Páginas', 'Preguntas frecuentes — sin preguntas', 'Próximamente publicaremos las preguntas frecuentes.', 'text'],
		'faq_contact_title'               => ['Páginas', 'Preguntas frecuentes — ¿no la encontraste?', '¿No encontraste tu respuesta?', 'text'],
		'faq_contact_cta'                 => ['Páginas', 'Preguntas frecuentes — botón de contacto', 'Escribinos', 'text'],
		'legal_eyebrow'                   => ['Páginas', 'Legales — antetítulo', 'Legales', 'text'],
		'legal_privacy_title'             => ['Páginas', 'Privacidad — título', 'Política de privacidad', 'text'],
		'legal_terms_title'               => ['Páginas', 'Términos — título', 'Términos y condiciones', 'text'],
		'sitemap_eyebrow'                 => ['Páginas', 'Mapa del sitio — antetítulo', 'Navegación', 'text'],
		'sitemap_title'                   => ['Páginas', 'Mapa del sitio — título', 'Mapa del sitio', 'text'],
		'sitemap_categories'              => ['Páginas', 'Mapa del sitio — grupo categorías', 'Categorías', 'text'],
		'sitemap_login'                   => ['Páginas', 'Mapa del sitio — ingresar', 'Ingresar', 'text'],
		'sitemap_register'                => ['Páginas', 'Mapa del sitio — registro', 'Registro', 'text'],
		'notfound_title'                  => ['Páginas', 'Página no encontrada — título', 'Esta página no existe', 'text'],
		'notfound_text'                   => ['Páginas', 'Página no encontrada — texto', 'Pero lo que buscás probablemente sí.', 'text'],
		// Buscador
		'search_label'                    => ['Buscador', 'Buscar productos (título accesible y encabezado en el celular)', 'Buscar productos', 'text'],
		'search_placeholder'              => ['Buscador', 'Texto de ayuda del buscador', 'Buscar productos…', 'text'],
		'search_placeholder_categories'   => ['Buscador', 'Texto de ayuda con categorías', 'Buscar {categorias}…', 'text', '{categorias} = las dos primeras categorías.'],
		'search_searching'                => ['Buscador', 'Buscando', 'Buscando…', 'text'],
		'search_close'                    => ['Buscador', 'Cerrar el buscador (título accesible)', 'Cerrar buscador', 'text'],
		'search_categories'               => ['Buscador', 'Columna de categorías', 'Categorías', 'text'],
		'search_all'                      => ['Buscador', 'Todas las categorías', 'Todas', 'text'],
		'search_category_title'           => ['Buscador', 'Categoría — título al pasar el mouse', 'Ver las {n} coincidencias en {categoria}', 'text', '{n} y {categoria} se reemplazan.'],
		'search_products'                 => ['Buscador', 'Columna de productos', 'Productos', 'text'],
		'search_in_category'              => ['Buscador', 'Productos en la categoría elegida', '{n} en {categoria}', 'text', '{n} y {categoria} se reemplazan.'],
		'search_the_category'             => ['Buscador', 'Categoría sin nombre', 'la categoría', 'text'],
		'search_in_stock_n'               => ['Buscador', 'Stock con cantidad', '{n} en stock', 'text', '{n} = las unidades.'],
		'search_no_category_matches'      => ['Buscador', 'Sin coincidencias en la categoría', 'Sin coincidencias en esta categoría.', 'text'],
		'search_view_results_one'         => ['Buscador', 'Ver resultados (uno)', 'Ver el único resultado para “{busqueda}”', 'text', '{busqueda} = lo que se buscó.'],
		'search_view_results_other'       => ['Buscador', 'Ver resultados (varios)', 'Ver los {n} resultados para “{busqueda}”', 'text', '{n} y {busqueda} se reemplazan.'],
		'search_view_in'                  => ['Buscador', 'Ver resultados — en la categoría', 'en {categoria}', 'text', '{categoria} = la categoría elegida.'],
		'search_no_results'               => ['Buscador', 'Sin resultados', 'Sin resultados para “{busqueda}”', 'text', '{busqueda} = lo que se buscó.'],
		// Carrito
		'cart_title'                      => ['Carrito', 'Título', 'Tu carrito', 'text'],
		'cart_loading'                    => ['Carrito', 'Cargando (título accesible)', 'Cargando el carrito', 'text'],
		'cart_load_error'                 => ['Carrito', 'Error al leer el carrito', 'No pudimos leer tu carrito', 'text'],
		'cart_empty_title'                => ['Carrito', 'Vacío — título', 'Todavía no agregaste nada', 'text'],
		'cart_empty_text'                 => ['Carrito', 'Vacío — texto', 'Tus productos te están esperando.', 'text'],
		'cart_remove'                     => ['Carrito', 'Quitar (título accesible)', 'Quitar', 'text'],
		'cart_summary_title'              => ['Carrito', 'Resumen — título', 'Resumen', 'text'],
		'cart_checkout'                   => ['Carrito', 'Botón finalizar compra', 'Finalizar compra', 'text'],
		'cart_quote'                      => ['Carrito', 'Botón pedir presupuesto', 'Solicitar presupuesto', 'text'],
		'cart_secure'                     => ['Carrito', 'Leyenda de pago seguro', 'Pago seguro', 'text', 'Va antes de los medios de pago.'],
		'cart_upsell_eyebrow'             => ['Carrito', 'Sugeridos — antetítulo', 'Antes de irte', 'text'],
		'cart_upsell_title'               => ['Carrito', 'Sugeridos — título', 'Sumá a tu pedido', 'text'],
		'cart_drawer_title'               => ['Carrito', 'Carrito lateral — título', 'Carrito', 'text', 'Si hay productos, va seguido de la cantidad.'],
		'cart_drawer_view'                => ['Carrito', 'Carrito lateral — ver carrito', 'Ver carrito', 'text'],
		'cart_drawer_checkout'            => ['Carrito', 'Carrito lateral — finalizar', 'Finalizar', 'text'],
		'cart_drawer_empty'               => ['Carrito', 'Carrito lateral — vacío', 'Tu carrito está vacío.', 'text'],
		'cart_drawer_remove'              => ['Carrito', 'Carrito lateral — quitar (título accesible)', 'Quitar del carrito', 'text'],
		'cart_cross_sell'                 => ['Carrito', 'Carrito lateral — sugeridos', 'Completá tu setup', 'text'],
		'cart_add_aria'                   => ['Carrito', 'Carrito lateral — agregar sugerido (título accesible)', 'Agregar {nombre}', 'text', '{nombre} = el producto.'],
		'cart_free_shipping_done'         => ['Carrito', 'Envío gratis — alcanzado', '¡Tenés envío GRATIS! 🎉', 'text'],
		'cart_free_shipping_left'         => ['Carrito', 'Envío gratis — cuánto falta', 'Te faltan {monto} para envío {gratis}', 'text', '{monto} = lo que falta; {gratis} = el texto "Envío gratis" (GRATIS), resaltado.'],
		'cart_stock_limit_one'            => ['Carrito', 'Tope de stock (una unidad)', 'Solo hay 1 unidad disponible', 'text'],
		'cart_stock_limit_other'          => ['Carrito', 'Tope de stock (varias)', 'Solo hay {n} unidades disponibles', 'text', '{n} = la cantidad.'],
		'cart_error_load'                 => ['Carrito', 'Error al leer el carrito (aviso)', 'No se pudo leer el carrito.', 'text'],
		'cart_subtotal'                   => ['Carrito', 'Subtotal', 'Subtotal', 'text', 'En el carrito, el carrito lateral y el checkout.'],
		'cart_shipping'                   => ['Carrito', 'Envío', 'Envío', 'text'],
		'cart_total'                      => ['Carrito', 'Total', 'Total', 'text'],
		'cart_free'                       => ['Carrito', 'Envío gratis', 'GRATIS', 'text'],
		'cart_shipping_pending'           => ['Carrito', 'Envío todavía sin calcular', 'Se calcula al finalizar', 'text'],
		'cart_shipping_coordinate'        => ['Carrito', 'Envío a coordinar', 'A coordinar', 'text'],
		// Finalizar compra (checkout)
		'checkout_title'                  => ['Finalizar compra (checkout)', 'Título', 'Checkout', 'text'],
		'checkout_back'                   => ['Finalizar compra (checkout)', 'Volver al carrito', 'Volver al carrito', 'text'],
		'checkout_secure'                 => ['Finalizar compra (checkout)', 'Leyenda de pago seguro', 'Pago 100% seguro y encriptado', 'text'],
		'checkout_express_cta'            => ['Finalizar compra (checkout)', 'Compra rápida — botón', 'Comprar rápido', 'text'],
		'checkout_express_hint'           => ['Finalizar compra (checkout)', 'Compra rápida — aclaración', 'Usamos los datos que ya tenemos y salteás los pasos.', 'text'],
		'checkout_express_use'            => ['Finalizar compra (checkout)', 'Compra rápida — usar', 'Usar', 'text'],
		'checkout_express_title'          => ['Finalizar compra (checkout)', 'Compra rápida — tus datos', 'Tus datos', 'text'],
		'checkout_express_change'         => ['Finalizar compra (checkout)', 'Compra rápida — cambiar', 'Cambiar', 'text'],
		'checkout_express_ships_to'       => ['Finalizar compra (checkout)', 'Compra rápida — a dónde se envía', 'Se envía a {direccion}, {ciudad}.', 'text', '{direccion} y {ciudad} se reemplazan.'],
		'checkout_express_ship_label'     => ['Finalizar compra (checkout)', 'Compra rápida — etiqueta del envío', 'Envío a:', 'text', 'Solo aparece cuando el envío va a otra dirección que la factura.'],
		'checkout_step_contact'           => ['Finalizar compra (checkout)', 'Paso 1 — contacto', 'Contacto', 'text', 'El número del paso lo pone la tienda.'],
		'checkout_shipping_title'         => ['Finalizar compra (checkout)', 'Paso 2 — envío', 'Envío', 'text', 'Con MJ Envíos, manda el título del plugin.'],
		'checkout_step_payment'           => ['Finalizar compra (checkout)', 'Paso 3 — pago', 'Pago', 'text'],
		'checkout_step_invoice'           => ['Finalizar compra (checkout)', 'Paso 4 — facturación', 'Facturación', 'text'],
		'checkout_placeholder_first_name' => ['Finalizar compra (checkout)', 'Nombre — ejemplo (placeholder)', 'María', 'text'],
		'checkout_placeholder_last_name'  => ['Finalizar compra (checkout)', 'Apellido — ejemplo (placeholder)', 'González', 'text'],
		'checkout_placeholder_phone'      => ['Finalizar compra (checkout)', 'Teléfono — ejemplo (placeholder)', '+54 11 ...', 'text'],
		'checkout_placeholder_address'    => ['Finalizar compra (checkout)', 'Dirección — ejemplo (placeholder)', 'Calle y número', 'text'],
		'checkout_placeholder_city'       => ['Finalizar compra (checkout)', 'Ciudad — ejemplo (placeholder)', 'Ciudad', 'text'],
		'checkout_placeholder_postcode'   => ['Finalizar compra (checkout)', 'Código postal — ejemplo (placeholder)', 'CP', 'text'],
		'checkout_no_gateways'            => ['Finalizar compra (checkout)', 'Sin medios de pago', 'No hay métodos de pago configurados. Contactanos para finalizar la compra.', 'text'],
		'checkout_saving'                 => ['Finalizar compra (checkout)', 'Ahorro por medio de pago', 'Ahorrás {monto} pagando con {medio}.', 'text', '{monto} y {medio} se reemplazan.'],
		'checkout_surcharge'              => ['Finalizar compra (checkout)', 'Recargo por medio de pago', 'Recargo de {monto} por pagar con {medio}.', 'text', '{monto} y {medio} se reemplazan.'],
		'checkout_credit_label'           => ['Finalizar compra (checkout)', 'Saldo a favor — nombre', 'Saldo a favor', 'text', 'Si el plugin MJ ERP manda su propio nombre, se usa ese.'],
		'checkout_credit_use'             => ['Finalizar compra (checkout)', 'Saldo a favor — usar', 'Usar mi {saldo}', 'text', '{saldo} = el nombre del saldo, en minúscula.'],
		'checkout_credit_have'            => ['Finalizar compra (checkout)', 'Saldo a favor — disponible', 'Tenés {monto}', 'text', '{monto} = el saldo disponible.'],
		'checkout_credit_includes'        => ['Finalizar compra (checkout)', 'Saldo a favor — otras monedas', '(incluye {montos} al dólar de hoy)', 'text', '{montos} = los saldos en otras monedas.'],
		'checkout_credit_and'             => ['Finalizar compra (checkout)', 'Saldo a favor — unión entre montos', 'y', 'text'],
		'checkout_credit_applied'         => ['Finalizar compra (checkout)', 'Saldo a favor — descuento aplicado', 'Se descuentan {monto} del total.', 'text', '{monto} = lo que se descuenta.'],
		'checkout_credit_less'            => ['Finalizar compra (checkout)', 'Saldo a favor — usar menos', '¿Querés usar menos?', 'text'],
		'checkout_credit_apply'           => ['Finalizar compra (checkout)', 'Saldo a favor — aplicar', 'Aplicar', 'text'],
		'checkout_invoice_no'             => ['Finalizar compra (checkout)', 'Factura — no la necesito', 'No necesito factura', 'text'],
		'checkout_invoice_yes'            => ['Finalizar compra (checkout)', 'Factura — la necesito', 'Sí, necesito factura', 'text'],
		'checkout_invoice_yes_hint'       => ['Finalizar compra (checkout)', 'Factura — cuándo se emite', 'Se emite al completarse el pedido', 'text'],
		'checkout_invoice_type'           => ['Finalizar compra (checkout)', 'Factura — tipo', 'Tipo de factura', 'text'],
		'checkout_invoice_letter'         => ['Finalizar compra (checkout)', 'Factura — nombre con la letra', 'Factura {letra}', 'text', '{letra} = A, B o C.'],
		'checkout_invoice_a_hint'         => ['Finalizar compra (checkout)', 'Factura A — aclaración', 'Responsable Inscripto — requiere CUIT', 'text'],
		'checkout_invoice_b_hint'         => ['Finalizar compra (checkout)', 'Factura B — aclaración', 'Consumidor final, monotributo o exento', 'text'],
		'checkout_invoice_auto'           => ['Finalizar compra (checkout)', 'Factura — tipo automático', 'Se emitirá {factura} con los datos que cargues acá.', 'text', '{factura} = "Factura A/B/C".'],
		'checkout_invoice_receiver'       => ['Finalizar compra (checkout)', 'Factura — receptor', 'Receptor de la factura', 'text'],
		'checkout_invoice_same'           => ['Finalizar compra (checkout)', 'Factura — mismos datos', 'Los mismos datos de la compra', 'text'],
		'checkout_invoice_other'          => ['Finalizar compra (checkout)', 'Factura — otra persona', 'Otra persona o empresa', 'text'],
		'checkout_business_name'          => ['Finalizar compra (checkout)', 'Factura A — razón social', 'Razón social', 'text'],
		'checkout_placeholder_business'   => ['Finalizar compra (checkout)', 'Factura A — razón social (placeholder)', 'Empresa S.A.', 'text'],
		'checkout_placeholder_fullname'   => ['Finalizar compra (checkout)', 'Factura — nombre (placeholder)', 'Nombre completo o razón social', 'text'],
		'checkout_invoice_cuit'           => ['Finalizar compra (checkout)', 'Factura A — CUIT', 'CUIT del receptor', 'text'],
		'checkout_summary_title'          => ['Finalizar compra (checkout)', 'Resumen — título', 'Tu pedido', 'text'],
		'checkout_shipping_method'        => ['Finalizar compra (checkout)', 'Resumen — método de envío', 'Método de envío', 'text'],
		'checkout_usd'                    => ['Finalizar compra (checkout)', 'Resumen — en dólares', 'En dólares', 'text'],
		'checkout_usd_rate'               => ['Finalizar compra (checkout)', 'Resumen — cotización', 'al dólar {cotizacion}', 'text', '{cotizacion} = el valor del dólar.'],
		'checkout_pay'                    => ['Finalizar compra (checkout)', 'Botón pagar', 'Pagar', 'text', 'Va seguido del total.'],
		'checkout_processing'             => ['Finalizar compra (checkout)', 'Botón pagar — procesando', 'Procesando…', 'text'],
		'checkout_error_fields'           => ['Finalizar compra (checkout)', 'Error — campos en rojo', 'Revisá los campos marcados en rojo.', 'text'],
		'checkout_error_invoice_name'     => ['Finalizar compra (checkout)', 'Error — falta el receptor', 'Cargá el nombre o razón social del receptor de la factura.', 'text'],
		'checkout_error_cuit_invalid'     => ['Finalizar compra (checkout)', 'Error — CUIT inválido', 'El CUIT/CUIL del receptor no es válido: revisá los números, hay algún dígito incorrecto.', 'text'],
		'checkout_error_cuit_length'      => ['Finalizar compra (checkout)', 'Error — largo del CUIT', 'El CUIT/CUIL del receptor debe tener 11 dígitos.', 'text'],
		'checkout_error_dni'              => ['Finalizar compra (checkout)', 'Error — DNI', 'El DNI del receptor debe tener 7 u 8 dígitos.', 'text'],
		'checkout_error_rate'             => ['Finalizar compra (checkout)', 'Error — sin forma de envío', 'Elegí cómo querés recibir tu compra.', 'text'],
		'checkout_error_missing'          => ['Finalizar compra (checkout)', 'Error — faltan datos de envío', 'Para poder enviarte la compra falta: {datos}.', 'text', '{datos} = la lista de lo que falta.'],
		'checkout_error_generic'          => ['Finalizar compra (checkout)', 'Error — al procesar el pedido', 'No pudimos procesar el pedido. Revisá los datos e intentá de nuevo.', 'text'],
		'checkout_done_title'             => ['Finalizar compra (checkout)', 'Pedido hecho — título', '¡Pedido confirmado!', 'text'],
		'checkout_done_order'             => ['Finalizar compra (checkout)', 'Pedido hecho — número', 'Tu orden {numero} fue creada.', 'text', '{numero} = el número de pedido.'],
		'checkout_done_email'             => ['Finalizar compra (checkout)', 'Pedido hecho — aviso del email', 'Te enviamos el detalle por email.', 'text'],
		'checkout_done_usd'               => ['Finalizar compra (checkout)', 'Pedido hecho — total en dólares', 'Total a pagar: {monto} al dólar {cotizacion}', 'text', '{monto} y {cotizacion} se reemplazan.'],
		'checkout_payment_howto'          => ['Finalizar compra (checkout)', 'Pedido hecho — cómo pagar', 'Cómo pagar', 'text', 'Si el plugin MJ Medios de Pago manda su texto, se usa ese.'],
		'checkout_done_orders'            => ['Finalizar compra (checkout)', 'Pedido hecho — ver mis pedidos', 'Ver mis pedidos', 'text'],
		'checkout_done_continue'          => ['Finalizar compra (checkout)', 'Pedido hecho — seguir comprando', 'Seguir comprando', 'text'],
		'checkout_empty_title'            => ['Finalizar compra (checkout)', 'Sin productos — título', 'No hay nada para pagar', 'text'],
		'checkout_empty_cta'              => ['Finalizar compra (checkout)', 'Sin productos — botón', 'Ir a la tienda', 'text'],
		// Envío en el checkout
		'shipping_methods_title'          => ['Envío en el checkout', 'Formas de envío — título', 'Cómo lo querés recibir', 'text', 'Los textos del plugin MJ Envíos (paso, código postal, sin envíos) se editan en el plugin.'],
		'shipping_calculating'            => ['Envío en el checkout', 'Calculando el envío', 'Calculando el envío…', 'text'],
		'shipping_choose_city'            => ['Envío en el checkout', 'Elegí tu localidad', 'Elegí tu localidad', 'text'],
		'shipping_choose_state'           => ['Envío en el checkout', 'Elegí tu provincia', 'Elegí tu provincia', 'text'],
		'shipping_city_placeholder'       => ['Envío en el checkout', 'Localidad — ejemplo (placeholder)', 'Tu localidad', 'text'],
		'shipping_address_placeholder'    => ['Envío en el checkout', 'Calle y número — ejemplo (placeholder)', 'Av. Siempre Viva 742', 'text'],
		'shipping_address_2_placeholder'  => ['Envío en el checkout', 'Piso o departamento — ejemplo (placeholder)', '3° B', 'text'],
		'shipping_branch'                 => ['Envío en el checkout', 'Sucursal', 'Sucursal', 'text', 'Si el plugin manda su propio nombre, se usa ese.'],
		'shipping_branches_loading'       => ['Envío en el checkout', 'Buscando sucursales', 'Buscando sucursales…', 'text'],
		'shipping_choose_branch'          => ['Envío en el checkout', 'Elegí la sucursal', 'Elegí la sucursal', 'text'],
		'shipping_no_branches'            => ['Envío en el checkout', 'Sin sucursales', 'No encontramos sucursales para ese código postal. Escribinos y la coordinamos.', 'text'],
		'shipping_choose_option'          => ['Envío en el checkout', 'Elegí una opción', 'Elegí una opción', 'text'],
		// Formularios
		'field_first_name'                => ['Formularios', 'Nombre', 'Nombre', 'text', 'En el checkout, el registro, el contacto y Mi cuenta.'],
		'field_last_name'                 => ['Formularios', 'Apellido', 'Apellido', 'text'],
		'field_email'                     => ['Formularios', 'Email', 'Email', 'text'],
		'field_phone'                     => ['Formularios', 'Teléfono', 'Teléfono', 'text'],
		'field_address'                   => ['Formularios', 'Dirección (checkout sin MJ Envíos)', 'Dirección', 'text'],
		'field_address_1'                 => ['Formularios', 'Calle y número', 'Calle y número', 'text', 'Con MJ Envíos, manda el nombre que tenga el plugin.'],
		'field_address_2'                 => ['Formularios', 'Piso, departamento o referencia', 'Piso, departamento o referencia', 'text'],
		'field_city'                      => ['Formularios', 'Ciudad', 'Ciudad', 'text'],
		'field_locality'                  => ['Formularios', 'Localidad', 'Localidad', 'text'],
		'field_state'                     => ['Formularios', 'Provincia', 'Provincia', 'text'],
		'field_postcode'                  => ['Formularios', 'Código postal', 'Código postal', 'text'],
		'field_country'                   => ['Formularios', 'País', 'País', 'text'],
		'field_password'                  => ['Formularios', 'Contraseña', 'Contraseña', 'text'],
		'field_password_hint'             => ['Formularios', 'Contraseña — ayuda', 'Mínimo 6 caracteres', 'text'],
		'field_message'                   => ['Formularios', 'Mensaje', 'Mensaje', 'text'],
		'field_email_placeholder'         => ['Formularios', 'Email — ejemplo (placeholder)', 'tu@email.com', 'text'],
		'field_name_placeholder'          => ['Formularios', 'Nombre — ejemplo (placeholder)', 'Tu nombre', 'text'],
		'field_last_name_placeholder'     => ['Formularios', 'Apellido — ejemplo (placeholder)', 'Tu apellido', 'text'],
		'validate_required'               => ['Formularios', 'Error — campo obligatorio', 'Completá este campo.', 'text'],
		'validate_email'                  => ['Formularios', 'Error — email', 'Revisá el email: le falta el @ o el dominio.', 'text'],
		'validate_phone_short'            => ['Formularios', 'Error — teléfono corto', 'El teléfono es muy corto: poné código de área y número.', 'text'],
		'validate_phone_long'             => ['Formularios', 'Error — teléfono largo', 'El teléfono es muy largo: revisalo.', 'text'],
		'validate_phone_chars'            => ['Formularios', 'Error — caracteres del teléfono', 'El teléfono solo puede tener números, espacios y guiones.', 'text'],
		'validate_postcode'               => ['Formularios', 'Error — código postal', 'El código postal son 4 números (o el CPA completo).', 'text'],
		'validate_name'                   => ['Formularios', 'Error — nombre muy corto', 'Es muy corto.', 'text'],
		// Facturación
		'fiscal_name'                     => ['Facturación', 'Nombre o razón social', 'Nombre o razón social', 'text', 'Mi cuenta y checkout (plugin MJ Facturador).'],
		'fiscal_doc_type'                 => ['Facturación', 'Tipo de documento', 'Tipo de documento', 'text'],
		'fiscal_doc_cf'                   => ['Facturación', 'Documento: consumidor final', 'Consumidor final (sin identificar)', 'text'],
		'fiscal_doc_cuit'                 => ['Facturación', 'Documento: CUIT', 'CUIT', 'text'],
		'fiscal_doc_dni'                  => ['Facturación', 'Documento: DNI', 'DNI', 'text'],
		'fiscal_doc_number'               => ['Facturación', 'Número de documento', 'Número de documento', 'text'],
		'fiscal_iva'                      => ['Facturación', 'Condición frente al IVA', 'Condición frente al IVA', 'text'],
		'fiscal_iva_cf'                   => ['Facturación', 'IVA: consumidor final', 'Consumidor Final', 'text'],
		'fiscal_iva_ri'                   => ['Facturación', 'IVA: responsable inscripto', 'Responsable Inscripto', 'text'],
		'fiscal_iva_mono'                 => ['Facturación', 'IVA: monotributo', 'Monotributo', 'text'],
		'fiscal_iva_exento'               => ['Facturación', 'IVA: exento', 'Exento', 'text'],
		'fiscal_cuit_invalid'             => ['Facturación', 'CUIT inválido (Mi cuenta)', 'El CUIT/CUIL no es válido: revisá los números.', 'text'],
		'fiscal_dni_invalid'              => ['Facturación', 'DNI inválido (Mi cuenta)', 'El DNI debe tener 7 u 8 dígitos.', 'text'],
		// Ingreso y registro
		'auth_login_title'                => ['Ingreso y registro', 'Ingresar — título', 'Bienvenido de vuelta', 'text'],
		'auth_login_subtitle'             => ['Ingreso y registro', 'Ingresar — bajada', 'Ingresá para ver tus pedidos y favoritos.', 'text'],
		'auth_login_user'                 => ['Ingreso y registro', 'Ingresar — usuario o email', 'Usuario o email', 'text'],
		'auth_remember'                   => ['Ingreso y registro', 'Ingresar — recordarme', 'Recordarme', 'text'],
		'auth_forgot'                     => ['Ingreso y registro', 'Ingresar — olvidé la contraseña', '¿Olvidaste tu contraseña?', 'text'],
		'auth_login_submit'               => ['Ingreso y registro', 'Ingresar — botón', 'Ingresar', 'text'],
		'auth_login_no_account'           => ['Ingreso y registro', 'Ingresar — ¿no tenés cuenta?', '¿No tenés cuenta?', 'text'],
		'auth_login_register_link'        => ['Ingreso y registro', 'Ingresar — enlace para crear una', 'Creá una', 'text'],
		'auth_register_title'             => ['Ingreso y registro', 'Registro — título', 'Creá tu cuenta', 'text'],
		'auth_register_subtitle'          => ['Ingreso y registro', 'Registro — bajada', 'Comprá más rápido y accedé a beneficios exclusivos.', 'text'],
		'auth_register_submit'            => ['Ingreso y registro', 'Registro — botón', 'Crear cuenta', 'text'],
		'auth_register_have_account'      => ['Ingreso y registro', 'Registro — ¿ya tenés cuenta?', '¿Ya tenés cuenta?', 'text'],
		'auth_register_login_link'        => ['Ingreso y registro', 'Registro — enlace para ingresar', 'Ingresá', 'text'],
		'auth_register_closed_subtitle'   => ['Ingreso y registro', 'Registro cerrado — bajada', 'El registro de cuentas nuevas no está disponible.', 'text'],
		'auth_register_closed_text'       => ['Ingreso y registro', 'Registro cerrado — aviso', 'El registro está deshabilitado. Si ya tenés una cuenta, ingresá con tus datos.', 'text'],
		'auth_register_closed_cta'        => ['Ingreso y registro', 'Registro cerrado — botón', 'Ir a ingresar', 'text'],
		'auth_back_to_login'              => ['Ingreso y registro', 'Volver a ingresar', 'Volver a ingresar', 'text'],
		'auth_recover_title'              => ['Ingreso y registro', 'Recuperar — título', 'Recuperar contraseña', 'text'],
		'auth_recover_subtitle'           => ['Ingreso y registro', 'Recuperar — bajada', 'Te enviamos un enlace para restablecerla.', 'text'],
		'auth_recover_submit'             => ['Ingreso y registro', 'Recuperar — botón', 'Enviar enlace', 'text'],
		'auth_recover_sent'               => ['Ingreso y registro', 'Recuperar — enviado', 'Si el email existe, te enviamos el enlace para restablecer tu contraseña.', 'text'],
		// Favoritos y comparador
		'wishlist_eyebrow'                => ['Favoritos y comparador', 'Favoritos — antetítulo', 'Tu selección', 'text'],
		'wishlist_title'                  => ['Favoritos y comparador', 'Favoritos — título', 'Favoritos', 'text'],
		'wishlist_count_one'              => ['Favoritos y comparador', 'Favoritos — cantidad (uno)', '{n} producto guardado.', 'text', '{n} = la cantidad.'],
		'wishlist_count_other'            => ['Favoritos y comparador', 'Favoritos — cantidad (varios)', '{n} productos guardados.', 'text', '{n} = la cantidad.'],
		'wishlist_empty_title'            => ['Favoritos y comparador', 'Favoritos — vacío, título', 'Nada por acá todavía', 'text'],
		'wishlist_empty_text'             => ['Favoritos y comparador', 'Favoritos — vacío, texto', 'Tocá el corazón en cualquier producto para guardarlo.', 'text'],
		'compare_eyebrow'                 => ['Favoritos y comparador', 'Comparador — antetítulo', 'Decisiones fáciles', 'text'],
		'compare_title'                   => ['Favoritos y comparador', 'Comparador — título', 'Comparador', 'text', 'También es el nombre del enlace en el mapa del sitio.'],
		'compare_subtitle'                => ['Favoritos y comparador', 'Comparador — bajada', 'Hasta 4 productos, lado a lado.', 'text'],
		'compare_empty_title'             => ['Favoritos y comparador', 'Comparador — vacío, título', 'Todavía no elegiste productos', 'text'],
		'compare_empty_text'              => ['Favoritos y comparador', 'Comparador — vacío, texto', 'Usá el ícono de balanza en las tarjetas para comparar.', 'text'],
		'compare_price'                   => ['Favoritos y comparador', 'Comparador — fila precio', 'Precio', 'text'],
		'compare_category'                => ['Favoritos y comparador', 'Comparador — fila categoría', 'Categoría', 'text'],
		'compare_rating'                  => ['Favoritos y comparador', 'Comparador — fila valoración', 'Valoración', 'text'],
		'compare_stock'                   => ['Favoritos y comparador', 'Comparador — fila stock', 'Stock', 'text'],
		'compare_in_stock'                => ['Favoritos y comparador', 'Comparador — disponible', 'Disponible', 'text'],
		'compare_out_of_stock'            => ['Favoritos y comparador', 'Comparador — sin stock', 'Sin stock', 'text'],
		'compare_remove'                  => ['Favoritos y comparador', 'Comparador — quitar (título accesible)', 'Quitar de comparación', 'text'],
		'compare_view'                    => ['Favoritos y comparador', 'Comparador — ver producto', 'Ver producto', 'text'],
		'recent_empty_title'              => ['Favoritos y comparador', 'Vistos recientemente — vacío', 'Tu historial está vacío', 'text', 'El antetítulo y el título son los de "Vistos recientemente" (Producto).'],
		'recent_empty_cta'                => ['Favoritos y comparador', 'Vistos recientemente — botón', 'Empezar a explorar', 'text'],
		// Consultas y presupuestos
		'consult_whatsapp'                => ['Consultas y presupuestos', 'Botón de WhatsApp', 'Consultar por WhatsApp', 'text'],
		'consult_topic_title'             => ['Consultas y presupuestos', 'Elegir tema — título', '¿Sobre qué querés consultar?', 'text'],
		'consult_topic_product'           => ['Consultas y presupuestos', 'Tema: este producto', 'Este producto', 'text'],
		'consult_topic_store'             => ['Consultas y presupuestos', 'Tema: la tienda', 'La tienda en general', 'text'],
		'consult_topic_store_hint'        => ['Consultas y presupuestos', 'Tema: la tienda — aclaración', 'Consultas, horarios, envíos y más', 'text'],
		'consult_topic_cart'              => ['Consultas y presupuestos', 'Tema: carrito', 'Mi carrito', 'text'],
		'consult_topic_wishlist'          => ['Consultas y presupuestos', 'Tema: favoritos', 'Mis favoritos', 'text'],
		'consult_topic_compare'           => ['Consultas y presupuestos', 'Tema: comparación', 'Mi comparación', 'text'],
		'consult_msg_product'             => ['Consultas y presupuestos', 'Mensaje: este producto', 'Hola! Quiero consultar sobre este producto de {tienda}:', 'text', '{tienda} = el nombre del sitio.'],
		'consult_msg_store'               => ['Consultas y presupuestos', 'Mensaje: la tienda', 'Hola! Quiero hacer una consulta sobre la tienda {tienda}.', 'text', '{tienda} = el nombre del sitio.'],
		'consult_msg_cart'                => ['Consultas y presupuestos', 'Mensaje: carrito', 'Hola! Quiero consultar sobre estos productos de mi carrito:', 'text'],
		'consult_msg_wishlist'            => ['Consultas y presupuestos', 'Mensaje: favoritos', 'Hola! Quiero consultar sobre estos productos que tengo en favoritos:', 'text'],
		'consult_msg_compare'             => ['Consultas y presupuestos', 'Mensaje: comparación', 'Hola! Quiero consultar sobre estos productos que estoy comparando:', 'text'],
		'consult_seller_title'            => ['Consultas y presupuestos', 'Elegir vendedor — título', '¿Con quién querés hablar?', 'text'],
		'consult_seller'                  => ['Consultas y presupuestos', 'Vendedor sin nombre', 'Vendedor', 'text'],
		'consult_back'                    => ['Consultas y presupuestos', 'Volver', 'Volver', 'text'],
		'consult_pdf'                     => ['Consultas y presupuestos', 'Descargar PDF', 'Descargar PDF', 'text'],
		'consult_pdf_busy'                => ['Consultas y presupuestos', 'Generando el PDF', 'Generando…', 'text'],
		'consult_pdf_title'               => ['Consultas y presupuestos', 'PDF — título', 'Consulta — {tema}', 'text', '{tema} = el tema elegido.'],
		'consult_each'                    => ['Consultas y presupuestos', 'Precio unitario (mensaje y PDF)', 'c/u', 'text'],
		'quote_title'                     => ['Consultas y presupuestos', 'Presupuesto — título', 'Presupuesto', 'text', 'También es el título del PDF y el asunto del mail.'],
		'quote_message'                   => ['Consultas y presupuestos', 'Presupuesto — mensaje', 'Hola! Quiero pedir un presupuesto de estos productos:', 'text'],
		'quote_send_mail'                 => ['Consultas y presupuestos', 'Presupuesto — enviar por mail', 'Enviar por mail', 'text'],
		'quote_send_whatsapp'             => ['Consultas y presupuestos', 'Presupuesto — enviar por WhatsApp', 'Enviar por WhatsApp', 'text'],
		'quote_send_to'                   => ['Consultas y presupuestos', 'Presupuesto — a quién', 'a', 'text', 'Va entre "Enviar por WhatsApp" y el nombre del vendedor.'],
		// Precios y medios de pago
		'price_with_method'               => ['Precios y medios de pago', 'Leyenda del precio con el medio elegido', 'con {medio}', 'text', '{medio} = el medio de pago (plugin MJ Medios de Pago).'],
		'price_off'                       => ['Precios y medios de pago', 'Descuento en porcentaje', '{n}% OFF', 'text', '{n} = el porcentaje.'],
		'price_badge'                     => ['Precios y medios de pago', 'Etiqueta de las tarjetas', '{n}% OFF con {medio}', 'text', 'Si el medio de pago tiene su propia etiqueta, se usa esa.'],
		'price_panel_title'               => ['Precios y medios de pago', 'Ficha — precios por medio de pago', 'Precio según medio de pago', 'text'],
		'price_selector_label'            => ['Precios y medios de pago', 'Selector de medio de pago', 'Ver precios con', 'text', 'Si el plugin manda su propio texto, se usa ese.'],
		'price_note'                      => ['Precios y medios de pago', 'Presupuesto — aclaración del medio de pago', 'Precios pagando con {medio} ({detalle}).', 'text', '{medio} y {detalle} se reemplazan.'],
		'price_note_discount'             => ['Precios y medios de pago', 'Presupuesto — con descuento', 'con descuento', 'text'],
		'price_note_surcharge'            => ['Precios y medios de pago', 'Presupuesto — con recargo', 'con recargo', 'text'],
		'pay_details_copy'                => ['Precios y medios de pago', 'Datos de pago — copiar', 'Copiar', 'text', 'Solo si el plugin MJ Medios de Pago no manda sus textos.'],
		'pay_details_copied'              => ['Precios y medios de pago', 'Datos de pago — copiado', 'Copiado', 'text'],
		'rate_label'                      => ['Precios y medios de pago', 'Dólar — rótulo', 'Dólar', 'text', 'Si el plugin MJ Dólar manda su propio rótulo, se usa ese.'],
		'rate_updated'                    => ['Precios y medios de pago', 'Dólar — última actualización', 'Cotización actualizada el {fecha} a las {hora}', 'text', '{fecha} y {hora} se reemplazan.'],
		// Proyecto de colegas
		'colegas_piece_one'               => ['Proyecto de colegas', 'Pieza (una)', 'pieza', 'text', 'Va después del número.'],
		'colegas_piece_other'             => ['Proyecto de colegas', 'Piezas (varias)', 'piezas', 'text', 'Va después del número.'],
		'colegas_shop_link'               => ['Proyecto de colegas', 'Enlace a la tienda', 'Ver en la tienda', 'text', 'Si el plugin MJ Colegas manda su propio texto, se usa ese.'],
		'colegas_empty'                   => ['Proyecto de colegas', 'Sin piezas publicadas', 'Todavía no hay piezas publicadas', 'text'],
		'colegas_website'                 => ['Proyecto de colegas', 'Botón del sitio web', 'Sitio web', 'text'],
		'colegas_back_to'                 => ['Proyecto de colegas', 'Volver al proyecto', 'Volver a {nombre}', 'text', '{nombre} = el nombre del proyecto.'],
		// Elementos generales
		'ui_explore_shop'                 => ['Elementos generales', 'Botón "explorar la tienda"', 'Explorar la tienda', 'text', 'Favoritos, comparador y carrito vacíos.'],
		'ui_view_shop'                    => ['Elementos generales', 'Botón "ver la tienda"', 'Ver la tienda', 'text', 'Página no encontrada, producto que ya no está, colegas sin piezas.'],
		'ui_go_home'                      => ['Elementos generales', 'Botón "ir al inicio"', 'Ir al inicio', 'text'],
		'ui_view_all'                     => ['Elementos generales', 'Enlace "ver todo" de las secciones', 'Ver todo', 'text'],
		'ui_products'                     => ['Elementos generales', 'Carrusel de productos (título accesible)', 'Productos', 'text'],
		'ui_close'                        => ['Elementos generales', 'Cerrar (título accesible)', 'Cerrar', 'text'],
		'ui_prev'                         => ['Elementos generales', 'Anterior (título accesible)', 'Anterior', 'text'],
		'ui_next'                         => ['Elementos generales', 'Siguiente (título accesible)', 'Siguiente', 'text'],
		'ui_carousel'                     => ['Elementos generales', 'Carrusel (título accesible)', 'Carrusel', 'text'],
		'ui_banner_prev'                  => ['Elementos generales', 'Banner anterior (título accesible)', 'Banner anterior', 'text'],
		'ui_banner_next'                  => ['Elementos generales', 'Banner siguiente (título accesible)', 'Banner siguiente', 'text'],
		'ui_qty_less'                     => ['Elementos generales', 'Cantidad — restar (título accesible)', 'Restar', 'text'],
		'ui_qty_more'                     => ['Elementos generales', 'Cantidad — sumar (título accesible)', 'Sumar', 'text'],
		'ui_rating'                       => ['Elementos generales', 'Valoración (título accesible)', '{n} de 5', 'text', '{n} = las estrellas.'],
		'ui_loading'                      => ['Elementos generales', 'Cargando (título accesible)', 'Cargando', 'text'],
		'ui_skip_to_content'              => ['Elementos generales', 'Saltar al contenido (lectores de pantalla)', 'Saltar al contenido', 'text'],
		'ui_load_error_title'             => ['Elementos generales', 'Error de carga — título', 'No pudimos cargar esto', 'text'],
		'ui_load_error_text'              => ['Elementos generales', 'Error de carga — texto', 'Puede ser la conexión o que el servidor esté tardando. Probá de nuevo en unos segundos.', 'text'],
		'ui_retry'                        => ['Elementos generales', 'Error de carga — reintentar', 'Reintentar', 'text'],
		'ui_retrying'                     => ['Elementos generales', 'Error de carga — reintentando', 'Reintentando…', 'text'],
		'ui_products_load_error'          => ['Elementos generales', 'Error al cargar los productos', 'No pudimos cargar los productos', 'text'],
		'ui_page_load_error'              => ['Elementos generales', 'Error al cargar una página', 'No pudimos cargar la página', 'text'],
		'ui_catalog_error'                => ['Elementos generales', 'Error al cargar el catálogo', 'No se pudo cargar el catálogo.', 'text'],
		'ui_error_title'                  => ['Elementos generales', 'Error inesperado — título', 'Algo salió mal', 'text'],
		'ui_error_text'                   => ['Elementos generales', 'Error inesperado — texto', 'Ya quedó registrado para que lo revisemos. Probá recargar la página; si sigue pasando, escribinos.', 'textarea'],
		'ui_error_reload'                 => ['Elementos generales', 'Error inesperado — recargar', 'Recargar', 'text'],
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

/*
 * Enlace escrito a mano en un ajuste: una ruta de la tienda ("/turnos",
 * "turnos") o una URL completa. Vacío = ''.
 */
function mj_sanitize_link($value) {
	$value = trim((string) (is_scalar($value) ? $value : ''));
	if ('' === $value) {
		return '';
	}
	if (0 === strpos($value, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
		return esc_url_raw($value);
	}
	return '/' . ltrim(sanitize_text_field($value), '/');
}

/*
 * Rutas donde no aparece el botón fijo abajo → lista de rutas limpias.
 * Acepta URLs completas (se queda con la ruta), con o sin barra inicial; un
 * "*" al final quiere decir "esa ruta y todo lo que cuelga de ella".
 */
function mj_sticky_cta_hide_paths($text) {
	$out   = [];
	$lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen');
	foreach ($lines as $line) {
		$wild = '*' === substr($line, -1);
		$path = rtrim($line, '*');
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
			$path = (string) wp_parse_url($path, PHP_URL_PATH);
		}
		$path = trim(sanitize_text_field($path), '/');
		$path = '/' . $path;
		if ($wild) {
			$path = rtrim($path, '/') . '/*';
		}
		$out[] = $path;
	}
	return array_values(array_unique($out));
}

/* Botón fijo abajo, tal como viaja a la SPA (window.MJ.stickyCta). */
function mj_sticky_cta_config(array $options) {
	$defaults = mj_option_defaults();
	$text     = isset($options['sticky_cta_text']) ? trim((string) $options['sticky_cta_text']) : '';
	return [
		'enabled' => !empty($options['sticky_cta_enabled']),
		'text'    => '' !== $text ? $text : (string) $defaults['sticky_cta_text'],
		'url'     => isset($options['sticky_cta_url']) ? (string) $options['sticky_cta_url'] : '',
		'desktop' => !empty($options['sticky_cta_desktop']),
		'hide'    => mj_sticky_cta_hide_paths(isset($options['sticky_cta_hide']) ? (string) $options['sticky_cta_hide'] : ''),
	];
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

	/*
	 * El formulario de ajustes manda cientos de campos. Si el servidor tiene un
	 * max_input_vars bajo, PHP corta el envío sin avisar y lo que queda afuera
	 * se guardaría vacío. El testigo va último en el formulario: si no llegó,
	 * no se toca nada y se avisa. (Los guardados que no vienen del formulario,
	 * como el botón "Aplicar" de una paleta, no lo necesitan.)
	 */
	if (isset($_POST['option_page']) && mj_settings_option_group() === sanitize_key(wp_unslash($_POST['option_page'])) && empty($input['_mj_form_complete'])) { // phpcs:ignore WordPress.Security.NonceVerification -- options.php ya verificó el nonce.
		add_settings_error('mj_options', 'mj_form_cut', sprintf('No se guardó nada: el servidor cortó el formulario (su límite max_input_vars es %d). Pedile al hosting que lo suba a 3000 o más y volvé a guardar.', (int) ini_get('max_input_vars')));
		$saved = get_option('mj_options', []);
		return is_array($saved) ? $saved : [];
	}
	unset($input['_mj_form_complete']);
	$out = [];

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

	/* SEO. */
	$out['seo_description'] = isset($input['seo_description']) ? sanitize_textarea_field($input['seo_description']) : '';
	$out['seo_share_image'] = isset($input['seo_share_image']) ? absint(strtok((string) $input['seo_share_image'], ',')) : 0;
	$out['about_highlights'] = isset($input['about_highlights']) ? sanitize_textarea_field($input['about_highlights']) : '';
	$out['about_stats']      = isset($input['about_stats']) ? sanitize_textarea_field($input['about_stats']) : '';

	$out['free_shipping_override'] = isset($input['free_shipping_override']) ? absint($input['free_shipping_override']) : 0;

	/*
	 * Categorías del menú: quedó como semilla del menú por defecto (ver inc/nav.php).
	 * Ya no tiene formulario propio, así que si no llega se conserva lo que había:
	 * borrarla en cada guardado cambiaría el menú de quien nunca tocó el panel nuevo.
	 */
	if (isset($input['menu_categories'])) {
		$slugs = array_values(array_filter(array_map('sanitize_title', (array) $input['menu_categories'])));
		$out['menu_categories'] = implode(',', array_slice($slugs, 0, 4));
	} else {
		$out['menu_categories'] = (string) mj_get_options()['menu_categories'];
	}

	// Barra de navegación: orden, visibilidad, nombres y destinos (inc/nav.php).
	$out['nav_items'] = mj_nav_sanitize($input);

	// Diseño: tema por defecto, paleta y tipografías.
	$theme               = isset($input['design_theme']) ? (string) $input['design_theme'] : 'dark';
	$out['design_theme'] = isset(mj_theme_choices()[$theme]) ? $theme : 'dark';

	$motion               = isset($input['motion_level']) ? (string) $input['motion_level'] : 'medium';
	$out['motion_level']  = isset(mj_motion_choices()[$motion]) ? $motion : 'medium';

	$switch               = isset($input['theme_switch']) ? (string) $input['theme_switch'] : 'instant';
	$out['theme_switch']  = isset(mj_theme_switch_choices()[$switch]) ? $switch : 'instant';
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

	/* Frenos de los endpoints públicos: 0 apaga el freno; el tope evita un número absurdo por error. */
	$out['rate_limit_auth']        = min(1000, max(0, isset($input['rate_limit_auth']) ? absint($input['rate_limit_auth']) : 0));
	$out['rate_limit_contact']     = min(1000, max(0, isset($input['rate_limit_contact']) ? absint($input['rate_limit_contact']) : 0));
	$out['rate_limit_window']      = min(1440, max(1, isset($input['rate_limit_window']) ? absint($input['rate_limit_window']) : 10));
	$out['catalog_stock_quantity'] = !empty($input['catalog_stock_quantity']) ? 1 : 0;
	$out['catalog_stock_cache']    = min(1440, max(1, isset($input['catalog_stock_cache']) ? absint($input['catalog_stock_cache']) : 10));
	if (function_exists('mj_catalog_stock_flush')) {
		mj_catalog_stock_flush();
	}
	$out['login_reload']           = !empty($input['login_reload']) ? 1 : 0;
	$out['express_checkout']       = !empty($input['express_checkout']) ? 1 : 0;
	$out['express_auto']           = !empty($input['express_auto']) ? 1 : 0;
	$out['express_label']          = isset($input['express_label']) ? sanitize_text_field((string) $input['express_label']) : '';

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

	// Páginas de WordPress (editor de bloques).
	$out['pages_enabled']  = !empty($input['pages_enabled']) ? 1 : 0;
	$out['pages_override'] = !empty($input['pages_override']) ? 1 : 0;
	$editor_theme          = isset($input['editor_theme']) ? sanitize_key($input['editor_theme']) : 'auto';
	$out['editor_theme']   = in_array($editor_theme, ['auto', 'light', 'dark'], true) ? $editor_theme : 'auto';

	/* Botón fijo abajo. */
	$out['sticky_cta_enabled'] = !empty($input['sticky_cta_enabled']) ? 1 : 0;
	$out['sticky_cta_desktop'] = !empty($input['sticky_cta_desktop']) ? 1 : 0;
	$out['sticky_cta_text']    = isset($input['sticky_cta_text']) ? sanitize_text_field((string) $input['sticky_cta_text']) : '';
	$out['sticky_cta_url']     = mj_sanitize_link(isset($input['sticky_cta_url']) ? $input['sticky_cta_url'] : '');
	$out['sticky_cta_hide']    = implode("\n", mj_sticky_cta_hide_paths(isset($input['sticky_cta_hide']) ? sanitize_textarea_field((string) $input['sticky_cta_hide']) : ''));

	/* Contacto: zona de atención y qué se muestra. */
	$out['contact_area']           = isset($input['contact_area']) ? sanitize_text_field((string) $input['contact_area']) : '';
	$out['contact_show_instagram'] = !empty($input['contact_show_instagram']) ? 1 : 0;
	$out['contact_show_form']      = !empty($input['contact_show_form']) ? 1 : 0;

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
		'layout-dashboard', 'external-link', 'arrow-up-right', 'briefcase', 'warehouse',
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
					<th scope="row"><label for="mj_theme_switch">Efecto al cambiar de tema</label></th>
					<td>
						<select id="mj_theme_switch" name="mj_options[theme_switch]">
							<?php foreach (mj_theme_switch_choices() as $mj_value => $mj_label) : ?>
								<option value="<?php echo esc_attr($mj_value); ?>" <?php selected($o['theme_switch'], $mj_value); ?>><?php echo esc_html($mj_label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Qué pasa al tocar el botón sol/luna. "Instantáneo" es lo más rápido y lo que se recomienda; con "reducir movimiento" activado en el sistema del visitante siempre es instantáneo.</p>
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
					<th scope="row">Compra rápida</th>
					<td>
						<label for="mj_express_checkout">
							<input type="checkbox" id="mj_express_checkout" name="mj_options[express_checkout]" value="1" <?php checked((int) $o['express_checkout'], 1); ?> />
							Ofrecer la compra rápida a quien ya tenga sus datos guardados.
						</label>
						<p class="description">Un cliente con sesión iniciada y dirección cargada ve el resumen del pedido y un solo botón, en lugar de los cuatro pasos. Siempre puede abrir el formulario completo para cambiar algo.</p>
						<p style="margin:8px 0 0;">
							<label for="mj_express_auto">
								<input type="checkbox" id="mj_express_auto" name="mj_options[express_auto]" value="1" <?php checked((int) $o['express_auto'], 1); ?> />
								Entrar directo en modo rápido.
							</label>
						</p>
						<p class="description">Desmarcado, el checkout arranca completo y la compra rápida aparece como un atajo arriba.</p>
						<p style="margin:8px 0 0;">
							<input type="text" id="mj_express_label" name="mj_options[express_label]" value="<?php echo esc_attr((string) $o['express_label']); ?>" class="regular-text" placeholder="Comprar ahora" />
							<label for="mj_express_label">Texto del botón</label>
						</p>
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
					<th scope="row"><label for="mj_rate_limit_auth">Freno por IP</label></th>
					<td>
						<p style="margin:6px 0;">
							<input type="number" id="mj_rate_limit_auth" name="mj_options[rate_limit_auth]" value="<?php echo esc_attr((string) (int) $o['rate_limit_auth']); ?>" min="0" max="1000" step="1" class="small-text" />
							<label for="mj_rate_limit_auth">intentos fallidos de acceso, registros y recuperaciones de contraseña</label>
						</p>
						<p style="margin:6px 0;">
							<input type="number" id="mj_rate_limit_contact" name="mj_options[rate_limit_contact]" value="<?php echo esc_attr((string) (int) $o['rate_limit_contact']); ?>" min="0" max="1000" step="1" class="small-text" />
							<label for="mj_rate_limit_contact">envíos del formulario de contacto y del newsletter</label>
						</p>
						<p style="margin:6px 0;">
							<input type="number" id="mj_rate_limit_window" name="mj_options[rate_limit_window]" value="<?php echo esc_attr((string) (int) $o['rate_limit_window']); ?>" min="1" max="1440" step="1" class="small-text" />
							<label for="mj_rate_limit_window">minutos de ventana</label>
						</p>
						<p class="description">Cuántos intentos se aceptan desde una misma IP antes de frenarla unos minutos. Protege el acceso de la fuerza bruta y el contacto del spam. 0 = sin freno. Si el sitio está detrás de Cloudflare o un proxy que no reescribe la IP, todas las visitas comparten una y conviene el filtro <code>mj_client_ip</code> (ver <code>CONFIGURACION.md</code>).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Stock en el buscador</th>
					<td>
						<label for="mj_catalog_stock_quantity">
							<input type="checkbox" id="mj_catalog_stock_quantity" name="mj_options[catalog_stock_quantity]" value="1" <?php checked((int) $o['catalog_stock_quantity'], 1); ?> />
							Mostrar la cantidad exacta de stock en los resultados del buscador ("12 en stock").
						</label>
						<p style="margin:8px 0 0;">
							<input type="number" id="mj_catalog_stock_cache" name="mj_options[catalog_stock_cache]" value="<?php echo esc_attr((string) (int) $o['catalog_stock_cache']); ?>" min="1" max="1440" step="1" class="small-text" />
							<label for="mj_catalog_stock_cache">minutos de caché del listado</label>
						</p>
						<p class="description">Desmarcado, el buscador dice solo "En stock" o "Sin stock", que es lo que la tienda de WooCommerce publica. La cantidad es un dato que cualquiera puede leer de la dirección pública. El listado se guarda en caché y se renueva solo cuando cambia un producto o su stock.</p>
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

			<?php mj_render_nav_settings(); ?>

			<h2>Marcas</h2>
			<table class="form-table" role="presentation">
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
				<tr>
					<th scope="row">Botón fijo abajo</th>
					<td>
						<label for="mj_sticky_cta_enabled">
							<input type="checkbox" id="mj_sticky_cta_enabled" name="mj_options[sticky_cta_enabled]" value="1" <?php checked((int) $o['sticky_cta_enabled'], 1); ?> />
							Mostrar un botón grande, a todo el ancho, pegado abajo de la pantalla (arriba de la barra inferior del celular).
						</label>
						<p style="margin:8px 0 0;">
							<input type="text" id="mj_sticky_cta_text" name="mj_options[sticky_cta_text]" value="<?php echo esc_attr((string) $o['sticky_cta_text']); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) mj_option_defaults()['sticky_cta_text']); ?>" />
							<label for="mj_sticky_cta_text">Texto del botón</label>
						</p>
						<p style="margin:8px 0 0;">
							<input type="text" id="mj_sticky_cta_url" name="mj_options[sticky_cta_url]" value="<?php echo esc_attr((string) $o['sticky_cta_url']); ?>" class="regular-text" placeholder="/turnos" />
							<label for="mj_sticky_cta_url">A dónde lleva</label>
						</p>
						<p class="description">Una ruta de la tienda (<code>/turnos</code>, <code>/servicios</code>) o una URL completa. Vacío = la reserva de turnos si el plugin MJ Turnos Online está activo; si no, la tienda.</p>
						<p style="margin:8px 0 0;">
							<label for="mj_sticky_cta_desktop">
								<input type="checkbox" id="mj_sticky_cta_desktop" name="mj_options[sticky_cta_desktop]" value="1" <?php checked((int) $o['sticky_cta_desktop'], 1); ?> />
								Mostrarlo también en la computadora (desmarcado = solo en el celular y la tablet).
							</label>
						</p>
						<p style="margin:10px 0 4px;"><label for="mj_sticky_cta_hide">Rutas donde no aparece</label></p>
						<textarea id="mj_sticky_cta_hide" name="mj_options[sticky_cta_hide]" rows="3" class="large-text code" placeholder="/nosotros&#10;/blog/*"><?php echo esc_textarea((string) $o['sticky_cta_hide']); ?></textarea>
						<p class="description">Una por línea. Con <code>*</code> al final vale para esa ruta y todo lo que cuelga de ella (<code>/blog/*</code>). Además nunca aparece en la página a la que lleva, en el carrito, el checkout, Mi cuenta ni en la ficha de un producto (que ya tiene su propia barra de compra abajo).</p>
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
						<p class="description">Arrastrá para cambiar el orden. Destildá una sección para ocultarla. El hero siempre va primero. Las secciones sin datos (sin banners, sin marcas, sin opiniones, sin Instagram, sin foto ni texto de "Nosotros") se ocultan solas aunque estén marcadas. Si elegís una página como portada (sección "Páginas", más abajo), la portada se arma con los bloques de esa página y este orden no se usa.</p>
					</td>
				</tr>
			</table>

			<?php mj_render_pages_section($o); ?>

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
				<tr>
					<th scope="row"><label for="mj_contact_area">Zona de atención</label></th>
					<td>
						<input type="text" id="mj_contact_area" name="mj_options[contact_area]" value="<?php echo esc_attr((string) $o['contact_area']); ?>" class="large-text" placeholder="Atiendo en zona Palermo, CABA" />
						<p class="description">Para no publicar la dirección exacta: se muestra como un canal más en la página de contacto. Vacío = no se muestra. Con la dirección y el mapa vacíos, no aparece ninguno de los dos.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Qué se muestra en Contacto</th>
					<td>
						<label for="mj_contact_show_instagram">
							<input type="checkbox" id="mj_contact_show_instagram" name="mj_options[contact_show_instagram]" value="1" <?php checked((int) $o['contact_show_instagram'], 1); ?> />
							Sumar Instagram a los canales (el enlace de Redes sociales).
						</label>
						<p style="margin:8px 0 0;">
							<label for="mj_contact_show_form">
								<input type="checkbox" id="mj_contact_show_form" name="mj_options[contact_show_form]" value="1" <?php checked((int) $o['contact_show_form'], 1); ?> />
								Mostrar el formulario de contacto.
							</label>
						</p>
						<p class="description">Cada canal aparece solo si tiene dato. Para mostrar únicamente Instagram: dejá vacíos teléfono, WhatsApp, dirección, mapa y email, marcá Instagram y desmarcá el formulario (si lo dejás y el email está vacío, los mensajes llegan al email del administrador). Vale para la página de contacto y para el bloque "Contacto".</p>
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

			<h2>SEO</h2>
			<p>Lo que Google y WhatsApp ven de cada URL: título, descripción y foto al compartir. Cada producto y categoría usa su propio nombre, descripción corta y foto; los títulos de las demás pantallas se editan en <strong>Textos de la tienda → SEO</strong>. Con Rank Math o Yoast activos, el plugin manda en productos, categorías y portada, y el tema completa las pantallas que el plugin no conoce.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mj_seo_description">Descripción del sitio</label></th>
					<td>
						<textarea id="mj_seo_description" name="mj_options[seo_description]" rows="2" class="large-text" placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>"><?php echo esc_textarea($o['seo_description']); ?></textarea>
						<p class="description">Va en la portada y en las pantallas sin descripción propia. Ideal: una o dos frases, hasta 160 caracteres, que digan qué vendés y a dónde. Vacío = la descripción corta de Ajustes → Generales.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Foto al compartir</th>
					<td>
						<?php mj_render_media_picker('seo_share_image', $o['seo_share_image'], 'Elegir foto…', 'La imagen de la tarjeta de WhatsApp, Instagram y Facebook cuando la URL no tiene foto propia (portada, contacto, tienda). Ideal: 1200 × 630 px. Vacío = el logo.', false); ?>
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
			<?php /* Testigo: va último. Si no llega, el servidor cortó el formulario (max_input_vars) y no se guarda nada. */ ?>
			<input type="hidden" name="mj_options[_mj_form_complete]" value="1" />
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

		/* ------------- Barra de navegación: orden, alta y baja de ítems ------------- */
		var $nav = $('#mj-nav-items');

		function mjSyncNavOrder() {
			$('#mj_nav_order').val(
				$nav.children('li').map(function () { return $(this).data('key'); }).get().join(',')
			);
		}

		if ($nav.length && $.fn.sortable) {
			$nav.sortable({
				items: '> li',
				handle: '.mj-nav-handle',
				axis: 'y',
				containment: 'parent',
				tolerance: 'pointer',
				placeholder: 'mj-nav-placeholder',
				start: function (e, ui) {
					ui.placeholder.height(ui.item.outerHeight()).css({ border: '1px dashed #2271b1', borderRadius: '6px', margin: '0 0 8px', background: '#f0f6fc' });
				},
				update: mjSyncNavOrder
			});
		}
		mjSyncNavOrder();

		/*
		 * Enlace propio: se clona el molde cambiando __KEY__ por una clave nueva.
		 * La clave identifica la fila en el formulario y en el orden, así que tiene
		 * que ser única y no repetirse entre guardados.
		 */
		$('.mj-nav-add').on('click', function (e) {
			e.preventDefault();
			var tpl = $('#mj-nav-template').html();
			if (!tpl || !$nav.length) {
				return;
			}
			var key = 'custom:' + Date.now().toString(36) + Math.floor(Math.random() * 1000).toString(36);
			$nav.append(tpl.split('__KEY__').join(key));
			mjSyncNavOrder();
			$nav.children('li').last().find('input[type="text"]').first().trigger('focus');
		});

		$(document).on('click', '.mj-nav-remove', function (e) {
			e.preventDefault();
			$(this).closest('li').remove();
			mjSyncNavOrder();
		});

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
