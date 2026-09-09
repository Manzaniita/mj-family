import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { ArrowRight, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Counter, Magnetic, Parallax } from '@/components/motion/Reveal';
import { useCatalog } from '@/store/catalog';
import { WP, SITE, REVIEWS_ENABLED } from '@/lib/config';
import { EASE, formatPrice, FREE_SHIPPING_THRESHOLD } from '@/lib/format';

/**
 * Hero editorial de una columna, 100% editable desde Apariencia → MJ Appearance
 * (window.MJ.hero). Campo vacío → ese elemento se oculta.
 * Si hay fotos configuradas (hero.images), rotan de fondo en crossfade y, en
 * nivel cinematográfico, se mueven en parallax con el scroll.
 */
export function Hero() {
  const hero = WP?.hero || {};
  const badge = hero.badge || '';
  const title = hero.title || '';
  const titleHighlight = hero.titleHighlight || '';
  // Subtítulo vacío → tagline del sitio; si ambos están vacíos → sin subtítulo.
  const subtitle = hero.subtitle || SITE.tagline || '';
  const ctaPrimary = hero.ctaPrimary || {};
  const ctaSecondary = hero.ctaSecondary || {};
  const images = Array.isArray(hero.images) ? hero.images.filter(Boolean) : [];
  const hasImages = images.length > 0;

  const [idx, setIdx] = useState(0);

  // Rotación de fotos de fondo (una sola foto → fondo fijo, sin timer).
  useEffect(() => {
    if (images.length < 2) return undefined;
    const t = setInterval(() => setIdx((i) => (i + 1) % images.length), 6000);
    return () => clearInterval(t);
  }, [images.length]);

  const products = useCatalog((s) => s.products);

  // Stats reales del catálogo — sin cifras inventadas.
  const rated = products.filter((p) => p.rating > 0);
  const avgRating = rated.length
    ? rated.reduce((n, p) => n + p.rating, 0) / rated.length
    : 0;
  const stats = [];
  if (products.length > 0) stats.push([`${products.length}+`, 'productos']);
  if (FREE_SHIPPING_THRESHOLD > 0) stats.push([formatPrice(FREE_SHIPPING_THRESHOLD), 'envío gratis desde']);
  if (REVIEWS_ENABLED && avgRating > 0) stats.push([avgRating.toFixed(1).replace('.', ','), 'rating promedio']);

  return (
    <section
      className={`mj-hero relative overflow-hidden ${hasImages ? 'mj-hero--media flex min-h-[70vh] items-center md:min-h-[78vh]' : ''}`}
    >
      {/* Fotos de fondo en crossfade (+ parallax en nivel cinematográfico) */}
      {hasImages && (
        <div className="mj-hero__media absolute inset-0 overflow-hidden" aria-hidden="true">
          <Parallax speed={0.3} className="absolute inset-x-0 -inset-y-[12%]">
            {images.map((src, i) => (
              <img
                key={src}
                src={src}
                alt=""
                className={`absolute inset-0 h-full w-full object-cover transition-opacity duration-[1600ms] ease-out ${
                  i === idx ? 'opacity-100' : 'opacity-0'
                }`}
              />
            ))}
          </Parallax>
          {/* veladura para que el texto siempre sea legible */}
          <div className="mj-hero__veil absolute inset-0 bg-black/55" />
          <div className="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-bg to-transparent" />
        </div>
      )}

      {/* glow ambiental (solo sin fotos de fondo) */}
      {!hasImages && (
        <div
          className="mj-hero__glow pointer-events-none absolute left-1/2 top-1/3 h-150 w-150 -translate-x-1/2 rounded-full opacity-30 blur-[120px]"
          style={{ background: 'var(--mj-accent)' }}
          aria-hidden="true"
        />
      )}

      <div className="mj-hero__inner relative mx-auto w-full max-w-3xl px-4 pb-20 pt-16 text-center md:px-8 md:pb-28 md:pt-24 lg:max-w-4xl">
        {badge && (
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, ease: EASE }}
            className="mj-hero__badge inline-flex items-center gap-2 rounded-(--mj-radius-pill) glass px-4 py-2 text-xs font-medium text-ink-soft"
          >
            <Sparkles size={13} className="text-accent" />
            {badge}
          </motion.div>
        )}

        {(title || titleHighlight) && (
          <motion.h1
            initial={{ opacity: 0, y: 32 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.1, ease: EASE }}
            className={`mj-hero__title display mt-6 text-5xl sm:text-6xl lg:text-7xl xl:text-[5.2rem] ${hasImages ? 'text-white' : ''}`}
          >
            {title}
            {title && titleHighlight && <br />}
            {titleHighlight && (
              <span
                className="mj-hero__highlight bg-clip-text text-transparent"
                style={{ backgroundImage: 'var(--mj-hero-gradient)' }}
              >
                {titleHighlight}
              </span>
            )}
          </motion.h1>
        )}

        {subtitle && (
          <motion.p
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.2, ease: EASE }}
            className={`mj-hero__subtitle mx-auto mt-6 max-w-xl text-lg leading-relaxed ${hasImages ? 'text-white/80' : 'text-ink-soft'}`}
          >
            {subtitle}
          </motion.p>
        )}

        {(ctaPrimary.text || ctaSecondary.text) && (
          <motion.div
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.3, ease: EASE }}
            className="mj-hero__actions mt-9 flex flex-wrap justify-center gap-3"
          >
            {ctaPrimary.text && (
              <Magnetic>
                <Button size="lg" to={ctaPrimary.url || '/tienda'}>
                  {ctaPrimary.text} <ArrowRight size={17} />
                </Button>
              </Magnetic>
            )}
            {ctaSecondary.text && (
              <Magnetic strength={0.25}>
                <Button size="lg" variant="secondary" to={ctaSecondary.url || '/tienda'}>
                  {ctaSecondary.text}
                </Button>
              </Magnetic>
            )}
          </motion.div>
        )}

        {stats.length > 0 && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 1, delay: 0.55 }}
            className={`mj-hero__stats mt-12 flex justify-center gap-10 ${hasImages ? 'text-white' : ''}`}
          >
            {stats.map(([n, l]) => (
              <div key={l} className="mj-hero__stat">
                <p className="font-display text-2xl font-semibold tracking-tight tabular-nums">
                  <Counter value={n} />
                </p>
                <p className={`mt-0.5 text-xs ${hasImages ? 'text-white/60' : 'text-ink-faint'}`}>{l}</p>
              </div>
            ))}
          </motion.div>
        )}
      </div>
    </section>
  );
}
