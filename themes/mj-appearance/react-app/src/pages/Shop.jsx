import { useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { LayoutGrid, List, SlidersHorizontal, X } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { ProductGrid } from '@/components/product/ProductGrid';
import { ProductImage } from '@/components/ui/ProductImage';
import { Price } from '@/components/ui/Price';
import { Rating } from '@/components/ui/Rating';
import { Highlight } from '@/components/ui/Highlight';
import { Button } from '@/components/ui/Button';
import { Reveal } from '@/components/motion/Reveal';
import { getProducts, priceBounds } from '@/lib/api';
import { useCatalog } from '@/store/catalog';
import { formatPrice, cn, EASE } from '@/lib/format';
import { REVIEWS_ENABLED } from '@/lib/config';
import { t, BRAND_LABEL } from '@/lib/copy';
import { COLEGAS, isValidMaker, makerLabel } from '@/lib/colegas';
import { usePageMeta } from '@/lib/seo';
import { LoadError } from '@/components/ui/LoadError';

const sortOptions = [
  ['relevance', 'Relevancia'],
  ['price-asc', 'Menor precio'],
  ['price-desc', 'Mayor precio'],
  ['rating', 'Mejor valorados'],
  ['newest', 'Novedades'],
];

function FilterGroup({ title, children }) {
  return (
    <div className="mj-filter-group border-b border-line pb-6">
      <p className="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-faint">{title}</p>
      {children}
    </div>
  );
}

function Chip({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'mj-chip rounded-(--mj-radius-chip) border px-3.5 py-1.5 text-[13px] font-medium transition-all duration-200',
        active
          ? 'mj-chip--active border-accent bg-accent text-white'
          : 'border-line text-ink-soft hover:border-line-strong hover:text-ink'
      )}
    >
      {children}
    </button>
  );
}

