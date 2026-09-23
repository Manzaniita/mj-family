# MJ Envíos

Los envíos de la tienda, armados por vos. Cada forma de recibir la compra
—a domicilio, a una sucursal, retiro en el local, tu propio reparto por zona,
envío gratis o "lo arreglamos después"— es un **método** que creás, editás,
ordenás, apagás o borrás desde el panel. Ninguno viene fijo en el código.

Se configura en **MJ → Envíos**.

Si el plugin de un correo falla al cotizar (por ejemplo, por un token vencido),
el carrito sigue calculando los demás métodos y muestra un aviso. La conexión
se corrige en el plugin del correo; el detalle del fallo queda en el registro.
Elegir un envío en el checkout guarda el método y sus datos en una sola operación.
La preferencia de la ficha se aplica después de resolver su código postal;
las respuestas de un código postal anterior se descartan.

> Activar el plugin no cambia nada en la tienda: viene con tres métodos de
> ejemplo **apagados**, para que veas de qué se trata. Hasta que enciendas uno,
> la tienda sigue cobrando el envío como lo hacía antes.

---

## Lo primero: desde dónde despachás

Pestaña **Ajustes**, bloque "Desde dónde despachás". El código postal es
obligatorio: sin eso ningún correo puede cotizar. Lo demás (dirección,
localidad, teléfono) se usa para armar el envío y para el retiro en el local.

---

## Crear un método

Pestaña **Métodos → Crear un método**. Lo único que hay que decidir de entrada
son dos cosas:

**De dónde sale el precio** (el tipo):

| Tipo | Cuándo lo usás |
| --- | --- |
| **Tabla propia** | Tenés tus propios precios por zona y peso. Cargás filas: "CP 1000-1999 hasta 5 kg, $8.000". |
| **Precio fijo** | Un solo precio para todo el país. |
| **Cotización en vivo** | El precio lo pone Vía Cargo, Andreani o Correo Argentino en el momento, con las credenciales que cargás acá. |
| **Correo conectado por su propio plugin** | El precio lo pone el plugin oficial del correo, con la cuenta que ya tenés conectada ahí. Aparece solo si hay uno instalado. |
| **Gratis** | Sin costo, siempre. |
| **Retiro en el local** | Pasa a buscarlo. No pide dirección de entrega. |
| **A coordinar** | Se ofrece sin precio y lo arreglás después de la compra. |

**A dónde entrega** (el destino): a domicilio, a sucursal del correo, o se
retira en el local. Esto es lo que decide qué datos se le piden al cliente: una
entrega a domicilio pide calle y número, una a sucursal pide la sucursal, y un
retiro no pide dirección.

Todo lo demás es opcional.

---

## Cuándo aparece cada método

En la ficha del método, bloque **Cuándo aparece**. Lo que dejás vacío o en 0 no
filtra nada. Podés combinar:

- Provincias, localidades y códigos postales (sueltos, en rango `1000-1999` o
  con comodín `58*`), en lista blanca o negra.
- Monto del carrito, peso, cantidad de productos: desde y hasta.
- Productos y categorías puntuales: "solo con estos" o "nunca con estos".
- Quién compra: cualquiera, solo con cuenta, solo sin cuenta, o ciertos roles.
- **Medios de pago.** Si decís que un envío es "solo con efectivo", elegirlo
  deja el checkout solo con efectivo. Así se arma, por ejemplo, un reparto
  propio que se paga contra entrega.

  Al cliente que venía con otro medio elegido el envío se le ofrece igual, con
  el cartel de con qué se paga, y el medio se le cambia solo al elegirlo.
  Esconderlo dejaba la regla peleándose consigo misma —el envío desaparecía por
  el medio y el medio desaparecería por el envío— y lo que veía era una opción
  que al tocarla volvía sola para atrás. Si aun así lo preferís escondido, está
  en *Ajustes → General → Envío y medio de pago que no combinan*.

---

## Qué datos se le piden al cliente

