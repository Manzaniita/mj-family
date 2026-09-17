import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ArrowLeft, Instagram, Globe } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { ProductGrid } from '@/components/product/ProductGrid';
import { Button } from '@/components/ui/Button';
import { Reveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ColegaCard, useColegasWithProducts } from '@/components/home/Colegas';
import { NotFound } from '@/pages/Misc';
import { useCatalog } from '@/store/catalog';
import { COLEGAS, projectPath, paragraphsOf } from '@/lib/colegas';
import { cn } from '@/lib/format';
import { usePageMeta } from '@/lib/seo';

/* Avatar de colega: foto o inicial sobre el color de acento. */
function Avatar({ name, image, className }) {
  return (
    <span className={cn('mj-colega-avatar grid shrink-0 place-items-center overflow-hidden rounded-full bg-accent-soft text-accent', className)}>
      {image ? (
        <img src={image} alt="" className="h-full w-full object-cover" />
      ) : (
        <span className="display font-semibold">{(name || '?').trim()[0]}</span>
      )}
    </span>
  );
}

/* Selector "Todo | Tienda | Colegas" con contadores. */
function Segment({ value, onChange, options }) {
  return (
    <div className="mj-segment inline-flex h-11 items-center gap-1 rounded-(--mj-radius-pill) glass p-1" role="tablist">
      {options.map((o) => (
        <button
          key={o.value}
          role="tab"
          aria-selected={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            'mj-segment__item flex h-9 items-center gap-2 rounded-(--mj-radius-pill) px-4 text-sm font-medium transition-colors',
            value === o.value ? 'mj-segment__item--active bg-surface-2 text-ink' : 'text-ink-faint hover:text-ink'
          )}
        >
          {o.label}
          <span className="text-[11px] tabular-nums opacity-60">{o.count}</span>
        </button>
      ))}
    </div>
  );
}

/* ------------------------------- Proyecto -------------------------------- */
/**
 * Página del proyecto de colegas (/{slug}): presentación, quiénes lo hacen y
 * las piezas, con un selector para ver todo, solo lo de la tienda o solo lo
 * de las colegas. Textos y fotos: plugin MJ Colegas → Ajustes.
 */
export function ProjectPage() {
  const { project, house, labels } = COLEGAS;
  usePageMeta(project.menuLabel || project.name || '');
  const colegas = useColegasWithProducts();
  const products = useCatalog((s) => s.products);
  const [view, setView] = useState('all'); // all | house | colegas

  const houseItems = products.filter((p) => !p.colega);
  const colegaItems = products.filter((p) => !!p.colega);
  const shown = view === 'house' ? houseItems : view === 'colegas' ? colegaItems : products;
  const cover = project.images[0] || null;
  const paragraphs = paragraphsOf(project.description);

  const productsTitle = view === 'house'
    ? house.name
    : view === 'colegas'
      ? `${labels.madeBy} ${labels.plural.toLowerCase()}`
      : project.productsTitle || project.allLabel;

  return (
    <>
      <section className={cn('mj-project__hero relative overflow-hidden', cover && 'mj-project__hero--media')}>
        {cover && (
          <div className="absolute inset-0" aria-hidden="true">
            <img src={cover} alt="" className="h-full w-full object-cover" />
            <div className="mj-project__veil absolute inset-0 bg-black/55" />
            <div className="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-bg to-transparent" />
          </div>
        )}
        <Section className={cn('relative md:py-28', cover && 'text-white')}>
          <Reveal className="max-w-3xl">
            {project.eyebrow && (
              <p className={cn('mj-eyebrow text-xs font-semibold uppercase tracking-[0.25em]', cover ? 'text-white/70' : 'text-accent')}>
                {project.eyebrow}
              </p>
            )}
            <h1 className="mj-project__title display mt-4 text-4xl md:text-6xl">{project.title}</h1>
            {project.subtitle && (
              <p className={cn('mt-5 text-lg leading-relaxed md:text-xl', cover ? 'text-white/80' : 'text-ink-soft')}>
                {project.subtitle}
              </p>
            )}
            {paragraphs.map((p, i) => (
              <p key={i} className={cn('mt-4 leading-relaxed', cover ? 'text-white/75' : 'text-ink-soft')}>{p}</p>
            ))}
            {colegas.length > 0 && (
              <div className={cn('mj-project__stats mt-8 flex gap-8', cover ? 'text-white' : '')}>
                <div>
                  <p className="font-display text-2xl font-semibold tabular-nums">{colegas.length}</p>
                  <p className={cn('text-xs', cover ? 'text-white/60' : 'text-ink-faint')}>
                    {colegas.length === 1 ? labels.singular : labels.plural}
                  </p>
                </div>
                <div>
                  <p className="font-display text-2xl font-semibold tabular-nums">{colegaItems.length}</p>
                  <p className={cn('text-xs', cover ? 'text-white/60' : 'text-ink-faint')}>
                    {colegaItems.length === 1 ? 'pieza' : 'piezas'}
                  </p>
                </div>
              </div>
            )}
          </Reveal>
        </Section>
      </section>

      <Section className="pt-6 md:pt-8">
        <Reveal>
          <Segment
            value={view}
            onChange={setView}
            options={[
              { value: 'all', label: project.allLabel, count: products.length },
              { value: 'house', label: house.name, count: houseItems.length },
              { value: 'colegas', label: labels.plural, count: colegaItems.length },
            ]}
          />
        </Reveal>

        {view !== 'house' && colegas.length > 0 && (
          <div className="mt-12">
            <SectionHeader
              eyebrow={project.makersEyebrow}
              title={project.makersTitle || labels.plural}
            />
            <Stagger className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {colegas.map((c) => (
                <motion.div key={c.slug} variants={staggerItem}>
                  <ColegaCard colega={c} />
                </motion.div>
              ))}
            </Stagger>
          </div>
        )}

        <div className="mt-16">
          <SectionHeader
            eyebrow={project.productsEyebrow || undefined}
            title={productsTitle}
            subtitle={`${shown.length} ${shown.length === 1 ? 'pieza' : 'piezas'}`}
            linkTo={`/tienda${view === 'all' ? '' : `?quien=${view}`}`}
            linkLabel={project.shopLink || 'Ver en la tienda'}
          />
          {shown.length > 0 ? (
            <ProductGrid products={shown.slice(0, 12)} />
          ) : (
            <Reveal className="mj-empty rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
              <p className="display text-2xl">Todavía no hay piezas publicadas</p>
              <Button className="mt-7" to="/tienda">Ver la tienda</Button>
            </Reveal>
          )}
        </div>
      </Section>
    </>
  );
}

