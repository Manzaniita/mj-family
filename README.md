# MJ Family

Repositorio privado de temas y plugins WordPress/WooCommerce para las tiendas
MJ Importaciones, GlossyHair, Yamana y el proyecto Jaz Beauty Art. Acá se
mantiene el fuente y se preparan los paquetes que reciben las tiendas.

## Empezar por lo que necesitás

| Necesidad | Documento |
| --- | --- |
| Trabajar con Codex | [AGENTS.md](AGENTS.md): reglas y mapa por tarea. |
| Configurar una función | [CONFIGURACION.md](CONFIGURACION.md): índice por tema y plugin. |
| Preparar un cambio o una versión | [PUBLICAR.md](PUBLICAR.md): versiones, verificación y publicación. |
| Instalar o revisar una tienda | [TIENDAS.md](TIENDAS.md): composición y puesta en marcha. |
| Probar un circuito | [PRUEBAS-EN-LA-TIENDA.md](PRUEBAS-EN-LA-TIENDA.md): recorridos manuales. |
| Elegir o cerrar trabajo pendiente | [AUDITORIA.md](AUDITORIA.md): deuda, mejoras y backlog. |

## Qué hay adentro

### Temas

| Tema | Versión del fuente | Función |
| --- | --- | --- |
| **MJ Appearance** | 3.35.0 | Tema padre: tienda React, catálogo, carrito, checkout, cuenta, bloques y configuración común. |
| **GlossyHair** | 1.1.1 | Salón de alisados, reservas y productos; identidad blanco y fucsia. |
| **Yamana** | 1.1.1 | Tejidos, cursos y Entre Hilos; identidad lino y terracota. |
| **MJ Atelier** | 1.2.0 | Tienda editorial; identidad marfil y bronce. |
| **Jaz Beauty Art** | 1.1.0 | Pestañas y cejas, reservas, fidelidad y contenido inicial en borrador. |
| **MJ ProSkin** | 1.0.0 | Tienda interactiva de ultralujo; cyber luxury, ultravioleta neón y obsidiana con patrones FSE/Gutenberg. |

Los hijos describen su identidad mediante los filtros del padre; no duplican
su motor. Los ajustes guardados en el panel prevalecen sobre los presets.

### Plugins

| Plugin | Versión del fuente | Función |
| --- | --- | --- |
| **MJ Turnos Online** | 1.4.0 | Reservas, agenda, señas, fichas, cuentas de clientas y recordatorios. Funciona sin WooCommerce. |
| **MJ Fidelidad** | 1.1.0 | Tarjeta de sellos y premios por turnos o compras. |
| **MJ Colegas** | 1.1.0 | Productos de terceros, comisiones y liquidaciones. |
| **MJ Dólar** | 1.2.0 | Precios USD, conversión a pesos y redondeo configurable. |
| **MJ Medios de Pago** | 1.6.0 | Descuentos/recargos, datos para pagar y revisión de comprobantes. |
| **MJ Envíos** | 1.11.3 | Métodos, reglas, cotizaciones, integración con correos y seguimiento. |
| **MJ ERP** | 1.23.0 | Ventas, compras, stock, clientes, consignación, devoluciones, saldo, caja y reportes. |
| **MJ Optimizador de Imágenes** | 1.2.0 | Redimensionado y conversión de imágenes en el servidor. |
| **MJ Fotos Rápidas** | 1.4.0 | Carga de fotos y publicación de productos desde el celular. |
| **MJ Popup** | 1.0.0 | Ventana emergente con imagen para avisos importantes. Compatible con cualquier tema. |
| **MJ Instagram** | 1.0.0 | Conexión con Instagram y bloque configurable para posts, historias, destacados y perfil. |
| **MJ Core** | 1.1.0 | Ofrece actualizaciones de paquetes instalados desde Releases privadas de GitHub. |

