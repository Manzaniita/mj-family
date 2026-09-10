=== MJ ERP ===
Contributors: mjimportaciones
Tags: erp, woocommerce, compras, importaciones, stock, costos
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ERP para WooCommerce: ventas propias y de la tienda, compras e importaciones con costeo en vivo, consignaciones, proveedores, cajas y actualización de stock, costos y precios.

== Description ==

MJ ERP es un sistema de gestión pensado para tiendas WooCommerce que importan mercadería. Se abre como una pantalla independiente en `https://tu-sitio/mjerp/` (la ruta es configurable) y está disponible para los usuarios con la capacidad `manage_mjerp` (administradores y los roles que habilites).

**Módulo Ventas (v1.4.0)**

Un solo registro para todo lo que sale, venga de donde venga.

* Ventas cargadas en el ERP (mostrador, WhatsApp, feria) con buscador rápido por nombre o SKU, cliente opcional, descuentos y cobro en el momento.
* Cada venta puede emitir un pedido real de WooCommerce: así aparece en la tienda, en sus informes y como notificación en la app del celular.
* Los pedidos de la tienda se espejan solos, con el costo landed congelado al día de la venta para que el margen sea el real.
* Regla de consignación: si el que compra es un revendedor, lo que ya tenía entregado se le descuenta de su stock y se le carga a su cuenta corriente al precio acordado; el resto sale del depósito. Una línea se puede partir entre las dos cosas.
* Cobros totales o parciales por venta, con medio de pago y caja. Y cobranzas de revendedores por cuenta corriente, como hasta ahora.
* Permisos por rol y por usuario para todo lo que un revendedor puede hacer.
* Cancelar o reembolsar un pedido deshace la venta y la deuda que había generado.

**Módulo Compras (v1.0.0)**

* Registro de compras/importaciones con código, fecha, proveedor, destino, moneda base, tipo de cambio (TC) por defecto y método de prorrateo.
* Ítems vinculados a productos o variaciones de WooCommerce (búsqueda por nombre o SKU) o creados desde el ERP, cada uno con cantidad, costo unitario, moneda y TC propio.
* Gastos adicionales (envío, despachante, impuestos, comisión, otros) con monto, moneda, TC, prorrateo sí/no y caja de origen.
* Costeo en vivo: total FOB, gastos prorrateados, costo puesto por unidad (con ajuste de centavos aplicado al ítem de mayor FOB) y comparación contra listas de precio configurables (por ejemplo "Público +70%").
* Flujo de estados: borrador → en tránsito → recibida (+ cancelada). Al recibir, suma stock en WooCommerce, registra movimientos de stock, actualiza el costo del producto y, opcionalmente, el precio.
* Importación de ítems desde planilla (pegar CSV/TSV desde Excel o Google Sheets).
* ABM de proveedores y cajas (base del futuro módulo "Caja").
* Página de productos con costo, precio, margen y markup, y el historial de movimientos de stock.
* Configuración completa: nada está fijo en el código (monedas, símbolos, TC, destinos, tipos de gasto, listas de precio, formato de código, comportamiento al recibir, roles con acceso, tema claro/oscuro).

**Precios en dólares (v1.2.0)**

Pensado para importadores: el costo se registra en dólares y el precio de venta también se publica en dólares, así el margen no se desarma cuando se mueve el tipo de cambio.

* Al recibir una compra, el precio sugerido se publica como precio USD del producto a través de **MJ Dólar**, que es el que pasa el precio a pesos con el TC vigente. Cuando cambiás el TC, todos los precios se actualizan solos: MJ ERP no vuelve a tocar el precio en pesos.
* El diálogo de recepción muestra por ítem el costo final y el precio de venta sugerido al público en dólares, con el equivalente en pesos calculado con el mismo TC y el mismo redondeo que usa la tienda. Lo que ves en el ERP es exactamente lo que va a ver el cliente.
* El TC de venta del ERP se toma de MJ Dólar por defecto, así los dos plugins nunca muestran números distintos.
* Redondeo propio del precio en dólares (al dólar, a los 50 centavos, a los 5 o 10 dólares; al más cercano o siempre para arriba), independiente del redondeo en pesos que hace MJ Dólar.
* El tema **MJ Appearance** y sus temas hijo (MJ Atelier, MJ Neon) muestran el precio en dólares sin configuración extra: leen el dato de MJ Dólar, que es el mismo que escribe el ERP.
* La página de Productos muestra el precio publicado en dólares y el margen y el markup medidos en dólares.
* Si MJ Dólar no está activo o no tiene TC cargado, el precio se escribe en la moneda de la tienda, como en las versiones anteriores.

