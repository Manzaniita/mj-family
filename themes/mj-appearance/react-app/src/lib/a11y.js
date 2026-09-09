import { useEffect, useRef } from 'react';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Comportamiento de diálogo accesible para drawers y modales:
 * - al abrir, el foco entra al primer control (o al contenedor),
 * - Tab / Shift+Tab quedan atrapados adentro,
 * - Escape cierra,
 * - al cerrar, el foco vuelve a donde estaba.
 * `onClose` se lee por ref: no hace falta memoizarlo en el caller.
 */
export function useDialog(ref, open, onClose) {
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) return undefined;
    const node = ref.current;
    const previous = document.activeElement;

    const focusables = () =>
      node ? [...node.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null) : [];

    const timer = setTimeout(() => {
      (focusables()[0] ?? node)?.focus?.({ preventScroll: true });
    }, 40);

    const onKey = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onCloseRef.current?.();
        return;
      }
      if (e.key !== 'Tab' || !node) return;
      const els = focusables();
      if (!els.length) {
        e.preventDefault();
        return;
      }
      const first = els[0];
      const last = els[els.length - 1];
      const outside = !node.contains(document.activeElement);
      if (e.shiftKey && (document.activeElement === first || outside)) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || outside)) {
        e.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKey);
    return () => {
      clearTimeout(timer);
      document.removeEventListener('keydown', onKey);
      if (previous && typeof previous.focus === 'function' && document.contains(previous)) {
        previous.focus({ preventScroll: true });
      }
    };
  }, [open, ref]);
}

/** Bloquea el scroll del body mientras `open` (drawers, modales, hojas). */
export function useScrollLock(open) {
  useEffect(() => {
    if (!open) return undefined;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
    };
  }, [open]);
}
