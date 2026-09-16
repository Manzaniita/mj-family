# Pruebas en la tienda real

Para MJ Dólar, MJ Medios de Pago, MJ Envíos y, de MJ ERP, la corrección de
ventas, las devoluciones con su RMA y el saldo a favor: todo eso todavía no se
probó con una tienda funcionando.

Esto es lo que no se puede hacer desde el código: todos tocan plata, stock o el
checkout, y ahí el único que sabe si está bien sos vos mirándolo.

---

## Antes de empezar

**Poné los registros en "Todo".** Ajustes → MJ · Registros → *Todo*. Así, si
algo falla en silencio, queda anotado y después se lee en WooCommerce → Estado
→ Registros, en los archivos que empiezan con `mj-`. Cuando termines de probar,
volvelo a *Solo los errores*.

**Probá con un producto de mentira.** Uno nuevo, precio redondo (100 USD),
stock 10, que después borrás. No uses uno que estés vendiendo.

**Anotá la hora de cada prueba.** Si algo sale mal, buscar en el registro por
hora es mucho más rápido que buscar por texto.

---

## 1. MJ Dólar

Lo que más importa: que el precio en pesos que ve el cliente sea el que vos
esperás, y que cambie cuando movés el tipo de cambio.

### 1.1. El precio sale bien

1. Cargá el tipo de cambio del día en WooCommerce → Dólar.
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

En WooCommerce → Dólar, pedí la cotización sugerida.

**Qué tiene que pasar:** trae un número de la fuente que elegiste.

**Si falla:** ahora queda anotado en el registro `mj-dolar` con el motivo y el
código HTTP. Antes no quedaba nada. Que falle no rompe la tienda —el tipo de
cambio lo ponés vos igual—, pero conviene saber si la fuente dejó de responder.

---

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

### 2.5. El link sin cuenta

Si tenés activados los links firmados, probá abrir uno desde otro navegador,
sin estar logueado.

**Qué tiene que pasar:** se puede subir el comprobante de ese pedido y **de
ninguno otro**. Probá cambiarle el número al link: tiene que rechazarlo.

---

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

### 3.8. Qué pasa si el correo no responde

Esto es lo más importante de todo, y se prueba fácil: poné mal la clave del
correo a propósito.

**Qué tiene que pasar:** el checkout **sigue funcionando** y ofrece los otros
métodos. El cliente no queda encerrado sin poder comprar.

**Señal de que algo está mal:** el checkout se cuelga, tarda diez segundos, o
no muestra ningún método de envío. Eso es una venta perdida cada vez que el
correo tenga un mal día.

---

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

## Cuando termines

1. Volvé los registros a **Solo los errores**.
2. Borrá el producto de prueba y los pedidos de prueba.
3. Anotá lo que salió mal. Si algo falló, el registro tiene el motivo: copialo
   entero, que es mucho más útil que "no anduvo".

---

## Lo que ya se verificó desde el código

Para que no lo pruebes de nuevo al pedo:

- Los 156 archivos PHP compilan, en PHP 7.4 y en 8.3.
- No hay sintaxis que rompa en un servidor con PHP 7.4.
- Las dos apps de React compilan, y lo compilado coincide con lo que está
  subido en `dist/`.
- No hay inyección de SQL en ninguna consulta.

Lo que **no** se puede verificar sin una tienda: que los números den bien, que
el checkout no se cuelgue, y que los correos respondan. Eso es esta lista.
