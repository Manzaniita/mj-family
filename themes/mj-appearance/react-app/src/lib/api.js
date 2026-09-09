/**
 * Capa de consulta sobre el catálogo (real o demo, según useCatalog).
 * Filtrado/orden/búsqueda client-side: instantáneo y sin recargas.
 */
import { useCatalog, whenCatalogReady } from '@/store/catalog';
import { filterByMaker } from '@/lib/colegas';

const inCategory = (p, slug) => p.category === slug || (p.categories || []).includes(slug);

export async function getProducts({
  category, brand, maker, search, minPrice, maxPrice,
  inStock, onlyNew, onlySale, featured, sort = 'relevance', limit,
} = {}) {
  const { products } = await whenCatalogReady();
  let out = [...products];

  if (category) out = out.filter((p) => inCategory(p, category));
  if (brand) out = out.filter((p) => p.brand === brand);
  // "¿Quién lo hizo?" (plugin MJ Colegas): 'house' | 'colegas' | slug de colega.
  if (maker) out = filterByMaker(out, maker);
  if (featured) out = out.filter((p) => p.featured);
  if (onlyNew) out = out.filter((p) => p.is_new);
  if (onlySale) out = out.filter((p) => p.sale_price);
  if (inStock) out = out.filter((p) => p.stock_status === 'instock');
  if (minPrice != null) out = out.filter((p) => effectivePrice(p) >= minPrice);
  if (maxPrice != null) out = out.filter((p) => effectivePrice(p) <= maxPrice);
  if (search) {
    const q = search.toLowerCase();
    out = out.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        (p.sku || '').toLowerCase().includes(q) ||
        (p.brand || '').includes(q) ||
        (p.brandName || '').toLowerCase().includes(q) ||
        (p.colegaName || '').toLowerCase().includes(q) ||
        (p.categories || [p.category]).some((c) => (c || '').includes(q)) ||
        (p.tagline || '').toLowerCase().includes(q)
    );
  }

  // "featured" y "novedades" sin curar en la tienda real: degradar con elegancia
  if (featured && out.length === 0) out = [...products].sort((a, b) => b.rating - a.rating).slice(0, limit ?? 4);
  if (onlyNew && out.length === 0 && !search && !category && !brand) out = products.slice(0, limit ?? 4);

  switch (sort) {
    case 'price-asc': out.sort((a, b) => effectivePrice(a) - effectivePrice(b)); break;
    case 'price-desc': out.sort((a, b) => effectivePrice(b) - effectivePrice(a)); break;
    case 'rating': out.sort((a, b) => b.rating - a.rating); break;
    case 'newest': out.sort((a, b) => (b.is_new ? 1 : 0) - (a.is_new ? 1 : 0)); break;
    default: break;
  }

  if (limit) out = out.slice(0, limit);
  return out;
}

export async function getProduct(slug) {
  const { products } = await whenCatalogReady();
  return products.find((p) => p.slug === slug) ?? null;
}

export async function getRelated(product, limit = 4) {
  const { products } = await whenCatalogReady();
  return products
    .filter((p) => p.id !== product.id && (inCategory(p, product.category) || (p.brand && p.brand === product.brand)))
    .slice(0, limit);
}

export async function getCategories() {
  const { categories } = await whenCatalogReady();
  return categories;
}

export async function getBrands() {
  const { brands } = await whenCatalogReady();
  return brands;
}

export const effectivePrice = (p) => p.sale_price ?? p.price;

export const discountPct = (p) =>
  p.sale_price ? Math.round((1 - p.sale_price / p.regular_price) * 100) : 0;

/** Rango de precios del catálogo cargado (sync; usar cuando ready=true). */
export const priceBounds = () => {
  const { products } = useCatalog.getState();
  if (!products.length) return [0, 5000000];
  const prices = products.map(effectivePrice);
  return [Math.min(...prices), Math.max(...prices)];
};
