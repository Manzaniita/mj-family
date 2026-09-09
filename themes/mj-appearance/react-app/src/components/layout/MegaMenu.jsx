import { useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { ArrowRight } from 'lucide-react';
import { useCatalog, buildMenu } from '@/store/catalog';
import { ProductImage } from '@/components/ui/ProductImage';
import { Price } from '@/components/ui/Price';
import { EASE } from '@/lib/format';

const linkBase = 'mj-nav__link rounded-(--mj-radius-chip) px-4 py-3 text-[13px] font-medium transition-colors';

/**
 * ¿El item del menú corresponde a la ubicación actual?
 * "Ofertas" y "Novedades" viven en /tienda con ?filtro=…: son activos solo
 * con ese filtro, y "Tienda" solo sin filtro (antes se marcaban los tres).
 */
function isItemActive(item, pathname, search) {
  const [path, query] = item.href.split('?');
  const current = new URLSearchParams(search).get('filtro');
  if (query) return pathname === path && current === new URLSearchParams(query).get('filtro');
  if (path === '/') return pathname === '/';
  return (pathname === path || pathname.startsWith(`${path}/`)) && !current;
}

/**
 * Nivel 3 — Mega menú declarativo derivado del catálogo real.
 * Items type:'link' navegan directo; type:'category' (categorías de
 * WooCommerce) abren un panel con productos de esa categoría.
 */
export function MegaMenu() {
  const { categories, brands, products } = useCatalog();
  const { pathname, search } = useLocation();
  const menu = useMemo(() => buildMenu({ categories, brands }), [categories, brands]);
  const [openSlug, setOpenSlug] = useState(null);

  const openCategory = categories.find((c) => c.slug === openSlug);
  const panelProducts = openSlug
    ? products
        .filter((p) => p.category === openSlug || (p.categories || []).includes(openSlug))
        .slice(0, 3)
    : [];

  return (
    <nav
      className="mj-nav relative hidden lg:block hairline-b bg-bg/70 backdrop-blur-xl"
      onMouseLeave={() => setOpenSlug(null)}
      aria-label="Navegación principal"
    >
      <div className="mx-auto flex max-w-7xl items-center justify-center gap-1 px-8">
        {menu.map((item) => {
          if (item.type === 'link') {
            const active = isItemActive(item, pathname, search);
            return (
              <Link
                key={item.label}
                to={item.href}
                onMouseEnter={() => setOpenSlug(null)}
                aria-current={active ? 'page' : undefined}
                className={`${linkBase} ${
                  item.highlight
                    ? 'mj-nav__link--highlight text-accent hover:bg-accent-soft'
                    : active
                      ? 'mj-nav__link--active text-ink'
                      : 'text-ink-soft hover:text-ink'
                }`}
              >
                {item.label}
              </Link>
            );
          }
          const categoryActive = pathname === `/categoria/${item.slug}`;
          return (
            <button
              key={item.label}
              onMouseEnter={() => setOpenSlug(item.slug)}
              onClick={() => setOpenSlug(openSlug === item.slug ? null : item.slug)}
              aria-expanded={openSlug === item.slug}
              className={`${linkBase} ${
                openSlug === item.slug || categoryActive ? 'mj-nav__link--active text-ink' : 'text-ink-soft hover:text-ink'
              }`}
            >
              {item.label}
            </button>
          );
        })}
      </div>

      <AnimatePresence>
        {openCategory && (
          <motion.div
            initial={{ opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: 0.3, ease: EASE }}
            className="mj-nav__panel absolute left-0 right-0 top-full z-40 hairline-b border-t border-line bg-bg/95 shadow-soft backdrop-blur-2xl"
          >
            <div className="mx-auto grid max-w-7xl grid-cols-4 gap-8 px-8 py-10">
              <div className="flex flex-col justify-between">
                <div>
                  <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">Categoría</p>
                  <h3 className="display mt-3 text-4xl">{openCategory.name}</h3>
                  <p className="mt-3 text-sm text-ink-soft">{openCategory.description}</p>
                </div>
                <Link
                  to={`/categoria/${openCategory.slug}`}
                  onClick={() => setOpenSlug(null)}
                  className="mj-link-arrow group mt-6 inline-flex items-center gap-2 text-sm font-medium text-accent"
                >
                  Explorar todo
                  <ArrowRight size={15} className="transition-transform duration-300 group-hover:translate-x-1" />
                </Link>
              </div>
              {panelProducts.map((p) => (
                <Link
                  key={p.id}
                  to={`/producto/${p.slug}`}
                  onClick={() => setOpenSlug(null)}
                  className="mj-nav__product group"
                >
                  <ProductImage
                    product={p}
                    className="aspect-4/3 rounded-xl2 transition-transform duration-500 group-hover:scale-[1.02]"
                    imgClassName="transition-transform duration-700 group-hover:scale-105"
                  />
                  <p className="mt-3 text-sm font-medium">{p.name}</p>
                  <Price product={p} size="sm" className="mt-1 text-ink-soft" />
                </Link>
              ))}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </nav>
  );
}
