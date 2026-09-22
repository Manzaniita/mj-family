# MJ Turnos Online — reserva, panel e integraciones

Aplican [las reglas de plugins](../AGENTS.md). Puede funcionar sin WooCommerce
y con cualquier tema mediante `[mj_turnos]`; preservá esa independencia.

## Dónde modificar

| Tarea | Fuente |
| --- | --- |
| Arranque y cron | `mj-turnos-online.php`. |
| Ajustes, servicios y esquema | `inc/options.php`, `inc/services.php`, `inc/db.php`. |
| Horarios y reserva | `inc/availability.php`, `inc/bookings.php`. |
| Cuentas, cambios y saldo | `inc/accounts.php`, `inc/policies.php`. |
| Comprobantes y avisos | `inc/receipts.php`, `inc/notifications.php`. |
| Opiniones y REST | `inc/reviews.php`, `inc/rest.php`. |
| Integración con tema y panel | `inc/frontend.php`, `inc/panel.php`. |
| Reserva pública | `app/src/booking/`. |
| Agenda de quien atiende | `app/src/panel/`. |
| Campos de fichas y componentes | `app/src/builder/`, `app/src/ui/`. |
| Cliente API y configuración pública | `app/src/lib/api.js`, `app/src/lib/config.js`. |

## Cuidados del circuito

- Revisá disponibilidad en el servidor, incluidos solapamientos y cambios de
  estado. Contemplá zona horaria, vencimiento de señas y reintentos del cron.
- La cuenta o token debe permitir operar únicamente sobre los turnos propios;
  el panel usa `MJTO_CAP`. Config pública no debe filtrar datos privados.
- Cancelar o reprogramar respeta las políticas guardadas, el saldo y la seña.
  Las integraciones de comprobantes y fidelidad siguen siendo opcionales.
- El mismo bundle sirve reserva, panel y constructor de campos. Conservá el
  aislamiento CSS `.mjto-root`/`.mjto-page` de `app/vite.config.js`.

## Compilar y verificar

Desde `app/`: `npm ci --no-audit --no-fund` para instalar y `npm run build`
para generar `../dist/`. No edites `dist/app.js` o `dist/app.css` a mano.
Cambiar `app/` requiere la verificación completa desde la raíz y revisar los
compilados. Los scripts disponibles son `dev` y `build`; no inventes `npm test`.

Uso: [LEEME.md](LEEME.md). Ajustes: [Turnos](../../CONFIGURACION.md#turnos).
Prueba manual: [reserva de punta a punta](../../PRUEBAS-EN-LA-TIENDA.md#turnos).
Si cambia el contrato que consume MJ Appearance, revisá también la integración
en el tema y su guía antes de editarla.
