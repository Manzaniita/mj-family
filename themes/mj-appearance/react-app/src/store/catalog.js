import { create } from 'zustand';
import * as demo from '@/data/catalog';
import { fetchStoreCatalog } from '@/lib/wc';
import { IS_WP, WP } from '@/lib/config';
import { BRAND_LABEL, t } from '@/lib/copy';
import { COLEGAS, projectPath } from '@/lib/colegas';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { NAV_MENU } from '@/lib/nav';

/**
 * Fuente única de catálogo para toda la app.
 * Dentro de WordPress carga productos/categorías/marcas reales desde la
 * Store API de WooCommerce — si falla o está vacío, la tienda se muestra
 * vacía (NUNCA datos demo dentro de WP). El catálogo demo es solo para
 * desarrollo standalone (`npm run dev`).
 */
export const useCatalog = create((set, get) => ({
  products: [],
  categories: [],
  brands: [],
  ready: false,
  demo: false,
  /*
   * Mensaje cuando la Store API no respondió (red caída, servidor lento).
   * Distinto de "no hay productos": eso es ready sin error. Las pantallas lo
   * muestran con un botón de reintentar, que llama a init(true).
   */
  error: null,

  init: async (retry = false) => {
    if (get().ready && !retry) return;
    if (IS_WP) {
      set({ error: null, ready: retry ? false : get().ready });
      try {
        const { products, categories, brands } = await fetchStoreCatalog();
        if (products.length === 0) console.warn('[mj] WooCommerce no tiene productos publicados.');
        set({ products, categories, brands, ready: true, demo: false, error: null });
        return;
      } catch (e) {
        console.warn('[mj] Store API no disponible.', e);
        set({
          products: [], categories: [], brands: [], ready: true, demo: false,
          error: e?.message || t('ui_catalog_error'),
        });
        return;
      }
    }
    set({
      products: demo.products,
      categories: demo.categories,
      brands: demo.brands,
      ready: true,
      demo: false,
    });
  },
}));

/** Espera a que el catálogo esté cargado (para la capa api). */
export function whenCatalogReady() {
  const state = useCatalog.getState();
  if (state.ready) return Promise.resolve(state);
  return new Promise((resolve) => {
    const unsub = useCatalog.subscribe((s) => {
      if (s.ready) { unsub(); resolve(s); }
    });
  });
}

/**
 * Menú principal.
 *
 * Dentro de WordPress lo arma PHP con lo que el admin configuró en Apariencia →
 * MJ Appearance → Barra de navegación (window.MJ.nav): qué ítems, en qué orden,
 * con qué nombre y a dónde llevan. Acá solo se usa esa lista.
 *
 * El armado automático de abajo es el respaldo para `npm run dev` (sin
 * WordPress) y para una instalación cuyo PHP todavía no manda `nav`: junta los
 * enlaces del tema, las categorías elegidas y lo que aporte cada plugin, que es
 * exactamente lo que PHP propone como configuración por defecto.
 */
export function buildMenu({ categories, brands }) {
  if (NAV_MENU) return NAV_MENU;

  // Categorías elegidas en Apariencia → MJ Appearance (máx. 4, en el orden guardado).
  // Sin selección → automático: las 4 con más productos.
  const chosen = Array.isArray(WP?.menuCategories) ? WP.menuCategories : [];
  const menuCats = chosen.length
    ? chosen.map((slug) => categories.find((c) => c.slug === slug)).filter(Boolean)
    : categories.slice(0, 4);

  // "Nosotros" en el menú solo si el admin (o la skin) le puso texto.
  const aboutLabel = t('nav_about').trim();

  return [
    { type: 'link', label: t('nav_home'), href: '/' },
    { type: 'link', label: t('nav_shop'), href: '/tienda' },
    ...menuCats.slice(0, 4).map((c) => ({ type: 'category', label: c.name, slug: c.slug })),
    { type: 'link', label: t('nav_sale'), href: '/tienda?filtro=ofertas', highlight: true },
    { type: 'link', label: t('nav_new'), href: '/tienda?filtro=novedades' },
    // Servicios y Turnos (plugin MJ Turnos Online): etiquetas y visibilidad configurables en Turnos → Ajustes.
    ...(TURNOS?.menu.servicesInMenu && TURNOS.menu.servicesPageEnabled
      ? [{ type: 'link', label: TURNOS.menu.servicesLabel, href: servicesPath() }]
      : []),
    ...(TURNOS?.menu.showInMenu ? [{ type: 'link', label: TURNOS.menu.label, href: bookingPath() }] : []),
    // Proyecto de colegas (plugin MJ Colegas): nombre y visibilidad configurables en el plugin.
    ...(COLEGAS?.project.showInMenu ? [{ type: 'link', label: COLEGAS.project.menuLabel, href: projectPath() }] : []),
    ...(brands.length ? [{ type: 'link', label: BRAND_LABEL.plural, href: '/marcas' }] : []),
    ...(aboutLabel ? [{ type: 'link', label: aboutLabel, href: '/nosotros' }] : []),
    { type: 'link', label: t('nav_contact'), href: '/contacto' },
  ];
}
