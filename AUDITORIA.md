# Auditoría de la familia MJ y cierre de la versión 1

Hecha el 17 de septiembre de 2026 sobre el commit `36fc230` (MJ Appearance
3.18.2) y puesta al día el 18 de septiembre, después de cerrar los puntos 2, 3,
4 y 6 de la Fase 0 y el 7 de la Fase 1 (sección 6): lo hecho se sacó de las
listas y se anota tachado en el orden sugerido. Reemplaza a `ANALISIS.md` (del
12 de septiembre) y a `PLUGINS-MJ.md` (el plan): lo que ahí seguía vigente está
acá, ordenado en tres listas:

1. **Qué falta para cerrar la versión 1** con lo que ya existe (sección 3).
2. **Qué mejorar** de lo que ya anda (sección 4).
3. **Qué se puede sumar después** (sección 5).

Cómo se hizo: lectura completa del PHP y del JavaScript de los nueve plugins,
el tema padre, los tres hijos, `shared/` y `tools/`; `.\tools\verificar.ps1`
en verde; el estado real de GitHub (Releases, corridas del CI). No se ejecutó
nada contra una tienda: lo que dice "sin probar" sigue sin probar.

---

## 1. En números

| | |
| --- | --- |
| Paquetes | 9 plugins + 1 tema padre + 3 temas hijo |
| Líneas propias (sin `dist/`, sin dependencias) | ~146.000, de las cuales MJ ERP ~72.000 (49 %) |
| Archivos PHP | 160, todos compilan en 7.4 y 8.4 |
| Rutas REST propias | ~145 (87 del ERP) |
| Tablas propias | 35 (25 del ERP, 6 de Turnos, 3 de Envíos, 1 de Comprobantes) |
| Avisos de PHPCS anotados como deuda | 456 (eran 512; la foto se rehizo el 18/9) |
| Commits / Releases publicadas | 80 / 15 |
| Corridas del CI que llegaron a arrancar | **0 de 30** (ver 3.1) |

---

## 2. Estado de cada pieza

Estado para cerrar la v1: **Cerrado** = no tiene nada bloqueante; **Casi** =
faltan cosas chicas; **Falta** = hay una pieza grande pendiente.

| Paquete | Versión | Estado | Qué le falta para la v1 |
| --- | --- | --- | --- |
| MJ Appearance | 3.21.0 | Casi | Reseñas en la ficha; tope de 1000 productos; subcarpeta. (Frenos y `/catalog-stock`: hechos en 3.19.0.) |
| GlossyHair / Yamana / Atelier | 1.1.1 | Cerrado | `*_REQUIRES` en 3.19.0. |
| MJ ERP | 1.22.0 | Casi | Probar en la tienda Caja, Reportes y los permisos nuevos; la bitácora del revendedor sin pestaña; LEEME. (Permisos, integridad, `uninstall.php`, ajustes fantasma, `readme.txt`, Caja y Reportes: hechos en 1.22.0.) |
| MJ Medios de Pago | 1.5.0 | Casi | `uninstall.php` deja el historial de pagos; filtro "parcial" de la bandeja; purga topeada en 200; LEEME. |
| MJ Dólar | 1.1.1 | Casi | Decidir si el TC se trae solo; endpoint de cotización como ajuste; LEEME. (`readme.txt`: hecho en 1.1.1.) |
| MJ Envíos | 1.3.0 | Casi | Probar en la tienda el puente con Andreani (`PRUEBAS-EN-LA-TIENDA.md` §11). Vía Cargo sigue siendo un andamio a la espera de su API; MJ Envíos no da de alta el envío ni imprime el rótulo (lo hace el plugin del correo desde su propia pantalla). (CSV validado, claves que no se reimprimen, `/cotizar` con freno y puente con los plugins de los correos: hechos en 1.3.0.) |
| MJ Turnos Online | 1.3.0 | Casi | Probar en la tienda lo de 1.3 (cuentas, reglas de cambios, saldo, mantenimiento, opiniones: `PRUEBAS-EN-LA-TIENDA.md` §10); el panel no usa `receipt`. (Freno de lecturas, alias/CBU fuera de `/config` y LEEME al día: hechos en 1.3.0.) |
| MJ Fidelidad | 1.0.0 | Casi | Nuevo: probar en la tienda (turnos atendidos, premio como saldo, descontar al sacar el atendido). |
| MJ Colegas | 1.0.1 | Casi | Sellar la base de la comisión. (`uninstall.php`, permiso del menú y CSV: hechos en 1.0.1.) |
| MJ Core | 1.0.1 | Casi | Instalarlo en GlossyHair y Yamana. Sin reporte de versiones ni rollback (mejora, no bloqueante). |
| MJ Optimizador | 1.1.1 | Cerrado | Borra el original sin escalar aunque no haya backup (ver 3.2). |
| MJ Fotos Rápidas | 1.3.0 | Cerrado | Todo lo de la v1 hecho en 1.3.0. Queda la guarda de WooCommerce (avisa `Requires Plugins`, alcanza). |
| Circuito de publicación | — | Casi | El CI nunca corrió (3.1). `publicar.ps1` sin rollback si falla la Release. |

