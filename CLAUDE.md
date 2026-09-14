# MJ Family — instrucciones para trabajar en el repo

Temas y plugins de WordPress propios, que corren en tres tiendas reales
(MJ Importaciones, GlossyHair, Yamana). Todo en castellano rioplatense: código,
comentarios, commits y documentación.

## El circuito de publicación manda

Las tiendas se actualizan solas con **MJ Core**, que lee las Releases de GitHub.
Antes de dar por terminado cualquier cambio en un plugin o tema, seguir
`PUBLICAR.md`:

- **Paquete nuevo:** la lista completa de la sección 7 (carpeta definitiva,
  cabecera y constante, `releases.config.json`, `sincronizar-compartido.ps1`,
  builds en `verificar.ps1` y en el workflow, documentación). Avisar que la
  primera instalación en cada tienda es a mano.
- **Cambio en un paquete existente:** la lista de la sección 8. Sobre todo,
  subir la versión en la cabecera **y** en la constante. Sin versión nueva,
  ninguna tienda recibe el cambio.
- **Cambio en `shared/`:** repartir con `tools/sincronizar-compartido.ps1` y
  subir la versión de cada plugin que recibe la copia.

Lo que nunca: renombrar la carpeta de un paquete, poner la versión en el nombre
del archivo principal, o editar las copias de `includes/compartido/`.

## Reglas de la casa

- **Nada de negocio hardcodeado.** Va en un ajuste del panel, una constante de
  `wp-config.php` o un filtro. Índice en `CONFIGURACION.md`, que se actualiza en
  el mismo cambio.
- **Cada plugin funciona solo.** Los cruces entre plugins van con
  `class_exists()`. Ninguno depende de MJ Core para funcionar.
- **PHP 7.4.** Nada de sintaxis de PHP 8.
- **Verificar antes de terminar:** `.\tools\verificar.ps1` (o `-Rapido` si no se
  tocó React) tiene que dar "TODO EN ORDEN".

## Git

- Commits chicos, uno por unidad de cambio.
- Publicar (`tools/publicar.ps1`) sube a GitHub y las tiendas lo ven: solo cuando
  el usuario lo pide.
