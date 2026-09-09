/**
 * Configuración inyectada por WordPress (window.MJTO).
 *
 * Tres modos (inc/panel.php, inc/frontend.php, inc/options.php):
 *  - 'panel'   → { mode, restUrl, nonce, basePath, homeUrl, siteName, business, currency,
 *                  locale, theme:{mode, accent, bg, ink, fontDisplay, fontSans, logo},
 *                  user:{id, name}, urls:{logout, publicBooking, settings, services, exportBase},
 *                  bootstrap: mjto_panel_bootstrap() }
 *  - 'booking' → { mode, restUrl, nonce, config: mjto_public_config(), currency, locale, preselect }
 *  - 'builder' → { mode, kind, defaults, ... }
 *
 * Todo con fallback defensivo: sin WordPress (npm run dev) la app arranca igual.
 */
export const BOOT = typeof window !== 'undefined' && window.MJTO ? window.MJTO : {};

/** 'panel' | 'booking' | 'builder' */
export const MODE = ['panel', 'booking', 'builder'].includes(BOOT.mode) ? BOOT.mode : 'panel';

/** Base de la REST del plugin, siempre con barra final: .../wp-json/mjto/v1/ */
export const REST_URL = (() => {
  const url = BOOT.restUrl || (BOOT.config && BOOT.config.restUrl) || '/wp-json/mjto/v1/';
  return url.endsWith('/') ? url : `${url}/`;
})();

/** Nonce de cookie-auth de WP (header X-WP-Nonce). Vacío en público sin sesión. */
export const NONCE = BOOT.nonce || '';

export const LOCALE = BOOT.locale || 'es-AR';

export const SITE_NAME = BOOT.siteName || '';

export const HOME_URL = BOOT.homeUrl || '/';

/** Datos del negocio (en booking vienen adentro de config). */
export const BUSINESS = {
  name: '',
  address: '',
  whatsapp: '',
  instagram: '',
  ...((BOOT.business || (BOOT.config && BOOT.config.business)) || {}),
};

/** Moneda: defaults ARS mergeados con lo que mande PHP (mjto_currency()). */
export const CUR = {
  code: 'ARS',
  symbol: '$',
  decimals: 0,
  thousandSep: '.',
  decimalSep: ',',
  position: 'left', // 'left' | 'right' | 'left_space' | 'right_space'
  ...((BOOT.currency || (BOOT.config && BOOT.config.currency)) || {}),
};

/** Tema visual del panel (colores del negocio). */
export const THEME = {
  mode: 'light', // 'light' | 'dark' | 'system'
  accent: '',
  bg: '',
  ink: '',
  fontDisplay: '',
  fontSans: '',
  logo: '',
  ...(BOOT.theme || {}),
};

export const USER = { id: 0, name: '', ...(BOOT.user || {}) };

/** Enlaces del panel: los de window.MJTO.urls y, si vienen, los del bootstrap. */
export const URLS = {
  site: HOME_URL,
  logout: '',
  publicBooking: '',
  settings: '',
  services: '',
  exportBase: '',
  ...(BOOT.urls || {}),
  ...((BOOT.bootstrap && BOOT.bootstrap.urls) || {}),
};

/** Configuración pública (modo booking): mjto_public_config(). */
export const PUBLIC_CONFIG = BOOT.config || null;

/** Servicio preseleccionado por el shortcode ([mj_turnos servicio="…"]) o ?servicio=. */
export const PRESELECT = (() => {
  if (BOOT.preselect) return String(BOOT.preselect);
  if (typeof window === 'undefined') return '';
  try {
    return new URLSearchParams(window.location.search).get('servicio') || '';
  } catch (e) {
    return '';
  }
})();

/**
 * basePath del router del panel: '/turnos' (o '/subcarpeta/turnos').
 * Sin barra final; siempre con barra inicial; '' si es la raíz.
 */
export const BASE_PATH = (() => {
  let p = String(BOOT.basePath || '/').trim();
  if (!p.startsWith('/')) p = `/${p}`;
  p = p.replace(/\/+$/, '');
  return p; // '' cuando basePath era '/'
})();

/* ---------------------------------------------------------------------------
 * Tema: aplica los tokens --mjto-* y data-theme sobre un elemento.
 * El CSS deriva toda la paleta de tres entradas (accent, bg, ink) y dos
 * familias tipográficas; lo demás (superficies, líneas, estados) es color-mix.
 * ------------------------------------------------------------------------- */

/** 'light' | 'dark' a partir de un modo ('system' consulta el SO). */
export function resolveThemeMode(mode) {
  if (mode === 'dark' || mode === 'light') return mode;
  if (mode === 'system' && typeof window !== 'undefined' && window.matchMedia) {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  return 'light';
}

/** Luminancia relativa (0..1) de un hex #rgb/#rrggbb; null si no es hex. */
export function hexLuminance(hex) {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return null;
  let h = m[1];
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const rgb = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
  const lin = rgb.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
}

/** Color de texto legible sobre un acento: blanco salvo acentos muy claros. */
export function onAccentColor(accentHex, darkInk = '#1c0f16') {
  const lum = hexLuminance(accentHex);
  if (lum === null) return '#ffffff';
  return lum > 0.5 ? darkInk : '#ffffff';
}

const setVar = (el, name, value) => {
  if (value === undefined || value === null || String(value).trim() === '') return;
  el.style.setProperty(name, String(value));
};

/**
 * Aplica el tema del negocio sobre `el` (por defecto <html>): variables
 * --mjto-accent/--mjto-bg/--mjto-ink/--mjto-font-*, --mjto-on-accent,
 * data-theme y color-scheme. Los valores vacíos se saltean (queda el CSS).
 */
export function applyTheme(el = document.documentElement, theme = THEME, { setMode = true } = {}) {
  if (!el) return 'light';
  const t = { ...THEME, ...(theme || {}) };
  setVar(el, '--mjto-accent', t.accent);
  setVar(el, '--mjto-bg', t.bg);
  setVar(el, '--mjto-ink', t.ink);
  if (t.accent) setVar(el, '--mjto-on-accent', onAccentColor(t.accent, t.ink || undefined));
  if (t.fontDisplay) setVar(el, '--mjto-font-display', `'${t.fontDisplay}', ui-sans-serif, system-ui, sans-serif`);
  if (t.fontSans) setVar(el, '--mjto-font-sans', `'${t.fontSans}', ui-sans-serif, system-ui, sans-serif`);
  const mode = resolveThemeMode(t.mode);
  if (setMode) {
    el.dataset.theme = mode;
    el.style.colorScheme = mode;
  }
  return mode;
}

/**
 * Modo booking/builder: el widget vive adentro de otra página. Si el anfitrión
 * es MJ Appearance, copia sus fuentes (--font-display/--font-sans de <html>)
 * y su data-theme; los colores ya caen solos por CSS (var(--mj-accent, …)).
 * En cualquier otro tema quedan los defaults del plugin.
 */
export function adoptHostTheme(el, { forceMode = '' } = {}) {
  if (!el || typeof window === 'undefined') return;
  const html = document.documentElement;
  try {
    const cs = getComputedStyle(html);
    const display = cs.getPropertyValue('--font-display').trim();
    const sans = cs.getPropertyValue('--font-sans').trim();
    if (display) setVar(el, '--mjto-font-display', display);
    if (sans) setVar(el, '--mjto-font-sans', sans);
  } catch (e) {
    /* sin estilos computados (SSR/tests) */
  }
  const mode = forceMode || html.dataset.theme || '';
  if (mode === 'dark' || mode === 'light') el.dataset.theme = mode;
}
