# Plugins MJ — plan completo de la tienda

Actualizado el 12 de septiembre de 2026.

Este documento junta todo lo que hablamos: lo que ya está funcionando, lo que hay
que rehacer, los plugins que quedaron acordados y las ideas nuevas que propongo.
Está escrito para que lo leas vos y decidas, no para un programador.

La idea de fondo es que toda la tienda funcione con plugins propios de MJ: nada
alquilado, nada que se pague por mes, nada que dependa de que un desarrollador
ajeno siga manteniendo su producto. Cada plugin hace una cosa y se puede
desactivar sin romper el resto.

---

## 1. Lo que ya está funcionando

| Plugin | Qué hace | Estado |
|---|---|---|
| **MJ Dólar** | Cargás el precio en dólares y la tienda lo pasa a pesos con el tipo de cambio que ponés vos. Campos USD en productos, variaciones, edición rápida, en lote y CSV. El dólar se ve en el header. | Entregado |
| **MJ Medios de Pago** | Descuento o recargo según el medio de pago, con precio distinto en tarjetas, ficha y carrito. Te deja crear medios de pago propios, como pago en dólares en el local. Y comprobantes de pago: elegís en qué medios se piden, el cliente sube la foto o el PDF, y vos aprobás o rechazás cada compra desde una sola bandeja (sirve también para los pagos de reventa de MJ ERP y para las señas de MJ Turnos Online). | Entregado |
| **MJ Fotos Rápidas** | Subís las fotos de los productos desde el celular en dos toques y los publicás desde ahí mismo. | Entregado (1.2.0) |
| **MJ Optimizador de Imágenes** | Achica y convierte todas las imágenes de la tienda usando tu propio servidor, sin límites ni créditos. Antes se llamaba WC Image Optimizer Ilimitado. | Entregado (1.1.0) |
| **MJ Colegas** | Productos de terceros dentro de la tienda: cada producto lleva quién lo hizo, vos cobrás y liquidás a cada colega descontando la comisión. Hecho para Yamana / Entre Hilos, pero sirve en cualquier tienda con el tema. Ver `YAMANA-ENTRE-HILOS.md`. | Entregado (1.0.0) |
| **MJ ERP** | Ventas propias y de la tienda, clientes, costeo, compras, proveedores, stock y consignación, con portal para consignatarios. La venta se carga como en un mostrador en la compu y en cinco pasos en el celular. Lleva la entrega y emite solo el pedido en WooCommerce, con su descuento de stock, cuando la venta se cobra o se entrega. Una venta sin cobrar se corrige desde su detalle; una cobrada acepta devoluciones, cambios y garantías, con la plata a favor del cliente o devuelta, y lo fallado esperando en RMA el reclamo al proveedor. El saldo a favor lo usa el cliente en la tienda. | Entregado (1.18.1) |
| **MJ Envíos** | Los métodos de envío los creás vos: a domicilio, a sucursal, retiro en el local, zona propia por código postal, gratis o a coordinar. Cada uno decide cuándo aparece, con qué medios de pago se combina, qué datos pide y de dónde sale su precio. Ver `plugins/mj-envios/LEEME.md`. | Entregado (1.0.0) |
| **MJ Turnos Online** | Reserva de turnos con seña, fichas técnicas por visita y panel de agenda para quien atiende. Ver `GLOSSYHAIR-TURNOS.md`. | Entregado (1.2.0) |
| **MJ Core** | Las actualizaciones de los temas y plugins MJ: cuando se publica una versión nueva, la tienda la ofrece con su botón de "Actualizar", como cualquier otra. Muestra qué versión de cada uno corre en la tienda. Ver `PUBLICAR.md`, sección 3. | Entregado (1.0.0). Instalado en MJ Importaciones; falta en GlossyHair y Yamana. |