---

## 3. Qué falta para cerrar la versión 1

En orden: primero lo que está afuera del código, después lo que hay que
arreglar en el código. Cada punto trae el archivo para ir directo.

### 3.1. Afuera del código

- [ ] **El CI de GitHub nunca corrió.** Las 30 corridas de
      `.github/workflows/verificar.yml`, desde el primer commit del 14 de
      septiembre, terminan en `startup_failure` sin crear un solo job. El
      archivo pasa `actionlint` en sus dos versiones, no tiene BOM ni tabs, el
      workflow figura como activo y Actions está habilitado. Lo que queda es la
      cuenta: minutos de Actions agotados o facturación en un repositorio
      privado. El aviso se ve solo en la pestaña Actions de github.com. Hasta
      que se resuelva, **la única red de seguridad es `.\tools\verificar.ps1`
      en la máquina.**
- [ ] **Instalar MJ Core en GlossyHair y Yamana**, con un token por tienda
      (`PUBLICAR.md`, sección 3). Hoy dos de las tres tiendas se actualizan a
      mano, y nadie sabe qué versión corren.
- [ ] **Probar en la tienda real** MJ Dólar, MJ Medios de Pago, MJ Envíos y, del
      ERP, corregir ventas, devoluciones/RMA, saldo a favor y entrega por
      partes. Es la lista de `PRUEBAS-EN-LA-TIENDA.md`, pendiente desde hace
      una semana. Todo eso toca plata, stock o el checkout.
- [ ] **Probar el puente con Andreani en MJ Importaciones**, que es la única
      tienda con cuenta de un correo. Hasta que se pruebe con una compra real,
      no encender la cotización en vivo en las otras tiendas: no tienen cuenta
      con ningún correo.

### 3.2. Seguridad

Ninguna es una inyección ni una fuga de datos; son puertas sin freno o
permisos mal cerrados. Ordenadas por lo que más duele.

- [ ] **Optimizador: borra el `original_image` sin escalar aunque `backup = 0`**
      (`class-mjoi-processor.php:185-197`). Es pérdida irreversible: forzar
      la copia cuando se convierte lo existente. Y `replace_urls()` (`:555-568`)
      hace `REPLACE` masivo sobre `postmeta` excluyendo solo serializados de
      primer nivel: un serializado anidado queda con longitudes rotas.

### 3.3. Integridad de datos y desinstalación

- [ ] **Medios de Pago: `uninstall.php` deja `_mjpm_paid` y `_mjpm_payments`**
      (`uninstall.php:62`), que son el historial de pagos parciales, y los
      transients `mjpm_rl_*`.
- [ ] **Colegas: se sella el porcentaje pero no la base de la comisión**
      (`inc/orders.php:98-100`): cambiar "total ↔ subtotal" recalcula
      liquidaciones históricas ya pagadas.
- [ ] **Turnos: `MJTO_DB_VERSION = '1'` y migrar es volver a correr `dbDelta`**
      (`inc/db.php:130-134`). La primera migración real necesita el mecanismo.

### 3.4. Ajustes y funciones que mienten

Cosas que la pantalla ofrece y el código no hace. Peor que no tenerlas.

