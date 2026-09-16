# MJ Family

Temas y plugins propios de WordPress con los que funcionan mis tiendas. Todo
hecho a medida: nada alquilado, nada con suscripción mensual, nada que dependa
de que un desarrollador ajeno siga manteniendo su producto.

Este repositorio es la fuente de verdad. Cada tienda corre una combinación de
estas piezas, y lo que se sube a cada una sale siempre de acá.

---

## Qué hay adentro

### Temas

Un tema padre con todo el motor, y un tema hijo por tienda que solo cambia la
piel: paleta, tipografías y textos.

| Tema | Versión | Qué es |
| --- | --- | --- |
| **MJ Appearance** | 3.14.1 | El motor. Tienda completa en React (una sola página) sobre la Store API de WooCommerce: catálogo, carrito, checkout con compra rápida, cuenta y turnos. Con MJ ERP, el cliente ve su saldo a favor en «Mi cuenta» y lo usa al pagar. Modo día/noche, niveles de animación, barra de navegación armada ítem por ítem, paso de envío con validación de código postal y **todo** configurable desde Apariencia → MJ Appearance. |
| **GlossyHair** | 1.1.0 | Salón de alisados que además vende productos. Blanco y fucsia, Urbanist + Manrope. Portada pensada para turnos. |
| **Yamana** | 1.1.0 | Tejidos artesanales y cursos. Lino y terracota, Fraunces + Nunito. Incluye el proyecto colectivo "Entre Hilos". |
| **MJ Atelier** | 1.1.0 | Tienda editorial de lujo. Marfil y bronce, Fraunces + Manrope, movimiento cinematográfico. |

Los temas hijo no repiten nada del padre: se **describen** con el filtro
`mj_skins` (paleta, tipografías, textos, orden de la portada, paletas
alternativas) y el padre se encarga del resto. Sus colores aparecen en el panel
como paletas que se aplican con un botón y después se editan campo por campo.

### Plugins

| Plugin | Versión | Qué hace |
| --- | --- | --- |
| **MJ Turnos Online** | 1.1.0 | Reserva de turnos con seña, fichas técnicas por visita y panel de agenda para quien atiende. |
| **MJ Colegas** | 1.0.0 | Productos de terceros en la tienda: cada uno lleva quién lo hizo, y se liquida descontando la comisión. |
| **MJ Dólar** | 1.1.0 | Precios cargados en dólares y convertidos con el tipo de cambio que decido yo. Campos USD en productos, variaciones, edición en lote y CSV. |
| **MJ Medios de Pago** | 1.3.0 | Descuento o recargo según el medio de pago, con el precio reflejado en ficha, carrito y checkout. Cada medio de la tienda se declara equivalente a uno del ERP, y con eso hereda su caja de ingreso. |
| **MJ Envíos** | 1.1.1 | Los métodos de envío de la tienda, creados y editados por mí: a domicilio, a sucursal, retiro en el local, zona propia por código postal, gratis o a coordinar. Cada uno decide con qué condiciones aparece, con qué medios de pago se combina, qué datos pide y de dónde sale su precio: tabla propia, precio fijo o cotización en vivo con Vía Cargo, Andreani o Correo Argentino. |
| **MJ ERP** | 1.19.1 | Ventas (propias y de la tienda), clientes, costeo, compras, proveedores, stock y consignación, con portal para consignatarios. La venta se carga como en un mostrador en la compu y en cinco pasos en el celular. Lleva la entrega —envío, punto de encuentro, en mano— y el pedido en WooCommerce se emite solo, con su descuento de stock, cuando la venta se cobra o se entrega. Cada medio de pago tiene su caja de ingreso, y el precio sugerido se redondea como lo publica la tienda. Los productos de una venta sin cobrar se corrigen desde su detalle —stock y pedido incluidos— y cada venta lleva su historial. Una venta cobrada acepta devoluciones, cambios y garantías: la plata queda como saldo a favor del cliente (en la moneda en que pagó, y lo usa al pagar en la tienda) o se le devuelve; desde Clientes se le suma o resta a mano, con motivo y la venta a la que se refiere, o se usa para pagar lo que debe, y lo fallado espera en RMA hasta volver al stock o darse de baja como pérdida por garantías. El pedido de WooCommerce sigue a la venta: cobrada queda procesando y, entregada, completado. |
| **MJ Optimizador de Imágenes** | 1.1.0 | Achica y convierte las imágenes en mi propio servidor, sin límites ni créditos. |
| **MJ Fotos Rápidas** | 1.2.0 | Cargar las fotos de un producto desde el celular en dos toques y publicarlo ahí mismo. |
| **MJ Core** | 1.0.1 | Actualizaciones desde wp-admin: cuando publico una versión nueva, cada tienda que usa ese tema o plugin la ofrece con su botón de "Actualizar". |

Cada plugin hace una cosa y se puede desactivar sin romper el resto.

---

## Cómo se arma cada tienda

| Tienda | Tema | Plugins |
| --- | --- | --- |
| GlossyHair | GlossyHair | Core, Turnos Online, Medios de Pago, Envíos, Optimizador, Fotos Rápidas |
| Yamana | Yamana | Core, Colegas, Medios de Pago, Envíos, Optimizador, Fotos Rápidas |
| MJ Importaciones | MJ Atelier | Core, Dólar, Medios de Pago, Envíos, ERP, Optimizador, Fotos Rápidas |

Esas combinaciones están declaradas en
[`tools/releases.config.json`](tools/releases.config.json) y el script las
imprime al final, para saber qué zip lleva cada tienda.

---

## Sacar los zip para subir a una tienda

```powershell
.\tools\build-releases.ps1
```