Además, el tema **MJ Appearance 3.4** sumó: logo configurable (con variante para modo noche), todos los textos fijos de cada pantalla editables desde Apariencia → MJ Appearance → Textos de la tienda, secciones de la portada que se pueden ocultar, cómo llamar a las "marcas", y términos y privacidad propios. Esto aplica también a MJ Importaciones: lo que no toques sigue igual que antes.

> **Lo que falta antes de seguir sumando.** MJ Dólar, MJ Medios de Pago y MJ
> Envíos todavía no se probaron en una tienda real. Los tres tocan plata o el
> checkout, que es donde un error se paga caro. El paso a paso está en
> `PRUEBAS-EN-LA-TIENDA.md`.
>
> De MJ Envíos hay algo más puntual: los tres correos (Vía Cargo, Andreani,
> Correo Argentino) están escritos contra la documentación, sin cuenta y sin
> una sola llamada real. Hasta que exista una cuenta, conviene usar tabla
> propia o precio fijo, que son las opciones que no dependen de nadie.

---

## 2. Lo que hay que rehacer

Estos dos existían y se perdieron. El tema todavía tiene lugar reservado para
ellos: cuando vuelvan a estar, la tienda los reconoce sola.

### MJ Facturador

Facturación electrónica argentina. Es el más importante de los dos, porque sin
esto facturás a mano cada venta.

**Qué hacía y qué debería volver a hacer**

- Un paso de facturación en el checkout, donde el cliente elige si necesita
  factura y a nombre de quién.
- Factura A, B o C según tu condición y la del cliente, resuelto solo.
- Pide CUIT o DNI según el caso, y valida que el CUIT sea real antes de aceptarlo.
- Emite el comprobante contra ARCA (ex AFIP) y guarda el CAE en el pedido.
- El cliente ve y descarga su factura en PDF desde Mi cuenta.
- Cada cliente puede dejar guardados sus datos fiscales para no cargarlos de nuevo.
- El pedido en el admin muestra si la factura salió, quedó pendiente o dio error.

**Lo que necesito de vos cuando lo encaremos:** tu condición frente al IVA, el
punto de venta habilitado, y el certificado digital de ARCA. Es el único plugin
de toda la lista que depende de un trámite externo.

**Tamaño:** grande. Probablemente dos entregas.

### MJ Tiempos de Entrega

Mostraba el tiempo de entrega real de cada producto.

- Un texto por producto tipo "Disponible en 7 a 10 días", cargado por SKU.
- Distinto por variación, porque no todos los colores o modelos llegan igual.
- Se ve en la tarjeta del producto y en la ficha, arriba del botón de comprar.

Es chico y evita muchísimas consultas por WhatsApp. Vale la pena rehacerlo.

**Tamaño:** chico. Una entrega.

---

## 3. Los que quedaron acordados

Están en el orden que habíamos charlado.

### 3.1. MJ Vencimiento de Pedidos y Comprobantes

El problema que resuelve: alguien compra por transferencia, el pedido queda con
el stock reservado, y nunca paga. O paga, te manda el comprobante por WhatsApp, y
vos tenés que buscarlo entre mil mensajes.

**Qué hace**

- Los pedidos sin pagar se cancelan solos pasadas las horas que vos elijas, y el
  stock vuelve a estar disponible.
- Antes de cancelar, el cliente recibe un aviso de que le queda poco tiempo.
- El cliente sube el comprobante de la transferencia desde Mi cuenta, con foto o PDF.
- Vos ves los comprobantes pendientes en una sola pantalla y aprobás o rechazás.
- Al aprobar, el pedido pasa a pagado y el cliente recibe la confirmación.
- Cuenta atrás visible para el cliente: "Te quedan 22 horas para pagar este pedido".

**Por qué importa en tu caso:** con precios atados al dólar, un pedido sin pagar
que queda abierto tres días es plata perdida si el tipo de cambio se movió.

**Lo que necesito de vos:** cuántas horas de plazo, si querés plazos distintos
por medio de pago, y si el aviso va por email, WhatsApp o los dos.

**Tamaño:** mediano.

