import { useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/format';
import { LOCALE } from '@/lib/config';
import { monthGrid, monthKey, monthLabel, parseMonthKey, shiftMonthKey, todayStr, weekdayLabels } from '@/lib/dates';
import { iconBtnCls } from './styles';

/**
 * Calendario mensual con días habilitados por función.
 * @param {{ value?: 'YYYY-MM-DD', onChange?: (date) => void,
 *   month?: 'YYYY-MM' (controlado), defaultMonth?: 'YYYY-MM', onMonthChange?: (month) => void,
 *   weekStart?: 1|7, min?: 'YYYY-MM-DD', max?: 'YYYY-MM-DD', today?: 'YYYY-MM-DD' (zona del sitio),
 *   isEnabled?: (date) => boolean (además de min/max), markers?: (date) => number|string[] (puntitos, máx. 3),
 *   loading?: boolean, compact?: boolean, className?, labels?: { prev, next } }} props
 */
export function Calendar({
  value,
  onChange,
  month,
  defaultMonth,
  onMonthChange,
  weekStart = 1,
  min,
  max,
  today,
  isEnabled,
  markers,
  loading = false,
  compact = false,
  className,
  labels = { prev: 'Mes anterior', next: 'Mes siguiente' },
}) {
  const todayKey = today || todayStr();
  const [innerMonth, setInnerMonth] = useState(() => defaultMonth || monthKey(value) || monthKey(todayKey));
  const current = month || innerMonth;
  const { year, month: m } = parseMonthKey(current);

  // Si cambia el valor a otro mes (por ejemplo, "hoy"), seguirlo.
  useEffect(() => {
    if (!month && value && monthKey(value) !== innerMonth) setInnerMonth(monthKey(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const go = (delta) => {
    const next = shiftMonthKey(current, delta);
    if (!month) setInnerMonth(next);
    onMonthChange?.(next);
  };

  const weeks = useMemo(() => monthGrid(year, m, weekStart), [year, m, weekStart]);
  const heads = useMemo(() => weekdayLabels(weekStart, LOCALE, 'short').map((s) => s.slice(0, 3)), [weekStart]);

  const canPrev = !min || shiftMonthKey(current, -1) >= monthKey(min);
  const canNext = !max || shiftMonthKey(current, 1) <= monthKey(max);

  const enabled = (date) => {
    if (min && date < min) return false;
    if (max && date > max) return false;
    if (isEnabled && !isEnabled(date)) return false;
    return true;
  };

  return (
    <div className={cn('mjto-cal select-none', loading && 'mjto-cal--loading', className)} role="group" aria-label={monthLabel(year, m, LOCALE)}>
      <div className="mjto-cal__head mb-2 flex items-center justify-between gap-2">
        <button type="button" onClick={() => go(-1)} disabled={!canPrev || loading} className={cn(iconBtnCls, 'size-9')} aria-label={labels.prev}>
          <ChevronLeft size={18} aria-hidden="true" />
        </button>
        <span className="font-display text-[15px] font-semibold tracking-tight text-ink" aria-live="polite">
          {monthLabel(year, m, LOCALE)}
        </span>
        <button type="button" onClick={() => go(1)} disabled={!canNext || loading} className={cn(iconBtnCls, 'size-9')} aria-label={labels.next}>
          <ChevronRight size={18} aria-hidden="true" />
        </button>
      </div>
      <div className="mjto-cal__weekdays mb-1 grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
        {heads.map((h) => (
          <span key={h} className="py-1">
            {h}
          </span>
        ))}
      </div>
      <div className={cn('mjto-cal__grid grid grid-cols-7 transition-opacity', compact ? 'gap-0.5' : 'gap-1', loading && 'opacity-50')}>
        {weeks.flat().map(({ date, inMonth }) => {
          if (!inMonth) return <span key={date} aria-hidden="true" />;
          const on = enabled(date);
          const marks = markers ? markers(date) : null;
          const dots = Array.isArray(marks) ? marks.slice(0, 3) : marks ? Array.from({ length: Math.min(3, Number(marks) || 0) }) : [];
          return (
            <button
              key={date}
              type="button"
              disabled={!on}
              onClick={() => onChange?.(date)}
              data-today={date === todayKey ? 'true' : undefined}
              data-selected={date === value ? 'true' : undefined}
              aria-pressed={date === value}
              aria-label={date}
              className={cn('mjto-cal__day', compact ? 'text-[13px]' : 'text-sm', on && date !== value && 'text-ink')}
            >
              <span>{Number(date.slice(8, 10))}</span>
              {dots.length > 0 && (
                <span className="mjto-cal__dots" aria-hidden="true">
                  {dots.map((d, i) => (
                    <span key={i} className="mjto-cal__dot" style={typeof d === 'string' ? { background: d, opacity: 1 } : undefined} />
                  ))}
                </span>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
