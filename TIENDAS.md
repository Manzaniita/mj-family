# Las tres tiendas: qué lleva cada una y cómo se pone en marcha

Este documento reemplaza a las guías de entrega de GlossyHair y Yamana, que
quedaron viejas (hablaban de MJ Appearance 3.6.0). Acá está lo que hoy corre en
cada tienda, qué conviene cargar apenas se instala, y la prueba corta para
confirmar que anda. Las versiones vigentes están en la tabla del `README.md`;
cómo se instala y se actualiza, en `PUBLICAR.md`.

---

## 1. De un vistazo

| Tienda | Tema hijo | Plugins | MJ Core |
| --- | --- | --- | --- |
| **MJ Importaciones** | MJ Atelier | Core, Dólar, Medios de Pago, Envíos, ERP, Optimizador, Fotos Rápidas | Instalado, con token |
| **GlossyHair** | GlossyHair | Core, Turnos Online, Medios de Pago, Envíos, Optimizador, Fotos Rápidas | **Falta instalar** |
| **Yamana** | Yamana | Core, Colegas, Medios de Pago, Envíos, Optimizador, Fotos Rápidas | **Falta instalar** |

Las tres llevan el tema padre **MJ Appearance** (instalado, sin activar) y el
tema hijo activo. La composición sale de `bundles` en
`tools/releases.config.json`, que es lo que `build-releases.ps1` imprime al
terminar.

**Orden de instalación la primera vez** (detalle en `PUBLICAR.md`, sección 6):

1. Apariencia → Temas → Subir: primero `mj-appearance-<versión>.zip`, después
   el hijo. Activar el hijo.
2. Plugins → Subir: primero `mj-core`, después el resto. Activar todos.
3. Ajustes → MJ · Actualizaciones: repositorio y token (sección 3 de
   `PUBLICAR.md`). Desde ahí, todo lo demás se actualiza solo.
4. Apariencia → MJ Appearance: logo, nombre, portada y textos.

Un tema hijo sin su padre aparece como "tema roto"; un plugin nuevo se sube a
mano la primera vez, MJ Core solo actualiza lo que ya está.

---

## 2. MJ Importaciones (MJ Atelier)

Tienda de importación con precios en dólares, mostrador propio y ERP.

**Qué cargar apenas se instala**

- **WooCommerce → Dólar**: el tipo de cambio del día y el redondeo. Sin esto,
  los productos con precio USD no tienen precio en pesos.
- **WooCommerce → Medios de pago**: recargo o descuento de cada medio, y en
  *Editar textos y datos para pagar* el alias, CBU y titular de transferencia.
  En **Comprobantes → Ajustes**, en qué medios se pide comprobante y la
  equivalencia de cada medio con el del ERP (así hereda su caja).
- **WooCommerce → Envíos**: el código postal de origen y al menos un método
  encendido. Empezar por retiro en el local o precio fijo; la cotización en
  vivo con los correos queda apagada hasta tener cuenta (ver
  `PRUEBAS-EN-LA-TIENDA.md`, sección 3.7).
- **MJ ERP → Configuración**: canales, medios de pago con su caja, formas de
  entrega, cuándo se emite el pedido, la libreta de clientes y quién puede
  corregir ventas.
- **Apariencia → MJ Appearance**: logo, hero, contacto y WhatsApp de los
  vendedores.

**Prueba corta**

1. Un producto de prueba con precio USD 100: la ficha lo muestra en pesos
   redondeado, y cambia al mover el dólar.
2. Compra de prueba con transferencia desde incógnito: la confirmación muestra
   "Cómo pagar" con los datos y el botón de copiar; el comprobante se sube y
   aparece en la bandeja; al aprobarlo el pedido pasa a Procesando y en el ERP
   la venta queda cobrada.
3. En el ERP, una venta de mostrador cobrada y entregada: el pedido de
   WooCommerce se emite solo y el stock baja una vez.

El paso a paso completo, con lo que tiene que pasar en cada punto, está en
`PRUEBAS-EN-LA-TIENDA.md`.

---

## 3. GlossyHair (turnos)

Salón de alisados que además vende productos. Blanco y fucsia, Urbanist +
Manrope, modo día, animación media, sin intro.