### 3.2. MJ Lista de Precios

Para un importador esto es de uso diario.

**Qué hace**

- Genera la lista completa de precios al dólar del día, en PDF o Excel.
- Filtrable por categoría, marca, o solo lo que tenés en stock.
- Con o sin fotos, según si es para mandar rápido o para mostrar.
- Podés generar una lista con precios mayoristas y otra minorista.
- Lista lista para mandar por WhatsApp, con tu logo y la fecha de vigencia.
- Opción de link permanente: mandás siempre el mismo link y muestra la lista
  actualizada al dólar de hoy.

**Por qué importa:** hoy eso lo armás a mano cada vez que cambia el dólar.

**Lo que necesito de vos:** qué datos querés en cada fila y si va con logo.

**Tamaño:** mediano.

### 3.3. MJ Precios Mayoristas

**Qué hace**

- Un segundo precio en dólares para clientes mayoristas.
- Descuento por cantidad: comprando 5 o más, otro precio.
- Cuentas mayoristas que vos aprobás, con un formulario de solicitud.
- El mayorista entra con su usuario y ve toda la tienda a su precio.
- Compra mínima obligatoria para mayoristas, si querés.
- El resto de los clientes nunca ve esos precios.

**Lo que necesito de vos:** si el precio mayorista es un porcentaje sobre el
minorista o un precio cargado aparte, y qué requisitos pedís para aprobar cuentas.

**Tamaño:** mediano.

### 3.4. MJ Presupuestos

Hoy el carrito ya arma un presupuesto en PDF, pero se genera en el navegador del
cliente y no queda registrado en ningún lado.

**Qué hace**

- El presupuesto se guarda en tu tienda, con número propio.
- Tiene fecha de validez, y avisa cuando está por vencer.
- Se envía por link: el cliente lo abre, lo ve con las fotos, y lo acepta.
- Al aceptar, se convierte en pedido con un clic.
- Vos podés armar presupuestos a mano desde el admin para un cliente puntual.
- Guarda con qué dólar y con qué medio de pago se calculó, así no hay discusión.
- Historial de todos los presupuestos, con cuáles se convirtieron en venta.

**Por qué importa:** te deja saber cuántos presupuestos mandás y cuántos cierran.

**Lo que necesito de vos:** cuántos días de validez por defecto y si querés
descuento especial autorizado por vos dentro del presupuesto.

**Tamaño:** mediano a grande.

### 3.5. MJ Envíos Argentina — **entregado** (MJ Envíos 1.0.0)

Se entregó como **MJ Envíos**, y bastante más grande de lo planeado: los métodos
de envío no vienen dados, los creás vos. Ver `plugins/mj-envios/LEEME.md`.

Lo que quedó pendiente de este punto: las etiquetas de despacho (hoy se carga el
número de seguimiento a mano en el pedido) y probar las tres APIs contra cuentas
reales, que todavía no existen.

**Qué hace**

- El cliente pone el código postal y ve el costo real del envío.
- Andreani, Correo Argentino y OCA.
- Retiro en el local como opción sin costo.
- Envío gratis a partir de cierto monto, que ya tenés configurado en el tema.
- Seguimiento: el cliente ve dónde está su paquete desde Mi cuenta.
- Generación de etiquetas de envío desde el admin.

**Aviso honesto:** es el más grande de la lista y el que más depende de terceros.
Cada correo tiene su propio sistema y sus propias credenciales. Lo dejaría para
el final, y quizás arrancando por uno solo de los tres.

**Lo que necesito de vos:** con qué correos trabajás realmente, y si ya tenés
cuenta de empresa con ellos.

**Tamaño:** grande.

---

## 4. Ideas nuevas que propongo

Estas no las habíamos hablado. Las ordeno por lo que creo que más te conviene.

### 4.1. Las que yo pondría primero

#### MJ Rentabilidad — **el motor ya está en MJ ERP**

Para un importador es la información que más falta y la que nadie muestra.

