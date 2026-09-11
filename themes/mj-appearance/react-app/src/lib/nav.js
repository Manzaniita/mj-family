/**
 * Barra de navegación (window.MJ.nav).
 *
 * Todo lo que se ve en el menú lo decide el admin en Apariencia → MJ Appearance
 * → Barra de navegación: qué ítems hay, en qué orden, cómo se llaman, a dónde
 * llevan, quién los ve y si van en el menú o como botón del header. PHP arma esa
 * lista (inc/nav.php) y la manda ya filtrada por audiencia: lo que es solo para
 * quien entra al ERP no llega siquiera al navegador del cliente.
 *
 * Fuera de WordPress (`npm run dev`) no hay nada de esto: NAV_ITEMS queda en
 * null y el menú se deriva del catálogo demo, como antes.
 */
import { WP } from './config';

/** ¿El destino sale de la tienda? Esos no pueden ir por el router de la SPA. */
export const isExternalHref = (href) =>
  /^(?:https?:)?\/\//i.test(href) || /^(?:mailto|tel):/i.test(href);

function normalize(item) {
  const label = String(item?.label ?? '').trim();
  const type = item?.type === 'category' ? 'category' : 'link';
  const slug = String(item?.slug ?? '').trim();
  const href = String(item?.href ?? '').trim() || (slug ? `/categoria/${slug}` : '');
  if (!label || (!href && !slug)) return null;

  return {
    key: String(item?.key ?? label),
    type,
    label,
    href,
    slug,
    highlight: !!item?.highlight,
    target: item?.target === '_blank' ? '_blank' : '_self',
    icon: String(item?.icon ?? '').trim(),
    placement: ['menu', 'header', 'both'].includes(item?.placement) ? item.placement : 'menu',
    external: isExternalHref(href),
  };
}

/** Lista completa configurada, o null si la tienda corre fuera de WordPress. */
export const NAV_ITEMS = Array.isArray(WP?.nav?.items)
  ? WP.nav.items.map(normalize).filter(Boolean)
  : null;

/** Ítems del menú principal (barra y menú del celular). */
export const NAV_MENU = NAV_ITEMS ? NAV_ITEMS.filter((i) => i.placement !== 'header') : null;

/**
 * Accesos rápidos del header: botones al lado del carrito.
 *
 * Acá aparece el acceso al ERP para quien puede entrar — el resto de los
 * visitantes no recibe el ítem, así que no hay nada que ocultar en el frontend.
 */
export const NAV_HEADER = NAV_ITEMS ? NAV_ITEMS.filter((i) => i.placement !== 'menu') : [];
