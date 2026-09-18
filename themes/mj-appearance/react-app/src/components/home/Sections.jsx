import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ArrowRight, Instagram, Star } from 'lucide-react';
import { MaskReveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ProductImage } from '@/components/ui/ProductImage';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { Carousel } from '@/components/ui/Carousel';
import { reviews as demoReviews } from '@/data/catalog';
import { useCatalog } from '@/store/catalog';
import { IS_WP, SOCIAL, REVIEWS_ENABLED } from '@/lib/config';
import { t } from '@/lib/copy';
import { fetchReviews } from '@/lib/wc';
import { TURNOS, getReviews, normalizeReview } from '@/lib/turnos';

/* ------------------------- Categorías inmersivas ------------------------- */
function CategoryTile({ category, cover, index }) {
  return (
    <Link to={`/categoria/${category.slug}`} className="mj-showcase__tile group block">
      <div className="relative aspect-3/4 overflow-hidden rounded-xl3 border border-line">
        <MaskReveal className="absolute inset-0" delay={index * 0.08}>
          <ProductImage
            product={cover}
            className="absolute inset-0"
            imgClassName="transition-transform duration-700 group-hover:scale-105"
          />
        </MaskReveal>
        <div className="mj-showcase__veil absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent" />
        <div className="absolute inset-x-0 bottom-0 p-6">
          <h3 className="mj-showcase__title display text-2xl text-white">{category.name}</h3>
          <p className="mt-1 text-sm text-white/60">{category.description}</p>
          <span className="mj-showcase__arrow mt-4 inline-flex size-9 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all duration-300 group-hover:bg-white group-hover:text-black">
            <ArrowRight size={15} className="transition-transform duration-300 group-hover:-rotate-45" />
          </span>
        </div>
      </div>
    </Link>
  );
}

/**
 * `slugs`: categorías elegidas en el bloque (en ese orden); vacío = las
 * `limit` con más productos. `layout` 'carousel' las pone en un carrusel.
 */
export function CategoryShowcase({ slugs, limit = 4, layout = 'grid' } = {}) {
  const { categories, products } = useCatalog();
  const chosen = Array.isArray(slugs) ? slugs : [];
  const featured = chosen.length
    ? chosen.map((slug) => categories.find((c) => c.slug === slug)).filter(Boolean)
    : categories.slice(0, Math.max(1, Number(limit) || 4));
  const cover = (slug) =>
    products.find((p) => p.category === slug || (p.categories || []).includes(slug));

  if (layout === 'carousel') {
    return (
      <Carousel cols={Math.min(4, Math.max(2, featured.length))} ariaLabel="Categorías">
        {featured.map((c, i) => (
          <CategoryTile key={c.slug} category={c} cover={cover(c.slug)} index={i} />
        ))}
      </Carousel>
    );
  }

  return (
    <Stagger className="mj-showcase grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      {featured.map((c, i) => (
        <motion.div key={c.slug} variants={staggerItem}>
          <CategoryTile category={c} cover={cover(c.slug)} index={i} />
        </motion.div>
      ))}
    </Stagger>
  );
}

/* ------------------------------ Testimonios ------------------------------ */

const REVIEW_SOURCES = ['auto', 'woo', 'turnos'];

/* Opinión de MJ Turnos → la forma de una tarjeta (el servicio va como subtítulo). */
const fromTurnos = (r) => ({ id: `turno-${r.id}`, name: r.name, rating: r.rating, text: r.body, role: r.serviceName });

/* Opiniones de los turnos con texto (las que vinieron en window.MJ.turnos.reviews). */
const turnosInitial = () =>
  TURNOS?.reviews.enabled ? TURNOS.reviews.items.filter((r) => r.body.trim() !== '' && r.name) : [];

/**
 * `source`: 'auto' (default: las valoraciones de WooCommerce como siempre y,
 * si no hay ninguna, las opiniones de MJ Turnos Online), 'woo' o 'turnos'.
 * Sin opiniones de ninguna fuente, la sección no se muestra.
 */
