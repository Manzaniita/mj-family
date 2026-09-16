# Qué se configura y desde dónde

Regla de la familia MJ: **nada que dependa del negocio vive en el código.**
Colores, textos, rutas, plazos, proveedores y permisos se cambian desde
wp-admin. Lo que queda en el código es lógica: cómo se calcula un precio, cómo
se valida una reserva, cómo se aclara un color para el modo noche.

Este documento es el índice de dónde se toca cada cosa.

---

## 1. Apariencia → MJ Appearance

La pantalla donde se configura la tienda entera. Aparece con la capacidad
`edit_theme_options`, la misma que WordPress ya pide para el menú Apariencia
(antes pedía `manage_options`, y por eso no se veía en algunas instalaciones).

Además del menú, se llega desde dos lugares más: el enlace **MJ Appearance** en
la tarjeta del tema activo (Apariencia → Temas) y la entrada en la barra de
administración, arriba del nombre del sitio.

| Sección | Qué se configura |
| --- | --- |
| **Diseño y marca** | Logo (y variante para modo noche), tema de arranque, nivel de animación, intro, paleta completa, tipografías. |
| **Sesión y acceso** | Quién ve la barra de administración, si la tienda recarga al iniciar sesión, si "Recordarme" viene marcado, cuánto dura la sesión, ruta de "Mi cuenta". |
| **Skin** | Qué propone el tema hijo activo, si se usan sus propuestas, y sus paletas alternativas (se aplican con un botón). |
| **Barra de navegación** | Qué ítems tiene el menú, en qué orden, cómo se llaman, a dónde llevan, quién los ve y si van en el menú o como botón del header. |
| **Marcas** | Cómo llamar a las "marcas" (singular y plural). |
| **Anuncios y avisos** | Barra superior y etiquetas de producto. |
| **Portada (hero)** | Antetítulo, título, subtítulo, botones y fondos. |
| **Banners promocionales** | Imágenes del carrusel. |
| **Secciones de la portada** | Orden (arrastrando) y cuáles se muestran. |
| **Textos de la tienda** | Cada texto de cada pantalla. Vacío = el del tema o el de la skin. |
| **WhatsApp y presupuestos** | Vendedores y condiciones del presupuesto. |
| **Contacto / Redes sociales** | Mail, teléfono, WhatsApp, dirección, mapa, redes. |
| **Contenido** | "Nosotros" con foto, puntos destacados, cifras y preguntas frecuentes. |
| **Legales** | Términos y privacidad. |
| **Envíos** | Umbral de envío gratis. |
| **Avanzado** | Servicio de mapas e íconos del panel. |

### La barra de navegación, en detalle

Cada renglón del panel es un ítem del menú. Se arrastra para ordenar, se
destilda para esconder y se escribe encima para renombrar; vacío = el nombre que
se ve en gris, que sale de **Textos de la tienda → Menú**.

