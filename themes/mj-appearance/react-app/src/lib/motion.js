/**
 * Nivel de animación efectivo de la tienda.
 *
 * MOTION (Apariencia → MJ Appearance, o ?motion=) define la ambición; el
 * sistema operativo puede pedir menos (prefers-reduced-motion) y siempre
 * gana. Los componentes de motion consultan estas banderas y degradan a
 * versiones estáticas: nada depende de que la animación exista.
 */
import { MotionGlobalConfig } from 'framer-motion';
import { MOTION } from './config';

const mq = (query) => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query) : null);

/** El visitante pidió menos movimiento a nivel sistema. */
export const REDUCED_MOTION = !!mq('(prefers-reduced-motion: reduce)')?.matches;

/** Puntero fino (mouse/trackpad): habilita efectos de hover y magnéticos. */
export const FINE_POINTER = !!mq('(hover: hover) and (pointer: fine)')?.matches;

/**
 * Nivel "Ninguna": la tienda no anima nada. Reveals, stagger y transiciones
 * de página muestran todo quieto al instante, la cortina de entrada no
 * aparece, los botones no se mueven al pasar el mouse y cualquier animación
 * de framer-motion (modales, cajones, toasts) salta directo a su estado final.
 * El CSS hace lo mismo con transiciones y hovers (html[data-motion="none"],
 * globals.css). Es el mismo trato que reduced-motion, pero elegido por la tienda.
 */
export const STILL = MOTION === 'none';

if (typeof document !== 'undefined') {
  if (STILL) {
    MotionGlobalConfig.skipAnimations = true;
    document.documentElement.dataset.motion = 'none';
  } else if (document.documentElement.dataset.motion === 'none') {
    // ?motion=medium sobre una tienda "Ninguna": la vista previa manda sobre lo que pintó PHP.
    delete document.documentElement.dataset.motion;
  }
}

/** Transiciones entre páginas y reveals con desplazamiento. */
export const TRANSITIONS = !STILL && MOTION !== 'subtle' && !REDUCED_MOTION;

/** Parallax, reveals por máscara, botones magnéticos, contadores. */
export const CINEMATIC = MOTION === 'cinematic' && !REDUCED_MOTION;
