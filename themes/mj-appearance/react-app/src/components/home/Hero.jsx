import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { ArrowRight, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Counter, Magnetic, Parallax } from '@/components/motion/Reveal';
import { t } from '@/lib/copy';
import { useCatalog } from '@/store/catalog';
import { WP, SITE, REVIEWS_ENABLED } from '@/lib/config';
import { EASE, formatPrice, FREE_SHIPPING_THRESHOLD } from '@/lib/format';

/**
 * Hero editorial de una columna, 100% editable desde Apariencia → MJ Appearance
 * (window.MJ.hero). Campo vacío → ese elemento se oculta.
 * Si hay fotos configuradas (hero.images), rotan de fondo en crossfade y, en
 * nivel cinematográfico, se mueven en parallax con el scroll.
 */
/*
 * `override`: lo que escribió el admin en el bloque "Hero" de una página
 * (components/bloques/registry.jsx). Cada campo con texto pisa al del panel;
 * vacío = el del panel, igual que siempre.
 */
export function Hero({ override } = {}) {
  const base = WP?.hero || {};
  const o = override || {};
  const badge = o.badge || base.badge || '';
  const title = o.title || base.title || '';
  const titleHighlight = o.titleHighlight || base.titleHighlight || '';
  // Subtítulo vacío → tagline del sitio; si ambos están vacíos → sin subtítulo.
  const subtitle = o.subtitle || base.subtitle || SITE.tagline || '';
  const ctaPrimary = o.ctaPrimary?.text
    ? { text: o.ctaPrimary.text, url: o.ctaPrimary.url || base.ctaPrimary?.url }
    : base.ctaPrimary || {};
  const ctaSecondary = o.ctaSecondary?.text
    ? { text: o.ctaSecondary.text, url: o.ctaSecondary.url || base.ctaSecondary?.url }
    : base.ctaSecondary || {};
  const ownImages = Array.isArray(o.images) ? o.images.filter(Boolean) : [];
  const images = ownImages.length ? ownImages : (Array.isArray(base.images) ? base.images.filter(Boolean) : []);
  const hasImages = images.length > 0;
  const showStats = o.showStats !== false;

  const [idx, setIdx] = useState(0);

  // Rotación de fotos de fondo (una sola foto → fondo fijo, sin timer).
  useEffect(() => {
    if (images.length < 2) return undefined;
    const timer = setInterval(() => setIdx((i) => (i + 1) % images.length), 6000);
    return () => clearInterval(timer);
  }, [images.length]);

  const products = useCatalog((s) => s.products);

  // Stats reales del catálogo — sin cifras inventadas.
  const rated = products.filter((p) => p.rating > 0);
  const avgRating = rated.length
    ? rated.reduce((n, p) => n + p.rating, 0) / rated.length
    : 0;
  const stats = [];
  if (showStats && products.length > 0) stats.push([`${products.length}+`, t('home_hero_stat_products')]);
  if (showStats && FREE_SHIPPING_THRESHOLD > 0) stats.push([formatPrice(FREE_SHIPPING_THRESHOLD), t('home_hero_stat_shipping')]);
  if (showStats && REVIEWS_ENABLED && avgRating > 0) stats.push([avgRating.toFixed(1).replace('.', ','), t('home_hero_stat_rating')]);

  return (
    <section
      className={`mj-hero relative overflow-hidden ${hasImages ? 'mj-hero--media flex min-h-[70vh] items-center md:min-h-[78vh]' : ''}`}
    >
      {/* Fotos de fondo en crossfade (+ parallax en nivel cinematográfico) */}
      {hasImages && (
        <div className="mj-hero__media absolute inset-0 overflow-hidden" aria-hidden="true">
          <Parallax speed={0.3} className="absolute inset-x-0 -inset-y-[12%]">
            {images.map((imgDef, i) => {
              const src = typeof imgDef === 'string' ? imgDef : imgDef.url;
              const style = typeof imgDef === 'object' && imgDef.focal ? { objectPosition: `${imgDef.focal.x * 100}% ${imgDef.focal.y * 100}%` } : {};
              return (
              <img
                key={src}
                src={src}
                alt=""
                style={style}
                className={`absolute inset-0 h-full w-full object-cover transition-opacity duration-[1600ms] ease-out ${
                  i === idx ? 'opacity-100' : 'opacity-0'
                }`}
              />
              );
            })}
          </Parallax>
          {/* veladura para que el texto siempre sea legible */}
          <div className="mj-hero__veil absolute inset-0 bg-black/55" />
          <div className="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-bg to-transparent" />
        </div>
      )}

      {/*
        Glow ambiental (solo sin fotos de fondo). Es un degradado radial y no un
        filter: blur(120px): el blur sobre un círculo de 600 px se recalcula en
        cada repintado (scroll, hover, cambio de tema) y era lo más caro de la
        portada. El degradado se ve igual y no cuesta nada.
      */}
      {!hasImages && (
        <div
          className="mj-hero__glow pointer-events-none absolute left-1/2 top-1/3 h-150 w-150 -translate-x-1/2 rounded-full opacity-30"
          style={{ background: 'radial-gradient(closest-side, var(--mj-accent) 0%, color-mix(in srgb, var(--mj-accent) 45%, transparent) 45%, transparent 100%)' }}
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
              <span className="mj-hero__highlight">
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
            className={`mj-hero__stats mt-12 flex flex-wrap justify-center gap-x-10 gap-y-4 ${hasImages ? 'text-white' : ''}`}
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
