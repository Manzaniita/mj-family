import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ArrowRight } from 'lucide-react';
import { Stagger, staggerItem } from '@/components/motion/Reveal';
import { ProductImage } from '@/components/ui/ProductImage';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { useCatalog } from '@/store/catalog';
import { COLEGAS, colegaPath, projectPath } from '@/lib/colegas';
import { cn } from '@/lib/format';

/**
 * Colegas con al menos un producto publicado, con su foto de portada
 * (la propia cargada en el plugin o, si no hay, la del primer producto).
 */
export function useColegasWithProducts() {
  const products = useCatalog((s) => s.products);
  if (!COLEGAS) return [];
  return COLEGAS.list
    .map((c) => {
      const items = products.filter((p) => p.colega === c.slug);
      return {
        ...c,
        count: items.length,
        cover: c.image || items.find((p) => p.image)?.image || null,
        hue: items[0]?.hue ?? 30,
      };
    })
    .filter((c) => c.count > 0);
}

/** Tarjeta de colega (portada, nombre, cantidad de piezas, descripción). */
export function ColegaCard({ colega, className }) {
  const label = colega.count === 1 ? COLEGAS?.labels.singular : COLEGAS?.labels.plural;
  return (
    <Link to={colegaPath(colega.slug)} className={cn('mj-colega-card group block', className)}>
      <div className="relative aspect-4/5 overflow-hidden rounded-xl3 border border-line">
        <ProductImage
          product={{ name: colega.name, hue: colega.hue }}
          src={colega.cover}
          className="absolute inset-0"
          imgClassName="transition-transform duration-700 group-hover:scale-105"
        />
        <div className="mj-colega-card__veil absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-transparent" />
        <div className="absolute inset-x-0 bottom-0 p-6">
          <p className="mj-colega-card__count text-[11px] font-semibold uppercase tracking-[0.2em] text-white/60">
            {colega.count} {colega.count === 1 ? 'pieza' : 'piezas'}
          </p>
          <h3 className="mj-colega-card__name display mt-1 text-2xl text-white">{colega.name}</h3>
          {colega.description && (
            <p className="mt-1 line-clamp-2 text-sm text-white/70">{colega.description}</p>
          )}
          <span className="mj-colega-card__arrow mt-4 inline-flex size-9 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all duration-300 group-hover:bg-white group-hover:text-black">
            <ArrowRight size={15} className="transition-transform duration-300 group-hover:-rotate-45" />
          </span>
        </div>
      </div>
      <span className="sr-only">{label}</span>
    </Link>
  );
}

/**
 * Sección de la portada: las colegas del proyecto (plugin MJ Colegas).
 * `override`: lo escrito en el bloque "Colegas" (textos y cantidad); vacío = Colegas → Ajustes.
 */
export function ColegasShowcase({ override } = {}) {
  const o = override || {};
  const colegas = useColegasWithProducts();
  if (!COLEGAS || (!override && !COLEGAS.home.enabled) || colegas.length === 0) return null;
  const { home, project } = COLEGAS;
  const limit = Number(o.limit) > 0 ? Number(o.limit) : 4;
  return (
    <Section className="mj-colegas-home">
      <SectionHeader
        eyebrow={o.eyebrow || home.eyebrow}
        title={o.title || home.title || project.title}
        subtitle={o.subtitle || home.subtitle}
        linkTo={projectPath()}
        linkLabel={o.linkLabel || home.link || project.name}
      />
      <Stagger className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {colegas.slice(0, limit).map((c) => (
          <motion.div key={c.slug} variants={staggerItem}>
            <ColegaCard colega={c} />
          </motion.div>
        ))}
      </Stagger>
    </Section>
  );
}
