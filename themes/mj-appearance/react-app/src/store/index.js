import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { effectivePrice } from '@/lib/api';
import { IS_WP } from '@/lib/config';
import * as wc from '@/lib/wc';
import { useCatalog } from './catalog';
import { applyTheme, currentTheme, transitionTheme } from '@/lib/theme';
import { usePaymentMethod, selectedMethodId } from '@/lib/payments';

export { useCatalog } from './catalog';
export { useAuth, finishAuth } from './auth';

/* ------------------------------- Carrito --------------------------------
 * Dentro de WordPress el carrito vive en la sesión de WooCommerce
 * (Store API) — mismo carrito que ve el servidor al hacer checkout.
 * Standalone funciona 100% local (persistido).
 *
 * Todas las mutaciones al servidor pasan por una cola serializada
 * (enqueue): los clicks rápidos esperan su turno y no disparan
 * requests paralelos que pisen el carrito. Si una mutación falla,
 * se hace rollback con init() y se muestra el mensaje del servidor.
 * ------------------------------------------------------------------------ */

const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
const toAmount = (value, minorUnit) => Number(value || 0) / 10 ** (minorUnit ?? 2);

function mapServerCart(cart) {
  const catalog = useCatalog.getState().products;
  return (cart?.items ?? []).map((it) => ({
    key: it.key,
    qty: it.quantity,
    // Límites de cantidad que impone WooCommerce (stock, máximos por compra)
    qtyLimits: {
      min: it.quantity_limits?.minimum ?? 1,
      max: it.quantity_limits?.maximum ?? 9999,
    },
    product: catalog.find((p) => p.id === it.id) ?? wc.mapCartItemProduct(it),
  }));
}

/** Métodos de envío y costo derivados del carrito del servidor. */
function mapServerShipping(cart) {
  if (!cart) return { rates: [], selected: null, cost: null };
  const u = cart.totals?.currency_minor_unit ?? 2;
  const rates = (cart.shipping_rates || []).flatMap((p) =>
    (p.shipping_rates || []).map((r) => ({
      rate_id: r.rate_id,
      name: stripHtml(r.name),
      selected: !!r.selected,
      cost: toAmount(r.price, r.currency_minor_unit ?? u),
    }))
  );
  return {
    rates,
    selected: rates.find((r) => r.selected)?.rate_id ?? null,
    cost: cart.totals?.total_shipping != null ? toAmount(cart.totals.total_shipping, u) : null,
  };
}

/** Fees, totales del servidor y datos del plugin MJ Medios de Pago (extensions.mjpm). */
function mapServerExtras(cart) {
  const u = cart?.totals?.currency_minor_unit ?? 2;
  const fees = (cart?.fees || []).map((f) => ({
    id: f.id,
    name: stripHtml(f.name),
    amount: toAmount(f.totals?.total, u),
  }));
  const t = cart?.totals;
  const totals = t
    ? {
        subtotal: toAmount(t.total_items, u),
        discount: toAmount(t.total_discount, u),
        fees: toAmount(t.total_fees, u),
        shipping: toAmount(t.total_shipping, u),
        total: toAmount(t.total_price, u),
      }
    : null;
  const mjpm = cart?.extensions?.mjpm;
  const payment = mjpm && mjpm.method
    ? {
        method: mjpm.method,
        kind: mjpm.kind,
        mode: mjpm.mode,
        value: Number(mjpm.value) || 0,
        amount: Number(mjpm.amount) || 0,
        label: mjpm.label || '',
        currency: mjpm.currency || null,
        usdTotal: mjpm.usd_total != null ? Number(mjpm.usd_total) : null,
      }
    : null;
  return { fees, totals, payment };
}

/** Estado parcial que se aplica con TODO response de cart de la Store API. */
const serverCartState = (cart) => ({
  items: mapServerCart(cart),
  shipping: mapServerShipping(cart),
  server: mapServerExtras(cart),
});