Los plugins no dependen de MJ Core para funcionar. Las dependencias obligatorias
particulares, como WooCommerce, se declaran en cada paquete; los cruces con
otros plugins MJ son opcionales. Versiones de esta tabla: **fuente del repo**,
no prueba de qué está instalado o publicado. Las cabeceras son la referencia.

## Cómo está organizado

```text
AGENTS.md                          entrada para Codex
plugins/AGENTS.md                  mapa y reglas de plugins
plugins/mj-*/                      un plugin por función
  mj-erp/AGENTS.md                 circuitos de ventas, stock y caja
  mj-turnos-online/AGENTS.md       reserva, panel y app React
    app/                          fuente React de Turnos
    dist/                         resultado compilado que va a la tienda
themes/AGENTS.md                   tema padre y personalización por tienda
themes/mj-appearance/AGENTS.md     mapa de PHP y tienda React
  inc/                            ajustes, sesión, skins y REST
  react-app/                      fuente React de la tienda
  dist/                           resultado compilado que va a la tienda
themes/mj-{atelier,glossyhair,yamana,jazbeauty,proskin}/
shared/                           originales del código que se copia a plugins
tools/                            verificar, sincronizar, empaquetar y publicar
.github/                          Actions, instrucciones y plantilla de PR
releases/                         manifiestos generados y ZIP locales ignorados
```

