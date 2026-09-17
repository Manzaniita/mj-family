/**
 * Título y descripción de cada pantalla, del lado del navegador.
 *
 * El servidor (inc/seo.php) ya manda el <title>, la descripción y el
 * canonical correctos en la primera carga: eso es lo que ven Google y
 * WhatsApp. Pero la tienda navega sin recargar, así que al cambiar de
 * pantalla hay que actualizar la pestaña acá, con el mismo formato
 * "Página — Sitio" para que no haya dos títulos distintos para la misma URL.
 */
import { useEffect } from 'react';
import { SITE } from './config';

export const SEP = '—';

/** Título completo de la pestaña. Sin título → portada: "Sitio — Descripción". */
export function fullTitle(title) {
  const site = SITE.name;
  if (!title) return SITE.tagline ? `${site} ${SEP} ${SITE.tagline}` : site;
  return `${title} ${SEP} ${site}`;
}

/* Escribe (o crea) una etiqueta del <head>. */
function setHead(selector, attr, value, make) {
  if (typeof document === 'undefined' || !value) return;
  let el = document.head.querySelector(selector);
  if (!el) {
    el = make();
    document.head.appendChild(el);
  }
  el.setAttribute(attr, value);
}

const meta = (name, value, prop = false) =>
  setHead(prop ? `meta[property="${name}"]` : `meta[name="${name}"]`, 'content', value, () => {
    const m = document.createElement('meta');
    if (prop) m.setAttribute('property', name);
    else m.name = name;
    return m;
  });

/**
 * Aplica título, descripción y canonical de la pantalla actual.
 * `title` vacío = portada. Se vuelve a aplicar cuando cambian.
 */
export function usePageMeta(title, description) {
  useEffect(() => {
    if (typeof document === 'undefined') return;
    const full = fullTitle(title);
    document.title = full;
    meta('og:title', full, true);
    if (description) {
      meta('description', description);
      meta('og:description', description, true);
    }
    const url = `${window.location.origin}${window.location.pathname}`;
    setHead('link[rel="canonical"]', 'href', url, () => {
      const l = document.createElement('link');
      l.rel = 'canonical';
      return l;
    });
    meta('og:url', url, true);
  }, [title, description]);
}
