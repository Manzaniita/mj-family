import { useMemo, useRef } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { SITE } from '@/lib/config';
import { EASE, cn } from '@/lib/format';
import { TRANSITIONS } from '@/lib/motion';
import { todayISO, addDays, isoWeekday, monthBounds, formatDateLong } from '@/lib/turnos';

/**
 * Calendario mensual del wizard de reserva (plugin MJ Turnos Online).
 *
 * Props:
 *  - month: 'YYYY-MM' (acepta también 'YYYY-MM-DD'; se usa el mes).
 *  - onMonthChange(next: 'YYYY-MM'): navegación ‹ ›.
 *  - days: mapa { 'YYYY-MM-DD': ['09:00', …] } — un día se puede elegir solo
 *    si tiene al menos un horario (respuesta de GET /availability).
 *  - selected: 'YYYY-MM-DD' | null; onSelect(iso).
 *  - weekStart: 1 (lunes) | 7 (domingo) — schedule.weekStart del plugin.
 *  - minDate / maxDate: ISO inclusive; fuera de ese rango nada se puede
 *    elegir y la navegación de meses se frena.
 *  - loading: atenúa la grilla mientras se carga la disponibilidad.
 * Nombres de meses y días: Intl en el locale del sitio (sin textos fijos).
 */

const pad2 = (n) => String(n).padStart(2, '0');
const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const parts = (month) => String(month || '').split('-').map(Number);

/** 'YYYY-MM' de una fecha ISO (o del mismo 'YYYY-MM'). */
export const monthOf = (iso) => String(iso || '').slice(0, 7);

/** Mes desplazado ±N: shiftMonth('2026-12', 1) → '2027-01'. */
export function shiftMonth(month, delta) {
  const [y, m] = parts(month);
  const d = new Date(y, (m || 1) - 1 + Number(delta || 0), 1);
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`;
}

/** Primer y último día del mes → { from, to } en ISO. */
export function monthRange(month) {
  const [y, m] = parts(month);
  return monthBounds(y, (m || 1) - 1);
}

/** "Septiembre de 2026" en el locale del sitio. */
export function monthTitle(month) {
  const [y, m] = parts(month);
  try {
    return cap(new Intl.DateTimeFormat(SITE.locale, { month: 'long', year: 'numeric' }).format(new Date(y, (m || 1) - 1, 1)));
  } catch {
    return String(month || '');
  }
}

/* Cabecera de días: 7 etiquetas cortas a partir del día en que arranca la semana (2024-01-01 fue lunes). */
function weekdayLabels(weekStart) {
  let fmt;
  try {
    fmt = new Intl.DateTimeFormat(SITE.locale, { weekday: 'short' });
  } catch {
    fmt = null;
  }
  return Array.from({ length: 7 }, (_, i) => {
    const isoDow = ((weekStart - 1 + i) % 7) + 1;
    const date = new Date(2024, 0, 1 + (isoDow - 1));
    const label = fmt ? fmt.format(date) : String(isoDow);
    return { isoDow, label: cap(label.replace(/\.$/, '')) };
  });
}

export function Calendar({
  month, onMonthChange, days, selected = null, onSelect, weekStart = 1, minDate, maxDate, loading = false, className,
}) {
  const key = /^\d{4}-\d{2}/.test(String(month || '')) ? monthOf(month) : monthOf(todayISO());
  const { from, to } = monthRange(key);
  const min = minDate || todayISO();
  const max = maxDate || '';
  const start = Number(weekStart) === 7 ? 7 : 1;
  const today = todayISO();

  const canPrev = monthOf(min) < key;
  const canNext = !max || monthOf(max) > key;

  // Dirección del deslizamiento al cambiar de mes.
  const prevKey = useRef(key);
  const dir = key > prevKey.current ? 1 : key < prevKey.current ? -1 : 0;
  prevKey.current = key;

  const weekdays = useMemo(() => weekdayLabels(start), [start]);
  const cells = useMemo(() => {
    const lead = (isoWeekday(from) - start + 7) % 7;
    const list = Array.from({ length: lead }, () => null);
    for (let iso = from; iso <= to; iso = addDays(iso, 1)) list.push(iso);
    while (list.length % 7 !== 0) list.push(null);
    return list;
  }, [from, to, start]);

  const isAvailable = (iso) =>
    iso >= min && (!max || iso <= max) && Array.isArray(days?.[iso]) && days[iso].length > 0;

  const navCls =
    'mj-calendar__nav grid size-9 place-items-center rounded-(--mj-radius-pill) glass text-ink-soft transition-colors hover:text-ink disabled:opacity-30 disabled:pointer-events-none';

  return (
    <div className={cn('mj-booking__calendar mj-calendar', className)}>
      <div className="mj-calendar__head mb-4 flex items-center justify-between gap-3">
        <button
          type="button"
          className={navCls}
          disabled={!canPrev}
          onClick={() => onMonthChange?.(shiftMonth(key, -1))}
          aria-label={monthTitle(shiftMonth(key, -1))}
        >
          <ChevronLeft size={17} />
        </button>
        <p className="mj-calendar__title inline-flex items-center gap-2 font-display text-base font-semibold tracking-tight" aria-live="polite">
          {monthTitle(key)}
          {loading && <Loader2 size={14} className="animate-spin text-ink-faint" aria-hidden="true" />}
        </p>
        <button
          type="button"
          className={navCls}
          disabled={!canNext}
          onClick={() => onMonthChange?.(shiftMonth(key, 1))}
          aria-label={monthTitle(shiftMonth(key, 1))}
        >
          <ChevronRight size={17} />
        </button>
      </div>

      <div className="mj-calendar__weekdays grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
        {weekdays.map((d) => (
          <span key={d.isoDow} className="py-1">{d.label}</span>
        ))}
      </div>

      <AnimatePresence mode="wait" initial={false}>
        <motion.div
          key={key}
          className={cn('mj-calendar__grid mt-1 grid grid-cols-7 gap-y-1 transition-opacity duration-300', loading && 'opacity-50')}
          initial={TRANSITIONS ? { opacity: 0, x: dir * 18 } : false}
          animate={{ opacity: 1, x: 0 }}
          exit={TRANSITIONS ? { opacity: 0, x: dir * -18 } : undefined}
          transition={{ duration: 0.25, ease: EASE }}
          aria-busy={loading || undefined}
        >
          {cells.map((iso, i) => {
            if (!iso) return <span key={`empty-${i}`} className="mj-calendar__day mj-calendar__day--outside" aria-hidden="true" />;
            const ok = isAvailable(iso);
            const sel = iso === selected;
            const isToday = iso === today;
            return (
              <button
                key={iso}
                type="button"
                disabled={!ok}
                aria-pressed={sel}
                aria-label={formatDateLong(iso)}
                onClick={() => onSelect?.(iso)}
                className={cn(
                  'mj-calendar__day mx-auto grid size-10 place-items-center rounded-full text-sm tabular-nums transition-all duration-200',
                  ok && 'mj-calendar__day--available bg-surface-2 font-medium text-ink hover:bg-accent-soft hover:text-accent',
                  !ok && 'mj-calendar__day--disabled text-ink-faint opacity-50',
                  isToday && 'mj-calendar__day--today ring-1 ring-line-strong',
                  sel && 'mj-calendar__day--selected bg-accent text-white shadow-glow hover:bg-accent hover:text-white'
                )}
              >
                {Number(iso.slice(8, 10))}
              </button>
            );
          })}
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
