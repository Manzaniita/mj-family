/**
 * Formateo: precios (moneda del boot), fechas/horas (Intl, es-AR), duraciones,
 * teléfonos/WhatsApp y `cn()` para clases.
 */
import { CUR, LOCALE, BUSINESS } from './config';
import { fromDateStr, fromDateTimeStr, addDaysStr, addMonthsStr, timeToMinutes, splitDateTime } from './dates';

export const cn = (...classes) => classes.filter(Boolean).join(' ');

export const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';

/* ------------------------------- Dinero --------------------------------- */

/** Número → "1.234,50" con los separadores de la moneda (sin símbolo). */
export function formatAmount(n, cur = CUR) {
  const decimals = Math.max(0, Number(cur.decimals) || 0);
  const value = Math.abs(Number(n) || 0);
  const fixed = value.toFixed(decimals);
  const [int, frac] = fixed.split('.');
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, cur.thousandSep ?? '.');
  const sign = Number(n) < 0 ? '-' : '';
  return sign + (decimals ? `${grouped}${cur.decimalSep ?? ','}${frac}` : grouped);
}

/** Precio con símbolo y posición de la moneda configurada. */
export function formatPrice(n, cur = CUR) {
  const amount = formatAmount(n, cur);
  const symbol = cur.symbol ?? '$';
  switch (cur.position) {
    case 'right':
      return `${amount}${symbol}`;
    case 'left_space':
      return `${symbol} ${amount}`;
    case 'right_space':
      return `${amount} ${symbol}`;
    case 'left':
    default:
      return `${symbol}${amount}`;
  }
}

/**
 * "1.234,50" | "1234.50" | "$ 1.234" → número. Acepta ambos separadores:
 * el último separador que aparece se toma como decimal si tiene 1–2 dígitos después.
 */
export function parseMoney(input) {
  if (typeof input === 'number') return Number.isFinite(input) ? input : 0;
  let s = String(input ?? '').replace(/[^\d,.\-]/g, '');
  if (!s) return 0;
  const negative = s.startsWith('-');
  s = s.replace(/-/g, '');
  const lastComma = s.lastIndexOf(',');
  const lastDot = s.lastIndexOf('.');
  const sepIndex = Math.max(lastComma, lastDot);
  let value;
  if (sepIndex === -1) {
    value = Number(s);
  } else {
    const decimalsPart = s.slice(sepIndex + 1);
    const isDecimal = decimalsPart.length > 0 && decimalsPart.length <= 2;
    if (isDecimal) {
      const intPart = s.slice(0, sepIndex).replace(/[.,]/g, '');
      value = Number(`${intPart}.${decimalsPart}`);
    } else {
      value = Number(s.replace(/[.,]/g, ''));
    }
  }
  if (!Number.isFinite(value)) return 0;
  return negative ? -value : value;
}

/** Redondea a los decimales de la moneda. */
export function roundMoney(n, cur = CUR) {
  const f = 10 ** (Number(cur.decimals) || 0);
  return Math.round((Number(n) || 0) * f) / f;
}

/* ------------------------------- Fechas --------------------------------- */

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

const fmtCache = new Map();
const intl = (opts) => {
  const key = JSON.stringify(opts);
  if (!fmtCache.has(key)) fmtCache.set(key, new Intl.DateTimeFormat(LOCALE, opts));
  return fmtCache.get(key);
};

/** 'YYYY-MM-DD' → "12/09/2026" (numérico corto). */
export function formatDate(dateStr, opts = { day: '2-digit', month: '2-digit', year: 'numeric' }) {
  const d = fromDateStr(dateStr);
  return d ? intl(opts).format(d) : '';
}

/** 'YYYY-MM-DD' → "sáb 12 sep" (igual que dateLabel de PHP). */
export function dateLabel(dateStr, { year = false } = {}) {
  const d = fromDateStr(dateStr);
  if (!d) return '';
  const s = intl({ weekday: 'short', day: 'numeric', month: 'short', ...(year ? { year: 'numeric' } : {}) })
    .format(d)
    .replace(/\./g, '')
    .replace(/,/g, '');
  return s.replace(/\s+/g, ' ').trim();
}

/** 'YYYY-MM-DD' → "sábado 12 de septiembre". */
export function longDateLabel(dateStr, { year = false } = {}) {
  const d = fromDateStr(dateStr);
  if (!d) return '';
  return intl({ weekday: 'long', day: 'numeric', month: 'long', ...(year ? { year: 'numeric' } : {}) })
    .format(d)
    .replace(/,/g, '');
}

/** 'YYYY-MM-DD' → "12 sep" / "12 sep 2026". */
export function shortDateLabel(dateStr, { year = false } = {}) {
  const d = fromDateStr(dateStr);
  if (!d) return '';
  return intl({ day: 'numeric', month: 'short', ...(year ? { year: 'numeric' } : {}) }).format(d).replace(/\./g, '');
}

/** Nombre del día ("Sábado") o corto ("Sáb"). */
export function weekdayLabel(dateStr, width = 'long') {
  const d = fromDateStr(dateStr);
  return d ? cap(intl({ weekday: width }).format(d).replace(/\.$/, '')) : '';
}

