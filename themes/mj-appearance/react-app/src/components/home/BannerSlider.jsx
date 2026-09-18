import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { MaskReveal } from '@/components/motion/Reveal';
import { t } from '@/lib/copy';
import { WP } from '@/lib/config';

/* Alto del slider según el ajuste del bloque (la portada clásica usa 'md'). */
const HEIGHTS = {
  sm: 'h-56 sm:h-72 md:h-80',
  md: 'h-72 sm:h-[26rem] md:h-[32rem]',
  lg: 'h-80 sm:h-[32rem] md:h-[40rem]',
};

/**
 * Slider de banners promocionales (Apariencia → MJ Appearance → Banners promocionales).
 * Ocupa el ancho del contenedor estándar; flechas prev/next con loop.
 * Sin banners configurados → no se renderiza.
 *
 * Desde el bloque "Slider de banners" se le pueden pasar otras imágenes,
 * que pase solo cada `interval` segundos y el alto.
 */
export function BannerSlider({ images, autoplay = false, interval = 6, height = 'md' } = {}) {
  const own = (Array.isArray(images) ? images : []).filter(Boolean);
  const banners = own.length ? own : (Array.isArray(WP?.promoBanners) ? WP.promoBanners : []).filter(Boolean);
  const [idx, setIdx] = useState(0);
  const [paused, setPaused] = useState(false);

  useEffect(() => {
    if (!autoplay || paused || banners.length < 2) return undefined;
    const ms = Math.max(2, Number(interval) || 6) * 1000;
    const timer = setInterval(() => setIdx((i) => (i + 1) % banners.length), ms);
    return () => clearInterval(timer);
  }, [autoplay, paused, interval, banners.length]);

  if (!banners.length) return null;

  const go = (dir) => setIdx((i) => (i + dir + banners.length) % banners.length);

  const arrowCls =
    'mj-banner__arrow absolute top-1/2 -translate-y-1/2 grid size-10 place-items-center rounded-full bg-black/45 text-white backdrop-blur-md transition-colors hover:bg-black/70';

  return (
    <div
      className="mj-banner relative"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <MaskReveal className="overflow-hidden rounded-xl3 border border-line">
        <div
          className="flex transition-transform duration-700 ease-out"
          style={{ transform: `translateX(-${idx * 100}%)` }}
        >
          {banners.map((src, i) => (
            <img
              key={src}
              src={src}
              alt=""
              loading={i === 0 ? 'eager' : 'lazy'}
              className={`w-full shrink-0 object-cover ${HEIGHTS[height] || HEIGHTS.md}`}
            />
          ))}
        </div>
      </MaskReveal>

      {banners.length > 1 && (
        <>
          <button onClick={() => go(-1)} className={`${arrowCls} left-3`} aria-label={t('ui_banner_prev')}>
            <ChevronLeft size={20} />
          </button>
          <button onClick={() => go(1)} className={`${arrowCls} right-3`} aria-label={t('ui_banner_next')}>
            <ChevronRight size={20} />
          </button>
        </>
      )}
    </div>
  );
}
