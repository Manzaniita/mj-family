/**
 * Plugin MJ Colegas (window.MJ.colegas): productos hechos por terceros
 * ("colegas") que venden dentro de la tienda con una comisión que administra
 * el plugin. La SPA solo muestra: chip "Hecho por" en tarjetas y ficha,
 * filtro "¿quién lo hizo?" en la tienda, sección en la portada, página del
 * proyecto (/{slug}) y una página por colega (/{slug}/{colega}).
 *
 * Sin plugin → COLEGAS = null y nada de esto se renderiza.
 * Todas las palabras (Colega/Colegas, "Hecho por", nombre del proyecto…)
 * vienen del plugin: acá no hay textos fijos.
 */
import { WP, SITE } from './config';

function normalize(raw) {
  if (!raw || raw.enabled === false || !Array.isArray(raw.list)) return null;
  const labels = raw.labels || {};
  const house = raw.house || {};
  const project = raw.project || {};
  const home = raw.home || {};
  const plural = labels.plural || 'Colegas';
  const name = project.name || plural;
  return {
    labels: {
      singular: labels.singular || 'Colega',
      plural,
      madeBy: labels.madeBy || 'Hecho por',
    },
    /* La tienda misma (productos sin colega). */
    house: {
      name: house.name || SITE.name,
      slug: house.slug || 'tienda',
      description: house.description || '',
      image: house.image || null,
      instagram: house.instagram || '',
    },
    project: {
      name,
      slug: project.slug || 'colegas',
      eyebrow: project.eyebrow || '',
      title: project.title || name,
      subtitle: project.subtitle || '',
      description: project.description || '',
      images: Array.isArray(project.images) ? project.images.filter(Boolean) : [],
      menuLabel: project.menuLabel || name,
      showInMenu: project.showInMenu !== false,
      allLabel: project.allLabel || 'Todo',
      makersEyebrow: project.makersEyebrow || plural,
      makersTitle: project.makersTitle || '',
      productsEyebrow: project.productsEyebrow || '',
      productsTitle: project.productsTitle || '',
      shopLink: project.shopLink || '',
      backLabel: project.backLabel || '',
    },
    home: {
      enabled: home.enabled !== false,
      eyebrow: home.eyebrow || name,
      title: home.title || '',
      subtitle: home.subtitle || '',
      link: home.link || '',
    },
    showBadge: raw.showBadge !== false,
    showHouseBadge: !!raw.showHouseBadge,
    showFilter: raw.showFilter !== false,
    showProductBlock: raw.showProductBlock !== false,
    list: raw.list
      .filter((c) => c && c.slug && c.name)
      .map((c) => ({
        id: c.id,
        name: c.name,
        slug: c.slug,
        description: c.description || '',
        image: c.image || null,
        instagram: c.instagram || '',
        website: c.website || '',
      })),
  };
}

export const COLEGAS = normalize(WP?.colegas);

export const projectPath = () => (COLEGAS ? `/${COLEGAS.project.slug}` : '/');
export const colegaPath = (slug) => `${projectPath()}/${slug}`;

/** Colega del producto, o null si es de la tienda (o no hay plugin). */
export const colegaOf = (product) =>
  COLEGAS && product?.colega ? COLEGAS.list.find((c) => c.slug === product.colega) ?? null : null;

/** Quién hizo el producto: el colega, o la tienda misma ({ ...house, house: true }). */
export function makerOf(product) {
  if (!COLEGAS) return null;
  const colega = colegaOf(product);
  if (colega) return colega;
  return { ...COLEGAS.house, house: true };
}

/** Valor válido para el filtro "¿quién lo hizo?": 'house' | 'colegas' | slug de colega. */
export function isValidMaker(value) {
  if (!COLEGAS || !value) return false;
  return value === 'house' || value === 'colegas' || COLEGAS.list.some((c) => c.slug === value);
}

/** Nombre legible del valor del filtro (título de la tienda cuando está activo). */
export function makerLabel(value) {
  if (!COLEGAS || !value) return '';
  if (value === 'house') return COLEGAS.house.name;
  if (value === 'colegas') return COLEGAS.labels.plural;
  return COLEGAS.list.find((c) => c.slug === value)?.name ?? '';
}

/** Aplica el filtro "¿quién lo hizo?" a una lista de productos. */
export function filterByMaker(products, maker) {
  if (!COLEGAS || !maker) return products;
  if (maker === 'house') return products.filter((p) => !p.colega);
  if (maker === 'colegas') return products.filter((p) => !!p.colega);
  return products.filter((p) => p.colega === maker);
}

/** Separa un texto largo en párrafos (líneas en blanco). */
export const paragraphsOf = (text) =>
  String(text || '')
    .split(/\n\s*\n/)
    .map((s) => s.trim())
    .filter(Boolean);
