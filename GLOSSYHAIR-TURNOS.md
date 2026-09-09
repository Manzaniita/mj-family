# GlossyHair y MJ Turnos Online — qué se entregó y cómo ponerlo en marcha

Entregado el 8 de septiembre de 2026 (revisado y corregido). Tres archivos en `_zips/`:

| Archivo | Qué es | Dónde se sube |
|---|---|---|
| `mj-appearance.zip` | Tema padre **MJ Appearance 3.5.0** (obligatorio: el hijo lo necesita; sirve también para MJ Importaciones y Yamana) | Apariencia → Temas → Añadir → Subir tema |
| `mj-glossyhair.zip` | Tema hijo **GlossyHair** (blanco + fucsia, Urbanist + Manrope, modo día) | Apariencia → Temas → Añadir → Subir tema, y **activarlo** |
| `mj-turnos-online.zip` | Plugin **MJ Turnos Online** (turnos con seña, fichas técnicas por visita, panel en `/turnos`) | Plugins → Añadir → Subir plugin |

Orden: primero el padre, después el hijo (activar GlossyHair), después el plugin. Si ya tenías MJ Appearance 3.4, subí el 3.5 encima: WordPress pregunta si querés reemplazarlo y los ajustes guardados no se pierden. El plugin no necesita WooCommerce, pero la tienda de productos sigue funcionando igual que siempre con WooCommerce activo.

Las decisiones que tomamos antes de arrancar: la clienta elige **servicio + día y hora**; el turno queda **pendiente hasta que llega la seña** (se puede cambiar a "pendiente hasta que confirmes vos" o "confirmado al instante"); **`/turnos` hace las dos cosas** (reserva pública para las clientas, panel para tu hermana cuando entra con su usuario de WordPress); **una sola agenda** y entran al panel las administradoras.

---

## 1. El tema hijo GlossyHair

Al activarlo, el sitio queda en modo día, animación media y sin intro, con la paleta blanco + fucsia y las tipografías Urbanist (títulos) y Manrope (texto). Todo se sigue cambiando desde **Apariencia → MJ Appearance**; lo que ya escribió el admin nunca se pisa.

Lo que conviene cargar apenas se active, todo en Apariencia → MJ Appearance:

- **Logo** (y opcionalmente una variante clara para el modo noche).
- **Nombre del sitio y descripción corta** en Ajustes → Generales ("GlossyHair" y una frase).
- **Portada (hero)**: fotos de fondo, título y botones. El tema propone "Pelo liso, con brillo / y sano de verdad." con los botones "Reservar turno" (→ /turnos) y "Ver productos" (→ /tienda).
- **Secciones de la portada** (nuevo en 3.5): ahora se pueden **ordenar arrastrando** y tildar cuáles se ven. GlossyHair arranca con: Servicios → Reservá tu turno → Sobre mí → Destacados → Categorías → Novedades → Opiniones → Instagram (banners, marcas y recomendados quedan ocultos).
- **Nosotros / Sobre mí** (nuevo en 3.5): foto, texto (párrafos separados por línea en blanco), puntos destacados (`Texto | ícono`, uno por línea), cifras propias (`Número | Etiqueta`) y un botón opcional al final. La misma foto y el primer párrafo se usan en la sección "Sobre mí" de la portada.
- **Textos de la tienda**: todos los títulos de cada pantalla, y ahora también los del **menú** (Inicio, Tienda, Ofertas, Novedades, Contacto, y "Sobre mí" para mostrar Nosotros en el menú) y los del **pie de página**.
- **Categorías del menú**: hasta 4 (por ejemplo Shampoo, Acondicionador, Tratamientos, Kits de alisado).

Los productos de cuidado capilar se cargan como siempre en WooCommerce; la portada los muestra en Destacados, Categorías y Novedades.

---

## 2. El plugin MJ Turnos Online

Manual completo en `mj-turnos-online/LEEME.md`. Resumen para arrancar, todo en **Turnos** (menú de wp-admin):

1. **Turnos → Servicios**: un servicio por tratamiento (alisado, botox capilar, etc.) con duración, precio (visible o no), seña propia opcional, color y foto. Con la duración se calculan los horarios libres.
2. **Turnos → Ajustes → Agenda**: horarios por día (`09:00-13:00, 15:00-20:00`; vacío = cerrado), cada cuántos minutos se ofrecen horarios, descanso entre turnos, anticipación mínima, hasta cuántos días adelante, días cerrados.
3. **Reservas y seña**: modo de confirmación (arranca en "pendiente hasta recibir la seña"), seña fija o porcentaje, instrucciones para transferir (alias, CBU, titular), link de pago opcional, horas para enviarla antes de que el turno se cancele solo, si la clienta puede cancelar y con cuánta anticipación, texto de política.
4. **Notificaciones**: email de la dueña, qué avisos recibe, recordatorio automático (por defecto 24 h antes), todas las plantillas de email y WhatsApp con placeholders (`{nombre}`, `{servicio}`, `{fecha}`, `{hora}`, `{sena}`, `{link_turno}`…) y los dos motivos de cancelación que ve la clienta en `{motivo}` (cuando cancela ella y cuando vence la seña).
5. **Textos y páginas**: nombre, dirección y WhatsApp del negocio (vacíos = los del tema), URL de la página de turnos, etiquetas del menú, textos de la reserva y de la página de servicios, nombres de los estados y **mensajes** (cada aviso de error que puede ver la clienta: campo obligatorio, email o teléfono mal, horario ya tomado, demasiados intentos…; vacío = el texto por defecto).
6. **Formulario**: qué datos pide la reserva (nombre y teléfono siempre; el resto se renombra, desactiva o se agregan campos).
7. **Ficha técnica**: bloques y campos de la ficha con sus opciones y advertencias. Viene armada con la ficha de GlossyHair: historial químico (tintura, decoloración/reflejos, alisados previos, permanente), análisis de la fibra (grosor, porosidad, elasticidad con aviso en "Crítica (chicle)", cuero cabelludo con aviso en "Con lesiones"), trabajo técnico (prueba de mechón, producto, plancha y pasadas, observaciones) y caja y seguimiento (¿dejó seña?, monto, método de pago, total cobrado, control a los 3 días, retoque a los 4 meses). Se puede cambiar todo y restaurar la ficha por defecto.