- [ ] ERP: la **bitácora del revendedor** la escriben cuatro repositorios y
      solo la lee el portal del revendedor; el dueño no tiene pestaña
      (`page-consignee.js:22-27`).
- [ ] Envíos: el proveedor de código postal "correo" nunca responde:
      `resolve_cp()` y `cities()` son stubs que devuelven `[]` y ningún correo
      los pisa (`carriers/class-mje-carrier.php:130`). Sacarlo del selector.
- [ ] Medios de Pago: el filtro por estado de la bandeja no acepta `parcial`
      (`class-mjpm-receipts.php:683`) y la purga borra 200 archivos por día
      como mucho (`:971`).
- [ ] Turnos: el panel recibe `receipt` en cada turno y ningún componente lo
      usa (`inc/db.php`, `mjto_appointment_public`).
- [ ] Tema: la ficha de producto **nunca muestra reseñas** en WordPress: la
      pestaña solo se arma en modo demo (`react-app/src/pages/Product.jsx:664`),
      aunque `fetchReviews()` y `reviewsEnabled` existen. Es la única pantalla
      donde WooCommerce queda por encima del tema.
- [ ] Tema: `quoteShipping()` y `getTracking()` (`react-app/src/lib/wc.js:362-365`)
      envuelven `mje/v1/cotizar` y `/seguimiento` y nadie los llama: el
      seguimiento del pedido en Mi cuenta quedó a medio integrar.
- [ ] Tema: `menu_categories` (`inc/settings.php`) ya no tiene formulario
      propio, pero no es un fantasma: sigue siendo la semilla del menú por
      defecto (`inc/nav.php`) y del orden de categorías en la SPA
      (`store/catalog.js`). Dejarlo escrito en `CONFIGURACION.md` o devolverle
      el formulario.

### 3.5. Consistencia y documentación

- [ ] **Faltan cinco `LEEME.md`**: Dólar, Medios de Pago, Core, ERP y
      Optimizador. La convención ya está escrita en `README.md` (un `LEEME.md`
      por plugin; el `readme.txt`, donde existe, solo cabecera con `Stable tag`
      y changelog) y los `readme.txt` del ERP, Dólar y Optimizador ya la
      cumplen. Se escribe el de cada plugin cuando se lo toque.
- [ ] `publicar.ps1`: si `gh release create` falla, el commit y la etiqueta ya
      están subidos y no hay vuelta atrás (`tools/publicar.ps1:166-206`). Crear
      la Release antes de subir la etiqueta, o borrarla en el `catch`. Y
      `git add -A` sin comprobar que la rama esté al día con el remoto.
- [ ] El "MJ Appearance va primero" está fijo en `publicar.ps1:198` y
      `build-releases.ps1:434`; es dato de producto, va en
      `releases.config.json`. `sincronizar-compartido.ps1` tiene la lista de
      plugins destino adentro del script (`:51-62`) en vez de en la
      configuración.

---

## 4. Mejoras a lo que ya anda

No frenan la v1. Ordenadas por área; dentro de cada una, por valor.

### 4.1. Tema MJ Appearance y la SPA

- **Catálogo que escale.** La SPA baja todo el catálogo al entrar, con tope de
  10 páginas × 100 (`lib/wc.js:205-210`): a partir del producto 1001
  desaparecen de tienda, buscador y comparador sin aviso. Falta paginación y
  búsqueda contra el servidor. Con menos de mil productos no se nota, pero es
  un límite silencioso.
- **Instalación en subcarpeta.** `<BrowserRouter>` sin `basename`
  (`App.jsx:63`) y el "volver al inicio" del ErrorBoundary con `/` fijo;
  el PHP sí la soporta (`functions.php:535`). Pasarle `home_url` a la SPA, o
  documentar que solo se soporta en la raíz.
- **Negocio que queda en el padre:** los legales por defecto con "Ley 25.326",
  "Ley 24.240" y "pesos argentinos e incluyen IVA" (`pages/Misc.jsx:508-519`);
  títulos de Privacidad/Términos escritos a mano aunque existen las claves de
  copy; Favoritos, Comparador, Vistos y 404 sin clave de copy. Un filtro
  `mj_legal_defaults` y cuatro claves nuevas.
