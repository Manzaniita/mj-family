# Plugins MJ — plan completo de la tienda

Actualizado el 4 de septiembre de 2026.

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
| **MJ Fotos Rápidas** | Subís las fotos de los productos desde el celular en dos toques y los publicás desde ahí mismo. | Ya lo tenías |
| **WC Image Optimizer Ilimitado** | Achica y convierte todas las imágenes de la tienda usando tu propio servidor, sin límites ni créditos. | Ya lo tenías |
| **MJ Colegas** | Productos de terceros dentro de la tienda: cada producto lleva quién lo hizo, vos cobrás y liquidás a cada colega descontando la comisión. Hecho para Yamana / Entre Hilos, pero sirve en cualquier tienda con el tema. Ver `YAMANA-ENTRE-HILOS.md`. | Entregado |

Además, el tema **MJ Appearance 3.4** sumó: logo configurable (con variante para modo noche), todos los textos fijos de cada pantalla editables desde Apariencia → MJ Appearance → Textos de la tienda, secciones de la portada que se pueden ocultar, cómo llamar a las "marcas", y términos y privacidad propios. Esto aplica también a MJ Importaciones: lo que no toques sigue igual que antes.

Los dos primeros todavía no los probaste en la tienda real. Antes de seguir
sumando conviene confirmar que andan bien.

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

#### MJ Rentabilidad

Para un importador es la información que más falta y la que nadie muestra.

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

#### MJ Proveedores y Reposición

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

#### MJ Punto de Venta

- Vendés desde el mostrador con la misma tienda, en una pantalla simple.
- Descuenta del mismo stock que la web, así nunca vendés algo que no tenés.
- Cobrás en pesos o en dólares, con el mismo tipo de cambio.
- Cierre de caja diario.

**Tamaño:** grande. Pero si vendés mucho en el local, cambia tu día a día.

#### MJ Reservas y Señas

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

### Ahora

1. Probar MJ Dólar y MJ Medios de Pago en la tienda real y corregir lo que aparezca.

### Etapa 1 — que la tienda pueda operar sola

2. **MJ Vencimiento de Pedidos y Comprobantes.** Deja de perder stock y ventas.
3. **MJ Tiempos de Entrega.** Chico, y te saca consultas de encima.
4. **MJ Facturador.** Es el trámite más lento, conviene arrancarlo temprano.

### Etapa 2 — que la tienda te haga ganar más

5. **MJ Rentabilidad.** Saber cuánto ganás de verdad.
6. **MJ Lista de Precios.** Uso diario.
7. **MJ SEO.** Que te encuentren.
8. **MJ Avisos de Stock.** Chico y rendidor.

### Etapa 3 — crecer

9. **MJ Precios Mayoristas.**
10. **MJ Carrito Abandonado.**
11. **MJ Presupuestos.**
12. **MJ Panel MJ.**

### Etapa 4 — lo grande

13. **MJ Envíos Argentina.**
14. Lo del local físico, si aplica: **Etiquetas y QR**, **Punto de Venta**, **Reservas**.
15. Lo demás: **Combos**, **Reseñas con Fotos**, **WhatsApp**, **Proveedores**, **Fidelidad**.

---

## 6. Cómo trabajamos cada uno

Para que sepas qué esperar en cada entrega:

1. Te hago las preguntas necesarias antes de empezar, con una recomendación en
   cada una para que puedas contestar rápido.
2. Escribo el plugin completo, no una parte.
3. Reviso que no tenga errores de código antes de entregarlo.
4. Te dejo el archivo zip listo para subir desde el panel de WordPress. Si el
   plugin toca el diseño de la tienda, también te dejo el zip del tema.
5. Te digo con claridad qué pude verificar y qué no, y te dejo una prueba corta
   para que confirmes que funciona.
6. Todo configurable desde el panel. Nada fijo en el código, nada que dependa de
   volver a llamarme para cambiar un texto o un número.

---

*MJ Importaciones — plan de plugins propios.*