// Cola de mutaciones del carrito: nunca rechaza para no frenar la cadena.
let _q = Promise.resolve();
// Timer del auto-limpieza del error (para no solapar timeouts).
let _errTimer = null;

export const useCart = create(
  persist(
    (set, get) => {
      let pending = 0;

      /** Encola una mutación del servidor y mantiene `syncing` mientras haya trabajo. */
      const enqueue = (fn) => {
        pending += 1;
        set({ syncing: true });
        const p = _q.then(() => fn());
        _q = p.catch(() => {}); // la cola sigue aunque un request falle
        const done = () => {
          pending -= 1;
          if (pending === 0) set({ syncing: false });
        };
        p.then(done, done);
        return p;
      };

      /** Rollback + mensaje visible cuando el servidor rechaza una mutación. */
      const fail = (e) => {
        console.warn('[mj] Mutación de carrito rechazada.', e);
        get().setError(e.data?.message || e.message);
        get().init();
      };

      return {
        items: [], // { product, qty, key?, qtyLimits }
        shipping: { rates: [], selected: null, cost: null },
        // Lo que sabe el servidor y la SPA no calcula: fees, totales y ajuste por medio de pago.
        server: { fees: [], totals: null, payment: null },
        error: null,
        syncing: false,

        /** Hidrata desde la sesión de WooCommerce (llamado al montar la app). */
        init: async () => {
          if (!IS_WP) return;
          try {
            const cart = await wc.getCart();
            set(serverCartState(cart));
            // El servidor debe usar el mismo medio de pago que el visitante eligió para ver precios.
            const wanted = selectedMethodId();
            if (wanted && cart?.extensions?.mjpm?.method !== wanted) get().syncPaymentMethod(wanted);
          } catch (e) {
            console.warn('[mj] No se pudo leer el carrito del servidor.', e);
          }
        },

        /**
         * Avisa al servidor el medio de pago elegido (plugin MJ Medios de Pago):
         * recalcula la línea de ajuste y devuelve el carrito actualizado.
         */
        syncPaymentMethod: (id = selectedMethodId()) => {
          if (!IS_WP || !id) return;
          enqueue(() => wc.updateCartExtension('mjpm', { payment_method: id }))
            .then((cart) => set(serverCartState(cart)))
            .catch((e) => console.warn('[mj] No se pudo sincronizar el medio de pago.', e));
        },

        /** Muestra un error del carrito; se auto-limpia a los 4.5s. */
        setError: (msg) => {
          if (_errTimer) clearTimeout(_errTimer);
          if (!msg) return set({ error: null });
          set({ error: String(msg) });
          _errTimer = setTimeout(() => set({ error: null }), 4500);
        },

        /**
         * variation: { id, price?, regular_price?, sale_price? } de la variación
         * elegida (productos variables). Con precio, el subtotal optimista
         * refleja la variación real y no el precio del padre.
         */
        add: (product, qty = 1, variation = null) => {
          const variationId = variation?.id ?? null;
          const lineProduct = variation?.price != null
            ? {
                ...product,
                price: variation.price,
                regular_price: variation.regular_price ?? product.regular_price,
                sale_price: variation.sale_price ?? null,
              }
            : product;
          set((s) => {
            // Misma clave producto+variación: dos variaciones distintas NO se fusionan.
            const existing = s.items.find(
              (i) => i.product.id === product.id && (i.variationId ?? null) === variationId
            );
            if (existing) {
              return {
                items: s.items.map((i) =>
                  i === existing ? { ...i, qty: i.qty + qty } : i
                ),
              };
            }
            return {
              items: [...s.items, { product: lineProduct, qty, variationId, qtyLimits: { min: 1, max: 9999 } }],
            };
          });
          if (IS_WP) {
            enqueue(() => wc.addCartItem(variationId ?? product.id, qty))
              .then((cart) => set(serverCartState(cart)))
              .catch(fail);
          }
        },

        setQty: (id, qty) => {
          const item = get().items.find((i) => i.product.id === id);
          if (!item) return;
          if (qty <= 0) return get().remove(id);

          // Clamp al máximo permitido (stock). Si no cambia nada, no se llama al server.
          const max = item.qtyLimits?.max ?? 9999;
          let next = qty;
          if (max >= 1 && qty > max) {
            next = max;
            get().setError(
              max === 1 ? 'Solo hay 1 unidad disponible' : `Solo hay ${max} unidades disponibles`
            );
          }
          if (next === item.qty) return;

          set((s) => ({
            items: s.items.map((i) => (i.product.id === id ? { ...i, qty: next } : i)),
          }));
          if (IS_WP && item.key) {
            enqueue(() => wc.updateCartItem(item.key, next))
              .then((cart) => set(serverCartState(cart)))
              .catch(fail);
          }
        },

        remove: (id) => {
          const item = get().items.find((i) => i.product.id === id);
          set((s) => ({ items: s.items.filter((i) => i.product.id !== id) }));
          if (IS_WP && item?.key) {
            enqueue(() => wc.removeCartItem(item.key))
              .then((cart) => set(serverCartState(cart)))
              .catch(fail);
          }
        },

        clear: () => set({ items: [] }),
        count: () => get().items.reduce((n, i) => n + i.qty, 0),
        subtotal: () => get().items.reduce((n, i) => n + effectivePrice(i.product) * i.qty, 0),
      };
    },
    {
      name: 'mj-cart',
      // En WP la sesión del servidor es la fuente de verdad: no rehidratar viejo estado local.
      skipHydration: IS_WP,
      // Solo persistir el carrito standalone: error/syncing/shipping son efímeros.
      partialize: (s) => ({ items: s.items }),
    }
  )
);

