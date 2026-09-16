import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { Search, ArrowUpRight, Loader2, ShoppingBag, FolderOpen, LayoutGrid } from 'lucide-react';
import { getProducts } from '@/lib/api';
import { useCatalog, useCart, useUI } from '@/store';
import { ProductImage } from '@/components/ui/ProductImage';
import { Price } from '@/components/ui/Price';
import { Highlight } from '@/components/ui/Highlight';
import { EASE } from '@/lib/format';

const MAX_PRODUCTS = 5;
const MAX_CATS = 7;

/** "119 en stock" | "En stock" | "Sin stock" según lo que sepamos del producto. */
function stockLabel(p) {
  if (p.stock_status !== 'instock') return { text: 'Sin stock', ok: false };
  if (p.stock_quantity != null) {
    return { text: `${p.stock_quantity} en stock`, ok: true };
  }
  return { text: 'En stock', ok: true };
}

const inCategory = (p, slug) => p.category === slug || (p.categories || []).includes(slug);

/**
 * Buscador central con autocompletado en vivo sobre el catálogo de WooCommerce.
 *
 * Panel en dos columnas: a la izquierda las categorías donde hubo coincidencias
 * de ESTA búsqueda (con la cantidad de resultados encontrados en cada una);
 * al hacer click, la columna derecha se filtra a las coincidencias de esa
 * categoría. A la derecha, los productos con título, categoría, SKU, stock,
 * precio y añadir al carrito, resaltando dónde matcheó la búsqueda.
 * Toda la navegación es SPA (sin recargas). Mientras busca, la lupa se
 * reemplaza por un spinner.
 */
