# Cómo publicar una versión

Todo lo que se sube a una tienda sale de `releases/`, y esa carpeta se genera
con un solo comando. No hay que armar zips a mano ni acordarse de qué archivo
va en cada uno.

---

## 1. Sacar los zip

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
   dependencias: solo lo que corre en el servidor.
4. **Borra el zip de la versión anterior** de esos paquetes.
5. **Escribe el manifiesto** con la versión, el tamaño y el SHA-256 de cada zip,
   y lista qué zip necesita cada tienda.

Si no hay ninguna versión nueva, no compila, no arma nada y lo dice.

Variantes útiles:

```powershell
.\tools\build-releases.ps1 -Force                        # rehace aunque la versión no haya cambiado
.\tools\build-releases.ps1 -SkipBuild                    # no recompila React
.\tools\build-releases.ps1 -Only mj-glossyhair           # un solo paquete
```

`-Force` es para cuando tocaste el código sin subir el número de versión: el
script mira la versión, no los archivos. Lo normal es subir la versión —
WordPress necesita el número nuevo para ofrecer la actualización.

### Qué queda en `releases/`

| Ruta | Qué es |
| --- | --- |
| `mj-glossyhair-1.1.0.zip` | El paquete listo para subir. Uno solo por paquete: el de la última versión. |
| `MANIFEST.md` | Tabla con versión y SHA-256 de cada zip. |
| `manifest.json` | Lo mismo, para scripts. |