| Columna | Qué hace |
| --- | --- |
| Tilde | Si el ítem se muestra. |
| Nombre | Cómo se llama en el menú. Vacío = el nombre por defecto. |
| Destino | A dónde lleva. Una ruta de la tienda (`/tienda?filtro=ofertas`) o una URL completa. Vacío = el destino propio del ítem. Las categorías no lo tienen: abren su panel de productos. |
| Dónde | En el menú, como botón del header (al lado del carrito), o en los dos. |
| Quién lo ve | Todo el mundo, solo con sesión iniciada, solo sin sesión, o —con MJ ERP activo— solo quien puede entrar al ERP. |
| Ícono | Nombre de [lucide.dev/icons](https://lucide.dev/icons). Lo usan los botones del header. |
| Destacado | Lo pinta con el color de acento (así viene "Ofertas"). |
| Nueva pestaña | Abre el destino en otra pestaña. |

La lista se arma sola con lo que hay: los enlaces del tema, **todas** las
categorías de producto, y lo que aporte cada plugin (Servicios y Turnos de MJ
Turnos Online, el proyecto de MJ Colegas, el acceso al ERP). Una categoría nueva
o un plugin recién activado aparecen al final sin tocar nada. **Agregar enlace**
suma uno propio, con el nombre y el destino que quieras.

Quien nunca abra este panel ve el menú de siempre: mientras no se guarde nada,
manda el orden por defecto.

**En el celular** el menú se abre con el botón ☰ de la barra de arriba, y ahí
adentro van los mismos ítems, los accesos rápidos (el ERP, por ejemplo) y
Favoritos y Comparar, que en una pantalla angosta no entran arriba.

La **barra de anuncios** y la de **envío gratis** también se ven en el celular:
aparecen arriba de todo al entrar y se van con el scroll, para que la barra que
queda pegada sea solo la del logo y los botones. En la computadora quedan
pegadas junto al resto del header, como siempre.

> **El acceso al ERP.** MJ ERP se ofrece como un ítem más, con audiencia "solo
> quien puede entrar al ERP" y como botón del header. Quien no tiene permiso no
> lo ve, y —más importante— no lo recibe: el filtro se aplica en el servidor, así
> que el enlace ni siquiera llega al navegador del cliente. El camino de vuelta
> está en el ERP: **Configuración → General → Acceso a la tienda**.

---

### Sesión y acceso, en detalle

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Barra de administración | Según el perfil de cada usuario | "Según el perfil" es lo que hace WordPress: respeta la casilla de Usuarios → Perfil. También hay "solo quienes administran el tema", "todos" y "nunca". |
| Recargar al iniciar sesión | Sí | La tienda es una sola página; sin recargar, WordPress no puede pintar la barra de administración de quien acaba de entrar. |
| "Recordarme" marcado | Sí | Cómo viene la casilla en el formulario de acceso. |
| Duración de la sesión | 2 días / 14 con "Recordarme" | 0 = lo que use WordPress. Máximo 365. |
| Ruta de "Mi cuenta" | `/cuenta` | A dónde va el cliente después de entrar. |
| Compra rápida | Sí, y entra directo | Un cliente con sesión iniciada y dirección guardada ve el resumen y un solo botón en lugar de los cuatro pasos. Siempre puede abrir el formulario completo. Desmarcando "entrar directo", el atajo aparece arriba pero el checkout arranca normal. |
| Texto del botón de compra rápida | "Comprar ahora" | Lo que dice el botón cuando el checkout está en modo rápido. |

---

## 2. Temas hijo (skins)

Un tema hijo ya no repite en su código la paleta ni los textos del padre: se
**describe** con el filtro `mj_skins` y el padre hace el resto.

```php
add_filter('mj_skins', function ($skins) {
    $skins['mi-skin'] = [
        'name'      => 'Mi Skin',
        'palette'   => ['color_accent' => '#e11d84', /* … */],
        'fonts'     => ['font_display' => 'Urbanist', 'font_sans' => 'Manrope'],
        'fonts_url' => 'https://fonts.googleapis.com/…',   // opcional
        'options'   => ['hero_title' => '…'],              // otras propuestas
        'copy'      => ['nav_shop' => 'Productos'],        // textos
        'home'      => ['design_theme' => 'light', 'order' => '…'],
        'presets'   => ['nude' => ['label' => 'Nude', 'palette' => [/* … */]]],
    ];
    return $skins;
});
```

Cómo se resuelve cada valor, de mayor a menor prioridad:

1. **Lo que el admin escribió** en Apariencia → MJ Appearance. Siempre gana.
2. **Lo que propone la skin**, si el campo está vacío o tiene todavía el valor
   por defecto del tema base.
3. **El valor por defecto del tema base.**

Las **paletas** de cada skin se ven en la sección Skin con su muestra de
colores, y el botón "Aplicar" las **escribe** en los campos de Diseño y marca:
a partir de ahí son colores comunes, editables uno por uno. Desmarcando
"Propuestas de la skin" la tienda vuelve al tema base y queda solo el CSS.

Cada hijo declara qué versión del padre necesita (filtro `mj_skin_requires`);
si se sube un hijo nuevo sobre un padre viejo, aparece un aviso en wp-admin.

---

## 3. Otros paneles

| Plugin | Dónde | Qué se configura |
| --- | --- | --- |
| MJ Turnos Online | Turnos → Ajustes | Servicios, horarios, seña, textos de la reserva, plantillas de aviso, slug de la página. |
| MJ Colegas | Colegas → Ajustes | Nombre del proyecto, etiquetas, comisión, textos. |
| MJ Dólar | WooCommerce → Dólar | Tipo de cambio, redondeo, cotización de referencia y su proveedor. |
| MJ Medios de Pago | WooCommerce → Medios de pago | Recargos y descuentos por medio de pago. Con **Editar textos y datos para pagar**, en cada fila: título, descripción, instrucciones, si está activo, y la lista de datos para pagar (alias, CBU, titular, lo que haga falta: la etiqueta la escribe el admin), con su encabezado, cuáles llevan botón de copiar y si se muestran también en el checkout. En Ajustes generales → *Cómo pagar*: el título del bloque («Cómo pagar»), el encabezado por defecto de los datos, el texto del botón de copiar y el de después de copiar, y en qué estados del pedido los ve el cliente. |
| MJ Medios de Pago (comprobantes) | WooCommerce → Comprobantes → Ajustes | En qué medios de pago se pide comprobante, tipos y tamaño de archivo, plazos, qué pasa al aprobar, qué estado toma el pedido con un pago parcial, el mail y el WhatsApp del pago parcial, si los mails suman el resumen del pago (acreditado, pagado, falta abonar y mensaje), quién puede revisarlos, mails y WhatsApp, cuánto se guardan, las equivalencias con los métodos de MJ ERP, y **todos los textos que ve el cliente**: nombres de los estados, mensajes de cada estado, lo pagado y lo que falta, el historial de pagos, el resumen de los mails y los botones y avisos del widget de subida. |
| MJ Envíos | WooCommerce → Envíos | Los métodos de envío (crear, editar, ordenar, apagar, borrar), sus condiciones, qué datos pide cada uno, la tabla propia de códigos postales, las credenciales de cada correo, el origen del despacho, pesos, margen, redondeo, textos del checkout y los avisos de seguimiento. |
| MJ Optimizador de Imágenes | Ajustes → Imágenes | Formato, calidad, tamaños. |
| MJ Fotos Rápidas | Productos → Fotos rápidas → "Ajustes de las fotos" | Lado más largo y calidad con que el celular achica cada foto antes de subirla. |
| MJ ERP | MJ ERP → Ajustes | Ventas, clientes, costeo, proveedores, consignación, la caja de ingreso de cada medio de pago, y permisos del revendedor (por rol y por usuario). |
| MJ Core | Ajustes → MJ · Actualizaciones | De qué repositorio de GitHub salen las versiones, el token para leerlo y cada cuántas horas se consulta. Muestra qué versión de cada paquete tiene la tienda y cuál está publicada. Ver `PUBLICAR.md`, sección 3. |

---

## 3.1. La caja de cada medio de pago

Toda la plata que entra tiene que caer en alguna caja, y no en la misma: una
transferencia va a la caja de transferencias, los dólares físicos a la caja de
dólares. Eso se decide **una sola vez**, sobre el medio de pago.

**Dónde:** MJ ERP → Configuración → Ventas → *Medios de pago*. Cada fila tiene
ahora una columna **Caja de ingreso**. Lo mismo en Consignaciones → *Medios de
cobro*.

**Qué alcanza esa decisión**, sin configurar nada más:

- Las ventas cargadas en el ERP (mostrador, WhatsApp, feria).
- Los cobros contra una venta ya existente.
- Los pagos que registra un revendedor desde su panel.
- **Los pedidos de la tienda**, a través de la equivalencia gateway → método del
  ERP que ya se carga en *WooCommerce → Comprobantes → Ajustes*. Esa pantalla
  muestra en una columna a qué caja va a parar cada medio de la tienda, para
  verificarlo de un vistazo.

**Las reglas, en orden:**

1. Si el medio de pago tiene una caja propia, esa.
2. Si no, la que tenga el mismo medio en el otro catálogo (ventas ↔
   consignaciones comparten las claves: `transferencia` es la misma plata venga
   de un cliente o de un revendedor).
3. Si no, la **Caja por defecto** de Ventas.
4. Si tampoco hay, queda sin caja — igual que antes de esta función.

Elegir la caja a mano en la pantalla de venta sigue mandando sobre todo lo
anterior. Y una caja borrada nunca queda pegada: se cae al default sola.

Un plugin puede cambiar la resolución con el filtro `mjerp_cashbox_for_method`.

---

## 3.1.1. La libreta de clientes

Un cliente del ERP es **a quién le vendiste**, no una cuenta de la tienda. Se
carga en el mostrador con un nombre y listo: no crea un usuario de WordPress, no
manda ningún mail, y no hace falta que esa persona sepa que existe.

**Dónde:** MJ ERP → Clientes. La ficha también se puede crear sin salir de la
pantalla de venta: si escribís un nombre que no está, el buscador ofrece
«Crear cliente…» al final de la lista.

**Qué se configura** (MJ ERP → Configuración → **Clientes**):

| Opción | Para qué |
| --- | --- |
| Llevar una libreta de clientes | Apagada, las ventas guardan el nombre que escribas pero sin ficha ni historial por persona. |
| Crear clientes desde la pantalla de venta | El «Crear cliente…» del buscador. |
| Pedir siempre un cliente | Obliga a elegir a quién antes de registrar la venta. Apagado por defecto: al mostrador no siempre se le pregunta el nombre. |
| Campos que se muestran | Qué pide la ficha además del nombre: teléfono, email, documento, empresa, dirección, notas, lista de precios propia. |
| Tipos de documento | DNI, CUIT, CUIL… la lista es tuya. |
| Qué hace el ERP | Cuando alguien se crea una cuenta en la tienda: avisarte, vincularlos solo, o no buscar nada. |
| Por qué datos reconocerlos | Email, teléfono y/o documento. |
| Marcar también las ventas anteriores | Al vincular, las ventas viejas del mostrador pasan a figurar como de esa cuenta. |

### Cuando el cliente se crea una cuenta en la tienda

Este es el caso que el módulo existe para resolver. Alguien te viene comprando
en el mostrador desde hace meses; un día entra a la tienda y se registra. Para
el sistema son dos cosas distintas —una ficha y una cuenta— hasta que alguien
diga que son la misma persona.

El default es **avisarte**: la sugerencia aparece arriba de todo en MJ ERP →
Clientes («fulano se creó una cuenta y coincide con tu cliente mengano»), con
dos botones: *Es la misma persona* y *No*. No se unen solas a propósito: dos
personas de la misma casa comparten teléfono, y unir dos historiales por error
es de lo poco acá que cuesta deshacer. Si tu caso no tiene esa ambigüedad,
poné **Vincularlos solo**.

Al vincular:

- La ficha pasa a llevar la cuenta consigo, y desde ahí lo que compre online y
  lo que compre en el mostrador es el mismo historial.
- Los datos que falten en la ficha se completan con los de la cuenta, sin pisar
  lo que hayas cargado a mano.
- Las ventas anteriores se remarcan con esa cuenta, si dejaste esa opción
  activada.

Se puede **desvincular** cuando quieras: la ficha y todas sus ventas quedan
intactas, solo se suelta la cuenta. Una cuenta no puede pertenecer a dos fichas
a la vez — si intentás, el ERP te dice de quién es.

Los pedidos que entran por la tienda ya no quedan sueltos: si la cuenta tiene
ficha se usa esa, y si no, se le abre una. Un pedido de invitado abre ficha solo
cuando trae nombre y (email o teléfono); si no, no se ensucia la libreta con una
ficha vacía por cada compra anónima.

Al actualizar el plugin, la libreta **estrena con el historial que ya tenías**:
se arma una ficha por cada persona que aparecía en las ventas, agrupando por
cuenta cuando la hay y por email cuando no.

### Borrar un cliente

Si ya tiene ventas no se borra: queda **archivado** (deja de aparecer al cargar
una venta, el historial sigue completo). Si no tiene ninguna, se borra de verdad.

---

## 3.1.2. El precio que sugiere una venta

**Dónde:** MJ ERP → Configuración → Ventas → *Redondeo del precio sugerido*.

El default es **«Como la tienda»**: el precio que el ERP te propone pasa por la
misma escalera con la que MJ Dólar publica el precio en la vidriera —el
redondeo en dólares primero, el redondeo en pesos después—. Así lo que ves
escrito en el mostrador es exactamente lo que paga quien compra online, sin
diferencias de centavos que después hay que explicar.

Las otras opciones: **Como el ERP** usa el paso de *Recepción y stock →
Redondeo*; **Paso propio** usa el número que pongas al lado (100 redondea a los
$100 más cercanos); **Sin redondear** deja los centavos.

Solo afecta al precio que el ERP **sugiere**. Un precio escrito a mano en la
línea de la venta se respeta tal cual, siempre.

---

## 3.1.3. La pantalla de venta: el mostrador

**Dónde:** MJ ERP → Ventas → *Nueva venta*.

Tiene dos formas, según el ancho de la pantalla:

- **En la compu, un mostrador**: el catálogo a la izquierda, el carrito fijo a la
  derecha y el buscador arriba. Se vende mirando los productos, que es lo que se
  hace cuando hay alguien esperando del otro lado.
- **En el celular, un paso a paso**: productos → cantidades → cliente → pago →
  entrega, con **Atrás** y **Siguiente** fijos abajo y el total siempre a la
  vista. Dos columnas no entran en una mano: lo que sirve es una decisión por
  pantalla y el pulgar cerca del botón.

Cambiar de forma no pierde nada: si girás la tablet a mitad de una venta, la
venta sigue igual.

El paso a paso salta los pasos que la tienda no usa: sin libreta de clientes no
hay paso de cliente, sin entregas no hay paso de entrega.

- **Tocar un producto lo agrega.** Si ya estaba, suma una unidad, y la tarjeta
  muestra cuántas van.
- **El buscador filtra el catálogo** mientras escribís. Con **Enter** agrega
  directo: si lo que tipeaste es un SKU exacto gana ese, así que el lector de
  códigos entra de una.
- **Las categorías** son las de WooCommerce, y **Solo con stock** esconde lo
  agotado.
- **El carrito no se mueve** mientras recorrés el catálogo: el total y el botón
  de confirmar están siempre a la vista. **F2** confirma, como antes.
- **Todo lo que define la venta se decide en el carrito**: cliente, canal, lista
  de precios, fecha, descuento, envío, cobro y nota. Son decisiones sobre la
  venta, no sobre el producto que estás eligiendo.

Lo que se configura, en **Configuración → Ventas → Cargar una venta**:

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Productos por página en el catálogo | 24 | Cuántos trae de una (entre 6 y 96). "Ver más productos" trae la página siguiente. |
| Mostrar las fotos de los productos | Sí | Con foto se elige de memoria más rápido; sin foto entran más productos en pantalla. |
| Ancho para el paso a paso | 900 px | Debajo de ese ancho la venta se carga en pasos; arriba, con el mostrador. 0 lo apaga y deja el mostrador siempre. |

El resto de la pantalla sale de lo que ya estaba configurado: los canales, los
medios de pago y su caja, la lista de precios sugerida, si se permiten
descuentos y si se emite el pedido en WooCommerce.

### La entrega

**Dónde se configura:** MJ ERP → Configuración → Ventas → *Entrega*.

Vender y entregar son dos cosas distintas: se puede haber cobrado un envío que
todavía no salió, y haber entregado algo que se paga después. Por eso la entrega
lleva su propio estado, al lado del cobro.

**Las formas de entrega las ponés vos** —envío, punto de encuentro, retira en el
local, en mano, lo que uses— y cada una dice **si la venta ya queda entregada al
elegirla**:

| Columna | Qué hace |
| --- | --- |
| Nombre | Cómo se llama en la pantalla de venta. |
| Clave | Se genera del nombre si la dejás vacía. |
| ¿Ya queda entregada? | *Sí, se entrega en el acto* (mostrador) o *No, queda a entregar* (envío, punto de encuentro). |

Esa marca es lo único que hace falta para que la lista de pendientes signifique
algo: lo que se entrega en el momento no aparece nunca ahí, y lo que hay que
llevar aparece hasta que lo marques.

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Usar el paso de entrega | Sí | Apagado, las ventas no piden ni muestran entrega en ninguna pantalla. |
| Forma por defecto | Entregado en el momento | Con la que arranca la pantalla de venta nueva. |
| Forma de los pedidos de la tienda | Envío | Con esta nacen las ventas que entran solas por WooCommerce: todavía no salieron de acá. |
| Permitir entregar una parte | Sí | Apagado, la entrega es de la venta entera y el detalle no lista productos. |
| Entregar una parte cuenta como entrega | Sí | Para *Cuándo se emite y baja el stock*: prendido, la venta queda firme en cuanto se lleva algo; apagado, espera a que se entregue todo. |
| Cómo se llama cada estado | A entregar · Entregado en parte · En camino · Entregado | Los nombres que se ven en la lista, el detalle, los filtros y el historial. Vacío vuelve al de fábrica. |

**Cómo se usa:**

1. **Al cargar la venta**, en el carrito: elegís la forma y, si hace falta,
   anotás dónde (el punto de encuentro, la dirección, el seguimiento). Abajo
   dice si queda entregada o pendiente.
2. **Desde la lista de ventas**, cuando la entregaste: el botón del camión la
   marca entregada de un toque, sin abrir nada. Si ya estaba entregada, el mismo
   botón abre el detalle para corregir la forma o la referencia.
3. **Desde el detalle de la venta**, en la tarjeta *Entrega*: el mismo botón, y
   además queda registrado cuándo se entregó y quién la marcó.

**Entregar una parte.** Si la venta tiene más de una unidad, el botón
*Entregar una parte o cambiar cómo* de la tarjeta *Entrega* (y el del camión, en
una venta ya entregada) lista los productos con cuánto se llevó de cada uno. Se
marca lo que se llevó, se elige cómo se entrega lo que falta —envío, retira en el
local, lo que tengas configurado— y se anota dónde. La venta queda **Entregado en
parte** (o *En camino*, si lo que falta ya salió), el detalle muestra qué falta, y
*Entregar lo que falta* la termina de un toque. El historial anota qué se llevó
cada vez.

- Entregar una parte cuenta como entrega para *Cuándo se emite y baja el
  stock*, salvo que apagues *Entregar una parte cuenta como entrega*.
- El pedido de WooCommerce no pasa a completado hasta que se entregue todo.
- Corregir los productos o hacer un cambio respeta lo ya entregado: lo nuevo
  queda por entregar (si *Si una venta entregada suma productos, lo nuevo queda
  por entregar* está prendido) y lo demás sigue entregado.

El filtro **Entrega** de la lista de ventas deja ver de una lo que falta
entregar (también lo entregado en parte), y el número del menú **Ventas** suma
—además de lo que falta cobrar— las entregas pendientes y las parciales.

### La lista de ventas

Cada fila muestra **qué se vendió** (las primeras líneas y el resto contado; el
detalle completo aparece al pasar el mouse), así no hay que abrir la venta para
saber cuál era. Las **anuladas** se siguen viendo, y tienen un botón de tacho
para borrarlas de la lista cuando ya no interesan: como están anuladas, borrarlas
no cambia stock ni cuentas, solo limpia la vista. Una venta viva no se borra de
un botón — primero se anula, que es lo que devuelve el stock y la deuda.

> **Al actualizar**, todas las ventas que ya existían quedan marcadas como
> entregadas: lo que se vendió antes de que existiera este paso está cerrado, y
> arrancar con años de entregas "pendientes" no le serviría a nadie. Si alguna
> quedó a medias de verdad, se corrige a mano desde la lista.

---

### No hay deuda sin cliente

Una venta que no queda saldada deja plata a cobrar, y esa plata tiene que tener a
quién reclamársela. **Si la venta va a quedar con saldo, hay que elegir (o crear)
el cliente**: un nombre suelto no alcanza, porque no abre historial ni cuenta
corriente — el saldo quedaría flotando en un listado sin aparecer en la cuenta de
nadie.

La pantalla lo dice mientras cargás —en el paso del pago, apenas el importe
cobrado es menor al total— y te lleva al paso del cliente. El servidor aplica la
misma regla venga de donde venga la venta, así que no hay forma de saltearla por
accidente.

Tres aclaraciones:

- **Cobrada del todo, no hace falta cliente.** Una venta de mostrador que se paga
  entera se puede cargar sin nombre, como siempre.
- **Lo que sale de la consignación de un revendedor no cuenta**: esa plata se
  salda en su cuenta corriente, no acá.
- **Con la libreta de clientes apagada** (Configuración → Clientes) la regla no
  aplica: la tienda eligió no llevar fichas.

Si alguna vez necesitás permitir una deuda sin ficha, hay un filtro:
`mjerp_sale_debt_check`.

**Pagos parciales y diferidos**: cargás lo que te dieron (o nada) y la venta
queda con saldo a nombre del cliente; el resto se lo registrás cuando pague,
desde la venta o desde la lista. No hace falta nada más.

---

### Cuándo se emite el pedido y baja el stock

**Dónde:** MJ ERP → Configuración → Ventas → *Pedido en WooCommerce* →
**Cuándo se emite y baja el stock**.

Anotar una venta no reserva nada. Mientras nadie la pagó ni se llevó el
producto, esa unidad se sigue pudiendo vender: bajarle el stock ahí sería
esconder mercadería que todavía está disponible. Lo que vuelve firme a una venta
es una de dos cosas:

- **Cobrada** — el cliente puso la plata, el producto ya es suyo aunque todavía
  no lo tenga.
- **Entregada** — se lo llevó, aunque todavía no haya pagado.

En cualquiera de las dos el ERP **emite el pedido en WooCommerce y descuenta el
stock, solo**. No hay que apretar nada: pasa cuando registrás el cobro o cuando
marcás la entrega, venga de donde venga esa acción.

| Opción | Cuándo se emite el pedido y baja el stock |
| --- | --- |
| **Cuando se cobra o se entrega** *(default)* | Apenas pasa cualquiera de las dos. |
| Solo cuando se cobra | La entrega no alcanza: hasta que no esté paga, no se toca el stock. |
| Solo cuando se entrega | El pago no alcanza: se descuenta cuando sale de tu mano. |
| Al registrar la venta | Como era antes: todo se emite y descuenta al confirmar. |
| Nunca sola | Queda siempre a mano, con el botón **Emitir pedido** del detalle. |

**Un pago parcial no alcanza.** Una seña deja la venta en "Pago parcial", no en
"Pagada". Si querés que una seña reserve la mercadería, marcá la entrega o emití
el pedido a mano desde la venta. (Para cambiar esa regla hay un filtro,
`mjerp_sale_is_committed`.)

**La casilla "Emitir el pedido en WooCommerce"** de la pantalla de venta sigue
decidiendo *si* esa venta lleva pedido; esto decide *cuándo*. Si la destildás, la
venta igual descuenta el stock cuando se vuelve firme — solo que lo hace el ERP
en vez de WooCommerce, y esa venta no aparece en los informes de Woo.

Mientras una venta no es firme, la pantalla lo dice en dos lugares: en el
carrito, antes de confirmar ("Queda anotada sin tocar el stock: el pedido se
emite y el stock baja cuando la cobres o la entregues"), y arriba del detalle de
la venta.

### El estado del pedido sigue a la venta

**Dónde:** MJ ERP → Configuración → Ventas → *Pedido en WooCommerce*.

El pedido nace con el estado que le toca a la venta y, mientras **El pedido sigue
a la venta** esté marcado, cambia solo cuando registrás un cobro o marcás la
entrega, venga el pedido del ERP o de la tienda:

| La venta | Ajuste | Default |
| --- | --- | --- |
| Queda a cobrar | Estado si queda a cobrar | En espera |
| Cobrada y falta entregar | Estado si está cobrada y falta entregar | Procesando |
| Cobrada y entregada (o cobrada, sin el paso de entrega) | Estado si está cobrada y entregada | Completado |

- **Solo para adelante.** Nunca vuelve un pedido a «a cobrar» por su cuenta: un
  pedido de la tienda esperando la pasarela, o uno que WooCommerce cobró y el ERP no
  anotó, lo decide WooCommerce. Entre *falta entregar* y *entregada* sí va y
  vuelve, si desmarcás la entrega.
- **No pisa lo que hiciste a mano.** Un pedido cancelado, reembolsado o con un
  estado propio no se toca. Los que mueve son los de esos tres estados y los
  pendientes de pago (filtro `mjerp_order_sync_statuses`).
- **Los mails son los de WooCommerce** para ese estado (por ejemplo, «pedido
  completado» al cliente). Se prenden y apagan en WooCommerce → Ajustes →
  Correos electrónicos.
- Al actualizar a MJ ERP 1.19.0, los pedidos que habían quedado atrasados (en
  espera con la venta cobrada y entregada) se ponen al día **una sola vez y sin
  mails** a los clientes.

| Ajuste | Default | Para qué |
| --- | --- | --- |
| El pedido sigue a la venta | Sí | Apagado, el estado se decide solo al emitir el pedido, como antes. |

> **Deshacer.** Emitir el pedido es un acto real, así que no se revierte solo: si
> marcaste una entrega por error, la vuelta atrás de la mercadería es **anular la
> venta**, que cancela el pedido y repone el stock.

---

### Corregir una venta

**Dónde se configura:** MJ ERP → Configuración → Ventas → *Corregir ventas*.

Cuando el cliente cambia de idea —se lleva tres en vez de dos, cambia un producto por
otro— o la venta se cargó mal, no hace falta anular y cargar de nuevo: en el detalle de
la venta, **Editar productos** cambia la misma venta, con su código, su fecha y su pedido.

Se pueden cambiar cantidades, precios, el descuento de cada línea, el descuento de la
venta y el envío, sacar productos y agregar otros. Antes de guardar, la pantalla
muestra cómo queda el total, qué pasa con el stock y con la entrega.

Lo que hace al guardar:

- **Lo que ya estaba conserva su costo.** Lo que se agrega toma el costo de hoy y, si
  no escribís el precio, el sugerido **con el dólar de la venta**, no con el de hoy.
- **El stock se mueve solo por la diferencia**, y lo mueve quien lo movió la primera
  vez: el pedido de WooCommerce si lo hay, el ERP si no. Si la venta todavía no bajó
  stock, no se toca nada.
- **El pedido de WooCommerce se reescribe** para que diga lo mismo que la venta, con
  una nota que lo cuenta.
- **Si estaba entregada y suma productos, vuelve a «A entregar»**: lo nuevo todavía no
  se lo llevó.
- **Los cobros no se tocan.** Si la venta ya tenía cobros, el saldo se recalcula con el
  total nuevo: queda debiendo, o avisa que se cobró de más para corregir el cobro.
- **Queda en el historial** de la venta: qué cambió, quién, cuándo y por qué.

**Corregir un cobro.** En la tabla de cobros, el lápiz de cada cobro deja cambiar el
medio, el importe, la caja, la fecha y la referencia, con un motivo opcional. El estado
de pago (sin cobrar, en parte, cobrada) se recalcula solo y queda en el historial. Lo
hace solo quien puede corregir ventas. No se corrigen así: lo pagado con saldo a favor
(se borra y se carga de nuevo), lo cobrado online en la tienda ni lo que es parte de
una devolución.

Lo que **no** se edita, a propósito:

- **Una venta con cobros, si está apagado** *También las ventas que ya tienen cobros*.
  Ahí cambiar lo que ya se pagó va por devolución: ver *Devoluciones, cambios y
  garantías*, más abajo.
- **Una venta cobrada online en la tienda**, porque la plata está en la pasarela, ni
  **una con devoluciones o cambios**: esas siguen por *Devolución o cambio*.
- **Una venta con productos de la consignación de un revendedor**, porque toca su
  cuenta corriente. Tampoco se le puede agregar algo que el revendedor tiene en
  consignación. Esas se anulan y se cargan de nuevo.
- **Una venta anulada**, ni una cuyo pedido tenga reembolsos en WooCommerce.

Si no se puede, el detalle de la venta dice por qué en lugar del botón.

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Llevar el historial de cada venta | Sí | Anota quién cargó, cobró, entregó, corrigió o anuló cada venta, y cuándo. Se ve en el detalle, abajo de los cobros. |
| Quién puede corregir ventas y ver el historial | Administrador | Roles que, además de entrar al ERP, pueden corregir ventas y ver su historial. El administrador siempre puede. Este ajuste lo cambia solo un administrador. |
| Dejar editar los productos de una venta | Sí | Apagado, el botón no aparece y el servidor rechaza cualquier corrección. |
| También las ventas que ya tienen cobros | Sí | Deja corregir productos de ventas cobradas (menos las cobradas online en la tienda y las que tienen devoluciones). Apagado, una venta con cobros solo cambia por devolución. |
| Pedir el motivo de cada corrección | No | Obliga a escribir por qué se cambió. Queda en el historial y en la nota del pedido. |
| Si una venta entregada suma productos, vuelve a «A entregar» | Sí | Apagado, la entrega queda como estaba. |

---

### Devoluciones, cambios y garantías

**Dónde se configura:** MJ ERP → Configuración → Ventas → *Devoluciones, cambios y
garantías*.

Cuando una venta **ya cobrada** vuelve —entera o en parte, fallada, o para cambiarla
por otra cosa—, en el detalle de la venta **Devolución o cambio** la corrige sin
anularla. Lo registra solo quien puede corregir ventas.

Por cada producto se marca cuántas unidades vuelven, **a dónde va** cada una (al stock,
o a RMA si vino fallada) y si **se le da otro igual** por garantía. También se puede
agregar lo que se lleva a cambio. Antes de guardar, la pantalla muestra cómo queda la
venta, qué pasa con el stock y cuánta plata sobra o falta.

- **Se le reconoce lo que pagó**: el precio de la línea con el descuento de la venta
  repartido. Un descuento no es plata a favor.
- **La garantía del mismo producto no mueve plata**, aunque hoy valga más. Si se lleva
  otro modelo, es un cambio: se cobra o se le reconoce la diferencia.
- **Lo que se lleva a cambio** va al precio de hoy **con el dólar de la venta**, y con
  el costo de hoy.
- **La plata que sobra** sale de la venta (un renglón «Devolución» en los cobros) y
  **queda a favor del cliente** —repartida por moneda según cómo pagó, y se puede
  cambiar el reparto— o **se le devuelve**, eligiendo medio, caja y referencia. Si
  falta, queda en *Falta cobrar*.
- **El stock lo mueve el ERP**: lo que vuelve al stock entra, el reemplazo y lo nuevo
  salen, y **lo fallado no entra** hasta que su RMA vuelva al stock. Si la venta tiene
  pedido, sus líneas se reescriben sin volver a mover stock.
- **Si estaba entregada y se lleva algo**, vuelve a «A entregar».
- **Queda en el historial** y en la tarjeta *Devoluciones y cambios* de la venta.

Si vuelve **todo** lo que se llevó, no es una devolución: se **anula** la venta. Al
anular una venta cobrada se elige lo mismo —queda a favor o le di la plata—; lo que
había pagado con saldo a favor le vuelve solo a su saldo.

No se puede con una venta anulada, con productos de la consignación de un revendedor, o
cuyo pedido tenga reembolsos en WooCommerce.

**RMA.** Lo que volvió fallado queda en **Ventas → RMA** (en el menú, con un globo de lo
abierto), con el proveedor al que se reclama —el último que te lo vendió, y se puede
cambiar—, el problema y una nota. Cada uno termina en **Volvió al stock** (entra al
stock) o **Dado de baja**. Recién al darlo de baja su costo pasa a ser **pérdida por
garantías**, que se muestra aparte (mes, año y total) y no toca el margen de las ventas.

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Registrar devoluciones y cambios de ventas cobradas | Sí | Apagado, el botón no aparece y el servidor rechaza cualquier devolución. |
| Pedir el motivo de cada devolución | Sí | Obliga a escribir por qué. Queda en el historial y en la nota del pedido. |
| Qué se propone con la plata que sobra | Queda como saldo a favor | Lo que viene marcado. En cada devolución se puede elegir lo otro. |
| Actualizar el pedido de WooCommerce | Sí | Apagado, el pedido queda como estaba y solo cambia la venta. |
| Mandar lo fallado a la lista de RMA | Sí | Apagado, todo lo que vuelve entra al stock y no hay lista de RMA. |
| Prefijo de las devoluciones | DEV | Código de cada devolución: `DEV-2026-0001`. |
| Prefijo de los RMA | RMA | Código de cada RMA: `RMA-2026-0001`. |

---

### El camino de vuelta a la tienda

**Dónde:** MJ ERP → Configuración → General → *Acceso a la tienda*.

Un botón arriba a la derecha, en todas las pantallas del ERP, para pasar a la
tienda sin escribir la dirección. Se puede apagar, cambiarle el texto y
mandarlo a otra página. La ida —de la tienda al ERP— se configura en el tema:
Apariencia → MJ Appearance → Barra de navegación.

---

## 3.1.4. Saldo a favor

**Dónde se configura:** MJ ERP → Configuración → Clientes → *Saldo a favor*.

Es lo que se le reconoce a un cliente —por una devolución, por anular una venta
que ya pagó, o a mano— y que puede usar después para pagar. Vive en la **ficha
del cliente** (Clientes → el nombre), con cada movimiento y el saldo después de
cada uno.

- **Queda en la moneda en que pagó.** Si pagó en pesos, el saldo es en pesos; si
  pagó en dólares, en dólares. Un cliente puede tener las dos. **No se convierte
  nunca**: si se devalúa, es del cliente por no usarlo antes.
- **No vence.**
- **Usarlo en otra moneda** (dólares en una venta en pesos, o al revés) lo pasa al
  dólar de ese momento, y ese dólar queda anotado en el cobro.
- **Nunca queda en negativo**: usar, restar o devolver la plata no puede pasar de
  lo que hay.

Cómo se usa desde el ERP:

| Qué | Dónde | Quién |
| --- | --- | --- |
| Ver el saldo y sus movimientos | Ficha del cliente, y la columna del saldo en la lista de clientes | Cualquiera que use el ERP |
| Pagar una venta con saldo | *Registrar cobro* → medio **Saldo a favor** (aparece solo si el cliente tiene) | Cualquiera que use el ERP |
| Sumar o restar a mano | Clientes → botón **$** de la fila, o ficha del cliente → **Editar saldo**: *A favor* suma, *En contra* resta (hasta lo que tiene). Con motivo y, si querés, la venta a la que se refiere: el movimiento la enlaza y queda en el historial de la venta | Quien puede corregir ventas |
| Usar el saldo para pagar lo que debe | La misma ventana → **Uso el saldo para pagar una venta**: elegís una de las ventas que deben y queda como cobro con saldo | Quien puede corregir ventas |
| Devolverle la plata | Ficha del cliente → **Le di la plata**: medio, caja de la que sale y referencia | Quien puede corregir ventas |

Un cobro con saldo **no entra a ninguna caja** (la plata ya había entrado), no se
edita —se borra y se carga de nuevo— y, si se borra o se anula la venta, **el
saldo vuelve** con un movimiento nuevo.

**La vinculación con la cuenta de la tienda.** Una ficha con saldo **nunca se une
sola** a una cuenta, aunque la vinculación esté en *automático*: si alguien se crea
una cuenta con el mismo email o teléfono, aparece en **Posibles vinculaciones**
(arriba de la lista de clientes) y vos confirmás. Mientras tanto, lo que esa cuenta
compre entra sin ficha, y pasa al cliente cuando confirmás. Para reconocer también
por teléfono, marcalo en *Cuando se crean una cuenta en la tienda → Por qué datos
reconocerlos*.

**En la tienda.** Quien tiene cuenta vinculada a su ficha ve su saldo, moneda por
moneda, y sus últimos movimientos en **Mi cuenta → Resumen**. Al pagar, el checkout
le ofrece usarlo: todo o una parte, nunca más que el total (productos, envío y
ajustes). Se descuenta como una línea con el nombre del saldo; si tiene saldo en otra
moneda, se usa primero el de la moneda de la tienda y después el otro, al dólar de
hoy. Al confirmar se vuelve a controlar que alcance y se toma del saldo; en el ERP,
la venta del pedido lo muestra como cobro con saldo a favor y la pasarela cobra solo
lo que falta. **Si el pedido se cancela, el saldo vuelve.**

| Ajuste | Default | Para qué |
| --- | --- | --- |
| Llevar el saldo a favor de los clientes | Sí | Apagado, no se muestra ni se puede usar. Necesita la libreta de clientes. |
| Cómo se llama | Saldo a favor | El nombre del medio de pago y de la sección, en el ERP y en la tienda. |
| Una ficha con saldo a favor se vincula solo con tu confirmación | Sí | Apagado, sigue la regla general de vinculación. |
| Pedir el motivo al sumar o restar saldo a mano | Sí | Apagado, el motivo es opcional. Queda en los movimientos del saldo y, si el ajuste va atado a una venta, en su historial. |
| Que lo vean y lo usen en la tienda | Sí | Apagado, el saldo no aparece en «Mi cuenta» ni en el checkout; se sigue usando desde el ERP. |
| En el checkout, el saldo viene aplicado | Sí | Apagado, el cliente lo tiene que marcar. En los dos casos lo puede sacar o usar menos. |
| Mostrarle al cliente el motivo de un ajuste | Sí | En «Mi cuenta» el ajuste dice «Ajuste de saldo: el motivo». Apagado, dice solo «Ajuste de saldo» y el motivo queda para vos. |

---

## 3.2. Cómo funciona una consignación

El circuito es el de la calle, no el de un formulario:

1. **Entregás** mercadería a un revendedor. El stock de WooCommerce **no** baja:
   las unidades siguen siendo tuyas, solo que están en otro lado.
2. **El revendedor avisa lo que vendió** desde su panel. Eso vale desde el
   momento en que lo carga: baja el stock de WooCommerce y le queda la deuda.
   No espera que confirmes nada — la venta ya pasó.
3. **El revendedor avisa lo que pagó.** *Eso* sí queda esperando: vos confirmás
   que la plata entró, y recién ahí se le descuenta de la cuenta. Es lo único
   que podés verificar de verdad.

Nunca puede avisar más unidades de las que le entregaste. Una venta avisada
reserva su unidad aunque todavía no esté confirmada, así que la misma unidad no
se puede vender dos veces.

**Lo que se puede cambiar** (MJ ERP → Configuración → Consignaciones):

| Ajuste | Para qué |
| --- | --- |
| Qué ventas necesitan tu confirmación | Por defecto ninguna. Si querés revisar lo que carga el revendedor antes de que cuente, poné «Solo lo que carga el revendedor». |
| Minutos para deshacer una venta | Como la venta cuenta al toque, este es el rato que tiene para arreglar un botón mal apretado. Un día por defecto; 0 lo desactiva. Nunca sobre una venta que ya tiene un pago aplicado. |
| Dejar registrar más ventas que unidades entregadas | Apagado. Encendelo solo si vas a ordenar los papeles después. |
| Stock al vender | Si cada venta descuenta de WooCommerce o no toca el stock. |

Los pagos del revendedor esperan tu confirmación según el permiso
**«El pago necesita mi confirmación»**, que se configura por rol y por persona
en la ficha de cada revendedor.

### Precios en pesos y el dólar

La deuda de un revendedor se lleva **en dólares**. Los pesos que ves al lado son
la lectura de hoy, no un segundo número guardado: si el dólar sube, la misma
deuda se lee como más pesos.

Por eso el precio acordado se puede **escribir en pesos**: ponés 22.000, el ERP
lo divide por el dólar del momento y guarda US$ 14,01. Es lo que evita el
centavo de diferencia que aparecía al cargar «14» a ojo y después cobrar 22.000.

El dólar que usa es el de **MJ Dólar**; si ese plugin está apagado, el «TC de
venta» de MJ ERP → Configuración → General. El TC siempre se escribe como
**cuántos pesos vale 1 dólar** (1.570), nunca al revés.

### Cobrar un pago

El importe de un pago tiene tres casillas: **dólares, pesos y el TC**. Se
escriben dos y la tercera se calcula sola:

- Escribís los dólares: los pesos salen del dólar de hoy.
- Si te pagó otra cifra en pesos (te debía US$ 14 y te dio $ 22.000), la
  escribís encima: los dólares quedan en 14 y el TC se ajusta a lo que dé. El
  pago se guarda **en pesos, tal cual**, así la caja recibe $ 22.000 y no los
  $ 21.980 que daría 14 × 1.570.

Para no escribir nada: **«Paga todo lo que debe»** llena el importe con toda la
deuda, y **«Paga esta»** con lo que falta de una venta. En la lista de ventas del
revendedor y en el detalle de cada venta está el botón **«Cobrar»**, que abre el
pago ya lleno: solo elegís cómo pagó.

### Corregir lo ya cargado

Desde la ficha del revendedor se puede editar y eliminar:

- **El precio acordado** de cada línea entregada, incluso después de entregada.
  Las ventas ya registradas conservan el precio que tenían: el nuevo rige de ahí
  en adelante.
- **Las ventas**: cantidad, precio, fecha y nota. No se puede tocar una venta que
  ya tiene un pago aplicado — primero se quita el pago.
- **Los pagos**: importe en dólares y en pesos, tipo de cambio, fecha, medio y
  referencia. Al guardar se vuelven a repartir sobre las ventas impagas.

Cada corrección queda anotada en la **bitácora**, que el revendedor ve en su
panel bajo «Novedades». No es opcional: si le cambiás el precio de algo que ya
tiene, se tiene que enterar sin preguntarte.

### Comprobantes

Los comprobantes que sube el revendedor se ven **dentro del ERP**, en el botón
de la fila del pago, con la imagen o el PDF y los botones de aprobar y rechazar.
No hace falta entrar a wp-admin.

---

## 3.3. Los envíos, en detalle

Todo está en **WooCommerce → 📦 Envíos**, en cinco pestañas. El paso a paso
completo está en `plugins/mj-envios/LEEME.md`; acá va el mapa.

| Pestaña | Qué se decide ahí |
| --- | --- |
| **Métodos** | Cada forma de recibir la compra. Se crean, se editan, se duplican, se ordenan, se apagan y se borran. |
| **Datos del cliente** | Qué se le pide para poder enviar, y los campos propios que agregues vos. |
| **Códigos postales** | La tabla propia: cuántos hay, importar un CSV, sumar uno a mano, vaciarla. |
| **Correos** | Credenciales y URLs de Vía Cargo, Andreani y Correo Argentino, y sus sucursales. |
| **Ajustes** | Origen del despacho, pesos, precio, envío gratis, cotización, código postal, textos y seguimiento. |

### Lo que define un método

Dos decisiones, y el resto es opcional:

- **De dónde sale el precio:** tabla propia (por CP, provincia o localidad, y por
  peso), precio fijo, cotización en vivo con un correo, gratis, retiro en el
  local, o a coordinar (se ofrece sin precio).
- **A dónde entrega:** a domicilio, a sucursal del correo, o se retira en el
  local. Esto ya decide qué datos se piden: a domicilio pide calle y número, a
  sucursal pide la sucursal, el retiro no pide dirección.

### Cuándo aparece un método

Todo lo que quede vacío o en 0 no filtra. Se combinan: provincias, localidades,
códigos postales (sueltos, en rango `1000-1999` o con comodín `58*`), monto del
carrito, peso, cantidad, productos y categorías puntuales, quién compra (con
cuenta, sin cuenta, ciertos roles) y **medios de pago**.

La regla de medios de pago vale **en los dos sentidos**: un envío "solo con
efectivo" no aparece si el cliente eligió otra cosa, y si lo eligió, el checkout
solo le ofrece los medios permitidos.

### Los datos del cliente, y la regla de heredar

El ajuste general está en la pestaña **Datos del cliente**; cada método puede
pedir más o menos desde su ficha. Lo que queda en **Heredar** sigue al general,
así subir una regla general alcanza a todos los métodos que nunca se tocaron a
mano. Es la misma regla que usan los comprobantes de MJ Medios de Pago.

### El código postal

El cliente pone el CP y la tienda completa provincia y localidad sola. De dónde
sale ese dato se elige en **Ajustes → Código postal**, y en qué orden se
pregunta:

- **La tabla propia** (instantánea, sin internet). Se importa por CSV y se va
  llenando sola con todo lo que se resuelve y con lo que elige cada cliente.
- **La API pública del Estado** (Georef). Gratis y sin credenciales; es la que
  hace andar el autocompletado de localidad. No publica códigos postales: un CP
  que todavía no esté en la tabla propia se completa eligiendo la localidad, y
  ahí queda aprendido.
- **El correo conectado**, si su API resuelve CP.

Las provincias salen de WooCommerce (país base de la tienda), no de una lista
escrita en el plugin.

### Los correos

Un correo sin credenciales queda apagado, y los métodos que lo usan cotizan con
su tabla propia. Si la API no responde, pasa lo que diga **Ajustes → Si el correo
no responde**: cotizar con la tabla (lo recomendado), ofrecerlo a coordinar, o no
ofrecerlo.

Las cotizaciones se guardan las horas que elijas y las sucursales quedan
guardadas en la base, así el checkout no depende de que el correo esté
funcionando.

### El peso

Se usa el peso cargado en cada producto. Para lo que no lo tenga, en este orden:
el **peso por defecto del producto** (pestaña Envío del producto), el de su
**categoría**, y el **general**. Encima de eso se puede sumar el embalaje, poner
un piso, partir en bultos y usar peso volumétrico.

### El envío gratis

Se activa por monto en **Ajustes → Envío gratis**, y cada método decide si puede
quedar gratis. Ese número es el que anuncia la barra de "te faltan $X para el
envío gratis" de la tienda: MJ Envíos se lo informa al tema, así que no hay que
cargarlo dos veces.

### Con MJ ERP

Cada método declara su **forma de entrega equivalente** del ERP. Así una venta
capturada de la tienda queda como "retira en el local" o como "envío" según lo
que eligió el cliente, y la lista de entregas pendientes no se ensucia con lo
que ya se entregó. Si un método no declara equivalencia, el ERP sigue usando la
forma configurada para la tienda.

El pedido también guarda **cuánto cobró el correo**, aparte de lo que pagó el
cliente: es la diferencia real entre lo que cobrás por el envío y lo que te
cuesta.

---

## 4. Para instalaciones especiales

Cosas que casi nunca se tocan, pero que no están clavadas en el código. Van en
`wp-config.php` (constantes) o en un plugin propio (filtros).

| Qué | Constante | Filtro |
| --- | --- | --- |
| Quién ve y guarda los ajustes del tema | `MJ_SETTINGS_CAPABILITY` | `mj_settings_capability` |
| Nombre y slug de la página de ajustes | — | `mj_settings_menu_title`, `mj_settings_page_slug` |
| Versión de la librería de íconos del panel | `MJ_ICONS_VERSION` | `mj_icons_script_url` |
| Ruta de la tienda y de la cuenta | — | `mj_shop_path`, `mj_account_path` |
| Rutas que maneja la tienda (SPA) | — | `mj_spa_routes`, `mj_spa_handles_path` |
| Tipografías y iconos disponibles en el panel | — | `mj_font_choices`, `mj_icon_choices` |
| Servicio de mapas | — | `mj_map_embed_base` |
| Duración de la sesión | — | `mj_session_days` |
| Quién ve la barra de administración | — | `mj_admin_bar_capability`, `mj_admin_bar_modes` |
| Variables CSS por tema | — | `mj_design_vars` |
| Datos que recibe la tienda (`window.MJ`) | — | `mj_app_config` |
| Accesos extra en «Mi cuenta» de la tienda | — | `mj_account_links` |
| Datos extra de «Mi cuenta» (`GET /mj/v1/account`) | — | `mj_rest_account` |
| Caja de ingreso de un medio de pago | — | `mjerp_cashbox_for_method` |
| Estados de pedido que el ERP mueve para seguir a la venta | — | `mjerp_order_sync_statuses` |
| Si la cuenta del cliente completa los datos de envío que la tienda no mandó | — | `mje_fill_from_account` |
| Qué datos de envío se completan con el pedido o la cuenta | — | `mje_known_fields` |
| Qué campos de dirección deja de exigir WooCommerce cuando el método no los pide | — | `mje_relaxed_address_fields` |
| Cuántas ventas revisa la puesta al día de estados de pedido (una vez, al actualizar) | — | `mjerp_order_status_resync_limit` |
| Quién puede corregir ventas y ver su historial | — | `mjerp_can_manage_sales` |
| Cuándo una venta no se puede editar | — | `mjerp_sale_edit_blocker` |
| Cuándo un cobro de una venta no se puede corregir | — | `mjerp_sale_payment_edit_blocker` |
| Cuántas anotaciones del historial trae una venta | — | `mjerp_sale_log_limit` |
| Clave del medio de pago «saldo a favor» | — | `mjerp_credit_method_key` |
| Cuántos movimientos del saldo a favor trae una ficha | — | `mjerp_customer_credit_movements_limit` |
| Cuántas ventas recientes ofrece la ventana del saldo para atar un ajuste (las que deben van todas) | — | `mjerp_credit_related_sales_limit` |
| Cuántos movimientos del saldo a favor ve el cliente en «Mi cuenta» | — | `mjerp_shop_credit_movements_limit` |
| Cuándo una venta cobrada no acepta devoluciones | — | `mjerp_sale_return_blocker` |
| A qué proveedor se reclama un producto fallado | — | `mjerp_rma_supplier` |
| Proveedor de la cotización del dólar | `MJD_SUGGEST_ENDPOINT` | `mjd_suggest_endpoint`, `mjd_suggest_sources` |
| Achique de fotos desde el celular | — | `mj_fotos_rapidas_ajustes` |
| Tope de subidas de comprobante por IP y hora | — | `mjpm_receipt_rate_limit` |
| Dónde se carga el widget de comprobantes | — | `mjpm_enqueue_uploader` |
| Contextos que pueden pedir un comprobante | — | `mjpm_receipt_contexts` |
| Regla del comprobante de un pedido | — | `mjpm_order_receipt_requirement` |
| Textos del widget de comprobantes, después de los del panel | — | `mjpm_uploader_texts` |
| Resumen del pago al pie del mail al cliente | — | `mjpm_receipt_mail_summary` |
| Instrucciones y datos para pagar de un medio | — | `mjpm_payment_info` |
| Estados de pedido en los que el cliente ve cómo pagar, después de los del panel | — | `mjpm_payment_info_statuses` |
| Regla del comprobante de un pago de reventa | — | `mjerp_payment_receipt_requirement` |
| Regla del comprobante de una seña | — | `mjto_receipt_requirement` |
| Repositorio de donde salen las actualizaciones | `MJ_CORE_REPO` | `mjc_ajustes` |
| Token de GitHub para leerlo (así no queda en la base) | `MJ_CORE_TOKEN` | `mjc_ajustes` |
| Dirección de la API de GitHub (GitHub Enterprise) | — | `mjc_api_base` |

Ejemplo — dejar los ajustes del tema solo para quien administre la tienda:

```php
// wp-config.php
define('MJ_SETTINGS_CAPABILITY', 'manage_woocommerce');
```

---

## 5. Lo que a propósito NO es configurable

Para que quede claro dónde está el límite:

- **Cómo se aclara el acento para el modo noche.** Es un cálculo (HSL) que
  acompaña al color que elijas; los dos números que lo afinan sí son filtros.
- **La estructura de las rutas de la tienda.** Los nombres se pueden filtrar,
  pero la tienda y su código de React tienen que coincidir: cambiarlos a mano
  rompe la navegación.
- **Las reglas de negocio** (cómo se calcula un total, cuándo un turno queda
  pendiente, cómo se liquida a un colega). Eso es el producto, no un ajuste.
- **Que aprobar una compra sea una decisión tuya.** Un comprobante aprobado no
  da la compra por cobrada solo: un archivo por la mitad del monto no debería
  cerrar una venta. Los archivos se marcan con lo que decidas, pero el botón lo
  apretás vos.
- **Dónde se guardan los comprobantes.** Van a una carpeta privada, nunca a la
  Biblioteca de Medios: son datos bancarios de tus clientes y las URLs de
  uploads son públicas.
