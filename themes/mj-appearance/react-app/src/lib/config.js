/**
 * Configuración inyectada por WordPress (functions.php → window.MJ).
 * Si no existe (dev standalone con `npm run dev`), la app usa el catálogo demo.
 * Todos los campos son opcionales en dev → siempre fallback defensivo.
 */
export const WP = typeof window !== 'undefined' && window.MJ ? window.MJ : null;

export const IS_WP = !!WP?.restUrl;

/** Moneda: defaults ARS mergeados con lo que mande PHP (currency). */
export const CUR = {
  code: 'ARS',
  symbol: '$',
  decimals: 2,
  thousandSep: '.',
  decimalSep: ',',
  position: 'left', // 'left' | 'right' | 'left_space' | 'right_space'
  ...(WP?.currency || {}),
};

/**
 * Valoraciones activas — el dueño puede desactivarlas en WooCommerce
 * (window.MJ.reviewsEnabled). false → ocultar estrellas/opiniones en toda la UI.
 * Standalone sin WP → true (demo como siempre).
 */
export const REVIEWS_ENABLED = WP?.reviewsEnabled !== false;

/**
 * Sesión y acceso (Apariencia → MJ Appearance → Sesión y acceso).
 *
 * `reloadOnLogin` es el que hace que la barra de administración aparezca
 * apenas iniciás sesión: WordPress sólo puede pintarla al armar la página, y
 * la tienda es una SPA que no recarga por su cuenta. Fuera de WordPress
 * (`npm run dev`) no hay nada que recargar, así que queda apagado.
 */
export const SESSION = {
  rememberDefault: WP?.session?.rememberDefault !== false,
  reloadOnLogin: IS_WP && WP?.session?.reloadOnLogin !== false,
  accountPath: WP?.session?.accountPath || '/cuenta',
  adminBar: !!WP?.session?.adminBar,
};

/**
 * Compra rápida (Apariencia → MJ Appearance → Sesión y acceso).
 *
 * `enabled` solo dice que está habilitada. Quién califica lo decide el checkout cuando ve los datos
 * guardados del cliente: sin dirección completa no hay atajo que valga, porque el pedido no se
 * podría cerrar igual.
 */
export const EXPRESS = {
  enabled: IS_WP && WP?.express?.enabled !== false,
  auto: WP?.express?.auto !== false,
  label: WP?.express?.label || 'Comprar ahora',
};

export const CONTACT = WP?.contact || {};

export const SOCIAL = WP?.social || {};

/** Vendedores de WhatsApp configurados en Apariencia → MJ Appearance: [{ name, number }]. */
export const WHATSAPP_NUMBERS = Array.isArray(WP?.whatsappNumbers) ? WP.whatsappNumbers : [];

/** Texto del presupuesto (condiciones, validez, etc.) configurado en Apariencia → MJ Appearance. */
export const QUOTE_INFO = WP?.quoteInfo || '';

/**
 * Tipo de cambio (plugin MJ Dólar → window.MJ.exchangeRate). null si el plugin
 * está inactivo o todavía no hay TC cargado: la UI no muestra nada.
 * { rate, formatted, label, updatedAt, showHeader, showUsdPrice: 'off' | 'product' | 'all' }
 */
export const EXCHANGE_RATE =
  WP?.exchangeRate && Number(WP.exchangeRate.rate) > 0 ? WP.exchangeRate : null;

/**
 * Precios por medio de pago (plugin MJ Medios de Pago → window.MJ.paymentPricing).
 * null si el plugin está inactivo: la tienda muestra el precio base de siempre.
 * { base, defaultDisplay, rounding, roundingMode, applyOnSale, stackCoupons, showSelector,
 *   selectorLabel, showBadge, showPanel, showListPrice, methods: [{ id, title, short, kind, mode, value, label, badge, ... }] }
 */
export const PAYMENT_PRICING =
  WP?.paymentPricing && Array.isArray(WP.paymentPricing.methods) ? WP.paymentPricing : null;

export const SITE = {
  name: WP?.siteName || 'mj',
  tagline: WP?.tagline || '',
  locale: WP?.locale || 'es-AR',
};

