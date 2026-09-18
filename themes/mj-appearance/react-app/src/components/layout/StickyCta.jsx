import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { STICKY_CTA, SESSION } from '@/lib/config';
import { TURNOS, bookingPath } from '@/lib/turnos';
import { cn } from '@/lib/format';

/* Ruta sin barra final ('/turnos/' → '/turnos'; '/' queda '/'). */
const clean = (path) => {
  const p = String(path || '').split(/[?#]/)[0] || '/';
  return p.length > 1 ? p.replace(/\/+$/, '') || '/' : p;
};

/* ¿La ruta cae bajo `base`? ('/cuenta' cubre '/cuenta' y '/cuenta/pedidos'.) */
const under = (path, base) => path === base || (base !== '/' && path.startsWith(`${base}/`));

/**
 * Destino del botón: el del ajuste o, vacío, la reserva de MJ Turnos (si el
 * plugin está activo) o la tienda. Una URL de este mismo sitio se usa como
 * ruta, así navega sin recargar.
 */
function target() {
  const raw = STICKY_CTA.url || (TURNOS ? bookingPath() : '/tienda');
  if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) {
    try {
      const u = new URL(raw);
      if (typeof window !== 'undefined' && u.origin === window.location.origin) {
        return { internal: true, href: `${u.pathname}${u.search}${u.hash}`, path: clean(u.pathname) };
      }
    } catch {
      /* URL rara: se usa tal cual como enlace externo */
    }
    return { internal: false, href: raw, path: '' };
  }
  const href = raw.startsWith('/') ? raw : `/${raw}`;
  return { internal: true, href, path: clean(href) };
}

/* Rutas donde el botón nunca aparece: carrito, checkout, la cuenta y la ficha de producto (tiene su barra de compra). */
const ALWAYS_HIDDEN = ['/carrito', '/checkout', '/cuenta', '/producto'];

function hiddenOn(pathname, dest) {
  const path = clean(pathname);
  if (dest.path && under(path, dest.path)) return true;
  const account = clean(SESSION.accountPath);
  if ([...ALWAYS_HIDDEN, account].some((base) => under(path, base))) return true;
  return STICKY_CTA.hide.some((rule) => {
    if (rule.endsWith('/*')) return under(path, clean(rule.slice(0, -2)) || '/');
    return path === clean(rule);
  });
}

/**
 * Botón fijo abajo (Apariencia → MJ Appearance → Anuncios y avisos → Botón
 * fijo abajo): un llamado a la acción grande, a todo el ancho, que queda a
 * mano mientras se recorre la tienda. En el celular va arriba de la barra
 * inferior; en la computadora solo si el ajuste lo pide. Deja al final de la
 * página el espacio que ocupa, para no tapar el pie.
 */
export function StickyCta() {
  const { pathname } = useLocation();
  const dest = target();
  const text = STICKY_CTA.text;
  const visible = STICKY_CTA.enabled && !!text && !hiddenOn(pathname, dest);
  const scope = STICKY_CTA.desktop ? 'all' : 'mobile';

  // El botón flotante de WhatsApp se corre para arriba mientras el botón está visible (globals.css).
  useEffect(() => {
    if (!visible || typeof document === 'undefined') return undefined;
    const root = document.documentElement;
    root.dataset.stickyCta = scope;
    return () => {
      delete root.dataset.stickyCta;
    };
  }, [visible, scope]);

  if (!visible) return null;

  const onlyMobile = !STICKY_CTA.desktop && 'lg:hidden';
  const linkProps = dest.internal ? { to: dest.href } : { href: dest.href, target: '_blank', rel: 'noreferrer' };

  return (
    <>
      <div className={cn('mj-sticky-cta__spacer', onlyMobile)} aria-hidden="true" />
      <div className={cn('mj-sticky-cta', onlyMobile)}>
        <div className="mj-sticky-cta__inner">
          <Button size="lg" className="mj-sticky-cta__button w-full shadow-soft" {...linkProps}>
            {text}
          </Button>
        </div>
      </div>
    </>
  );
}
