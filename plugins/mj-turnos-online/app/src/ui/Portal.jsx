import { createContext, useContext, useLayoutEffect, useState } from 'react';
import { createPortal } from 'react-dom';

/*
 * Portal "con tema": los diálogos y toasts se montan en un <div class="mjto-root
 * mjto-portal"> al final del <body>, así `position: fixed` es relativo a la
 * ventana aunque el widget viva adentro de un contenedor con transform
 * (secciones animadas del tema). Como todo el CSS del plugin está acotado a
 * `.mjto-root`, el contenedor del portal repite la clase y copia data-theme
 * y las variables --mjto-* inline de la raíz que lo abre.
 */
const RootContext = createContext(null);

/** Envuelve cada árbol montado por main.jsx con su elemento raíz. */
export function RootProvider({ el, children }) {
  return <RootContext.Provider value={el || null}>{children}</RootContext.Provider>;
}

/** Elemento .mjto-root donde vive este árbol (null en tests). */
export function useRootEl() {
  return useContext(RootContext);
}

let portalEl = null;

/** Devuelve (creando si hace falta) el contenedor del portal, sincronizado con `source`. */
export function getPortalRoot(source) {
  if (typeof document === 'undefined') return null;
  if (!portalEl || !document.body.contains(portalEl)) {
    portalEl = document.createElement('div');
    portalEl.className = 'mjto-root mjto-portal';
    document.body.appendChild(portalEl);
  }
  if (source) {
    const theme = source.dataset.theme || source.closest('[data-theme]')?.dataset.theme || '';
    if (theme) portalEl.dataset.theme = theme;
    else delete portalEl.dataset.theme;
    const css = source.getAttribute('style') || '';
    if (css) portalEl.setAttribute('style', css);
    const panel = source.closest('.mjto-panel') || source.querySelector('.mjto-panel');
    portalEl.classList.toggle('mjto-panel', !!panel);
  }
  return portalEl;
}

/** Renderiza `children` en el contenedor del portal. */
export function Portal({ children }) {
  const rootEl = useRootEl();
  const [target, setTarget] = useState(null);
  useLayoutEffect(() => {
    setTarget(getPortalRoot(rootEl));
  }, [rootEl]);
  if (!target) return null;
  return createPortal(children, target);
}
