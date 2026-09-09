=== MJ Medios de Pago ===
Contributors: mjimportaciones
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.0.0
License: GPL-2.0-or-later

Descuentos y recargos por medio de pago, con precio distinto en tarjetas, ficha, carrito y checkout. Métodos de pago propios.

== Qué hace ==

* Tabla con todos los medios de pago de la tienda: marcás cuál tiene el precio base y qué descuento o recargo lleva cada uno (porcentaje por unidad o monto fijo por pedido), con justificación, badge, nombre corto y mínimo de compra.
* Métodos propios (por ejemplo "Pago en dólares en el local"): se registran como medios de pago reales de WooCommerce, con descripción, instrucciones, estado del pedido y moneda. Con MJ Dólar activo, los cobrados en dólares muestran el total en USD.
* El ajuste entra al pedido como una línea aparte con su justificación, calculada ítem por ítem con el mismo redondeo que ven las tarjetas.
* En la tienda (tema MJ Appearance 3.3+): selector "Ver precios con" en header y carrito, precio por método en tarjetas y ficha, panel de precios por medio de pago, totales del carrito y checkout en vivo, instrucciones y total en USD en la confirmación.
* Exclusión por producto y por categoría. Reglas: descuentos en productos en oferta y acumulación con cupones, configurables. El porcentaje se calcula solo sobre productos.
* Vista previa en el admin con un precio de ejemplo.

== Instalación ==

1. Plugins → Añadir nuevo → Subir plugin → mj-medios-pago.zip → Activar.
2. WooCommerce → Medios de pago: marcá el precio base, cargá los ajustes y, si querés, creá métodos propios.
3. Subí el tema MJ Appearance 3.3 (o superior) para ver el selector y los precios por método en la tienda.

== Notas ==

* Los recargos se aplican siempre; los descuentos respetan las reglas de ofertas y cupones.
* Un método propio marcado en dólares necesita MJ Dólar activo con tipo de cambio cargado; si no, se cobra en pesos.
* El interruptor "Activo" de un método propio se guarda en WooCommerce → Ajustes → Pagos (mismo interruptor); el resto se edita acá.

== Actualización ==

Plugins → MJ Medios de Pago → Desactivar y Borrar, después subís el zip nuevo. No se pierde nada.

== Changelog ==

= 1.0.0 =
* Versión inicial.