/** 'HH:MM[:SS]' → 'HH:MM' (formato 24 h, como se usa en la agenda). */
export function formatTime(time) {
  const min = timeToMinutes(time);
  if (Number.isNaN(min)) return '';
  const h = Math.floor(min / 60);
  const m = min % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/** 'YYYY-MM-DD HH:MM[:SS]' → "sáb 12 sep, 10:30". */
export function formatDateTime(dt, { year = false } = {}) {
  const parts = splitDateTime(dt);
  if (!parts) return formatDate(dt);
  return `${dateLabel(parts.date, { year })}, ${formatTime(parts.time)}`;
}

/** "10:00 – 12:00" */
export function timeRange(from, to) {
  const a = formatTime(from);
  const b = formatTime(to);
  return b ? `${a} – ${b}` : a;
}

/**
 * 'Hoy' | 'Mañana' | 'Ayer' | null relativo a `today` ('YYYY-MM-DD').
 * Los textos son del panel (uso interno de la dueña) y se pueden pasar.
 */
export function relativeDayLabel(dateStr, today, labels = { today: 'Hoy', tomorrow: 'Mañana', yesterday: 'Ayer' }) {
  if (!dateStr || !today) return null;
  if (dateStr === today) return labels.today;
  if (dateStr === addDaysStr(today, 1)) return labels.tomorrow;
  if (dateStr === addDaysStr(today, -1)) return labels.yesterday;
  return null;
}

/** "Hoy · sáb 12 sep" o solo "sáb 12 sep". */
export function friendlyDate(dateStr, today, labels) {
  const rel = relativeDayLabel(dateStr, today, labels);
  const base = dateLabel(dateStr);
  return rel ? `${rel} · ${base}` : cap(base);
}

/** Tiempo transcurrido ("hace 5 min", "hace 2 h", "hace 3 días") desde un 'YYYY-MM-DD HH:MM'. */
export function timeAgo(dt, now = new Date()) {
  const d = fromDateTimeStr(dt);
  if (!d) return '';
  const diff = Math.max(0, (now - d) / 1000);
  if (diff < 60) return 'recién';
  if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
  const days = Math.floor(diff / 86400);
  return days === 1 ? 'hace 1 día' : `hace ${days} días`;
}

export { addDaysStr as addDays, addMonthsStr as addMonths };

/* ------------------------------ Duración -------------------------------- */

/**
 * 150 → "2 h 30 min" · 60 → "1 h" · 45 → "45 min".
 * Las unidades salen de las opciones (label_hours / label_minutes).
 */
export function minutesToLabel(min, labels = {}) {
  const total = Math.max(0, Math.round(Number(min) || 0));
  const h = Math.floor(total / 60);
  const m = total % 60;
  const hl = labels.hours || 'h';
  const ml = labels.minutes || 'min';
  if (!h) return `${m} ${ml}`;
  if (!m) return `${h} ${hl}`;
  return `${h} ${hl} ${m} ${ml}`;
}

/* ------------------------------ Teléfonos ------------------------------- */

/** Solo dígitos, sin ceros iniciales (espejo de mjto_normalize_phone). */
export function phoneDigits(raw) {
  return String(raw || '').replace(/\D+/g, '').replace(/^0+/, '');
}

/**
 * Número para wa.me: si ya empieza con el código de país lo deja, si no
 * antepone el prefijo (default AR: país 54, prefijo 549). Espejo de mjto_whatsapp_number.
 */
export function whatsappNumber(phone, { country = '54', prefix = '549' } = {}) {
  const digits = phoneDigits(phone);
  if (!digits) return '';
  if (country && digits.startsWith(country) && digits.length > 10) return digits;
  return `${prefix}${digits}`;
}

export function whatsappUrl(number, text = '') {
  const n = phoneDigits(number);
  if (!n) return '';
  return `https://wa.me/${n}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}

/** Link para escribirle al negocio (business.whatsapp del boot). */
export function businessWhatsappUrl(text = '') {
  return whatsappUrl(BUSINESS.whatsapp, text);
}

/** "+54 9 11 1234-5678" aproximado para mostrar (deja el raw si es raro). */
export function prettyPhone(raw) {
  const s = String(raw || '').trim();
  const digits = phoneDigits(s);
  if (digits.length >= 10 && digits.length <= 13) {
    const local = digits.slice(-8);
    const area = digits.slice(-10, -8);
    const rest = digits.slice(0, -10);
    return `${rest ? `+${rest} ` : ''}${area} ${local.slice(0, 4)}-${local.slice(4)}`;
  }
  return s;
}

/* -------------------------------- Textos -------------------------------- */

export const capitalize = cap;

/** "María José Pérez" → "MP" (iniciales para avatares). */
export function initials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return '?';
  const first = parts[0].charAt(0);
  const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
  return (first + last).toUpperCase();
}

/** Nombre completo a partir de un cliente { firstName, lastName, name }. */
export function fullName(c) {
  if (!c) return '';
  if (c.name) return c.name;
  return [c.firstName, c.lastName].filter(Boolean).join(' ').trim();
}

/** Reemplaza {placeholders} en una plantilla (mismo criterio que strtr en PHP). */
export function fillTemplate(template, values = {}) {
  return String(template || '').replace(/\{([a-zA-Z0-9_ñáéíóú]+)\}/g, (m, key) =>
    values[key] !== undefined && values[key] !== null ? String(values[key]) : m
  );
}

/** Plural simple: pluralize(3, 'turno', 'turnos') → "3 turnos". */
export function pluralize(n, singular, plural = `${singular}s`) {
  const v = Number(n) || 0;
  return `${v} ${v === 1 ? singular : plural}`;
}

/** Quita acentos y baja a minúsculas: para buscar en memoria. */
export function normalizeText(s) {
  return String(s || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

/** Clave estilo sanitize_key: minúsculas, [a-z0-9_-]. */
export function slugKey(s) {
  return normalizeText(s)
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '');
}