**Módulo Consignaciones (v1.3.0)**

Para dejarle mercadería a un revendedor y no perder de vista ni el stock ni la plata.

* **Revendedores**: se crean como usuarios de WordPress con rol de reventa, así quedan también como clientes de WooCommerce. Podés crear la cuenta desde el ERP o vincular una que ya exista. Cada uno tiene su lista de precio y, si querés, un límite de crédito que avisa cuando se pasa.
* **Entregas**: un documento por cada salida de mercadería, con el precio acordado por unidad **congelado en dólares** el día que se entrega. Una deuda que ya se acordó no se mueve cuando se mueve el dólar.
* **Ventas**: cuando el revendedor vende, se registra la venta. Ahí baja el stock de WooCommerce, queda el movimiento en el historial y se genera la deuda. Antes de eso, la mercadería sigue contando como stock propio y la página de Productos avisa cuántas unidades están en la calle.
* **Panel del revendedor**: una pantalla propia en `https://tu-sitio/reventa/` (configurable), pensada para el celular, donde el revendedor ve lo que tiene, avisa lo que vendió y consulta su cuenta. Lo que carga queda pendiente de tu confirmación. Nunca ve costos, márgenes ni las cuentas de los demás, y no puede entrar al ERP ni al panel de WordPress.
* **Pagos siempre atados a ventas**: cada pago se reparte sobre ventas concretas, de la más vieja a la más nueva, y el reparto se puede editar antes de confirmar. Si sobra plata queda como saldo a favor a la vista, y se aplica sola a la próxima venta. La única forma de mover un saldo sin una venta detrás es un **ajuste manual**, que exige motivo.
* **Cuenta corriente**: resumen con el saldo corrido, cada venta que suma y cada pago que resta, y el detalle de qué pagó cada pago. El saldo nunca se guarda en una columna: se calcula de los movimientos, así siempre se puede discutir con el número a la vista.
* **Devoluciones**: lo que el revendedor no vendió vuelve a estar disponible para la web.
* Los pagos en pesos se registran con su tipo de cambio (el de MJ Dólar por defecto) y se imputan en dólares, que es la moneda en la que se acordó el precio.
* Todo configurable: el rol de reventa, el formato de los códigos de entrega, qué ventas necesitan confirmación, si se puede vender más de lo entregado, los medios de cobro, y qué puede hacer el revendedor desde su panel.

**Requisitos**

* WordPress 6.0 o superior.
* WooCommerce 7.0 o superior (compatible con HPOS / tablas de pedidos personalizadas).
* PHP 7.4 o superior.

**Seguridad**

* Todas las rutas REST (`mjerp/v1`) exigen usuario autenticado con la capacidad `manage_mjerp` (o `manage_options`) y nonce de WordPress.
* Todos los cálculos se realizan en el servidor; los valores enviados por el navegador nunca se confían para precios o costos.

== Installation ==

1. Subí la carpeta `mj-erp` a `/wp-content/plugins/` o instalá el archivo ZIP desde *Plugins → Añadir nuevo → Subir plugin*.
2. Asegurate de tener WooCommerce instalado y activo.
3. Activá **MJ ERP** desde el menú *Plugins*.
4. Ingresá al ERP desde el menú **MJ ERP** del panel de WordPress, desde el enlace "Abrir ERP" en la fila del plugin, o directamente en `https://tu-sitio/mjerp/`.
5. Revisá la **Configuración** del ERP (moneda base, TC por defecto, listas de precio, roles con acceso) antes de cargar la primera compra.

Si tu sitio usa enlaces permanentes simples (sin URLs amigables), el ERP se abre en `https://tu-sitio/?mjerp_app=1`.

== Frequently Asked Questions ==

= ¿Quién puede entrar al ERP? =

Los administradores siempre tienen acceso. Desde *Configuración → Acceso* podés habilitar otros roles (por ejemplo "Gestor de la tienda"). El plugin asigna la capacidad `manage_mjerp` a los roles elegidos.

= ¿Qué pasa con el stock al marcar una compra como recibida? =

