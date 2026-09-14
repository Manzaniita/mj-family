# MJ Family — análisis y próximos pasos

Escrito el 12 de septiembre de 2026, sobre el commit `ce68647`.

Esto es una lectura del repositorio como está hoy: qué está bien, qué está
flojo, y en qué orden conviene moverse. No toqué nada del código.

---

## 1. Qué hay, en números

| | |
| --- | --- |
| Archivos versionados | 363 |
| Líneas de código propio (sin `dist/`, sin dependencias) | ~128.000 |
| Plugins | 8 |
| Temas | 1 padre + 3 hijos |
| Hooks propios (`do_action` / `apply_filters`) | 186 |
| Rutas REST | 125 |
| Commits | 10, todos entre el 9 y el 11 de septiembre |

Reparto del código:

| Pieza | Líneas | Peso |
| --- | --- | --- |
| MJ ERP | ~60.600 | 47% |
| MJ Medios de Pago | ~7.500 | 6% |
| MJ Envíos | ~7.000 | 5% |
| MJ Appearance (fuente React + PHP) | ~7.100 + ~3.000 | 8% |
| MJ Turnos Online | ~6.000 (sin `node_modules`) | 5% |
| MJ Optimizador | ~3.900 | 3% |
| MJ Dólar | ~3.100 | 2% |
| MJ Colegas | ~1.700 | 1% |
| MJ Fotos Rápidas | 581 | <1% |

**MJ ERP es prácticamente la mitad del proyecto.** Ese dato vuelve más abajo.

---

## 2. Lo que está bien hecho

No lo digo por cortesía: hay decisiones acá que mucha gente que cobra por esto
no toma.

### 2.1. La arquitectura de temas

Un padre con el motor y tres hijos que **solo se describen** con el filtro
`mj_skins`. Los hijos no repiten nada: [functions.php](themes/mj-glossyhair/functions.php)
declara paleta, tipografías y textos, y listo. Agregar una cuarta tienda es
escribir un archivo, no clonar un tema. Está muy bien resuelto.

### 2.2. El desacople entre plugins

