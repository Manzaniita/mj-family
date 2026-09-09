/**
 * Textos planos para consultas (WhatsApp / mail) y presupuestos.
 * Se comparten entre el botón flotante, el modal de presupuesto y el PDF.
 */
import { formatPrice } from './format';
import { effectivePrice } from './api';

export const productUrl = (p) =>
  `${typeof location !== 'undefined' ? location.origin : ''}/producto/${p.slug}`;

/** Líneas "2 × Nombre — $X c/u = $Y" para ítems del carrito. */
export const cartLines = (items) =>
  items.map((i) => {
    const unit = effectivePrice(i.product);
    return `${i.qty} × ${i.product.name} — ${formatPrice(unit)} c/u = ${formatPrice(unit * i.qty)}`;
  });

/** Líneas "- Nombre — $X — url" para listas de productos (favoritos, comparador). */
export const productLines = (products) =>
  products.map((p) => `- ${p.name} — ${formatPrice(effectivePrice(p))}\n  ${productUrl(p)}`);

export const cartTotal = (items) =>
  items.reduce((n, i) => n + effectivePrice(i.product) * i.qty, 0);

/**
 * Arma el mensaje completo: título, líneas, total opcional y pie opcional.
 */
export function buildMessage({ title, lines = [], total = null, footer = null }) {
  const parts = [title, ''];
  parts.push(...lines);
  if (total != null) {
    parts.push('', `Total: ${formatPrice(total)}`);
  }
  if (footer) {
    parts.push('', footer);
  }
  return parts.join('\n');
}

/** URL de WhatsApp con el mensaje encodeado. */
export const whatsappUrl = (number, text) =>
  `https://wa.me/${number}?text=${encodeURIComponent(text)}`;

/** URL mailto con asunto y cuerpo. */
export const mailUrl = (email, subject, body) =>
  `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
