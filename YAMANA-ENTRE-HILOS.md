# Yamana y Entre Hilos — qué se entregó y cómo ponerlo en marcha

Entregado el 4 de septiembre de 2026. **Actualizado el 9 de septiembre de 2026.**

Los zip están en `releases/bundles/yamana/` (ver `PUBLICAR.md`):

| Archivo | Qué es | Dónde se sube |
|---|---|---|
| `mj-appearance-3.6.0.zip` | Tema padre **MJ Appearance 3.6.0** (obligatorio: el hijo y el plugin lo necesitan) | Apariencia → Temas → Añadir → Subir tema |
| `mj-yamana-1.1.0.zip` | Tema hijo **Yamana** (la skin cálida artesanal) | Apariencia → Temas → Añadir → Subir tema, y **activarlo** |
| `mj-colegas-1.0.0.zip` | Plugin **MJ Colegas** (colegas, comisión, liquidaciones, proyecto Entre Hilos) | Plugins → Añadir → Subir plugin |

Orden: primero el padre, después el hijo (activar Yamana), después el plugin.
Si ya tenías MJ Appearance 3.3 instalado, subí el 3.4 encima: WordPress pregunta si querés reemplazarlo. Los ajustes guardados no se pierden.

---

## 1. El tema hijo Yamana

Al activarlo, la tienda queda en modo día, animación media y sin intro. Propone su paleta (lino, chocolate, terracota) y sus tipografías (Fraunces + Nunito); todo se sigue cambiando desde **Apariencia → MJ Appearance**.

Lo que conviene cargar apenas lo actives, todo en Apariencia → MJ Appearance:

- **Logo** (y opcionalmente una variante clara para el modo noche). Si no cargás ninguno, muestra la inicial del sitio y el nombre. También toma el logo del Personalizador si ya lo tenías ahí.
- **Nombre del sitio y descripción corta** están en Ajustes → Generales: "Yamana" y una frase corta. La descripción corta es el subtítulo del hero si no escribís otro.
- **Portada (hero)**: fotos de fondo, título, botón. El tema propone "Tejido a mano, / con tiempo y con cariño." hasta que escribas el tuyo.
- **Textos de la tienda**: cada título, antetítulo y frase de cada pantalla, agrupados por Portada, Tienda, Producto, Pie de página y Páginas. Vacío = el texto en gris.
- **Secciones de la portada**: tildá cuáles se ven. Las que no tienen datos (sin banners, sin marcas, sin opiniones, sin Instagram) se ocultan solas.
- **Cómo llamar a las "marcas"**: la tienda arma "marcas" desde el atributo de producto llamado *Marca*. Si preferís usarlo para el hilado, ponele "Hilado / Hilados".
- **Legales**: términos y privacidad propios, con secciones `# Título` y su texto.
- **Categorías del menú**: hasta 4 en el header. Las categorías las creás vos en Productos → Categorías (por ejemplo Prendas, Accesorios, Amigurumis, Kits, Cursos).

**Cursos**: se venden como productos normales dentro de una categoría "Cursos". Como el material lo mandás por correo, cargalos como producto *virtual* (sin envío) y contá en la descripción corta cómo y cuándo llega el material. Si más adelante querés fecha, modalidad y cupos como campos propios, eso es un plugin chico aparte.

---

## 2. El plugin MJ Colegas

Con el tema Yamana activo, el proyecto ya se llama **Entre Hilos** y vive en `/entre-hilos`. Todo se cambia en **Colegas → Ajustes**.

### Cómo se trabaja

1. **Colegas → Lista de colegas**: una ficha por colega. Nombre y presentación son públicos; foto, Instagram y sitio también. Comisión propia (vacío = 10%), contacto, cómo pagarle (CBU/alias) y notas son solo para vos.
2. **Productos**: en cada producto, en la caja "Colegas" (columna derecha), elegís quién lo hizo. Sin elegir nada, es de Yamana. El precio que cargás es el precio final que ve el cliente.
3. **El cliente compra normalmente**, mezclando piezas tuyas y de colegas en el mismo carrito. Cada línea del pedido queda sellada con la colega y el porcentaje vigente en ese momento.
4. **Colegas → Liquidaciones**: elegís el período (por defecto el mes en curso) y ves por colega cuánto se vendió, cuánto es tu 10% y cuánto le tenés que pagar, con sus datos de pago al lado. Le transferís por fuera y tocás **Marcar como liquidado** (con una nota, por ejemplo "transferencia 12/09"). Queda en el historial y esas ventas dejan de estar pendientes. Podés exportar a CSV y deshacer una liquidación.
5. En cada pedido del admin ves, línea por línea, de quién es y cuánto va para cada una.

### Reglas del 10%

- Se calcula sobre lo que pagó el cliente por el producto, después de cupones (o sobre el precio de lista, según ajustes). El envío nunca entra.
- Cuentan como venta los pedidos en Procesando y Completado (configurable).
- Si cambiás un porcentaje, los pedidos viejos no se tocan.
- Los productos de Yamana no llevan comisión.

### Lo que ve el visitante

- Chip **"Tejido por …"** sobre la foto de las piezas de colegas (opcional también en las tuyas).
- En la ficha del producto, un bloque con la foto de la colega y un enlace a su página.
- En la tienda, el filtro **Colegas**: Todo / Yamana / Colegas / cada una.
- En la portada, la sección **Entre Hilos** con las colegas (hasta 4) y enlace al proyecto.
- La página **/entre-hilos**: presentación (textos y fotos que cargues), selector Todo / Yamana / Colegas, las colegas y las piezas. Y una página por colega: `/entre-hilos/nombre`.
- El proyecto aparece en el menú principal y en el pie de página (se puede sacar).

---

## 3. Prueba corta para confirmar que anda

1. Subí y activá los tres archivos en ese orden. Entrá a la portada: fondo lino, títulos en serif, botones terracota.
2. Apariencia → MJ Appearance: cargá el logo y guardá. Tiene que aparecer en el header y en el pie.
3. Colegas → Lista de colegas: creá una colega de prueba con foto. Asignásela a un producto. En la tienda, ese producto muestra el chip con su nombre y en el filtro "Colegas" aparece ella.
4. Entrá a `/entre-hilos`: se ve la presentación, el selector y la colega. Tocá su tarjeta: se abre su página con sus piezas.
5. Comprá ese producto (pedido de prueba) y pasalo a Procesando. Colegas → Liquidaciones: aparece con el 10% calculado. Tocá "Marcar como liquidado": pasa al historial.
6. Borrá el pedido y la colega de prueba si querés.

---

## 4. Lo que no pude verificar acá

No tengo WordPress ni PHP en esta máquina: el PHP pasó una revisión de sintaxis y la app React compiló sin errores, pero el recorrido completo (subir zip, activar, comprar) lo confirmás vos con la prueba de arriba. Si algo no se ve como esperás, decime qué pantalla y qué texto y lo ajusto.
