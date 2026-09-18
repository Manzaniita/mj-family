# Las tiendas: qué lleva cada una y cómo se pone en marcha

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
| **Jaz Beauty Art** | Jaz Beauty Art | Core, Turnos Online, Fidelidad, Medios de Pago, Envíos, Optimizador, Fotos Rápidas | **Tienda nueva: todo a mano la primera vez** |

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
  Si la tienda tiene el **plugin oficial de un correo** (Andreani, Correo
  Argentino), la credencial se carga ahí y no en MJ Envíos: alcanza con que su
  método esté en una zona de envío. Después se mira *Envíos → Correos →
  Conectados por su plugin*, que avisa si falta algo.
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

## 3.1. Jaz Beauty Art (pestañas y cejas)

Pestañas y cejas con turnos, un Beauty Shop sin envíos y tarjeta de
fidelidad. Crema cálido, bordó y negro, Cormorant Garamond + Jost, modo día,
**sin animaciones**, pensada para el celular (la mayoría llega desde
Instagram). El logo lo sube Jaz en Apariencia → MJ Appearance.

**Qué se instala, en este orden (todo a mano la primera vez):** WordPress y
WooCommerce, después MJ Appearance (3.27.0 o más nuevo) y el tema hijo Jaz
Beauty Art, después MJ Turnos Online (1.3.0+), MJ Fidelidad, MJ Medios de Pago
(1.5.0+), MJ Envíos (1.8.0+), Optimizador, Fotos Rápidas y MJ Core. Un cron
real del hosting (cada 5 minutos, llamando a `wp-cron.php`) hace exactos la
reserva provisoria de 1 hora y el recordatorio de 1 hora antes. Un plugin SMTP
para que los mails lleguen. En PHP, `max_input_vars` en 3000 o más (el panel
del tema manda muchos campos; si el hosting lo corta, avisa y no guarda).

**Lo que el tema hijo ya propone** (todo editable): seña del 50 % por
transferencia, reserva provisoria de 1 hora, comprobante obligatorio,
cancelar y reprogramar con 48 h (a tiempo: la seña queda a favor o se
conserva; tarde: se pierde y se abona otra), saldo a favor, opciones cuando
cancela Jaz, cuenta obligatoria, recordatorios a las 24 h y a la 1 h, aviso
de mantenimiento 2 días antes, opiniones después del turno, el formulario con
la ficha de salud (alergias, anteojos o lentes, primera vez, sensibilidad,
látex, cirugía, género, fecha de nacimiento, Instagram), la ficha técnica de
pestañas y cejas, los mails con el tono de Jaz, la tarjeta de 10 sellos y el
botón fijo "Reservar turno".

**Qué cargar apenas se instala**

1. Apariencia → **Contenido inicial** → "Cargar el contenido inicial": crea
   como borrador las categorías (Pestañas, Cejas, Combos), los 13 servicios,
   los 4 productos y las páginas Inicio, Sobre mí, Preguntas frecuentes,
   Políticas y Mis trabajos.
2. **Turnos → Servicios**: a cada servicio, precio, duración, foto,
   mantenimiento ("volver cada N semanas"), las secciones (qué incluye,
   cuidados, recomendaciones, información adicional) y los sellos del cuadro
   Fidelidad (un combo puede dar 2). Publicar.
3. **Turnos → Ajustes → Agenda**: días y horarios, días cerrados.
   **Reservas y seña**: las instrucciones para transferir a Mercado Pago
   (alias, CVU, titular). **Notificaciones**: el email de Jaz.
4. **Productos**: precio, stock, foto y descripción de cada uno. Publicar.
5. **MJ Medios de Pago**: un medio "Transferencia a Mercado Pago" con sus datos
   para pagar, comprobante obligatorio, plazo 1 hora, "Al vencer el plazo →
   cancelar el pedido" prendido, y "Al aprobar el pago" → *Listo para
   retirar* (después de prenderlo en Envíos).
6. **MJ Envíos**: un método "Retiro / punto a coordinar" (tipo retiro, con
   sus instrucciones) y, en Seguimiento y avisos, **Listo para retirar**
   prendido. Ningún método de envío a domicilio.
7. **Páginas**: revisar los textos de Sobre mí y Políticas, subir las fotos
   de Mis trabajos (bloque Galería, con el nombre de cada tratamiento),
   publicar todo y elegir "Inicio" como portada (Ajustes → Lectura).
8. **Apariencia → MJ Appearance**: logo, Contacto (solo Instagram
   @jaz.beauty.art, la zona donde atiende y nada de dirección exacta,
   WhatsApp ni mapa) y la barra de navegación: Inicio, Servicios, Reservar
   turno, Mis trabajos, Beauty Shop, Sobre mí, Preguntas frecuentes,
   Políticas, Contacto.

**Prueba corta:** la de `PRUEBAS-EN-LA-TIENDA.md` §10, con una reserva real
de punta a punta (reserva, comprobante, confirmación, recordatorios,
reprogramar, atendido, opinión y sello), y una compra con retiro.

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
- **No encender la cotización en vivo de envíos** en GlossyHair, Yamana ni Jaz
  Beauty Art: ninguna tiene cuenta con un correo. En MJ Importaciones ya cotiza
  Andreani por su plugin oficial; falta probarlo con una compra real
  (`PRUEBAS-EN-LA-TIENDA.md`, sección 11).
- Cuando se instale MJ Core en una tienda o se cree o revoque un token,
  actualizar la tabla de arriba y la de `PUBLICAR.md`, sección 3.
