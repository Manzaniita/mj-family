# GitHub — repositorio privado y verificaciones

Aplican [las reglas de la raíz](../AGENTS.md) y, para scripts,
[las de tools](../tools/AGENTS.md).

- `workflows/verificar.yml`: PHP 7.4/8.3, PHPCS, copias compartidas y dos builds.
  Omite cambios exclusivamente Markdown o de manifiestos de releases.
- `workflows/documentacion.yml`: control liviano de documentos e instrucciones,
  también cuando los únicos cambios son Markdown.
- `pull_request_template.md`: resumen, validación y efecto sobre paquetes.
  No exige cambiar versiones cuando no cambia el contenido distribuido.

## Al editar

- Conservá `permissions: contents: read` mientras no haga falta escritura;
  estos workflows verifican y no publican. No uses secretos de tiendas.
- Si cambia la matriz React, mantenela alineada con `tools/verificar.ps1` y
  `tools/releases.config.json`. Usá `npm ci` con el lockfile del proyecto.
- No tapes fallos con `continue-on-error` ni regeneres baseline en CI.
- Documentá disparadores y límites en el README; no afirmes que Actions pasó
  sin ver una corrida. Un archivo válido no comprueba minutos, permisos o
  configuración de la cuenta de GitHub.
- Los archivos del repo no configuran su visibilidad ni las reglas de rama
  remotas. No marques un check como obligatorio sin considerar los workflows
  que se omiten por filtros de rutas.