/**
 * Accesos extra en «Mi cuenta» que agregan los plugins MJ (filtro `mj_account_links`).
 * Cada uno es `{ key, label, href, description }` y se dibuja al final del menú lateral,
 * debajo de los ítems propios de la tienda.
 *
 * Existe porque un cliente puede ser además otra cosa —hoy revendedor de MJ ERP— y esa
 * pantalla tiene que poder encontrarse desde su cuenta, sin que el tema sepa qué plugins
 * hay instalados. Sin plugins, la lista viene vacía y el menú queda igual que siempre.
 */
export const ACCOUNT_LINKS = (Array.isArray(WP?.accountLinks) ? WP.accountLinks : [])
  .map((link) => ({
    key: String(link?.key || link?.href || ''),
    label: String(link?.label || ''),
    href: String(link?.href || ''),
    description: String(link?.description || ''),
  }))
  .filter((link) => link.label && link.href);

/* ---------------------------------------------------------------------------
 * Presentación: tema por defecto, skin activa, nivel de animación, intro y
 * efecto del cambio de tema.
 *
 * En WordPress vienen de PHP (Apariencia → MJ Appearance → Diseño y marca; la
 * skin la declara el tema hijo). Para previsualizar, cualquiera de ellos
 * se puede forzar por query string — también sirve en standalone:
 *   ?theme=light&skin=atelier&motion=cinematic&intro=1&switch=circle
 * ------------------------------------------------------------------------- */
const qs = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

/** 'dark' | 'light' | 'system' — con qué tema se abre si el visitante no eligió. */
export const THEME_DEFAULT = pick(qs?.get('theme') || WP?.theme, ['dark', 'light', 'system'], 'dark');

/** Slug de la skin (tema hijo) activa; '' = MJ Appearance base. */
export const SKIN =
  qs?.get('skin') ||
  WP?.skin ||
  (typeof document !== 'undefined' ? document.documentElement.dataset.skin : '') ||
  '';

/** 'none' | 'subtle' | 'medium' | 'cinematic' — cuánta animación lleva la tienda ('none' = todo quieto). */
export const MOTION = pick(qs?.get('motion') || WP?.motion, ['none', 'subtle', 'medium', 'cinematic'], 'medium');

/**
 * 'instant' | 'fade' | 'circle' — cómo cambia la tienda de día a noche al tocar el
 * botón sol/luna. Instantáneo por defecto: el cambio tiene que sentirse al clic.
 */
export const THEME_SWITCH = pick(qs?.get('switch') || WP?.themeSwitch, ['instant', 'fade', 'circle'], 'instant');

/**
 * Botón fijo abajo (Apariencia → MJ Appearance → Anuncios y avisos).
 * url '' = la reserva de MJ Turnos si el plugin está activo; si no, la tienda
 * (lo resuelve components/layout/StickyCta.jsx). hide: rutas donde no
 * aparece ('/blog/*' = esa ruta y todo lo que cuelga).
 */
export const STICKY_CTA = {
  enabled: !!WP?.stickyCta?.enabled,
  text: typeof WP?.stickyCta?.text === 'string' ? WP.stickyCta.text.trim() : '',
  url: typeof WP?.stickyCta?.url === 'string' ? WP.stickyCta.url.trim() : '',
  desktop: !!WP?.stickyCta?.desktop,
  hide: (Array.isArray(WP?.stickyCta?.hide) ? WP.stickyCta.hide : []).filter((p) => typeof p === 'string' && p.trim() !== ''),
};

/** Cortina de intro al cargar el sitio (una vez por sesión). */
export const INTRO_ENABLED = qs?.has('intro') ? qs.get('intro') === '1' : !!WP?.intro;

/**
 * Logo (Apariencia → MJ Appearance → Logo, o el del Personalizador).
 * url '' = sin imagen: la SPA muestra la inicial del sitio + el nombre.
 */
export const LOGO = {
  url: WP?.logo?.url || '',
  dark: WP?.logo?.dark || '',
  width: Number(WP?.logo?.width) || 0,
  height: Number(WP?.logo?.height) || 0,
  showName: WP?.logo ? WP.logo.showName !== false : true,
  alt: WP?.logo?.alt || '',
};

/* ---------------------------------------------------------------------------
 * 3.5 — Orden de las secciones de la portada y página "Nosotros".
 * ------------------------------------------------------------------------- */

/**
 * Orden por defecto de la portada (espejo de mj_home_default_order() en PHP).
 * 'services' y 'booking' las aporta el plugin MJ Turnos Online; sin plugin,
 * Home.jsx no tiene elemento para esas keys y las saltea.
 */