> **Ojo, esto cambió.** Cuando se escribió esto, el costo no se guardaba en
> ningún lado. Hoy MJ ERP ya carga el costo en dólares de cada producto y
> calcula el margen (`MJERP_Costing`), y ya registra el descuento por medio de
> pago y el costo de envío de cada venta. O sea: **los datos ya están**.
>
> Lo que falta es la pantalla que los muestre junta: el tablero de qué deja
> más margen, y el aviso de "esto se está vendiendo por debajo del costo".
> Eso es una pantalla más dentro del ERP, no un plugin nuevo, y es
> bastante menos trabajo de lo que dice el "mediano" de acá abajo.

- Cargás el costo en dólares de cada producto, aparte del precio de venta.
- Ves el margen de cada producto, en porcentaje y en plata.
- Ves cuánto ganaste realmente en cada pedido, después del descuento por medio
  de pago y del costo de envío.
- Un tablero con los productos que más margen dejan, no solo los que más se venden.
- Alerta si un producto quedó vendiéndose por debajo del costo, cosa que pasa
  fácil cuando el dólar se mueve y no actualizaste.

Va de la mano con MJ Dólar: el costo también va en dólares, así que el margen se
mantiene correcto sin tocar nada.

**Tamaño:** mediano. **Valor:** muy alto.

#### MJ SEO

Tu tienda es rapidísima y se ve muy bien, pero está armada de una forma moderna
que Google entiende a medias. Hoy, si alguien busca un producto tuyo en Google,
es probable que no aparezca bien.

**Qué hace**

- Título y descripción propios para cada producto y categoría, editables por vos.
- La ficha que Google, Facebook y WhatsApp muestran al compartir un link, con foto,
  nombre y precio.
- Precio y disponibilidad marcados de forma que Google los muestre en el resultado.
- Mapa del sitio automático para que Google encuentre todos los productos.
- Archivo para el buscador de Google Shopping, si algún día querés publicar ahí.

**Por qué lo pongo tan arriba:** es la diferencia entre que te encuentren o que
solo te lleguen los que ya te conocen.

**Tamaño:** mediano. **Valor:** muy alto.

#### MJ Avisos de Stock

- Botón "Avisame cuando vuelva" en los productos sin stock.
- El cliente deja su email o WhatsApp.
- Cuando reponés, se avisa solo a todos los que preguntaron.
- Vos ves la lista de espera de cada producto: te dice qué reponer primero.

Es chico y se paga solo con la primera reposición.

**Tamaño:** chico. **Valor:** alto.

#### MJ Carrito Abandonado

- Detecta el que cargó productos y se fue sin comprar.
- Le manda un recordatorio a las horas que elijas.
- Podés incluir un cupón chico en el segundo recordatorio.
- Vos ves cuántos carritos se recuperaron y cuánta plata representó.

**Tamaño:** mediano. **Valor:** alto.

### 4.2. Para vender más

#### MJ Combos y Packs

- Armás combos de dos o más productos a un precio mejor.
- "Comprado junto con", con la sugerencia armada por vos.
- El combo descuenta stock de cada producto que lo compone.
- Sirve mucho para sacar productos que se mueven lento.

**Tamaño:** mediano.

#### MJ Reseñas con Fotos

- El cliente puede subir fotos con su opinión.
- Pedido automático de reseña unos días después de recibido.
- Vos aprobás antes de que se publiquen.
- Las mejores reseñas se destacan en la home.

WooCommerce ya tiene reseñas básicas. Esto agrega las fotos, que son las que
realmente venden.

**Tamaño:** chico a mediano.

#### MJ Fidelidad

- Puntos por compra que se canjean por descuento.
- Puntos extra por dejar reseña o por referir a un amigo.
- El cliente ve su saldo en Mi cuenta.

Honestamente, lo pondría último de este grupo. Suma, pero menos que los otros.

**Tamaño:** mediano.

### 4.3. Para trabajar más rápido

