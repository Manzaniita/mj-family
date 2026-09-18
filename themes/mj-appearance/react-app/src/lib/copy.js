/**
 * Textos de la tienda (copy), configurables desde Apariencia → MJ Appearance
 * → Textos de la tienda (window.MJ.copy). PHP manda el mapa efectivo: el
 * default del tema (o del tema hijo activo) más lo que escribió el admin.
 * Estos defaults son el espejo para el modo standalone y el último fallback.
 */
import { WP } from './config';

export const COPY_DEFAULTS = {
  home_featured_eyebrow: 'Selección',
  home_featured_title: 'Destacados de la semana',
  home_featured_subtitle: 'Lo que nuestro equipo elegiría hoy.',
  home_categories_eyebrow: 'Categorías',
  home_categories_title: 'Explorá por universo',
  home_categories_link: 'Ver la tienda',
  home_latest_eyebrow: 'Recién llegados',
  home_latest_title: 'Últimos ingresos',
  home_recommended_eyebrow: 'Para vos',
  home_recommended_title: 'Recomendados',
  home_recommended_subtitle: 'Los mejor valorados por nuestra comunidad.',
  home_brands_label: 'Trabajamos con las mejores marcas',
  home_testimonials_eyebrow: 'Comunidad',
  home_testimonials_title: 'Lo que dicen de nosotros',
  home_instagram_title: 'Seguinos en Instagram',
  home_instagram_link: 'Seguinos',
  home_about_eyebrow: 'Sobre nosotros',
  home_about_title: 'Quiénes somos',
  home_about_text: '', // vacío = primer párrafo de WP.aboutText
  home_about_link: 'Conocenos',
  // Menú y navegación
  nav_home: 'Inicio',
  nav_shop: 'Tienda',
  nav_sale: 'Ofertas',
  nav_new: 'Novedades',
  nav_contact: 'Contacto',
  nav_about: '', // vacío = la página Nosotros no va en el menú
  nav_menu: 'Menú',
  nav_search: 'Buscador',
  nav_cart: 'Carrito',
  nav_account: 'Cuenta',
  nav_home_mobile: 'Inicio',
  shop_eyebrow: 'Catálogo',
  shop_title: 'Tienda',
  shop_sale_title: 'Ofertas',
  shop_new_title: 'Novedades',
  shop_empty_title: 'Sin resultados',
  shop_empty_text: 'Probá ajustando los filtros o buscá otra cosa.',
  brands_eyebrow: 'Catálogo',
  brands_subtitle: 'Trabajamos con las mejores marcas del mercado.',
  product_related_eyebrow: 'Seguí explorando',
  product_related_title: 'También te puede gustar',
  product_recent_eyebrow: 'Tu historial',
  product_recent_title: 'Vistos recientemente',
  footer_newsletter_title: 'Lo nuevo, antes que nadie.',
  footer_newsletter_text: 'Lanzamientos, ofertas exclusivas y novedades. Sin spam, prometido.',
  footer_tagline: '',
  footer_rights: 'Todos los derechos reservados.',
  footer_group_shop: 'Tienda',
  footer_group_help: 'Ayuda',
  footer_group_account: 'Cuenta',
  // SEO: título de la pestaña y descripción de cada pantalla (inc/seo.php los usa en el servidor).
  seo_title_shop: '', // vacío = el título de la tienda
  seo_desc_shop: 'Todo el catálogo con precios, stock y envíos a todo el país.',
  seo_title_brands: '', // vacío = el nombre en plural de "marcas"
  seo_desc_brands: 'Las marcas con las que trabajamos, con todos sus productos.',
  seo_title_contact: 'Contacto',
  seo_desc_contact: 'Escribinos por WhatsApp, mail o teléfono y te respondemos a la brevedad.',
  seo_title_about: 'Nosotros',
  seo_desc_about: 'Quiénes somos, cómo trabajamos y por qué comprar con nosotros.',
  seo_title_faq: 'Preguntas frecuentes',
  seo_desc_faq: 'Envíos, pagos, cambios y garantías: las respuestas a lo que más nos preguntan.',
  seo_title_terms: 'Términos y condiciones',
  seo_title_privacy: 'Política de privacidad',
  seo_title_sitemap: 'Mapa del sitio',
  seo_title_search: 'Buscar',
  seo_title_cart: 'Tu carrito',
  seo_title_checkout: 'Finalizar compra',
  seo_title_account: 'Mi cuenta',
  seo_title_login: 'Iniciar sesión',
  seo_title_register: 'Crear cuenta',
  seo_title_recover: 'Recuperar contraseña',
  seo_title_wishlist: 'Favoritos',
  seo_title_compare: 'Comparar productos',
  seo_title_recent: 'Vistos recientemente',
  seo_title_notfound: 'Página no encontrada',
  seo_desc_category: 'Comprá {nombre} en {sitio}: precios, stock y envíos a todo el país.',
  seo_desc_brand: 'Todos los productos {nombre} en {sitio}, con precios y stock al día.',
  footer_link_contact: 'Contacto',
  footer_link_faq: 'Preguntas frecuentes',
  footer_link_terms: 'Términos y condiciones',
  footer_link_privacy: 'Privacidad',
  footer_link_account: 'Mi cuenta',
  footer_link_orders: 'Pedidos',
  footer_link_wishlist: 'Favoritos',
  about_eyebrow: 'Nosotros',
  about_title: 'No vendemos productos. Diseñamos la forma de conseguirlos.',
  about_highlights_title: '', // vacío = sin título sobre los puntos destacados
  about_cta_text: '', // vacío = sin botón al final de Nosotros
  about_cta_url: '',
  contact_eyebrow: 'Hablemos',
  contact_title: 'Contacto',
  contact_subtitle: 'Escribinos y te respondemos a la brevedad.',
  faq_subtitle: 'Todo lo que necesitás saber antes de comprar.',
  contact_label_address: 'Dirección',
  contact_label_phone: 'Teléfono',
  contact_label_whatsapp: 'WhatsApp',
  contact_label_email: 'Email',
  contact_label_instagram: 'Instagram',
  contact_label_area: 'Zona de atención',
  // Mi cuenta
  account_nav_orders: 'Pedidos',
  account_orders_title: 'Mis pedidos',
  account_orders_empty: 'Todavía no hiciste ningún pedido.',
  account_orders_cta: 'Explorar la tienda',
  account_orders_back: 'Volver a pedidos',
  account_order_title: 'Pedido',
  account_status_label: 'Estado',
  account_payment_label: 'Pago',
  // Galería de trabajos (bloque mj/galeria)
  gallery_page_eyebrow: 'Trabajos',
  gallery_page_title: 'Galería',
  gallery_page_subtitle: '',
  gallery_filter_all: 'Todos',
  gallery_open: 'Ver la foto grande',
  gallery_prev: 'Foto anterior',
  gallery_next: 'Foto siguiente',
  // Servicios (MJ Turnos Online): título del grupo sin categoría. Vacío = sin título.
  services_uncategorized: '',
};

const saved = WP?.copy && typeof WP.copy === 'object' ? WP.copy : {};

/** Texto por clave. `fallback` pisa el default del tema cuando el llamador tiene uno mejor. */
export function t(key, fallback) {
  const value = saved[key];
  if (typeof value === 'string' && value.trim() !== '') return value;
  return fallback ?? COPY_DEFAULTS[key] ?? '';
}

/** Secciones de la portada ocultas por el admin (Apariencia → MJ Appearance → Secciones). */
const hidden = new Set(Array.isArray(WP?.homeHidden) ? WP.homeHidden : []);
export const homeSectionVisible = (key) => !hidden.has(key);

/** Cómo se llama a las "marcas" (la tienda las deriva del atributo Marca/Brand). */
export const BRAND_LABEL = {
  singular: WP?.brandLabel?.singular || 'Marca',
  plural: WP?.brandLabel?.plural || 'Marcas',
};

/** Legales escritos por el admin: [{ h, body }] o null (→ texto genérico del tema). */
const legalList = (list) => (Array.isArray(list) && list.length ? list : null);
export const LEGAL = {
  terms: legalList(WP?.legal?.terms),
  privacy: legalList(WP?.legal?.privacy),
};