Las versiones anteriores no quedan en la carpeta: están publicadas en las
[Releases de GitHub](https://github.com/Manzaniita/mj-family/releases), colgadas
de la etiqueta con la que se subieron.

---

## 2. Subir a una tienda

Al terminar, el script lista qué zip lleva cada tienda (salen de `bundles` en
`tools/releases.config.json`). La regla que no hay que saltearse:

> **MJ Appearance (el tema padre) va primero.** Un tema hijo sin su padre
> aparece como "tema roto" en WordPress.

Después:

1. **Apariencia → Temas → Añadir → Subir tema** para el padre y el hijo.
2. **Plugins → Añadir → Subir plugin** para cada plugin.
3. Activar el tema hijo (el padre se queda instalado, sin activar).
4. Configurar todo en **Apariencia → MJ Appearance**.

Para **actualizar** una instalación que ya existe, no hace falta subir nada si
la tienda tiene **MJ Core** (ver la sección siguiente). Sin MJ Core, se sube el
zip nuevo encima: WordPress pregunta si reemplaza y hay que decirle que sí. No
hace falta borrar nada — los ajustes viven en la base de datos y no se tocan.

---

## 2.1. Que las tiendas pidan la actualización solas (MJ Core)

Con **MJ Core** instalado, cada vez que se publica una versión nueva con
`publicar.ps1`, las tiendas que usan ese tema o plugin la muestran como
cualquier actualización de WordPress: en **Escritorio → Actualizaciones**, en
la lista de plugins y en Apariencia → Temas, con su botón de "Actualizar ahora".
También funciona el "Activar actualizaciones automáticas" de cada plugin.

### Cómo sabe qué ofrecer

MJ Core lee las Releases de GitHub del repositorio. Cada zip se llama
`<carpeta>-<versión>.zip`, y con eso alcanza: si en la tienda hay un plugin o un
tema en la carpeta `mj-erp` y en las Releases hay un `mj-erp-1.16.0.zip`, la
tienda compara con la versión instalada y, si la publicada es más nueva, la
ofrece. Se miran las últimas 20 Releases y de cada paquete gana la versión más
alta.

Por eso **la versión tiene que subir** (sección 3): un zip rehecho con `-Force`
y el mismo número no le cambia nada a la tienda.

GitHub se consulta cada 6 horas (se cambia en el panel). Para no esperar, el
botón **Buscar versiones nuevas ahora** de Ajustes → MJ · Actualizaciones, o
"Buscar de nuevo" en Escritorio → Actualizaciones.

Al actualizar, MJ Core baja el zip con el token de la tienda y lo compara con
el SHA-256 que GitHub publica de cada archivo. Si no coincide, no instala nada
y lo anota en MJ · Registros.

### Ponerlo en marcha en una tienda (una sola vez)

1. **Crear el token en GitHub.** Settings → Developer settings → Personal access
   tokens → *Fine-grained tokens* → Generate new token. Repository access: solo
   `Manzaniita/mj-family`. Permissions: únicamente **Contents: Read-only**. Con
   eso el token puede leer las Releases y nada más. Alcanza uno para las tres
   tiendas, pero uno por tienda deja revocar una sin tocar las otras.
2. **Subir `mj-core-<versión>.zip`** a mano, esta última vez, y activarlo.
3. **Ajustes → MJ · Actualizaciones**: repositorio `Manzaniita/mj-family` y el
   token. Guardar y apretar **Buscar versiones nuevas ahora**. La tabla muestra
   qué versión tiene la tienda de cada paquete y cuál está publicada.

Si preferís que el token no quede en la base de datos, va en `wp-config.php` y
manda sobre el panel:

```php
define('MJ_CORE_REPO', 'Manzaniita/mj-family');
define('MJ_CORE_TOKEN', 'github_pat_...');
```

Desde ahí, MJ Core se actualiza a sí mismo como los demás.

### Cuando algo no anda

La pantalla de MJ · Actualizaciones muestra el último error en castellano.
Los más comunes:

| Dice | Qué pasa |
| --- | --- |
| rechazó el token (401) | El token venció o se copió mal. Crear otro y pegarlo. |
| no encuentra el repositorio (404) | Nombre mal escrito, o el token no tiene acceso a ese repositorio. |
| frenó la consulta (403) | Falta el token: sin token GitHub deja 60 consultas por hora. |

Mientras GitHub no responda, la tienda sigue ofreciendo lo último que había
leído.

### Volver a una versión anterior

MJ Core ofrece siempre la más alta publicada, así que volver atrás sigue siendo
a mano: bajar el zip de la Release vieja y subirlo encima. Para que después no
vuelva a ofrecer la versión rota, lo que corresponde es publicar un arreglo con
un número más alto.

---

## 3. Numerar las versiones

Se usa `MAYOR.MENOR.PARCHE`:

- **PARCHE** (1.1.0 → 1.1.1): arreglos que no cambian nada de lo que se ve.
- **MENOR** (1.1.0 → 1.2.0): ajustes o funciones nuevas, sin romper lo anterior.
- **MAYOR** (1.1.0 → 2.0.0): algo dejó de funcionar como antes.

Dónde se escribe cada una:

| Paquete | Archivo | Además |
| --- | --- | --- |
| Temas | `style.css` → `Version:` | — |
| MJ Appearance | `style.css` | y `MJ_VERSION` en `functions.php` |
| Plugins | cabecera `* Version:` del archivo principal | y su constante `MJ*_VERSION` |

Los temas hijo declaran qué versión del padre necesitan
(`MJ_GLOSSYHAIR_REQUIRES` y equivalentes). Si se sube un hijo nuevo sobre un
padre viejo, WordPress muestra un aviso en el escritorio en vez de fallar en
silencio.

---

## 4. Publicar en GitHub (todo en un comando)

El repositorio privado es <https://github.com/Manzaniita/mj-family>. Para dejar
el código, la etiqueta y los zip sincronizados alcanza con:

```powershell
.\tools\publicar.ps1 -Mensaje "GlossyHair 1.2.0: nueva paleta"
```

Eso hace, en orden: comprueba que git y la sesión de GitHub estén bien, arma los
paquetes (paso 1), comitea lo que haya cambiado, crea la etiqueta con la fecha
de hoy, sube rama y etiqueta, y publica los zip como **Release** colgado de esa
etiqueta. Antes de subir nada muestra qué va a hacer y pide confirmación.

```powershell
.\tools\publicar.ps1 -Mensaje "Arregla el carrito" -Version v2026.10.01
.\tools\publicar.ps1 -Mensaje "Solo docs" -SkipBuild
.\tools\publicar.ps1 -Mensaje "..." -Notas notas.md      # notas propias
```

Si algo no está en condiciones —falta `gh`, no hay sesión, la etiqueta ya
existe— corta **antes** de tocar el repositorio.

### A mano, si preferís

```bash
git add -A
git commit -m "GlossyHair 1.1.0: paletas configurables"
git tag -a v2026.09.09 -m "GlossyHair 1.1.0, MJ Appearance 3.6.0, Turnos 1.1.0"
git push origin main
git push origin v2026.09.09
gh release create v2026.09.09 releases/*.zip --title "v2026.09.09"
```

### Qué se guarda y qué no

Los `.zip` **no** se versionan (se rehacen en cualquier momento con el script y
quedan publicados en las Releases), pero `releases/MANIFEST.md` **sí**: ahí
queda registrado con qué SHA-256 se subió cada versión a producción, aunque el
archivo ya no esté en la carpeta.

Para volver a una versión de antes lo más simple es bajar el zip de su Release
(más abajo). También se puede rearmar:

```bash
git checkout v2026.09.09
.\tools\build-releases.ps1
```

Sale el mismo contenido que se subió aquel día, y el zip de la versión actual se
borra de la carpeta (vuelve al volver a `main` y correr el script). El SHA-256
no tiene por qué coincidir con el del `MANIFEST.md`: el zip guarda la fecha de
cada archivo, y el `git checkout` las cambia. Para comparar byte a byte, el que
vale es el zip publicado en la Release.

### Bajar los zip sin tener el proyecto

Desde otra computadora, con `gh` instalado:

```bash
gh release download v2026.09.09 --repo Manzaniita/mj-family --dir zips
```

O desde la web, en la pestaña **Releases** del repositorio.

---

## 5. Agregar un plugin o un tema nuevo

No hay que tocar el script. Alcanza con:

1. Crear la carpeta en `plugins/` o en `themes/`, con su cabecera y su versión.
2. Sumarlo a la tienda que lo use, en `tools/releases.config.json` → `bundles`:
   de ahí sale la lista que el script imprime al final.
3. Si trae una app de React, agregarla a `builds` en ese mismo archivo.

El resto lo detecta solo.