Por cada ítem vinculado a un producto de WooCommerce se suma la cantidad recibida al stock (si el producto no gestiona stock y la opción está activada, se habilita la gestión de stock). Cada suma queda registrada en el historial de movimientos. Si deshacés la recepción, el stock se descuenta nuevamente; los costos y precios actualizados no se revierten.

= ¿Cómo se calcula el costo puesto de cada producto? =

Cada ítem se convierte a la moneda base con su TC (FOB). Los gastos marcados como "prorratea" se reparten entre los ítems según el método elegido (por FOB, por cantidad o sin prorrateo). El costo puesto por unidad es (FOB + gasto prorrateado) / cantidad. La diferencia de redondeo se aplica al ítem de mayor FOB.

= ¿Puedo cambiar la URL del ERP? =

Sí, desde *Configuración → General → Slug de la ruta*. Al guardar, el plugin regenera las reglas de reescritura.

= ¿Se borran los datos al desinstalar? =

No, salvo que actives *Configuración → Avanzado → Eliminar datos al desinstalar*. En ese caso se eliminan las tablas del ERP, sus opciones y los metadatos de costo (`_mjerp_*`) de los productos.

= ¿Funciona con HPOS (almacenamiento de pedidos de alto rendimiento)? =

Sí, el plugin declara compatibilidad con `custom_order_tables`.

== Screenshots ==

1. Detalle de una compra con productos, gastos y costeo en vivo.
2. Listado de compras con filtros por estado, proveedor y fechas.
3. Configuración del ERP.

== Changelog ==

= 1.5.0 =
* **Comprobante de los pagos de reventa.** Cuando un revendedor avisa un pago desde su panel, puede adjuntar la foto o el PDF de la transferencia. Aprobar ese comprobante confirma el pago: baja su saldo y se imputa a sus ventas, igual que si lo confirmaras a mano.
* Se configura una sola vez: nuevo permiso "Comprobante del pago" por rol y por revendedor, y en "Heredar" sigue la regla del medio de pago equivalente en MJ Medios de Pago.
* Nueva pantalla en MJ Medios de Pago para declarar esas equivalencias (qué método del ERP es cada medio de pago de la tienda). Arregla de paso que una venta capturada de la tienda cayera en el método "Otro" cuando el id del gateway no coincidía por nombre.
* En la ficha del revendedor, cada pago muestra en qué está su comprobante y linkea a la bandeja donde se aprueba o se rechaza.
* Al rechazar, el motivo queda escrito en la nota del pago, visible desde el ERP.
* Los archivos los guarda MJ Medios de Pago en su bóveda privada, con su retención y sus permisos: el ERP no guarda archivos por su cuenta. Sin ese plugin, todo sigue funcionando como antes.

= 1.4.0 =
* Nuevo módulo **Ventas**: todo lo que sale de la tienda queda registrado en un solo lugar, venga del mostrador, de la tienda online o de un revendedor.
* Pantalla de venta rápida: buscás el producto, ponés la cantidad y confirmás. El foco arranca en el buscador, Enter agrega y F2 confirma.
* Cada venta cargada en el ERP emite un pedido real de WooCommerce, así aparece en los informes de Woo y llega la notificación a la app del celular. El estado del pedido (cobrada / a cobrar) es configurable.
* Los pedidos de la tienda entran solos al ERP con su costo landed congelado, para que el margen sea el real del día de la venta.
* **Cuando compra un revendedor**, el ERP revisa producto por producto: lo que ya tenía en consignación se le descuenta de su stock y se le carga a su cuenta corriente al precio acordado; lo que no tenía sale del depósito como una venta común. Una línea se puede partir entre las dos cosas.
* Cancelar, reembolsar o mandar a la papelera un pedido deshace la venta y la deuda del revendedor. El stock lo repone WooCommerce.
* Cobros por venta: totales o parciales, con medio de pago, caja y referencia. Una venta muestra siempre cuánto falta cobrar.
* Un revendedor puede avisar sus pagos desde su panel. Según lo que configures, el pago baja el saldo al instante o queda esperando tu confirmación.
* **Permisos configurables por rol y por usuario**: qué puede hacer cada revendedor (avisar ventas, registrar pagos, pagos parciales, devoluciones, ver su cuenta, si sus compras descuentan de su consignación) se resuelve encadenando el ajuste general, su rol y su ficha.
* Precio de revendedor en la tienda: un revendedor logueado puede ver el precio de su lista en lugar del público, calculado desde el costo landed más el markup de la lista.
* Nueva pestaña de Configuración → Ventas: canales, medios de pago, códigos, emisión del pedido, qué estados confirman o anulan una venta, y las reglas de consignación.
* Tablero y menú con lo vendido en el mes, el margen y lo que falta cobrar.

