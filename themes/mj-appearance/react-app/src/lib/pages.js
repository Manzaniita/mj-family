/**
 * Páginas de WordPress dentro de la tienda (window.MJ.pages, inc/paginas.php).
 *
 * PHP manda qué páginas publicadas se muestran (`routes`, sin barra inicial:
 * "ayuda", "ayuda/envios") y si la portada la arma una página elegida en
 * Ajustes → Lectura (`home`). La SPA pide cada una a mj/v1/page y la dibuja
 * con PageView (pages/Page.jsx), que hidrata los bloques MJ con los
 * componentes de siempre.
 *
 * Fuera de WordPress (`npm run dev`) no hay páginas: todo queda apagado y la
 * tienda usa sus pantallas propias.
 */
import { IS_WP, WP } from './config';
import { getPage } from './wc';

const raw = WP?.pages && typeof WP.pages === 'object' ? WP.pages : {};
const enabled = IS_WP && !!raw.enabled;

export const PAGES = {
  enabled,
  /** La portada es una página de WordPress (Ajustes → Lectura → Una página estática). */
  home: enabled && !!raw.home,
  /** Una página con el mismo slug reemplaza a la pantalla del tema. */
  override: enabled && raw.override !== false,
  /** Direcciones servidas (PHP ya excluyó las reservadas y las que no reemplazan). */
  routes: enabled && Array.isArray(raw.routes)
    ? [...new Set(raw.routes.filter((p) => typeof p === 'string' && p !== '').map((p) => p.replace(/^\/+|\/+$/g, '')))]
    : [],
};

/** Pantallas del tema que una página puede reemplazar (espejo de mj_pages_overridable()). */
export const OVERRIDABLE = ['contacto', 'nosotros', 'faq', 'privacidad', 'terminos'];

/** ¿Esta dirección la responde una página de WordPress? */
export const pageRoute = (path) => PAGES.routes.includes(path);

/** Páginas que no reemplazan a ninguna pantalla: van como rutas nuevas. */
export const extraPageRoutes = () => PAGES.routes.filter((p) => !OVERRIDABLE.includes(p));

/** ¿La URL actual es una vista previa de Gutenberg de una página? → su id, o 0. */
export function previewPageId(search) {
  const params = new URLSearchParams(search || '');
  if (params.get('preview') !== 'true') return 0;
  return Number(params.get('page_id') || params.get('p') || params.get('preview_id')) || 0;
}

/*
 * Una página se pide una sola vez por sesión (la tienda navega sin recargar y
 * el visitante vuelve a Contacto varias veces). Las vistas previas no se
 * guardan: tienen que mostrar siempre lo último que se escribió.
 */
const cache = new Map();

export function fetchPage({ path = '', id = 0, preview = false } = {}) {
  if (!IS_WP) {
    return Promise.reject(Object.assign(new Error('Sin WordPress no hay páginas.'), { status: 404 }));
  }
  const key = `${id}|${path}`;
  if (!preview && cache.has(key)) return cache.get(key);
  const request = getPage({ path, id, preview }).catch((err) => {
    cache.delete(key);
    throw err;
  });
  if (!preview) cache.set(key, request);
  return request;
}
