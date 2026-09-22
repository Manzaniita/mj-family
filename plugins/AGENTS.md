# Plugins — mapa y reglas comunes

Aplican también las [instrucciones de la raíz](../AGENTS.md). Elegí un plugin;
leé su archivo principal para ubicar los módulos y luego solo los relevantes.

## Mapa de entrada

| Función | Carpeta | Primera consulta |
| --- | --- | --- |
| Actualizaciones desde GitHub | [mj-core](mj-core/) | `mj-core.php`, `includes/`; [publicación](../PUBLICAR.md#actualizaciones). |
| Conversión USD y precios | [mj-dolar](mj-dolar/) | `mj-dolar.php`, `includes/`; [pruebas](../PRUEBAS-EN-LA-TIENDA.md#dolar). |
| Medios de pago y comprobantes | [mj-medios-pago](mj-medios-pago/) | `mj-medios-pago.php`, `includes/`; [pruebas](../PRUEBAS-EN-LA-TIENDA.md#pagos). |
| Métodos y cotizaciones de envío | [mj-envios](mj-envios/) | `mj-envios.php`, [manual](mj-envios/LEEME.md); [configuración](../CONFIGURACION.md#envios). |
| Ventas, stock, caja y consignaciones | [mj-erp](mj-erp/) | [AGENTS.md](mj-erp/AGENTS.md). |
| Reservas, agenda y señas | [mj-turnos-online](mj-turnos-online/) | [AGENTS.md](mj-turnos-online/AGENTS.md). |
| Sellos y premios | [mj-fidelidad](mj-fidelidad/) | `mj-fidelidad.php`, [manual](mj-fidelidad/LEEME.md). |
| Productos de colegas y liquidaciones | [mj-colegas](mj-colegas/) | `mj-colegas.php`, [manual](mj-colegas/LEEME.md). |
| Conversión y optimización de imágenes | [mj-optimizador-imagenes](mj-optimizador-imagenes/) | `mj-optimizador-imagenes.php`, `includes/`. |
| Carga de fotos desde el celular | [mj-fotos-rapidas](mj-fotos-rapidas/) | `mj-fotos-rapidas.php`; plugin concentrado en ese archivo. |

## Al modificar

- Conservá el prefijo del paquete para clases, funciones, constantes, opciones
  y rutas REST. Antes de elegir uno nuevo, comprobá colisiones en el repo.
- Las dependencias obligatorias declaradas (por ejemplo WooCommerce) se validan
  al arrancar. Desactivar una integración opcional no debe causar errores fatales.
- REST/AJAX: validá permisos y propiedad de los datos del lado servidor; un
  nonce no sustituye una capability. Sanitizá entradas, escapá salidas y usá
  consultas preparadas cuando corresponda. No expongas datos privados en config pública.
- Si cambia el esquema, revisá instalación, migraciones y desinstalación;
  distinguí versión del plugin y versión de base de datos. Desactivar no debe
  borrar datos. Evitá efectos duplicados al reintentar pagos, stock o reservas.
- Ajustes: [CONFIGURACION §3](../CONFIGURACION.md#paneles) y
  [§4](../CONFIGURACION.md#instalaciones-especiales). Uso: `LEEME.md` del plugin
  si existe; no dupliques ahí las instrucciones para agentes.
- Si cambia lo que consume la tienda React, seguí el contrato hasta
  `themes/mj-appearance/react-app/src/lib/` o la página afectada y leé su guía.

## Verificar y entregar

Seguí [PUBLICAR §8](../PUBLICAR.md#cambio-existente) para versiones y entregables.
PHP o JS sin build: verificación rápida. Fuente de Turnos: verificación completa.
Elegí las pruebas manuales del flujo afectado; el ERP y Turnos tienen guías
propias. No pruebes cobros, mensajes ni cambios de stock en tiendas reales sin
autorización para esas acciones.