= 1.3.0 =
* Nuevo módulo **Consignaciones**: revendedores, entregas de mercadería, ventas, devoluciones, pagos y cuenta corriente.
* Los revendedores son usuarios de WordPress con rol de reventa (se crea solo si no existe), así quedan también como clientes de WooCommerce. Se pueden crear desde el ERP o vincular cuentas existentes.
* Las entregas congelan el precio acordado por unidad en dólares el día que sale la mercadería.
* El stock de WooCommerce baja cuando el revendedor vende, no al entregar. La página de Productos avisa cuántas unidades están en poder de un revendedor.
* Panel del revendedor en su propia dirección, pensado para el celular: ve lo que tiene, avisa lo que vendió y consulta su cuenta. Lo que carga queda pendiente de confirmación.
* Cada pago se imputa a ventas concretas (de la más vieja a la más nueva, editable antes de confirmar). El sobrante queda como saldo a favor y se aplica solo a la próxima venta. Los ajustes manuales exigen motivo.
* Resumen de cuenta con saldo corrido, y el detalle de qué venta pagó cada pago.
* Nueva pestaña de Configuración → Consignaciones: rol de reventa, códigos de entrega, qué ventas necesitan confirmación, medios de cobro, imputación de pagos y permisos del panel del revendedor.
* Contador en el menú y tarjetas en el tablero con lo que está en la calle, lo que te deben y las ventas esperando confirmación.

= 1.2.0 =
* Precios de venta en dólares: al recibir una compra, el precio sugerido se publica como precio USD del producto a través de MJ Dólar, y la tienda lo pasa a pesos con el TC vigente. Cuando cambiás el TC en MJ Dólar, los precios se actualizan solos.
* El diálogo de recepción muestra, por ítem, el costo final y el precio de venta sugerido al público en dólares con su equivalente en pesos, calculado con el mismo TC y el mismo redondeo que usa la tienda.
* El TC de venta del ERP puede tomarse de MJ Dólar (opción por defecto), así el precio sugerido en el ERP y el que ve el cliente son el mismo número.
* Nuevos ajustes en Recepción y stock: cómo se publica el precio (automático, en dólares o en la moneda de la tienda), redondeo del precio en dólares (al dólar, a los 50 centavos, a los 5 o 10 dólares) y su modo (al más cercano o siempre para arriba).
* La página de Productos muestra el precio publicado en dólares y el margen y el markup medidos en dólares, que no se mueven cuando cambia el TC.
* Al crear un producto desde el ERP se pide el precio de venta en dólares cuando MJ Dólar está activo.
* Sin MJ Dólar, o sin TC cargado, todo funciona como antes: el precio se escribe en la moneda de la tienda.

= 1.1.0 =
* Carga masiva de productos en una compra: buscador y catálogo con selección múltiple, configuración común (cantidad, costo, moneda y TC) que se copia a las filas tildadas o a todas, edición fila por fila y alta de todos los productos en una sola operación.
* La importación por lote acepta productos ya identificados (product_id / variation_id), así que lo elegido en el selector se vincula exacto sin buscar por SKU ni por nombre.
* Nuevo contador linked_by_id en el reporte de la importación.

= 1.0.0 =
* Versión inicial.
* Módulo Compras: compras, ítems, gastos, costeo en vivo, prorrateo, listas de precio, importación desde planilla.
* Recepción de compras con actualización de stock, costos y precios en WooCommerce; historial de movimientos.
* Proveedores y cajas.
* Página de productos con costo, precio, margen y markup.
* Configuración completa y control de acceso por roles.

== Upgrade Notice ==

= 1.3.0 =
Agrega el módulo de Consignaciones. Crea siete tablas nuevas y el rol de reventa al activarse; no toca nada de lo que ya tenías. Después de actualizar, entrá una vez a Ajustes → Enlaces permanentes y guardá, para que funcione la dirección del panel del revendedor.

= 1.2.0 =
Publica los precios de venta en dólares a través de MJ Dólar. Si tenés MJ Dólar activo con el TC cargado, la nueva opción se activa sola; si no, todo sigue igual que antes.

= 1.1.0 =
Agrega la carga masiva de productos a una compra. No requiere ningún cambio manual.

= 1.0.0 =
Versión inicial.