Los cruces entre plugins pasan siempre por `class_exists()` o
`function_exists()` — 44 chequeos contra `MJERP_Settings`, 13 contra
`MJERP_Dolar`, 10 contra `MJPM_Receipts`. Ningún plugin explota si falta otro.
Eso es exactamente lo que promete el README ("cada plugin hace una cosa y se
puede desactivar sin romper el resto") y, a diferencia de casi todos los
proyectos que prometen eso, acá **se cumple en el código**.

### 2.3. La base de datos

MJ ERP tiene una capa de repositorios ([includes/repositories/](plugins/mj-erp/includes/repositories/))
en vez de SQL desparramado. `dbDelta` con versión de tabla y upgrades
([class-mjerp-install.php:164](plugins/mj-erp/includes/class-mjerp-install.php#L164)),
`uninstall.php` en cada plugin. Las consultas usan `prepare()`; las 15 que no,
son `TRUNCATE`, `DROP` y `COUNT(*)` sobre nombres de tabla propios, todas con su
`phpcs:ignore` y su motivo. **No encontré una sola inyección SQL.**

### 2.4. La seguridad de las rutas públicas

Los `permission_callback => '__return_true'` asustan hasta que se lee el
comentario de [class-mjpm-receipts-rest.php:14-18](plugins/mj-medios-pago/includes/class-mjpm-receipts-rest.php#L14-L18):
la ruta es pública a propósito porque hay tres formas legítimas de llegar
(quien revisa por capability, el cliente logueado dueño, el invitado con link
firmado) y el permiso se resuelve adentro, en `access()`. Es la decisión
correcta, y está documentada donde hay que documentarla.

### 2.5. La regla de "nada hardcodeado"

[CONFIGURACION.md](CONFIGURACION.md) tiene 35.000 caracteres documentando qué se
configura y desde dónde, con una sección final ("Lo que a propósito NO es
configurable") que es justo lo que falta en la mayoría de las documentaciones.
La regla se sostiene en el código.

### 2.6. El empaquetado

[tools/build-releases.ps1](tools/build-releases.ps1) lee la versión de cada
cabecera, arma solo lo que cambió, borra el zip viejo, y deja
[releases/MANIFEST.md](releases/MANIFEST.md) con tamaño y SHA-256. Siempre se
puede verificar qué build exacto se subió a una tienda. Eso es higiene de
release que muchos equipos no tienen.

---

## 3. Los problemas, por orden de riesgo

### 3.1. 🔴 No hay ninguna verificación automática — este es el problema número uno

- Sin CI: no existe `.github/`.
- Sin tests: cero archivos de test en todo el repo.
- Sin linter, ni de PHP ni de JavaScript.
- Sin `php -l`: **no hay PHP instalado en la máquina.**

Son 128.000 líneas que corren en tres tiendas reales, y la única red de
seguridad es leer con atención. Un punto y coma de menos en un `.php` no da un
error prolijo: da una **pantalla blanca en una tienda con clientes adentro**, y
te enterás cuando alguien te escribe.

Esto no es teórico. Cada vez que se edita PHP sin poder ejecutarlo, se está
apostando. Y el riesgo crece con el tamaño: el proyecto creció casi a la mitad
de su tamaño actual en tres días.

**Qué haría, en este orden:**

1. Instalar PHP local (`winget install PHP.PHP.8.3`) aunque sea solo para
   `php -l`. Cinco minutos, y de golpe cada archivo se puede verificar antes de
   subirlo.
2. Un workflow de GitHub Actions que corra `php -l` sobre todos los `.php` en
   cada push. Es gratis, tarda 30 segundos, y atrapa el 90% de lo que rompe una
   tienda.
3. Sumarle PHPCS con el estándar `WordPress-Extra`. Ya hay `phpcs:ignore` en el
   código, así que el estándar ya se está asumiendo — falta correrlo.
4. Que el mismo workflow corra `npm run build` de los dos proyectos Vite. Si el
   build del tema falla, hoy te enterás recién cuando vas a publicar.

Costo: una tarde. Es, de lejos, lo que más devuelve de todo este documento.

### 3.2. 🔴 No hay rastro de errores en ningún lado

`error_log` aparece **cero veces** en todo el repositorio.

MJ Envíos hace lo correcto con su propio `MJE_Geo::log()` — es el patrón que
falta generalizar. El resto no deja huella de nada:

- Si MJ ERP no emite el pedido de WooCommerce al cobrar una venta, no queda
  registro de por qué.
- Si falla la subida de un comprobante, el cliente ve un error genérico y vos no
  tenés nada para mirar.
- Si el cron de MJ Dólar no actualiza el tipo de cambio, la tienda vende con el
  dólar de ayer y nadie se entera.

Ese último caso, con precios atados al dólar, es plata directa.

**Qué haría:** un `MJ_Log` compartido, con nivel configurable desde el panel
(apagado / errores / todo), escribiendo sobre `wc_get_logger()` para que
aparezca en WooCommerce → Estado → Registros, que ya existe y ya sabés mirar. Es
un archivo de 80 líneas.

### 3.3. 🟠 Nadie sabe qué versión corre cada tienda

El despliegue es: armar el zip, entrar a cada tienda, subirlo a mano. Tres
tiendas, once paquetes. No hay mecanismo de actualización, así que:

- No hay forma de saber si GlossyHair está corriendo MJ Envíos 1.0.0 o quedó en
  una versión anterior.
- Actualizar un plugin en las tres tiendas son quince minutos de clics.
- Si una actualización rompe algo, volver atrás es el mismo trabajo otra vez.

**Qué haría:** un `mj-core` mínimo instalado en las tres tiendas que (a) le diga
a WordPress dónde buscar actualizaciones —las Releases de este repo, que ya
existen y ya tienen los zip— y (b) exponga un endpoint que reporte qué versiones
tiene puestas. Con eso, actualizar pasa a ser un botón en cada tienda, y el
estado de las tres se ve desde un solo lugar.

La pieza (a) es `pre_set_site_transient_update_plugins` más un JSON; no hace
falta librería externa, aunque `plugin-update-checker` lo resuelve en veinte
líneas y está muy probado.

### 3.4. 🟠 MJ ERP se está convirtiendo en un monolito

60.600 líneas, el 47% del proyecto, en un plugin. Los síntomas ya están:

- [page-settings.js](plugins/mj-erp/assets/js/page-settings.js) — 3.345 líneas en un archivo.
- [app.css](plugins/mj-erp/assets/css/app.css) — 5.301 líneas en un archivo.
- [class-mjerp-purchases-repo.php](plugins/mj-erp/includes/repositories/class-mjerp-purchases-repo.php) — 2.314 líneas.
- El frontend es JavaScript plano sobre un namespace global `window.MJ`
  ([core.js](plugins/mj-erp/assets/js/core.js)), mientras el tema es React. Dos
  frontends con cero código compartido.

Que sea JS plano no está mal en sí: carga rápido, no necesita build, y el router
propio funciona. El problema es el tamaño. A este ritmo, en seis meses cada
cambio en el ERP es una operación de riesgo.

**Qué NO haría:** reescribirlo en React. Funciona, está entregado, y reescribir
60.000 líneas para terminar donde ya estás es la peor inversión posible.

**Qué SÍ haría:**

1. Poner tests —unitarios, de verdad— sobre la parte que calcula plata:
   `MJERP_Costing`, `MJERP_Pricing`, el redondeo, la cuenta corriente de
   consignación, `calc.js`. Es un porcentaje chico del código y es donde un
   error cuesta dinero real y silencioso.
2. Partir los archivos de más de 1.500 líneas. Mecánico, sin riesgo, y hace que
   el próximo cambio se pueda leer.
3. Congelar el alcance del ERP. Todo lo nuevo que aparezca —rentabilidad,
   reposición, presupuestos— evaluarlo primero como *extensión*, no como plugin
   nuevo ni como más código adentro del mismo archivo.

### 3.5. 🟠 SEO: la tienda es invisible para Google

El tema sirve [index.php](themes/mj-appearance/index.php) con un
`<div id="root"></div>` y nada más. No hay `<title>` por producto, ni
descripción, ni Open Graph, ni JSON-LD, ni sitemap.

Consecuencias concretas, hoy:

- Un producto tuyo no aparece en Google salvo que busquen la marca.
- Mandar el link de un producto por WhatsApp muestra una caja vacía, sin foto ni
  precio. En Argentina, donde la venta pasa por WhatsApp, eso es una venta menos
  cada vez.
- Google Shopping, Instagram Shopping y cualquier comparador quedan afuera.

El plan lo tiene como "MJ SEO, Etapa 2". **Lo subiría a lo primero después de la
Fase 0**, y lo partiría en dos:

- **Parte barata (un día, no un plugin):** que `index.php` detecte la ruta
  pedida y emita en el `<head>` el título, la descripción, las etiquetas Open
  Graph y un `JSON-LD` de `Product` con precio y disponibilidad. Eso solo ya
  arregla el compartir por WhatsApp y la mitad del SEO.
- **Parte grande (el plugin):** campos editables por producto, sitemap, feed de
  Shopping.

Hacer la parte barata ya, aunque el plugin quede para más adelante.

### 3.6. 🟠 MJ Envíos nunca habló con un correo real

Los tres carriers —[Andreani](plugins/mj-envios/includes/carriers/class-mje-carrier-andreani.php),
[Correo Argentino](plugins/mj-envios/includes/carriers/class-mje-carrier-correo.php),
[Vía Cargo](plugins/mj-envios/includes/carriers/class-mje-carrier-viacargo.php)—
están escritos contra la documentación, sin cuenta y sin una sola llamada real.
El código está bien armado (maneja `WP_Error`, cachea el token, deja log), pero
**ninguna API se comporta como su documentación.**

Riesgo si se activa en producción sin probar: el checkout cotiza mal o se
cuelga, y se pierde la venta en el último paso.

**Qué haría:** no activar cotización en vivo hasta tener cuenta real de al menos
uno. Mientras tanto, la tabla propia y el precio fijo —que ya existen y son las
opciones seguras— cubren el caso. Cuando llegue la cuenta, probar contra el
ambiente de pruebas del correo antes de tocar la tienda.

### 3.7. 🟡 Las traducciones están a mitad de camino

- MJ ERP: 645 strings con `__()`.
- MJ Optimizador: 94.
- MJ Appearance: 2.
- Todo lo demás: cero, castellano escrito directo en el código.
- No hay carpeta `languages/` ni archivos `.pot` en ningún plugin.

Hoy es lo peor de los dos mundos: el costo de escribir `__()` sin el beneficio
de poder traducir. Hay que decidir:

- **Si esto nunca sale de tus tiendas en castellano:** sacar el i18n. Menos
  código y más legible.
- **Si algún día lo vendés o lo compartís:** completarlo y generar los `.pot`.

Decidilo, no lo dejes en el medio. Mi apuesta: es código privado para tres
tiendas argentinas, así que **sacaría el i18n**.

### 3.8. 🟡 El historial de git no sirve para volver atrás

Diez commits en tres días, y son enormes. `ce68647 "Agrega MJ Envíos y el paso
de envío en el checkout"` mete un plugin de 7.000 líneas *y* toca el tema. Si
mañana el checkout falla, `git bisect` te dice "fue ese commit" y no te ayuda en
nada.

**Qué haría:** commits por unidad de cambio, y una rama por funcionalidad que se
integra cuando anda. No hace falta ceremonia; alcanza con que cada commit sea
una cosa sola.

### 3.9. 🟡 `dist/` versionado

Está bien justificado (el servidor no tiene Node) y lo comparto. Pero tiene un
costo: cada build ensucia el diff con miles de líneas minificadas, y dos ramas
que toquen el tema chocan siempre en `dist/`.

**Alternativa, cuando haya CI:** que GitHub Actions compile y adjunte el `dist`
al zip de la Release, y sacar `dist/` del repositorio. Cambio opcional, no
urgente — pero si vas a poner CI igual (3.1), sale casi gratis.

### 3.10. 🟡 Cosas repetidas en los ocho plugins

Cada plugin trae su propia versión de: formato de precio, redondeo, validación
de código postal, render de pantallas de ajustes, CSS de admin, manejo de
`WP_Error` en REST. No es dramático —son plugins chicos— pero cada arreglo hay
que hacerlo ocho veces.

**Qué haría:** el mismo `mj-core` de 3.3, con los helpers compartidos adentro.
**Con una advertencia:** eso crea una dependencia dura, y hoy la gracia del
proyecto es que cada plugin se desactiva solo. Conviene mantener el patrón que
ya usás: `class_exists('MJ_Core_Money')` con un fallback interno. Si `mj-core`
no está, el plugin sigue funcionando con su copia.

---

## 4. El plan de PLUGINS-MJ.md está desactualizado

[PLUGINS-MJ.md](PLUGINS-MJ.md) dice "Actualizado el 4 de septiembre", y MJ ERP
1.14 ya hace varias cosas que ahí figuran como pendientes:

| Lo que el plan propone | Lo que ERP 1.14 ya hace |
| --- | --- |
| **MJ Punto de Venta** ("grande") | El mostrador de ventas ya está: venta en cinco pasos en el celular, cobro por medio de pago, caja por medio. |
| **MJ Rentabilidad** ("mediano, valor muy alto") | `MJERP_Costing` ya carga costo y calcula margen. Falta el *tablero*, no el motor. |
| **MJ Proveedores y Reposición** ("grande") | Compras y proveedores ya están, con su repositorio. Falta la sugerencia de reposición. |
| **MJ Reservas y Señas** ("mediano") | MJ Turnos Online ya cobra seña, y el ERP ya lleva cuenta corriente. |

Traducido: **tres de los ítems marcados como "grande" son en realidad una
pantalla de reportes sobre datos que ya tenés.** Eso cambia el orden de
prioridades por completo, y es una noticia buena.

Actualizar ese documento es en sí una tarea pendiente, porque hoy te está
haciendo planificar trabajo que ya está hecho.

---

## 5. El orden que sugiero

### Fase 0 — la red de seguridad (antes de una línea nueva de funcionalidad)

Estimado: una o dos semanas de trabajo intermitente. Nada de esto se ve en la
tienda, y todo lo demás depende de esto.

1. **PHP local y `php -l` en CI.** Una tarde. El cambio más grande por menos
   esfuerzo de toda la lista.
2. **PHPCS con `WordPress-Extra`** en el mismo workflow.
3. **`MJ_Log` compartido** sobre `wc_get_logger()`.
4. **Probar en la tienda real** MJ Dólar, MJ Medios de Pago y MJ Envíos, que es
   lo que el propio plan pone como "Ahora" desde hace más de una semana.
5. **Actualizar `PLUGINS-MJ.md`** con lo que el ERP ya cubre.

### Fase 1 — lo que mueve plata ahora

6. **SEO server-side, la parte barata.** Meta, Open Graph y JSON-LD en
   `index.php`. Un día. Arregla el compartir por WhatsApp, que hoy es una venta
   perdida cada vez.
7. **MJ Vencimiento de Pedidos y Comprobantes.** El plan lo tiene primero y
   tiene razón: con precios en dólares, un pedido sin pagar abierto tres días es
   plata perdida.
8. **Tablero de rentabilidad dentro del ERP** (no plugin nuevo). El costeo ya
   existe; es una pantalla.
9. **MJ Tiempos de Entrega.** Chico, y saca consultas de encima.

### Fase 2 — lo que tarda por motivos ajenos

10. **MJ Facturador (ARCA).** Arrancar **ya** el trámite del certificado y el
    punto de venta, aunque el código venga después: el trámite es lo lento.
11. **MJ Lista de Precios.** Uso diario, y con MJ Dólar ya resuelto es directo.
12. **MJ Avisos de Stock.** Chico y rendidor.

### Fase 3 — crecer

13. Actualizaciones automáticas (`mj-core` como update server). Subilo acá si
    para entonces ya duele.
14. Precios mayoristas · Carrito abandonado · Presupuestos · Panel MJ.

### Fase 4 — lo grande

15. Cotización en vivo de envíos, **cuando existan las cuentas reales**.
16. Etiquetas de despacho.

### Lo que sacaría del plan

- **MJ Punto de Venta**: ya está en el ERP.
- **MJ Proveedores y Reposición** como plugin: es una pantalla del ERP.
- **MJ Fidelidad**: el propio plan lo pone último y coincido. Salteálo.

---

## 6. Si tuviera que elegir tres cosas

1. **`php -l` en CI.** Hoy estás editando a ciegas código que corre en tiendas
   con clientes adentro. Todo lo demás de este documento es mejora; esto es un
   riesgo abierto.
2. **Meta tags y Open Graph en el `<head>`.** Un día de trabajo que cambia lo
   que pasa cada vez que mandás un link por WhatsApp.
3. **Tests sobre el cálculo de plata del ERP.** Costeo, redondeo, consignación.
   Un error ahí no rompe nada visible: simplemente vendés mal durante meses.

---

## 7. Lo que está bien y no tocaría

Para que quede escrito, porque es tan importante como lo que falta:

- La separación tema padre / temas hijo. Está resuelta mejor que en la mayoría
  de los temas comerciales.
- El desacople entre plugins vía `class_exists`. Funciona, es simple, y no
  inventa un framework.
- La capa de repositorios del ERP y el uso de `prepare()`.
- El sistema de release con SHA-256 y manifiesto.
- La regla de "nada hardcodeado" y `CONFIGURACION.md`. Es el activo más valioso
  del proyecto después del código: cuando vuelvas en seis meses, eso es lo que
  te va a salvar.
- La decisión de no hacer plugins de backup ni de seguridad. Correcta.