La composición de las cuatro tiendas se declara en `bundles` de
[tools/releases.config.json](tools/releases.config.json). El detalle operativo
está en [TIENDAS.md](TIENDAS.md#composicion), sin repetir otra lista acá.

## Preparar el entorno

- Git y Windows PowerShell 5.1 o PowerShell 7 para los scripts locales.
- PHP 8.4 y Composer para las herramientas locales; el código distribuido debe
  seguir siendo compatible con PHP 7.4. CI comprueba sintaxis en 7.4 y 8.3.
- Node 20 o superior y npm para las dos apps React; CI usa Node 20.
- WordPress/WooCommerce de desarrollo para probar flujos reales. El repositorio
  no incluye una instalación completa, una base de datos ni credenciales.
- GitHub CLI autenticado solo si vas a publicar; no hace falta para editar.

Desde la raíz:

```powershell
composer install
```

Cada app instala sus dependencias desde su lockfile:

```powershell
# Tienda del tema
Push-Location themes/mj-appearance/react-app
npm ci --no-audit --no-fund
npm run dev
Pop-Location

# Reservas y panel de Turnos
Push-Location plugins/mj-turnos-online/app
npm ci --no-audit --no-fund
npm run dev
Pop-Location
```

Los puertos de desarrollo son 5199 (tema) y 5198 (Turnos). La conexión al
backend depende de la configuración de cada app; abrir Vite no valida una
compra o reserva. MJ ERP usa JavaScript propio y no tiene un build Vite.

## Verificar antes de cerrar un cambio

```powershell
.\tools\verificar.ps1 -Rapido   # Sin cambios en React
.\tools\verificar.ps1           # Incluye los dos builds
```

Seis controles: sintaxis PHP, compatibilidad 7.4, PHPCS contra su baseline,
builds, copias de `shared/` y documentación. `-Rapido` omite solo los builds.
Los scripts nuevos también entran al control de sintaxis PHP.

Para diagnosticar únicamente documentación o copias:

```powershell
php tools/verificar-documentacion.php
.\tools\sincronizar-compartido.ps1 -Verificar
```

El control documental revisa archivos obligatorios, enlaces locales Markdown
inline sin títulos, anclas de encabezados y `<a id="...">`, tamaño de guías y
exclusiones de empaquetado. No visita enlaces externos ni reemplaza la revisión
semántica. Cada guía tiene un tope de 8 KiB; cada cadena del repo, 24 KiB para
dejar margen a instrucciones globales. Son límites de mantenimiento en bytes,
no una medición de tokens. Preferí mantenerte bastante por debajo.

[verificar.yml](.github/workflows/verificar.yml) ejecuta los controles de código
en pushes a `main` y PR, salvo cambios exclusivamente Markdown o de releases.
[documentacion.yml](.github/workflows/documentacion.yml) ejecuta el chequeo
liviano cuando cambian Markdown, su validador o la configuración de paquetes.
Ambos permiten ejecución manual. Las etiquetas no disparan otra compilación.
El estado histórico de Actions en la auditoría debe confirmarse en GitHub.

La baseline de [PHPCS](tools/phpcs-baseline.json) registra deuda previa;
`-Foto` solo corresponde cuando se resolvió deuda comprobada. Nunca se usa para
absorber avisos nuevos. Las pruebas manuales pendientes se informan como tales.

<a id="documentacion"></a>

## Qué documentación actualizar

| Si el cambio… | Actualizá… |
| --- | --- |
| Agrega un paquete o cambia su versión | Tabla de este README y checklist de [PUBLICAR](PUBLICAR.md#paquete-nuevo). |
| Agrega/cambia ajustes, constantes o filtros | Sección correspondiente de [CONFIGURACION](CONFIGURACION.md). |
| Cambia el uso de un plugin | Su `LEEME.md`, si existe, y la configuración afectada. |
| Cambia composición, instalación o estado confirmado de una tienda | [TIENDAS](TIENDAS.md). |
| Cambia un recorrido observable | Sección correspondiente de [PRUEBAS](PRUEBAS-EN-LA-TIENDA.md). |
| Resuelve deuda o descubre un pendiente concreto | [AUDITORIA](AUDITORIA.md): retirar lo resuelto, agregar evidencia del pendiente. |
| Cambia arquitectura, rutas o comandos de desarrollo | `AGENTS.md` del área; raíz solo si cambia una regla común o entrada. |
| Cambia herramientas o publicación | [PUBLICAR](PUBLICAR.md), guía de tools y este README si cambian comandos. |

Los manuales actuales son [Turnos](plugins/mj-turnos-online/LEEME.md),
[Colegas](plugins/mj-colegas/LEEME.md), [Envíos](plugins/mj-envios/LEEME.md) y
[Fidelidad](plugins/mj-fidelidad/LEEME.md). Faltan seis: Dólar, Medios de Pago,
Core, ERP, Optimizador y Fotos Rápidas; siguen registrados en la auditoría.
`readme.txt`, donde existe, conserva cabecera, `Stable tag` y changelog.

Los documentos son fuentes mantenidas por función, no informes por entrega.
Las guías de Codex apuntan a ellos sin copiarlos. Al consultar uno largo, usá
el índice y leé la sección de la tarea. `AGENTS.md` es la única convención
compartida de instrucciones de este repo; las preferencias personales quedan
fuera de Git. Detalle de lectura jerárquica: [documentación de Codex](https://learn.chatgpt.com/docs/agent-configuration/agents-md).

## Git y distribución

- `.editorconfig` define formato; `.gitattributes` normaliza finales de línea.
  Conservá el estilo local al editar: no reformatees todo el archivo.
- `dist/`, `composer.lock` y los dos `package-lock.json` se versionan.
  Dependencias instaladas, ZIP, credenciales y sesiones locales se ignoran.
- Las instrucciones de agentes quedan excluidas de los ZIP; no cambian las
  versiones de plugins o temas por sí solas.
- Preparar cambios y subir código al repositorio son pasos distintos de crear
  una Release. La publicación solo se hace por pedido explícito.

Para empaquetar o publicar, seguí [PUBLICAR.md](PUBLICAR.md#alcance). Los
paquetes publicados se descargan de las
[Releases privadas](https://github.com/Manzaniita/mj-family/releases).
La primera instalación de cada paquete en una tienda es manual.

Visibilidad privada, acceso de colaboradores, reglas de rama y disponibilidad
de Actions se administran en GitHub. Estos archivos no cambian esos ajustes.
Al elegir checks obligatorios, considerá los filtros de rutas de cada workflow.

Código y textos nuevos en castellano rioplatense. No se agrega infraestructura
de traducción a los paquetes. © MJ. Repositorio privado; acceso restringido.