Pestaña **Datos del cliente**: el ajuste general. Nombre, teléfono, DNI, calle,
piso, entre calles, quién recibe, horario, aclaraciones, y los **campos propios**
que agregues vos (texto, lista, casilla, texto largo). De cada uno elegís si se
pide, si es obligatorio, cómo se llama, qué ayuda muestra y en qué orden aparece.

Cada método puede pedir más o menos desde su propia ficha. Lo que queda en
**Heredar** sigue al general: si mañana subís una regla general, alcanza a todos
los métodos que nunca tocaste a mano.

**Lo que el cliente ya cargó cuenta.** El nombre, el teléfono, el email y la
dirección se escriben en el paso de contacto y en el bloque de dirección de la
tienda, y viajan con los datos del envío. Al confirmar vale también lo que trae
el pedido, y lo que el cliente tiene guardado en su cuenta completa lo que la
tienda no mandó (si lo mandó vacío, es que lo borró). Así nunca aparece
«Falta completar "Nombre"» con el nombre escrito.

**WooCommerce pide la dirección que pide el método.** WooCommerce exige calle,
localidad, provincia y código postal en todo pedido con envío, aunque se retire
en el local. El plugin afloja esos campos cuando el método elegido no los pide
como obligatorios: el retiro en el local se confirma sin calle. Nunca exige algo
que el método no pide, y en wp-admin la ficha del pedido sigue como siempre.

Los dos se pueden cambiar con filtros: `mje_fill_from_account` (en `false`, la
cuenta no completa nada), `mje_known_fields` (qué datos se completan) y
`mje_relaxed_address_fields` (qué campos de dirección se aflojan; `[]` deja que
WooCommerce exija los suyos siempre). Los nombres de los datos que se ven en
**Mi cuenta → Direcciones** de la tienda son los de esta pestaña.

Lo que el cliente carga al comprar queda guardado en su cuenta (lo hace
WooCommerce) y la próxima compra arranca completa; lo corrige en **Mi cuenta →
Direcciones**.

### Los datos que exige el plugin de un correo

El plugin de un correo puede agregarle campos propios a la dirección del pedido:
el de Andreani, por ejemplo, agrega un **DNI obligatorio**. Son suyos, pero
WooCommerce los exige al cerrar la compra, y sin ellos el pedido se cae con
«Parámetro no válido: billing_address».

Para el documento hay un solo campo: **DNI o CUIT de quien recibe** (`doc`).
No se muestra una segunda fila `x_andreani_dni`: ese mismo valor completa los
campos de DNI/CUIT registrados por Andreani, Correo Argentino u otro plugin,
con las claves que cada uno espera. También se recuperan los documentos de
cuentas y sesiones anteriores. El valor actual de `doc` tiene prioridad.

Los otros datos del correo siguen apareciendo como campos separados. El tipo
de documento, si un plugin lo pide, es distinto del número y se conserva.
Una integración con un nombre de campo especial puede mapearlo a `doc` usando
el filtro `mje_extra_field_source`.

Si ponés uno de esos campos en **No se pide** y su plugin lo exige, ningún pedido
se va a poder cerrar. Configurá `doc` como obligatorio cuando un correo lo exija.

---

## Código postal, provincia y localidad

El cliente pone el código postal y la tienda completa provincia y localidad
sola. De dónde sale ese dato lo elegís en **Ajustes → Código postal**:

- **La tabla propia del plugin.** Instantánea, sin internet y sin credenciales.
  Se carga importando un CSV en la pestaña **Códigos postales** (columnas: CP,
  localidad, provincia) y **se va llenando sola**: todo lo que se resuelve, y
  todo lo que el cliente elige a mano, queda guardado para la próxima.
- **La API pública del Estado** (Georef, apis.datos.gob.ar). Gratis y sin
  credenciales. Es la que hace andar el autocompletado de localidad. Aviso
  honesto: Georef no publica códigos postales, así que un CP que todavía no esté
  en la tabla propia se completa eligiendo la localidad — y ahí queda aprendido.
