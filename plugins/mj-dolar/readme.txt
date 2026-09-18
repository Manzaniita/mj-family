=== MJ Dólar ===
Contributors: mjimportaciones
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.1.1
License: GPL-2.0-or-later

Precios en dólares que se convierten solos a pesos con el tipo de cambio que cargás vos desde wp-admin.

== Qué hace ==

* Campo "Precio USD" y "Precio oferta USD" en la ficha del producto, en cada variación, en la edición rápida y en la edición en lote (aumentar/reducir % o monto).
* Precio en pesos = USD × tipo de cambio, redondeado (a los $100 por defecto, configurable). Se escribe en los campos normales de WooCommerce, así todo lo demás (ordenar por precio, filtros, informes, otros plugins) ve pesos reales.
* Al cambiar el TC, el cliente ve los precios nuevos al instante; el recálculo de la base corre en segundo plano con barra de progreso.
* Columnas "Precio USD" y "Precio oferta USD" en Productos → Importar / Exportar (CSV nativo de WooCommerce). Acepta 1234.5, 1.234,50 y 1234,50.
* Columna USD en el listado de productos, ordenable, y filtro "Con / Sin precio USD".
* El TC queda guardado en cada pedido (y el USD unitario en cada línea): se ve en el admin del pedido y en Mi cuenta.
* Tipo de cambio en la barra de admin, widget del escritorio y página WooCommerce → Dólar con historial de cambios.
* Opcional: cotización sugerida de dolarapi.com (solo referencia, nunca se aplica sola).
* Header de la tienda: muestra "Dólar $ 1.450" (tema MJ Appearance 3.2+).
* Los productos sin precio USD siguen en pesos fijos.

== Instalación ==

1. Plugins → Añadir nuevo → Subir plugin → mj-dolar.zip → Activar.
2. WooCommerce → Dólar: cargá el tipo de cambio.
3. Cargá el precio USD de tus productos (ficha, edición rápida, en lote o CSV).

== Notas ==

* Funciona con el editor de producto clásico de WooCommerce (el que se usa en esta tienda). Si activás el "nuevo editor de productos" (beta), los campos USD no aparecen ahí.
* El importador CSV: si mapeás "Precio USD" y la celda viene vacía, ese producto vuelve a pesos fijos (igual que hace WooCommerce con sus columnas). Si la columna no está en el CSV, no se toca el USD existente.
* Para editar a mano el precio en pesos de un producto, borrá su precio USD.

== Actualización ==

Plugins → MJ Dólar → Desactivar y Borrar, después subís el zip nuevo. No se pierde nada: los precios USD, el TC y el historial quedan en la base.

== Changelog ==

= 1.1.1 =
* Solo el readme: el Stable tag y este historial habían quedado en 1.0.0.

= 1.1.0 =
* El tipo de cambio queda guardado en cada pedido y se muestra en «Mi cuenta» del tema MJ Appearance (con el USD de cada línea).
* Los fallos del recálculo masivo y de la cotización sugerida quedan anotados en MJ · Registros, con motivo y código HTTP.
* Campos USD en la edición rápida, en lote y en el importador y exportador CSV de WooCommerce.

= 1.0.0 =
* Versión inicial.
