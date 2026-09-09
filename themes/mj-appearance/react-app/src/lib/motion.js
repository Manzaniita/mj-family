/**
 * Nivel de animación efectivo de la tienda.
 *
 * MOTION (Apariencia → MJ Appearance, o ?motion=) define la ambición; el
 * sistema operativo puede pedir menos (prefers-reduced-motion) y siempre
 * gana. Los componentes de motion consultan estas banderas y degradan a
 * versiones estáticas: nada depende de que la animación exista.
 */
import { MOTION } from './config';

const mq = (query) => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query) : null);

/** El visitante pidió menos movimiento a nivel sistema. */
export const REDUCED_MOTION = !!mq('(prefers-reduced-motion: reduce)')?.matches;

/** Puntero fino (mouse/trackpad): habilita efectos de hover y magnéticos. */
export const FINE_POINTER = !!mq('(hover: hover) and (pointer: fine)')?.matches;

/** Transiciones entre páginas y reveals con desplazamiento. */
export const TRANSITIONS = MOTION !== 'subtle' && !REDUCED_MOTION;

/** Parallax, reveals por máscara, botones magnéticos, contadores. */
export const CINEMATIC = MOTION === 'cinematic' && !REDUCED_MOTION;
