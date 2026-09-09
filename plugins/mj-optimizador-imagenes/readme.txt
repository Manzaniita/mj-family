=== MJ Optimizador de Imágenes ===
Contributors: mjimportaciones
Tags: woocommerce, imágenes, optimizar, webp, compresión
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Achica y convierte a WebP todas las imágenes de la tienda usando tu propio servidor. Sin límites, sin créditos, sin servicios externos.

== Qué hace ==

Subís una foto y te olvidás: el plugin la endereza, la achica, la pasa a WebP y le limpia los metadatos antes de que aparezca en la tienda. Y las que ya tenías se van optimizando solas, de a poco, en segundo plano.

**Con cada imagen nueva**

* Aplica la rotación EXIF y achica las fotos gigantes al lado máximo (2560 px por defecto), evitando el duplicado "-scaled" que crea WordPress.
* Genera las miniaturas de WooCommerce directamente en WebP con la calidad configurada.
* Convierte la imagen principal a WebP (JPEG con calidad 85, PNG sin pérdida) y borra EXIF/GPS conservando el perfil de color.
* Nunca reemplaza un archivo si el resultado no pesa menos.

**Con la biblioteca que ya tenías**

* Se optimiza sola en segundo plano (cada 5 minutos) o de una desde el botón "Optimizar pendientes ahora".
* Por defecto mantiene el formato y la URL original (100 % seguro). Si querés, las pasa a WebP y te actualiza las URLs del contenido.
* Borra los originales sobredimensionados que WordPress guarda aparte.

**Extras**

* Columna "Optimización" en Medios con el ahorro de cada imagen y botones para reoptimizar y restaurar.
* Acción masiva en la biblioteca.
* Copias de seguridad opcionales, con restauración de un clic.
* Comandos WP-CLI: `wp mjoi optimize --all`, `wp mjoi stats`.
* Compatible con HPOS de WooCommerce.

Anda con Imagick o con GD (lo que tenga tu hosting). No necesita API ni cuentas.

== Cómo se lleva con los otros plugins MJ ==

No le pisa datos a nadie: sólo toca archivos de la biblioteca de medios y su propia meta (`_mjoi_*`). MJ Fotos Rápidas, MJ ERP y el tema suben las imágenes por el flujo estándar de WordPress, así que quedan optimizadas sin configurar nada.

== Instalación ==

1. Plugins → Añadir nuevo → Subir plugin → mj-optimizador-imagenes.zip → Activar.
2. Listo: las imágenes nuevas se optimizan solas.
3. Para las que ya tenías, entrá en WooCommerce → 🖼️ Imágenes y tocá "Optimizar pendientes ahora" (o dejá que el proceso en segundo plano las vaya haciendo).

Si venías usando "WC Image Optimizer Ilimitado", activá este y listo: se trae tus ajustes, las estadísticas, las copias de seguridad y la marca de lo que ya estaba optimizado, y desactiva el viejo solo para que no trabajen los dos a la vez. Después podés borrarlo desde Plugins.

== Preguntas frecuentes ==

= ¿Pierdo calidad? =
La calidad 85 es idéntica al original a simple vista. Los PNG se mantienen sin pérdida. Si vendés fotografía o arte, subila a 90-92.

= ¿Y si algo sale mal? =
Activá "Copias de seguridad" en los ajustes: guarda el original y lo restaurás desde la biblioteca con un clic.

= ¿Anda con importaciones CSV y con la API REST? =
Sí. Se engancha al flujo estándar de subida de WordPress, que es el que usan WooCommerce, el importador CSV, MJ Fotos Rápidas, la app móvil y la API.

= ¿Tiene límite de imágenes? =
No. Todo se procesa en tu servidor.

== Actualización ==

Plugins → MJ Optimizador de Imágenes → Desactivar y Borrar, después subís el zip nuevo. No se pierde nada: las imágenes ya optimizadas quedan como están y los ajustes siguen en la base.

== Changelog ==

= 1.1.0 =
* Pasa a llamarse MJ Optimizador de Imágenes y entra en la familia de plugins MJ.
* Menú propio en WooCommerce → 🖼️ Imágenes, junto al resto de los plugins MJ.
* Migración automática desde WC Image Optimizer Ilimitado: ajustes, estadísticas, historial, copias de seguridad y el estado de cada imagen. No se reoptimiza nada de lo ya hecho.
* Textos en el mismo tono que el resto de los plugins MJ.
* Requisitos al día: WordPress 6.0+ y WooCommerce probado hasta 11.1.

= 1.0.0 =
* Versión inicial (como WC Image Optimizer Ilimitado).