/* -------------------------------- Colega --------------------------------- */
/** Página de una colega (/{slug}/{colega}) — o de la tienda misma (/{slug}/{house.slug}). */
export function ColegaPage() {
  const { colega: slug } = useParams();
  const products = useCatalog((s) => s.products);
  const ready = useCatalog((s) => s.ready);
  const { house, labels, project } = COLEGAS;

  const isHouse = slug === house.slug;
  const colega = isHouse ? { ...house, house: true } : COLEGAS.list.find((c) => c.slug === slug) ?? null;
  usePageMeta(colega?.name || '', colega?.description || undefined);

  if (!colega) return ready ? <NotFound /> : null;

  const items = products.filter((p) => (isHouse ? !p.colega : p.colega === slug));
  const paragraphs = paragraphsOf(colega.description);

  return (
    <Section className="mj-colega md:py-16">
      <Reveal>
        <Link to={projectPath()} className="mj-link-arrow inline-flex items-center gap-2 text-sm text-ink-soft transition-colors hover:text-ink">
          <ArrowLeft size={15} />
          {project.backLabel || project.name}
        </Link>
      </Reveal>

      <Reveal className="mj-colega__head mt-8 flex flex-col gap-6 md:flex-row md:items-start md:gap-10">
        <Avatar name={colega.name} image={colega.image} className="size-24 text-3xl md:size-32 md:text-4xl" />
        <div className="max-w-2xl">
          <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.25em] text-accent">
            {isHouse ? project.name : labels.singular}
          </p>
          <h1 className="display mt-3 text-4xl md:text-6xl">{colega.name}</h1>
          {paragraphs.map((p, i) => (
            <p key={i} className="mt-4 text-lg leading-relaxed text-ink-soft">{p}</p>
          ))}
          <div className="mt-6 flex flex-wrap items-center gap-3">
            <span className="text-sm text-ink-faint">
              {items.length} {items.length === 1 ? 'pieza' : 'piezas'}
            </span>
            {colega.instagram && (
              <Button size="sm" variant="secondary" href={colega.instagram} target="_blank" rel="noreferrer">
                <Instagram size={14} /> Instagram
              </Button>
            )}
            {colega.website && (
              <Button size="sm" variant="secondary" href={colega.website} target="_blank" rel="noreferrer">
                <Globe size={14} /> Sitio web
              </Button>
            )}
          </div>
        </div>
      </Reveal>

      <div className="mt-14">
        {items.length > 0 ? (
          <ProductGrid products={items} />
        ) : (
          <Reveal className="mj-empty rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
            <p className="display text-2xl">Todavía no hay piezas publicadas</p>
            <Button className="mt-7" to={projectPath()}>Volver a {project.name}</Button>
          </Reveal>
        )}
      </div>
    </Section>
  );
}
