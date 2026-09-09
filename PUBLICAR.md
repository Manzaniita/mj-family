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

1. **Compila** la tienda (React) del tema y la app de reservas del plugin de
   turnos. Si falta `node_modules`, lo instala solo.
2. **Lee la versión** de cada tema y plugin de su propia cabecera
   (`style.css` en los temas, el comentario `Version:` en los plugins).
3. **Arma un zip por paquete**, sin código fuente ni dependencias: solo lo que
   corre en el servidor.
4. **Copia los conjuntos por tienda** a `releases/bundles/`.
5. **Escribe el manifiesto** con la versión, el tamaño y el SHA-256 de cada zip.

Variantes útiles:

```powershell
.\tools\build-releases.ps1 -SkipBuild                    # no recompila React
.\tools\build-releases.ps1 -Only mj-glossyhair           # un solo paquete
```

### Qué queda en `releases/`

| Ruta | Qué es |
| --- | --- |
| `mj-glossyhair-1.1.0.zip` | El paquete de esa versión exacta. **Nunca se pisa.** |
| `latest/mj-glossyhair.zip` | La última, con nombre fijo (cómodo para subir siempre igual). |
| `bundles/glossyhair/` | Todos los zip que necesita esa tienda, con su `LEEME.md`. |
| `MANIFEST.md` | Tabla con versión y SHA-256 de cada zip. |
| `manifest.json` | Lo mismo, para scripts. |

---

## 2. Subir a una tienda

En `releases/bundles/<tienda>/` está el `LEEME.md` con el orden exacto. La regla
que no hay que saltearse:

> **MJ Appearance (el tema padre) va primero.** Un tema hijo sin su padre
> aparece como "tema roto" en WordPress.

Después:

1. **Apariencia → Temas → Añadir → Subir tema** para el padre y el hijo.
2. **Plugins → Añadir → Subir plugin** para cada plugin.
3. Activar el tema hijo (el padre se queda instalado, sin activar).
4. Configurar todo en **Apariencia → MJ Appearance**.

Para **actualizar** una instalación que ya existe, se sube el zip nuevo encima:
WordPress pregunta si reemplaza y hay que decirle que sí. No hace falta borrar
nada — los ajustes viven en la base de datos y no se tocan.

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

Para volver a una versión de antes:

```bash
git checkout v2026.09.09
.\tools\build-releases.ps1
```

Sale el mismo zip que se subió aquel día (los hashes se pueden comparar contra
el `MANIFEST.md` de esa etiqueta).

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
2. Si es un tema nuevo, sumarlo a algún conjunto en
   `tools/releases.config.json` → `bundles`.
3. Si trae una app de React, agregarla a `builds` en ese mismo archivo.

El resto lo detecta solo.
