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
| MJ ERP | MJ ERP → Ajustes | Ventas, costeo, proveedores, consignación y permisos del revendedor (por rol y por usuario). |

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
