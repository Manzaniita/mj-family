# Código compartido — una fuente, varias copias

Aplican [las reglas de la raíz](../AGENTS.md).

- `class-mj-menu.php`: menú MJ común en wp-admin.
- `class-mj-log.php`: registro común de errores y diagnóstico.
- Los consumidores se declaran en `$compartidos` de
  [sincronizar-compartido.ps1](../tools/sincronizar-compartido.ps1).

## Secuencia de cambio

1. Leé el archivo original y la lista de consumidores. Si cambia un contrato,
   buscá sus llamadas en esos plugins y consultá sus instrucciones.
2. Editá solo la fuente de esta carpeta. Las clases deben tolerar varias copias
   cargadas y consumidores con distintas versiones durante una actualización.
3. Desde la raíz, ejecutá `.\tools\sincronizar-compartido.ps1`. Las copias pueden
   estar en `includes/compartido/`, `inc/compartido/` o `compartido/`.
4. Revisá el diff: subí cabecera y constante de cada plugin cuya copia cambió,
   además de `Stable tag` si existe y la tabla del README. No subas la versión
   de consumidores cuya copia quedó idéntica.
5. Ejecutá `.\tools\sincronizar-compartido.ps1 -Verificar` y la verificación
   general correspondiente. Una copia faltante también es un error.

No repartas `AGENTS.md` a los plugins. Si incorporás otro archivo compartido o
consumidor, actualizá la lista del script y las instrucciones necesarias en
el mismo cambio. Detalle: [PUBLICAR §8](../PUBLICAR.md#cambio-existente).
