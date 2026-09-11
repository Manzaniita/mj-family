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
| **Categorías del menú** | Hasta 4 categorías en el header (vacío = automático). |
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
| MJ Medios de Pago | WooCommerce → Medios de pago | Recargos y descuentos por medio de pago. |
| MJ Medios de Pago (comprobantes) | WooCommerce → Comprobantes → Ajustes | En qué medios de pago se pide comprobante, tipos y tamaño de archivo, plazos, qué pasa al aprobar, quién puede revisarlos, mails y WhatsApp, cuánto se guardan, y las equivalencias con los métodos de MJ ERP. |
| MJ Optimizador de Imágenes | Ajustes → Imágenes | Formato, calidad, tamaños. |
| MJ Fotos Rápidas | Productos → Fotos rápidas → "Ajustes de las fotos" | Lado más largo y calidad con que el celular achica cada foto antes de subirla. |
| MJ ERP | MJ ERP → Ajustes | Ventas, clientes, costeo, proveedores, consignación, la caja de ingreso de cada medio de pago, y permisos del revendedor (por rol y por usuario). |

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
venta» de MJ ERP → Configuración → General.

### Corregir lo ya cargado

Desde la ficha del revendedor se puede editar y eliminar:

- **El precio acordado** de cada línea entregada, incluso después de entregada.
  Las ventas ya registradas conservan el precio que tenían: el nuevo rige de ahí
  en adelante.
- **Las ventas**: cantidad, precio, fecha y nota. No se puede tocar una venta que
  ya tiene un pago aplicado — primero se quita el pago.
- **Los pagos**: importe, moneda, tipo de cambio, fecha, medio y referencia. Al
  guardar se vuelven a repartir sobre las ventas impagas.

Cada corrección queda anotada en la **bitácora**, que el revendedor ve en su
panel bajo «Novedades». No es opcional: si le cambiás el precio de algo que ya
tiene, se tiene que enterar sin preguntarte.

### Comprobantes

Los comprobantes que sube el revendedor se ven **dentro del ERP**, en el botón
de la fila del pago, con la imagen o el PDF y los botones de aprobar y rechazar.
No hace falta entrar a wp-admin.

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
| Caja de ingreso de un medio de pago | — | `mjerp_cashbox_for_method` |
| Proveedor de la cotización del dólar | `MJD_SUGGEST_ENDPOINT` | `mjd_suggest_endpoint`, `mjd_suggest_sources` |
| Achique de fotos desde el celular | — | `mj_fotos_rapidas_ajustes` |
| Tope de subidas de comprobante por IP y hora | — | `mjpm_receipt_rate_limit` |
| Dónde se carga el widget de comprobantes | — | `mjpm_enqueue_uploader` |
| Contextos que pueden pedir un comprobante | — | `mjpm_receipt_contexts` |
| Regla del comprobante de un pedido | — | `mjpm_order_receipt_requirement` |
| Regla del comprobante de un pago de reventa | — | `mjerp_payment_receipt_requirement` |
| Regla del comprobante de una seña | — | `mjto_receipt_requirement` |

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
