# MJ ERP — trabajar por circuito

Heredá [las reglas de plugins](../AGENTS.md). El ERP usa PHP y JavaScript propio
en `assets/js/`; no es una app Vite y no requiere un build React.

## Entradas y responsabilidades

| Cambio | Archivos a buscar dentro de este plugin |
| --- | --- |
| Arranque y carga de módulos | `mj-erp.php`, `includes/class-mjerp-plugin.php`. |
| API y permisos | `includes/rest/class-mjerp-rest-*.php`, `class-mjerp-permissions.php` y `class-mjerp-capabilities.php` en `includes/`. |
| Venta, edición, pedido y stock | `includes/class-mjerp-sales.php`, `class-mjerp-sale-editor.php`, `class-mjerp-orders.php`, `class-mjerp-stock.php`. |
| Devoluciones y saldo a favor | `includes/class-mjerp-returns.php`, `class-mjerp-credit.php`, `class-mjerp-shop-credit.php`. |
| Persistencia, caja, reportes y consignaciones | `includes/repositories/`: elegí el repositorio del circuito. |
| Pantalla del operador | `assets/js/page-*.js`, `components-*.js`, `core.js`; CSS en `assets/css/app.css`. |
| Portal del consignatario | `includes/class-mjerp-portal.php`, `assets/js/portal.js`, `templates/portal.php`. |
| Esquema y bajas | `includes/class-mjerp-install.php`, `uninstall.php`. |

Seguí el recorrido pantalla → REST → lógica → repositorio del caso concreto.
No leas los demás repositorios o pantallas salvo que aparezca un cruce real.
La carga de archivos está declarada en `MJERP_Plugin::files()`.

## Invariantes para revisar

- Verificá por separado stock, cobros, entrega, estado del pedido y saldo: una
  transición no implica automáticamente todas las demás.
- Evitá descontar stock o imputar plata dos veces al repetir una operación.
  Conservá moneda, cotización histórica y trazabilidad de correcciones.
- Validá acceso al cliente, venta o consignación además del permiso general.
- Preservá el contrato entre repositorios, REST y JS; una columna nueva necesita
  migración para instalaciones existentes, además de la creación desde cero.
- `MJERP_VERSION` y `MJERP_DB_VERSION` tienen propósitos distintos: la segunda
  cambia solo cuando el esquema/migración lo requiere.

## Documentación y verificación

Consultá [configuración del ERP](../../CONFIGURACION.md#erp) o
[consignaciones](../../CONFIGURACION.md#consignaciones), según el caso.
Las pruebas manuales son [ventas](../../PRUEBAS-EN-LA-TIENDA.md#erp-ventas),
[devoluciones](../../PRUEBAS-EN-LA-TIENDA.md#erp-devoluciones),
[entregas](../../PRUEBAS-EN-LA-TIENDA.md#erp-entregas),
[caja](../../PRUEBAS-EN-LA-TIENDA.md#erp-caja) y
[reportes](../../PRUEBAS-EN-LA-TIENDA.md#erp-reportes).
Corré la verificación rápida si no se modificó también una app React.
Un lint correcto no demuestra que una operación contable funcione: informá
cuáles recorridos se probaron y cuáles requieren WordPress/WooCommerce.
