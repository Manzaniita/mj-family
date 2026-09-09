/**
 * Utilidades de calendario sobre strings 'YYYY-MM-DD' y 'HH:MM'.
 *
 * Todo trabaja en "fecha local sin zona": nunca convertimos a UTC ni usamos
 * Date.toISOString() (que corre el día). Los Date internos se crean a las
 * 12:00 para esquivar los cambios de horario.
 */

export const pad2 = (n) => String(n).padStart(2, '0');

/** Date → 'YYYY-MM-DD' (componentes locales). */
export function toDateStr(d) {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/** 'YYYY-MM-DD' → Date local a las 12:00 (null si no es una fecha válida). */
export function fromDateStr(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 12, 0, 0, 0);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** ¿Es un 'YYYY-MM-DD' real (mes/día existentes)? */
export function isDateStr(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
  if (!m) return false;
  const d = fromDateStr(s);
  return !!d && d.getMonth() + 1 === Number(m[2]) && d.getDate() === Number(m[3]);
}

/** Hoy según el reloj del navegador. El panel prefiere `bootstrap.today` (zona del sitio). */
export function todayStr() {
  return toDateStr(new Date());
}

/** 'HH:MM' actual del navegador. */
export function nowTimeStr() {
  const d = new Date();
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

export function addDaysStr(dateStr, days) {
  const d = fromDateStr(dateStr);
  if (!d) return dateStr;
  d.setDate(d.getDate() + Number(days || 0));
  return toDateStr(d);
}

/** Suma meses cuidando fin de mes (31 ene + 1 mes → 28/29 feb). */
export function addMonthsStr(dateStr, months) {
  const d = fromDateStr(dateStr);
  if (!d) return dateStr;
  const day = d.getDate();
  d.setDate(1);
  d.setMonth(d.getMonth() + Number(months || 0));
  const last = daysInMonth(d.getFullYear(), d.getMonth() + 1);
  d.setDate(Math.min(day, last));
  return toDateStr(d);
}

/** Días de un mes (month 1..12). */
export function daysInMonth(year, month) {
  return new Date(year, month, 0).getDate();
}

/** Día ISO de la semana: 1 = lunes … 7 = domingo. */
export function isoDow(dateStr) {
  const d = fromDateStr(dateStr);
  if (!d) return 0;
  const js = d.getDay();
  return js === 0 ? 7 : js;
}

/** Compara dos 'YYYY-MM-DD' (funcionan como strings). -1 | 0 | 1 */
export function compareDates(a, b) {
  if (a === b) return 0;
  return a < b ? -1 : 1;
}

/** Diferencia en días (b - a). */
export function daysBetween(a, b) {
  const da = fromDateStr(a);
  const db = fromDateStr(b);
  if (!da || !db) return 0;
  return Math.round((db - da) / 86400000);
}

export function isSameMonth(a, b) {
  return String(a || '').slice(0, 7) === String(b || '').slice(0, 7);
}

/** 'YYYY-MM' de una fecha. */
export function monthKey(dateStr) {
  return String(dateStr || '').slice(0, 7);
}

/** { year, month } (month 1..12) desde 'YYYY-MM' o 'YYYY-MM-DD'. */
export function parseMonthKey(key) {
  const m = /^(\d{4})-(\d{2})/.exec(String(key || ''));
  if (!m) {
    const t = new Date();
    return { year: t.getFullYear(), month: t.getMonth() + 1 };
  }
  return { year: Number(m[1]), month: Number(m[2]) };
}

export function toMonthKey(year, month) {
  return `${year}-${pad2(month)}`;
}

/** 'YYYY-MM' + n meses. */
export function shiftMonthKey(key, n) {
  const { year, month } = parseMonthKey(key);
  const d = new Date(year, month - 1 + Number(n || 0), 1, 12);
  return toMonthKey(d.getFullYear(), d.getMonth() + 1);
}

export function startOfMonth(dateStr) {
  return `${monthKey(dateStr)}-01`;
}

export function endOfMonth(dateStr) {
  const { year, month } = parseMonthKey(dateStr);
  return `${toMonthKey(year, month)}-${pad2(daysInMonth(year, month))}`;
}

/** Primer día de la semana que contiene la fecha (weekStart 1 = lunes | 7 = domingo). */
export function startOfWeek(dateStr, weekStart = 1) {
  const dow = isoDow(dateStr);
  if (!dow) return dateStr;
  const first = Number(weekStart) === 7 ? 7 : 1;
  const diff = (dow - first + 7) % 7;
  return addDaysStr(dateStr, -diff);
}

export function endOfWeek(dateStr, weekStart = 1) {
  return addDaysStr(startOfWeek(dateStr, weekStart), 6);
}

/** Los 7 días de la semana de la fecha. */
export function weekDates(dateStr, weekStart = 1) {
  const start = startOfWeek(dateStr, weekStart);
  return Array.from({ length: 7 }, (_, i) => addDaysStr(start, i));
}

/** Todas las fechas entre from y to (inclusive). */
export function rangeDates(from, to) {
  const out = [];
  if (!isDateStr(from) || !isDateStr(to) || from > to) return out;
  let cur = from;
  let guard = 0;
  while (cur <= to && guard < 400) {
    out.push(cur);
    cur = addDaysStr(cur, 1);
    guard += 1;
  }
  return out;
}

/**
 * Grilla mensual para un calendario: semanas de 7 celdas
 * [{ date: 'YYYY-MM-DD', inMonth: bool }]. Incluye los días de relleno
 * del mes anterior/siguiente según weekStart (1 lunes | 7 domingo).
 */
export function monthGrid(year, month, weekStart = 1) {
  const firstStr = `${toMonthKey(year, month)}-01`;
  const start = startOfWeek(firstStr, weekStart);
  const total = daysInMonth(year, month);
  const lastStr = `${toMonthKey(year, month)}-${pad2(total)}`;
  const end = endOfWeek(lastStr, weekStart);
  const dates = rangeDates(start, end);
  const weeks = [];
  for (let i = 0; i < dates.length; i += 7) {
    weeks.push(
      dates.slice(i, i + 7).map((date) => ({
        date,
        inMonth: date.slice(0, 7) === toMonthKey(year, month),
      }))
    );
  }
  return weeks;
}

/** Nombres cortos de los días en el orden de la semana (según locale). */
export function weekdayLabels(weekStart = 1, locale = 'es-AR', width = 'short') {
  // 2024-01-01 fue lunes.
  const base = ['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05', '2024-01-06', '2024-01-07'];
  const ordered = Number(weekStart) === 7 ? [base[6], ...base.slice(0, 6)] : base;
  const fmt = new Intl.DateTimeFormat(locale, { weekday: width });
  return ordered.map((d) => {
    const label = fmt.format(fromDateStr(d)).replace(/\.$/, '');
    return label.charAt(0).toUpperCase() + label.slice(1);
  });
}

/** Etiqueta "septiembre 2026" (capitalizada). */
export function monthLabel(year, month, locale = 'es-AR') {
  const label = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1, 12));
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/* ---------------------------- Horas 'HH:MM' ----------------------------- */

/** 'HH:MM' → minutos desde medianoche (NaN si no es válido). */
export function timeToMinutes(t) {
  const m = /^(\d{1,2}):(\d{2})/.exec(String(t || ''));
  if (!m) return NaN;
  return Number(m[1]) * 60 + Number(m[2]);
}

/** Minutos desde medianoche → 'HH:MM' (se envuelve a 24 h). */
export function minutesToTime(min) {
  const total = ((Math.round(Number(min) || 0) % 1440) + 1440) % 1440;
  return `${pad2(Math.floor(total / 60))}:${pad2(total % 60)}`;
}

export function isTimeStr(t) {
  const m = /^(\d{2}):(\d{2})$/.exec(String(t || ''));
  return !!m && Number(m[1]) < 24 && Number(m[2]) < 60;
}

/** 'HH:MM' + minutos → 'HH:MM'. */
export function addMinutesToTime(t, minutes) {
  const base = timeToMinutes(t);
  if (Number.isNaN(base)) return t;
  return minutesToTime(base + Number(minutes || 0));
}

/** 'YYYY-MM-DD HH:MM[:SS]' → { date, time } (o null). */
export function splitDateTime(s) {
  const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(String(s || ''));
  return m ? { date: m[1], time: m[2] } : null;
}

/** 'YYYY-MM-DD HH:MM' → Date local (null si no parsea). */
export function fromDateTimeStr(s) {
  const parts = splitDateTime(s);
  if (!parts) return fromDateStr(s);
  const d = fromDateStr(parts.date);
  if (!d) return null;
  const min = timeToMinutes(parts.time);
  d.setHours(Math.floor(min / 60), min % 60, 0, 0);
  return d;
}

/** Franjas "09:00-13:00, 15:00-20:00" → [['09:00','13:00'], …] (espejo de mjto_parse_ranges). */
export function parseRanges(str) {
  return String(str || '')
    .split(/[,;\n]+/)
    .map((chunk) => chunk.trim())
    .map((chunk) => /^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/.exec(chunk))
    .filter(Boolean)
    .map((m) => [minutesToTime(timeToMinutes(m[1])), minutesToTime(timeToMinutes(m[2]))])
    .filter(([a, b]) => timeToMinutes(a) < timeToMinutes(b));
}
