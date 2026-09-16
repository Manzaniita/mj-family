/**
 * Modo día / noche.
 *
 * Fuente de verdad: <html data-theme="dark|light">. El orden de prioridad es
 *   1. elección del visitante (localStorage 'mj-theme'),
 *   2. "Tema por defecto" del admin (dark | light | system),
 *   3. si es system, prefers-color-scheme del dispositivo.
 * index.php (y el index.html de dev) corren la misma lógica en un script
 * inline ANTES de cargar CSS, así el primer paint ya sale con el tema
 * correcto y no hay parpadeo. Acá vive lo que pasa después de montar.
 */
import { THEME_DEFAULT, THEME_SWITCH } from './config';
import { REDUCED_MOTION } from './motion';

export const THEME_KEY = 'mj-theme';

const darkQuery = () =>
  typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

/** Tema del sistema operativo. */
export const systemTheme = () => (darkQuery()?.matches ? 'dark' : 'light');

/** Elección guardada por el visitante, o null si nunca tocó el botón. */
export function storedTheme() {
  try {
    const v = localStorage.getItem(THEME_KEY);
    return v === 'light' || v === 'dark' ? v : null;
  } catch {
    return null;
  }
}

/** Tema con el que debería verse la tienda ahora mismo. */
export function resolveTheme() {
  return storedTheme() ?? (THEME_DEFAULT === 'system' ? systemTheme() : THEME_DEFAULT);
}

/** Tema actualmente aplicado en el documento. */
export const currentTheme = () =>
  (typeof document !== 'undefined' && document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');

/**
 * Sincroniza <meta name="theme-color"> (barra del navegador en mobile) con
 * el fondo real del tema, leído de --mj-bg una vez aplicado el CSS.
 */
export function syncThemeColor() {
  if (typeof document === 'undefined') return;
  let meta = document.querySelector('meta[name="theme-color"]');
  if (!meta) {
    meta = document.createElement('meta');
    meta.name = 'theme-color';
    document.head.appendChild(meta);
  }
  requestAnimationFrame(() => {
    const bg = getComputedStyle(document.documentElement).getPropertyValue('--mj-bg').trim();
    if (bg) meta.content = bg;
  });
}

/** Aplica el tema al documento. persist=false → solo refleja (p. ej. cambió el sistema). */
export function applyTheme(theme, { persist = true } = {}) {
  const root = document.documentElement;
  root.dataset.theme = theme;
  root.style.colorScheme = theme;
  if (persist) {
    try {
      localStorage.setItem(THEME_KEY, theme);
    } catch {
      /* modo privado / storage bloqueado: el tema igual se aplica */
    }
  }
  syncThemeColor();
}

/* Evita que dos clics seguidos encimen dos transiciones. */
let switching = false;

/**
 * Cambia de tema con el efecto elegido en Apariencia → MJ Appearance
 * ("Efecto al cambiar de tema", THEME_SWITCH):
 *
 *   instant — en seco. Mientras se aplica, se apagan TODAS las transiciones
 *             CSS por un frame: si no, cada elemento con transition-colors
 *             (cientos en una página de catálogo) arranca su propio fundido y
 *             el cambio se siente pesado y desparejo.
 *   fade    — fundido corto del fondo y el texto base.
 *   circle  — el tema nuevo se revela en círculo desde `origin` ({x, y} en px
 *             de viewport, normalmente el clic del botón) con View Transitions.
 *             Sin soporte o con reduced-motion cae a "fade".
 *
 * `commit` corre de forma SÍNCRONA dentro del cambio (el store lo envuelve en
 * flushSync) para que React pinte el ícono nuevo del botón en el mismo frame.
 * Importante: dentro del callback de startViewTransition el navegador pausa el
 * render, así que ahí no se puede esperar un requestAnimationFrame: ese frame
 * nunca llega y el cambio queda colgado hasta el timeout del navegador (varios
 * segundos con la página congelada). Por eso todo es síncrono.
 */
export function transitionTheme(theme, origin, commit) {
  if (switching || typeof document === 'undefined') return Promise.resolve();
  const root = document.documentElement;
  const apply = () => {
    applyTheme(theme);
    commit?.();
  };

  const effect = REDUCED_MOTION ? 'instant' : THEME_SWITCH;

  if (effect === 'instant') {
    root.classList.add('mj-theme-cut');
    apply();
    // Forzar el recálculo de estilos con las transiciones apagadas; recién
    // después, en el próximo frame, se vuelven a habilitar.
    void root.offsetWidth;
    requestAnimationFrame(() => root.classList.remove('mj-theme-cut'));
    return Promise.resolve();
  }

  if (effect === 'fade' || typeof document.startViewTransition !== 'function') {
    root.classList.add('mj-theme-fade');
    apply();
    setTimeout(() => root.classList.remove('mj-theme-fade'), 400);
    return Promise.resolve();
  }

  const x = origin?.x ?? window.innerWidth / 2;
  const y = origin?.y ?? 0;
  const r = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
  root.style.setProperty('--mj-vt-x', `${x}px`);
  root.style.setProperty('--mj-vt-y', `${y}px`);
  root.style.setProperty('--mj-vt-r', `${r}px`);
  root.classList.add('mj-theme-switching');
  switching = true;

  const transition = document.startViewTransition(apply);
  return transition.finished.catch(() => {}).finally(() => {
    switching = false;
    root.classList.remove('mj-theme-switching');
  });
}

/**
 * Sigue los cambios del sistema mientras el visitante no haya elegido a mano.
 * Devuelve la función para dejar de escuchar.
 */
export function watchSystemTheme(onChange) {
  const mq = darkQuery();
  if (!mq) return () => {};
  const handler = () => {
    if (!storedTheme() && THEME_DEFAULT === 'system') onChange(mq.matches ? 'dark' : 'light');
  };
  mq.addEventListener('change', handler);
  return () => mq.removeEventListener('change', handler);
}
