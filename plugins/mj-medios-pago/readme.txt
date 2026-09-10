=== MJ Medios de Pago ===
Contributors: mjimportaciones
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.1.0
License: GPL-2.0-or-later

Descuentos y recargos por medio de pago, con precio distinto en tarjetas, ficha, carrito y checkout. Métodos de pago propios y comprobantes de pago que vos aprobás.

== Qué hace ==

* Tabla con todos los medios de pago de la tienda: marcás cuál tiene el precio base y qué descuento o recargo lleva cada uno (porcentaje por unidad o monto fijo por pedido), con justificación, badge, nombre corto y mínimo de compra.
* Métodos propios (por ejemplo "Pago en dólares en el local"): se registran como medios de pago reales de WooCommerce, con descripción, instrucciones, estado del pedido y moneda. Con MJ Dólar activo, los cobrados en dólares muestran el total en USD.
* El ajuste entra al pedido como una línea aparte con su justificación, calculada ítem por ítem con el mismo redondeo que ven las tarjetas.
* En la tienda (tema MJ Appearance 3.3+): selector "Ver precios con" en header y carrito, precio por método en tarjetas y ficha, panel de precios por medio de pago, totales del carrito y checkout en vivo, instrucciones y total en USD en la confirmación.
* Exclusión por producto y por categoría. Reglas: descuentos en productos en oferta y acumulación con cupones, configurables. El porcentaje se calcula solo sobre productos.
* Vista previa en el admin con un precio de ejemplo.

== Comprobantes de pago ==

* Elegís medio por medio si pide comprobante: no lo pide, puede subirlo, o tiene que subirlo. Lo que dejás en "Heredar" sigue la regla general, así cambiar el default después alcanza a todos los métodos que nunca tocaste a mano.
* El cliente lo sube DESPUÉS de comprar, con el pedido en el estado que elegiste: desde la página de gracias, desde Mi cuenta, o con un link firmado que le llega por email si compró sin cuenta.
* Vos aprobás o rechazás cada compra desde WooCommerce → Comprobantes (con contador en el menú) o desde la pantalla del pedido. Al rechazar, el motivo que escribís es lo que ve el cliente.
* Varios archivos por compra, para los pagos en partes: cada uno se revisa por separado y la compra la aprobás vos, nunca se da por cobrada sola.
* Mails configurables al aprobar y al rechazar (asunto y cuerpo, con marcadores), aviso a la tienda cuando entra uno nuevo, recordatorio si no lo subió, y botón de WhatsApp con el mensaje ya escrito.
* Los archivos NO van a la Biblioteca de Medios: viven en una carpeta privada con nombre aleatorio y se leen solo por un endpoint que valida permiso. Retención configurable: pasado el plazo se borra el archivo y queda el registro (importe, fecha, referencia y quién aprobó).
* Tipos, tamaño máximo y cuántos archivos, configurables. El HEIC del iPhone se convierte a JPG solo cuando el servidor puede. Opción aparte para pasar o no las imágenes por MJ Optimizador de Imágenes (apagada por defecto: un comprobante se guarda como llegó).
* Quién los puede ver: administradores siempre, y por separado quien administra la tienda y quien administra MJ ERP.
* Equivalencias con MJ ERP: decís qué método del ERP es cada medio de pago de la tienda y configurás el comprobante una sola vez para las dos partes. De paso, una venta que entra de la tienda deja de caer en el método "Otro".
* Lo usan también MJ ERP (pagos de las reventas) y MJ Turnos Online (señas), con la misma bóveda, la misma bandeja y la misma retención.

== Instalación ==

1. Plugins → Añadir nuevo → Subir plugin → mj-medios-pago.zip → Activar.
2. WooCommerce → Medios de pago: marcá el precio base, cargá los ajustes y, si querés, creá métodos propios.
3. Subí el tema MJ Appearance 3.3 (o superior) para ver el selector y los precios por método en la tienda.

== Notas ==

* Los recargos se aplican siempre; los descuentos respetan las reglas de ofertas y cupones.
* Un método propio marcado en dólares necesita MJ Dólar activo con tipo de cambio cargado; si no, se cobra en pesos.
* El interruptor "Activo" de un método propio se guarda en WooCommerce → Ajustes → Pagos (mismo interruptor); el resto se edita acá.
* Los comprobantes necesitan que la carpeta wp-content/uploads tenga permiso de escritura: el plugin crea ahí mj-comprobantes con su propio bloqueo.
* En Apache el bloqueo va por .htaccess; en Nginx ese archivo se ignora, y ahí la defensa es el nombre aleatorio de 40 caracteres más que la única URL publicada sea la del endpoint que valida permiso.
* Borrar el plugin SÍ borra los comprobantes y sus archivos. Desactivarlo no borra nada.

== Actualización ==

Plugins → MJ Medios de Pago → Desactivar y Borrar, después subís el zip nuevo. No se pierde nada.

== Changelog ==

= 1.1.0 =
* Comprobantes de pago: configurables por medio de pago, con bandeja de revisión, aprobación o rechazo por compra, mails y WhatsApp.
* Bóveda privada de archivos fuera de la Biblioteca de Medios, con retención configurable.
* Link firmado para que quien compró sin cuenta pueda subir el comprobante.
* Opción para pasar o no las imágenes por MJ Optimizador de Imágenes.
* Pantalla de equivalencias con los métodos de pago de MJ ERP (arregla además las ventas capturadas que caían en "Otro").
* API para que otros plugins de la familia usen la misma bóveda: MJ ERP (pagos de reventa) y MJ Turnos Online (señas) ya la usan.

= 1.0.0 =
* Versión inicial.