export function Testimonials({ eyebrow, title, limit = 4, source = 'auto' } = {}) {
  // En WordPress: opiniones reales de clientes (Store API). Sin opiniones → sin sección.
  // Standalone: opiniones demo para desarrollo.
  // Valoraciones desactivadas en WooCommerce → sin las de WooCommerce (las de los turnos van aparte).
  const count = Math.max(1, Number(limit) || 4);
  const src = REVIEW_SOURCES.includes(source) ? source : 'auto';
  const wantsWoo = src !== 'turnos';
  const wantsTurnos = src !== 'woo' && !!TURNOS?.reviews.enabled;
  const [fetched, setFetched] = useState(null);
  const [turnos, setTurnos] = useState(turnosInitial);

  useEffect(() => {
    if (!IS_WP || !REVIEWS_ENABLED || !wantsWoo) return undefined;
    let live = true;
    fetchReviews(count)
      .then((r) => { if (live) setFetched(Array.isArray(r) ? r : []); })
      .catch(() => { if (live) setFetched([]); });
    return () => { live = false; };
  }, [count, wantsWoo]);

  // Las de los turnos: si en la config vinieron menos de las que hay y de las que se piden, se buscan más.
  useEffect(() => {
    if (!IS_WP || !wantsTurnos) return undefined;
    const initial = turnosInitial();
    if (initial.length >= count || TURNOS.reviews.count <= TURNOS.reviews.items.length) return undefined;
    let live = true;
    getReviews({ limit: count })
      .then((r) => {
        if (!live) return;
        const items = (Array.isArray(r?.items) ? r.items : []).map(normalizeReview).filter((x) => x.body.trim() !== '' && x.name);
        if (items.length) setTurnos(items);
      })
      .catch(() => { /* quedan las de la config */ });
    return () => { live = false; };
  }, [count, wantsTurnos]);

  const woo = !wantsWoo || !REVIEWS_ENABLED ? [] : IS_WP ? fetched : demoReviews.slice(0, count);
  const fromBookings = wantsTurnos ? turnos.slice(0, count).map(fromTurnos) : [];

  let list = null;
  if (src === 'turnos') list = fromBookings;
  else if (src === 'woo') list = woo;
  else if (woo === null) list = null; // todavía llegan las de WooCommerce: no se decide nada
  else list = woo.length ? woo : fromBookings;
  if (!list?.length) return null;

  return (
    <Section>
      <SectionHeader eyebrow={eyebrow || t('home_testimonials_eyebrow')} title={title || t('home_testimonials_title')} />
      <Stagger className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        {list.map((r, i) => (
        <motion.figure
          key={r.id ?? `${r.name}-${i}`}
          variants={staggerItem}
          className="mj-testimonial flex flex-col justify-between rounded-xl3 border border-line glass p-7"
        >
          <div>
            <div className="flex gap-0.5">
              {Array.from({ length: r.rating }).map((_, i) => (
                <Star key={i} size={14} className="fill-(--mj-star) text-(--mj-star)" />
              ))}
            </div>
            <blockquote className="mj-testimonial__quote mt-4 text-sm leading-relaxed text-ink-soft">
              “{r.text}”
            </blockquote>
          </div>
          <figcaption className="mt-6 flex items-center gap-3">
            <span className="grid size-9 place-items-center rounded-full bg-accent-soft font-display text-sm font-semibold text-accent">
              {r.name[0]}
            </span>
            <div>
              <p className="text-sm font-medium">{r.name}</p>
              {r.role && <p className="text-xs text-ink-faint">{r.role}</p>}
            </div>
          </figcaption>
        </motion.figure>
        ))}
      </Stagger>
    </Section>
  );
}

/* ------------------------------- Instagram ------------------------------- */
export function InstagramStrip({ limit = 6 } = {}) {
  const shots = useCatalog((s) => s.products).slice(0, Math.max(1, Number(limit) || 6));
  const url = SOCIAL?.instagram;
  if (!url) return null;
  return (
    <div className="mj-ig">
      <Stagger className="grid grid-cols-3 gap-3 md:grid-cols-6" gap={0.05}>
        {shots.map((p) => (
          <motion.a
            key={p.id}
            variants={staggerItem}
            href={url}
            target="_blank"
            rel="noreferrer"
            className="mj-ig__shot group relative block overflow-hidden rounded-xl2 border border-line"
            aria-label="Ver en Instagram"
          >
            <ProductImage
              product={p}
              className="aspect-square"
              imgClassName="transition-transform duration-500 group-hover:scale-110"
            />
            <span className="absolute inset-0 grid place-items-center bg-black/50 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
              <Instagram size={22} className="text-white" />
            </span>
          </motion.a>
        ))}
      </Stagger>
    </div>
  );
}
