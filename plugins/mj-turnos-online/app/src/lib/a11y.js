/**
 * Hooks de accesibilidad y presencia para diálogos (Modal, Drawer, menús).
 * Copia adaptada de react-app/src/lib/a11y.js del tema, sin framer-motion.
 */
import { useEffect, useRef, useState } from 'react';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Comportamiento de diálogo accesible:
 * - al abrir, el foco entra al primer control (o al contenedor),
 * - Tab / Shift+Tab quedan atrapados adentro,
 * - Escape cierra,
 * - al cerrar, el foco vuelve a donde estaba.
 * `onClose` se lee por ref: no hace falta memoizarlo en el caller.
 */
export function useDialog(ref, open, onClose, { autoFocus = true } = {}) {
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) return undefined;
    const node = ref.current;
    const previous = document.activeElement;

    const focusables = () =>
      node ? [...node.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null) : [];

    const timer = setTimeout(() => {
      if (!autoFocus) {
        node?.focus?.({ preventScroll: true });
        return;
      }
      const first = focusables().find((el) => !el.dataset.noAutofocus) ?? node;
      first?.focus?.({ preventScroll: true });
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
  }, [open, ref, autoFocus]);
}

/*
 * Contador de bloqueos de scroll a nivel módulo: un diálogo abierto desde otro
 * (la hoja de WhatsApp que abre Cancelar, la doble confirmación de eliminar)
 * convive unos milisegundos con el anterior. Sin contar, el segundo guardaría
 * 'hidden' como valor previo y al cerrarse dejaría el body sin scroll.
 */
let scrollLocks = 0;
let scrollSaved = '';

/** Bloquea el scroll del body mientras `open` (drawers, modales, hojas). */
export function useScrollLock(open) {
  useEffect(() => {
    if (!open) return undefined;
    if (scrollLocks === 0) {
      scrollSaved = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    }
    scrollLocks += 1;
    return () => {
      scrollLocks = Math.max(0, scrollLocks - 1);
      if (scrollLocks === 0) document.body.style.overflow = scrollSaved;
    };
  }, [open]);
}

/**
 * Presencia animada sin librerías: mantiene montado el nodo `ms` después de
 * cerrar para que corra la animación de salida.
 * @returns {{ mounted: boolean, state: 'open'|'closed' }}
 */
export function usePresence(open, ms = 220) {
  const [mounted, setMounted] = useState(!!open);
  const [state, setState] = useState(open ? 'open' : 'closed');

  useEffect(() => {
    let raf = 0;
    let timer = 0;
    if (open) {
      setMounted(true);
      raf = requestAnimationFrame(() => setState('open'));
    } else {
      setState('closed');
      timer = setTimeout(() => setMounted(false), ms);
    }
    return () => {
      cancelAnimationFrame(raf);
      clearTimeout(timer);
    };
  }, [open, ms]);

  return { mounted, state };
}

/** Cierra al hacer click/tap fuera del nodo (menús, popovers). */
export function useClickOutside(ref, active, onOutside) {
  const cb = useRef(onOutside);
  cb.current = onOutside;
  useEffect(() => {
    if (!active) return undefined;
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) cb.current?.(e);
    };
    document.addEventListener('mousedown', handler);
    document.addEventListener('touchstart', handler, { passive: true });
    return () => {
      document.removeEventListener('mousedown', handler);
      document.removeEventListener('touchstart', handler);
    };
  }, [ref, active]);
}

/** true cuando la media query matchea (por ejemplo '(min-width: 48rem)'). */
export function useMediaQuery(query) {
  const get = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query).matches : false);
  const [matches, setMatches] = useState(get);
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return undefined;
    const mq = window.matchMedia(query);
    const onChange = () => setMatches(mq.matches);
    onChange();
    mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange);
    return () => {
      mq.removeEventListener ? mq.removeEventListener('change', onChange) : mq.removeListener(onChange);
    };
  }, [query]);
  return matches;
}

/** Ids únicos estables para label/aria. */
let counter = 0;
export function useUid(prefix = 'mjto') {
  const ref = useRef('');
  if (!ref.current) {
    counter += 1;
    ref.current = `${prefix}-${counter}`;
  }
  return ref.current;
}
