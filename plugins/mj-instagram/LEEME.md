# MJ Instagram

Conexión con Instagram y bloque para el editor de WordPress (Gutenberg) que permite
mostrar publicaciones, historias, destacados y perfil en cualquier página de tu tienda.

Funciona de forma autónoma con cualquier tema de WordPress y se integra con la
familia de temas y plugins MJ.

---

## Características

- **Bloque para el editor de bloques (Gutenberg):** agregalo en cualquier página,
  portada o entrada desde el insertador de bloques de WordPress (`Instagram (MJ)`).
- **Todo configurable desde el editor:**
  - **Qué mostrar:** solo publicaciones (feed), solo historias activas (stories), solo
    historias destacadas (highlights), perfil completo o combinación de todas.
  - **Disposición:** grilla clásica o carrusel horizontal deslizable con botones de avance.
  - **Columnas responsivas:** elegí de 2 a 6 columnas en computadora y de 1 a 3 en celulares.
  - **Cantidad de fotos:** límite personalizable de 1 a 24 publicaciones.
  - **Formato:** fotos cuadradas (1:1) o verticales (4:5).
  - **Espaciado:** compacto (6px), normal (14px) o amplio (24px).
  - **Filtro de medios:** todas las publicaciones, solo fotos o solo videos/reels.
- **Tarjeta de perfil:** muestra avatar con anillo degradé de Instagram, nombre, biografía,
  cantidad de seguidores y botón personalizable «Seguinos».
- **Historias y Destacados:** fila deslizable con burbujas de historias activas o colecciones
  destacadas con enlace directo.
- **Rendimiento y caché:** almacena las respuestas de la API en transitorios de WordPress
  para que la tienda cargue instantáneamente y no sature los límites de Meta.
- **Modo demostración:** si aún no conectaste tu token, el bloque muestra fotos de prueba
  para que puedas diseñar la página y ver el resultado en tiempo real.
- **Shortcode alternativo:** `[mj_instagram display="posts" columns="4" limit="8"]` para
  usar en páginas clásicas o donde no se use el editor de bloques.

---

## Cómo usarlo

1. En el panel de administración de WordPress, ingresá a **MJ → Instagram**.
2. Ingresá tu **Access Token** de Instagram Graph API. Podés probar la conexión
   con el botón **Probar conexión ahora**.
3. Opcionalmente, configurá tu usuario, biografía y los destacados personalizados.
4. Hacé clic en **Guardar credenciales**.
5. Andá a **Páginas → Añadir nueva** (o editá una existente) y buscá el bloque **Instagram (MJ)**.
6. En la barra lateral derecha podés elegir si mostrar posts, historias, destacados o perfil,
   la cantidad de fotos y el diseño en grilla o carrusel.
7. Publicá la página y mirala en la tienda.
