# MJ Appearance — PHP y tienda React

Aplican [las reglas de temas](../AGENTS.md). WordPress/WooCommerce es el backend;
`react-app/src/` es el fuente de la tienda y `dist/` es lo que se distribuye.

## Mapa por función

| Tarea | Entrada |
| --- | --- |
| Carga del tema y assets | `functions.php`, `style.css`. |
| Ajustes y configuración que recibe React | `inc/settings.php`, `inc/config.php`, `react-app/src/lib/config.js`. |
| Sesión, cuenta y REST propio | `inc/session.php`, `inc/rest.php`, `react-app/src/store/auth.js`, `react-app/src/lib/api.js`. |
| Skins y navegación | `inc/skins.php`, `inc/nav.php`, `react-app/src/lib/nav.js`. |
| Bloques y páginas de WordPress | `inc/bloques.php`, `inc/paginas.php`, `react-app/src/components/bloques/`, `react-app/src/pages/Page.jsx`. |
| Catálogo y producto | `react-app/src/store/catalog.js`, `react-app/src/pages/Shop.jsx`, `Product.jsx` en esa misma carpeta. |
| Carrito y checkout | `react-app/src/pages/CartPage.jsx`, `Checkout.jsx`, `react-app/src/lib/wc.js`. |
| Envíos, pagos y turnos | `react-app/src/lib/envios.js`, `payments.js`, `turnos.js`; seguí el contrato hasta el plugin afectado. |
| SEO | `inc/seo.php`, `react-app/src/lib/seo.js`. |
| Diseño y componentes comunes | `react-app/src/styles/globals.css`, `react-app/src/components/`. |

## Reglas del frontend

- Los datos comerciales y permisos vienen del servidor. No dupliques en React
  las decisiones de precio, saldo, disponibilidad, envío o acceso.
- Conservá los contratos de Store API y REST propio, incluidos errores,
  sesión y nonces. No presupongas plugins opcionales activos.
- Respetá rutas y configuración calculadas por PHP; no fijes dominios de
  tiendas ni rutas absolutas que rompan una instalación en subcarpeta.
- Revisá móvil, teclado, foco, estados vacíos y errores en la interacción
  afectada. Paletas y niveles de animación se configuran, no se imponen.

## Build y pruebas

Desde `react-app/`: `npm ci --no-audit --no-fund`, luego `npm run build`.
Vite escribe `../dist/`; PHP resuelve los assets con hash. No fijes nombres de
chunks ni edites bundles. Los scripts son `dev`, `build` y `preview`.
El frontend necesita un backend WordPress configurado para probar operaciones
reales: compilar o abrir Vite no valida por sí solo carrito, sesión o checkout.

Cambios en React requieren verificación completa desde la raíz. Cambios solo
de PHP usan `-Rapido`. Versioná `style.css` y `MJ_VERSION` juntos si cambia el
paquete distribuido. Consultá [ajustes](../../CONFIGURACION.md#appearance) y
[pruebas del tema](../../PRUEBAS-EN-LA-TIENDA.md#appearance), más las del plugin
con el que se cruza el cambio.
