import { useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { ArrowRight } from 'lucide-react';
import { useCatalog, buildMenu } from '@/store/catalog';
import { isExternalHref } from '@/lib/nav';
import { ProductImage } from '@/components/ui/ProductImage';
import { Price } from '@/components/ui/Price';
import { EASE } from '@/lib/format';
import { t } from '@/lib/copy';

const linkBase = 'mj-nav__link rounded-(--mj-radius-chip) px-4 py-3 text-[13px] font-medium transition-colors';

/**
 * ¿El item del menú corresponde a la ubicación actual?
 * "Ofertas" y "Novedades" viven en /tienda con ?filtro=…: son activos solo
 * con ese filtro, y "Tienda" solo sin filtro (antes se marcaban los tres).
 */
function isItemActive(item, pathname, search) {
  if (!item.href || isExternalHref(item.href)) return false;
  const [path, query] = item.href.split('?');
  const current = new URLSearchParams(search).get('filtro');
  if (query) return pathname === path && current === new URLSearchParams(query).get('filtro');
  if (path === '/') return pathname === '/';
  return (pathname === path || pathname.startsWith(`${path}/`)) && !current;
}

function parseNavLinks(text) {
  if (!text || typeof text !== 'string') return [];
  const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  return lines.map((line, idx) => {
    const parts = line.split('|').map((s) => s.trim());
    const label = parts[0] || '';
    const href = parts[1] || '#';
    const target = parts[2] === '_blank' || parts[2] === 'blank' ? '_blank' : undefined;
    return {
      key: `custom-${idx}-${label}`,
      label,
      href,
      target,
      type: 'link'
    };
  });
}

/**
 * Nivel 3 — Mega menú declarativo derivado del catálogo real o personalizado.
 * Items type:'link' navegan directo; type:'category' (categorías de
 * WooCommerce) abren un panel con productos de esa categoría.
 */
export function MegaMenu({
  inline = false,
  className = '',
  source = 'auto',
  customLinks = '',
  showCategories = true,
  showBrands = false,
  fontSize = '',
  fontWeight = '',
  textTransform = '',
  letterSpacing = '',
  textColor = '',
  hoverColor = '',
  bgHoverColor = '',
  activeColor = '',
  dropdownBg = '',
  dropdownTextColor = '',
  dropdownHoverColor = '',
  itemGap = '',
  itemPadding = '',
  justify = '',
  borderRadius = ''
} = {}) {
  const { categories, brands, products } = useCatalog();
  const { pathname, search } = useLocation();
  const [openSlug, setOpenSlug] = useState(null);
  const [hoveredKey, setHoveredKey] = useState(null);

  const customItems = useMemo(() => {
    if (source === 'custom' && customLinks && customLinks.trim()) {
      return parseNavLinks(customLinks);
    }
    return null;
  }, [source, customLinks]);

  const menu = useMemo(() => {
    if (customItems && customItems.length > 0) {
      return customItems;
    }
    return buildMenu({
      categories: showCategories !== false ? categories : [],
      brands: showBrands ? brands : []
    });
  }, [customItems, categories, brands, showCategories, showBrands]);

  const openCategory = categories.find((c) => c.slug === openSlug);
  const panelProducts = openSlug
    ? products
        .filter((p) => p.category === openSlug || (p.categories || []).includes(openSlug))
        .slice(0, 3)
    : [];

  const getLinkStyle = (active, isHovered, isHighlight) => {
    const s = {};
    if (fontSize) s.fontSize = fontSize;
    if (fontWeight) s.fontWeight = fontWeight;
    if (textTransform && textTransform !== 'none') s.textTransform = textTransform;
    if (letterSpacing && letterSpacing !== 'normal') s.letterSpacing = letterSpacing;
    if (itemPadding) s.padding = itemPadding;
    if (borderRadius) s.borderRadius = borderRadius;

    if (isHovered) {
      if (hoverColor) s.color = hoverColor;
      if (bgHoverColor) s.backgroundColor = bgHoverColor;
    } else if (active) {
      if (activeColor) s.color = activeColor;
    } else if (isHighlight) {
      // Highlight default accent
    } else if (textColor) {
      s.color = textColor;
    }
    return s;
  };

  const containerStyle = {
    justifyContent: justify || 'center',
    gap: itemGap || '0.25rem',
  };

  const panelStyle = {
    backgroundColor: dropdownBg || undefined,
    color: dropdownTextColor || undefined,
  };

  return (
    <nav
      className={inline ? `mj-nav mj-nav--inline relative hidden lg:flex items-center gap-1 ${className}` : `mj-nav relative hidden lg:block hairline-b bg-bg/70 backdrop-blur-xl ${className}`}
      onMouseLeave={() => { setOpenSlug(null); setHoveredKey(null); }}
      aria-label={t('nav_main_label')}
    >
      <div
        className={inline ? "flex items-center flex-wrap" : "mx-auto flex max-w-7xl items-center px-8 flex-wrap"}
        style={containerStyle}
      >
        {menu.map((item) => {
          const itemKey = item.key || item.label;
          const isHovered = hoveredKey === itemKey;
          const hasPanel = item.type === 'category' && categories.some((c) => c.slug === item.slug);

          if (!hasPanel) {
            const href = item.href || `/categoria/${item.slug}`;
            const active = isItemActive({ ...item, href }, pathname, search);
            const isHighlight = !!item.highlight;
            const style = getLinkStyle(active, isHovered, isHighlight);

            const className = `${linkBase} ${
              isHighlight && !hoverColor && !textColor
                ? 'mj-nav__link--highlight text-accent hover:bg-accent-soft'
                : active && !activeColor && !textColor
                  ? 'mj-nav__link--active text-ink'
                  : !textColor
                    ? 'text-ink-soft hover:text-ink'
                    : ''
            }`;

            if (isExternalHref(href)) {
              return (
                <a
                  key={itemKey}
                  href={href}
                  target={item.target === '_blank' ? '_blank' : undefined}
                  rel={item.target === '_blank' ? 'noreferrer' : undefined}
                  onMouseEnter={() => { setOpenSlug(null); setHoveredKey(itemKey); }}
                  onMouseLeave={() => setHoveredKey(null)}
                  className={className}
                  style={style}
                >
                  {item.label}
                </a>
              );
            }

            return (
              <Link
                key={itemKey}
                to={href}
                target={item.target === '_blank' ? '_blank' : undefined}
                onMouseEnter={() => { setOpenSlug(null); setHoveredKey(itemKey); }}
                onMouseLeave={() => setHoveredKey(null)}
                aria-current={active ? 'page' : undefined}
                className={className}
                style={style}
              >
                {item.label}
              </Link>
            );
          }

          const categoryActive = pathname === `/categoria/${item.slug}`;
          const isPanelActive = openSlug === item.slug || categoryActive;
          const style = getLinkStyle(isPanelActive, isHovered, false);

          return (
            <button
              key={itemKey}
              onMouseEnter={() => { setOpenSlug(item.slug); setHoveredKey(itemKey); }}
              onMouseLeave={() => setHoveredKey(null)}
              onClick={() => setOpenSlug(openSlug === item.slug ? null : item.slug)}
              aria-expanded={openSlug === item.slug}
              className={`${linkBase} ${
                isPanelActive && !activeColor && !textColor ? 'mj-nav__link--active text-ink' : !textColor ? 'text-ink-soft hover:text-ink' : ''
              }`}
              style={style}
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
            style={panelStyle}
          >
            <div className="mx-auto grid max-w-7xl grid-cols-4 gap-8 px-8 py-10">
              <div className="flex flex-col justify-between">
                <div>
                  <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent" style={{ color: dropdownHoverColor || undefined }}>{t('nav_mega_eyebrow')}</p>
                  <h3 className="display mt-3 text-4xl" style={{ color: dropdownTextColor || undefined }}>{openCategory.name}</h3>
                  <p className="mt-3 text-sm text-ink-soft" style={{ color: dropdownTextColor ? dropdownTextColor : undefined, opacity: dropdownTextColor ? 0.8 : undefined }}>{openCategory.description}</p>
                </div>
                <Link
                  to={`/categoria/${openCategory.slug}`}
                  onClick={() => setOpenSlug(null)}
                  className="mj-link-arrow group mt-6 inline-flex items-center gap-2 text-sm font-medium text-accent"
                  style={{ color: dropdownHoverColor || undefined }}
                >
                  {t('nav_mega_link')}
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
                  <p className="mt-3 text-sm font-medium" style={{ color: dropdownTextColor || undefined }}>{p.name}</p>
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