#### MJ WhatsApp

En Argentina es el canal real de venta, y hoy todo lo hacés a mano.

- Aviso automático al cliente cuando su pedido cambia de estado.
- Aviso a vos cuando entra una venta.
- Recordatorio de pago pendiente.
- Plantillas de mensajes editables por vos.

**Aviso honesto:** mandar mensajes automáticos por WhatsApp requiere una cuenta
de WhatsApp Business API, que es un servicio pago de Meta o de un intermediario.
Es el único plugin de la lista con un costo mensual inevitable. La versión gratis
sería abrir el chat con el mensaje ya escrito y que vos toques enviar, que es lo
que ya hace el tema hoy.

**Tamaño:** mediano.

#### MJ Proveedores y Reposición — **la mitad ya está en MJ ERP**

> **Ojo, esto cambió.** MJ ERP ya tiene compras y proveedores, con su propia
> tabla y su historial. Lo que falta es solo la parte de *sugerir* qué
> reponer, cruzando lo que se vendió con lo que hay en lista de espera.
> Extensión del ERP, no plugin nuevo.

- Cargás quién te provee cada producto y a qué costo.
- La tienda te arma la orden de compra de lo que falta reponer.
- Tiene en cuenta lo que se vendió en el último mes y lo que hay en lista de espera.
- Historial de compras por proveedor.

**Tamaño:** grande.

#### MJ Panel MJ

Un tablero único donde ves todo lo de MJ junto: el dólar del día, ventas de hoy,
pedidos esperando comprobante, productos sin stock con gente esperando, márgenes
de la semana. Hoy cada plugin tiene su pantalla; esto las junta en una sola.

Es chico y hace que todo el conjunto se sienta un producto y no cinco plugins
sueltos.

**Tamaño:** chico. **Valor:** alto en comodidad diaria.

### 4.4. Para el local físico

Estos tienen sentido si vendés también en un local, cosa que asumo por el medio
de pago en dólares en la tienda que mencionaste.

#### MJ Etiquetas y QR

- Imprimís etiquetas de precio para la vidriera, actualizadas al dólar del día.
- Cada etiqueta con un código QR que lleva al producto en la web.
- Varias etiquetas por hoja, listas para imprimir.

**Tamaño:** chico.

#### ~~MJ Punto de Venta~~ — **ya está hecho, en MJ ERP**

Esto se entregó dentro de MJ ERP 1.14 y no hace falta como plugin aparte:

- El mostrador de ventas: en la compu como un mostrador, en el celular en
  cinco pasos.
- Descuenta del mismo stock que la web, porque emite el pedido de WooCommerce.
- Cobra por medio de pago, y cada medio tiene su caja de ingreso.

Lo único que quedaría de la idea original es el **cierre de caja diario**, que
hoy no está como pantalla. Eso sí vale la pena, y es chico.

#### MJ Reservas y Señas — **hay piezas de esto ya hechas**

> **Ojo.** MJ Turnos Online ya cobra señas (con comprobante), y MJ ERP ya
> lleva cuenta corriente por cliente. Lo que falta de verdad es congelar el
> precio de un producto por unos días, que es la parte específica. Bastante
> menos de lo que parece.

- El cliente reserva un producto pagando una seña.
- El precio le queda congelado por los días que vos definas.
- El saldo lo paga al retirar.

Con precios en dólares, congelar el precio por una seña es un argumento de venta
fuerte.

**Tamaño:** mediano.

### 4.5. Lo que NO haría

Para que quede claro dónde no vale la pena gastar tiempo:

- **Copias de seguridad.** Hay plugins gratuitos y muy probados. Hacer uno propio
  es riesgo sin beneficio.
- **Seguridad y firewall.** Lo mismo. Es un campo donde conviene usar lo que ya
  está probado por miles de tiendas.
- **Buscador avanzado.** El buscador que ya tiene tu tema es rápido y funciona
  bien. Recién valdría la pena si pasás de mil productos.
