# MJ Envíos

Los envíos de la tienda, armados por vos. Cada forma de recibir la compra
—a domicilio, a una sucursal, retiro en el local, tu propio reparto por zona,
envío gratis o "lo arreglamos después"— es un **método** que creás, editás,
ordenás, apagás o borrás desde el panel. Ninguno viene fijo en el código.

Se configura en **WooCommerce → 📦 Envíos**.

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
| **Cotización en vivo** | El precio lo pone Vía Cargo, Andreani o Correo Argentino en el momento. |
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
- **Medios de pago.** Esta regla filtra en los dos sentidos: si decís que un
  envío es "solo con efectivo", no aparece cuando el cliente eligió otra cosa —
  y si lo eligió, en el checkout solo le quedan los medios permitidos. Así se
  arma, por ejemplo, un reparto propio que se paga contra entrega.

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

Lo que el cliente carga al comprar queda guardado en su cuenta (lo hace
WooCommerce) y la próxima compra arranca completa; lo corrige en **Mi cuenta →
Direcciones**.

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

## Correos

Pestaña **Correos**. Cada uno pide sus datos: URL de la API, usuario, clave,
número de cliente, contratos. **Un correo sin credenciales queda apagado**, y los
métodos que lo usan cotizan con su tabla propia.

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

## Seguimiento

El número del correo se carga en el pedido (recuadro **📦 Envío**, a la derecha).
Al guardarlo:

- El cliente lo ve en **Mi cuenta**, con el link para rastrearlo.
- Le sale un email, con el asunto y el texto que vos escribís en los ajustes.
- Queda un botón para avisarle por WhatsApp con el mensaje ya armado.

El link se arma con la plantilla del método o la del correo, que usan la marca
`{codigo}`.

---

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

filtros  mje_carriers · mje_method_available · mje_quote · mje_fields ·
         mje_package_weight · mje_geo_cp
REST     /wp-json/mje/v1/config · /geo/cp · /geo/localidades · /provincias ·
         /sucursales · /cotizar · /seguimiento
Store API  extensions.mje en el carrito · cart/extensions namespace "mje"
```

Sumar otro correo es registrar una clase que extienda `MJE_Carrier` con el
filtro `mje_carriers`: declara qué datos de conexión pide, cómo cotiza y cómo se
lee la respuesta. El resto (HTTP, caché, margen, redondeo) ya está hecho.
