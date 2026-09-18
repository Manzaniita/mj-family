import { useEffect, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { Modal } from '@/components/ui/Modal';
import { Stagger, staggerItem } from '@/components/motion/Reveal';
import { t } from '@/lib/copy';
import { cn } from '@/lib/format';

/* Forma de las fotos de la grilla ('original' = como vienen). */
const RATIOS = {
  square: 'aspect-square',
  portrait: 'aspect-4/5',
  landscape: 'aspect-4/3',
  original: '',
};

const clampCols = (value, min, max, fallback) => {
  const n = Math.round(Number(value));
  return Number.isFinite(n) && n >= min ? Math.min(max, n) : fallback;
};

/* Fotos que manda PHP (mj_block_gallery_items): solo las que tienen imagen. */
const cleanPhotos = (list) =>
  (Array.isArray(list) ? list : [])
    .filter((p) => p && typeof p.src === 'string' && p.src)
    .map((p, i) => ({
      key: `${i}-${p.src}`,
      src: p.src,
      full: typeof p.full === 'string' && p.full ? p.full : p.src,
      caption: typeof p.caption === 'string' ? p.caption.trim() : '',
      alt: typeof p.alt === 'string' ? p.alt : '',
      width: Number(p.width) || undefined,
      height: Number(p.height) || undefined,
    }));

/**
 * Galería de trabajos (bloque mj/galeria): fotos con su nombre debajo, un
 * filtro opcional por leyenda y la foto grande al tocarla (Modal del tema,
 * con anterior/siguiente y las flechas del teclado). Con la animación en
 * "Ninguna" todo aparece y se abre quieto (lib/motion.js).
 */
export function GalleryBlock({ eyebrow, title, images, showCaptions = true, filter = false, cols, colsMobile, ratio }) {
  const photos = useMemo(() => cleanPhotos(images), [images]);
  const labels = useMemo(() => [...new Set(photos.map((p) => p.caption).filter(Boolean))], [photos]);
  const withFilter = !!filter && labels.length > 1;
  const [active, setActive] = useState('');
  const [open, setOpen] = useState(-1);

  const shown = withFilter && active ? photos.filter((p) => p.caption === active) : photos;
  const current = open >= 0 ? shown[open] : null;

  const step = (dir) => setOpen((i) => (i < 0 ? i : (i + dir + shown.length) % shown.length));

  // Flechas del teclado con la foto abierta (Escape lo maneja el Modal).
  useEffect(() => {
    if (!current || shown.length < 2) return undefined;
    const onKey = (e) => {
      if (e.key === 'ArrowRight') step(1);
      if (e.key === 'ArrowLeft') step(-1);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [current, shown.length]);

  if (!photos.length) return null;

  const desktop = clampCols(cols, 1, 6, 3);
  const mobile = clampCols(colsMobile, 1, 2, 2);
  const shape = RATIOS[ratio] ?? RATIOS.square;

  return (
    <Section className="mj-gallery-block">
      {(eyebrow || title) && <SectionHeader eyebrow={eyebrow} title={title || ''} />}

      {withFilter && (
        <div className="mj-gallery-filter -mt-4 mb-8 flex flex-wrap gap-2 md:-mt-6 md:mb-10" role="group" aria-label={title || undefined}>
          {['', ...labels].map((label) => {
            const on = active === label;
            return (
              <button
                key={label || '__all'}
                type="button"
                onClick={() => setActive(label)}
                aria-pressed={on}
                className={cn(
                  'mj-gallery-filter__chip rounded-(--mj-radius-pill) border px-4 py-2 text-sm font-medium transition-colors',
                  on ? 'mj-gallery-filter__chip--active border-accent bg-accent text-white' : 'border-line text-ink-soft hover:border-accent hover:text-ink'
                )}
              >
                {label || t('gallery_filter_all')}
              </button>
            );
          })}
        </div>
      )}

      {/* Las columnas van por variables CSS (globals.css → .mj-gallery-grid). */}
      <div style={{ '--mj-gcols': desktop, '--mj-gcols-t': Math.min(desktop, 3), '--mj-gcols-m': mobile }}>
      <Stagger key={active} className="mj-gallery-grid" gap={0.05}>
        {shown.map((photo, i) => (
          <motion.figure
            key={photo.key}
            variants={staggerItem}
            className="mj-gallery-item m-0"
          >
            <button
              type="button"
              onClick={() => setOpen(i)}
              className="mj-gallery-item__button group block w-full overflow-hidden rounded-xl2 border border-line bg-surface"
              aria-label={`${t('gallery_open')}${photo.caption ? `: ${photo.caption}` : ''}`}
            >
              <img
                src={photo.src}
                alt={photo.alt}
                width={photo.width}
                height={photo.height}
                loading="lazy"
                decoding="async"
                className={cn('mj-gallery-item__img block w-full object-cover transition-transform duration-500 group-hover:scale-105', shape || 'h-auto')}
              />
            </button>
            {showCaptions !== false && photo.caption && (
              <figcaption className="mj-gallery-item__caption mt-2 text-center text-sm font-medium text-ink md:mt-3">{photo.caption}</figcaption>
            )}
          </motion.figure>
        ))}
      </Stagger>
      </div>

      <Modal open={!!current} onClose={() => setOpen(-1)} wide label={current?.caption || current?.alt || t('gallery_open')}>
        {current && (
          <figure className="mj-gallery-lightbox m-0 p-4 pt-14 md:p-6 md:pt-14">
            <div className="relative">
              <img
                src={current.full}
                alt={current.alt}
                className="mj-gallery-lightbox__img mx-auto block max-h-[75vh] w-auto max-w-full rounded-xl2 object-contain"
              />
              {shown.length > 1 && (
                <>
                  <button
                    type="button"
                    onClick={() => step(-1)}
                    className="mj-gallery-lightbox__prev absolute left-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/45 text-white backdrop-blur-md"
                    aria-label={t('gallery_prev')}
                  >
                    <ChevronLeft size={20} />
                  </button>
                  <button
                    type="button"
                    onClick={() => step(1)}
                    className="mj-gallery-lightbox__next absolute right-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/45 text-white backdrop-blur-md"
                    aria-label={t('gallery_next')}
                  >
                    <ChevronRight size={20} />
                  </button>
                </>
              )}
            </div>
            {current.caption && (
              <figcaption className="mj-gallery-lightbox__caption mt-4 text-center font-display text-lg font-semibold">{current.caption}</figcaption>
            )}
          </figure>
        )}
      </Modal>
    </Section>
  );
}