export function SearchBar() {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [selectedCat, setSelectedCat] = useState(null);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const navigate = useNavigate();
  const categories = useCatalog((s) => s.categories);
  const add = useCart((s) => s.add);
  const setCartOpen = useUI((s) => s.setCartOpen);

  const placeholder = categories.length
    ? `Buscar ${categories.slice(0, 2).map((c) => c.name).join(', ')}…`
    : 'Buscar productos…';

  const active = query.trim().length >= 2;

  useEffect(() => {
    setSelectedCat(null); // nueva búsqueda → sin filtro de categoría
    if (!active) { setResults([]); setLoading(false); return; }
    let live = true;
    setLoading(true);
    const t = setTimeout(async () => {
      const r = await getProducts({ search: query });
      if (live) { setResults(r); setLoading(false); }
    }, 180);
    return () => { live = false; clearTimeout(t); };
  }, [query, active]);

  useEffect(() => {
    const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  /* Categorías con la cantidad de resultados de ESTA búsqueda en cada una. */
  const catMatches = useMemo(() => {
    if (!results.length) return [];
    return categories
      .map((c) => ({ ...c, matches: results.filter((p) => inCategory(p, c.slug)).length }))
      .filter((c) => c.matches > 0)
      .sort((a, b) => b.matches - a.matches)
      .slice(0, MAX_CATS);
  }, [results, categories]);

  /* Pool visible: todas las coincidencias, o solo las de la categoría elegida. */
  const pool = useMemo(
    () => (selectedCat ? results.filter((p) => inCategory(p, selectedCat)) : results),
    [results, selectedCat]
  );
  const products = pool.slice(0, MAX_PRODUCTS);

  const close = () => { setOpen(false); setQuery(''); setSelectedCat(null); };

  /** Ir a la página de resultados (SPA, sin recarga), con la categoría elegida. */
  const goToResults = (catSlug = selectedCat) => {
    if (!query.trim()) return;
    const params = new URLSearchParams({ q: query.trim() });
    if (catSlug) params.set('categoria', catSlug);
    setOpen(false);
    navigate(`/buscar?${params.toString()}`);
  };

  const submit = (e) => {
    e.preventDefault();
    goToResults();
  };

  const handleAdd = (e, p) => {
    e.stopPropagation();
    if (p.type === 'variable') {
      close();
      navigate(`/producto/${p.slug}`);
      return;
    }
    add(p);
    setCartOpen(true);
  };

  const categoryName = (p) =>
    categories.find((c) => c.slug === p.category)?.name ?? p.category;

  const showPanel = open && active;

  return (
    <div ref={ref} className="mj-search relative w-full max-w-xl">
      <form onSubmit={submit}>
        <div className="mj-search__field group flex h-11 items-center gap-3 rounded-(--mj-radius-pill) glass px-4 transition-all duration-300 focus-within:border-accent/50 focus-within:shadow-glow">
          {loading ? (
            <Loader2 size={16} className="shrink-0 animate-spin text-accent" aria-label="Buscando…" />
          ) : (
            <Search size={16} className="shrink-0 text-ink-faint transition-colors group-focus-within:text-accent" />
          )}
          <input
            value={query}
            onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
            onFocus={() => setOpen(true)}
            onKeyDown={(e) => { if (e.key === 'Enter') submit(e); }}
            placeholder={placeholder}
            className="w-full bg-transparent text-sm text-ink placeholder:text-ink-faint outline-none"
            aria-label="Buscar productos"
          />
          <kbd className="hidden rounded-md border border-line bg-surface px-1.5 py-0.5 text-[10px] text-ink-faint lg:block">
            Enter
          </kbd>
        </div>
      </form>

      <AnimatePresence>
        {showPanel && (
          <motion.div
            initial={{ opacity: 0, y: 8, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 6, scale: 0.98 }}
            transition={{ duration: 0.25, ease: EASE }}
            className="mj-search__panel absolute left-1/2 top-full z-50 mt-2 w-[min(94vw,50rem)] -translate-x-1/2 overflow-hidden rounded-xl3 border border-line bg-bg-soft/95 shadow-soft backdrop-blur-2xl"
          >
            {results.length > 0 ? (
              <>
                <div className="grid grid-cols-1 sm:grid-cols-[13rem_minmax(0,1fr)]">
                  {/* Categorías con coincidencias de esta búsqueda */}
                  <div className="hidden border-r border-line p-2 sm:block">
                    <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-ink-faint">
                      Categorías
                    </p>
                    <button
                      onClick={() => setSelectedCat(null)}
                      className={`flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left transition-colors ${
                        !selectedCat ? 'bg-accent-soft text-accent' : 'hover:bg-surface'
                      }`}
                    >
                      <LayoutGrid size={14} className="shrink-0 opacity-70" />
                      <span className="min-w-0 flex-1 truncate text-[13px] font-medium">Todas</span>
                      <span className="grid min-w-5 place-items-center rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-bold text-accent">
                        {results.length}
                      </span>
                    </button>
                    {catMatches.map((c) => (
                      <button
                        key={c.slug}
                        onClick={() => setSelectedCat(selectedCat === c.slug ? null : c.slug)}
                        onDoubleClick={() => goToResults(c.slug)}
                        title={`Ver las ${c.matches} coincidencias en ${c.name}`}
                        className={`flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left transition-colors ${
                          selectedCat === c.slug ? 'bg-accent-soft text-accent' : 'hover:bg-surface'
                        }`}
                      >
                        <FolderOpen size={14} className="shrink-0 opacity-70" />
                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                          <Highlight text={c.name} query={query} />
                        </span>
                        <span className="grid min-w-5 place-items-center rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-bold text-accent">
                          {c.matches}
                        </span>
                      </button>
                    ))}
                  </div>

                  {/* Productos (coincidencias, filtradas por la categoría elegida) */}
                  <div className="min-w-0 p-2">
                    <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-ink-faint">
                      {selectedCat
                        ? `${pool.length} en ${catMatches.find((c) => c.slug === selectedCat)?.name ?? 'la categoría'}`
                        : 'Productos'}
                    </p>
                    {products.map((p) => {
                      const stock = stockLabel(p);
                      return (
                        <div
                          key={p.id}
                          role="button"
                          tabIndex={0}
                          onClick={() => { close(); navigate(`/producto/${p.slug}`); }}
                          onKeyDown={(e) => { if (e.key === 'Enter') { close(); navigate(`/producto/${p.slug}`); } }}
                          className="flex w-full min-w-0 cursor-pointer items-center gap-3 rounded-xl px-3 py-2 text-left transition-colors hover:bg-surface"
                        >
                          <ProductImage product={p} className="size-12 shrink-0 rounded-xl" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                              <Highlight text={p.name} query={query} />
                            </p>
                            <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-ink-faint">
                              <span className="font-semibold uppercase tracking-wide text-accent/90">
                                <Highlight text={categoryName(p)} query={query} />
                              </span>
                              {p.sku && (
                                <span>SKU: <Highlight text={p.sku} query={query} /></span>
                              )}
                              <span className={stock.ok ? 'text-success' : 'text-danger'}>
                                • {stock.text}
                              </span>
                            </p>
                          </div>
                          <Price product={p} size="sm" className="shrink-0" />
                          <motion.button
                            whileHover={stock.ok ? { scale: 1.1 } : undefined}
                            whileTap={stock.ok ? { scale: 0.9 } : undefined}
                            onClick={(e) => handleAdd(e, p)}
                            disabled={!stock.ok}
                            aria-label={p.type === 'variable' ? 'Elegir opciones' : 'Añadir al carrito'}
                            title={p.type === 'variable' ? 'Elegir opciones' : 'Añadir al carrito'}
                            className={`grid size-9 shrink-0 place-items-center rounded-full transition-all ${
                              stock.ok
                                ? 'mj-solid bg-accent text-white shadow-glow'
                                : 'cursor-not-allowed bg-surface text-ink-faint'
                            }`}
                          >
                            <ShoppingBag size={15} />
                          </motion.button>
                        </div>
                      );
                    })}
                    {pool.length === 0 && (
                      <p className="px-3 py-4 text-sm text-ink-faint">Sin coincidencias en esta categoría.</p>
                    )}
                  </div>
                </div>

                <button
                  onClick={() => goToResults()}
                  className="flex w-full items-center justify-between px-4 py-3 text-xs font-medium text-accent hairline-t transition-colors hover:bg-surface"
                >
                  Ver {pool.length === 1 ? 'el único resultado' : `los ${pool.length} resultados`} para “{query}”
                  {selectedCat && ` en ${catMatches.find((c) => c.slug === selectedCat)?.name ?? ''}`}
                  <ArrowUpRight size={14} />
                </button>
              </>
            ) : (
              <p className="px-4 py-6 text-center text-sm text-ink-faint">
                {loading ? 'Buscando…' : `Sin resultados para “${query}”`}
              </p>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