/** Tienda con filtros sin recarga, vista grid/lista, ordenamiento. */
export default function Shop({ mode }) {
  const params = useParams();
  const [searchParams] = useSearchParams();
  const { categories, brands, products, ready } = useCatalog();
  const bounds = useMemo(() => priceBounds(), [ready]);

  const lockedCategory = mode === 'category' ? params.slug : null;
  const lockedBrand = mode === 'brand' ? params.slug : null;
  const searchQuery = mode === 'search' ? searchParams.get('q') ?? '' : '';
  // Categoría elegida en el panel del buscador (/buscar?q=…&categoria=slug).
  const searchCategory = mode === 'search' ? searchParams.get('categoria') : null;
  const preset = searchParams.get('filtro');
  // "¿Quién lo hizo?" preseleccionado desde el proyecto de colegas (/tienda?quien=house|colegas|slug).
  const presetMaker = isValidMaker(searchParams.get('quien')) ? searchParams.get('quien') : null;

  const [filters, setFilters] = useState({
    category: searchCategory, brand: null, maker: presetMaker, maxPrice: null,
    inStock: false, onlyNew: preset === 'novedades', onlySale: preset === 'ofertas',
  });
  const [sort, setSort] = useState('relevance');
  const [view, setView] = useState('grid');
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [mobileFilters, setMobileFilters] = useState(false);
  const catalogError = useCatalog((s) => s.error);

  // Título de la pestaña según la vista: categoría, marca, búsqueda, ofertas, novedades o la tienda.
  const lockedCat = lockedCategory ? categories.find((c) => c.slug === lockedCategory) : null;
  const lockedBr = lockedBrand ? brands.find((b) => b.slug === lockedBrand) : null;
  const pageTitle = lockedCat
    ? lockedCat.name
    : lockedBr
      ? lockedBr.name
      : mode === 'search'
        ? `${t('seo_title_search')}: ${searchQuery}`
        : preset === 'ofertas'
          ? t('shop_sale_title')
          : preset === 'novedades'
            ? t('shop_new_title')
            : t('seo_title_shop') || t('shop_title');
  usePageMeta(pageTitle, lockedCat?.description || t('seo_desc_shop'));

  useEffect(() => {
    setFilters((f) => ({ ...f, onlyNew: preset === 'novedades', onlySale: preset === 'ofertas' }));
  }, [preset]);

  useEffect(() => {
    setFilters((f) => ({ ...f, maker: presetMaker }));
  }, [presetMaker]);

  // Nueva búsqueda desde el navbar (misma página, sin recarga): aplicar la
  // categoría que viene en la URL y descartar el filtro viejo.
  useEffect(() => {
    if (mode !== 'search') return;
    setFilters((f) => ({ ...f, category: searchCategory }));
  }, [mode, searchQuery, searchCategory]);

  useEffect(() => {
    setLoading(true);
    getProducts({
      ...filters,
      category: lockedCategory ?? filters.category,
      brand: lockedBrand ?? filters.brand,
      search: searchQuery || undefined,
      sort,
    }).then((r) => { setItems(r); setLoading(false); });
  }, [filters, sort, lockedCategory, lockedBrand, searchQuery]);

  // Colegas con productos publicados (para los chips del filtro).
  const colegasWithProducts = useMemo(
    () => (COLEGAS ? COLEGAS.list.filter((c) => products.some((p) => p.colega === c.slug)) : []),
    [products]
  );

  const title = useMemo(() => {
    if (mode === 'category') return categories.find((c) => c.slug === params.slug)?.name ?? 'Categoría';
    if (mode === 'brand') return brands.find((b) => b.slug === params.slug)?.name ?? BRAND_LABEL.singular;
    if (mode === 'search') {
      const catName = filters.category
        ? categories.find((c) => c.slug === filters.category)?.name
        : null;
      return catName
        ? `“${searchQuery}” en ${catName}`
        : `Resultados para “${searchQuery}”`;
    }
    if (preset === 'ofertas') return t('shop_sale_title');
    if (preset === 'novedades') return t('shop_new_title');
    // Con el filtro "¿quién lo hizo?" activo, el título es ese nombre.
    if (filters.maker) return makerLabel(filters.maker) || t('shop_title');
    return t('shop_title');
  }, [mode, params.slug, searchQuery, preset, filters.category, filters.maker, categories, brands]);

  const set = (patch) => setFilters((f) => ({ ...f, ...patch }));

  const filterPanel = (
    <div className="space-y-6">
      {!lockedCategory && (
        <FilterGroup title="Categoría">
          <div className="flex flex-wrap gap-2">
            <Chip active={!filters.category} onClick={() => set({ category: null })}>Todas</Chip>
            {categories.map((c) => (
              <Chip key={c.slug} active={filters.category === c.slug} onClick={() => set({ category: c.slug })}>
                {c.name}
              </Chip>
            ))}
          </div>
        </FilterGroup>
      )}
      {/* ¿Quién lo hizo? (plugin MJ Colegas) — la tienda, todas las colegas o una en particular. */}
      {COLEGAS?.showFilter && colegasWithProducts.length > 0 && (
        <FilterGroup title={COLEGAS.labels.plural}>
          <div className="flex flex-wrap gap-2">
            <Chip active={!filters.maker} onClick={() => set({ maker: null })}>{COLEGAS.project.allLabel}</Chip>
            <Chip active={filters.maker === 'house'} onClick={() => set({ maker: 'house' })}>{COLEGAS.house.name}</Chip>
            <Chip active={filters.maker === 'colegas'} onClick={() => set({ maker: 'colegas' })}>{COLEGAS.labels.plural}</Chip>
            {colegasWithProducts.map((c) => (
              <Chip key={c.slug} active={filters.maker === c.slug} onClick={() => set({ maker: c.slug })}>
                {c.name}
              </Chip>
            ))}
          </div>
        </FilterGroup>
      )}
      {!lockedBrand && brands.length > 0 && (
        <FilterGroup title={BRAND_LABEL.singular}>
          <div className="flex flex-wrap gap-2">
            <Chip active={!filters.brand} onClick={() => set({ brand: null })}>Todas</Chip>
            {brands.map((b) => (
              <Chip key={b.slug} active={filters.brand === b.slug} onClick={() => set({ brand: b.slug })}>
                {b.name}
              </Chip>
            ))}
          </div>
        </FilterGroup>
      )}
      <FilterGroup title={`Precio máximo · ${formatPrice(filters.maxPrice ?? bounds[1])}`}>
        <input
          type="range"
          min={bounds[0]}
          max={bounds[1]}
          step={Math.max(1, Math.round((bounds[1] - bounds[0]) / 100))}
          value={filters.maxPrice ?? bounds[1]}
          onChange={(e) => set({ maxPrice: Number(e.target.value) })}
          className="w-full accent-(--mj-accent)"
          aria-label="Precio máximo"
        />
        <div className="mt-1 flex justify-between text-xs text-ink-faint">
          <span>{formatPrice(bounds[0])}</span>
          <span>{formatPrice(bounds[1])}</span>
        </div>
      </FilterGroup>
      <FilterGroup title="Disponibilidad">
        <div className="flex flex-wrap gap-2">
          <Chip active={filters.inStock} onClick={() => set({ inStock: !filters.inStock })}>Solo en stock</Chip>
          <Chip active={filters.onlyNew} onClick={() => set({ onlyNew: !filters.onlyNew })}>{t('shop_new_title')}</Chip>
          <Chip active={filters.onlySale} onClick={() => set({ onlySale: !filters.onlySale })}>En oferta</Chip>
        </div>
      </FilterGroup>
    </div>
  );

  return (
    <Section className="mj-shop md:py-16">
      <Reveal className="mj-page-head">
        <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{t('shop_eyebrow')}</p>
        <h1 className="display mt-3 text-4xl md:text-6xl">{title}</h1>
        <p className="mt-3 text-ink-soft">{items.length} producto{items.length !== 1 && 's'}</p>
      </Reveal>

      <div className="mt-10 grid gap-10 lg:grid-cols-[260px_1fr]">
        {/* Filtros desktop */}
        <aside className="hidden lg:block">
          <div className="mj-filters sticky top-40 rounded-xl3 border border-line glass p-6">{filterPanel}</div>
        </aside>

        <div>
          {/* Toolbar */}
          <div className="mj-toolbar mb-6 flex flex-wrap items-center gap-3">
            <button
              onClick={() => setMobileFilters(true)}
              className="flex h-10 items-center gap-2 rounded-(--mj-radius-pill) glass px-4 text-sm font-medium lg:hidden"
            >
              <SlidersHorizontal size={15} /> Filtros
            </button>
            <div className="mj-toolbar__sort flex h-10 items-center gap-1 rounded-(--mj-radius-pill) glass p-1 ml-auto">
              {sortOptions.map(([value, label]) => (
                <button
                  key={value}
                  onClick={() => setSort(value)}
                  className={cn(
                    'hidden rounded-(--mj-radius-pill) px-3.5 py-1.5 text-xs font-medium transition-colors md:block',
                    sort === value ? 'bg-surface-2 text-ink' : 'text-ink-faint hover:text-ink'
                  )}
                >
                  {label}
                </button>
              ))}
              <select
                value={sort}
                onChange={(e) => setSort(e.target.value)}
                className="bg-transparent px-3 text-xs font-medium outline-none md:hidden"
                aria-label="Ordenar"
              >
                {sortOptions.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div className="mj-toolbar__view flex h-10 items-center gap-1 rounded-(--mj-radius-pill) glass p-1">
              {[['grid', LayoutGrid], ['list', List]].map(([v, Icon]) => (
                <button
                  key={v}
                  onClick={() => setView(v)}
                  className={cn(
                    'grid size-8 place-items-center rounded-(--mj-radius-pill) transition-colors',
                    view === v ? 'bg-surface-2 text-ink' : 'text-ink-faint hover:text-ink'
                  )}
                  aria-label={v === 'grid' ? 'Vista grilla' : 'Vista lista'}
                >
                  <Icon size={15} />
                </button>
              ))}
            </div>
          </div>

          {/* Resultados */}
          {catalogError && items.length === 0 ? (
            <LoadError
              title="No pudimos cargar los productos"
              onRetry={() => useCatalog.getState().init(true)}
            />
          ) : loading ? (
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="aspect-4/5 animate-pulse rounded-xl3 bg-surface-2" />
              ))}
            </div>
          ) : items.length === 0 ? (
            <div className="rounded-xl3 border border-line glass p-16 text-center">
              <p className="display text-2xl">{t('shop_empty_title')}</p>
              <p className="mt-2 text-sm text-ink-soft">{t('shop_empty_text')}</p>
              <Button className="mt-6" to="/tienda">Ver toda la tienda</Button>
            </div>
          ) : view === 'grid' ? (
            <ProductGrid products={items} cols={3} highlight={searchQuery || undefined} />
          ) : (
            <div className="space-y-4">
              {items.map((p) => (
                <Reveal key={p.id} y={16}>
                  <Link
                    to={`/producto/${p.slug}`}
                    className="mj-row group flex gap-5 rounded-xl3 border border-line glass p-4 transition-colors hover:border-line-strong"
                  >
                    <ProductImage product={p} className="size-28 shrink-0 rounded-xl2 md:size-36" />
                    <div className="flex min-w-0 flex-1 flex-col justify-center gap-1.5">
                      <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-faint">
                        {p.colegaName ?? p.brandName ?? p.brand ?? p.category}
                      </p>
                      <h3 className="font-display text-lg font-semibold tracking-tight md:text-xl">
                        <Highlight text={p.name} query={searchQuery || undefined} />
                      </h3>
                      <p className="hidden text-sm text-ink-soft sm:block">{p.tagline}</p>
                      {REVIEWS_ENABLED && <Rating value={p.rating} count={p.rating_count} />}
                    </div>
                    <div className="flex flex-col items-end justify-center gap-2">
                      <Price product={p} />
                    </div>
                  </Link>
                </Reveal>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Filtros mobile */}
      <AnimatePresence>
        {mobileFilters && (
          <div className="fixed inset-0 z-90 lg:hidden">
            <motion.div
              className="absolute inset-0 bg-(--mj-overlay) backdrop-blur-sm"
              initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              onClick={() => setMobileFilters(false)}
            />
            <motion.div
              initial={{ y: '100%' }} animate={{ y: 0 }} exit={{ y: '100%' }}
              transition={{ duration: 0.4, ease: EASE }}
              className="mj-sheet absolute inset-x-0 bottom-0 max-h-[80vh] overflow-y-auto rounded-t-(--mj-radius-2xl) border-t border-line bg-bg-soft p-6 pb-10"
            >
              <div className="mb-6 flex items-center justify-between">
                <h2 className="font-display text-lg font-semibold">Filtros</h2>
                <button onClick={() => setMobileFilters(false)} className="grid size-9 place-items-center rounded-(--mj-radius-pill) glass" aria-label="Cerrar filtros">
                  <X size={16} />
                </button>
              </div>
              {filterPanel}
              <Button className="mt-6 w-full" onClick={() => setMobileFilters(false)}>
                Ver {items.length} resultado{items.length !== 1 && 's'}
              </Button>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </Section>
  );
}
