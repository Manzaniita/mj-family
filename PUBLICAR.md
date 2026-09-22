# Cómo publicar una versión y cómo llega a las tiendas

Todo lo que corre en una tienda sale de este repositorio. Este documento explica
el circuito completo: cómo se arma un zip, cómo se publica, cómo lo pide cada
tienda sola, y qué hay que tocar cuando se crea un plugin o un tema nuevo para
que todo siga sincronizado.

## Consulta por tema

Preparar y verificar un cambio no equivale a publicarlo. Consultá el
checklist del caso, y la sección de publicación solo cuando corresponda.

- [Circuito completo](#circuito)
- [Armar ZIP y configurar herramientas](#empaquetar)
- [MJ Core y acceso al repositorio privado](#actualizaciones)
- [Crear una Release](#publicar)
- [Qué versiones cambiar](#versiones)
- [Primera instalación o vuelta atrás](#instalar)
- [Checklist de un paquete nuevo](#paquete-nuevo)
- [Checklist de un cambio existente](#cambio-existente)
- [Errores que rompen la distribución](#prohibiciones)
- [Diagnóstico](#diagnostico)

<a id="alcance"></a>

## Alcance de cada acción

| Pedido | Hasta dónde se trabaja |
| --- | --- |
| Analizar | Lectura y diagnóstico; sin editar. |
| Implementar o preparar para GitHub | Cambios, documentación, versiones si corresponden y verificación local. |
| Subir código al repositorio | Commit/push según lo pedido; no crea por sí solo una Release. |
| Publicar una versión | Empaquetar, commit, etiqueta, push y Release; requiere pedido explícito. |

Los checklists separan preparación y publicación. No invoques `publicar.ps1`
para un cambio únicamente de documentación de desarrollo. El script usa
`git add -A`: revisá todos los cambios antes de ejecutarlo y no incluyas trabajo
ajeno. Su confirmación interactiva sucede después de generar los ZIP.

---

<a id="circuito"></a>

## 1. El circuito, de un vistazo

```
  cambio en el código
        │
        ▼
  subir la versión  ──────────  cabecera del plugin o style.css (+ su constante)
        │
        ▼
  .\tools\verificar.ps1  ─────  PHP, reglas, builds, copias y documentación
        │
        ▼
  .\tools\publicar.ps1  ──────  arma los zip, commit, etiqueta, push
        │                       y Release de GitHub con TODOS los zip
        ▼
  GitHub Releases  (Manzaniita/mj-family, privado)
        │
        │   cada tienda consulta cada 6 horas con su token (MJ Core)
        ▼
  Escritorio → Actualizaciones  ──  "Actualizar ahora"  (o automática)
```

Tres reglas sostienen todo lo demás:

1. **Si la versión no sube, la tienda no se entera.** MJ Core compara números,
   no archivos.
2. **La carpeta es la identidad del paquete.** El zip `mj-erp-1.16.0.zip` trae la
   carpeta `mj-erp/`, y la tienda lo reconoce porque tiene un plugin instalado en
   `mj-erp/`. Una carpeta no se renombra nunca.
3. **MJ Core actualiza, no instala.** Un plugin o tema nuevo se sube a mano la
   primera vez en cada tienda que lo use. Después ofrece las versiones nuevas;
   la instalación automática depende de lo habilitado en WordPress.

---

<a id="empaquetar"></a>

## 2. Sacar los zip

<a id="herramientas"></a>

### Configuración de herramientas

| Fuente | Qué controla |
| --- | --- |
| `tools/releases.config.json` → `roots` | Carpetas de plugins y temas a detectar. |
| `builds` | Instalación, compilación y archivo esperado de cada app. |
| `exclude` | Nombres y patrones excluidos del ZIP, aplicados en cada nivel. |
| `bundles` | Paquetes que componen cada tienda; no instala nada. |
| `output` | Carpeta de ZIP y manifiestos generados. |
| `tools/sincronizar-compartido.ps1` → `$compartidos` | Fuente y consumidores de cada archivo compartido. |
| `tools/verificar.ps1` y `.github/workflows/verificar.yml` | Controles locales, versiones de CI y apps a compilar. |
| `.github/workflows/documentacion.yml` | Control liviano de documentos e instrucciones. |

Los `AGENTS.md`, overrides y carpetas locales de agentes quedan fuera del ZIP.
No excluyas todos los Markdown: los `LEEME.md` de usuario sí se distribuyen.
No edites `releases/MANIFEST.md` ni `releases/manifest.json` a mano.

```powershell
.\tools\build-releases.ps1
```

Eso hace, en orden:

1. **Lee la versión** de cada tema y plugin de su propia cabecera
   (`style.css` en los temas, el comentario `Version:` en los plugins) y la
   compara con los zip que ya están en `releases/`.
2. **Compila** la tienda (React) del tema o la app de reservas del plugin de
   turnos **solo si ese paquete tiene una versión nueva**. Si falta
   `node_modules`, lo instala solo.
3. **Arma el zip** de los paquetes que cambiaron, sin código fuente ni
   dependencias: solo lo que corre en el servidor. Adentro va la carpeta con el
   nombre del paquete (`mj-erp/…`), que es lo que WordPress reemplaza.
4. **Borra el zip de la versión anterior** de esos paquetes.
5. **Escribe el manifiesto** con la versión, el tamaño y el SHA-256 de cada zip,
   y lista qué zip necesita cada tienda.

Si no hay ninguna versión nueva, no compila, no arma nada y lo dice.

```powershell
.\tools\build-releases.ps1 -Force                        # rehace aunque la versión no haya cambiado
.\tools\build-releases.ps1 -SkipBuild                    # no recompila React
.\tools\build-releases.ps1 -Only mj-glossyhair           # un solo paquete
```

`-Force` sirve para rearmar un zip en la máquina, pero **no genera una
actualización**: con el mismo número, ninguna tienda lo va a ofrecer.

### Qué queda en `releases/`

| Ruta | Qué es |
| --- | --- |
| `mj-glossyhair-1.1.0.zip` | El paquete listo. Uno solo por paquete: el de la última versión. No se versiona en git. |
| `MANIFEST.md` | Tabla con versión y SHA-256 de cada zip. Sí se versiona. |
| `manifest.json` | Lo mismo, para scripts. |

---

<a id="actualizaciones"></a>

## 3. Las actualizaciones automáticas (MJ Core)

Con **MJ Core** instalado, cada vez que se publica una versión nueva, las
tiendas que usan ese tema o plugin la muestran como cualquier actualización de
WordPress: en **Escritorio → Actualizaciones**, en la lista de plugins y en
Apariencia → Temas, con su botón de "Actualizar ahora". También funciona el
"Activar actualizaciones automáticas" de cada plugin.

### Cómo decide qué ofrecer

1. **Lee las Releases.** Cada 6 horas (se cambia en MJ ·
   Actualizaciones) consulta las últimas 20 Releases del repositorio. Salta las
   que están en borrador o marcadas como prerelease.
2. **Arma el catálogo por nombre de archivo.** De `mj-erp-1.16.0.zip` saca la
   carpeta (`mj-erp`) y la versión (`1.16.0`). Si un paquete aparece en varias
   Releases, gana la versión más alta.
3. **Cruza con lo instalado.** Por cada plugin y tema de la tienda cuya carpeta
   esté en el catálogo, compara la versión instalada con la publicada. Si la
   publicada es mayor, la ofrece. Si no, lo marca como al día.
4. **No toca lo ajeno.** WooCommerce, los temas de WordPress y cualquier cosa
   cuya carpeta no esté en las Releases siguen con su actualización normal. Si
   WordPress.org tuviera un plugin con la misma carpeta que uno de MJ, para esa
   carpeta manda lo publicado por MJ.

### Cómo instala

Al apretar "Actualizar", MJ Core baja el zip él mismo, porque el repositorio es
privado y WordPress solo no tiene el token:

1. Pide el archivo a la API de GitHub con el token.
2. GitHub responde con un enlace firmado que vale unos minutos, y MJ Core lo baja
   de ahí (ese segundo pedido va sin token).
3. Compara el SHA-256 con el que GitHub publica de cada archivo. **Si no
   coincide, no instala nada** y lo anota en MJ · Registros.
4. Le pasa el zip a WordPress, que reemplaza la carpeta como en cualquier
   actualización. Los ajustes viven en la base de datos y no se tocan.

Si GitHub no responde, la tienda sigue ofreciendo lo último que leyó, anota el
error y reintenta en una hora.

### Para no esperar las 6 horas

- **MJ → Actualizaciones → Buscar versiones nuevas ahora**, o
- **Escritorio → Actualizaciones → Buscar de nuevo**.

Las dos consultan GitHub en el momento.

### Qué muestra la pantalla

MJ → Actualizaciones tiene una tabla con cada paquete MJ instalado,
la versión que tiene la tienda, la publicada y el estado. Un paquete que no
aparece ahí no tiene zip en las Releases, así que la tienda no lo va a
actualizar. Pasa con un plugin nuevo que todavía no se publicó.

Que la instalada sea **mayor** que la publicada también figura como "al día".
Quiere decir que se subió algo a mano sin publicarlo: conviene publicar.

### Ponerlo en marcha en una tienda (una sola vez)

1. **Crear el token en GitHub** con la cuenta Manzaniita:
   <https://github.com/settings/personal-access-tokens/new>
   - Nombre: el de la tienda (`MJ Importaciones - actualizaciones`). Un token por
     tienda: así se revoca uno sin tocar las otras.
   - Repository access: *Only select repositories* → `Manzaniita/mj-family`.
   - Permissions: **Contents: Read-only**, y nada más (Metadata se agrega solo).
   - Copiar el `github_pat_…` al generarlo: GitHub no lo vuelve a mostrar.
2. **Subir `mj-core-<versión>.zip`** a mano y activarlo.
3. **MJ → Actualizaciones**: repositorio `Manzaniita/mj-family`, pegar
   el token y **Guardar**. El campo del token tiene que pasar a decir "Hay uno
   guardado". Si sigue diciendo `github_pat_…`, no se guardó.
4. **Buscar versiones nuevas ahora.** La tabla se llena.

Si preferís que el token no quede en la base de datos, va en `wp-config.php` y
manda sobre el panel:

```php
define('MJ_CORE_REPO', 'Manzaniita/mj-family');
define('MJ_CORE_TOKEN', 'github_pat_...');
```

Desde ahí, MJ Core se actualiza a sí mismo como los demás.

### Estado de cada tienda

El único registro operativo está en [TIENDAS.md](TIENDAS.md#composicion).
Cuando se instala MJ Core o se cambia un token, actualizá ahí el estado y la
fecha de comprobación. No guardes el valor de tokens en la documentación.

---

<a id="publicar"></a>

## 4. Publicar en GitHub

```powershell
.\tools\publicar.ps1 -Mensaje "GlossyHair 1.2.0: nueva paleta"
```

Eso hace, en orden: comprueba que git y la sesión de GitHub estén bien, arma los
paquetes (sección 2), comitea lo que haya cambiado, crea la etiqueta con la
fecha de hoy, sube rama y etiqueta, y publica **todos los zip** de `releases/`
como **Release** colgada de esa etiqueta. Después de empaquetar y antes del
commit/push muestra el resumen y pide confirmación. No ejecuta por sí mismo
`verificar.ps1`: ese control debe haber pasado antes.

Que suban todos los zip y no solo los que cambiaron es a propósito: cada
Release queda completa por sí sola, y el catálogo de MJ Core nunca pierde un
paquete aunque haga meses que no cambia.

```powershell
.\tools\publicar.ps1 -Mensaje "Arregla el carrito" -Version v2026.10.01b   # segunda publicación del día
.\tools\publicar.ps1 -Mensaje "Paquetes ya compilados y verificados" -SkipBuild
.\tools\publicar.ps1 -Mensaje "..." -Notas notas.md                        # notas propias
```

La etiqueta por defecto es la fecha (`v2026.09.14`). Si ya se publicó ese día,
el script corta antes de tocar nada y pide otra con `-Version`.

Las notas de la Release son las que la tienda muestra en "Ver los detalles de
la versión". Con `-Notas` se pueden escribir para quien actualiza: qué cambió y
si hay que revisar algo después.

### Requisitos de la máquina

- `gh` con sesión activa de **Manzaniita** y permiso `workflow`. Sin ese
  permiso, GitHub rechaza cualquier push que toque `.github/workflows/`:
  ```powershell
  gh auth refresh -h github.com -s workflow
  gh auth setup-git
  ```
- Si algo no está en condiciones (falta `gh`, no hay sesión, la etiqueta ya
  existe), el script corta **antes** de tocar el repositorio.

### A mano, si preferís

```bash
git add -A
git commit -m "GlossyHair 1.1.0: paletas configurables"
git tag -a v2026.09.09 -m "GlossyHair 1.1.0, MJ Appearance 3.6.0, Turnos 1.1.0"
git push origin main
git push origin v2026.09.09
gh release create v2026.09.09 releases/*.zip --title "v2026.09.09"
```

Sin `releases/*.zip` completo, la Release queda sin algunos paquetes. MJ Core
los sigue encontrando en Releases anteriores, pero solo mientras estén entre
las últimas 20.

---

<a id="versiones"></a>

## 5. Numerar las versiones

Se usa `MAYOR.MENOR.PARCHE`:

- **PARCHE** (1.1.0 → 1.1.1): arreglos que no cambian nada de lo que se ve.
- **MENOR** (1.1.0 → 1.2.0): ajustes o funciones nuevas, sin romper lo anterior.
- **MAYOR** (1.1.0 → 2.0.0): algo dejó de funcionar como antes.

Dónde se escribe cada una (las dos, siempre juntas):

| Paquete | Archivo | Además |
| --- | --- | --- |
| Temas hijo | `style.css` → `Version:` | su constante `*_VERSION` en `functions.php` |
| MJ Appearance | `style.css` → `Version:` | `MJ_VERSION` en `functions.php` |
| Plugins | cabecera `* Version:` del archivo principal | su constante `MJ*_VERSION` |

La versión del paquete cambia cuando cambia su contenido distribuido. No hace
falta subirla por guías de agentes excluidas del ZIP, documentación de la raíz,
CI o herramientas que no alteren lo distribuido. Si se cambia un `LEEME.md`
incluido en el ZIP para entregarlo a tiendas, también corresponde una versión
nueva; nunca se reemplaza el ZIP de una versión ya publicada.

Si existe `readme.txt`, mantené `Stable tag` alineado. Las versiones de esquema
(`*_DB_VERSION`) son independientes y solo cambian cuando hay una migración.
El `version` de `package.json` no es la versión WordPress del paquete.

La cabecera es la que lee WordPress y la que usa el script para nombrar el zip.
La constante es la que usa el código, por ejemplo para versionar CSS y JS. Si
difieren, la tienda muestra una versión y carga los archivos de otra.

Los temas hijo declaran qué versión del padre necesitan
(`MJ_GLOSSYHAIR_REQUIRES` y equivalentes). Si un hijo nuevo necesita un padre
nuevo, **se publican los dos juntos**. Si la tienda actualiza el hijo sin el
padre, WordPress muestra un aviso en el escritorio en vez de fallar en silencio.

---

<a id="instalar"></a>

## 6. Subir a mano

Hace falta en tres casos: la **primera instalación** de un paquete en una
tienda, una tienda **sin MJ Core**, o **volver atrás** una versión.

Al terminar, `build-releases.ps1` lista qué zip lleva cada tienda (salen de
`bundles` en `tools/releases.config.json`). La regla que no hay que saltearse:

> **MJ Appearance (el tema padre) va primero.** Un tema hijo sin su padre
> aparece como "tema roto" en WordPress.

1. **Apariencia → Temas → Añadir → Subir tema** para el padre y el hijo.
2. **Plugins → Añadir → Subir plugin** para cada plugin, empezando por MJ Core.
3. Activar el tema hijo (el padre se queda instalado, sin activar).
4. Configurar todo en **MJ → MJ Appearance**.

Para reemplazar una versión existente, se sube el zip encima: WordPress pregunta
si reemplaza y hay que decirle que sí. No hace falta borrar nada.

### Volver a una versión anterior

MJ Core siempre ofrece la versión más alta publicada, así que volver atrás es a
mano: bajar el zip de la Release vieja y subirlo encima. En unas horas la tienda
va a volver a ofrecer la versión rota. Lo que corresponde es **publicar un
arreglo con un número más alto** (1.16.0 rota → 1.16.1 arreglada), no borrar
la Release.

```bash
gh release download v2026.09.09 --repo Manzaniita/mj-family --pattern "mj-erp-*.zip" --dir zips
```

También se puede rearmar desde la etiqueta:

```bash
git checkout v2026.09.09
.\tools\build-releases.ps1
```

El SHA-256 no va a coincidir con el del `MANIFEST.md`: el zip guarda la fecha de
cada archivo, y el `git checkout` las cambia. Para comparar byte a byte, el que
vale es el zip publicado en la Release.

---

<a id="paquete-nuevo"></a>

## 7. Agregar un plugin o un tema nuevo

Esta es la lista para que un paquete nuevo quede sincronizado con todo: el
empaquetado, la verificación, las tiendas, las actualizaciones y la
documentación. Los pasos marcados **siempre** no se saltean nunca.

### El paquete

- [ ] **Siempre.** Crear la carpeta en `plugins/` o `themes/`, con nombre
      `mj-<algo>`: en minúsculas, con guiones y sin la versión. **Ese nombre es
      definitivo.** Es el nombre del zip, la carpeta en el servidor y lo que
      MJ Core usa para reconocerlo.
- [ ] **Siempre.** Plugin: el archivo principal con el mismo nombre que la
      carpeta (`mj-algo/mj-algo.php`). Nunca con la versión en el nombre: si el
      archivo cambia de nombre, WordPress instala un plugin nuevo al lado en
      lugar de actualizar. Pasó con MJ Fotos Rápidas.
- [ ] **Siempre.** Cabecera con `Version: 1.0.0`, `Requires at least`,
      `Requires PHP: 7.4` y `Author: MJ Importaciones`. En los plugins, además,
      la constante `MJ*_VERSION` con el mismo número.
- [ ] **Siempre.** Un prefijo que no use ningún otro paquete para constantes,
      clases, funciones y opciones: buscalo en todo el repositorio antes de
      elegirlo. Dos plugins con la misma constante no dan error al definirla;
      gana el que carga primero, y el otro falla lejos de ahí. Pasó con
      `MJC_DIR`, que usaban MJ Colegas y MJ Core: en Yamana, MJ Core no se podía
      activar.
- [ ] **Siempre.** Plugin: `defined('ABSPATH') || exit;` arriba y un
      `uninstall.php` que borre sus opciones y tablas.
- [ ] Tema hijo: `Template: mj-appearance` en `style.css`, descripción con el
      filtro `mj_skins`, y la constante de versión mínima del padre.

### El código compartido

- [ ] Si usa `MJ_Log` (o cualquier archivo de `shared/`): sumar el plugin a la
      lista de `tools/sincronizar-compartido.ps1`, correr
      `.\tools\sincronizar-compartido.ps1`, y en el archivo principal hacer
      `require_once` de `includes/compartido/class-mj-log.php` y llamar a
      `MJ_Log::init()`.
- [ ] Nunca editar las copias de `compartido/` (en `includes/`, `inc/` o la raíz
      del plugin): se edita `shared/` y se vuelve a repartir.

### La verificación

- [ ] Si trae una app de React o Vite:
  - sumarla a `builds` en `tools/releases.config.json`,
  - a la lista `$apps` de `tools/verificar.ps1`,
  - y a la matriz `compilar` de `.github/workflows/verificar.yml`.
- [ ] Si crea capabilities propias: sumarlas a `custom_capabilities` en
      `phpcs.xml.dist`.
- [ ] **Siempre.** `.\tools\verificar.ps1` en verde.

### Composición de tiendas

- [ ] **Siempre.** Sumarlo a cada tienda que lo use en `bundles` de
      `tools/releases.config.json`.

### Documentación de la preparación

- [ ] **Siempre.** `README.md`: fila en la tabla de temas o plugins. El mapa
      técnico del plugin va en `plugins/AGENTS.md`; una guía local solo si aporta
      reglas o rutas específicas.
- [ ] **Siempre.** `CONFIGURACION.md`: dónde se configura (sección 3), y sus
      constantes y filtros (sección 4).
- [ ] **Siempre.** `TIENDAS.md`: sumarlo a la tabla de cada tienda que lo use.
- [ ] `AUDITORIA.md`: si estaba en el backlog, sacarlo de ahí.
- [ ] Si toca plata o el checkout: su paso a paso en `PRUEBAS-EN-LA-TIENDA.md`.

### Publicación e instalación: solo cuando el usuario las pide

- [ ] Publicarlo con `.\tools\publicar.ps1`. Sin Release, MJ Core no lo conoce.
- [ ] **Siempre.** Subirlo **a mano la primera vez** en cada tienda que lo use
      (sección 6). MJ Core no instala paquetes nuevos: los actualiza una vez que
      están.
- [ ] Revisar en MJ → Actualizaciones de esa tienda que aparezca en la
      tabla como "Al día".

---

<a id="cambio-existente"></a>

## 8. Cambiar un plugin o un tema que ya existe

### Preparación del cambio

- [ ] Hacer el cambio. Si suma un ajuste, anotarlo en `CONFIGURACION.md`.
- [ ] Si cambia contenido distribuido, **subir la versión** en la cabecera y
      en la constante; también `Stable tag` si existe (sección 5).
- [ ] Si el cambio necesita un padre o plugin más nuevo, preparar sus versiones
      también y actualizar la versión mínima. Publicarlos juntos cuando se autorice.
- [ ] Si se tocó `shared/`: `.\tools\sincronizar-compartido.ps1`, y **subir la
      versión de cada plugin que recibió la copia**. Si no, esas tiendas nunca
      reciben el cambio.
- [ ] Si se tocó el React: el `dist/` compilado va en el commit (el servidor no
      tiene Node).
- [ ] `.\tools\verificar.ps1` en verde, o `-Rapido` si no cambió React.
      Incluye comprobar copias compartidas y documentación.
- [ ] Actualizar la tabla de `README.md` si cambió una versión y la documentación
      funcional afectada; retirar pendientes realmente resueltos de `AUDITORIA.md`.
- [ ] Revisar el diff, las instrucciones del área y `git diff --check`. Informar
      pruebas ejecutadas y pendientes. La preparación puede terminar acá.

### Publicación: solo por pedido explícito

- [ ] `.\tools\publicar.ps1 -Mensaje "…"`.
- [ ] En una tienda: **Buscar versiones nuevas ahora** y actualizar. Si toca plata
      o el checkout, probar ahí antes de actualizar las otras.

---

<a id="prohibiciones"></a>

## 9. Lo que no hay que hacer nunca

- **Renombrar la carpeta de un paquete.** La tienda deja de reconocerlo, y un zip
  con la carpeta nueva instala un segundo plugin al lado del viejo.
- **Cambiar código sin subir la versión.** Las tiendas no se enteran, y dos
  tiendas con "la misma versión" terminan corriendo código distinto.
- **Borrar o editar a mano una Release publicada.** Una tienda puede estar a
  mitad de una descarga, y el SHA-256 deja de coincidir.
- **Marcar una Release como prerelease para "probar".** MJ Core la ignora. Para
  probar, se sube el zip a mano en una tienda.
- **Poner un token con permisos de escritura.** Alcanza con Contents: Read-only.
- **Commitear un token.** Va en el panel de la tienda o en su `wp-config.php`,
  nunca en el repositorio.

---

<a id="diagnostico"></a>

## 10. Cuando algo no anda

MJ → Actualizaciones muestra el último error en castellano, y el
detalle queda en WooCommerce → Estado → Registros, archivo `mj-core`.

| Síntoma | Qué pasa | Qué hacer |
| --- | --- | --- |
| "no encuentra el repositorio (404)" | No hay token guardado, o no tiene acceso a ese repo. | Pegar el token y guardar. El campo tiene que decir "Hay uno guardado". |
| "rechazó el token (401)" | Venció, se revocó o se copió mal. | Crear otro y pegarlo. |
| "frenó la consulta (403)" | Sin token hay 60 consultas por hora. | Cargar el token. |
| El paquete no aparece en la tabla | No tiene zip en las últimas 20 Releases, o la carpeta instalada tiene otro nombre. | Publicar. Revisar que la carpeta en el servidor sea `mj-algo/` y no `mj-algo-1.0.0/`. |
| Publiqué y la tienda sigue "Al día" | No se subió la versión, o la tienda todavía no consultó. | Revisar el número. Apretar "Buscar versiones nuevas ahora". |
| "SHA-256 distinto" al actualizar | El archivo llegó cortado o alterado. No se instaló nada. | Reintentar. Si se repite, rearmar y publicar con otra versión. |
| El push a `main` rechaza `.github/workflows` | A la sesión de GitHub le falta el permiso `workflow`. | `gh auth refresh -h github.com -s workflow` y `gh auth setup-git`. |
