import { useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { MaskReveal } from '@/components/motion/Reveal';
import { WP } from '@/lib/config';

/**
 * Slider de banners promocionales (Apariencia → MJ Appearance → Banners promocionales).
 * Ocupa el ancho del contenedor estándar; flechas prev/next con loop.
 * Sin banners configurados → no se renderiza.
 */
export function BannerSlider() {
  const banners = (Array.isArray(WP?.promoBanners) ? WP.promoBanners : []).filter(Boolean);
  const [idx, setIdx] = useState(0);

  if (!banners.length) return null;

  const go = (dir) => setIdx((i) => (i + dir + banners.length) % banners.length);

  const arrowCls =
    'mj-banner__arrow absolute top-1/2 -translate-y-1/2 grid size-10 place-items-center rounded-full bg-black/45 text-white backdrop-blur-md transition-colors hover:bg-black/70';

  return (
    <div className="mj-banner relative">
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
              className="h-72 w-full shrink-0 object-cover sm:h-[26rem] md:h-[32rem]"
            />
          ))}
        </div>
      </MaskReveal>

      {banners.length > 1 && (
        <>
          <button onClick={() => go(-1)} className={`${arrowCls} left-3`} aria-label="Banner anterior">
            <ChevronLeft size={20} />
          </button>
          <button onClick={() => go(1)} className={`${arrowCls} right-3`} aria-label="Banner siguiente">
            <ChevronRight size={20} />
          </button>
        </>
      )}
    </div>
  );
}