- **El correo conectado**, si su API resuelve códigos postales.

Las provincias salen de WooCommerce (el país base de la tienda), no de una lista
escrita en el plugin.

---

## Correos que ya tienen su propio plugin

Andreani y Correo Argentino tienen plugin oficial. Si está instalado y
conectado, **no hace falta cargar la credencial dos veces**: MJ Envíos usa esa
conexión, deja que ese plugin calcule su tarifa y le aplica encima tus reglas
(envío gratis, excluidos, margen, redondeo, nombre, orden). Aparece en
**Correos → Conectados por su plugin**, con el titular, el CUIT y en qué zonas
está su método.

Lo único que tiene que estar prendido es la zona: el plugin del correo cotiza
cuando su método está en una zona de envío de WooCommerce. Si está conectado pero
fuera de todas las zonas, no cotiza nunca — el panel lo avisa y tiene el botón
para agregarlo ahí mismo.

Cada servicio suyo que cotiza **queda como un método propio, editable**, sin
cargarlo a mano (se apaga en *Ajustes → General → Adopción automática*). Y el
pedido sale escrito también en las claves que usa ese plugin, así lo ves en su
propia pantalla de envíos y le podés dar el alta o imprimir el rótulo desde ahí.

> Instalar o desactivar el plugin de un correo no rompe nada: si no está, MJ
> Envíos ni lo nombra y sigue funcionando igual.

---

## Un correo caído no rompe la tienda

Los plugins de los correos son ajenos y fallan: uno instalado **sin credenciales
cargadas** contesta «access token no válido o indefinido» a cualquier cosa que se
le pregunte. MJ Envíos le pregunta siempre con red: lo que falle queda anotado en
el registro `mj-envios` con el nombre del correo y el motivo, y la tienda sigue
con lo que sabe. El código postal se completa igual, el carrito calcula igual y
el checkout ofrece los otros métodos.

---

## Cuando el correo devuelve $0

Varios plugins de correo, cuando no pueden cotizar, en vez de no devolver nada
devuelven una tarifa de **$0** con el motivo en el nombre. MJ Envíos las
reconoce y las ignora, y además nunca ofrece un envío de correo en $0: casi
siempre es "no pude cotizar" disfrazado de regalo. Si de verdad tenés un acuerdo
sin cargo, se habilita en *Ajustes → General → Precio $0 del correo*.

El motivo se muestra en la ficha del correo, en el checkout y en el cotizador.

---

## Correos sin plugin propio

Pestaña **Correos → Configurados acá**. Cada uno pide sus datos: URL de la API,
usuario, clave, número de cliente, contratos. **Un correo sin credenciales queda
apagado**, y los métodos que lo usan cotizan con su tabla propia. Una clave
guardada no se vuelve a mostrar: dejar el campo vacío la conserva.

Si la API no responde, hace lo que digas en **Ajustes → Si el correo no
responde**: cotizar con la tabla propia (lo recomendado), ofrecerlo a coordinar,
o no ofrecerlo.

Las cotizaciones se guardan las horas que elijas: el mismo destino y peso no se
pregunta dos veces. Las sucursales se traen con un botón y quedan guardadas, así
el checkout nunca depende de que el correo esté funcionando.

**Vía Cargo** es el más flexible de los tres a propósito: no publica su API, así
que se configura la URL, cómo se autentica y —si hace falta— el cuerpo exacto del
pedido con marcas (`{cp_destino}`, `{peso}`, `{valor}`). El día que te pasen el
contrato de su API, se completa desde el panel sin tocar código.

---

## Peso

Se usa el peso cargado en cada producto. Para lo que no lo tenga hay tres redes,
en este orden: el **peso por defecto del producto** (campo nuevo en la pestaña
Envío del producto), el de su **categoría**, y el **general** de los ajustes.
También podés sumar el peso del embalaje, poner un piso, partir en bultos arriba
de cierto peso y usar peso volumétrico.

