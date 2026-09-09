/**
 * Precios por medio de pago (plugin MJ Medios de Pago → window.MJ.paymentPricing).
 *
 * Misma regla que el servidor (MJPM_Methods::price_for): precio con método =
 * redondeo(precio base × factor). Sin plugin, todo devuelve el precio base y
 * los componentes que dependen de esto no renderizan nada.
 *
 * En el carrito y el checkout los productos se muestran a precio base y el
 * ajuste va como línea aparte con su justificación (igual que en el pedido).
 */
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { PAYMENT_PRICING, EXCHANGE_RATE } from './config';
import { effectivePrice } from './api';

export const PAY = PAYMENT_PRICING;

/** Métodos visibles en selector, panel y tarjetas. */
export const payMethods = () => (PAY?.methods || []).filter((m) => m.show !== false);
export const findMethod = (id) => (id && PAY?.methods?.find((m) => m.id === id)) || null;
export const defaultMethodId = () => PAY?.defaultDisplay || PAY?.base || payMethods()[0]?.id || null;

/** Método elegido por el visitante para ver precios (persistido). null = el por defecto del admin. */
export const usePaymentMethod = create(
  persist((set) => ({ id: null, set: (id) => set({ id }) }), { name: 'mj-payment-method' })
);

/** Id efectivo: el elegido si sigue existiendo; si no, el por defecto. */
export const selectedMethodId = (id = usePaymentMethod.getState().id) => (findMethod(id) ? id : defaultMethodId());

/** Hook: método efectivo (config completa), o null sin plugin. */
export const useSelectedMethod = () => {
  const id = usePaymentMethod((s) => s.id);
  return findMethod(selectedMethodId(id));
};

export const adjusts = (m) => !!m && (m.kind === 'discount' || m.kind === 'surcharge') && m.value > 0;

export const roundPrice = (n) => {
  const step = Number(PAY?.rounding) || 0;
  if (step <= 0) return Math.round(n * 100) / 100;
  const q = n / step;
  return (PAY?.roundingMode === 'up' ? Math.ceil(q - 1e-9) : Math.round(q)) * step;
};

export const factorOf = (m) => {
  if (!adjusts(m) || m.mode !== 'percent') return 1;
  return m.kind === 'discount' ? Math.max(0, 1 - m.value / 100) : 1 + m.value / 100;
};

/** ¿El producto participa del ajuste de este método? (exclusiones + regla de ofertas). */
export const productEligible = (product, m) => {
  if (!product || !adjusts(m)) return false;
  if (product.paymentEligible === false) return false;
  if (m.kind === 'discount' && PAY?.applyOnSale === false && product.sale_price != null) return false;
  return true;
};

/**
 * Precio unitario del producto con un método (id, config, o el elegido si se omite).
 * → { price, base, factor, method, applied }  (applied: el precio unitario cambió)
 */
export function priceFor(product, method) {
  const m = typeof method === 'string' ? findMethod(method) : method === undefined ? findMethod(selectedMethodId()) : method;
  const base = effectivePrice(product);
  if (!productEligible(product, m) || m.mode !== 'percent') {
    return { price: base, base, factor: 1, method: m, applied: false };
  }
  const factor = factorOf(m);
  return { price: roundPrice(base * factor), base, factor, method: m, applied: true };
}

export const displayPrice = (product, method) => priceFor(product, method).price;

/** Badge para tarjetas ("10% OFF con transferencia") o ''. */
export const badgeFor = (product, m) =>
  PAY?.showBadge !== false && m?.kind === 'discount' && m.mode === 'percent' && productEligible(product, m)
    ? m.badge || `${m.value}% OFF con ${m.short || m.title}`
    : '';

/** "Precios pagando con Transferencia (10% OFF)." para presupuestos, o ''. */
export const paymentNote = (m = findMethod(selectedMethodId())) => {
  if (!m || !adjusts(m)) return '';
  const tag =
    m.mode === 'percent'
      ? (m.kind === 'discount' ? `${m.value}% OFF` : `+${m.value}%`)
      : (m.kind === 'discount' ? 'con descuento' : 'con recargo');
  return `Precios pagando con ${m.short || m.title} (${tag}).`;
};

/**
 * Estimación local del ajuste del carrito (misma regla que el servidor, sin
 * cupones): { amount, label, kind, mode, method } o null. amount < 0 = descuento.
 */
export function cartAdjustment(items, m) {
  if (!adjusts(m) || !items?.length) return null;
  let eligible = 0;
  let amount = 0;
  for (const it of items) {
    if (!productEligible(it.product, m)) continue;
    const unit = effectivePrice(it.product);
    eligible += unit * it.qty;
    if (m.mode === 'percent') amount += (roundPrice(unit * factorOf(m)) - unit) * it.qty;
  }
  if (m.mode === 'fixed') amount = eligible > 0 ? (m.kind === 'discount' ? -m.value : m.value) : 0;
  if (m.minAmount > 0 && eligible < m.minAmount) amount = 0;
  amount = Math.round(amount * 100) / 100;
  if (!amount) return null;
  return { amount, label: m.label, kind: m.kind, mode: m.mode, method: m };
}

/**
 * Ajuste a mostrar: el confirmado por el servidor cuando el carrito ya está
 * sincronizado con este método (live = dentro de WordPress); si no, la
 * estimación local para que el cambio se vea al instante.
 */
export function resolveAdjustment({ items, method, server, syncing, live }) {
  if (!method) return null;
  if (live && !syncing && server?.payment && server.payment.method === method.id) {
    const p = server.payment;
    return p.amount ? { amount: p.amount, label: p.label, kind: p.kind, mode: p.mode || method.mode, method } : null;
  }
  return cartAdjustment(items, method);
}

/** Total en dólares para métodos cobrados en USD (requiere MJ Dólar), o null. */
export const usdTotalFor = (amount, m) =>
  m?.currency === 'USD' && Number(EXCHANGE_RATE?.rate) > 0 ? amount / Number(EXCHANGE_RATE.rate) : null;