**Decisiones que ya están tomadas:** la clienta elige servicio + día y hora; el
turno queda pendiente hasta que llega la seña (se puede cambiar a "hasta que
confirmes vos" o "confirmado al instante"); `/turnos` es la reserva pública para
las clientas y el panel para quien atiende cuando entra con su usuario; una sola
agenda.

**Qué cargar apenas se instala** (Apariencia → MJ Appearance y Turnos → Ajustes)

- Logo, nombre del sitio, hero con los botones "Reservar turno" (→ `/turnos`) y
  "Ver productos" (→ `/tienda`).
- **Turnos → Servicios**: un servicio por tratamiento, con duración, precio,
  seña propia opcional, color y foto. Con la duración se calculan los horarios.
- **Turnos → Ajustes → Agenda**: horarios por día, cada cuántos minutos, descanso
  entre turnos, anticipación mínima, días cerrados.
- **Reservas y seña**: modo de confirmación, monto o porcentaje, instrucciones
  para transferir, horas para enviarla antes de que el turno se cancele solo, y
  si se pide comprobante (necesita MJ Medios de Pago activo).
- **Notificaciones**: email de la dueña, recordatorio (24 h por defecto),
  plantillas de email y WhatsApp.
- **Ficha técnica**: viene armada para alisados (historial químico, análisis de
  la fibra, trabajo técnico, caja y seguimiento); se puede cambiar toda.
- Categorías del menú (hasta 4) y "Sobre mí".

**Cómo se trabaja:** la clienta reserva en `/turnos` y ve la pantalla de seña;
quien atiende entra a la misma URL con su usuario y ve el panel (Hoy, Turnos,
Clientas con su ficha, Caja, Más). Al confirmar un turno se crea sola la ficha
de esa visita; "Nueva visita" precarga el historial de la última vez. Manual
completo en `plugins/mj-turnos-online/LEEME.md`.

**Prueba corta**

1. Servicio "Alisado" de 120 minutos: aparece en la portada y en `/servicios`.
2. Reserva desde incógnito con un email propio: llegan dos emails (nueva reserva
   a la dueña, pedido de seña a la clienta). "Ya transferí la seña" avisa por
   email.
3. En el panel, "Seña recibida" con el monto: se crea la ficha y la clienta
   recibe la confirmación. Marcar "Crítica (chicle)" en Elasticidad muestra la
   advertencia; el total aparece en Caja.
4. Borrar el turno y la clienta de prueba.

**Hosting:** si el sitio está detrás de Cloudflare o un proxy, el límite de
reservas por IP ve una sola IP para todas; el filtro `mjto_client_ip` está
explicado en el LEEME. Los emails salen con `wp_mail`: si el hosting no entrega
bien, un plugin SMTP. Los recordatorios corren con el cron de WordPress; si el
hosting permite un cron real, mejor.

---

## 4. Yamana (Entre Hilos)

Tejidos artesanales y cursos, con el proyecto colectivo Entre Hilos: piezas de
colegas en la misma tienda, con comisión. Lino y terracota, Fraunces + Nunito.

**Qué cargar apenas se instala**

- Logo, nombre del sitio y descripción corta (es el subtítulo del hero si no se
  escribe otro), hero, textos de la tienda.
- Cómo llamar a las "marcas": si el atributo *Marca* se usa para el hilado,
  ponerle "Hilado / Hilados".
- Categorías del menú (por ejemplo Prendas, Accesorios, Amigurumis, Kits,
  Cursos). Los cursos se venden como producto *virtual* dentro de la categoría
  Cursos; si algún día hacen falta fecha, modalidad y cupos, es un plugin chico
  aparte.
- **Colegas → Lista de colegas**: una ficha por colega (nombre y presentación
  públicos; comisión propia, contacto y cómo pagarle, privados).
- **Colegas → Ajustes**: nombre del proyecto (con Yamana activo ya se llama
  Entre Hilos y vive en `/entre-hilos`), comisión por defecto (10 %), sobre qué
  se calcula y qué estados de pedido cuentan como venta.

**Cómo se trabaja:** en cada producto se elige quién lo hizo; el cliente compra
mezclando piezas propias y de colegas; en **Colegas → Liquidaciones** se ve por
período cuánto vendió cada una, cuánto es la comisión y cuánto hay que pagarle,
con "Marcar como liquidado" y CSV. Manual en `plugins/mj-colegas/LEEME.md`.

**Prueba corta**

1. Una colega de prueba con foto, asignada a un producto: el chip "Tejido por…"
   sobre la foto, el filtro Colegas en la tienda, y su página en
   `/entre-hilos/nombre`.
2. Pedido de prueba de ese producto pasado a Procesando: aparece en
   Liquidaciones con el 10 % calculado; "Marcar como liquidado" lo pasa al
   historial.
3. Borrar el pedido y la colega de prueba.

---

## 5. Lo que hay que hacer en las tiendas, hoy

- **Instalar MJ Core en GlossyHair y Yamana** con su token (uno por tienda,
  solo lectura). Hasta que esté, esas dos se actualizan subiendo el zip a mano.
- **Correr las pruebas de `PRUEBAS-EN-LA-TIENDA.md`** en MJ Importaciones: MJ
  Dólar, MJ Medios de Pago, MJ Envíos y las correcciones, devoluciones y saldo a
  favor del ERP nunca se probaron con una tienda funcionando.
- **No encender la cotización en vivo de envíos** hasta tener cuenta con un
  correo y haberlo probado contra su ambiente de pruebas.
- Cuando se instale MJ Core en una tienda o se cree o revoque un token,
  actualizar la tabla de arriba y la de `PUBLICAR.md`, sección 3.
