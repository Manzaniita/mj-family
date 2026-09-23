# Pruebas en la tienda real

Recorridos para validar en WordPress/WooCommerce los cambios que afectan
precios, pagos, stock, checkout, reservas y administración. La presencia de un
paso en este documento no significa que ya haya sido probado.

Estado: siguen pendientes los recorridos sin evidencia registrada. Una
verificación de código o un build no los marca como aprobados. Los números de
versión en los títulos indican cuándo se incorporó cada recorrido.

## Consulta por tema

Consultá la preparación y el recorrido del cambio; no repitas toda la guía
para una modificación aislada. Las integraciones pueden requerir más de uno.

- [Preparar una prueba y registrar evidencia](#preparacion)
- [Dólar y precios](#dolar)
- [Medios de pago y comprobantes](#pagos)
- [Envíos y direcciones](#envios)
- [ERP: corregir ventas](#erp-ventas)
- [ERP: devoluciones y saldo](#erp-devoluciones)
- [ERP: entregas parciales](#erp-entregas)
- [ERP: caja](#erp-caja)
- [ERP: reportes](#erp-reportes)
- [Tema: frenos y límites](#appearance)
- [Turnos: reserva completa y fidelidad](#turnos)
- [Envíos: integración con correos](#correos)
- [Envíos: restricciones por medio de pago](#envios-pagos)
- [Cerrar y registrar las pruebas](#cierre)

---

<a id="preparacion"></a>

## Antes de empezar

Elegí los recorridos afectados por el cambio y usá un entorno de prueba si
está disponible. En una tienda real, acordá antes el alcance: una reserva puede
mandar correos y una compra puede mover stock o plata. Los agentes no ejecutan
esas acciones por el solo hecho de leer esta guía.

Registrá en la sección probada: fecha, tienda/entorno, versiones instaladas,
caso, resultado esperado/obtenido y referencia a evidencia sin datos privados.

**Poné los registros en "Todo".** MJ → Registros → *Todo*. Así, si
algo falla en silencio, queda anotado y después se lee en WooCommerce → Estado
→ Registros, en los archivos que empiezan con `mj-`. Cuando termines de probar,
volvelo a *Solo los errores*.

**Probá con un producto de mentira.** Uno nuevo, precio redondo (100 USD),
stock 10, que después borrás. No uses uno que estés vendiendo.

**Anotá la hora de cada prueba.** Si algo sale mal, buscar en el registro por
hora es mucho más rápido que buscar por texto.

---

<a id="dolar"></a>

## 1. MJ Dólar

Lo que más importa: que el precio en pesos que ve el cliente sea el que vos
esperás, y que cambie cuando movés el tipo de cambio.

### 1.1. El precio sale bien

1. Cargá el tipo de cambio del día en MJ → Dólar.
2. En el producto de prueba, poné precio USD 100. Guardá.
3. Mirá la ficha del producto en la tienda.

**Qué tiene que pasar:** el precio en pesos es 100 × el tipo de cambio,
redondeado según el ajuste que elegiste.

**Señal de que algo está mal:** el precio sale en 0, sale sin redondear, o sale
el precio viejo en pesos que tenía el producto antes.

### 1.2. Cambiar el dólar mueve todo

1. Anotá el precio en pesos que muestra el producto.
2. Cambiá el tipo de cambio (subilo un 10%, para que se note).
3. Recargá la ficha del producto **sin esperar**.

**Qué tiene que pasar:** el precio nuevo aparece al toque. El recálculo masivo
corre en segundo plano, pero la ficha ya muestra el valor nuevo.

4. Esperá unos minutos y mirá la lista de productos en el admin.

**Qué tiene que pasar:** ahí también quedó el precio nuevo.

**Señal de que algo está mal:** la ficha muestra uno y el listado del admin
otro, y siguen distintos después de media hora. Eso quiere decir que el
recálculo se cortó: revisá el registro `mj-dolar`, que ahora anota cuando eso
pasa.

### 1.3. Las variaciones

Si tenés productos con variaciones (talles, colores), repetí 1.1 con uno.
**Es el caso que más se rompe**, porque el precio vive en cada variación.

### 1.4. Un producto sin precio en dólares

Agarrá un producto que tenga precio en pesos y **ningún** precio USD.

**Qué tiene que pasar:** sigue exactamente igual que siempre. MJ Dólar no lo
toca.

### 1.5. El pedido guarda el dólar del momento

Hacé una compra de prueba del producto de 100 USD. Después abrí el pedido en el
admin.

**Qué tiene que pasar:** el pedido guarda con qué tipo de cambio se hizo. Si
mañana cambia el dólar, ese pedido sigue mostrando el de ayer.

### 1.6. La cotización sugerida

En MJ → Dólar, pedí la cotización sugerida.

**Qué tiene que pasar:** trae un número de la fuente que elegiste.

**Si falla:** ahora queda anotado en el registro `mj-dolar` con el motivo y el
código HTTP. Antes no quedaba nada. Que falle no rompe la tienda —el tipo de
cambio lo ponés vos igual—, pero conviene saber si la fuente dejó de responder.

---

<a id="pagos"></a>

## 2. MJ Medios de Pago

Dos cosas distintas: el precio según el medio, y los comprobantes.

### 2.1. El descuento o recargo se ve en los tres lugares

Configurá un medio con un descuento bien visible (10%).

Mirá el precio en:

1. La ficha del producto.
2. El carrito.
3. El checkout, al elegir ese medio.

**Qué tiene que pasar:** los tres dicen lo mismo, y el total del checkout es el
que se cobra de verdad.

**Señal de que algo está mal:** la ficha promete un precio y el checkout cobra
otro. Es el error más caro de todos, porque el cliente ya decidió comprar.

### 2.2. Cambiar de medio en el checkout

En el checkout, cambiá de un medio con descuento a uno con recargo, y volvé.

**Qué tiene que pasar:** el total se actualiza cada vez, sin recargar la página
y sin quedarse pegado en el valor anterior.

### 2.3. El cliente sube un comprobante

1. Configurá que se pida comprobante en un medio (transferencia).
2. Comprá con ese medio, desde una ventana de incógnito.
3. Subí una foto desde Mi cuenta.

**Qué tiene que pasar:** se sube, el cliente ve que quedó pendiente de revisión,
y a vos te aparece en la bandeja.

**Probá también:** un archivo que no sea imagen ni PDF, y uno muy pesado.
Tienen que ser rechazados con un mensaje claro, no con un error genérico.

**Si algo falla:** ahora queda anotado en el registro `mj-medios-pago`. Antes,
si la base de datos rechazaba el comprobante, el cliente veía "no se pudo" y de
tu lado no quedaba nada.

### 2.4. Aprobar y rechazar

Desde la bandeja, aprobá uno y rechazá otro.

**Qué tiene que pasar:** el pedido cambia de estado, el cliente recibe el aviso,
y la decisión queda guardada (recargá la página para confirmarlo).

**Señal de que algo está mal:** aprobás, se ve aprobado, recargás y volvió a
pendiente. Eso ahora queda anotado en el registro.

### 2.4.1. Ver el archivo y aprobar con importe

1. Con un pedido de $ 28.600 y un comprobante subido, abrí WooCommerce →
   Comprobantes. La miniatura se tiene que ver, y "Ver" y "Descargar" tienen
   que abrir el archivo. Lo mismo desde Mi cuenta → el pedido, con el cliente.
2. Aprobá con **Pagó una parte: 10.000** y un mensaje ("Llegó la primera
   transferencia").

**Qué tiene que pasar:** el pedido sigue en espera, la bandeja muestra Pagado
$ 10.000 y Falta abonar $ 18.600, y el cliente ve en su pedido "Pago parcial",
el mensaje, lo pagado y lo que falta, y puede subir otro comprobante. Le llega
el mail del pago parcial con los importes.

3. Aprobá el segundo con **Pagó todo lo que falta**.

**Qué tiene que pasar:** el pedido pasa a Procesando, el cliente ve "Pago
confirmado" con los dos pagos en el historial, y en MJ ERP la venta queda
cobrada por el total (un solo cobro).

**Señal de que algo está mal:** "Ver" da un error de ruta o de permiso, o el
total cobrado en el ERP no coincide con el del pedido.

### 2.5. El link sin cuenta

Si tenés activados los links firmados, probá abrir uno desde otro navegador,
sin estar logueado.

**Qué tiene que pasar:** se puede subir el comprobante de ese pedido y **de
ninguno otro**. Probá cambiarle el número al link: tiene que rechazarlo.

### 2.6. Los datos para pagar

1. En MJ → Medios de pago, en la fila de Transferencia, entrá a
   **Editar textos y datos para pagar**. Cargá alias, CBU y titular, marcá
   "Copiar" en alias y CBU, y guardá.
2. Comprá con transferencia desde una ventana de incógnito.

**Qué tiene que pasar:** la confirmación del pedido muestra "Cómo pagar" con los
tres datos, y los botones copian el valor exacto (pegalo en una nota para
comprobarlo, también desde el celular). Los mismos datos llegan en el email del
pedido y en el link para subir el comprobante.

**Probá también:**

- Guardá la tabla de ajustes de arriba y volvé al editor: los datos siguen ahí.
  Antes, guardar la tabla borraba lo configurado aparte (la regla del
  comprobante de cada medio).
- Marcá "Mostrarlos también en el checkout": aparecen al elegir transferencia,
  y desaparecen al elegir otro medio.
- Aprobá el comprobante y abrí el pedido en Mi cuenta: los datos ya no se ven.

**Señal de que algo está mal:** el cliente ve los datos de otro medio, o un dato
viejo después de cambiarlo (vaciá la caché de la página y volvé a mirar).

---

<a id="envios"></a>

## 3. MJ Envíos

### 3.1. Antes que nada: cargá el origen

Sin el código postal de dónde despachás, ningún correo puede cotizar. El plugin
te avisa con un cartel si falta.

### 3.2. Un método que no depende de nadie

Empezá por **retiro en el local** o **precio fijo**, que no llaman a ningún
correo.

1. Creá el método y encendelo.
2. Andá al checkout con un producto en el carrito.

**Qué tiene que pasar:** aparece, con el precio que pusiste, y pide los datos
que configuraste.

### 3.3. El código postal

Probá en el checkout:

- Un código postal de tu zona propia.
- Uno de otra provincia.
- Uno que no existe (99999).
- El campo vacío.

**Qué tiene que pasar:** cada uno muestra los métodos que corresponden, y el
inválido da un mensaje claro en vez de romper el checkout o dejarlo colgado.

### 3.4. Las condiciones de cada método

Si configuraste que un método aparezca solo por encima de cierto monto, o solo
con ciertos medios de pago, probá los dos lados: el caso que lo muestra y el
que lo esconde.

### 3.5. El envío gratis

Poné el mínimo en un número que puedas superar con el producto de prueba.
Probá justo debajo y justo encima.

### 3.6. Los datos del cliente, con cuenta

1. Entrá con una cuenta que tenga nombre pero no teléfono. En el checkout, elegí
   **Retiro en el local**.

**Qué tiene que pasar:** el paso de envío avisa solo «Falta completar "Teléfono"».
Apenas escribís el teléfono en Contacto, el aviso se va (tarda un segundo).

2. Confirmá la compra sin calle.

**Qué tiene que pasar:** el pedido se hace. En **Mi cuenta → Direcciones** queda
guardado el teléfono, y la próxima compra arranca con los datos completos.

3. Repetí con **Envío a domicilio** y dejá la calle vacía.

**Qué tiene que pasar:** avisa que falta la calle y no deja pagar hasta cargarla.

### 3.7. Los correos en vivo — **con cuidado**

> **Esto no se probó nunca contra una cuenta real.** Vía Cargo, Andreani y
> Correo Argentino están escritos contra la documentación de cada uno, sin
> credenciales. Ninguna API se comporta exactamente como su documentación.

**No lo actives en la tienda que está vendiendo** hasta que:

1. Tengas cuenta de empresa con al menos uno de los tres.
2. Lo hayas probado en una tienda de prueba, o con el método apagado para los
   clientes.
3. Hayas visto que cotiza bien cinco códigos postales distintos, incluido uno
   del interior.

Mientras tanto: **tabla propia o precio fijo**. Cotizan igual de bien y no
dependen de que el servidor de nadie esté arriba.

**Cuando lo pruebes:** el registro `mj-envios` anota cada fallo con el motivo,
así que si algo no cotiza vas a poder ver si fue el usuario, la clave, un
tiempo de espera agotado o una respuesta rara.

### 3.8. El dato que exige el plugin de un correo

Con el plugin de Andreani instalado, WooCommerce exige un **DNI** en la dirección
del pedido. MJ Envíos lo adopta como un campo más.

1. En **MJ → Envíos → Datos del cliente**, buscá el campo con el nombre que le
   puso ese plugin (**DNI**). Tiene que estar en *Se pide, obligatorio* y avisar
   abajo quién lo pide.
2. Comprá con una cuenta que nunca lo haya cargado.

**Qué tiene que pasar:** el paso de envío lo pide, y el pedido se cierra. En el
pedido de wp-admin el DNI queda en la dirección, que es donde lo busca el plugin
del correo.

3. Comprá de nuevo con esa misma cuenta.

**Qué tiene que pasar:** el DNI viene completado; no hay que escribirlo otra vez.

4. Probá la **compra rápida** con una cuenta a la que le falte ese dato.

**Qué tiene que pasar:** el atajo no aparece (o se apaga solo) y se muestra el
formulario. Nunca tiene que dejar apretar «Comprar ahora» para después fallar.

**Señal de que algo está mal:** al confirmar, el checkout dice *«Parámetro(s) no
válido(s): shipping_address, billing_address»*. Eso es un campo obligatorio de
otro plugin que no está viajando con el pedido.

### 3.9. Qué pasa si el correo no responde

Esto es lo más importante de todo, y se prueba fácil: poné mal la clave del
correo a propósito.

**Qué tiene que pasar:** el checkout **sigue funcionando** y ofrece los otros
métodos. El cliente no queda encerrado sin poder comprar.

**Señal de que algo está mal:** el checkout se cuelga, tarda diez segundos, o
no muestra ningún método de envío. Eso es una venta perdida cada vez que el
correo tenga un mal día.

Probá también con el correo **instalado pero sin credenciales**, que es el caso
que rompía la tienda: el código postal tiene que seguir completando provincia y
localidad, y el carrito tiene que seguir calculando el envío con los otros
métodos. Un **error 500** en `wp-json/mje/v1/geo/cp` o en
`wp-json/wc/store/v1/cart/update-customer` es la señal de que un correo se está
llevando puesta la tienda.

Probá además agregar un producto desde su ficha, abrir el carrito y elegir un
envío: `/cart/add-item`, `/cart` y `/cart/extensions` deben seguir respondiendo
aunque un cotizador nativo lance el error de token. El método que falló no debe
aparecer como gratuito; los otros deben conservar sus tarifas.

### 3.10. Destino y selección estables

1. En la ficha, cotizá un CP y dejá elegido un método. Entrá al checkout: la
   preferencia debe aplicarse una sola vez, después de resolver ese destino.
2. Elegí otro método y esperá. Debe conservarse; cada selección propia manda
   una sola solicitud a `/cart/extensions`.
3. Con la red lenta, cambiá el CP antes de que termine la primera consulta.
   La respuesta vieja no debe reemplazar la provincia, localidad o tarifas nuevas.
4. Entrá con una cuenta guardada para otro CP: sus datos no deben mezclarse
   con el destino elegido en la ficha. Repetí con compra rápida activada.
5. Mientras se calcula, las opciones deben estar deshabilitadas. Cambiar de
   sucursal o destino sí debe actualizar las tarifas y el total.

Regresiones locales: `php tools/pruebas-envios.php` y `npm test` desde
`themes/mj-appearance/react-app`. Cubren excepciones del cotizador, selección
atómica, CP tardío y respuestas fuera de orden; no reemplazan esta prueba con
WordPress y las cuentas reales de los correos.

---

<a id="erp-ventas"></a>

## 4. MJ ERP: corregir una venta

Toca el stock y el pedido de WooCommerce, así que conviene verlo con ventas de
prueba antes de usarlo con clientes. Usá el producto de prueba (stock 10).

### 4.1. Una venta sin pedido

1. Cargá en el ERP una venta de 2 unidades, **destildando** «Emitir el pedido», y
   marcala entregada. El stock tiene que quedar en 8.
2. En el detalle, **Editar productos**: pasá la cantidad a 3 y guardá.

**Qué tiene que pasar:** el total sube, el stock queda en 7, la entrega vuelve a
«A entregar» y el **Historial** dice qué cambió y quién lo hizo.

3. Volvé a editar y dejala en 2.

**Qué tiene que pasar:** el stock vuelve a 8.

### 4.2. Una venta con pedido

1. Cargá una venta de 2 unidades **con** pedido y marcala entregada: se emite el
   pedido y el stock baja a 8.
2. Editá: 3 unidades, y agregá un segundo producto.

**Qué tiene que pasar:** el pedido en WooCommerce muestra las mismas líneas y el
mismo total que la venta, tiene la nota «Productos corregidos desde MJ ERP», y el
stock de cada producto bajó solo lo que se agregó.

3. Cancelá el pedido desde WooCommerce.

**Qué tiene que pasar:** vuelve **todo** el stock (el producto de prueba, a 10) y la
venta queda anulada.

**Señal de que algo está mal:** al cancelar, el stock no vuelve a 10. Quiere decir
que el pedido y lo descontado quedaron desparejos: anotá la venta y el pedido.

### 4.3. Lo que sale de la consignación de un revendedor

1. Entregale en consignación 2 unidades del producto de prueba a un revendedor de
   prueba.
2. Cargá en el ERP una venta de 1 unidad a ese revendedor, con pedido, y marcala
   entregada.

**Qué tiene que pasar:** el stock baja **una sola vez** (de 10 a 9).

3. Anulá la venta.

**Qué tiene que pasar:** el stock vuelve a 10 y al revendedor se le saca la deuda.

### 4.4. Lo que no se tiene que poder

- Registrá un cobro en una venta: el botón **Editar productos** desaparece y en su
  lugar dice por qué no se puede.
- Entrá con un usuario que use el ERP pero no esté en «Quién puede corregir
  ventas»: no ve el botón ni el Historial.

---

<a id="erp-devoluciones"></a>

## 5. MJ ERP: devoluciones, RMA y saldo a favor

Mueve plata, stock y el saldo de un cliente: probalo con un cliente de prueba que
tenga **cuenta en la tienda vinculada a su ficha**, y el producto de prueba (stock 10).

### 5.1. Devolución que queda a favor

1. Cargá en el ERP una venta de 2 unidades a ese cliente, con pedido, **cobrala
   entera** y marcala entregada. El stock queda en 8.
2. En el detalle, **Devolución o cambio**: vuelve 1 unidad, «Vuelve al stock»,
   motivo, y «Queda como saldo a favor».

**Qué tiene que pasar:** el total baja a una unidad, en Cobros aparece un renglón
«Devolución» en negativo, el stock queda en 9, el pedido de WooCommerce dice 1
unidad, y la ficha del cliente (Clientes → el nombre) tiene el saldo a favor por lo
que pagó esa unidad. Si la venta tenía descuento, el saldo es sin el descuento.

### 5.2. Fallado, con reemplazo

1. En la misma venta, **Devolución o cambio**: 1 unidad, «Vino fallado (RMA)», tildá
   «Le doy otro igual» y escribí el problema.

**Qué tiene que pasar:** la plata no cambia, el stock baja a 8 (sale el reemplazo, el
fallado no entra) y en **RMA** (menú) aparece abierto, con el proveedor de la última
compra de ese producto.

2. En RMA, **Baja**.

**Qué tiene que pasar:** «Pérdida por garantías · mes» sube por el costo de esa unidad.
Probá otro con **Al stock**: el stock sube 1 y no es pérdida.

### 5.3. Devuelve todo, sin llevarse nada

1. Cargá y cobrá una venta de 1 unidad a ese cliente, con pedido. Stock: baja 1.
2. **Devolución o cambio**: vuelve esa unidad, «Vino fallado (RMA)», **sin** tildar
   «Le doy otro igual» y sin agregar nada a cambio.

**Qué tiene que pasar:** calcula enseguida (no se queda en «Calculando…»), el total
nuevo es $ 0 y pregunta qué pasa con la plata. Con «Queda como saldo a favor»: la
venta queda sin productos y cobrada, el saldo del cliente sube por lo que pagó, el
fallado aparece en RMA, el stock **no** vuelve (está fallado) y el pedido de
WooCommerce queda sin productos. Repetilo con «Le di la plata» y «Vuelve al stock»:
el stock vuelve y en Cobros queda el renglón «Devolución».

### 5.4. Anular una venta cobrada

1. Cargá y cobrá otra venta a ese cliente. **Anular**.

**Qué tiene que pasar:** pregunta qué pasa con la plata. Con «Le di la plata» pedí
medio y caja; en Cobros queda el renglón «Devolución» y el saldo del cliente no
cambia.

### 5.5. El saldo en la tienda

1. Entrá a la tienda con la cuenta del cliente: **Mi cuenta → Resumen** muestra el
   saldo y sus movimientos.
2. Armá un carrito y andá al checkout.

**Qué tiene que pasar:** aparece «Usar mi saldo a favor», aplicado, y el total baja.
Destildalo: el total vuelve. Probá «¿Querés usar menos?» con la mitad.

3. Confirmá el pedido.

**Qué tiene que pasar:** en la ficha del cliente el saldo bajó lo usado; la venta del
pedido en el ERP tiene un cobro «Saldo a favor» y lo que falta lo cobra la pasarela.

4. Cancelá ese pedido.

**Qué tiene que pasar:** el saldo vuelve a como estaba, con un movimiento «Vuelve al
saldo».

**Señal de que algo está mal:** el saldo baja dos veces para el mismo pedido, o no
vuelve al cancelar. Anotá el pedido y el cliente.

### 5.6. Editar el saldo desde Clientes

1. En **Clientes**, en la fila del cliente de prueba, el botón **$**. Elegí «A favor»,
   $ 1.000, una venta suya en «Venta relacionada» y un motivo.

**Qué tiene que pasar:** la columna del saldo sube $ 1.000; en su ficha el movimiento
dice «Ajuste: el motivo» con el enlace a la venta, y el **Historial** de esa venta lo
tiene anotado.

2. Otra vez **$** → «En contra»: probá restar más de lo que tiene.

**Qué tiene que pasar:** no te deja (el saldo no queda en negativo). «Restar todo»
completa lo justo.

3. Cargale una venta **sin cobrar** y volvé a **$** → «Uso el saldo para pagar una
   venta».

**Qué tiene que pasar:** propone lo que debe (o lo que alcance el saldo). Al
guardar, la venta tiene un cobro «Saldo a favor», el «Debe» del cliente baja y el
saldo también.

### 5.7. El pedido sigue a la venta

1. Apenas actualizás: en WooCommerce → Pedidos, los que estaban «En espera» con la
   venta cobrada y entregada en el ERP (el #135, por ejemplo) pasan a «Completado».

**Qué tiene que pasar:** ningún cliente recibe mail por esos pedidos viejos, y los
que siguen sin cobrar quedan «En espera».

2. Cargá una venta sin cobrar, con pedido y «A entregar». Registrá el cobro.

**Qué tiene que pasar:** el pedido pasa a «Procesando». Marcá la entrega: pasa a
«Completado», y el cliente recibe el mail de pedido completado (si ese mail está
prendido en WooCommerce).

3. Hacé una compra de prueba en la tienda con transferencia y no la pagues.

**Qué tiene que pasar:** el pedido queda como lo deja WooCommerce («En espera» o
«Pendiente de pago»): el ERP no lo mueve hasta que registres el cobro.

---

<a id="erp-entregas"></a>

## 6. MJ ERP: entregar una parte

1. Cargá una venta con tres productos (uno con cantidad 2) y forma de entrega
   *Envío*.
2. En el detalle, *Entregar una parte o cambiar cómo*: marcá dos productos
   entregados, dejá el tercero en 0, elegí *Retira en el local* y anotá "Pasa el
   jueves".

**Qué tiene que pasar:** la venta queda *Entregado en parte*; en Productos, los
dos primeros dicen *Entregado* y el tercero *Falta entregar 1*; la tarjeta
Entrega muestra qué falta, la forma y la nota; el historial dice qué se llevó.
Si la regla es "se confirma al cobrar o entregar", el pedido de WooCommerce se
emite, pero no pasa a completado.

3. *Entregar lo que falta*.

**Qué tiene que pasar:** queda *Entregado*, con fecha, y el historial anota solo
el tercer producto. El pedido pasa a completado si ya estaba cobrada.

4. Probá también, en otra venta entregada: *Editar productos* agregando uno.

**Señal de que algo está mal:** lo que ya se había entregado vuelve a figurar
pendiente, o la venta no sale de la lista de entregas pendientes después de
entregar todo.

---

<a id="erp-caja"></a>

## 7. MJ ERP: Caja

Lee lo que ya está anotado en ventas, cobranzas, compras y reintegros: lo que
hay que comprobar es que el saldo de cada caja diga lo que vos sabés que hay.

1. En Proveedores → Cajas, una caja "Efectivo" en pesos. En Configuración →
   Ventas → Medios de pago, que "Efectivo" apunte a esa caja.
2. Cargá una venta de mostrador cobrada en efectivo por $ 10.000.

**Qué tiene que pasar:** en **Caja**, la tarjeta de Efectivo muestra $ 10.000, y
en el libro aparece "Cobro · VTA-…" con el nombre del cliente y el enlace a la
venta. En el inicio, la fila de cajas dice lo mismo.

3. **Egreso**: $ 1.500, tipo "Gasto", concepto "bolsas".

**Qué tiene que pasar:** el saldo baja a $ 8.500 y el renglón dice quién lo
cargó. Sin concepto, no deja guardar (si el ajuste "Pedir el concepto" está
prendido).

4. Una segunda caja "Banco" y **Transferir** $ 5.000 de Efectivo a Banco.

**Qué tiene que pasar:** Efectivo queda en $ 3.500 y Banco en $ 5.000, con un
renglón "Transferencia entre cajas" en cada una. Borrar uno de los dos borra los
dos.

5. En una venta cobrada, registrá una devolución con «Le di la plata» desde
   Efectivo.

**Qué tiene que pasar:** en Caja aparece "Devolución · VTA-…" en negativo y el
saldo baja.

6. **Cerrar caja** en Efectivo con un contado distinto al esperado ($ 3.000).

**Qué tiene que pasar:** el cierre queda con esperado, contado y la diferencia
en rojo; la tarjeta dice "Cerrada hoy"; un ingreso nuevo con fecha de hoy es
rechazado hasta deshacer el cierre. Deshacer el cierre solo lo puede quien
corrige ventas.

7. Entrá con un usuario que usa el ERP pero no corrige ventas.

**Qué tiene que pasar:** ve la Caja y puede anotar movimientos, pero no tiene el
tacho para borrar ni el botón de deshacer cierres. Tampoco ve «Anular» en una
venta ni el botón de borrar una venta anulada, y los datos de la venta (canal,
fecha, nota) se ven pero no se guardan.

**Señal de que algo está mal:** el saldo de Caja no coincide con la suma de los
cobros de ese medio en Ventas del mismo período.

---

<a id="erp-reportes"></a>

## 8. MJ ERP: Reportes

Todo sale del costo congelado en cada venta, así que usá el producto de prueba
con costo cargado (recibilo en una compra de prueba a US$ 60).

1. Vendé 2 unidades a US$ 100 y 1 unidad a US$ 50 (por debajo del costo).

**Qué tiene que pasar:** en **Reportes**, Vendido US$ 250, Costo US$ 180,
Ganancia US$ 70; "Vendido bajo costo: 1". En *Por producto*, el producto sale
marcado en rojo con "Vendido por debajo del costo"; en *Bajo costo*, la línea de
US$ 50 con la venta y la pérdida de US$ 10. En el inicio aparece el aviso con
el enlace, y el menú Reportes lleva el globo con "1".

2. Anulá la venta de US$ 50.

**Qué tiene que pasar:** desaparece de todos lados: las anuladas no cuentan.

3. Cambiá el período a "Mes pasado" y volvé a "Este mes"; probá *Por canal*,
   *Por mes* y *Por cliente*; exportá el CSV.

**Qué tiene que pasar:** los totales cambian con el período, cada pestaña
muestra las mismas cifras agrupadas de otra forma, y el CSV abre en Excel con
punto y coma y los importes con coma.

4. Apagá "Mostrar Reportes en el menú" en Configuración → Caja y reportes.

**Qué tiene que pasar:** el menú lo esconde al guardar, sin recargar.

---

<a id="appearance"></a>

## 9. MJ Appearance: los frenos

1. Desde incógnito, en `/login`, probá una contraseña mal 11 veces seguidas
   (el tope por defecto es 10 cada 10 minutos).

**Qué tiene que pasar:** el intento 11 contesta "Demasiados intentos. Esperá
unos minutos". Con la contraseña correcta antes del tope, entra y el contador
se limpia.

2. En Contacto, mandá 6 mensajes seguidos.

**Qué tiene que pasar:** el sexto dice "Demasiados intentos"; los cinco
anteriores llegan al email de contacto.

3. Abrí `/wp-json/mj/v1/catalog-stock` en el navegador.

**Qué tiene que pasar:** lista `id` y `sku` de cada producto, sin cantidades.
Con "Stock en el buscador" marcado en MJ → MJ Appearance → Sesión y
acceso, aparece `stock_quantity` y el buscador de la tienda dice "12 en stock".
Cambiá el stock de un producto: el listado se actualiza al toque (la caché se
vacía sola).

**Señal de que algo está mal:** el freno salta con menos intentos de los
configurados. Si el hosting está detrás de Cloudflare o un proxy, todas las
visitas comparten la IP: ver el filtro `mj_client_ip` en `CONFIGURACION.md`.

<a id="turnos"></a>

## 10. MJ Turnos Online 1.3

Primero en una tienda de prueba (o en la de Jaz antes de abrirla). En
GlossyHair, con los ajustes de siempre, **nada de esto tiene que cambiar**:
la prueba ahí es reservar un turno como siempre y ver que todo sigue igual.

Preparación: un servicio de 60 minutos con precio 10.000; *Reservas y seña*
con porcentaje 50 y plazo 1 hora; *Cambios y cancelaciones* con cancelar y
reprogramar a 48 h, "saldo a favor" prendido, "reprogramar tarde: pierde la
seña y abona una nueva", "ofrecer opciones" prendido; *Cuentas* obligatoria;
*Opiniones* prendidas con espera 0; *Notificaciones* con segundo
recordatorio y aviso de mantenimiento. Un cron real del hosting ayuda.

### 10.1. Reserva con cuenta y seña del 50 %

1. En incógnito, reservá el servicio: el resumen dice seña 5.000. En el paso
   de datos pide contraseña.

**Qué tiene que pasar:** el turno queda "Esperando seña", se crea la cuenta
y la sesión queda iniciada. En *Mi cuenta → Mis turnos* aparece solo ese
turno. Con otra cuenta no se ve.

2. Esperá una hora sin subir comprobante.

**Qué tiene que pasar:** el turno se cancela, llega el mail, y el horario
vuelve a aparecer libre al consultar (aunque la tarea programada no haya
corrido todavía).

### 10.2. Comprobante

1. Reservá de nuevo y subí el comprobante desde la página del turno.

**Qué tiene que pasar:** te llega el aviso; el turno ya no vence. Aprobalo
desde la bandeja: queda "Confirmado" y a la clienta le llega "¡Tu turno está
confirmado!". Probá también rechazar uno: el plazo vuelve a correr.

### 10.3. Reprogramar y cancelar según la anticipación

1. Con un turno confirmado a más de 48 h: *Reprogramar*.
   **Qué tiene que pasar:** antes de confirmar dice que la seña se conserva;
   el turno se mueve y conserva la seña. Te llega el aviso.
2. Con un turno confirmado a menos de 48 h: *Reprogramar*.
   **Qué tiene que pasar:** avisa que la seña se pierde; el turno viejo queda
   cancelado con la seña perdida y el nuevo espera una seña nueva.
3. Cancelar a tiempo.
   **Qué tiene que pasar:** la seña pasa a saldo a favor (se ve en Mis turnos
   y en su ficha del panel). En la reserva siguiente se usa sola: si alcanza,
   el turno queda confirmado sin transferir.

### 10.4. Cancelás vos

1. Desde el panel, cancelá un turno con seña pagada tildando "Ofrecerle
   opciones".

**Qué tiene que pasar:** a la clienta le llega el mail; en su turno elige
otro día (queda confirmado, conserva la seña), la devolución (te llega el
aviso; en el panel aparece "Marcar devuelta") o saldo a favor.

### 10.5. Mantenimiento, recordatorios y opinión

1. Poné "Mantenimiento: cada 4 semanas" en el servicio y marcá un turno como
   atendido.
   **Qué tiene que pasar:** en la ficha, la fecha de mantenimiento es 4 semanas
   después; dos días antes llega el aviso (probá cambiando la fecha a mano).
2. Con un turno confirmado para dentro de 23 horas y otro para dentro de 50
   minutos.
   **Qué tiene que pasar:** el primero recibe el recordatorio de 24 h y el
   segundo solo el de "una hora antes".
3. Tras marcar atendido, llega el pedido de opinión.
   **Qué tiene que pasar:** la opinión se publica sola (o espera revisión con
   puntaje bajo) y aparece en el panel, en Más → Opiniones.

**Señal de que algo está mal:** un horario ocupado aparece como libre, una
clienta ve un turno que no es suyo, o un mail sale dos veces.

---

<a id="correos"></a>

## 11. MJ Envíos 1.4: el puente con los correos y la lista en dos niveles

Solo en **MJ Importaciones**, que es la única tienda con cuenta de un correo.
Esto toca el checkout: probalo antes de tocar las otras tiendas.

Antes de empezar: en **MJ → Envíos → Correos → Conectados por su
plugin** tiene que decir **conectado** y mostrar el titular y el CUIT de la
cuenta. Si dice *fuera de las zonas*, agregá su método a la zona con el botón
que está ahí mismo y volvé a mirar.

0. **Antes de nada: que los correos no tengan pendientes.** Andá a *Envíos →
   Correos → Conectados por su plugin*.
   **Qué tiene que pasar:** cada correo dice CONECTADO, no «le falta
   configuración» ni «fuera de las zonas». Si Correo Argentino dice que le
   faltan los datos del comercio, completalos **dentro del método de la zona**
   (WooCommerce → Ajustes → Envío → la zona → Correo Argentino), no solo en su
   pantalla principal: cotiza con los de la instancia.

1. **El correo cotiza y aparece una sola vez.** Poné un producto con peso en el
   carrito y andá al checkout con un código postal de otra provincia.
   **Qué tiene que pasar:** los envíos de los correos **no** están sueltos: hay
   una sola opción con el nombre de tu zona (*Envío a todo el país*) que dice
   «desde $X» y cuántas opciones tiene. Al elegirla se abren las tarjetas, cada
   una con el correo arriba (Andreani, Correo Argentino), el modo de entrega, el
   plazo y el precio. Ninguna aparece dos veces.
2. **El grupo se renombra.** Andá a *Envíos → Métodos → Grupos* y cambiale el
   nombre, la descripción y el emoji.
   **Qué tiene que pasar:** el checkout lo dice con el nombre nuevo. Sacá un
   método del grupo desde su ficha: vuelve a verse suelto en la lista.
3. **Los métodos se crearon solos.** Mirá **Envíos → Métodos**.
   **Qué tiene que pasar:** hay un método por cada servicio que cotizó Andreani,
   encendido, con el nombre que le puso el correo. Cambiale el nombre y el orden
   a uno y volvé al checkout: tiene que salir con el nombre nuevo, en el lugar
   nuevo, y con el mismo precio.
4. **Tus reglas mandan sobre el precio del correo.** En *Ajustes → Precio*
   ponele 10 % de margen y guardá. Recargá el checkout.
   **Qué tiene que pasar:** el envío de Andreani sale 10 % más caro. Volvelo a 0
   antes de seguir.
5. **El envío gratis alcanza al correo.** Prendé *Envío gratis por monto* con un
   número por debajo de tu carrito.
   **Qué tiene que pasar:** el envío de Andreani dice GRATIS. Apagalo de nuevo.
6. **Los carteles.** Con dos o más envíos a distinto precio.
   **Qué tiene que pasar:** uno solo dice MÁS BARATO y uno solo MÁS RÁPIDO. Si
   hay empate, no aparece el cartel.
7. **"A coordinar" dice que se coordina.** Encendé un método a coordinar.
   **Qué tiene que pasar:** en la lista dice el texto de a coordinar, **no**
   GRATIS.
8. **La sucursal.** Elegí un envío a sucursal.
   **Qué tiene que pasar:** el desplegable trae sucursales del código postal
   cargado, las más cercanas primero. Cambiá de sucursal: el precio se recalcula
   si el correo cobra distinto.
9. **El código postal completa solo.** Escribí un CP que no hayas usado antes.
   **Qué tiene que pasar:** se completan provincia y localidad, y las podés
   corregir a mano sin que se vuelvan a pisar.
10. **El cotizador de la ficha.** Entrá a un producto sin nada en el carrito y
   poné tu código postal.
   **Qué tiene que pasar:** salen las mismas opciones y los mismos precios que
   en el checkout. Recargá la página: el código postal quedó cargado.
   Después, con algo en el carrito y con la sesión iniciada, recargá la ficha
   dos o tres veces: Andreani y Correo Argentino tienen que seguir apareciendo
   con precio, nunca con «no devolvió ninguna tarifa». En el checkout, con esos
   correos cotizando, tampoco tiene que aparecer ese cartel.
11. **El pedido que el correo reconoce.** Comprá con un envío de Andreani y
   abrí el pedido en **su** pantalla (menú Andreani).
   **Qué tiene que pasar:** el pedido figura ahí con el modo de entrega
   correcto y, si era a sucursal, con la sucursal elegida. Desde ahí tenés que
   poder darle el alta como siempre.
12. **La pantalla de envíos.** Andá a **Envíos → Envíos**.
   **Qué tiene que pasar:** el pedido está en *Pendientes de despacho*, con el
   correo, el destino y los días que lleva. Cargale el número de seguimiento
   con el aviso al cliente prendido y guardá: sale de la lista, al cliente le
   llega el mail y en Mi cuenta ve el link para rastrearlo.
13. **Sin el plugin del correo, nada se rompe.** Desactivá el plugin de Andreani
   un minuto y mirá el checkout.
   **Qué tiene que pasar:** sus envíos dejan de ofrecerse (o caen al respaldo
   que elegiste en *Si el correo no responde*), pero los tuyos siguen igual y
   el panel no tira ningún error. Volvé a activarlo.

**Señal de que algo está mal:** el mismo envío listado dos veces, un envío de
Andreani que sale $0 sin que hayas puesto envío gratis, un pedido que el panel
de Andreani no reconoce, o el checkout que se queda pensando al elegir sucursal.

<a id="envios-pagos"></a>

## 12. MJ Envíos 1.10: el envío que pide cierta forma de pago

En **MJ Importaciones**. Necesitás un método con *Medios de pago → Solo con
estos* y un solo medio marcado (por ejemplo, transferencia).

1. **Se ofrece igual, con el cartel.** En el checkout elegí **otro** medio de
   pago (efectivo) y mirá la lista de envíos.
   **Qué tiene que pasar:** el envío está, se puede elegir, y abajo del nombre
   dice con qué se puede pagar. **No** puede pasar que la opción se marque y
   vuelva sola al envío anterior: ese era el problema que esto arregla.
2. **Elegirlo recorta el pago.** Elegí ese envío y bajá al paso de pago.
   **Qué tiene que pasar:** quedan solo los medios que acepta, con el aviso
   arriba explicando por qué, y el que estaba elegido cambió solo al permitido.
   Los precios de la tienda acompañan el cambio.
3. **Volver atrás devuelve los medios.** Elegí de nuevo un envío sin regla (el
   retiro en el local).
   **Qué tiene que pasar:** vuelven todos los medios de pago, sin recargar la
   página. Recargá igual: siguen todos.
4. **El comportamiento viejo sigue disponible.** En *Ajustes → General → Envío y
   medio de pago que no combinan* elegí *No ofrecer ese envío* y volvé al
   checkout con el medio que no corresponde.
   **Qué tiene que pasar:** ese envío no aparece. Volvelo a *Mostrarlo igual y
   avisar* antes de seguir.
5. **Dejar elegido desde la ficha.** Con *Ajustes → Textos → Cotizador →
   Dejar elegido* prendido, entrá a un producto, cotizá tu código postal y tocá
   una opción.
   **Qué tiene que pasar:** queda marcada y aparece la confirmación. Agregá el
   producto al carrito y andá al checkout: el código postal ya está puesto (y
   **no** cambió la dirección de facturación de tu cuenta) y ese envío está
   elegido. Cambiá el envío a mano: no se vuelve a cambiar solo.
6. **La foto acompaña.** En la ficha de un producto, en una pantalla ancha,
   scrolleá hasta la descripción.
   **Qué tiene que pasar:** la foto baja con el panel de compra en vez de dejar
   la mitad izquierda en blanco, y se suelta al llegar a la descripción. En una
   pantalla baja tiene que entrar entera, con sus miniaturas, sin quedar cortada.
7. **Se lee.** Mirá el checkout y la ficha en modo día y en modo noche.
   **Qué tiene que pasar:** las etiquetas («QUIÉN RECIBE»), los plazos y las
   ayudas se leen sin esforzar la vista, y los bordes de las cajas se ven. El
   desplegable de sucursales tiene el texto sobre un fondo opaco, no marfil
   sobre blanco. Si algo sigue flojo, subí *MJ Appearance → Diseño y marca →
   Fuerza del texto secundario*.

**Señal de que algo está mal:** una opción de envío que no se deja marcar, un
medio de pago que desapareció y no vuelve, o un pedido que WooCommerce rechaza
por «medio de pago no disponible».

<a id="cierre"></a>

## Cuando termines

1. Volvé los registros a **Solo los errores**.
2. Borrá el producto de prueba y los pedidos de prueba.
3. Anotá el resultado y el fragmento relevante del registro, con la hora.
   Quitá tokens, datos personales y datos de pago antes de compartirlo.
4. Actualizá el pendiente correspondiente de `AUDITORIA.md` solo si el
   recorrido quedó realmente validado; registrá los fallos que continúan.

---

## Qué cubre la verificación automática

`tools/verificar.ps1` revisa sintaxis, compatibilidad PHP, reglas estáticas,
compilación (salvo `-Rapido`), copias compartidas y documentación. El resultado
corresponde a los archivos y al entorno de esa ejecución.

PHPCS no demuestra ausencia de vulnerabilidades. Un build no comprueba los
importes, permisos, stock, entregas de correo ni respuestas de proveedores.
Para esos comportamientos se usan los recorridos anteriores.