- **Higiene del bundle:** `data/catalog.js` (202 líneas de datos demo con
  marcas reales y promesas comerciales) viaja en producción; el `<title>` y la
  descripción "MJ Appearance — Tecnología premium" de `react-app/index.html`
  se publican en el zip.
- **`<noscript>`** en `index.php` con contacto y un enlace: hoy sin JavaScript
  la tienda queda en blanco.
- **Verificación automática de los espejos PHP↔JS:** claves de copy (92/92
  hoy), rutas, orden de la portada, tipos de campo de bloques y
  `OVERRIDABLE` (`lib/pages.js:33`, duplicado a mano en vez de leerse de
  `window.MJ.pages`). Un test que compare los dos lados.
- Accesibilidad: al navegar solo se hace scroll (`Layout.jsx:66`), sin mover el
  foco a `<main>` ni `aria-live`; chips y botones de filtro sin `type="button"`
  (`Shop.jsx:40`).
- `echo mj_favicon_tag()` sin escapar (`index.php:33`), `REQUEST_URI` sin
  sanitizar (`functions.php:536`), `$_GET['mj-skin']` sin nonce
  (`inc/skins.php:429`). Ninguno explotable; los tres son avisos de la
  baseline de PHPCS que conviene cerrar.

### 4.2. MJ ERP

- **Partir los archivos grandes:** `page-settings.js` 3573 líneas,
  `page-purchase.js` 2398, `components.js` 2090, `page-sale-new.js` 1609,
  `app.css` 5397. Mecánico y sin riesgo.
- **Los defaults duplicados:** `const DEFAULTS` en `page-settings.js:112-310`
  copia entera `MJERP_Settings::defaults()` (154 claves, hoy idénticas). Que
  `GET /settings` devuelva los defaults.
- **`calc.js` es `MJERP_Costing` escrito en JavaScript** (719 líneas, a
  propósito y documentado). Es donde un cambio futuro rompe en silencio:
  tests que corran los dos con los mismos números.
- **Tests sobre la plata:** costeo, redondeo, cuenta corriente de
  consignación, saldo a favor en dos monedas. Es un porcentaje chico del
  código y donde un error vende mal durante meses sin que se note.
- Helpers copiados en cada archivo: `toastError()` ×13, `headerHtml()` ×13,
  `num()` ×17 en JS; `v()` ×16, `cast()` ×15, `formats()` ×16 en los
  repositorios. Mover a `core.js` y a una clase base.
- Diecinueve métodos públicos sin ningún llamador (`round4`, `round_money`,
  `tables_exist`, `drop_tables`, `remove_capabilities`, `find_by_sku`,
  `count_by_supplier`, `delete_for_ref`…) y dos rutas REST que nadie llama
  (`/consignees/{id}/account`, `/stock`). Varios huelen a guardas de borrado
  pensadas y nunca cableadas.
- `class-mjerp-sale-log-repo.php` define `MJERP_Sale_Log`, no un repositorio,
  y se inicializa como módulo. Carpeta equivocada.
- `migrate()` no tiene bloques 1.10, 1.11 ni 1.12 aunque `MJERP_DB_VERSION`
  subió en esas versiones. Si es porque `dbDelta` cubrió todo, dejarlo escrito.
- Placeholders atados al rubro en el código (`components.js:981`,
  `components-consignment.js:1178`).

### 4.3. MJ Medios de Pago, MJ Dólar y MJ Envíos

- **MJ Dólar no actualiza el tipo de cambio solo.** El único trabajo programado
  es el recálculo de precios; la cotización sugerida corre por AJAX del admin y
  es referencia. Decidir: o un cron opcional que traiga el TC (con aviso y
  tope de variación), o dejar escrito que es a mano.
- El endpoint de cotización (`dolarapi.com`) se cambia por constante o filtro
  pero no desde el panel (`class-mjd-suggest.php:22`), a diferencia de
  `geo_url` en Envíos que sí es un ajuste. Las siete fuentes son el catálogo
  de dolarapi escrito en el código (`class-mjd-settings.php:48-56`).
- Envíos: el estado del correo se cachea una hora incluso cuando la respuesta
  es vacía por error (`class-mje-tracking.php:176`). Y el `__return_true` de
  las siete rutas no tiene el comentario que lo justifique, como sí tienen
  los comprobantes (`class-mjpm-receipts-rest.php:13-17`).
