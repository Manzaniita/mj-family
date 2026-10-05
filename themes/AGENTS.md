# Temas — motor común y personalización

Aplican [las reglas de la raíz](../AGENTS.md). El motor está en
[mj-appearance](mj-appearance/AGENTS.md). Los hijos son `mj-atelier`,
`mj-glossyhair`, `mj-yamana`, `mj-jazbeauty` y `mj-proskin`.

## Elegir el lugar del cambio

- Comportamiento común de catálogo, checkout, sesión, bloques o configuración:
  tema padre. Leé su guía antes de editar PHP o React.
- Paleta, tipografías, copy, presets o contenido inicial de una tienda: tema
  hijo, sin copiar la implementación del padre.
- Los hijos usan `mj_skin`, `mj_skins` y `mj_skin_requires`. Revisá `functions.php`,
  `style.css` y el CSS del hijo afectado. Jaz suma archivos en `inc/` para
  textos y contenido inicial.
- Los defaults son propuestas: los ajustes guardados por el usuario deben
  prevalecer. No fuerces datos de una tienda desde el tema padre.

## Contratos y entrega

- Conservá `Template: mj-appearance` y el nombre de carpeta de cada hijo.
- Al cambiar un hijo, actualizá `Version:` en `style.css` y su constante
  `*_VERSION` en `functions.php`. `*_REQUIRES` expresa la versión mínima del
  padre: solo cambia si la funcionalidad usada requiere un padre más nuevo.
- Las referencias viejas en comentarios no sustituyen el código actual de
  `inc/skins.php`. Comprobá los filtros antes de copiar un ejemplo.
- Ajustes: [temas hijo](../CONFIGURACION.md#temas-hijo). Composición y alta:
  [TIENDAS.md](../TIENDAS.md). Release: [PUBLICAR §8](../PUBLICAR.md#cambio-existente).
- Un cambio solo de PHP/CSS del hijo usa la verificación rápida. Si cambia el
  React del padre, ejecutá la completa y versioná su `dist/` regenerado.
  Revisá visualmente las variantes afectadas cuando haya un entorno disponible;
  no des por probadas las otras tiendas por compartir tema padre.
