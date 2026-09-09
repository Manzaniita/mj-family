import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ArrowRight, Instagram, Star } from 'lucide-react';
import { MaskReveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ProductImage } from '@/components/ui/ProductImage';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { reviews as demoReviews } from '@/data/catalog';
import { useCatalog } from '@/store/catalog';
import { IS_WP, SOCIAL, REVIEWS_ENABLED } from '@/lib/config';
import { t } from '@/lib/copy';
import { fetchReviews } from '@/lib/wc';

/* ------------------------- Categorías inmersivas ------------------------- */
export function CategoryShowcase() {
  const { categories, products } = useCatalog();
  const featured = categories.slice(0, 4);
  const cover = (slug) =>
    products.find((p) => p.category === slug || (p.categories || []).includes(slug));
  return (
    <Stagger className="mj-showcase grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      {featured.map((c, i) => (
        <motion.div key={c.slug} variants={staggerItem}>
          <Link to={`/categoria/${c.slug}`} className="mj-showcase__tile group block">
            <div className="relative aspect-3/4 overflow-hidden rounded-xl3 border border-line">
              <MaskReveal className="absolute inset-0" delay={i * 0.08}>
                <ProductImage
                  product={cover(c.slug)}
                  className="absolute inset-0"
                  imgClassName="transition-transform duration-700 group-hover:scale-105"
                />
              </MaskReveal>
              <div className="mj-showcase__veil absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent" />
              <div className="absolute inset-x-0 bottom-0 p-6">
                <h3 className="mj-showcase__title display text-2xl text-white">{c.name}</h3>
                <p className="mt-1 text-sm text-white/60">{c.description}</p>
                <span className="mj-showcase__arrow mt-4 inline-flex size-9 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all duration-300 group-hover:bg-white group-hover:text-black">
                  <ArrowRight size={15} className="transition-transform duration-300 group-hover:-rotate-45" />
                </span>
              </div>
            </div>
          </Link>
        </motion.div>
      ))}
    </Stagger>
  );
}

/* ------------------------------ Testimonios ------------------------------ */
export function Testimonials() {
  // En WordPress: opiniones reales de clientes (Store API). Sin opiniones → sin sección.
  // Standalone: opiniones demo para desarrollo.
  // Valoraciones desactivadas en WooCommerce → la sección entera se oculta.
  const [fetched, setFetched] = useState(null);

  useEffect(() => {
    if (!IS_WP || !REVIEWS_ENABLED) return undefined;
    let live = true;
    fetchReviews(4)
      .then((r) => { if (live) setFetched(Array.isArray(r) ? r : []); })
      .catch(() => { if (live) setFetched([]); });
    return () => { live = false; };
  }, []);

  const list = IS_WP ? fetched : demoReviews;
  if (!REVIEWS_ENABLED || !list?.length) return null;

  return (
    <Section>
      <SectionHeader eyebrow={t('home_testimonials_eyebrow')} title={t('home_testimonials_title')} />
      <Stagger className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        {list.map((r) => (
        <motion.figure
          key={r.name}
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
export function InstagramStrip() {
  const shots = useCatalog((s) => s.products).slice(0, 6);
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