- Envíos: el método de ejemplo trae `'erp_delivery' => 'retira_local'`, una
  clave del ERP escrita adentro de Envíos (`class-mje-methods.php:168`).
- Los tres tienen `rounding_choices()` idéntico, tres `parse_decimal`, tres
  formateos de importe y dos armados de link de WhatsApp. Candidatos a
  `shared/` con el mecanismo de copias que ya existe.
- Montos de ejemplo fijos en el JS de admin (`mjpm/assets/admin.js:50`,
  `mjd/assets/admin.js:66`).
- `sniff()` de la bóveda confía en la extensión si el servidor no tiene
  `finfo` ni `getimagesize` (`class-mjpm-vault.php:296-299`). Está comentado
  como decisión; es el único punto blando de la bóveda.

### 4.4. MJ Turnos Online, MJ Colegas, MJ Core, Optimizador, Fotos Rápidas

- Turnos: etiquetas del panel ("Hoy", "Turnos", "Clientas", "Caja", "Más")
  fijas en el bundle (`app/src/panel/App.jsx:72-77`) mientras el resto viaja
  por `mjto_panel_labels()`; fucsia `#e11d84` y `Manrope` como último fallback
  sin filtro (`inc/options.php:2040`, `inc/panel.php:126`); prefijos `54`/`549`
  fijos si el ajuste queda vacío (`inc/db.php:181-185`). Dos implementaciones
  de "¿puede cancelar?" con casteos distintos (`rest.php:338` /
  `bookings.php:707`). `mjto_service_by_slug()` y `mjto_receipt_asks()` sin usos.
- Colegas: Liquidaciones hace `wc_get_orders(['limit' => -1])` y recorre todo
  en memoria en cada carga (`inc/report.php:32-39`); todas las liquidaciones
  viven en una sola opción que crece sin techo (`:161-162`).
- Core: el token en claro en `wp_options` (sin autoload, nunca se re-muestra;
  `MJ_CORE_TOKEN` en `wp-config.php` es la salida y está documentada). Si la
  Release no trae `digest`, instala sin comparar SHA-256
  (`class-mjc-catalogo.php:271-276`): avisar fuerte.
- Optimizador: lleva su propio registro (`mjoi_log`) en vez de `MJ_Log` y no
  está en `sincronizar-compartido.ps1`. Solo JPEG/PNG/WebP: sin AVIF, sin GIF,
  sin SVG. No regenera miniaturas. Un "deshacer todo" sería el cierre.
- Fotos Rápidas: 40 productos fijos sin "cargar más"; no reordena la galería ni
  elige la principal; quitar una foto no borra el adjunto; CSS y JS embebidos
  en el PHP; "Creado por MJ Importaciones" impreso fijo (`:240`).
- `releases.config.json` excluye `app` y `react-app` por nombre a cualquier
  profundidad: el día que un plugin tenga `includes/app/`, se cae del zip sin
  aviso. `build-releases.ps1` no valida que la versión de la cabecera suba
  respecto del zip anterior.

---

## 5. Qué se puede sumar después

Lo que quedaba vigente del plan (`PLUGINS-MJ.md`), revisado contra lo que ya
existe. Tamaño: chico = días, mediano = una o dos semanas, grande = más.

### 5.1. Dentro del ERP (no son plugins nuevos)

- **Reposición.** Compras y proveedores ya están. Falta sugerir qué reponer
  cruzando lo vendido en el último mes con la lista de espera. Mediano.
- **Reservas con seña.** Turnos ya cobra señas y el ERP lleva cuenta
  corriente; lo específico es congelar el precio de un producto por unos días.
  Con precios en dólares es un argumento de venta fuerte. Chico a mediano.

### 5.2. Plugins nuevos, por orden de valor

1. **MJ Vencimiento de Pedidos.** Los pedidos sin pagar se cancelan solos
   pasadas las horas que se elijan, con aviso antes y cuenta atrás visible.
   Los comprobantes ya existen en MJ Medios de Pago, así que es la mitad del
   plan original. Con precios en dólares, un pedido abierto tres días es plata
   perdida. Mediano.