export const HOME_DEFAULT_ORDER = [
  'banners', 'featured', 'categories', 'latest', 'colegas', 'recommended',
  'brands', 'testimonials', 'instagram', 'services', 'booking', 'about',
];

/**
 * Orden efectivo (Apariencia → MJ Appearance → Secciones de la portada,
 * window.MJ.homeOrder). Las keys que PHP no mande (build más nuevo que el
 * tema, plugin recién activado) van al final en su orden por defecto.
 */
export const HOME_ORDER = Array.isArray(WP?.homeOrder)
  ? [...new Set([...WP.homeOrder.filter((k) => typeof k === 'string' && k), ...HOME_DEFAULT_ORDER])]
  : HOME_DEFAULT_ORDER;

/**
 * Página "Nosotros" (Apariencia → MJ Appearance → Contenido → Nosotros).
 * image: URL de la foto o ''. highlights: [{ text, icon }]. stats: [{ n, label }]
 * (vacío = la SPA muestra las cifras automáticas del catálogo).
 */
export const ABOUT = {
  image: typeof WP?.about?.image === 'string' ? WP.about.image : '',
  highlights: (Array.isArray(WP?.about?.highlights) ? WP.about.highlights : [])
    .filter((h) => h && typeof h.text === 'string' && h.text.trim() !== '')
    .map((h) => ({ text: h.text, icon: typeof h.icon === 'string' && h.icon ? h.icon : null })),
  stats: (Array.isArray(WP?.about?.stats) ? WP.about.stats : [])
    .map((s) => ({ n: String(s?.n ?? '').trim(), label: String(s?.label ?? '').trim() }))
    .filter((s) => s.n !== '' && s.label !== ''),
};

/**
 * Configuración global del Header (Apariencia → MJ Appearance → Cabecera / Header).
 */
export const HEADER = {
  sticky: WP?.header?.sticky || 'sticky',
  headerStyle: WP?.header?.headerStyle || 'glass',
  border: WP?.header?.border || 'subtle',
  logoPosition: WP?.header?.logoPosition || 'left',
  searchPosition: WP?.header?.searchPosition || 'center',
  menuPosition: WP?.header?.menuPosition || 'below',
  showWishlist: WP?.header?.showWishlist !== false,
  showCompare: WP?.header?.showCompare !== false,
  showCart: WP?.header?.showCart !== false,
  showAccount: WP?.header?.showAccount !== false,
  showThemeToggle: WP?.header?.showThemeToggle !== false,
  showDollarRate: WP?.header?.showDollarRate !== false,
  showPaymentSelect: WP?.header?.showPaymentSelect !== false,
  showTurnosCta: !!WP?.header?.showTurnosCta,
  turnosCtaText: WP?.header?.turnosCtaText || '',
  customButtonText: WP?.header?.customButtonText || '',
  customButtonUrl: WP?.header?.customButtonUrl || '',
  customButtonIcon: WP?.header?.customButtonIcon || '',
  customButtonTarget: WP?.header?.customButtonTarget || '_self',
  bgColor: WP?.header?.bgColor || '',
  inkColor: WP?.header?.inkColor || '',
  accentColor: WP?.header?.accentColor || '',
};

/**
 * Configuración global del Footer (Apariencia → MJ Appearance → Pie de página / Footer).
 */
export const FOOTER = {
  showNewsletter: WP?.footer?.showNewsletter !== false,
  columnsCount: Number(WP?.footer?.columnsCount) || 4,
  showLogo: WP?.footer?.showLogo !== false,
  tagline: WP?.footer?.tagline || '',
  showContact: WP?.footer?.showContact !== false,
  showSocials: WP?.footer?.showSocials !== false,
  columns: Array.isArray(WP?.footer?.columns) ? WP.footer.columns : [],
  showLegalBar: WP?.footer?.showLegalBar !== false,
  copyright: WP?.footer?.copyright || '',
  showPayments: WP?.footer?.showPayments !== false,
  border: WP?.footer?.border || 'subtle',
  bgColor: WP?.footer?.bgColor || '',
  inkColor: WP?.footer?.inkColor || '',
  accentColor: WP?.footer?.accentColor || '',
};

/**
 * Esquemas modulares del Constructor de Cabecera y Pie (Header & Footer Builder).
 */
export const HEADER_LAYOUT = WP?.headerLayout || null;
export const FOOTER_LAYOUT = WP?.footerLayout || null;
