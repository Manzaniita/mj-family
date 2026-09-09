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

/* ---------------------------------------------------------------------------
 * Presentación: tema por defecto, skin activa, nivel de animación e intro.
 *
 * En WordPress vienen de PHP (Apariencia → MJ Appearance → Diseño y marca; la
 * skin la declara el tema hijo). Para previsualizar, cualquiera de los cuatro
 * se puede forzar por query string — también sirve en standalone:
 *   ?theme=light&skin=atelier&motion=cinematic&intro=1
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

/** 'subtle' | 'medium' | 'cinematic' — cuánta animación lleva la tienda. */
export const MOTION = pick(qs?.get('motion') || WP?.motion, ['subtle', 'medium', 'cinematic'], 'medium');

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
