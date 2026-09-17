import { lazy } from 'react';

/*
 * Pantallas que se bajan recién al entrar (checkout, cuenta, turnos, páginas
 * de WordPress…). Ver el comentario de App.jsx sobre qué viaja en la primera
 * carga y qué no.
 *
 * Si un archivo viejo quedó cacheado después de una actualización del tema,
 * el import falla: se recarga la página una sola vez para traer el nuevo.
 */
export function lazyPage(loader, name) {
  return lazy(() =>
    loader()
      .then((m) => ({ default: name ? m[name] : m.default }))
      .catch((err) => {
        const key = 'mj-chunk-reload';
        let already = false;
        try {
          already = sessionStorage.getItem(key) === '1';
          if (!already) sessionStorage.setItem(key, '1');
        } catch {
          /* sin storage: se intenta igual una vez */
        }
        if (!already) {
          window.location.reload();
          return new Promise(() => {}); // la recarga corta acá
        }
        throw err;
      })
  );
}