- **Chat en vivo.** WhatsApp ya cumple ese rol y lo tenés integrado.

---

## 5. Orden que sugiero

Reordenado el 12 de septiembre de 2026, después de revisar qué cubre MJ ERP 1.14.

### Fase 0 — la red de seguridad *(en curso)*

Antes de una línea nueva de funcionalidad. No se ve en la tienda, y todo lo
demás depende de esto.

1. ~~Verificación automática: `php -l`, PHPCS y compilación en cada push.~~
   **Hecho.** Se corre sola en GitHub, y en la máquina con `.	oolserificar.ps1`.
2. ~~Cuaderno de errores compartido (`MJ_Log`).~~ **Hecho.** Lo que falla queda
   anotado en WooCommerce → Estado → Registros. Se regula en Ajustes → MJ · Registros.
3. **Probar en la tienda real MJ Dólar, MJ Medios de Pago y MJ Envíos.**
   Es lo único de esta fase que no se puede hacer desde el código.
   Paso a paso en `PRUEBAS-EN-LA-TIENDA.md`.

### Fase 1 — lo que mueve plata ahora

4. **SEO server-side, la parte barata.** Que la tienda emita título,
   descripción, Open Graph y JSON-LD en el `<head>`. Hoy mandar el link de un
   producto por WhatsApp muestra una caja vacía, sin foto ni precio: cada vez
   que compartís algo, perdés la venta que eso traería. Es un día de trabajo
   sobre `index.php` del tema, no el plugin entero de SEO.
5. **MJ Vencimiento de Pedidos y Comprobantes** (punto 3.1). Con precios en
   dólares, un pedido sin pagar abierto tres días es plata perdida.
6. **Tablero de rentabilidad dentro del ERP.** El costeo ya existe; falta la
   pantalla. Ver el punto 4.1.
7. **MJ Tiempos de Entrega** (punto 2). Chico, y saca consultas de encima.

### Fase 2 — lo que tarda por motivos ajenos

8. **MJ Facturador** (punto 2). Arrancar **ya** el trámite del certificado de
   ARCA y el punto de venta, aunque el código venga después: el trámite es lo
   lento, no el plugin.
9. **MJ Lista de Precios** (punto 3.2). Uso diario, y con MJ Dólar ya resuelto
   es directo.
10. **MJ Avisos de Stock** (punto 4.1). Chico y rendidor.

### Fase 3 — crecer

11. ~~**Actualizaciones automáticas.**~~ **Hecho, adelantado a pedido: MJ Core.**
    Cuando se publica una versión nueva, cada tienda que usa ese tema o plugin
    la pide como cualquier actualización de WordPress. Queda instalarlo una vez
    en cada tienda: paso a paso en `PUBLICAR.md`, sección 3.
12. **MJ Precios Mayoristas** (punto 3.3).
13. **MJ Carrito Abandonado** (punto 4.1).
14. **MJ Presupuestos** (punto 3.4).
15. **MJ Panel MJ** (punto 4.3). Ahora que son ocho plugins, tiene más sentido
    que cuando se escribió.

### Fase 4 — lo grande

16. **Cotización en vivo de envíos**, cuando existan las cuentas reales con los
    correos. Hasta entonces, tabla propia o precio fijo.
17. **Etiquetas de despacho** (lo que quedó pendiente del punto 3.5).
18. **Cierre de caja diario** en el ERP, y **Etiquetas y QR** si el local lo
    pide.

### Lo que saqué de la lista

- **MJ Punto de Venta.** Ya está hecho, dentro de MJ ERP.
- **MJ Proveedores y Reposición** como plugin. Es una pantalla del ERP.
- **MJ Fidelidad.** Ya estaba último y sigue último. Suma poco al lado del resto.

---

## 6. De dónde sale este orden

El análisis completo del estado del código —qué está bien, qué está flojo y por
qué este orden y no otro— está en [`ANALISIS.md`](ANALISIS.md).