---

## Precio final

Sobre el costo del correo (o de la tabla) se aplica, en este orden: margen en
porcentaje, margen fijo, seguro sobre el valor declarado, precio mínimo y
redondeo. Todo se puede definir general y pisar método por método.

**Envío gratis:** se activa por monto en los ajustes, y cada método decide si
puede quedar gratis. El número que pongas ahí es el que anuncia la barra de
"te faltan $X para el envío gratis" de la tienda.

---

## Sucursales

Se traen una vez desde **Correos → Sucursales** y quedan guardadas: el checkout
nunca depende de que el correo esté arriba. Los correos las publican por zona,
así que se piden con un código postal.

Al cliente se le ofrecen **las más cercanas primero**: por distancia real cuando
el correo publica las coordenadas de sus sucursales, y si no, primero las de su
código postal y después las de su localidad. Se cambia en *Ajustes →
Cotización → Sucursales*, junto con cuántas mostrar.

---

## Grupos: que la lista no se llene de opciones sueltas

Un correo conectado trae varios servicios ("estándar", "sucursal", "expreso"), y
si cada uno es una fila más el cliente no sabe qué está comparando. Un **grupo**
los junta abajo de un solo título y los ordena en tres niveles: el cliente ve
*Envío a todo el país* (y con qué correos se puede mandar), lo elige, y ahí se
le abre **una tarjeta por correo** con los modos que ofrece ese correo, cada uno
con su plazo y su precio. Antes de abrirlo ve *desde $X*.

Se administran en **Métodos → Grupos** (nombre, descripción, emoji, orden), y a
qué grupo va cada método se elige en su ficha. Lo que quede sin grupo se sigue
viendo suelto, como el retiro en el local.

**El primero se crea solo:** cuando un correo conectado cotiza por primera vez,
sus servicios se adoptan dentro de un grupo con **el nombre de la zona de envío
de WooCommerce** donde está ese correo. Es la agrupación que ya hiciste vos.
Renombralo cuando quieras. Borrar un grupo no borra ningún método: los deja
sueltos.

---

## El más barato y el más rápido

En la lista del checkout se le marca al cliente cuál es el envío más barato y
cuál el más rápido. Los dos carteles se escriben (o se apagan) en *Ajustes →
General*. Solo aparecen cuando hay con qué comparar y hay un ganador claro: con
dos envíos al mismo precio no se marca nada, y se compara solo entre las
opciones que se ven juntas (las de un grupo entre sí, las sueltas entre sí).

---

## Cuánto sale, desde la ficha del producto

El cliente pone su código postal en la página del producto y ve cuánto sale y
cuándo llega, con las mismas opciones y los mismos precios que el checkout, sin
armar el carrito. Se prende y se escribe en *Ajustes → Textos*. El código postal
le queda guardado, así no lo escribe de nuevo en cada producto.

Con **Dejar elegido** prendido puede además tocar la opción que le sirve ahí
mismo: al llegar al checkout aparece con el código postal puesto y ese envío
marcado, sin volver a comparar. Es una preferencia, no una promesa —lo que se
cobra lo decide el carrito con el destino real—, así que si ese envío ya no está
disponible se elige como siempre.

---

## Qué hay para despachar

Pestaña **Envíos**: los pedidos que esperan salir, con qué correo va cada uno, a
dónde, cuántos días llevan esperando y el campo para cargarles el número de
seguimiento sin abrir el pedido. Se cargan varios y se guarda una vez, con o sin
aviso al cliente. Se filtra por correo y por método.

Un pedido entra en la lista cuando tiene un envío de MJ Envíos, está en uno de
los estados que elegiste en *Ajustes → Avisos → Pantalla de envíos* y todavía no
tiene seguimiento. Ahí mismo se elige a los cuántos días se marca atrasado.

---

## Seguimiento

El número del correo se carga en el pedido (recuadro **📦 Envío**, a la derecha)
o, de a varios, en la pestaña **Envíos**.
Al guardarlo:

- El cliente lo ve en **Mi cuenta**, con el link para rastrearlo.
- Le sale un email, con el asunto y el texto que vos escribís en los ajustes.
- Queda un botón para avisarle por WhatsApp con el mensaje ya armado.

El link se arma con la plantilla del método o la del correo, que usan la marca
`{codigo}`.

---

## Listo para retirar

Para lo que se retira en el local o en un punto a coordinar. En *Ajustes →
Seguimiento y avisos → Listo para retirar* se prende un estado de pedido
propio (apagado por defecto). Prendido, aparece en el pedido y en las acciones
en lote de la lista de pedidos, cuenta como pagado y el cliente lo ve en Mi
cuenta con el nombre que le pongas. Al pasar un pedido a ese estado le llega
un mail (asunto y cuerpo editables; `{instrucciones}` son las del método de
envío del pedido).

El circuito típico con MJ Medios de Pago: el cliente sube el comprobante,
lo aprobás (el pedido pasa a *Procesando*) y, cuando lo tenés preparado, lo
pasás a *Listo para retirar*. Si en Comprobantes → Ajustes elegís ese estado
para "Al aprobar el pago", se saltea el paso del medio.

## Cómo se conecta con el resto

- **MJ Appearance** (el tema): el paso de envío del checkout, el autocompletado
  del código postal y el seguimiento en Mi cuenta. Necesita la versión 3.13.0 o
  más nueva.
- **MJ Medios de Pago**: la condición por medio de pago usa los medios reales de
  la tienda, incluidos los propios que hayas creado ahí.
- **MJ ERP**: cada método declara su **forma de entrega equivalente**, así una
  venta capturada de la tienda queda como "retira en el local" o "envío" según
  lo que eligió el cliente, y no todo como envío pendiente. Además, el pedido
  guarda cuánto cobró el correo, aparte de lo que pagó el cliente.
- **MJ Dólar**: las tablas se cargan en pesos; el total en dólares lo sigue
  resolviendo el medio de pago.

---

## Actualizar

Plugins → MJ Envíos → **Desactivar** y **Borrar**, y después subís el zip nuevo.
No se pierde nada: métodos, condiciones, campos, tablas de precios, códigos
postales y sucursales quedan en la base de datos.

Borrar el plugin desde la lista de plugins **sí** borra todo eso (los pedidos ya
hechos no se tocan nunca).

---

## Para un programador

```
MJE_Methods::available($package)       métodos que cumplen condiciones
MJE_Rates::quote($def, $package)       precio de un método para un paquete
MJE_Geo::resolve_cp($cp)               provincia y localidades de un CP
MJE_Orders::shipment($order)           el envío guardado en un pedido

MJE_Native::all()                      correos con su propio plugin instalado
MJE_Native::find($plugin, $servicio)   la tarifa que ese plugin ya calculó

filtros  mje_carriers · mje_native_plugins · mje_method_available · mje_quote ·
         mje_fields · mje_package_weight · mje_geo_cp · mje_known_fields ·
         mje_fill_from_account · mje_relaxed_address_fields · mje_quote_rate_limit
REST     /wp-json/mje/v1/config · /geo/cp · /geo/localidades · /provincias ·
         /sucursales · /cotizar · /seguimiento
Store API  extensions.mje en el carrito · cart/extensions namespace "mje"
```

Sumar otro correo es registrar una clase que extienda `MJE_Carrier` con el
filtro `mje_carriers`: declara qué datos de conexión pide, cómo cotiza y cómo se
lee la respuesta. El resto (HTTP, caché, margen, redondeo) ya está hecho.

Si ese correo **ya tiene su plugin oficial**, el camino es el otro: una clase que
extienda `MJE_Native_Plugin`, registrada con el filtro `mje_native_plugins`.
Declara cómo se llama su método de envío, si está conectado, sus sucursales y qué
hay que escribirle al pedido. La tarifa no se calcula: se toma la que ese plugin
ya puso en el carrito.
