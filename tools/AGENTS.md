# Herramientas — distinguir verificar, empaquetar y publicar

Aplican [las reglas de la raíz](../AGENTS.md). Scripts compatibles con Windows
PowerShell 5.1; los controles usados en CI también deben funcionar en Linux.

| Archivo o comando | Responsabilidad y efectos |
| --- | --- |
| `verificar.ps1 -Rapido` | PHP, compatibilidad, baseline, copias compartidas y documentación; escribe informe temporal. |
| `verificar.ps1` | Lo anterior más builds; puede instalar dependencias y regenerar `dist/`. |
| `verificar.ps1 -Foto` | Reemplaza la baseline: solo al resolver deuda comprobada, nunca para hacer pasar un fallo. |
| `verificar-documentacion.php` | Enlaces locales Markdown, anclas, guías y exclusiones de instrucciones; no escribe archivos. |
| `sincronizar-compartido.ps1 -Verificar` | Comprueba copias sin modificarlas. |
| `sincronizar-compartido.ps1` | Sobrescribe copias desde `shared/`. Seguí [su guía](../shared/AGENTS.md). |
| `build-releases.ps1` | Compila cuando corresponde, arma ZIP, limpia paquetes viejos y genera manifiestos. No es un chequeo de solo lectura. |
| `releases.config.json` | Raíces de paquetes, builds, exclusiones y composición de tiendas. |
| `publicar.ps1` | Empaqueta, agrega cambios a Git, comitea, etiqueta, hace push y crea una Release. Solo por pedido explícito. |

## Al modificar herramientas

- Revisá [PUBLICAR.md](../PUBLICAR.md) en la sección afectada. Agregar una app
  requiere mantener builds del JSON, lista de `verificar.ps1` y matriz del CI.
- Comprobá códigos de salida de programas externos; no confundas stderr con
  un fallo. Restaurá ubicación y preferencias al salir de funciones/scripts.
- Las exclusiones se comparan por nombre de archivo/carpeta en cada nivel.
  `AGENTS.md`, `AGENTS.override.md` y configuración local de agentes quedan
  fuera de los ZIP; los manuales de usuario pueden seguir distribuyéndose.
- Los manifiestos de `releases/` son generados: no cambies versiones ni hashes
  a mano. `-Force` no autoriza reemplazar una versión ya publicada.
- Probá validadores con casos válidos e inválidos. Probá empaquetado en una
  carpeta temporal o inspeccionando la función aislada, sin publicar ni borrar
  paquetes existentes para simular una prueba.
- Mantené los controles equivalentes entre local y CI. Consultá
  [.github/AGENTS.md](../.github/AGENTS.md) si cambia un workflow.

La deuda del publicador está en [AUDITORIA](../AUDITORIA.md#consistencia).
Antes de invocarlo, revisá todos los cambios: actualmente usa `git add -A`.
Una autorización para editar o preparar GitHub no autoriza una Release.