### Cómo se trabaja en el día a día

- **La clienta** entra a `/turnos` (o toca "Reservar" en el header o en la portada), elige el tratamiento, el día y la hora, deja nombre, apellido, teléfono, localidad (y email si quiere) y ve la pantalla de seña con las instrucciones y el botón "Ya transferí la seña". Le llega un email con el resumen y un link para ver el estado o cancelar.
- **Tu hermana** entra a `/turnos` con su usuario de WordPress (desde el celu o la tablet) y ve el **panel**: **Hoy** (agenda del día con confirmar, seña recibida, abrir ficha, WhatsApp con plantillas, reprogramar, atendida, no vino, cancelar), **Turnos** (rangos, estados, buscador), **Clientas** (buscador por nombre o teléfono, filtros por año y mes, y la ficha de cada una con su línea de tiempo de visitas), **Caja** (cobrado, señas, por método, CSV) y **Más** (bloqueos de agenda por vacaciones o franjas, exportaciones).
- **Al confirmar un turno** (con la seña o a mano) se crea sola la ficha de esa visita con los datos de la clienta y la fecha. Cuando la clienta llega, abre la ficha desde el turno y completa el diagnóstico, el trabajo y el pago. Las opciones con advertencia (elasticidad crítica, cuero cabelludo con lesiones) se resaltan al elegirlas.
- **Si la clienta vuelve** (a los 4 meses o cuando sea), "Nueva visita" en su ficha agrega otra entrada a la línea de tiempo con el historial químico y el análisis precargados de la última vez, sin borrar nada.
- **Los contadores de arriba** (Pendiente, Esperando seña, Avisaron seña) se tocan y abren la lista de turnos ya filtrada.
- **Ver la reserva como clienta** estando logueada: `/turnos?vista=publica`.

---

## 3. Prueba corta para confirmar que anda

1. Subí y activá los tres archivos en ese orden. Entrá a la portada: fondo blanco, títulos en Urbanist, botones fucsia, sección "Servicios" vacía hasta que cargues uno.
2. Turnos → Servicios: creá "Alisado" de 120 minutos con precio. En la portada aparece la tarjeta y en `/servicios` la lista.
3. Turnos → Ajustes → Reservas y seña: dejá el modo "pendiente hasta recibir la seña", poné un monto y tus instrucciones. Guardá.
4. En una ventana de incógnito, abrí `/turnos` y reservá con un email tuyo. Tenés que ver el paso final con la seña y recibir dos emails (nueva reserva para la dueña y pedido de seña para la clienta). Tocá "Ya transferí la seña".
5. Entrá a `/turnos` con tu usuario administrador: aparece el panel con el turno marcado "avisó la seña". Tocá "Seña recibida", cargá el monto y confirmá: se crea la ficha y la clienta recibe la confirmación.
6. Abrí la ficha desde el turno, marcá "Crítica (chicle)" en Elasticidad (tiene que aparecer la advertencia), cargá un total y guardá. En Caja tiene que aparecer el monto. Tocá "Sugerir" en Retoque: tiene que proponer 4 meses después.
7. En Clientas, buscá por el teléfono que usaste: aparece con 1 visita. Tocá "Nueva visita": la nueva entrada trae precargado el historial químico y el análisis de la fibra.
8. Borrá el turno y la clienta de prueba si querés (menú "…" del turno → Eliminar; ficha → Eliminar clienta).

---

## 4. Detalles del hosting (por si hacen falta)

- **Si el sitio está detrás de Cloudflare o de un balanceador**, el límite de reservas por IP (5 cada 10 minutos, configurable) puede ver una sola IP para todas las visitantes. En `LEEME.md` está el filtro `mjto_client_ip` para tomar la IP real de la cabecera del proxy. El límite cuenta **reservas creadas**, no intentos: equivocarse en un campo o encontrar el horario ocupado no gasta cupo.
- **Emails**: los manda WordPress con `wp_mail`. Si el hosting no entrega bien, conviene un plugin SMTP; los avisos por WhatsApp del panel no dependen de eso.

---

## 5. Lo que no pude verificar acá

No tengo WordPress ni PHP en esta máquina. Lo que sí quedó verificado: el PHP pasa el análisis de sintaxis (19 archivos, 0 errores) y no hay funciones duplicadas ni llamadas a funciones inexistentes; las dos apps React compilan (tema 610 KB, plugin 429 KB) y los zips llevan ese build; las cinco suites de prueba automática en navegador simulado (panel, panel en celular, ficha, reserva y montaje de los tres modos) pasan enteras; una revisión cruzada de ocho lentes (seguridad, motor de agenda, contratos PHP↔React, requisitos, errores de ejecución, skin) encontró 28 puntos y los 28 quedaron corregidos y vueltos a probar, incluidos los dos graves (los horarios de la semana se corrían de día al guardar; el scroll del panel quedaba trabado después de encadenar dos diálogos). El recorrido completo real (subir zips, activar, reservar, recibir emails, confirmar, ficha) lo confirmás vos con la prueba de arriba. Si algo no se ve o no se comporta como esperás, decime qué pantalla y qué texto y lo ajusto.
