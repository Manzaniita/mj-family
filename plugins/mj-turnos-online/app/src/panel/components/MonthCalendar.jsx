/**
 * Calendario del mes para saltar a una fecha (Hoy, 1.3): cantidad de turnos
 * por día y los días cerrados o con bloqueo marcados.
 * GET /panel/month?month=YYYY-MM → { days: { 'YYYY-MM-DD': { count, open, blocked } } }.
 *
 *   <MonthCalendar open={open} onClose={…} value={date} onPick={(date) => …} />
 *
 * Los meses ya pedidos quedan en memoria mientras el modal está montado; al
 * abrirlo de nuevo se vuelven a pedir (los turnos cambian).
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Sun } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { LOCALE } from '@/lib/config';
import { cn, longDateLabel, pluralize } from '@/lib/format';
import { monthGrid, monthKey, monthLabel, parseMonthKey, shiftMonthKey, weekdayLabels } from '@/lib/dates';
import { Button, Modal, iconBtnCls } from '@/ui';
import { usePanel } from '../store';

/**
 * @param {{ open: boolean, onClose: () => void, value: 'YYYY-MM-DD', onPick: (date: string) => void }} props
 */
export function MonthCalendar({ open, onClose, value, onPick }) {
  const store = usePanel();
  const [month, setMonth] = useState(() => monthKey(value || store.today));
  const [days, setDays] = useState({});
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const cache = useRef({});

  // Al abrir: arranca en el mes de la fecha elegida y olvida lo pedido antes.
  useEffect(() => {
    if (!open) return;
    cache.current = {};
    setMonth(monthKey(value || store.today));
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return undefined;
    if (cache.current[month]) {
      setDays(cache.current[month]);
      setFailed(false);
      return undefined;
    }
    const ctrl = new AbortController();
    setLoading(true);
    setFailed(false);
    panelApi
      .getMonth(month, { signal: ctrl.signal })
      .then((res) => {
        if (ctrl.signal.aborted) return;
        const map = res?.days && typeof res.days === 'object' ? res.days : {};
        cache.current[month] = map;
        setDays(map);
      })
      .catch((e) => {
        if (ctrl.signal.aborted || e?.name === 'AbortError') return;
        setDays({});
        setFailed(true);
      })
      .finally(() => {
        if (!ctrl.signal.aborted) setLoading(false);
      });
    return () => ctrl.abort();
  }, [open, month]);

  const { year, month: m } = parseMonthKey(month);
  const weeks = useMemo(() => monthGrid(year, m, store.weekStart), [year, m, store.weekStart]);
  const heads = useMemo(() => weekdayLabels(store.weekStart, LOCALE, 'short').map((s) => s.slice(0, 3)), [store.weekStart]);

  const total = useMemo(() => Object.values(days).reduce((sum, d) => sum + (Number(d?.count) || 0), 0), [days]);

  const pick = (date) => {
    onPick?.(date);
    onClose?.();
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title="Ir a una fecha"
      description={loading ? 'Cargando el mes…' : failed ? 'No se pudo cargar la cantidad de turnos.' : `${pluralize(total, 'turno')} en ${monthLabel(year, m, LOCALE).toLowerCase()}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cerrar
          </Button>
          <Button variant="soft" icon={Sun} onClick={() => pick(store.today)}>
            Hoy
          </Button>
        </>
      }
    >
      <div className="mjto-monthcal select-none pt-1">
        <div className="mb-2 flex items-center justify-between gap-2">
          <button type="button" onClick={() => setMonth((k) => shiftMonthKey(k, -1))} className={cn(iconBtnCls, 'size-9')} aria-label="Mes anterior">
            <ChevronLeft size={18} aria-hidden="true" />
          </button>
          <span className="font-display text-[15px] font-semibold tracking-tight text-ink" aria-live="polite">
            {monthLabel(year, m, LOCALE)}
          </span>
          <button type="button" onClick={() => setMonth((k) => shiftMonthKey(k, 1))} className={cn(iconBtnCls, 'size-9')} aria-label="Mes siguiente">
            <ChevronRight size={18} aria-hidden="true" />
          </button>
        </div>

        <div className="mb-1 grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-wider text-ink-faint" aria-hidden="true">
          {heads.map((h) => (
            <span key={h} className="py-1">
              {h}
            </span>
          ))}
        </div>

        <div className={cn('grid grid-cols-7 gap-1 transition-opacity', loading && 'opacity-50')} role="group" aria-label={monthLabel(year, m, LOCALE)}>
          {weeks.flat().map(({ date, inMonth }) => {
            if (!inMonth) return <span key={date} aria-hidden="true" />;
            const info = days[date] || null;
            const count = Number(info?.count) || 0;
            const closed = !!info && info.open === false;
            const blocked = !!info?.blocked;
            const selected = date === value;
            const isToday = date === store.today;
            const extra = [count ? pluralize(count, 'turno') : '', closed ? 'cerrado' : '', blocked ? 'con bloqueo' : ''].filter(Boolean).join(', ');
            return (
              <button
                key={date}
                type="button"
                onClick={() => pick(date)}
                aria-pressed={selected}
                aria-label={`${longDateLabel(date)}${extra ? `, ${extra}` : ''}`}
                aria-current={isToday ? 'date' : undefined}
                className={cn(
                  'relative flex aspect-square flex-col items-center justify-center gap-0.5 rounded-xl text-sm font-medium transition-[background-color,color,transform] active:scale-95',
                  selected
                    ? 'bg-accent text-on-accent shadow-glow'
                    : blocked
                      ? 'bg-warning-soft text-warning hover:brightness-95'
                      : closed
                        ? 'text-ink-faint hover:bg-surface-2'
                        : 'text-ink hover:bg-accent-soft',
                  isToday && !selected && 'ring-[1.5px] ring-accent/60'
                )}
              >
                <span className={cn('leading-none mjto-tabular', closed && !selected && 'line-through decoration-1')}>{Number(date.slice(8, 10))}</span>
                <span
                  className={cn(
                    'min-w-4 rounded-full px-1 text-[10px] font-bold leading-4 mjto-tabular',
                    count ? (selected ? 'bg-on-accent/20 text-on-accent' : 'bg-accent-soft text-accent') : 'opacity-0'
                  )}
                  aria-hidden="true"
                >
                  {count || 0}
                </span>
              </button>
            );
          })}
        </div>

        <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-faint" aria-label="Referencias">
          <li className="inline-flex items-center gap-1.5">
            <span className="inline-grid min-w-4 place-items-center rounded-full bg-accent-soft px-1 text-[10px] font-bold leading-4 text-accent" aria-hidden="true">
              2
            </span>
            Turnos del día
          </li>
          <li className="inline-flex items-center gap-1.5">
            <span className="size-3 rounded bg-warning-soft ring-1 ring-warning/40" aria-hidden="true" />
            Con bloqueo
          </li>
          <li className="inline-flex items-center gap-1.5">
            <span className="text-[11px] line-through" aria-hidden="true">
              12
            </span>
            Cerrado
          </li>
        </ul>
      </div>
    </Modal>
  );
}

export default MonthCalendar;