/* ---------------------- Favoritos / Comparador -------------------------- */
const makeIdList = (name, max = Infinity) =>
  create(
    persist(
      (set, get) => ({
        ids: [],
        toggle: (id) =>
          set((s) => ({
            ids: s.ids.includes(id)
              ? s.ids.filter((x) => x !== id)
              : [...s.ids.slice(-(max - 1)), id],
          })),
        has: (id) => get().ids.includes(id),
        clear: () => set({ ids: [] }),
      }),
      { name }
    )
  );

export const useWishlist = makeIdList('mj-wishlist');
export const useCompare = makeIdList('mj-compare', 4);

/* --------------------- Vistos recientemente ----------------------------- */
export const useRecentlyViewed = create(
  persist(
    (set) => ({
      ids: [],
      push: (id) =>
        set((s) => ({ ids: [id, ...s.ids.filter((x) => x !== id)].slice(0, 8) })),
    }),
    { name: 'mj-recent' }
  )
);

/* ------------------------------ UI global ------------------------------- */
export const useUI = create((set, get) => ({
  cartOpen: false,
  mobileMenuOpen: false,
  quickView: null, // product | null
  currentProduct: null, // producto de la ficha abierta (contexto p/ consultas)
  // Tema inicial: ya está aplicado en <html data-theme> por el script
  // anti-flash de index.php (elección guardada > default del admin > sistema).
  theme: currentTheme(),
  setCartOpen: (v) => set({ cartOpen: v }),
  setMobileMenuOpen: (v) => set({ mobileMenuOpen: v }),
  setQuickView: (p) => set({ quickView: p }),
  setCurrentProduct: (p) => set({ currentProduct: p }),
  /** Aplica un tema. persist=false cuando lo dicta el sistema, no el visitante. */
  setTheme: (theme, { persist = true } = {}) => {
    applyTheme(theme, { persist });
    set({ theme });
  },
  /** Alterna día/noche con la transición circular desde `origin` ({x, y} del click). */
  toggleTheme: (origin) => {
    const theme = get().theme === 'dark' ? 'light' : 'dark';
    transitionTheme(theme, origin, () => set({ theme }));
  },
}));

/* Cambió el medio de pago elegido por el visitante → el servidor recalcula el carrito. */
usePaymentMethod.subscribe((state, prev) => {
  if (state.id !== prev.id) useCart.getState().syncPaymentMethod(selectedMethodId(state.id));
});