2. **MJ Facturador (ARCA).** Factura A/B/C según condición, CUIT o DNI
   validado, CAE en el pedido, PDF en Mi cuenta, datos fiscales guardados.
   **Arrancar ya el trámite** del certificado y el punto de venta: es lo
   lento. Grande, dos entregas.
3. **MJ Tiempos de Entrega.** "Disponible en 7 a 10 días" por SKU y por
   variación, en la tarjeta y en la ficha. Chico, saca consultas de encima.
4. **MJ Lista de Precios.** PDF o Excel al dólar del día, por categoría o
   marca, con o sin fotos, mayorista y minorista, y un link permanente que
   muestra la lista actualizada. Mediano, uso diario.
5. **MJ Avisos de Stock.** "Avisame cuando vuelva", lista de espera por
   producto que también dice qué reponer primero. Chico.
6. **MJ Precios Mayoristas.** Segundo precio en dólares, descuento por
   cantidad, cuentas aprobadas a mano, compra mínima. Mediano.
7. **MJ Carrito Abandonado.** Recordatorio a las horas elegidas, cupón chico
   en el segundo, cuánto se recuperó. Mediano.
8. **MJ Presupuestos.** El carrito ya arma un PDF en el navegador; falta que se
   guarde con número, validez, link para aceptar y conversión a pedido, con el
   dólar y el medio de pago con que se calculó. Mediano a grande.
9. **MJ Panel MJ.** Un tablero con el dólar del día, ventas de hoy,
   comprobantes pendientes, sin stock con gente esperando, márgenes de la
   semana. Chico, y hace que el conjunto se sienta un producto.
10. **MJ Combos y Packs**, **MJ Reseñas con Fotos**, **MJ Etiquetas y QR**
    (precios para la vidriera al dólar del día). Chicos a medianos.
11. **MJ WhatsApp** (avisos automáticos por cambio de estado). Único de la
    lista con costo mensual inevitable (WhatsApp Business API). La versión
    gratis ya está: abrir el chat con el mensaje escrito.

### 5.3. Lo grande que depende de terceros

- **Cotización en vivo de envíos** en las tiendas que todavía no tienen cuenta
  con ningún correo. En MJ Importaciones ya cotizan Andreani y Correo Argentino
  por el puente con sus plugins oficiales (1.3.0). Vía Cargo sigue siendo un
  formulario configurable a la espera del contrato de su API.
- **Alta de envío y etiquetas de despacho desde MJ Envíos.** Hoy el circuito de
  MJ Envíos termina en "cargá el número de seguimiento", de a uno o de a varios
  desde su pestaña Envíos. El alta y el rótulo se hacen en la pantalla del
  plugin del correo, que reconoce el pedido porque MJ Envíos se lo deja escrito.
  Traerlo adentro es una segunda vuelta: el alta crea un envío de verdad y no es
  idempotente.
- **Pago online de la seña** en Turnos (Mercado Pago con webhook), **agenda
  multi-profesional** (hoy todo el motor asume una) y **calendario** (un `.ics`
  para la clienta es el 20 % del valor con el 5 % del trabajo).
- **Portal para la colega** en MJ Colegas: hoy no existe; la dueña le manda el
  CSV. Con recibo de liquidación por mail o PDF.
- **MJ Core:** reporte de qué versión corre cada tienda, y volver a una
  versión anterior desde el panel.

### 5.4. Lo que no haría

- Copias de seguridad, seguridad y firewall: hay plugins gratuitos probados por
  miles de tiendas. Hacer uno propio es riesgo sin beneficio.
- Buscador avanzado: el de la tienda es rápido. Recién vale con más de mil
  productos, y ahí lo primero es la paginación de 4.1.
- Chat en vivo: WhatsApp ya cumple ese rol.
- Reescribir el ERP en React. Funciona, está entregado, y reescribir 72.000
  líneas para terminar donde ya se está es la peor inversión posible. Partir
  archivos y poner tests sobre la plata, sí.

---

## 6. El orden que sugiero

### Fase 0 — cerrar la v1 (sección 3)

1. Resolver la cuenta de GitHub para que el CI arranque, e instalar MJ Core en
   las dos tiendas que faltan. Nada de código. **Pendiente.**