Lee la versión de cada tema y plugin de su propia cabecera y **solo arma el zip
de los que tienen una versión que todavía no está en `releases/`**. Si ninguno
cambió, no compila ni escribe nada. Cuando aparece una versión nueva, el zip de
la anterior se borra: en la carpeta queda un único zip por paquete.

- `mj-glossyhair-1.1.0.zip` — el paquete, listo para subir a la tienda.
- `MANIFEST.md` — versión, tamaño y SHA-256 de cada uno.

Si tocaste código sin subir el número de versión, `-Force` lo rehace igual.

Los zip de cada versión publicada también quedan en las
[Releases](../../releases) de este repositorio, para bajarlos sin tener el
proyecto en la máquina:

```bash
gh release download v2026.09.09 --repo Manzaniita/mj-family --dir zips
```

### Publicar una versión nueva

```powershell
.\tools\publicar.ps1 -Mensaje "GlossyHair 1.2.0: nueva paleta"
```

Compila, arma los zip, comitea, etiqueta, sube todo a GitHub y publica los
paquetes como Release. Pide confirmación antes de subir y corta si algo no está
en condiciones.

> **Regla que no se saltea:** el tema padre **MJ Appearance** va primero. Un
> tema hijo sin su padre aparece como "tema roto" en WordPress.

El paso a paso completo está en [`PUBLICAR.md`](PUBLICAR.md).

---

## Documentación

| Archivo | Para qué |
| --- | --- |
| [`PUBLICAR.md`](PUBLICAR.md) | Cómo se saca una versión, cómo se numera y cómo se sube a una tienda. |
| [`CONFIGURACION.md`](CONFIGURACION.md) | Qué se configura y desde dónde. El índice de todos los ajustes. |
| [`PLUGINS-MJ.md`](PLUGINS-MJ.md) | El plan completo de la familia de plugins. |
| [`ANALISIS.md`](ANALISIS.md) | Cómo está el código hoy: qué está bien, qué está flojo y en qué orden conviene moverse. |
| [`PRUEBAS-EN-LA-TIENDA.md`](PRUEBAS-EN-LA-TIENDA.md) | Qué probar a mano en una tienda real, plugin por plugin. |
| [`GLOSSYHAIR-TURNOS.md`](GLOSSYHAIR-TURNOS.md) | Entrega y puesta en marcha de GlossyHair con turnos. |
| [`YAMANA-ENTRE-HILOS.md`](YAMANA-ENTRE-HILOS.md) | Entrega y puesta en marcha de Yamana con Entre Hilos. |

---

## Cómo está organizado

```
themes/
  mj-appearance/     tema padre — el motor
    react-app/       código fuente de la tienda (React + Vite)
    dist/            la tienda ya compilada (esto es lo que corre en el servidor)
    inc/             ajustes, sesión, skins, REST y configuración del frontend
  mj-glossyhair/     temas hijo: solo describen su piel
  mj-yamana/
  mj-atelier/
plugins/             un plugin por función
tools/               script de empaquetado y su configuración
releases/            los zip generados (no se versionan; ver Releases)
```

`dist/` **sí** se versiona a propósito: es lo que se sube al servidor, donde no
hay Node para compilar. Eso hace que bajar una etiqueta del repositorio alcance
para desplegar.

---

## Verificar antes de publicar

```powershell
.	oolserificar.ps1
```

Cuatro pasos: que todos los `.php` compilen, que nada use sintaxis que reviente
en PHP 7.4, que no aparezcan avisos nuevos de seguridad o de base de datos, y
que las dos apps de React compilen. Tarda un par de minutos; con `-Rapido` se
saltea los builds, que es lo que más tarda.

Lo mismo corre solo en GitHub en cada push, con
[`.github/workflows/verificar.yml`](.github/workflows/verificar.yml).

La primera vez hay que instalar las herramientas:

```powershell
winget install --id PHP.PHP.8.4 --scope user
composer install
```

Sobre los avisos de PHPCS: hay 512 anotados en
[`tools/phpcs-baseline.json`](tools/phpcs-baseline.json), casi todos falsos
positivos de un linter que no entiende el contexto (marca "falta nonce" cada
vez que se lee `$_GET`, aunque sea para mostrar un cartel). La verificación
corta solo si aparece uno **nuevo**, así lo viejo queda a la vista sin frenar
el trabajo. Si arreglaste deuda de verdad, `.	oolserificar.ps1 -Foto`
vuelve a sacar la foto.

---

## Trabajar en el proyecto

Requisitos: Node 20 o superior, PowerShell para el empaquetado, y PHP 8.4 más
Composer para la verificación.

```powershell
# Tienda del tema, con recarga en vivo
cd themes\mj-appearance\react-app
npm install
npm run dev

# App de reservas del plugin de turnos
cd plugins\mj-turnos-online\app
npm install
npm run dev
```

Regla de la casa: **nada que dependa del negocio vive en el código.** Colores,
textos, rutas, plazos, permisos y proveedores externos salen de un ajuste del
panel, de una constante de `wp-config.php` o de un filtro. En el código queda la
lógica: cómo se calcula un precio, cómo se valida una reserva, cómo se aclara un
color para el modo noche.

---

## Versiones

Se usa `MAYOR.MENOR.PARCHE` y cada publicación lleva su etiqueta de git
(`v2026.09.09`). Los `.zip` de esa fecha quedan colgados de la etiqueta, y el
`MANIFEST.md` guarda el SHA-256 de cada uno: siempre se puede verificar qué
build exacto se subió a una tienda.

---

© MJ. Todos los derechos reservados. Código privado, no distribuir.
