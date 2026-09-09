import { useState } from 'react';
import { SITE } from '@/lib/config';
import { cn } from '@/lib/format';

/**
 * Imagen de producto con fallback: si la foto no carga, muestra un
 * degradado basado en el hue del producto (.mj-img en globals.css, que se
 * adapta al tema claro/oscuro) — nunca un ícono roto.
 */
export function ProductImage({ product, src, className, imgClassName }) {
  const [failed, setFailed] = useState(false);
  const hue = product?.hue ?? 250;
  const url = src ?? product?.image;
  // Si el caller ya fija la posición (p. ej. `absolute inset-0` en la card),
  // no imponer `relative`: en la cascada de Tailwind `.relative` pisa a
  // `.absolute` y colapsa el contenedor a alto 0 (imagen invisible).
  const hasPosition = /\b(?:static|fixed|absolute|relative|sticky)\b/.test(className ?? '');

  return (
    <div
      className={cn('mj-img', !hasPosition && 'relative', 'overflow-hidden', className)}
      style={{ '--mj-hue': hue }}
    >
      {!failed && url && (
        <img
          src={url}
          alt={product?.name ?? ''}
          loading="lazy"
          onError={() => setFailed(true)}
          className={cn('absolute inset-0 h-full w-full object-cover', imgClassName)}
        />
      )}
      {failed && (
        <div className="absolute inset-0 grid place-items-center">
          <span className="display text-5xl opacity-20">{product?.name?.[0] ?? SITE?.name?.[0] ?? 'Y'}</span>
        </div>
      )}
    </div>
  );
}
