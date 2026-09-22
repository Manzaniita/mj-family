# MJ Family — instrucciones para Codex

Repositorio privado de plugins y temas WordPress/WooCommerce. Escribí código
nuevo, comentarios, documentación y commits en castellano rioplatense;
respetá nombres públicos y convenciones existentes. No traduzcas APIs.

## Antes de trabajar

1. Leé el pedido y `git status --short`. Conservá cambios ajenos; no restaures
   ni agregues al commit archivos que no correspondan a la tarea.
2. Elegí el área en la tabla. Antes de editar, leé los `AGENTS.md` del camino
   hasta esa carpeta, aunque la sesión haya arrancado en la raíz. Si el cambio
   cruza áreas, consultá las instrucciones de ambas.
3. Buscá primero con `rg --files <carpeta>` y `rg -n 'patrón' <carpeta>`.
   Leé funciones o secciones relevantes; ampliá a otros paquetes al encontrar
   una dependencia concreta. No leas todos los documentos al iniciar.

## Dónde empezar según la tarea

| Tarea | Entrada e instrucciones |
| --- | --- |
| Plugin, pagos, envíos, dólar, imágenes o actualizaciones | [plugins/AGENTS.md](plugins/AGENTS.md): mapa de los diez plugins. |
| ERP: ventas, caja, stock, clientes o consignaciones | [guía del ERP](plugins/mj-erp/AGENTS.md). |
| Reservas, agenda, señas o clientas | [guía de Turnos](plugins/mj-turnos-online/AGENTS.md). |
| Tema, diseño por tienda, catálogo, carrito o checkout | [themes/AGENTS.md](themes/AGENTS.md) y [MJ Appearance](themes/mj-appearance/AGENTS.md). |
| Menú MJ o registro compartido | [shared/AGENTS.md](shared/AGENTS.md). |
| Verificación, versiones, ZIP o publicación | [tools/AGENTS.md](tools/AGENTS.md). |
| GitHub Actions o plantilla de PR | [.github/AGENTS.md](.github/AGENTS.md). |
| Ajustes, tiendas, pruebas o pendientes | Índices de [CONFIGURACION](CONFIGURACION.md), [TIENDAS](TIENDAS.md), [PRUEBAS](PRUEBAS-EN-LA-TIENDA.md) o [AUDITORIA](AUDITORIA.md); solo la sección afectada. |

En búsquedas de código excluí `vendor/`, `node_modules/`, `dist/`, `releases/`,
archivos lock y `**/compartido/**`, salvo que sean el objeto de la tarea.
No leas bundles minificados para entender el fuente. No generes inventarios
completos, resúmenes repetidos ni archivos de entrega por cada conversación.

## Reglas que siempre aplican

- PHP compatible con **7.4**. Cada plugin funciona sin MJ Core; los cruces
  opcionales se protegen con `class_exists()` o `function_exists()`.
- Datos de negocio en ajustes, constantes de `wp-config.php` o filtros;
  documentalos en [CONFIGURACION.md](CONFIGURACION.md). Sin credenciales en Git.
- No renombres carpetas de paquetes ni archivos principales. No cambies
  contratos REST, opciones persistidas o esquemas sin contemplar compatibilidad.
- `shared/` es la fuente original: nunca edites `compartido/`, esté en
  `includes/`, `inc/` o directamente en el plugin. Seguí su guía para repartir.
- `dist/` se versiona y se genera desde el fuente; no se edita a mano.
- Corregí la causa y limitá el diff a la tarea. No reformatees paquetes enteros
  ni actualices dependencias o la baseline de PHPCS para esconder errores.

## Cerrar un cambio

- Código distribuido: seguí [PUBLICAR §8](PUBLICAR.md#cambio-existente).
  Cabecera y constante de versión deben coincidir; también `Stable tag` si
  existe. Documentación de desarrollo excluida del ZIP no exige subir versiones.
- Paquete nuevo: [PUBLICAR §7](PUBLICAR.md#paquete-nuevo), incluida la primera
  instalación manual. Preparar el paquete no autoriza publicarlo.
- Actualizá solo los documentos afectados según [la matriz](README.md#documentacion).
  Retirá pendientes resueltos de `AUDITORIA.md`; no marques pruebas reales
  como hechas basándote en una lectura o un build.
- Ejecutá `.\tools\verificar.ps1 -Rapido` si no cambió React, o la verificación
  completa si cambió. Revisá `git diff --check` y el diff final. Si algo falla
  o no se puede ejecutar, informalo: no declares «TODO EN ORDEN» sin evidencia.
- Informá qué cambió, qué se verificó y qué queda pendiente. Commits chicos
  por unidad de cambio cuando correspondan; publicar, crear Releases o ejecutar
  `tools/publicar.ps1` requiere un pedido explícito del usuario.