2. ~~Los permisos del ERP y los frenos del tema.~~ Hecho el 18/9 (ERP 1.22.0,
   MJ Appearance 3.19.0).
3. ~~Los cuatro `uninstall.php` y la integridad al borrar una venta.~~ Hecho el
   18/9 (Turnos 1.2.1, Colegas 1.0.1, Fotos Rápidas 1.3.0, ERP 1.22.0).
4. ~~Los ajustes fantasma, el `*_REQUIRES`, la constante de Fotos Rápidas, los
   `readme.txt` y el i18n.~~ Hecho el 18/9. Quedan los cinco `LEEME.md` (3.5).
5. Las pruebas en la tienda real. Lo único que no se hace desde el código.
   **Pendiente**, y ahora con Caja, Reportes y los frenos sumados a la lista.
6. Lo que sigue abierto de 3.2 y 3.3: frenos y datos de la seña en Turnos, el
   `/cotizar` y el CSV de Envíos, el Optimizador y el `uninstall.php` de Medios
   de Pago.

### Fase 1 — lo que mueve plata

7. ~~Caja y Reportes en el ERP.~~ Hecho el 18/9 (ERP 1.22.0). Falta probarlos
   en la tienda (`PRUEBAS-EN-LA-TIENDA.md`, secciones 7 y 8).
8. MJ Vencimiento de Pedidos.
9. MJ Tiempos de Entrega.
10. Arrancar el trámite de ARCA aunque el Facturador venga después.

### Fase 2 — crecer

10. Reseñas en la ficha y el catálogo paginado (4.1), antes de que un catálogo
    grande lo pida a los gritos.
11. Lista de Precios, Avisos de Stock, Precios Mayoristas.
12. Tests sobre la plata del ERP y partir los archivos grandes.

### Fase 3 — lo que depende de afuera

13. Cotización en vivo y etiquetas, cuando haya cuenta con un correo.
14. Facturador, cuando esté el certificado.
15. Pago online de la seña y portal de la colega.

---

## 7. Lo que está bien y no se toca

Para que quede escrito, porque vale tanto como lo que falta:

- **La regla de "nada hardcodeado" se cumple en serio.** Ni un dato de las
  tres tiendas reales en el tema padre ni en la SPA; 92 claves de copy
  sincronizadas al 100 % entre PHP y JS; los hijos se limitan a describirse
  con `mj_skins`. En el ERP, 154 claves de configuración, todas en uso.
- **El aislamiento entre plugins.** Cada cruce pasa por `class_exists()` o por
  una fachada (`MJERP_Dolar`, `MJERP_Receipts`) que degrada sola. Verificado
  cruce por cruce en los nueve plugins.
- **Seguridad de base.** Todo el SQL con `prepare()` y `ORDER BY` en listas
  blancas; ni una salida sin escapar en PHP; nonce y capability en cada
  handler de admin; el ERP sin un solo `__return_true` y con el portal del
  revendedor que nunca acepta un `consignee_id` desde el request. La bóveda de
  comprobantes (carpeta privada, nombre aleatorio, MIME real, links firmados
  con HMAC y vencimiento) es de lo mejor del repo.
- **PHP 7.4 respetado sin excepciones** en los 160 archivos.
- **Migraciones de datos explicadas** en el ERP (`migrate_180/190/1130`
  documentan un bug de moneda perseguido en tres pasos), dinero en dos
  monedas congelado al día de la operación, saldos calculados de los
  movimientos y no guardados en una columna.
- **MJ Core:** descarga solo desde la API, redirección validada a https,
  SHA-256 con `hash_equals`, errores en castellano útil, y "lo último que se
  leyó" cuando GitHub no responde.
- **Turnos:** zona horaria tomada en serio en todo el motor, lock atómico por
  fecha, y un esquema de opciones que un tema hijo puede proponer sin pisar.
- **El circuito de publicación:** un zip por paquete con SHA-256, el
  manifiesto versionado, `MJ_Log` con copias verificadas por script, y la
  verificación local en cuatro pasos con la baseline de PHPCS.
- **Los comentarios en castellano explican el porqué, no el qué.** Es lo que
  hizo esta auditoría barata, y lo que va a hacer que el proyecto se pueda
  retomar en seis meses.
