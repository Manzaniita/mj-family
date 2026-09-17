/**
 * Aviso de errores del navegador al servidor.
 *
 * Un error de JavaScript en el celular de un cliente no se ve desde ningún
 * lado. Esto lo manda a wp-json/mj/v1/client-error, que lo escribe en el
 * registro de WooCommerce (Estado → Registros, archivo mj-appearance). Se
 * limita a unos pocos avisos por carga para que un error en bucle no
 * inunde nada. Fuera de WordPress solo queda en la consola.
 */
import { WP, IS_WP } from './config';

const MAX_REPORTS = 3;
let sent = 0;
const seen = new Set();

export function reportClientError(error, extra = {}) {
  if (!IS_WP || sent >= MAX_REPORTS) return;
  const message = String(error?.message || error || 'Error desconocido').slice(0, 500);
  const key = `${message}|${extra.scope || ''}`;
  if (seen.has(key)) return;
  seen.add(key);
  sent += 1;
  const payload = {
    message,
    stack: String(error?.stack || '').slice(0, 4000),
    componentStack: String(extra.componentStack || '').slice(0, 2000),
    scope: extra.scope || 'page',
    url: window.location.href.slice(0, 500),
    userAgent: navigator.userAgent.slice(0, 300),
  };
  try {
    fetch(`${WP.restUrl}mj/v1/client-error`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      keepalive: true,
    }).catch(() => {});
  } catch {
    /* sin red: no hay nada que hacer */
  }
}

/** Errores sueltos (fuera de React) y promesas sin catch: también se avisan. */
export function watchGlobalErrors() {
  if (typeof window === 'undefined') return;
  window.addEventListener('error', (e) => reportClientError(e.error || e.message, { scope: 'window' }));
  window.addEventListener('unhandledrejection', (e) => reportClientError(e.reason, { scope: 'promise' }));
}
