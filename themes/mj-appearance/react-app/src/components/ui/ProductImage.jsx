import { useState } from 'react';
import { SITE } from '@/lib/config';
import { cn } from '@/lib/format';

/**
 * Imagen de producto con fallback: si la foto no carga, muestra un
 * degradado basado en el hue del producto (.mj-img en globals.css, que se
 * adapta al tema claro/oscuro) — nunca un ícono roto.
 */
/** Ancho que ocupa una card en cada corte de pantalla (para que el navegador elija el tamaño). */
export const CARD_SIZES = '(min-width: 1280px) 25vw, (min-width: 640px) 50vw, 100vw';

/*
 * `image` puede ser un objeto { src, srcset, sizes, alt } (galleryImages) o
 * nada: entonces va la foto principal del producto con su srcset. `sizes`
 * dice cuánto ancho ocupa la imagen en pantalla; sin eso el navegador asume
 * 100vw y baja la foto más grande aunque la card mida 200 px.
 * `priority`: la foto principal de la ficha carga primero, sin lazy.
 */
export function ProductImage({ product, src, image, sizes = CARD_SIZES, priority = false, className, imgClassName }) {
  const [failed, setFailed] = useState(false);
  const hue = product?.hue ?? 250;
  const set = image ?? (src ? null : product?.imageSet);
  const url = image?.src ?? src ?? product?.image;
  const srcSet = set?.srcset || undefined;
  // Si el caller ya fija la posición (p. ej. `absolute inset-0` en la card),
  // no imponer `relative`: en la cascada de Tailwind `.relative` pisa a
  // `.absolute` y colapsa el contenedor a alto 0 (imagen invisible).
  const hasPosition = /\b(?:static|fixed|absolute|relative|sticky)\b/.test(className ?? '');
  const focal = image?.focal ?? set?.focal ?? product?.focal;
  const focalStyle = focal && typeof focal === 'object' && typeof focal.x === 'number' && typeof focal.y === 'number'
    ? { objectPosition: `${Math.round(focal.x * 100)}% ${Math.round(focal.y * 100)}%` }
    : undefined;

  return (
    <div
      className={cn('mj-img', !hasPosition && 'relative', 'overflow-hidden', className)}
      style={{ '--mj-hue': hue }}
    >
      {!failed && url && (
        <img
          src={url}
          srcSet={srcSet}
          sizes={srcSet ? sizes : undefined}
          alt={image?.alt || product?.name || ''}
          loading={priority ? 'eager' : 'lazy'}
          fetchPriority={priority ? 'high' : undefined}
          decoding="async"
          onError={() => setFailed(true)}
          style={focalStyle}
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
